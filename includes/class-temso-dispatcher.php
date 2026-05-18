<?php
/**
 * Sends a batch of events to the configured Temso ingest endpoint.
 *
 * The request is non-blocking: WordPress hands it to the OS and returns
 * immediately, so the visitor's response is never delayed by the upload.
 * Delivery is best-effort — a failed batch is dropped, not retried.
 *
 * @package Temso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Temso_Dispatcher {

	const LAST_SENT_OPTION = 'temso_last_sent_at';

	/**
	 * @param array $events List of normalized events.
	 */
	public function send( array $events ) {
		if ( empty( $events ) ) {
			return;
		}

		$settings = Temso_Settings::get();
		if ( empty( $settings['ingest_url'] ) || empty( $settings['api_key'] ) ) {
			return;
		}

		$payload = array( 'events' => array_values( $events ) );
		if ( is_multisite() ) {
			$payload['site'] = (string) get_current_blog_id();
		}

		wp_remote_post(
			$settings['ingest_url'],
			array(
				'blocking'  => false,
				'timeout'   => 0.01,
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $settings['api_key'],
				),
				'body'      => wp_json_encode( $payload ),
				'sslverify' => true,
			)
		);

		update_option( self::LAST_SENT_OPTION, time(), false );
	}
}
