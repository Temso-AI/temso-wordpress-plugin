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

/*
 * A few WordPress helpers are thin wrappers over native PHP functions. The code
 * under test calls them directly, so they are defined here (rather than Brain
 * Monkey stubbed) and shared by every test file — Patchwork cannot redefine a
 * function declared at bootstrap, and no test needs to vary their behavior.
 */
if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Test wrapper: defer to the native parser.
	 *
	 * @param string $url       URL to parse.
	 * @param int    $component Component constant or -1 for all.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test shim mirroring wp_parse_url for pure unit tests.
	}
}

if ( ! function_exists( 'wp_parse_str' ) ) {
	/**
	 * Test wrapper: defer to the native parser.
	 *
	 * @param string $string Query string.
	 * @param array  $result Filled with the parsed pairs.
	 */
	function wp_parse_str( $string, &$result ) {
		parse_str( (string) $string, $result );
	}
}

if ( ! function_exists( 'wp_basename' ) ) {
	/**
	 * Test wrapper: defer to the native basename.
	 *
	 * @param string $path Path to take the basename of.
	 * @return string
	 */
	function wp_basename( $path ) {
		return basename( (string) $path );
	}
}
