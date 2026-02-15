<?php
/**
 * Admin settings.
 *
 * @package SinaliteWooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sinalite_WC_Settings {
	/**
	 * Option key.
	 *
	 * @var string
	 */
	private string $option_key = 'sinalite_wc_settings';
	private string $notice_key = 'sinalite_wc_test_notice';

	/**
	 * API client.
	 *
	 * @var Sinalite_WC_API_Client|null
	 */
	private ?Sinalite_WC_API_Client $api_client = null;

	/**
	 * Init hooks.
	 *
	 * @param Sinalite_WC_API_Client $api_client API client.
	 * @return void
	 */
	public function init( Sinalite_WC_API_Client $api_client ): void {
		$this->api_client = $api_client;
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_sinalite_wc_test_auth', array( $this, 'handle_test_auth' ) );
		add_action( 'admin_post_sinalite_wc_test_products', array( $this, 'handle_test_products' ) );
	}

	/**
	 * Get one setting value.
	 *
	 * @param string $key Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get( string $key, $default = '' ) {
		$settings = (array) get_option( $this->option_key, array() );
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Return full settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return wp_parse_args( (array) get_option( $this->option_key, array() ), $this->defaults() );
	}

	/**
	 * Register submenu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Sinalite WooCommerce', 'sinalite-woocommerce' ),
			__( 'Sinalite Import', 'sinalite-woocommerce' ),
			'manage_woocommerce',
			'sinalite-woocommerce',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings fields.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'sinalite_wc_settings_group',
			$this->option_key,
			array( $this, 'sanitize' )
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $input Input.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$defaults = $this->defaults();

		$output = array(
			'api_base_url'       => esc_url_raw( $input['api_base_url'] ?? $defaults['api_base_url'] ),
			'token_url'          => esc_url_raw( $input['token_url'] ?? $defaults['token_url'] ),
			'client_id'          => sanitize_text_field( (string) ( $input['client_id'] ?? '' ) ),
			'client_secret'      => sanitize_text_field( (string) ( $input['client_secret'] ?? '' ) ),
			'audience'           => sanitize_text_field( (string) ( $input['audience'] ?? $defaults['audience'] ) ),
			'store_code'         => absint( $input['store_code'] ?? $defaults['store_code'] ),
			'requests_per_min'   => max( 1, absint( $input['requests_per_min'] ?? $defaults['requests_per_min'] ) ),
			'request_timeout'    => max( 5, absint( $input['request_timeout'] ?? $defaults['request_timeout'] ) ),
			'batch_size'         => max( 1, absint( $input['batch_size'] ?? $defaults['batch_size'] ) ),
			'publish_status'     => in_array( (string) ( $input['publish_status'] ?? 'draft' ), array( 'draft', 'publish' ), true ) ? (string) $input['publish_status'] : 'draft',
			'cron_enabled'       => ! empty( $input['cron_enabled'] ),
			'cron_interval'      => in_array( (string) ( $input['cron_interval'] ?? 'sinalite_wc_15min' ), array( 'sinalite_wc_5min', 'sinalite_wc_15min', 'sinalite_wc_hourly' ), true ) ? (string) $input['cron_interval'] : 'sinalite_wc_15min',
		);

		return $output;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings = $this->all();
		$base_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sinalite WooCommerce Import', 'sinalite-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Use WP-CLI for heavy imports to avoid request timeouts. This page is for credentials and diagnostics.', 'sinalite-woocommerce' ); ?></p>

			<?php $this->render_test_notice(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'sinalite_wc_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sinalite_api_base_url"><?php esc_html_e( 'API Base URL', 'sinalite-woocommerce' ); ?></label></th>
						<td><input class="regular-text" type="url" id="sinalite_api_base_url" name="sinalite_wc_settings[api_base_url]" value="<?php echo esc_attr( (string) $settings['api_base_url'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sinalite_token_url"><?php esc_html_e( 'Token URL', 'sinalite-woocommerce' ); ?></label></th>
						<td><input class="regular-text" type="url" id="sinalite_token_url" name="sinalite_wc_settings[token_url]" value="<?php echo esc_attr( (string) $settings['token_url'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sinalite_client_id"><?php esc_html_e( 'Client ID', 'sinalite-woocommerce' ); ?></label></th>
						<td><input class="regular-text" type="text" id="sinalite_client_id" name="sinalite_wc_settings[client_id]" value="<?php echo esc_attr( (string) $settings['client_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sinalite_client_secret"><?php esc_html_e( 'Client Secret', 'sinalite-woocommerce' ); ?></label></th>
						<td><input class="regular-text" type="password" id="sinalite_client_secret" name="sinalite_wc_settings[client_secret]" value="<?php echo esc_attr( (string) $settings['client_secret'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sinalite_audience"><?php esc_html_e( 'Audience', 'sinalite-woocommerce' ); ?></label></th>
						<td><input class="regular-text" type="text" id="sinalite_audience" name="sinalite_wc_settings[audience]" value="<?php echo esc_attr( (string) $settings['audience'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sinalite_store_code"><?php esc_html_e( 'Store Code', 'sinalite-woocommerce' ); ?></label></th>
						<td><input type="number" id="sinalite_store_code" name="sinalite_wc_settings[store_code]" value="<?php echo esc_attr( (string) $settings['store_code'] ); ?>" min="1" step="1"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sinalite_requests_per_min"><?php esc_html_e( 'Requests per Minute', 'sinalite-woocommerce' ); ?></label></th>
						<td><input type="number" id="sinalite_requests_per_min" name="sinalite_wc_settings[requests_per_min]" value="<?php echo esc_attr( (string) $settings['requests_per_min'] ); ?>" min="1" step="1"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sinalite_request_timeout"><?php esc_html_e( 'Request Timeout (seconds)', 'sinalite-woocommerce' ); ?></label></th>
						<td><input type="number" id="sinalite_request_timeout" name="sinalite_wc_settings[request_timeout]" value="<?php echo esc_attr( (string) $settings['request_timeout'] ); ?>" min="5" step="1"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sinalite_batch_size"><?php esc_html_e( 'Default Process Batch Size', 'sinalite-woocommerce' ); ?></label></th>
						<td><input type="number" id="sinalite_batch_size" name="sinalite_wc_settings[batch_size]" value="<?php echo esc_attr( (string) $settings['batch_size'] ); ?>" min="1" step="1"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sinalite_publish_status"><?php esc_html_e( 'Imported Product Status', 'sinalite-woocommerce' ); ?></label></th>
						<td>
							<select id="sinalite_publish_status" name="sinalite_wc_settings[publish_status]">
								<option value="draft" <?php selected( 'draft', $settings['publish_status'] ); ?>><?php esc_html_e( 'Draft', 'sinalite-woocommerce' ); ?></option>
								<option value="publish" <?php selected( 'publish', $settings['publish_status'] ); ?>><?php esc_html_e( 'Publish', 'sinalite-woocommerce' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cron Processing', 'sinalite-woocommerce' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="sinalite_wc_settings[cron_enabled]" value="1" <?php checked( ! empty( $settings['cron_enabled'] ) ); ?>>
								<?php esc_html_e( 'Enable scheduled processing of staged rows', 'sinalite-woocommerce' ); ?>
							</label>
							<p>
								<select name="sinalite_wc_settings[cron_interval]">
									<option value="sinalite_wc_5min" <?php selected( 'sinalite_wc_5min', $settings['cron_interval'] ); ?>><?php esc_html_e( 'Every 5 minutes', 'sinalite-woocommerce' ); ?></option>
									<option value="sinalite_wc_15min" <?php selected( 'sinalite_wc_15min', $settings['cron_interval'] ); ?>><?php esc_html_e( 'Every 15 minutes', 'sinalite-woocommerce' ); ?></option>
									<option value="sinalite_wc_hourly" <?php selected( 'sinalite_wc_hourly', $settings['cron_interval'] ); ?>><?php esc_html_e( 'Hourly', 'sinalite-woocommerce' ); ?></option>
								</select>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Connection Tests', 'sinalite-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'These are lightweight diagnostics only. Run imports with WP-CLI commands listed below.', 'sinalite-woocommerce' ); ?></p>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( $base_url . '?action=sinalite_wc_test_auth', 'sinalite_wc_test_auth' ) ); ?>"><?php esc_html_e( 'Test Auth Token', 'sinalite-woocommerce' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( $base_url . '?action=sinalite_wc_test_products', 'sinalite_wc_test_products' ) ); ?>"><?php esc_html_e( 'Test Product Endpoint', 'sinalite-woocommerce' ); ?></a>
			</p>

			<h2><?php esc_html_e( 'WP-CLI Commands', 'sinalite-woocommerce' ); ?></h2>
			<pre>wp sinalite-wc db-install
wp sinalite-wc test-auth
wp sinalite-wc fetch --limit=50 --offset=0 --max-variant-pages=1
wp sinalite-wc process --batch=20 --max-variations=200
wp sinalite-wc run-batch --fetch-limit=20 --process-batch=20</pre>
		</div>
		<?php
	}

	/**
	 * Test auth action.
	 *
	 * @return void
	 */
	public function handle_test_auth(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'sinalite-woocommerce' ) );
		}

		check_admin_referer( 'sinalite_wc_test_auth' );

		$token = $this->api_client ? $this->api_client->get_access_token( true ) : new WP_Error( 'missing_client', __( 'API client not initialized.', 'sinalite-woocommerce' ) );
		if ( is_wp_error( $token ) ) {
			$this->set_test_notice( 'error', sprintf( 'Auth failed: %s', $token->get_error_message() ) );
		} else {
			$this->set_test_notice( 'success', __( 'Auth successful.', 'sinalite-woocommerce' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=sinalite-woocommerce' ) );
		exit;
	}

	/**
	 * Test product endpoint action.
	 *
	 * @return void
	 */
	public function handle_test_products(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'sinalite-woocommerce' ) );
		}

		check_admin_referer( 'sinalite_wc_test_products' );

		$products = $this->api_client ? $this->api_client->get_products() : new WP_Error( 'missing_client', __( 'API client not initialized.', 'sinalite-woocommerce' ) );
		if ( is_wp_error( $products ) ) {
			$this->set_test_notice( 'error', sprintf( 'Product request failed: %s', $products->get_error_message() ) );
		} else {
			$count = is_array( $products ) ? count( $products ) : 0;
			$this->set_test_notice( 'success', sprintf( 'Product request successful. Returned %d rows.', $count ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=sinalite-woocommerce' ) );
		exit;
	}

	/**
	 * Store notice for next page load.
	 *
	 * @param string $type success|error.
	 * @param string $message Notice message.
	 * @return void
	 */
	private function set_test_notice( string $type, string $message ): void {
		set_transient(
			$this->notice_key,
			array(
				'type'    => in_array( $type, array( 'success', 'error' ), true ) ? $type : 'success',
				'message' => wp_strip_all_tags( $message ),
			),
			60
		);
	}

	/**
	 * Render and clear test notice.
	 *
	 * @return void
	 */
	private function render_test_notice(): void {
		$notice = get_transient( $this->notice_key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $this->notice_key );
		$class = 'success' === ( $notice['type'] ?? '' ) ? 'notice-success' : 'notice-error';

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Settings defaults.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults(): array {
		return array(
			'api_base_url'     => 'https://liveapi.sinalite.com',
			'token_url'        => 'https://liveapi.sinalite.com/auth/token',
			'client_id'        => '',
			'client_secret'    => '',
			'audience'         => 'https://apiconnect.sinalite.com',
			'store_code'       => 6,
			'requests_per_min' => 60,
			'request_timeout'  => 30,
			'batch_size'       => 20,
			'publish_status'   => 'draft',
			'cron_enabled'     => false,
			'cron_interval'    => 'sinalite_wc_15min',
		);
	}
}
