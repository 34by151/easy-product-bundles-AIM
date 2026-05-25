<?php
/**
 * Plugin Name: Easy Product Bundles for WooCommerce - AIM
 * Description: Extends Easy Product Bundles for WooCommerce Pro with additional features: hide bundle item quantity controls and dynamic quantity linking between bundle items.
 * Version:     1.1.8
 * Author:      AIM
 * Text Domain: epb-aim
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 *
 * @package EasyProductBundlesAIM
 */

defined( 'ABSPATH' ) || exit;

define( 'EPB_AIM_VERSION', '1.1.8' );
define( 'EPB_AIM_PLUGIN_FILE', __FILE__ );
define( 'EPB_AIM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPB_AIM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Check that required plugins are installed and active.
 *
 * @return bool
 */
function epb_aim_dependencies_met() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return false;
	}

	// Base bundle plugin.
	if ( ! defined( 'ASNP_WEPB_VERSION' ) ) {
		return false;
	}

	// Pro bundle plugin.
	if ( ! defined( 'ASNP_WEPB_PRO_VERSION' ) ) {
		return false;
	}

	return true;
}

/**
 * Write a message to the WordPress debug log when detailed logging is enabled.
 * The option 'epb_aim_detailed_logging' is a global plugin setting.
 *
 * @param string $message Human-readable log message.
 */
function epb_aim_log( string $message ): void {
	if ( 'yes' !== get_option( 'epb_aim_detailed_logging', 'no' ) ) {
		return;
	}
	$entry = '[' . gmdate( 'Y-m-d H:i:s' ) . '] [EPB-AIM] ' . $message . PHP_EOL;
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	@file_put_contents( WP_CONTENT_DIR . '/debug.log', $entry, FILE_APPEND | LOCK_EX );
}

/**
 * Boot the plugin after all plugins are loaded.
 * Priority 1020 — after base plugin (1000) and pro plugin (1010).
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! epb_aim_dependencies_met() ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__(
						'Easy Product Bundles for WooCommerce - AIM requires both Easy Product Bundles for WooCommerce and Easy Product Bundles for WooCommerce Pro to be installed and active.',
						'epb-aim'
					);
					echo '</p></div>';
				}
			);
			return;
		}

		require_once EPB_AIM_PLUGIN_DIR . 'src/Plugin.php';
		\EasyProductBundlesAIM\Plugin::instance()->init();
	},
	1020
);
