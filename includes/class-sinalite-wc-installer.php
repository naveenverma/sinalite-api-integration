<?php
/**
 * Activation installer.
 *
 * @package SinaliteWooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sinalite_WC_Installer {
	/**
	 * Create/update DB schema.
	 *
	 * @return void
	 */
	public static function activate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$products_table  = self::products_table();
		$options_table   = self::options_table();
		$variants_table  = self::variants_table();

		$sql_products = "CREATE TABLE {$products_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			api_product_id BIGINT UNSIGNED NOT NULL,
			sku VARCHAR(191) DEFAULT '' NOT NULL,
			name VARCHAR(255) DEFAULT '' NOT NULL,
			category VARCHAR(191) DEFAULT '' NOT NULL,
			enabled TINYINT(1) DEFAULT 0 NOT NULL,
			raw_payload LONGTEXT NULL,
			fetched_at DATETIME NOT NULL,
			processed_at DATETIME NULL,
			wc_product_id BIGINT UNSIGNED NULL,
			status VARCHAR(30) DEFAULT 'pending' NOT NULL,
			last_error TEXT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY api_product_id (api_product_id),
			KEY status (status),
			KEY wc_product_id (wc_product_id)
		) {$charset_collate};";

		$sql_options = "CREATE TABLE {$options_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			api_product_id BIGINT UNSIGNED NOT NULL,
			option_id BIGINT UNSIGNED NOT NULL,
			option_group VARCHAR(120) DEFAULT '' NOT NULL,
			option_name VARCHAR(255) DEFAULT '' NOT NULL,
			raw_payload LONGTEXT NULL,
			fetched_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY product_option (api_product_id, option_id),
			KEY product_group (api_product_id, option_group)
		) {$charset_collate};";

		$sql_variants = "CREATE TABLE {$variants_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			api_product_id BIGINT UNSIGNED NOT NULL,
			variant_key VARCHAR(191) NOT NULL,
			price DECIMAL(12,4) NULL,
			raw_payload LONGTEXT NULL,
			fetched_at DATETIME NOT NULL,
			processed_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY product_variant (api_product_id, variant_key),
			KEY product_lookup (api_product_id)
		) {$charset_collate};";

		dbDelta( $sql_products );
		dbDelta( $sql_options );
		dbDelta( $sql_variants );

		update_option( 'sinalite_wc_db_version', SINALITE_WC_VERSION );
	}

	/**
	 * Products table.
	 *
	 * @return string
	 */
	public static function products_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sinalite_wc_products';
	}

	/**
	 * Product options table.
	 *
	 * @return string
	 */
	public static function options_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sinalite_wc_product_options';
	}

	/**
	 * Variants table.
	 *
	 * @return string
	 */
	public static function variants_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sinalite_wc_variants';
	}
}
