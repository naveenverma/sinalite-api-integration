<?php
/**
 * Staged import service.
 *
 * @package SinaliteWooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sinalite_WC_Importer {
	/**
	 * Settings helper.
	 *
	 * @var Sinalite_WC_Settings
	 */
	private Sinalite_WC_Settings $settings;

	/**
	 * API client.
	 *
	 * @var Sinalite_WC_API_Client
	 */
	private Sinalite_WC_API_Client $api_client;

	/**
	 * Constructor.
	 *
	 * @param Sinalite_WC_Settings   $settings Settings.
	 * @param Sinalite_WC_API_Client $api_client API.
	 */
	public function __construct( Sinalite_WC_Settings $settings, Sinalite_WC_API_Client $api_client ) {
		$this->settings   = $settings;
		$this->api_client = $api_client;
	}

	/**
	 * Fetch product data and stage in custom tables.
	 *
	 * @param int $limit Max product count.
	 * @param int $offset Product offset.
	 * @param int $max_variant_pages Max variant pages per product.
	 * @return array<string, int>
	 */
	public function fetch_to_staging( int $limit = 100, int $offset = 0, int $max_variant_pages = 1 ): array {
		global $wpdb;

		$products_table = Sinalite_WC_Installer::products_table();
		$options_table  = Sinalite_WC_Installer::options_table();
		$variants_table = Sinalite_WC_Installer::variants_table();
		$store_code     = (int) $this->settings->get( 'store_code', 6 );

		$result = array(
			'products' => 0,
			'options'  => 0,
			'variants' => 0,
			'errors'   => 0,
		);

		$products = $this->api_client->get_products();
		if ( is_wp_error( $products ) ) {
			throw new RuntimeException( $products->get_error_message() );
		}

		$slice = array_slice( $products, max( 0, $offset ), max( 1, $limit ) );
		foreach ( $slice as $product ) {
			$product_id = isset( $product['id'] ) ? (int) $product['id'] : 0;
			if ( $product_id <= 0 ) {
				++$result['errors'];
				continue;
			}

			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$products_table}
					(api_product_id, sku, name, category, enabled, raw_payload, fetched_at, status, processed_at, wc_product_id, last_error)
					VALUES (%d, %s, %s, %s, %d, %s, %s, %s, NULL, NULL, NULL)
					ON DUPLICATE KEY UPDATE
					sku = VALUES(sku),
					name = VALUES(name),
					category = VALUES(category),
					enabled = VALUES(enabled),
					raw_payload = VALUES(raw_payload),
					fetched_at = VALUES(fetched_at),
					status = 'pending',
					last_error = NULL",
					$product_id,
					(string) ( $product['sku'] ?? '' ),
					(string) ( $product['name'] ?? '' ),
					(string) ( $product['category'] ?? '' ),
					isset( $product['enabled'] ) ? (int) $product['enabled'] : 0,
					wp_json_encode( $product ),
					current_time( 'mysql', true ),
					'pending'
				)
			);

			if ( false !== $inserted ) {
				++$result['products'];
			}

			$pricing = $this->api_client->get_product_pricing( $product_id, $store_code );
			if ( is_wp_error( $pricing ) ) {
				$this->mark_product_error( $product_id, $pricing->get_error_message() );
				++$result['errors'];
				continue;
			}

			if ( isset( $pricing[0] ) && is_array( $pricing[0] ) ) {
				foreach ( $pricing[0] as $option ) {
					$option_id = isset( $option['id'] ) ? (int) $option['id'] : 0;
					if ( $option_id <= 0 ) {
						continue;
					}

					$ok = $wpdb->query(
						$wpdb->prepare(
							"INSERT INTO {$options_table}
							(api_product_id, option_id, option_group, option_name, raw_payload, fetched_at)
							VALUES (%d, %d, %s, %s, %s, %s)
							ON DUPLICATE KEY UPDATE
							option_group = VALUES(option_group),
							option_name = VALUES(option_name),
							raw_payload = VALUES(raw_payload),
							fetched_at = VALUES(fetched_at)",
							$product_id,
							$option_id,
							(string) ( $option['group'] ?? '' ),
							(string) ( $option['name'] ?? '' ),
							wp_json_encode( $option ),
							current_time( 'mysql', true )
						)
					);

					if ( false !== $ok ) {
						++$result['options'];
					}
				}
			}

			$this->refresh_product_variants( $product_id, $variants_table, $max_variant_pages, $result );
		}

		return $result;
	}

	/**
	 * Process staged rows into Woo products.
	 *
	 * @param int $batch Batch size.
	 * @param int $max_variations_per_product Cap variation creation.
	 * @return array<string, int>
	 */
	public function process_products( int $batch = 20, int $max_variations_per_product = 200 ): array {
		global $wpdb;

		$products_table = Sinalite_WC_Installer::products_table();
		$rows           = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$products_table}
				WHERE status IN ('pending', 'error')
				ORDER BY fetched_at ASC
				LIMIT %d",
				max( 1, $batch )
			),
			ARRAY_A
		);

		$summary = array(
			'simple'   => 0,
			'variable' => 0,
			'errors'   => 0,
		);

		foreach ( $rows as $row ) {
			$api_product_id = (int) $row['api_product_id'];

			try {
				$variant_rows = $this->get_variant_rows( $api_product_id, $max_variations_per_product );
				$product_id   = $this->upsert_wc_product( $row, $variant_rows );

				$type = count( $variant_rows ) > 1 ? 'variable' : 'simple';
				++$summary[ $type ];

				$wpdb->update(
					$products_table,
					array(
						'status'       => 'processed',
						'processed_at' => current_time( 'mysql', true ),
						'wc_product_id'=> $product_id,
						'last_error'   => null,
					),
					array( 'api_product_id' => $api_product_id ),
					array( '%s', '%s', '%d', '%s' ),
					array( '%d' )
				);
			} catch ( Throwable $e ) {
				$this->mark_product_error( $api_product_id, $e->getMessage() );
				++$summary['errors'];
			}
		}

		return $summary;
	}

	/**
	 * Build or update Woo product.
	 *
	 * @param array<string, mixed>      $row Product row.
	 * @param array<int, array<string,mixed>> $variant_rows Variant rows.
	 * @return int
	 */
	private function upsert_wc_product( array $row, array $variant_rows ): int {
		$wc_existing = $this->get_wc_product_by_api_id( (int) $row['api_product_id'] );
		$is_variable = count( $variant_rows ) > 1;
		$status      = (string) $this->settings->get( 'publish_status', 'draft' );

		if ( $is_variable ) {
			if ( $wc_existing instanceof WC_Product_Variable ) {
				$product = $wc_existing;
			} elseif ( $wc_existing instanceof WC_Product ) {
				wp_set_object_terms( $wc_existing->get_id(), 'variable', 'product_type' );
				$product = new WC_Product_Variable( $wc_existing->get_id() );
			} else {
				$product = new WC_Product_Variable();
			}
		} else {
			if ( $wc_existing instanceof WC_Product_Simple ) {
				$product = $wc_existing;
			} elseif ( $wc_existing instanceof WC_Product ) {
				wp_set_object_terms( $wc_existing->get_id(), 'simple', 'product_type' );
				$product = new WC_Product_Simple( $wc_existing->get_id() );
			} else {
				$product = new WC_Product_Simple();
			}
		}

		$product->set_name( (string) $row['name'] );
		$product->set_sku( $this->safe_sku( (string) $row['sku'], (int) $row['api_product_id'] ) );
		$product->set_status( $status );
		$product->set_catalog_visibility( 'visible' );
		$product->set_manage_stock( false );
		$product->set_description( sprintf( 'Imported from Sinalite API product #%d.', (int) $row['api_product_id'] ) );
		$product->update_meta_data( '_sinalite_product_id', (int) $row['api_product_id'] );
		$product->save();

		if ( ! empty( $row['category'] ) ) {
			wp_set_object_terms( $product->get_id(), array( (string) $row['category'] ), 'product_cat', true );
		}

		if ( $is_variable ) {
			$this->sync_variable_details( $product, $variant_rows );
		} else {
			$price = isset( $variant_rows[0]['price'] ) ? (float) $variant_rows[0]['price'] : 0.0;
			if ( $price > 0 ) {
				$product->set_regular_price( (string) $price );
				$product->save();
			}
		}

		return $product->get_id();
	}

	/**
	 * Apply variable attributes/variations.
	 *
	 * @param WC_Product_Variable              $product Woo variable.
	 * @param array<int, array<string, mixed>> $variant_rows Variant rows.
	 * @return void
	 */
	private function sync_variable_details( WC_Product_Variable $product, array $variant_rows ): void {
		$api_product_id = (int) $product->get_meta( '_sinalite_product_id', true );
		$map            = $this->get_product_option_map( $api_product_id );
		$attribute_sets = array();

		foreach ( $variant_rows as $variant ) {
			$key = (string) ( $variant['variant_key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$parsed = $this->parse_variant_key( $key, $map );
			foreach ( $parsed as $group => $value ) {
				if ( ! isset( $attribute_sets[ $group ] ) ) {
					$attribute_sets[ $group ] = array();
				}
				$attribute_sets[ $group ][ $value ] = $value;
			}
		}

		$attributes = array();
		foreach ( $attribute_sets as $group => $values ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( (string) $group );
			$attribute->set_options( array_values( $values ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}

		$product->set_attributes( $attributes );
		$product->save();

		foreach ( $product->get_children() as $child_id ) {
			wp_delete_post( (int) $child_id, true );
		}

		foreach ( $variant_rows as $variant ) {
			$key    = (string) ( $variant['variant_key'] ?? '' );
			$price  = isset( $variant['price'] ) ? (float) $variant['price'] : 0.0;
			$parsed = $this->parse_variant_key( $key, $map );
			if ( empty( $parsed ) ) {
				continue;
			}

			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_status( 'publish' );
			$variation->set_manage_stock( false );

			if ( $price > 0 ) {
				$variation->set_regular_price( (string) $price );
			}

			$wc_attrs = array();
			foreach ( $parsed as $group => $value ) {
				$wc_attrs[ sanitize_title( $group ) ] = $value;
			}
			$variation->set_attributes( $wc_attrs );
			$variation->save();
		}
	}

	/**
	 * Get map [option_id => [group,name]].
	 *
	 * @param int $api_product_id Product.
	 * @return array<int, array<string, string>>
	 */
	private function get_product_option_map( int $api_product_id ): array {
		global $wpdb;
		$table = Sinalite_WC_Installer::options_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_id, option_group, option_name
				FROM {$table}
				WHERE api_product_id = %d",
				$api_product_id
			),
			ARRAY_A
		);

		$map = array();
		foreach ( $rows as $row ) {
			$id         = (int) $row['option_id'];
			$map[ $id ] = array(
				'group' => (string) $row['option_group'],
				'name'  => (string) $row['option_name'],
			);
		}
		return $map;
	}

	/**
	 * Parse variant key IDs to group/value map.
	 *
	 * @param string                                  $variant_key Key.
	 * @param array<int, array<string, string>>       $map Option map.
	 * @return array<string, string>
	 */
	private function parse_variant_key( string $variant_key, array $map ): array {
		$result = array();
		$ids    = array_filter( array_map( 'absint', explode( '-', $variant_key ) ) );
		foreach ( $ids as $id ) {
			if ( empty( $map[ $id ] ) ) {
				continue;
			}
			$group          = $map[ $id ]['group'] ?: 'Option';
			$result[ $group ] = $map[ $id ]['name'];
		}
		return $result;
	}

	/**
	 * Get variant rows with cap.
	 *
	 * @param int $api_product_id Product.
	 * @param int $cap Cap.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_variant_rows( int $api_product_id, int $cap ): array {
		global $wpdb;
		$table = Sinalite_WC_Installer::variants_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT variant_key, price
				FROM {$table}
				WHERE api_product_id = %d
				ORDER BY id ASC
				LIMIT %d",
				$api_product_id,
				max( 1, $cap )
			),
			ARRAY_A
		);
	}

	/**
	 * Lookup Woo product by meta.
	 *
	 * @param int $api_product_id Product.
	 * @return WC_Product|null
	 */
	private function get_wc_product_by_api_id( int $api_product_id ): ?WC_Product {
		$ids = wc_get_products(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'meta_key'   => '_sinalite_product_id',
				'meta_value' => $api_product_id,
				'status'     => array( 'publish', 'draft', 'private', 'pending' ),
			)
		);

		if ( empty( $ids ) ) {
			return null;
		}

		$product = wc_get_product( (int) $ids[0] );
		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * Keep SKU unique.
	 *
	 * @param string $sku Source sku.
	 * @param int    $api_product_id API ID.
	 * @return string
	 */
	private function safe_sku( string $sku, int $api_product_id ): string {
		$sku = sanitize_text_field( $sku );
		if ( '' === $sku ) {
			return 'sinalite-' . $api_product_id;
		}
		return $sku;
	}

	/**
	 * Store error message.
	 *
	 * @param int    $api_product_id Product ID.
	 * @param string $message Error.
	 * @return void
	 */
	private function mark_product_error( int $api_product_id, string $message ): void {
		global $wpdb;
		$wpdb->update(
			Sinalite_WC_Installer::products_table(),
			array(
				'status'     => 'error',
				'last_error' => wp_strip_all_tags( $message ),
			),
			array(
				'api_product_id' => $api_product_id,
			),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Refresh staged variants for one product.
	 *
	 * @param int                $product_id Product ID.
	 * @param string             $variants_table Variants table.
	 * @param int                $max_variant_pages Max pages.
	 * @param array<string, int> $result Counters.
	 * @return void
	 */
	private function refresh_product_variants( int $product_id, string $variants_table, int $max_variant_pages, array &$result ): void {
		global $wpdb;

		$wpdb->delete( $variants_table, array( 'api_product_id' => $product_id ), array( '%d' ) );

		$offset = 0;
		for ( $page = 0; $page < max( 1, $max_variant_pages ); $page++ ) {
			$variants = $this->api_client->get_variants( $product_id, $offset );
			if ( is_wp_error( $variants ) ) {
				$this->mark_product_error( $product_id, $variants->get_error_message() );
				++$result['errors'];
				break;
			}

			if ( empty( $variants ) ) {
				break;
			}

			foreach ( $variants as $variant ) {
				$key = isset( $variant['key'] ) ? (string) $variant['key'] : '';
				if ( '' === $key ) {
					continue;
				}

				$ok = $wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$variants_table}
						(api_product_id, variant_key, price, raw_payload, fetched_at)
						VALUES (%d, %s, %f, %s, %s)
						ON DUPLICATE KEY UPDATE
						price = VALUES(price),
						raw_payload = VALUES(raw_payload),
						fetched_at = VALUES(fetched_at)",
						$product_id,
						$key,
						isset( $variant['price'] ) ? (float) $variant['price'] : 0.0,
						wp_json_encode( $variant ),
						current_time( 'mysql', true )
					)
				);

				if ( false !== $ok ) {
					++$result['variants'];
				}
			}

			if ( count( $variants ) < 1000 ) {
				break;
			}
			$offset += 1000;
		}
	}
}
