<?php
/**
 * Plugin Name: Sinalite WooCommerce
 * Description: Import Sinalite pricing products into WooCommerce using staged imports and WP-CLI.
 * Version: 0.1.0
 * Author: Naveen Verma
 * Author URI: https://www.haatchmedia.com
 * Text Domain: sinalite-woocommerce
 * Requires Plugins: woocommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SINALITE_WC_VERSION', '0.1.0' );
define( 'SINALITE_WC_PLUGIN_FILE', __FILE__ );
define( 'SINALITE_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SINALITE_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SINALITE_WC_PLUGIN_DIR . 'includes/class-sinalite-wc-plugin.php';

register_activation_hook( __FILE__, array( 'Sinalite_WC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Sinalite_WC_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function() {
		Sinalite_WC_Plugin::instance()->init();
	}
);
