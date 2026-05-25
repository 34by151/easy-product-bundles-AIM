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

				$slug        = dirname( plugin_basename( EPB_AIM_PLUGIN_FILE ) );
				$details_url = add_query_arg(
					[
						'tab'       => 'plugin-information',
						'plugin'    => $slug,
						'section'   => 'description',
						'TB_iframe' => 'true',
						'width'     => 600,
						'height'    => 550,
					],
					admin_url( 'plugin-install.php' )
				);

				$links[] = sprintf(
					'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s">%s</a>',
					esc_url( $details_url ),
					esc_attr__( 'More information about Easy Product Bundles for WooCommerce - AIM', 'epb-aim' ),
					esc_html__( 'View details', 'epb-aim' )
				);

				return $links;
			},
			10,
			2
		);
	}
}
