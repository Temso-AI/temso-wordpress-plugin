<?php
/**
 * Plugin Name:       Temso AI
 * Plugin URI:        https://temso.ai
 * Description:        Captures front-end requests at your WordPress origin and streams them to Temso so AI-crawler and bot traffic shows up in your Temso dashboard. No CDN, theme, or code changes.
 * x-release-please-start-version
 * Version:           0.1.0
 * x-release-please-end
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Temso
 * Author URI:        https://temso.ai
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       temso-ai
 *
 * @package Temso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TEMSO_VERSION', '0.1.0' ); // x-release-please-version.
define( 'TEMSO_FILE', __FILE__ );
define( 'TEMSO_PATH', plugin_dir_path( __FILE__ ) );

// Buffer flush thresholds. A tracked request only triggers an HTTP send once
// the buffer reaches TEMSO_BATCH_MAX_SIZE events or its oldest event is older
// than TEMSO_BATCH_MAX_AGE seconds — every other request is a fast option
// write with no outbound request.
if ( ! defined( 'TEMSO_BATCH_MAX_SIZE' ) ) {
	define( 'TEMSO_BATCH_MAX_SIZE', 100 );
}
if ( ! defined( 'TEMSO_BATCH_MAX_AGE' ) ) {
	define( 'TEMSO_BATCH_MAX_AGE', 60 );
}

require_once TEMSO_PATH . 'includes/class-temso-buffer.php';
require_once TEMSO_PATH . 'includes/class-temso-dispatcher.php';
require_once TEMSO_PATH . 'includes/class-temso-settings.php';
require_once TEMSO_PATH . 'includes/class-temso-cache-detect.php';
require_once TEMSO_PATH . 'includes/class-temso-updater.php';
require_once TEMSO_PATH . 'includes/class-temso-plugin.php';

register_deactivation_hook(
	__FILE__,
	static function () {
		( new Temso_Buffer() )->flush_now();
	}
);

add_action(
	'plugins_loaded',
	static function () {
		( new Temso_Plugin() )->boot();
		( new Temso_Updater() )->boot();
		if ( is_admin() ) {
			( new Temso_Settings() )->boot();
			( new Temso_Cache_Detect() )->boot();
		}
	}
);
