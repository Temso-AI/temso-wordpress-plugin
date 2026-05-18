<?php
/**
 * GitHub-release update checker for copies installed *outside* the
 * WordPress.org directory.
 *
 * Inert unless `TEMSO_GH_REPO` is defined (e.g. "temso/temso-wordpress-plugin")
 * — the wordpress.org-distributed build never defines it, so WP core's own
 * updater stays the single source of truth there and the two never collide.
 *
 * Each tagged GitHub release must attach a packaged `temso-ai.zip` whose top
 * folder is `temso-ai/` (a bare GitHub source zip extracts to `repo-tag/` and
 * would install under the wrong plugin slug).
 *
 * @package Temso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Temso_Updater {

	const CACHE_KEY = 'temso_gh_release';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	const FAIL_TTL  = 15 * MINUTE_IN_SECONDS;

	/**
	 * "owner/repo" from the TEMSO_GH_REPO constant.
	 *
	 * @var string
	 */
	private $repo;

	/**
	 * Plugin basename, e.g. "temso-ai/temso-ai.php".
	 *
	 * @var string
	 */
	private $basename;

	public function __construct() {
		$this->repo     = defined( 'TEMSO_GH_REPO' ) ? (string) TEMSO_GH_REPO : '';
		$this->basename = plugin_basename( TEMSO_FILE );
	}

	public function boot() {
		if ( '' === $this->repo ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
	}

	/**
	 * Latest release from GitHub, cached. Returns null on any failure.
	 *
	 * @return array{version:string,zip:string,url:string}|null
	 */
	private function latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( 'none' === $cached ) {
			return null;
		}
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Cache failures too, so an unreachable/rate-limited GitHub doesn't
		// trigger a slow remote call on every admin page load.
		$fail = static function () {
			set_site_transient( self::CACHE_KEY, 'none', self::FAIL_TTL );
			return null;
		};

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo . '/releases/latest',
			array(
				'timeout' => 3,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'temso-wp-plugin',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $fail();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return $fail();
		}

		$zip = '';
		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && '.zip' === substr( $asset['name'], -4 ) ) {
					$zip = esc_url_raw( (string) $asset['browser_download_url'] );
					break;
				}
			}
		}

		// Only install packages served from GitHub — the release JSON is
		// remote-controlled, and the URL is handed straight to core's plugin
		// installer with admin privileges.
		$host = (string) wp_parse_url( $zip, PHP_URL_HOST );
		if ( '' === $zip || ! preg_match( '/(^|\.)github(usercontent)?\.com$/', $host ) ) {
			return $fail();
		}

		$release = array(
			'version' => preg_replace( '/[^0-9A-Za-z.\-]/', '', ltrim( (string) $data['tag_name'], 'vV' ) ),
			'zip'     => $zip,
			'url'     => isset( $data['html_url'] ) ? esc_url_raw( (string) $data['html_url'] ) : '',
		);

		set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * @param mixed $transient The update_plugins site transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->latest_release();
		if ( null === $release || ! version_compare( $release['version'], TEMSO_VERSION, '>' ) ) {
			return $transient;
		}

		$transient->response[ $this->basename ] = (object) array(
			'id'          => $this->basename,
			'slug'        => 'temso-ai',
			'plugin'      => $this->basename,
			'new_version' => $release['version'],
			'package'     => $release['zip'],
			'url'         => $release['url'],
			'icons'       => array(),
			'tested'      => '',
			'requires'    => '',
		);

		return $transient;
	}

	/**
	 * @param mixed  $result The plugins_api result.
	 * @param string $action The requested action.
	 * @param object $args   Request args.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'temso-ai' !== $args->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Temso',
			'slug'          => 'temso-ai',
			'version'       => $release['version'],
			'download_link' => $release['zip'],
			'sections'      => array(
				'description' => esc_html__( 'Update served from GitHub releases.', 'temso-ai' ),
			),
		);
	}
}
