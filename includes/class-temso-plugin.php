<?php
/**
 * Request capture.
 *
 * Records metadata for every HTTP request WordPress serves and queues it for
 * batched delivery to Temso (mirrors the Cloudflare connector — only WP-CLI
 * is skipped). The final HTTP status is only known at `shutdown`, so the
 * event is finalized there.
 *
 * @package Temso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Temso_Plugin {

	/**
	 * Partial event captured at `init`, completed at `shutdown`.
	 *
	 * @var array|null
	 */
	private $pending = null;

	public function boot() {
		add_action( 'init', array( $this, 'capture' ), 1 );
		add_action( 'shutdown', array( $this, 'finalize' ), PHP_INT_MAX );
	}

	/**
	 * Decide whether this request is a trackable front-end page load and, if
	 * so, snapshot what we know now.
	 */
	public function capture() {
		if ( $this->should_skip() ) {
			return;
		}

		$settings = Temso_Settings::get();
		if ( empty( $settings['enabled'] ) || empty( $settings['ingest_url'] ) || empty( $settings['api_key'] ) ) {
			return;
		}

		// All values are attacker-controllable request headers; sanitize before
		// they leave the site.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		$this->pending = array(
			'ts'      => (int) round( microtime( true ) * 1000 ),
			'ua'      => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'method'  => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET',
			'url'     => ( is_ssl() ? 'https' : 'http' ) . '://' . $host . $uri,
			'referer' => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : null,
			'ip'      => $this->client_ip(),
		);
	}

	/**
	 * Attach the response status and hand the event to the buffer. Flushes
	 * when the buffer is due.
	 */
	public function finalize() {
		if ( null === $this->pending ) {
			return;
		}

		$status                  = http_response_code();
		$this->pending['status'] = is_int( $status ) ? $status : 200;

		$buffer = new Temso_Buffer();
		$due    = $buffer->add( $this->pending );
		$this->pending = null;

		if ( $due ) {
			( new Temso_Dispatcher() )->send( $buffer->drain() );
		}
	}

	/**
	 * Capture every request WordPress serves over HTTP — front-end, wp-admin,
	 * REST, AJAX, login, xmlrpc, cron pings — to mirror the Cloudflare edge
	 * connector, which sends all zone traffic. Temso decides server-side what
	 * to retain (bot classification) and hashes the IP; nothing is filtered
	 * here. Only genuine non-HTTP execution (WP-CLI) is skipped, since the
	 * edge never sees that either.
	 *
	 * @return bool
	 */
	private function should_skip() {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * Visitor IP from the direct connection. Forwarded headers
	 * (`X-Forwarded-For`, `CF-Connecting-IP`) are spoofable by any client
	 * unless the site is genuinely behind a trusted proxy, so they are only
	 * consulted when the operator opts in by defining `TEMSO_TRUSTED_PROXY`.
	 * Temso hashes this server-side; it is never stored raw.
	 *
	 * @return string|null
	 */
	private function client_ip() {
		$candidates = array( 'REMOTE_ADDR' );
		if ( defined( 'TEMSO_TRUSTED_PROXY' ) && TEMSO_TRUSTED_PROXY ) {
			array_unshift( $candidates, 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' );
		}

		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			// X-Forwarded-For may be a comma-separated chain; the client is first.
			$value = trim( explode( ',', $value )[0] );
			$ip    = filter_var( $value, FILTER_VALIDATE_IP );
			if ( false !== $ip ) {
				return $ip;
			}
		}

		return null;
	}
}
