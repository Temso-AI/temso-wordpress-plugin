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
	 * Post meta key holding Temso's stable idempotency id for a created post.
	 *
	 * Written on create so a retried create — one whose success response Temso
	 * never received, so the retry still carries no externalId — resolves back to
	 * the same post and updates it instead of inserting a duplicate. The nonce
	 * cannot serve this role: it is unique per request (a retry re-signs with a
	 * fresh nonce), whereas this key is stable across every retry of the same
	 * logical publish.
	 */
	const CONTENT_ID_META = '_temso_content_id';

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

		$media = new Temso_Media();

		// Sideload the featured image BEFORE writing the post. Featured handling
		// is strict, so a failure here must abort the publish without having
		// created or updated a post — that avoids orphaned drafts and duplicate
		// posts on a backend retry. The media layer sets the right HTTP status so
		// the backend can tell a permanent 4xx from a retryable 5xx.
		$featured = $media->sideload_featured( $payload['featured_image'] );
		if ( is_wp_error( $featured ) ) {
			return $featured;
		}

		$post_id = $this->create_or_update_post( $payload );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Past this point the post exists, so the publish has effectively
		// succeeded: the remaining media work is best-effort and must never turn
		// a written post into a failed publish (which would orphan it, or
		// duplicate it on a backend retry of a create). Attach the featured
		// thumbnail and rehost Temso-bucket inline images; if rewritten content
		// fails to persist, the post simply keeps its hotlinked images.
		$rehost = $media->apply_to_post( $post_id, $payload['html'], $featured );
		if ( ! empty( $rehost['content_changed'] ) ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $rehost['html'],
				)
			);
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

		// html, title, slug, targetState, and contentId are required non-empty strings.
		foreach ( array( 'html', 'title', 'slug', 'targetState', 'contentId' ) as $field ) {
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
			if ( (string) (int) $external_id !== $external_id ) {
				return new WP_Error(
					'temso_publish_invalid_payload',
					'externalId must be a positive post ID string.',
					array( 'status' => 400 )
				);
			}
		}

		// contentId is Temso's stable idempotency key, sent on every request (create
		// and update). On a create it is stored as post meta so a retried create —
		// which still has no externalId, because Temso never got the first response —
		// resolves back to the same post instead of inserting a duplicate. The raw
		// value passed the required-field check above, but must be re-checked after
		// sanitizing: a value that sanitizes to '' would otherwise be stored as an
		// empty key that later empty-sanitizing payloads could match and clobber.
		$content_id = sanitize_text_field( $data['contentId'] );
		if ( '' === $content_id ) {
			return new WP_Error(
				'temso_publish_invalid_payload',
				'Missing or invalid field: contentId.',
				array( 'status' => 400 )
			);
		}
		if ( strlen( $content_id ) > 191 ) {
			return new WP_Error(
				'temso_publish_invalid_payload',
				'contentId must be 191 characters or fewer.',
				array( 'status' => 400 )
			);
		}

		$seo = $this->parse_seo( isset( $data['seo'] ) ? $data['seo'] : null );
		if ( is_wp_error( $seo ) ) {
			return $seo;
		}

		$featured_image = $this->parse_featured_image( isset( $data['featuredImage'] ) ? $data['featuredImage'] : null );
		if ( is_wp_error( $featured_image ) ) {
			return $featured_image;
		}

		return array(
			'title'          => sanitize_text_field( $data['title'] ),
			'slug'           => sanitize_title( $data['slug'] ),
			// Temso already sanitizes HTML, but wp_kses_post() is defense in
			// depth on the WordPress side.
			'html'           => wp_kses_post( $data['html'] ),
			'status'         => 'published' === $data['targetState'] ? 'publish' : 'draft',
			'external_id'    => $external_id,
			'content_id'     => $content_id,
			'seo'            => $seo,
			'featured_image' => $featured_image,
		);
	}

	/**
	 * Validate and sanitize the optional featured-image block.
	 *
	 * Shape is `{ "url": "https://…", "alt": "…" }`. The URL is required when the
	 * block is present and must be https — it is sideloaded into the media
	 * library, so a non-https or empty URL is a hard error rather than a silent
	 * skip. The whole block is absent when the piece has no cover image.
	 *
	 * @param mixed $featured Raw featuredImage value from the payload.
	 * @return array|WP_Error|null Sanitized { url, alt }, null when absent, or an
	 *                             error on invalid input.
	 */
	private function parse_featured_image( $featured ) {
		if ( null === $featured ) {
			return null;
		}

		if ( ! is_array( $featured ) ) {
			return new WP_Error(
				'temso_publish_invalid_payload',
				'featuredImage must be an object.',
				array( 'status' => 400 )
			);
		}

		$url = isset( $featured['url'] ) && is_string( $featured['url'] )
			? esc_url_raw( trim( $featured['url'] ), array( 'https' ) )
			: '';
		if ( '' === $url ) {
			return new WP_Error(
				'temso_publish_invalid_payload',
				'featuredImage.url must be a non-empty https URL.',
				array( 'status' => 400 )
			);
		}

		$alt = isset( $featured['alt'] ) && is_string( $featured['alt'] )
			? sanitize_text_field( $featured['alt'] )
			: '';

		return array(
			'url' => $url,
			'alt' => $alt,
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

		// robots: a free directive string like "noindex, nofollow". When present it
		// is authoritative, so it is always recorded (even "index, follow") to let
		// the writer set a definitive state and clear a stale one on re-publish.
		if ( isset( $seo['robots'] ) ) {
			if ( ! is_string( $seo['robots'] ) ) {
				return new WP_Error(
					'temso_publish_invalid_payload',
					'seo.robots must be a string.',
					array( 'status' => 400 )
				);
			}
			$out['robots'] = self::parse_robots( $seo['robots'] );
		}

		$open_graph = $this->parse_seo_object(
			isset( $seo['openGraph'] ) ? $seo['openGraph'] : null,
			'seo.openGraph',
			array(
				'title'       => 'sanitize_text_field',
				'description' => 'sanitize_textarea_field',
				'imageUrl'    => 'esc_url_raw',
			)
		);
		if ( is_wp_error( $open_graph ) ) {
			return $open_graph;
		}
		if ( ! empty( $open_graph ) ) {
			$out['openGraph'] = $open_graph;
		}

		$twitter = $this->parse_seo_object(
			isset( $seo['twitter'] ) ? $seo['twitter'] : null,
			'seo.twitter',
			array(
				'title'       => 'sanitize_text_field',
				'description' => 'sanitize_textarea_field',
				'imageUrl'    => 'esc_url_raw',
			)
		);
		if ( is_wp_error( $twitter ) ) {
			return $twitter;
		}
		// card is a constrained enum, not free text; validate and pass it through.
		if ( isset( $seo['twitter'] ) && is_array( $seo['twitter'] ) && isset( $seo['twitter']['card'] ) ) {
			$card = is_string( $seo['twitter']['card'] ) ? $seo['twitter']['card'] : '';
			if ( in_array( $card, array( 'summary', 'summary_large_image' ), true ) ) {
				$twitter['card'] = $card;
			}
		}
		if ( ! empty( $twitter ) ) {
			$out['twitter'] = $twitter;
		}

		return $out;
	}

	/**
	 * Sanitize a flat map of string fields, dropping empties.
	 *
	 * @param array  $source       Raw values to read from.
	 * @param array  $map          Field name => sanitizer callable.
	 * @param string $label_prefix Error-message prefix for the field path.
	 * @return array|WP_Error Sanitized non-empty fields, or an error on bad type.
	 */
	private function parse_seo_strings( array $source, array $map, $label_prefix ) {
		$out = array();
		foreach ( $map as $key => $sanitizer ) {
			if ( ! isset( $source[ $key ] ) ) {
				continue;
			}
			if ( ! is_string( $source[ $key ] ) ) {
				return new WP_Error(
					'temso_publish_invalid_payload',
					$label_prefix . $key . ' must be a string.',
					array( 'status' => 400 )
				);
			}
			$value = call_user_func( $sanitizer, $source[ $key ] );
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Validate and sanitize an optional nested SEO object (openGraph/twitter).
	 *
	 * @param mixed  $value Raw nested object, or null when absent.
	 * @param string $label Field path used in error messages.
	 * @param array  $map   Field name => sanitizer callable for the object strings.
	 * @return array|WP_Error Sanitized fields (possibly empty), or an error.
	 */
	private function parse_seo_object( $value, $label, array $map ) {
		if ( null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			return new WP_Error(
				'temso_publish_invalid_payload',
				$label . ' must be an object.',
				array( 'status' => 400 )
			);
		}

		return $this->parse_seo_strings( $value, $map, $label . '.' );
	}

	/**
	 * Parse a robots directive string into explicit noindex/nofollow flags.
	 *
	 * Tokenizes on commas/whitespace and matches the exact directives noindex,
	 * nofollow, and none (= noindex + nofollow) — substrings of unrelated tokens
	 * never match. Both flags are always returned so the caller can write a
	 * definitive robots state (and clear a stale one); "index, follow" yields
	 * both false rather than an empty result.
	 *
	 * @param string $robots Raw robots directive string.
	 * @return array{noindex:bool,nofollow:bool}
	 */
	private static function parse_robots( $robots ) {
		$flags = array(
			'noindex'  => false,
			'nofollow' => false,
		);

		$tokens = preg_split( '/[\s,]+/', strtolower( (string) $robots ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $tokens ) ) {
			return $flags;
		}

		foreach ( $tokens as $token ) {
			if ( 'none' === $token ) {
				$flags['noindex']  = true;
				$flags['nofollow'] = true;
			} elseif ( 'noindex' === $token ) {
				$flags['noindex'] = true;
			} elseif ( 'nofollow' === $token ) {
				$flags['nofollow'] = true;
			}
		}

		return $flags;
	}

	/**
	 * Create a new post, or update the existing one named by externalId.
	 *
	 * Target resolution:
	 *  1. externalId present  — update that post. A missing or wrong-type target is
	 *     an error, never a silent create, which would duplicate posts after a
	 *     stale Temso record or a deleted post.
	 *  2. no externalId but a known contentId — update the post already created for
	 *     that key. This is the idempotent-retry case: the first create succeeded
	 *     but its response never reached Temso, so the retry carries the same
	 *     contentId (and still no externalId) and must reuse, not duplicate.
	 *  3. otherwise — insert a new post.
	 *
	 * The resulting post records its contentId (CONTENT_ID_META) so a later retried
	 * create can find it.
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

		if ( null !== $payload['external_id'] ) {
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
		} else {
			// No externalId: look up a post already created for this contentId. A
			// hit is the idempotent-retry case (the first create's response never
			// reached Temso) — update that post rather than insert a duplicate.
			// Temso serializes pushes per publication (contentId is the publication
			// id, claimed before each send) and spaces retries minutes apart, so two
			// creates with the same contentId never run concurrently; this
			// lookup-then-insert therefore needs no cross-request lock.
			$reused = self::find_post_by_content_id( $payload['content_id'] );

			if ( $reused > 0 ) {
				$postarr['ID'] = $reused;
				$result        = wp_update_post( $postarr, true );
			} else {
				$result = wp_insert_post( $postarr, true );
			}
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

		update_post_meta( $post_id, self::CONTENT_ID_META, $payload['content_id'] );

		return $post_id;
	}

	/**
	 * Find the post previously created for a given Temso contentId.
	 *
	 * Exact-match lookup on CONTENT_ID_META across every post status — including
	 * trash — so a retried create resolves to the post the first attempt made
	 * whatever state it is in. Trash is deliberately included: the externalId update
	 * path already resolves (and revives) a trashed post via get_post(), so this
	 * path must match, or a retried create after a trash would insert a duplicate
	 * instead of reusing. 'any' omits trash, hence the explicit status list.
	 * Returns 0 when none exists.
	 *
	 * @param string $content_id Idempotency key stored in CONTENT_ID_META.
	 * @return int Post ID, or 0.
	 */
	private static function find_post_by_content_id( $content_id ) {
		$ids = get_posts(
			array(
				'post_type'              => 'post',
				'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' ),
				'numberposts'            => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact-match idempotency lookup on our own meta key; required to dedupe retried creates.
					array(
						'key'   => self::CONTENT_ID_META,
						'value' => (string) $content_id,
					),
				),
			)
		);

		return ( is_array( $ids ) && ! empty( $ids ) ) ? (int) $ids[0] : 0;
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

		// Open Graph, Twitter, and robots live under plugin-specific keys.
		if ( $yoast ) {
			$this->write_yoast_extended_meta( $post_id, $seo );
		}
		if ( $rank_math ) {
			$this->write_rank_math_extended_meta( $post_id, $seo );
		}
	}

	/**
	 * Write Yoast Open Graph, Twitter, and robots post meta.
	 *
	 * Yoast keeps each surface under its own meta key and stores robots
	 * noindex/nofollow as separate per-post flags. Only set fields are written so
	 * an unset directive keeps Yoast's default behaviour.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $seo     Sanitized SEO fields.
	 */
	private function write_yoast_extended_meta( $post_id, array $seo ) {
		$open_graph = isset( $seo['openGraph'] ) ? $seo['openGraph'] : array();
		if ( ! empty( $open_graph['title'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-title', $open_graph['title'] );
		}
		if ( ! empty( $open_graph['description'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-description', $open_graph['description'] );
		}
		if ( ! empty( $open_graph['imageUrl'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', $open_graph['imageUrl'] );
		}

		$twitter = isset( $seo['twitter'] ) ? $seo['twitter'] : array();
		if ( ! empty( $twitter['title'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_twitter-title', $twitter['title'] );
		}
		if ( ! empty( $twitter['description'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_twitter-description', $twitter['description'] );
		}
		if ( ! empty( $twitter['imageUrl'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_twitter-image', $twitter['imageUrl'] );
		}

		// robots is authoritative when present: write a definitive value for both
		// flags so a prior noindex/nofollow is cleared on re-publish ( '0' = default ).
		if ( isset( $seo['robots'] ) ) {
			$robots = $seo['robots'];
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', ! empty( $robots['noindex'] ) ? '1' : '0' );
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', ! empty( $robots['nofollow'] ) ? '1' : '0' );
		}
	}

	/**
	 * Write Rank Math Open Graph, Twitter, and robots post meta.
	 *
	 * Rank Math reuses the Facebook/OG values for Twitter unless told otherwise,
	 * so providing any Twitter field opts the post into custom Twitter data.
	 * Robots directives are stored as an array; only constrained directives are
	 * written so an unset directive keeps Rank Math's default.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $seo     Sanitized SEO fields.
	 */
	private function write_rank_math_extended_meta( $post_id, array $seo ) {
		$open_graph = isset( $seo['openGraph'] ) ? $seo['openGraph'] : array();
		if ( ! empty( $open_graph['title'] ) ) {
			update_post_meta( $post_id, 'rank_math_facebook_title', $open_graph['title'] );
		}
		if ( ! empty( $open_graph['description'] ) ) {
			update_post_meta( $post_id, 'rank_math_facebook_description', $open_graph['description'] );
		}
		if ( ! empty( $open_graph['imageUrl'] ) ) {
			update_post_meta( $post_id, 'rank_math_facebook_image', $open_graph['imageUrl'] );
		}

		$twitter     = isset( $seo['twitter'] ) ? $seo['twitter'] : array();
		$has_twitter = ! empty( $twitter['title'] ) || ! empty( $twitter['description'] )
			|| ! empty( $twitter['imageUrl'] ) || ! empty( $twitter['card'] );
		if ( $has_twitter ) {
			// Without this Rank Math mirrors the Facebook/OG values for Twitter.
			update_post_meta( $post_id, 'rank_math_twitter_use_facebook', 'off' );
		}
		if ( ! empty( $twitter['title'] ) ) {
			update_post_meta( $post_id, 'rank_math_twitter_title', $twitter['title'] );
		}
		if ( ! empty( $twitter['description'] ) ) {
			update_post_meta( $post_id, 'rank_math_twitter_description', $twitter['description'] );
		}
		if ( ! empty( $twitter['imageUrl'] ) ) {
			update_post_meta( $post_id, 'rank_math_twitter_image', $twitter['imageUrl'] );
		}
		if ( ! empty( $twitter['card'] ) ) {
			// Rank Math card slugs differ from the payload's: summary -> summary_card.
			$card = 'summary' === $twitter['card'] ? 'summary_card' : 'summary_large_image';
			update_post_meta( $post_id, 'rank_math_twitter_card_type', $card );
		}

		// robots is authoritative when present: write the full directive array so a
		// prior noindex is overwritten by index on re-publish.
		if ( isset( $seo['robots'] ) ) {
			$robots     = $seo['robots'];
			$directives = array( ! empty( $robots['noindex'] ) ? 'noindex' : 'index' );
			if ( ! empty( $robots['nofollow'] ) ) {
				$directives[] = 'nofollow';
			}
			update_post_meta( $post_id, 'rank_math_robots', $directives );
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
