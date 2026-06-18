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
		Functions\when( 'sanitize_key' )->returnArg();
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
		$this->assertArrayHasKey( 'yoastMeta', $caps['features'] );
		$this->assertArrayHasKey( 'rankMathMeta', $caps['features'] );
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
