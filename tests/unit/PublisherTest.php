<?php
/**
 * Unit tests for Temso_Publisher.
 *
 * These are pure unit tests: WordPress is never loaded. Brain Monkey stubs the
 * WP functions the publisher calls, and the few WordPress runtime classes it
 * touches (WP_Error, WP_REST_Request) are stood in with minimal fakes below.
 * WPSEO_Options and RankMath are defined so the SEO detection reports active
 * and the metadata writes can be asserted.
 *
 * @package Temso
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'TEMSO_VERSION' ) ) {
	define( 'TEMSO_VERSION', '1.0.1' );
}

require_once dirname( __DIR__, 2 ) . '/includes/class-temso-settings.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-temso-media.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-temso-publisher.php';

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stand-in for the WordPress WP_Error class.
	 */
	class WP_Error {

		/** @var string */
		public $code;
		/** @var string */
		public $message;
		/** @var mixed */
		public $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal stand-in for WP_REST_Request: just headers and a raw body.
	 */
	class WP_REST_Request {

		/** @var array<string,string> */
		private $headers = array();
		/** @var string */
		private $body = '';

		public function set_header( $name, $value ) {
			$this->headers[ self::canonical( $name ) ] = $value;
		}

		public function get_header( $name ) {
			$key = self::canonical( $name );
			return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null;
		}

		public function set_body( $body ) {
			$this->body = $body;
		}

		public function get_body() {
			return $this->body;
		}

		private static function canonical( $name ) {
			return strtolower( str_replace( '-', '_', $name ) );
		}
	}
}

if ( ! class_exists( 'WPSEO_Options' ) ) {
	/**
	 * Presence of this class makes Temso_Publisher treat Yoast as active.
	 */
	class WPSEO_Options {}
}

if ( ! class_exists( 'RankMath' ) ) {
	/**
	 * Presence of this class makes Temso_Publisher treat Rank Math as active.
	 */
	class RankMath {}
}

