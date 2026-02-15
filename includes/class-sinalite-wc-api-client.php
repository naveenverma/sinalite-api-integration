<?php
/**
 * Sinalite API client.
 *
 * @package SinaliteWooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sinalite_WC_API_Client {
	/**
	 * Settings helper.
	 *
	 * @var Sinalite_WC_Settings
	 */
	private Sinalite_WC_Settings $settings;

	/**
	 * Timestamp of last request.
	 *
	 * @var float
	 */
	private static float $last_request_ts = 0.0;

	/**
	 * Constructor.
	 *
	 * @param Sinalite_WC_Settings $settings Settings.
	 */
	public function __construct( Sinalite_WC_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Get OAuth token.
	 *
	 * @param bool $force_refresh Force refresh token.
	 * @return string|WP_Error
	 */
	public function get_access_token( bool $force_refresh = false ) {
		$cache_key = 'sinalite_wc_access_token';
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$client_id     = (string) $this->settings->get( 'client_id', '' );
		$client_secret = (string) $this->settings->get( 'client_secret', '' );
		$audience      = (string) $this->settings->get( 'audience', '' );
		$token_url     = (string) $this->settings->get( 'token_url', '' );

		if ( '' === $client_id || '' === $client_secret || '' === $token_url ) {
			return new WP_Error( 'missing_credentials', __( 'Sinalite API credentials are not configured.', 'sinalite-woocommerce' ) );
		}

		$response = wp_remote_post(
			$token_url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'timeout' => (int) $this->settings->get( 'request_timeout', 30 ),
				'body'    => wp_json_encode(
					array(
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'audience'      => $audience,
						'grant_type'    => 'client_credentials',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			return new WP_Error( 'auth_failed', sprintf( 'Token request failed (%d): %s', $code, wp_json_encode( $body ) ) );
		}

		$token = (string) $body['access_token'];
		set_transient( $cache_key, $token, 50 * MINUTE_IN_SECONDS );
		return $token;
	}

	/**
	 * GET /product.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get_products() {
		return $this->request( 'GET', '/product' );
	}

	/**
	 * GET /product/{id}/{storeCode}.
	 *
	 * @param int $product_id Product ID.
	 * @param int $store_code Store code.
	 * @return array<mixed>|WP_Error
	 */
	public function get_product_pricing( int $product_id, int $store_code ) {
		return $this->request( 'GET', sprintf( '/product/%d/%d', $product_id, $store_code ) );
	}

	/**
	 * GET /variants/{id}/{offset}.
	 *
	 * @param int $product_id Product ID.
	 * @param int $offset Offset.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get_variants( int $product_id, int $offset = 0 ) {
		return $this->request( 'GET', sprintf( '/variants/%d/%d', $product_id, $offset ) );
	}

	/**
	 * POST /price/{id}/{storeCode}
	 *
	 * @param int              $product_id Product ID.
	 * @param int              $store_code Store code.
	 * @param array<int, int>  $option_ids Option IDs.
	 * @return array<string, mixed>|WP_Error
	 */
	public function post_price( int $product_id, int $store_code, array $option_ids ) {
		return $this->request(
			'POST',
			sprintf( '/price/%d/%d', $product_id, $store_code ),
			array(
				'productOptions' => array_values( array_map( 'intval', $option_ids ) ),
			)
		);
	}

	/**
	 * Send API request with retries and throttling.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path Path.
	 * @param array<string, mixed>|null $payload Payload.
	 * @return array<mixed>|WP_Error
	 */
	private function request( string $method, string $path, ?array $payload = null ) {
		$this->throttle();

		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = untrailingslashit( (string) $this->settings->get( 'api_base_url', 'https://liveapi.sinalite.com' ) ) . $path;
		$max_attempts = 4;
		$attempt      = 0;

		do {
			++$attempt;

			$args = array(
				'method'  => $method,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'timeout' => (int) $this->settings->get( 'request_timeout', 30 ),
			);

			if ( null !== $payload ) {
				$args['body'] = wp_json_encode( $payload );
			}

			$response = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				if ( $attempt >= $max_attempts ) {
					return $response;
				}
				sleep( $attempt );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
			$json = json_decode( $body, true );

			if ( 401 === $code && 1 === $attempt ) {
				$token = $this->get_access_token( true );
				if ( is_wp_error( $token ) ) {
					return $token;
				}
				continue;
			}

			if ( 429 === $code || ( $code >= 500 && $code <= 599 ) ) {
				if ( $attempt >= $max_attempts ) {
					return new WP_Error( 'api_throttle', sprintf( 'API temporary failure (%d): %s', $code, $body ) );
				}

				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				sleep( $retry_after > 0 ? $retry_after : $attempt * 2 );
				continue;
			}

			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'api_request_failed', sprintf( 'API request failed (%d): %s', $code, $body ) );
			}

			return is_array( $json ) ? $json : array();
		} while ( $attempt < $max_attempts );

		return new WP_Error( 'api_unknown', __( 'Unknown API failure.', 'sinalite-woocommerce' ) );
	}

	/**
	 * Client-side rate limiting.
	 *
	 * @return void
	 */
	private function throttle(): void {
		$requests_per_min = max( 1, (int) $this->settings->get( 'requests_per_min', 60 ) );
		$interval         = 60 / $requests_per_min;
		$now              = microtime( true );

		if ( self::$last_request_ts > 0 ) {
			$elapsed = $now - self::$last_request_ts;
			if ( $elapsed < $interval ) {
				$micros = (int) floor( ( $interval - $elapsed ) * 1000000 );
				if ( $micros > 0 ) {
					usleep( $micros );
				}
			}
		}

		self::$last_request_ts = microtime( true );
	}
}
