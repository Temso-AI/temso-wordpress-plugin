<?php
/**
 * Admin notice shown when a full-page caching plugin is active.
 *
 * Cached page views never reach PHP, so Temso only sees cache misses. This is
 * informational and dismissible — it never blocks the admin.
 *
 * @package Temso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warns the admin when a full-page caching plugin is active.
 */
class Temso_Cache_Detect {

	const DISMISS_META  = 'temso_cache_notice_dismissed';
	const DISMISS_PARAM = 'temso_dismiss_cache_notice';

	/**
	 * Plugin file => human label.
	 *
	 * @var array
	 */
	private $known = array(
		'wp-rocket/wp-rocket.php'             => 'WP Rocket',
		'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
		'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
		'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
	);

	/**
	 * Register the admin-notice and dismissal hooks.
	 */
	public function boot() {
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss' ) );
	}

	/**
	 * Persist the dismissal when the notice's dismiss link is clicked.
	 */
	public function maybe_dismiss() {
		if ( empty( $_GET[ self::DISMISS_PARAM ] ) ) {
			return;
		}
		if ( ! check_admin_referer( self::DISMISS_PARAM ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
	}

	/**
	 * Render the cache-detected notice for capable, non-dismissed users.
	 */
	public function maybe_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}

		$active = $this->detect();
		if ( empty( $active ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( self::DISMISS_PARAM, 1 ),
			self::DISMISS_PARAM
		);
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: caching plugin name(s). */
						__( 'Temso detected %s. Cached pages are served without running PHP, so Temso only measures cache misses — expect lower counts than your total traffic.', 'temso-ai' ),
						implode( ', ', $active )
					)
				);
				?>
				<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'temso-ai' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Detect which known full-page caching plugins are active.
	 *
	 * @return string[] Labels of active known caching plugins.
	 */
	private function detect() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$found = array();
		foreach ( $this->known as $file => $label ) {
			if ( is_plugin_active( $file ) ) {
				$found[] = $label;
			}
		}

		return $found;
	}
}
