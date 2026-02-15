<?php
/**
 * Main plugin runtime.
 *
 * @package SinaliteWooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sinalite_WC_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Sinalite_WC_Plugin|null
	 */
	private static ?Sinalite_WC_Plugin $instance = null;

	/**
	 * Plugin settings helper.
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
	 * Import service.
	 *
	 * @var Sinalite_WC_Importer
	 */
	private Sinalite_WC_Importer $importer;

	/**
	 * Get singleton.
	 *
	 * @return Sinalite_WC_Plugin
	 */
	public static function instance(): Sinalite_WC_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::load_dependencies();
		Sinalite_WC_Installer::activate();
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'sinalite_wc_import_cron' );
	}

	/**
	 * Boot plugin.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_required_notice' ) );
			return;
		}

		self::load_dependencies();

		$this->settings   = new Sinalite_WC_Settings();
		$this->api_client = new Sinalite_WC_API_Client( $this->settings );
		$this->importer   = new Sinalite_WC_Importer( $this->settings, $this->api_client );

		$this->settings->init( $this->api_client );
		$this->register_cron();
		$this->register_cli();
		add_action( 'sinalite_wc_import_cron', array( $this, 'run_cron_import' ) );
	}

	/**
	 * Run batched cron processing.
	 *
	 * @return void
	 */
	public function run_cron_import(): void {
		$batch_size = (int) $this->settings->get( 'batch_size', 20 );
		$this->importer->process_products( $batch_size, 200 );
	}

	/**
	 * Register cron schedules and events.
	 *
	 * @return void
	 */
	private function register_cron(): void {
		add_filter(
			'cron_schedules',
			static function( array $schedules ): array {
				$schedules['sinalite_wc_5min']  = array(
					'interval' => 5 * MINUTE_IN_SECONDS,
					'display'  => __( 'Every 5 minutes (Sinalite)', 'sinalite-woocommerce' ),
				);
				$schedules['sinalite_wc_15min'] = array(
					'interval' => 15 * MINUTE_IN_SECONDS,
					'display'  => __( 'Every 15 minutes (Sinalite)', 'sinalite-woocommerce' ),
				);
				$schedules['sinalite_wc_hourly'] = array(
					'interval' => HOUR_IN_SECONDS,
					'display'  => __( 'Hourly (Sinalite)', 'sinalite-woocommerce' ),
				);
				return $schedules;
			}
		);

		$enabled = (bool) $this->settings->get( 'cron_enabled', false );
		$event   = wp_next_scheduled( 'sinalite_wc_import_cron' );

		if ( ! $enabled ) {
			if ( $event ) {
				wp_clear_scheduled_hook( 'sinalite_wc_import_cron' );
			}
			return;
		}

		if ( ! $event ) {
			$interval = (string) $this->settings->get( 'cron_interval', 'sinalite_wc_15min' );
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, 'sinalite_wc_import_cron' );
		}
	}

	/**
	 * Register wp-cli commands.
	 *
	 * @return void
	 */
	private function register_cli(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		require_once SINALITE_WC_PLUGIN_DIR . 'includes/cli/class-sinalite-wc-cli.php';
		Sinalite_WC_CLI::register( $this->settings, $this->api_client, $this->importer );
	}

	/**
	 * Show WooCommerce dependency notice.
	 *
	 * @return void
	 */
	public function woocommerce_required_notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Sinalite WooCommerce requires WooCommerce to be active.', 'sinalite-woocommerce' )
		);
	}

	/**
	 * Load class dependencies.
	 *
	 * @return void
	 */
	private static function load_dependencies(): void {
		require_once SINALITE_WC_PLUGIN_DIR . 'includes/class-sinalite-wc-installer.php';
		require_once SINALITE_WC_PLUGIN_DIR . 'includes/class-sinalite-wc-settings.php';
		require_once SINALITE_WC_PLUGIN_DIR . 'includes/class-sinalite-wc-api-client.php';
		require_once SINALITE_WC_PLUGIN_DIR . 'includes/class-sinalite-wc-importer.php';
	}
}
