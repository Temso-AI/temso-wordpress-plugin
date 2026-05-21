<?php
/**
 * Plugin Name:       Temso AI
 * Plugin URI:        https://github.com/Temso-AI/temso-wordpress-plugin
 * Description:        Captures front-end requests at your WordPress origin and streams them to Temso so AI-crawler and bot traffic shows up in your Temso dashboard. No CDN, theme, or code changes.
 * x-release-please-start-version
 * Version:           0.4.4
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

define( 'TEMSO_VERSION', '0.4.4' ); // x-release-please-version.
define( 'TEMSO_FILE', __FILE__ );
define( 'TEMSO_PATH', plugin_dir_path( __FILE__ ) );

// temso:wporg-exclude-start.
// GitHub-distributed builds ship includes/github-distribution.php, which
// defines TEMSO_GH_REPO and so enables the Temso_Updater self-updater.
// The whole block is stripped from the wordpress.org build by
// bin/build.sh --wporg so that package has zero updater references.
if ( file_exists( TEMSO_PATH . 'includes/github-distribution.php' ) ) {
	require_once TEMSO_PATH . 'includes/github-distribution.php';
}
// temso:wporg-exclude-end.

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
// temso:wporg-exclude-start.
if ( file_exists( TEMSO_PATH . 'includes/class-temso-updater.php' ) ) {
	require_once TEMSO_PATH . 'includes/class-temso-updater.php';
}
// temso:wporg-exclude-end.
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
		// temso:wporg-exclude-start.
		if ( class_exists( 'Temso_Updater' ) ) {
			( new Temso_Updater() )->boot();
		}
		// temso:wporg-exclude-end.
		if ( is_admin() ) {
			( new Temso_Settings() )->boot();
			( new Temso_Cache_Detect() )->boot();
		}
	}
);
