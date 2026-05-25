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
		require_once EPB_AIM_PLUGIN_DIR . 'src/GitHubUpdater.php';

		( new GitHubUpdater( EPB_AIM_PLUGIN_FILE ) )->init();
		$this->register_plugin_row_links();

		if ( is_admin() ) {
			( new Admin() )->init();
		}

		( new Frontend() )->init();
	}

	/**
	 * Add "View details" to the plugin row meta on the Plugins screen.
	 */
	private function register_plugin_row_links(): void {
		add_filter(
			'plugin_row_meta',
			function ( array $links, string $file ): array {
				if ( plugin_basename( EPB_AIM_PLUGIN_FILE ) !== $file ) {
					return $links;
				}

				$links[] = sprintf(
					'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
					'https://github.com/34by151/easy-product-bundles-AIM',
					esc_html__( 'View details', 'epb-aim' )
				);

				return $links;
			},
			10,
			2
		);
	}
}
