<?php
/**
 * Main plugin class.
 *
 * @package EasyProductBundlesAIM
 */

namespace EasyProductBundlesAIM;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		require_once EPB_AIM_PLUGIN_DIR . 'src/Admin.php';
		require_once EPB_AIM_PLUGIN_DIR . 'src/Frontend.php';

		if ( is_admin() ) {
			( new Admin() )->init();
		}

		( new Frontend() )->init();
	}
}
