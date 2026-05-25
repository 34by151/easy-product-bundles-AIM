<?php
/**
 * GitHub-based auto-updater.
 *
 * Hooks into WordPress's native plugin update system so that new releases
 * published on GitHub appear in the WordPress Updates screen and can be
 * installed with a single click.
 *
 * Release tagging convention: v1.2.0 (the leading "v" is stripped when
 * comparing against the plugin's Version header).
 *
 * @package EasyProductBundlesAIM
 */

namespace EasyProductBundlesAIM;

defined( 'ABSPATH' ) || exit;

class GitHubUpdater {

	private const GITHUB_USER = '34by151';
	private const GITHUB_REPO = 'easy-product-bundles-AIM';
	private const CACHE_KEY   = 'epb_aim_github_release';
	private const CACHE_TTL   = 12 * HOUR_IN_SECONDS;
	private const CACHE_FAIL  = 5 * MINUTE_IN_SECONDS;

	private string $plugin_file;
	private string $plugin_basename;
	private string $plugin_slug;

	public function __construct( string $plugin_file ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->plugin_slug     = dirname( $this->plugin_basename );
	}

	public function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_post_install', [ $this, 'after_install' ], 10, 3 );
	}

	/**
	 * Inject update data into the WordPress update transient when a newer
	 * GitHub release exists.
	 *
	 * @param \stdClass $transient WordPress update transient.
	 * @return \stdClass
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest  = ltrim( $release['tag_name'], 'v' );
		$current = $transient->checked[ $this->plugin_basename ] ?? EPB_AIM_VERSION;

		if ( version_compare( $latest, $current, '>' ) ) {
			$transient->response[ $this->plugin_basename ] = (object) [
				'slug'         => $this->plugin_slug,
				'plugin'       => $this->plugin_basename,
				'new_version'  => $latest,
				'url'          => 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO,
				'package'      => $release['zipball_url'],
				'icons'        => [],
				'banners'      => [],
				'banners_rtl'  => [],
				'requires'     => '6.0',
				'requires_php' => '7.4',
				'tested'       => '6.8',
			];
		}

		return $transient;
	}

	/**
	 * Supply plugin metadata for the "View details" modal.
	 *
	 * @param false|\stdClass $result  Existing result (false = not yet found).
	 * @param string          $action  API action being requested.
	 * @param \stdClass       $args    Request arguments (includes slug).
	 * @return false|\stdClass
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || $this->plugin_slug !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'Easy Product Bundles for WooCommerce - AIM',
			'slug'          => $this->plugin_slug,
			'version'       => ltrim( $release['tag_name'], 'v' ),
			'author'        => '<a href="https://artinmetal.com.au">ArtInMetal.com.au</a>',
			'homepage'      => 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO,
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'tested'        => '6.8',
			'download_link' => $release['zipball_url'],
			'last_updated'  => $release['published_at'] ?? '',
			'sections'      => [
				'description' => 'Extends Easy Product Bundles for WooCommerce Pro with additional features: hide bundle item quantity controls and dynamic quantity linking between bundle items.',
				'changelog'   => $this->format_changelog( $release['body'] ?? '' ),
			],
		];
	}

	/**
	 * Rename the extracted GitHub zip folder to match the plugin slug.
	 *
	 * GitHub zips extract to "{user}-{repo}-{short_hash}/" rather than the
	 * plugin slug, so we move the folder into the correct location.
	 *
	 * @param bool  $response    Passthrough response value.
	 * @param array $hook_extra  Extra hook data (contains 'plugin' key).
	 * @param array $result      Upgrader result (contains 'destination' key).
	 * @return array|bool
	 */
	public function after_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $response;
		}

		$proper_destination = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $this->plugin_slug;
		$wp_filesystem->move( $result['destination'], $proper_destination );
		$result['destination'] = $proper_destination;

		return $result;
	}

	/**
	 * Fetch the latest GitHub release, caching the result as a transient.
	 *
	 * @return array|null Release data array, or null on failure.
	 */
	private function get_latest_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );

		if ( false !== $cached ) {
			return ! empty( $cached ) ? $cached : null;
		}

		$url      = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			self::GITHUB_USER,
			self::GITHUB_REPO
		);
		$response = wp_remote_get(
			$url,
			[
				'headers' => [
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				],
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, [], self::CACHE_FAIL );
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $release['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, [], self::CACHE_FAIL );
			return null;
		}

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	private function format_changelog( string $body ): string {
		return nl2br( esc_html( $body ) );
	}
}
