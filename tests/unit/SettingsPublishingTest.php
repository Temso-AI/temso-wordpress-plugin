<?php
/**
 * Unit tests for the publishing setup-token flow in Temso_Settings.
 *
 * Pure unit tests: WordPress is never loaded. Brain Monkey stubs the WP
 * functions the claim path calls, and wp_parse_url/wp_parse_str are provided as
 * thin wrappers over their native counterparts so the token parser can run.
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

		public function __construct( $code = '', $message = '', $data = '' ) {}
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Test wrapper: defer to the native parser.
	 *
	 * @param string $url       URL to parse.
	 * @param int    $component Component constant or -1 for all.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
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

final class SettingsPublishingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);
		// claim_url() runs its default URL through this filter.
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/* ----------------------------------------------------------------- *
	 * Setup-token parsing
	 * ----------------------------------------------------------------- */

	public function test_parse_setup_token_accepts_raw_token(): void {
		$this->assertSame(
			'wpsetup_abc123',
			Temso_Settings::parse_setup_token( 'wpsetup_abc123' )
		);
	}

	public function test_parse_setup_token_trims_whitespace(): void {
		$this->assertSame(
			'wpsetup_abc123',
			Temso_Settings::parse_setup_token( "  wpsetup_abc123\n" )
		);
	}

	public function test_parse_setup_token_reads_wordpress_setup_param_from_url(): void {
		$url = 'https://app.temso.ai/t/x/p/y/app/settings/integrations?wordpress_setup=wpsetup_abc123';
		$this->assertSame( 'wpsetup_abc123', Temso_Settings::parse_setup_token( $url ) );
	}

	public function test_parse_setup_reads_trusted_development_claim_url(): void {
		$url = 'https://development.temso.ai/t/x/p/y/app/settings/integrations'
			. '?wordpress_setup=wpsetup_abc123'
			. '&claim_url=' . rawurlencode( Temso_Settings::DEVELOPMENT_CLAIM_URL );
		$this->assertSame(
			array(
				'token'     => 'wpsetup_abc123',
				'claim_url' => Temso_Settings::DEVELOPMENT_CLAIM_URL,
			),
			Temso_Settings::parse_setup( $url )
		);
	}

	public function test_parse_setup_ignores_untrusted_claim_url(): void {
		$evil = 'https://evil.example/v1/integrations/wordpress/setup-claim';
		$url = 'https://app.temso.ai/t/x/p/y/app/settings/integrations'
			. '?wordpress_setup=wpsetup_abc123'
			. '&claim_url=' . rawurlencode( $evil );
		$this->assertSame(
			array(
				'token'     => 'wpsetup_abc123',
				'claim_url' => '',
			),
			Temso_Settings::parse_setup( $url )
		);
	}

	public function test_parse_setup_token_reads_generic_token_param_from_url(): void {
		$url = 'https://app.temso.ai/setup?token=tok-999';
		$this->assertSame( 'tok-999', Temso_Settings::parse_setup_token( $url ) );
	}

	public function test_parse_setup_token_returns_empty_for_url_without_token(): void {
		$url = 'https://app.temso.ai/setup?other=1';
		$this->assertSame( '', Temso_Settings::parse_setup_token( $url ) );
	}

	public function test_parse_setup_token_returns_empty_for_blank(): void {
		$this->assertSame( '', Temso_Settings::parse_setup_token( '   ' ) );
	}

	/* ----------------------------------------------------------------- *
	 * Claim endpoint resolution
	 * ----------------------------------------------------------------- */

	public function test_claim_url_defaults_to_production_endpoint(): void {
		$this->assertSame( Temso_Settings::DEFAULT_CLAIM_URL, Temso_Settings::claim_url() );
	}

	public function test_claim_url_accepts_trusted_development_endpoint(): void {
		$this->assertSame(
			Temso_Settings::DEVELOPMENT_CLAIM_URL,
			Temso_Settings::claim_url( Temso_Settings::DEVELOPMENT_CLAIM_URL )
		);
	}

	public function test_claim_url_rejects_untrusted_endpoint(): void {
		$this->assertSame(
			Temso_Settings::DEFAULT_CLAIM_URL,
			Temso_Settings::claim_url( 'https://evil.example/v1/integrations/wordpress/setup-claim' )
		);
	}

	/* ----------------------------------------------------------------- *
	 * Claim flow
	 * ----------------------------------------------------------------- */

	/**
	 * Wire up a mutable option store and the WP helpers connect_publishing()
	 * touches. Returns a reference array exposing the saved option and the
	 * captured wp_remote_post() arguments.
	 *
	 * @param array $initial   Initial saved option contents.
	 * @param int   $http_code Status code wp_remote_post should report, or 0 for a WP_Error.
	 * @return object Holds ->store (the saved option) and ->captured (wp_remote_post args).
	 */
	private function wire( array $initial, int $http_code ) {
		// An object (passed by handle) so the closures and the test observe the
		// same mutating state — a returned array would be copied by value.
		$state           = new \stdClass();
		$state->store    = $initial;
		$state->captured = array();

		Functions\when( 'get_option' )->alias(
			static function () use ( $state ) {
				return $state->store;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( $state ) {
				$state->store = $value;
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'rest_url' )->alias(
			static function ( $path ) {
				return 'https://example.com/wp-json/' . $path . '/';
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( $state, $http_code ) {
				$state->captured = array(
					'url'  => $url,
					'args' => $args,
				);
				return 0 === $http_code ? new WP_Error( 'http_request_failed', 'fail' ) : array();
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $http_code );

		return $state;
	}

	public function test_claim_generates_secret_when_missing_and_sends_site_and_rest_urls(): void {
		$state  = $this->wire( array(), 200 );
		$result = ( new Temso_Settings() )->connect_publishing( 'wpsetup_token123' );

		$this->assertTrue( $result['ok'] );

		// A secret was generated and persisted.
		$secret = $state->store['publish_secret'];
		$this->assertNotEmpty( $secret );

		// The claim hit the production endpoint with the expected payload.
		$this->assertSame( Temso_Settings::DEFAULT_CLAIM_URL, $state->captured['url'] );
		$body = json_decode( $state->captured['args']['body'], true );
		$this->assertSame( 'wpsetup_token123', $body['setupToken'] );
		$this->assertSame( 'https://example.com', $body['siteUrl'] );
		$this->assertSame( 'https://example.com/wp-json/temso/v1', $body['restBaseUrl'] );
		$this->assertSame( $secret, $body['sharedSecret'] );
		$this->assertSame( TEMSO_VERSION, $body['pluginVersion'] );
		$this->assertTrue( $body['features']['publish'] );

		// Connection status was recorded.
		$this->assertArrayHasKey( 'publishing_connected_at', $state->store );
		$this->assertSame( 'https://example.com', $state->store['publishing_site_url'] );
		$this->assertSame( 'https://example.com/wp-json/temso/v1', $state->store['publishing_rest_base_url'] );
	}

	public function test_claim_uses_trusted_setup_claim_url(): void {
		$state  = $this->wire( array(), 200 );
		$result = ( new Temso_Settings() )->connect_publishing(
			'wpsetup_token123',
			Temso_Settings::DEVELOPMENT_CLAIM_URL
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( Temso_Settings::DEVELOPMENT_CLAIM_URL, $state->captured['url'] );
	}

	public function test_claim_reuses_existing_secret(): void {
		$state  = $this->wire(
			array(
				'publish_secret' => 'existing-secret-abcdef123456',
				'ingest_url'     => 'https://ingest.example/',
			),
			200
		);
		$result = ( new Temso_Settings() )->connect_publishing( 'tok' );

		$this->assertTrue( $result['ok'] );

		$body = json_decode( $state->captured['args']['body'], true );
		$this->assertSame( 'existing-secret-abcdef123456', $body['sharedSecret'] );

		// The existing secret is untouched and unrelated settings survive.
		$this->assertSame( 'existing-secret-abcdef123456', $state->store['publish_secret'] );
		$this->assertSame( 'https://ingest.example/', $state->store['ingest_url'] );
	}

	public function test_successful_claim_does_not_expose_secret_in_result(): void {
		$this->wire( array( 'publish_secret' => 'super-secret-value-987654' ), 200 );
		$result = ( new Temso_Settings() )->connect_publishing( 'tok' );

		$this->assertTrue( $result['ok'] );
		$this->assertStringNotContainsString( 'super-secret-value-987654', json_encode( $result ) );
	}

	public function test_failed_claim_reports_invalid_token_and_hides_secret(): void {
		$state  = $this->wire( array( 'publish_secret' => 'super-secret-value-987654' ), 400 );
		$result = ( new Temso_Settings() )->connect_publishing( 'bad-token' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_token', $result['code'] );
		// A failed claim records no connection.
		$this->assertArrayNotHasKey( 'publishing_connected_at', $state->store );
		// The secret never appears in the browser-bound response.
		$this->assertStringNotContainsString( 'super-secret-value-987654', json_encode( $result ) );
	}

	public function test_server_error_claim_reports_server_error(): void {
		$this->wire( array( 'publish_secret' => 'a-secret-value-1234567890' ), 503 );
		$result = ( new Temso_Settings() )->connect_publishing( 'tok' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'server_error', $result['code'] );
	}

	public function test_network_failure_claim_reports_network(): void {
		$this->wire( array( 'publish_secret' => 'a-secret-value-1234567890' ), 0 );
		$result = ( new Temso_Settings() )->connect_publishing( 'tok' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'network', $result['code'] );
	}

	/* ----------------------------------------------------------------- *
	 * Capabilities reflect the claimed secret
	 * ----------------------------------------------------------------- */

	public function test_capabilities_publish_flips_false_to_true_across_claim(): void {
		$state = $this->wire( array(), 200 );

		// Before any secret: capabilities reports publish disabled.
		$this->assertFalse( ( new Temso_Publisher() )->capabilities()['features']['publish'] );

		( new Temso_Settings() )->connect_publishing( 'wpsetup_token123' );

		// After the claim persists a secret: publish is enabled.
		$this->assertNotEmpty( $state->store['publish_secret'] );
		$this->assertTrue( ( new Temso_Publisher() )->capabilities()['features']['publish'] );
	}
}
