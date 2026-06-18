<?php
/**
 * Inbound publishing API.
 *
 * Temso is an external server-to-server caller: it sends signed requests to the
 * customer's WordPress site to create or update posts. Authentication is an
 * HMAC over `timestamp.nonce.rawBody` using the shared secret saved in
 * Settings — never WordPress cookies or admin capabilities. This is a write API
 * to WordPress, so every request is verified, replay-protected, and sanitized.
 *
 * @package Temso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Temso REST endpoints and handles signed publish requests.
 */
class Temso_Publisher {

	/**
	 * How far a request timestamp may drift from server time, in seconds.
	 *
	 * Doubles as the nonce-replay transient lifetime: a nonce only needs to be
	 * remembered for as long as a replay of its signature could still validate.
	 */
	const SIGNATURE_TOLERANCE_SECONDS = 300;

	/**
	 * Prefix for the per-nonce replay-protection transients.
	 */
	const NONCE_TRANSIENT_PREFIX = 'temso_publish_nonce_';

	/**
	 * Register the REST routes on `rest_api_init`.
	 */
	public function boot() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the `temso/v1` capabilities and publish routes.
	 */
	public function register_routes() {
		register_rest_route(
			'temso/v1',
			'/capabilities',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'capabilities' ),
				// Public on purpose: Temso calls this before the connection is
				// saved and cannot sign it yet.
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'temso/v1',
			'/publish',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'publish' ),
				'permission_callback' => array( $this, 'can_publish' ),
			)
		);
	}

	/**
	 * Report plugin version and feature support.
	 *
	 * `publish` is only reported true once a shared secret is configured, so a
	 * Temso connection attempt fails early and clearly when the site has not
	 * been set up as a publishing destination yet.
	 *
	 * @return array
	 */
	public function capabilities() {
		$settings = Temso_Settings::get();
		$secret   = isset( $settings['publish_secret'] ) ? (string) $settings['publish_secret'] : '';

		return array(
			'pluginVersion' => TEMSO_VERSION,
			'features'      => self::capability_features( $secret ),
		);
	}

	/**
	 * Compute the capability feature flags for a given publish secret.
	 *
	 * Shared by the public capabilities endpoint and the setup-token claim in
	 * Temso_Settings, so the plugin reports identical features to Temso whether
	 * it is answering an inbound capabilities request or describing itself in a
	 * claim payload. `publish` is true exactly when a secret is configured.
	 *
	 * @param string $secret Configured publish shared secret ('' when unset).
	 * @return array{publish:bool,yoastMeta:bool,rankMathMeta:bool}
	 */
	public static function capability_features( $secret ) {
		return array(
			'publish'      => '' !== (string) $secret,
			'yoastMeta'    => self::is_yoast_active(),
			'rankMathMeta' => self::is_rank_math_active(),
		);
	}

	/**
	 * Permission callback for the publish route.
	 *
	 * Requires a configured secret and a valid HMAC signature. Returning a
	 * WP_Error here lets the REST server reject the request with the right
	 * status before the publish callback ever runs.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function can_publish( WP_REST_Request $request ) {
		$settings = Temso_Settings::get();
		$secret   = isset( $settings['publish_secret'] ) ? (string) $settings['publish_secret'] : '';

		if ( '' === $secret ) {
			return new WP_Error(
				'temso_publish_not_configured',
				'Publishing is not configured on this site.',
				array( 'status' => 403 )
			);
		}

		return $this->verify_signature( $request, $secret );
	}

	/**
	 * Create or update a post from a verified publish request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array|WP_Error
	 */
	public function publish( WP_REST_Request $request ) {
		$payload = $this->parse_payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$post_id = $this->create_or_update_post( $payload );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// SEO metadata is best-effort: a failure to write it must never fail the
		// publish, and it is skipped entirely when no SEO plugin is active.
		$this->write_seo_meta( $post_id, $payload['seo'] );

		$response = array(
			'externalId'  => (string) $post_id,
			'remoteState' => 'publish' === $payload['status'] ? 'published' : 'draft',
		);

		// Omit externalUrl rather than return a false/empty value Temso would
		// reject as a malformed URL.
		$permalink = get_permalink( $post_id );
		if ( ! empty( $permalink ) ) {
			$response['externalUrl'] = $permalink;
		}

		return $response;
	}

	/**
	 * Verify the HMAC signature, timestamp window, and nonce replay protection.
	 *
	 * The signed string is `timestamp + "." + nonce + "." + rawBody`, matching
	 * exactly what the Temso backend signs.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param string          $secret  Configured shared secret.
	 * @return true|WP_Error
	 */
	private function verify_signature( WP_REST_Request $request, $secret ) {
		$signature = (string) $request->get_header( 'x-temso-signature' );
		$timestamp = (string) $request->get_header( 'x-temso-timestamp' );
		$nonce     = (string) $request->get_header( 'x-temso-nonce' );

		if ( '' === $signature || '' === $timestamp || '' === $nonce ) {
			return new WP_Error(
				'temso_publish_missing_header',
				'Missing signature, timestamp, or nonce header.',
				array( 'status' => 401 )
			);
		}

		// The timestamp must be integer Unix seconds and within the window.
		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::SIGNATURE_TOLERANCE_SECONDS ) {
			return new WP_Error(
				'temso_publish_stale_signature',
				'Request timestamp is outside the allowed window.',
				array( 'status' => 401 )
			);
		}

		// Signature header is `sha256=<hex hmac>`.
		if ( 0 !== strpos( $signature, 'sha256=' ) ) {
			return new WP_Error(
				'temso_publish_invalid_signature',
				'Malformed signature header.',
				array( 'status' => 401 )
			);
		}
		$provided = substr( $signature, strlen( 'sha256=' ) );

		$expected = hash_hmac(
			'sha256',
			$timestamp . '.' . $nonce . '.' . $request->get_body(),
			$secret
		);

		if ( ! hash_equals( $expected, $provided ) ) {
			return new WP_Error(
				'temso_publish_invalid_signature',
				'Signature verification failed.',
				array( 'status' => 401 )
			);
		}

		// Replay protection. Only signed-valid requests reach this point, so an
		// attacker cannot burn nonces with unsigned noise. The nonce is hashed
		// to keep the transient key well under WordPress's option-name limit.
		$transient_key = self::NONCE_TRANSIENT_PREFIX . hash( 'sha256', $nonce );
		if ( false !== get_transient( $transient_key ) ) {
			return new WP_Error(
				'temso_publish_replayed_nonce',
				'This request has already been processed.',
				array( 'status' => 401 )
			);
		}
		set_transient( $transient_key, 1, self::SIGNATURE_TOLERANCE_SECONDS );

		return true;
	}

	/**
	 * Decode, validate, and sanitize the publish payload.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array|WP_Error Sanitized payload, or an error on invalid input.
	 */
	private function parse_payload( WP_REST_Request $request ) {
		$data = json_decode( $request->get_body(), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'temso_publish_invalid_json',
				'Request body is not valid JSON.',
				array( 'status' => 400 )
			);
		}

		// html, title, slug, and targetState are required non-empty strings.
		foreach ( array( 'html', 'title', 'slug', 'targetState' ) as $field ) {
			if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) || '' === trim( $data[ $field ] ) ) {
				return new WP_Error(
					'temso_publish_invalid_payload',
					'Missing or invalid field: ' . $field . '.',
					array( 'status' => 400 )
				);
			}
		}

		if ( 'draft' !== $data['targetState'] && 'published' !== $data['targetState'] ) {
			return new WP_Error(
				'temso_publish_invalid_payload',
				'targetState must be "draft" or "published".',
				array( 'status' => 400 )
			);
		}

		// externalId is optional; when present it must be the exact positive
		// WordPress post ID string returned from an earlier publish response.
		$external_id = null;
		if ( isset( $data['externalId'] ) && '' !== $data['externalId'] ) {
			if (
				! is_string( $data['externalId'] )
				|| 1 !== preg_match( '/^[1-9][0-9]*$/', $data['externalId'] )
			) {
				return new WP_Error(
					'temso_publish_invalid_payload',
					'externalId must be a positive post ID string.',
					array( 'status' => 400 )
				);
			}
			$external_id = (string) $data['externalId'];
			if ( $external_id !== (string) (int) $external_id ) {
				return new WP_Error(
					'temso_publish_invalid_payload',
					'externalId must be a positive post ID string.',
					array( 'status' => 400 )
				);
			}
		}

		$seo = $this->parse_seo( isset( $data['seo'] ) ? $data['seo'] : null );
		if ( is_wp_error( $seo ) ) {
			return $seo;
		}

		return array(
			'title'       => sanitize_text_field( $data['title'] ),
			'slug'        => sanitize_title( $data['slug'] ),
			// Temso already sanitizes HTML, but wp_kses_post() is defense in
			// depth on the WordPress side.
			'html'        => wp_kses_post( $data['html'] ),
			'status'      => 'published' === $data['targetState'] ? 'publish' : 'draft',
			'external_id' => $external_id,
			'seo'         => $seo,
		);
	}

	/**
	 * Validate and sanitize the optional SEO block.
	 *
	 * @param mixed $seo Raw seo value from the payload.
	 * @return array|WP_Error Sanitized SEO fields, or an error on invalid input.
	 */
	private function parse_seo( $seo ) {
		if ( null === $seo ) {
			return array();
		}

		if ( ! is_array( $seo ) ) {
			return new WP_Error(
				'temso_publish_invalid_payload',
				'seo must be an object.',
				array( 'status' => 400 )
			);
		}

		$out = array();
		$map = array(
			'metaTitle'       => 'sanitize_text_field',
			'metaDescription' => 'sanitize_textarea_field',
			'canonicalUrl'    => 'esc_url_raw',
		);

		foreach ( $map as $key => $sanitizer ) {
			if ( ! isset( $seo[ $key ] ) ) {
				continue;
			}
			if ( ! is_string( $seo[ $key ] ) ) {
				return new WP_Error(
					'temso_publish_invalid_payload',
					'seo.' . $key . ' must be a string.',
					array( 'status' => 400 )
				);
			}
			$value = call_user_func( $sanitizer, $seo[ $key ] );
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Create a new post, or update the existing one named by externalId.
	 *
	 * A missing or wrong-type update target is an error, never a silent create —
	 * that would duplicate posts after a stale Temso record or a deleted post.
	 *
	 * @param array $payload Sanitized payload from parse_payload().
	 * @return int|WP_Error New/updated post ID, or an error.
	 */
	private function create_or_update_post( array $payload ) {
		$postarr = array(
			'post_type'    => 'post',
			'post_status'  => $payload['status'],
			'post_title'   => $payload['title'],
			'post_name'    => $payload['slug'],
			'post_content' => $payload['html'],
		);

		if ( null === $payload['external_id'] ) {
			$result = wp_insert_post( $postarr, true );
		} else {
			$post_id  = (int) $payload['external_id'];
			$existing = $post_id > 0 ? get_post( $post_id ) : null;

			if ( ! $existing ) {
				return new WP_Error(
					'temso_publish_missing_post',
					'No post exists with the given externalId.',
					array( 'status' => 404 )
				);
			}

			if ( 'post' !== $existing->post_type ) {
				return new WP_Error(
					'temso_publish_wrong_post_type',
					'The externalId does not refer to a post.',
					array( 'status' => 400 )
				);
			}

			$postarr['ID'] = $post_id;
			$result        = wp_update_post( $postarr, true );
		}

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'temso_publish_wp_error',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$post_id = (int) $result;
		if ( $post_id <= 0 ) {
			return new WP_Error(
				'temso_publish_wp_error',
				'WordPress did not return a post ID.',
				array( 'status' => 500 )
			);
		}

		return $post_id;
	}

	/**
	 * Write SEO metadata for whichever SEO plugins are active.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $seo     Sanitized SEO fields (may be empty).
	 */
	private function write_seo_meta( $post_id, array $seo ) {
		if ( empty( $seo ) ) {
			return;
		}

		$yoast     = self::is_yoast_active();
		$rank_math = self::is_rank_math_active();
		if ( ! $yoast && ! $rank_math ) {
			return;
		}

		// Each payload field maps to one Yoast key and one Rank Math key.
		$map = array(
			'metaTitle'       => array(
				'yoast'     => '_yoast_wpseo_title',
				'rank_math' => 'rank_math_title',
			),
			'metaDescription' => array(
				'yoast'     => '_yoast_wpseo_metadesc',
				'rank_math' => 'rank_math_description',
			),
			'canonicalUrl'    => array(
				'yoast'     => '_yoast_wpseo_canonical',
				'rank_math' => 'rank_math_canonical_url',
			),
		);

		foreach ( $map as $field => $keys ) {
			if ( ! isset( $seo[ $field ] ) || '' === $seo[ $field ] ) {
				continue;
			}
			if ( $yoast ) {
				update_post_meta( $post_id, $keys['yoast'], $seo[ $field ] );
			}
			if ( $rank_math ) {
				update_post_meta( $post_id, $keys['rank_math'], $seo[ $field ] );
			}
		}
	}

	/**
	 * Whether the Yoast SEO plugin is active.
	 *
	 * @return bool
	 */
	private static function is_yoast_active() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
	}

	/**
	 * Whether the Rank Math SEO plugin is active.
	 *
	 * @return bool
	 */
	private static function is_rank_math_active() {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}
}
