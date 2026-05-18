<?php
/**
 * PHPUnit bootstrap.
 *
 * These are pure unit tests: WordPress is never loaded. Brain Monkey stubs
 * the handful of WP functions the code under test calls, so we define just
 * enough of the runtime contract (ABSPATH and the plugin's batch constants)
 * for the source files to be includable.
 *
 * @package Temso
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'TEMSO_BATCH_MAX_SIZE' ) ) {
	define( 'TEMSO_BATCH_MAX_SIZE', 100 );
}

if ( ! defined( 'TEMSO_BATCH_MAX_AGE' ) ) {
	define( 'TEMSO_BATCH_MAX_AGE', 60 );
}