final class PublisherTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Passthrough sanitizers and an is_wp_error that recognises our fake.
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_title' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		// Pass-through filters so Temso_Media's host check runs against defaults.
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return (int) $value;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/* ----------------------------------------------------------------- *
	 * Helpers
	 * ----------------------------------------------------------------- */

	private function stub_secret( string $secret ): void {
		Functions\when( 'get_option' )->alias(
			static function () use ( $secret ) {
				return array( 'publish_secret' => $secret );
			}
		);
	}

	private function sign( string $timestamp, string $nonce, string $body, string $secret ): string {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $body, $secret );
	}

	private function encode( array $data ): string {
		return json_encode( $data );
	}

	private function request( string $body, array $headers ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_body( $body );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return $request;
	}

	private function assert_error_code( string $expected, $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $expected, $result->get_error_code() );
	}

	/* ----------------------------------------------------------------- *
	 * Capabilities
	 * ----------------------------------------------------------------- */

	public function test_capabilities_returns_version_and_feature_flags(): void {
		$this->stub_secret( 'a-configured-shared-secret-0001' );

		$caps = ( new Temso_Publisher() )->capabilities();

		$this->assertSame( TEMSO_VERSION, $caps['pluginVersion'] );
		$this->assertArrayHasKey( 'publish', $caps['features'] );
		$this->assertArrayHasKey( 'media', $caps['features'] );
		$this->assertArrayHasKey( 'yoastMeta', $caps['features'] );
		$this->assertArrayHasKey( 'rankMathMeta', $caps['features'] );
	}

	public function test_capabilities_reports_media_true(): void {
		$this->stub_secret( 'a-configured-shared-secret-0001' );

		$caps = ( new Temso_Publisher() )->capabilities();

		// media is a static code capability, advertised regardless of the secret.
		$this->assertTrue( $caps['features']['media'] );
	}

	public function test_capabilities_reports_media_true_without_secret(): void {
		$this->stub_secret( '' );

		$caps = ( new Temso_Publisher() )->capabilities();

		$this->assertTrue( $caps['features']['media'] );
		$this->assertFalse( $caps['features']['publish'] );
	}

	public function test_capabilities_reports_publish_false_without_secret(): void {
		$this->stub_secret( '' );

		$caps = ( new Temso_Publisher() )->capabilities();

		$this->assertFalse( $caps['features']['publish'] );
	}

	public function test_capabilities_reports_publish_true_with_secret(): void {
		$this->stub_secret( 'another-shared-secret-value-02' );

		$caps = ( new Temso_Publisher() )->capabilities();

		$this->assertTrue( $caps['features']['publish'] );
	}

	/* ----------------------------------------------------------------- *
	 * Signature verification (via can_publish)
	 * ----------------------------------------------------------------- */

	public function test_valid_signature_is_accepted(): void {
		$secret = 'shared-secret-value-1234567890';
		$this->stub_secret( $secret );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$body  = '{"html":"x"}';
		$ts    = (string) time();
		$nonce = 'nonce-aaa';
		$request = $this->request(
			$body,
			array(
				'x-temso-signature' => $this->sign( $ts, $nonce, $body, $secret ),
				'x-temso-timestamp' => $ts,
				'x-temso-nonce'     => $nonce,
			)
		);

		$this->assertTrue( ( new Temso_Publisher() )->can_publish( $request ) );
	}

	public function test_old_body_only_hmac_is_rejected(): void {
		$secret = 'shared-secret-value-1234567890';
		$this->stub_secret( $secret );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$body  = '{"html":"x"}';
		$ts    = (string) time();
		$nonce = 'nonce-bbb';
		// Sign only the body, as an older/naive client would have.
		$bad_signature = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		$request       = $this->request(
			$body,
			array(
				'x-temso-signature' => $bad_signature,
				'x-temso-timestamp' => $ts,
				'x-temso-nonce'     => $nonce,
			)
		);

		$this->assert_error_code( 'temso_publish_invalid_signature', ( new Temso_Publisher() )->can_publish( $request ) );
	}

	public function test_missing_signature_header_is_rejected(): void {
		$this->stub_secret( 'shared-secret-value-1234567890' );

		$ts      = (string) time();
		$request = $this->request(
			'{"html":"x"}',
			array(
				'x-temso-timestamp' => $ts,
				'x-temso-nonce'     => 'nonce-ccc',
			)
		);

		$this->assert_error_code( 'temso_publish_missing_header', ( new Temso_Publisher() )->can_publish( $request ) );
	}

	public function test_stale_timestamp_is_rejected(): void {
		$secret = 'shared-secret-value-1234567890';
		$this->stub_secret( $secret );

		$body  = '{"html":"x"}';
		$ts    = (string) ( time() - 1000 );
		$nonce = 'nonce-ddd';
		$request = $this->request(
			$body,
			array(
				'x-temso-signature' => $this->sign( $ts, $nonce, $body, $secret ),
				'x-temso-timestamp' => $ts,
				'x-temso-nonce'     => $nonce,
			)
		);

		$this->assert_error_code( 'temso_publish_stale_signature', ( new Temso_Publisher() )->can_publish( $request ) );
	}

	public function test_replayed_nonce_is_rejected(): void {
		$secret = 'shared-secret-value-1234567890';
		$this->stub_secret( $secret );
		// Transient already present means the nonce has been seen before.
		Functions\when( 'get_transient' )->justReturn( 1 );
		Functions\when( 'set_transient' )->justReturn( true );

		$body  = '{"html":"x"}';
		$ts    = (string) time();
		$nonce = 'nonce-eee';
		$request = $this->request(
			$body,
			array(
				'x-temso-signature' => $this->sign( $ts, $nonce, $body, $secret ),
				'x-temso-timestamp' => $ts,
				'x-temso-nonce'     => $nonce,
			)
		);

		$this->assert_error_code( 'temso_publish_replayed_nonce', ( new Temso_Publisher() )->can_publish( $request ) );
	}

	public function test_not_configured_is_rejected(): void {
		$this->stub_secret( '' );

		$request = $this->request( '{"html":"x"}', array() );

		$this->assert_error_code( 'temso_publish_not_configured', ( new Temso_Publisher() )->can_publish( $request ) );
	}

	/* ----------------------------------------------------------------- *
	 * Payload validation and post creation (via publish)
	 * ----------------------------------------------------------------- */

	public function test_invalid_json_is_rejected(): void {
		$request = $this->request( 'not-json{', array() );

		$this->assert_error_code( 'temso_publish_invalid_json', ( new Temso_Publisher() )->publish( $request ) );
	}

	public function test_create_path_inserts_post_with_expected_fields(): void {
		$captured = null;
		Functions\when( 'wp_insert_post' )->alias(
			static function ( $postarr ) use ( &$captured ) {
				$captured = $postarr;
				return 123;
			}
		);
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/the-slug/' );

		$body = $this->encode(
			array(
				'html'        => '<p>Body</p>',
				'title'       => 'Title',
				'slug'        => 'the-slug',
				'targetState' => 'published',
			)
		);

		$result = ( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		$this->assertSame( 'post', $captured['post_type'] );
		$this->assertSame( 'Title', $captured['post_title'] );
		$this->assertSame( '<p>Body</p>', $captured['post_content'] );
		$this->assertSame( 'the-slug', $captured['post_name'] );
		$this->assertSame( '123', $result['externalId'] );
		$this->assertIsString( $result['externalId'] );
		$this->assertSame( 'https://example.com/the-slug/', $result['externalUrl'] );
	}

	public function test_draft_target_maps_to_draft_status(): void {
		$captured = null;
		Functions\when( 'wp_insert_post' )->alias(
			static function ( $postarr ) use ( &$captured ) {
				$captured = $postarr;
				return 5;
			}
		);
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( '' );

		$body = $this->encode(
			array(
				'html'        => '<p>x</p>',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'draft',
			)
		);

		$result = ( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		$this->assertSame( 'draft', $captured['post_status'] );
		$this->assertSame( 'draft', $result['remoteState'] );
		// get_permalink returned empty, so externalUrl must be omitted.
		$this->assertArrayNotHasKey( 'externalUrl', $result );
	}

	public function test_published_target_maps_to_publish_status(): void {
		$captured = null;
		Functions\when( 'wp_insert_post' )->alias(
			static function ( $postarr ) use ( &$captured ) {
				$captured = $postarr;
				return 6;
			}
		);
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/s/' );

		$body = $this->encode(
			array(
				'html'        => '<p>x</p>',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'published',
			)
		);

		$result = ( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		$this->assertSame( 'publish', $captured['post_status'] );
		$this->assertSame( 'published', $result['remoteState'] );
	}

	public function test_update_path_updates_existing_post(): void {
		$captured = null;
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				return (object) array(
					'ID'        => $id,
					'post_type' => 'post',
				);
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			static function ( $postarr ) use ( &$captured ) {
				$captured = $postarr;
				return 55;
			}
		);
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/?p=55' );

		$body = $this->encode(
			array(
				'html'        => '<p>x</p>',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'published',
				'externalId'  => '55',
			)
		);

		$result = ( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		$this->assertSame( 55, $captured['ID'] );
		$this->assertSame( '55', $result['externalId'] );
	}

	public function test_update_path_rejects_missing_post_without_creating_duplicate(): void {
		$insert_called = false;
		$update_called = false;
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'wp_insert_post' )->alias(
			static function () use ( &$insert_called ) {
				$insert_called = true;
				return 1;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			static function () use ( &$update_called ) {
				$update_called = true;
				return 1;
			}
		);

		$body = $this->encode(
			array(
				'html'        => '<p>x</p>',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'published',
				'externalId'  => '999',
			)
		);

		$result = ( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		$this->assert_error_code( 'temso_publish_missing_post', $result );
		$this->assertFalse( $insert_called, 'A missing update target must never trigger a create.' );
		$this->assertFalse( $update_called );
	}

	public function test_update_path_rejects_non_post_type(): void {
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				return (object) array(
					'ID'        => $id,
					'post_type' => 'page',
				);
			}
		);

		$body = $this->encode(
			array(
				'html'        => '<p>x</p>',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'published',
				'externalId'  => '7',
			)
		);

		$this->assert_error_code( 'temso_publish_wrong_post_type', ( new Temso_Publisher() )->publish( $this->request( $body, array() ) ) );
	}

	public function test_update_path_rejects_malformed_external_id_without_casting(): void {
		$get_post_called = false;
		Functions\when( 'get_post' )->alias(
			static function () use ( &$get_post_called ) {
				$get_post_called = true;
				return null;
			}
		);

		$body = $this->encode(
			array(
				'html'        => '<p>x</p>',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'published',
				'externalId'  => '123abc',
			)
		);

		$this->assert_error_code(
			'temso_publish_invalid_payload',
			( new Temso_Publisher() )->publish( $this->request( $body, array() ) )
		);
		$this->assertFalse(
			$get_post_called,
			'A malformed externalId must not be cast and looked up as a post ID.'
		);
	}

	/* ----------------------------------------------------------------- *
	 * SEO metadata
	 * ----------------------------------------------------------------- */

	public function test_yoast_meta_is_written_when_seo_present(): void {
		$meta = array();
		Functions\when( 'wp_insert_post' )->justReturn( 321 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/x/' );
		Functions\when( 'update_post_meta' )->alias(
			static function ( $post_id, $key, $value ) use ( &$meta ) {
				$meta[ $key ] = $value;
				return true;
			}
		);

		$body = $this->encode(
			array(
				'html'        => '<p>x</p>',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'published',
				'seo'         => array(
					'metaTitle'       => 'SEO Title',
					'metaDescription' => 'SEO Description',
					'canonicalUrl'    => 'https://example.com/canonical',
				),
			)
		);

		( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		$this->assertSame( 'SEO Title', $meta['_yoast_wpseo_title'] );
		$this->assertSame( 'SEO Description', $meta['_yoast_wpseo_metadesc'] );
		$this->assertSame( 'https://example.com/canonical', $meta['_yoast_wpseo_canonical'] );
	}

	public function test_rank_math_meta_is_written_when_seo_present(): void {
		$meta = array();
		Functions\when( 'wp_insert_post' )->justReturn( 654 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/x/' );
		Functions\when( 'update_post_meta' )->alias(
			static function ( $post_id, $key, $value ) use ( &$meta ) {
				$meta[ $key ] = $value;
				return true;
			}
		);

		$body = $this->encode(
			array(
				'html'        => '<p>x</p>',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'published',
				'seo'         => array(
					'metaTitle'       => 'SEO Title',
					'metaDescription' => 'SEO Description',
					'canonicalUrl'    => 'https://example.com/canonical',
				),
			)
		);

		( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		$this->assertSame( 'SEO Title', $meta['rank_math_title'] );
		$this->assertSame( 'SEO Description', $meta['rank_math_description'] );
		$this->assertSame( 'https://example.com/canonical', $meta['rank_math_canonical_url'] );
	}

	public function test_yoast_open_graph_twitter_and_robots_meta_are_written(): void {
		$meta = array();
		Functions\when( 'wp_insert_post' )->justReturn( 401 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/x/' );
		Functions\when( 'update_post_meta' )->alias(
			static function ( $post_id, $key, $value ) use ( &$meta ) {
				$meta[ $key ] = $value;
				return true;
			}
		);

		$body = $this->encode(
			array(
				'html'        => '<p>x</p>',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'published',
				'seo'         => array(
					'robots'    => 'noindex, nofollow',
					'openGraph' => array(
						'title'       => 'OG Title',
						'description' => 'OG Description',
						'imageUrl'    => 'https://example.com/og.png',
					),
					'twitter'   => array(
						'card'        => 'summary_large_image',
						'title'       => 'TW Title',
						'description' => 'TW Description',
						'imageUrl'    => 'https://example.com/tw.png',
					),
				),
			)
		);

		( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		$this->assertSame( 'OG Title', $meta['_yoast_wpseo_opengraph-title'] );
		$this->assertSame( 'OG Description', $meta['_yoast_wpseo_opengraph-description'] );
		$this->assertSame( 'https://example.com/og.png', $meta['_yoast_wpseo_opengraph-image'] );
		$this->assertSame( 'TW Title', $meta['_yoast_wpseo_twitter-title'] );
		$this->assertSame( 'TW Description', $meta['_yoast_wpseo_twitter-description'] );
		$this->assertSame( 'https://example.com/tw.png', $meta['_yoast_wpseo_twitter-image'] );
		$this->assertSame( '1', $meta['_yoast_wpseo_meta-robots-noindex'] );
		$this->assertSame( '1', $meta['_yoast_wpseo_meta-robots-nofollow'] );
	}

	public function test_rank_math_open_graph_twitter_and_robots_meta_are_written(): void {
		$meta = array();
		Functions\when( 'wp_insert_post' )->justReturn( 402 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/x/' );
		Functions\when( 'update_post_meta' )->alias(
			static function ( $post_id, $key, $value ) use ( &$meta ) {
				$meta[ $key ] = $value;
				return true;
			}
		);

		$body = $this->encode(
			array(
				'html'        => '<p>x</p>',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'published',
				'seo'         => array(
					'robots'    => 'noindex, nofollow',
					'openGraph' => array(
						'title'       => 'OG Title',
						'description' => 'OG Description',
						'imageUrl'    => 'https://example.com/og.png',
					),
					'twitter'   => array(
						'card'        => 'summary',
						'title'       => 'TW Title',
						'description' => 'TW Description',
						'imageUrl'    => 'https://example.com/tw.png',
					),
				),
			)
		);

		( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		$this->assertSame( 'OG Title', $meta['rank_math_facebook_title'] );
		$this->assertSame( 'OG Description', $meta['rank_math_facebook_description'] );
		$this->assertSame( 'https://example.com/og.png', $meta['rank_math_facebook_image'] );
		$this->assertSame( 'off', $meta['rank_math_twitter_use_facebook'] );
		$this->assertSame( 'TW Title', $meta['rank_math_twitter_title'] );
		$this->assertSame( 'TW Description', $meta['rank_math_twitter_description'] );
		$this->assertSame( 'https://example.com/tw.png', $meta['rank_math_twitter_image'] );
		// Payload 'summary' maps to Rank Math's 'summary_card' slug.
		$this->assertSame( 'summary_card', $meta['rank_math_twitter_card_type'] );
		$this->assertSame( array( 'noindex', 'nofollow' ), $meta['rank_math_robots'] );
	}

	public function test_seo_index_follow_robots_clears_robots_meta(): void {
		$meta = array();
		Functions\when( 'wp_insert_post' )->justReturn( 403 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/x/' );
		Functions\when( 'update_post_meta' )->alias(
			static function ( $post_id, $key, $value ) use ( &$meta ) {
				$meta[ $key ] = $value;
				return true;
			}
		);

		$body = $this->encode(
			array(
				'html'        => '<p>x</p>',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'published',
				'seo'         => array( 'robots' => 'index, follow' ),
			)
		);

		( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		// 'index, follow' is present and authoritative: it writes a definitive
		// default that clears any prior noindex/nofollow when a post is re-published.
		$this->assertSame( '0', $meta['_yoast_wpseo_meta-robots-noindex'] );
		$this->assertSame( '0', $meta['_yoast_wpseo_meta-robots-nofollow'] );
		$this->assertSame( array( 'index' ), $meta['rank_math_robots'] );
	}

	public function test_seo_without_robots_field_leaves_robots_meta_untouched(): void {
		$meta = array();
		Functions\when( 'wp_insert_post' )->justReturn( 404 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/x/' );
		Functions\when( 'update_post_meta' )->alias(
			static function ( $post_id, $key, $value ) use ( &$meta ) {
				$meta[ $key ] = $value;
				return true;
			}
		);

		$body = $this->encode(
			array(
				'html'        => '<p>x</p>',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'published',
				'seo'         => array( 'metaTitle' => 'Only a title' ),
			)
		);

		( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		// No robots field means no opinion: the site's robots meta is never touched.
		$this->assertArrayNotHasKey( '_yoast_wpseo_meta-robots-noindex', $meta );
		$this->assertArrayNotHasKey( '_yoast_wpseo_meta-robots-nofollow', $meta );
		$this->assertArrayNotHasKey( 'rank_math_robots', $meta );
	}

	/* ----------------------------------------------------------------- *
	 * Media sideloading (via publish)
	 * ----------------------------------------------------------------- */

	/**
	 * Stub the media download/attach path used by Temso_Media::sideload().
	 *
	 * @param int    $id        Attachment ID media_handle_sideload returns.
	 * @param string $local_url Local URL wp_get_attachment_url returns.
	 */
	private function stub_media( int $id, string $local_url ): void {
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'download_url' )->justReturn( '/tmp/temso-dl' );
		Functions\when( 'wp_getimagesize' )->justReturn(
			array(
				0      => 800,
				1      => 600,
				'mime' => 'image/webp',
			)
		);
		Functions\when( 'media_handle_sideload' )->justReturn( $id );
		Functions\when( 'wp_get_attachment_url' )->justReturn( $local_url );
		Functions\when( 'wp_get_attachment_image_srcset' )->justReturn( '' );
		Functions\when( 'wp_read_image_metadata' )->justReturn( array() );
		Functions\when( 'wp_delete_file' )->justReturn( true );
		Functions\when( 'set_post_thumbnail' )->justReturn( true );
	}

	public function test_publish_rewrites_inline_temso_image_to_local_url(): void {
		$content = null;
		Functions\when( 'wp_insert_post' )->justReturn( 200 );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/p/' );
		Functions\when( 'wp_update_post' )->alias(
			static function ( $postarr ) use ( &$content ) {
				$content = $postarr['post_content'];
				return 200;
			}
		);
		$this->stub_media( 88, 'https://example.com/wp-content/uploads/cover.webp' );

		$body = $this->encode(
			array(
				'html'        => '<!-- wp:image --><figure class="wp-block-image"><img src="https://storage.googleapis.com/temso-public-prod/cover.webp" alt="c"/></figure><!-- /wp:image -->',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'published',
			)
		);

		( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		$this->assertNotNull( $content, 'Inline rewrite must persist via wp_update_post.' );
		$this->assertStringContainsString( 'https://example.com/wp-content/uploads/cover.webp', $content );
		$this->assertStringContainsString( 'wp-image-88', $content );
		$this->assertStringNotContainsString( 'storage.googleapis.com', $content );
	}

	public function test_publish_succeeds_even_if_rewritten_content_fails_to_persist(): void {
		// Inline rehosting is best-effort: if persisting the rewritten body fails,
		// the publish must still succeed (the post keeps its hotlinked images)
		// rather than 500 — a post-write failure would orphan/duplicate the post.
		$this->stub_media( 88, 'https://example.com/uploads/cover.webp' );
		Functions\when( 'wp_insert_post' )->justReturn( 202 );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/p/' );
		// Simulate the content update failing.
		Functions\when( 'wp_update_post' )->justReturn( 0 );

		$body = $this->encode(
			array(
				'html'        => '<img src="https://storage.googleapis.com/temso-public-prod/cover.webp" alt="c"/>',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'published',
			)
		);

		$result = ( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		$this->assertSame( '202', $result['externalId'] );
		$this->assertSame( 'published', $result['remoteState'] );
	}

	public function test_publish_update_succeeds_even_if_content_repersist_fails(): void {
		// Same best-effort guarantee on the UPDATE path: the first wp_update_post()
		// (create_or_update_post, with the original body) succeeds; the second one
		// (persisting the rehosted body) fails. The publish must still succeed.
		$this->stub_media( 88, 'https://example.com/uploads/cover.webp' );
		$calls = 0;
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				return (object) array(
					'ID'        => $id,
					'post_type' => 'post',
				);
			}
		);
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/p/' );
		Functions\when( 'wp_update_post' )->alias(
			static function ( $postarr ) use ( &$calls ) {
				++$calls;
				// First call writes the post (success); second persists the
				// rewritten body (simulated failure).
				return 1 === $calls ? 55 : 0;
			}
		);

		$body = $this->encode(
			array(
				'html'        => '<img src="https://storage.googleapis.com/temso-public-prod/cover.webp" alt="c"/>',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'published',
				'externalId'  => '55',
			)
		);

		$result = ( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		$this->assertSame( '55', $result['externalId'] );
		$this->assertSame( 'published', $result['remoteState'] );
		$this->assertSame( 2, $calls, 'Both the initial write and the content repersist are attempted.' );
	}

	public function test_publish_leaves_external_inline_image_hotlinked(): void {
		$update_called = false;
		$downloaded    = false;
		$this->stub_media( 1, 'https://example.com/uploads/x.webp' );
		Functions\when( 'wp_insert_post' )->justReturn( 201 );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/p/' );
		Functions\when( 'wp_update_post' )->alias(
			static function () use ( &$update_called ) {
				$update_called = true;
				return 201;
			}
		);
		// A non-Temso URL must never be fetched.
		Functions\when( 'download_url' )->alias(
			static function () use ( &$downloaded ) {
				$downloaded = true;
				return '/tmp/x';
			}
		);

		$body = $this->encode(
			array(
				'html'        => '<img src="https://cdn.example.com/third-party.jpg" alt="x"/>',
				'title'       => 'T',
				'slug'        => 's',
				'targetState' => 'published',
			)
		);

		$result = ( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		$this->assertSame( '201', $result['externalId'] );
		$this->assertFalse( $downloaded, 'A non-Temso image must never be downloaded.' );
		$this->assertFalse( $update_called, 'A non-Temso image triggers no content rewrite/update.' );
	}

	public function test_publish_sets_featured_image_thumbnail(): void {
		$thumb = null;
		$alt   = null;
		$this->stub_media( 91, 'https://example.com/wp-content/uploads/cover.webp' );
		Functions\when( 'wp_insert_post' )->justReturn( 300 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/p/' );
		Functions\when( 'update_post_meta' )->alias(
			static function ( $post_id, $key, $value ) use ( &$alt ) {
				if ( '_wp_attachment_image_alt' === $key ) {
					$alt = $value;
				}
				return true;
			}
		);
		Functions\when( 'set_post_thumbnail' )->alias(
			static function ( $post_id, $attachment_id ) use ( &$thumb ) {
				$thumb = array( $post_id, $attachment_id );
				return true;
			}
		);

		$body = $this->encode(
			array(
				'html'          => '<p>No inline images.</p>',
				'title'         => 'T',
				'slug'          => 's',
				'targetState'   => 'published',
				'featuredImage' => array(
					'url' => 'https://storage.googleapis.com/temso-public-prod/cover.webp',
					'alt' => 'Cover alt',
				),
			)
		);

		( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		$this->assertSame( array( 300, 91 ), $thumb );
		$this->assertSame( 'Cover alt', $alt );
	}

	public function test_publish_fails_when_featured_image_cannot_be_sideloaded(): void {
		$insert_called = false;
		$this->stub_media( 1, 'https://example.com/uploads/x.webp' );
		Functions\when( 'wp_insert_post' )->alias(
			static function () use ( &$insert_called ) {
				$insert_called = true;
				return 301;
			}
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/p/' );
		// Featured download fails: a transient error the backend should retry.
		Functions\when( 'download_url' )->justReturn( new WP_Error( 'http_request_failed', 'boom' ) );

		$body = $this->encode(
			array(
				'html'          => '<p>x</p>',
				'title'         => 'T',
				'slug'          => 's',
				'targetState'   => 'published',
				'featuredImage' => array(
					'url' => 'https://storage.googleapis.com/temso-public-prod/cover.webp',
				),
			)
		);

		$result = ( new Temso_Publisher() )->publish( $this->request( $body, array() ) );

		$this->assert_error_code( 'temso_publish_featured_failed', $result );
		$this->assertSame( 502, $result->get_error_data()['status'] );
		// The strict featured failure must abort BEFORE any post is written, so a
		// backend retry cannot create a duplicate or leave an orphaned draft.
		$this->assertFalse( $insert_called, 'No post may be created when the featured image fails.' );
	}

	public function test_publish_rejects_non_object_featured_image(): void {
		$body = $this->encode(
			array(
				'html'          => '<p>x</p>',
				'title'         => 'T',
				'slug'          => 's',
				'targetState'   => 'published',
				'featuredImage' => 'not-an-object',
			)
		);

		$this->assert_error_code(
			'temso_publish_invalid_payload',
			( new Temso_Publisher() )->publish( $this->request( $body, array() ) )
		);
	}

	public function test_publish_rejects_featured_image_without_url(): void {
		$body = $this->encode(
			array(
				'html'          => '<p>x</p>',
				'title'         => 'T',
				'slug'          => 's',
				'targetState'   => 'published',
				'featuredImage' => array( 'alt' => 'no url here' ),
			)
		);

		$this->assert_error_code(
			'temso_publish_invalid_payload',
			( new Temso_Publisher() )->publish( $this->request( $body, array() ) )
		);
	}

	public function test_publish_rejects_non_https_featured_image_url(): void {
		// Mimic esc_url_raw's protocol allowlist: http is stripped to ''.
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url, $protocols = null ) {
				if ( is_array( $protocols ) && ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), $protocols, true ) ) {
					return '';
				}
				return $url;
			}
		);

		$body = $this->encode(
			array(
				'html'          => '<p>x</p>',
				'title'         => 'T',
				'slug'          => 's',
				'targetState'   => 'published',
				'featuredImage' => array( 'url' => 'http://storage.googleapis.com/temso-public-prod/cover.webp' ),
			)
		);

		$this->assert_error_code(
			'temso_publish_invalid_payload',
			( new Temso_Publisher() )->publish( $this->request( $body, array() ) )
		);
	}

	/* ----------------------------------------------------------------- *
	 * Settings sanitizer
	 * ----------------------------------------------------------------- */

	public function test_settings_sanitizer_preserves_publish_secret(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$out = ( new Temso_Settings() )->sanitize(
			array(
				'publish_secret' => '  ABCdef0123456789ABCdef01  ',
				'ingest_url'     => '',
				'api_key'        => '',
			)
		);

		// Surrounding whitespace trimmed, but case and content kept verbatim.
		$this->assertSame( 'ABCdef0123456789ABCdef01', $out['publish_secret'] );
	}

	public function test_settings_sanitizer_keeps_saved_secret_on_empty_submit(): void {
		// The secret field is never pre-filled, so an empty submit must not wipe
		// the secret the setup-token flow generated.
		Functions\when( 'get_option' )->justReturn(
			array( 'publish_secret' => 'kept-secret-value-123456' )
		);

		$out = ( new Temso_Settings() )->sanitize(
			array(
				'publish_secret' => '',
				'ingest_url'     => '',
				'api_key'        => '',
			)
		);

		$this->assertSame( 'kept-secret-value-123456', $out['publish_secret'] );
	}

	public function test_settings_sanitizer_carries_forward_connection_status(): void {
		// Connection status is written by the AJAX claim, not this form, so a
		// normal save must preserve it rather than drop it.
		Functions\when( 'get_option' )->justReturn(
			array(
				'publishing_connected_at'  => 1718000000,
				'publishing_site_url'      => 'https://example.com',
				'publishing_rest_base_url' => 'https://example.com/wp-json/temso/v1',
			)
		);

		$out = ( new Temso_Settings() )->sanitize(
			array(
				'ingest_url' => '',
				'api_key'    => '',
			)
		);

		$this->assertSame( 1718000000, $out['publishing_connected_at'] );
		$this->assertSame( 'https://example.com', $out['publishing_site_url'] );
		$this->assertSame( 'https://example.com/wp-json/temso/v1', $out['publishing_rest_base_url'] );
	}

	public function test_settings_sanitizer_clears_connection_status_when_secret_changes(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'publish_secret'           => 'old-secret-value-123456',
				'publishing_connected_at'  => 1718000000,
				'publishing_site_url'      => 'https://example.com',
				'publishing_rest_base_url' => 'https://example.com/wp-json/temso/v1',
			)
		);

		$out = ( new Temso_Settings() )->sanitize(
			array(
				'publish_secret' => 'new-secret-value-123456',
				'ingest_url'     => '',
				'api_key'        => '',
			)
		);

		$this->assertSame( 'new-secret-value-123456', $out['publish_secret'] );
		$this->assertArrayNotHasKey( 'publishing_connected_at', $out );
		$this->assertArrayNotHasKey( 'publishing_site_url', $out );
		$this->assertArrayNotHasKey( 'publishing_rest_base_url', $out );
	}

	public function test_settings_sanitizer_persists_connection_status_from_input(): void {
		// register_setting() runs sanitize() on every update_option() for this
		// option, including the setup-token claim's own save_option_fields()
		// write. That write carries the connection fields in $input while the
		// stored option has none yet, so the sanitizer must keep them — otherwise
		// a successful claim never records its connected status. (Regression.)
		Functions\when( 'get_option' )->justReturn(
			array( 'publish_secret' => 'kept-secret-value-123456' )
		);

		$out = ( new Temso_Settings() )->sanitize(
			array(
				'publish_secret'           => 'kept-secret-value-123456',
				'ingest_url'               => '',
				'api_key'                  => '',
				'publishing_connected_at'  => 1718000123,
				'publishing_site_url'      => 'https://example.com',
				'publishing_rest_base_url' => 'https://example.com/wp-json/temso/v1',
			)
		);

		$this->assertSame( 1718000123, $out['publishing_connected_at'] );
		$this->assertSame( 'https://example.com', $out['publishing_site_url'] );
		$this->assertSame( 'https://example.com/wp-json/temso/v1', $out['publishing_rest_base_url'] );
	}
}
