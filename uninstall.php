<?php
/**
 * Removes all plugin data when the plugin is deleted from the WordPress admin.
 *
 * @package Temso
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Pull option/transient names from the classes so they can't drift from the
// code that writes them. These files only define classes (no side effects).
require_once __DIR__ . '/includes/class-temso-buffer.php';
require_once __DIR__ . '/includes/class-temso-dispatcher.php';
require_once __DIR__ . '/includes/class-temso-settings.php';
require_once __DIR__ . '/includes/class-temso-updater.php';

$temso_options = array(
	Temso_Settings::OPTION,
	Temso_Buffer::EVENTS_OPTION,
	Temso_Buffer::STARTED_OPTION,
	Temso_Dispatcher::LAST_SENT_OPTION,
);

$temso_purge = static function () use ( $temso_options ) {
	foreach ( $temso_options as $temso_option ) {
		delete_option( $temso_option );
	}
	delete_site_transient( Temso_Updater::CACHE_KEY );
};

$temso_purge();

// Multisite: clear the same data on every site in the network.
if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $temso_site_id ) {
		switch_to_blog( $temso_site_id );
		$temso_purge();
		restore_current_blog();
	}
}
