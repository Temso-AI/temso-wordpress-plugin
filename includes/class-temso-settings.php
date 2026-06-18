<?php
/**
 * Settings → Temso AI admin page.
 *
 * @package Temso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the Settings → Temso AI page.
 */
class Temso_Settings {

	const OPTION                         = 'temso_settings';
	const GROUP                          = 'temso_settings_group';
	const AJAX_VERIFY_ACTION             = 'temso_verify';
	const AJAX_CONNECT_PUBLISHING_ACTION = 'temso_connect_publishing';
	const SETTINGS_PAGE_HOOK             = 'settings_page_temso-ai';

	/**
	 * Production Temso endpoint that claims a publishing setup token.
	 *
	 * Overridable with the TEMSO_PUBLISHING_CLAIM_URL constant or the
	 * `temso_publishing_claim_url` filter for staging or self-hosted Temso —
	 * see claim_url(). The shared secret is POSTed here, so the default is a
	 * fixed Temso-owned host unless a pasted setup link carries another
	 * hard-allowlisted Temso endpoint.
	 */
	const DEFAULT_CLAIM_URL     = 'https://api.temso.ai/v1/integrations/wordpress/setup-claim';
	const DEVELOPMENT_CLAIM_URL = 'https://api.development.temso.ai/v1/integrations/wordpress/setup-claim';

	/**
	 * Current settings, with defaults applied.
	 *
	 * @return array{enabled:bool,ingest_url:string,api_key:string,publish_secret:string,publishing_connected_at:int,publishing_site_url:string,publishing_rest_base_url:string}
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array(
			'enabled'                  => ! empty( $saved['enabled'] ),
			'ingest_url'               => isset( $saved['ingest_url'] ) ? (string) $saved['ingest_url'] : '',
			'api_key'                  => isset( $saved['api_key'] ) ? (string) $saved['api_key'] : '',
			'publish_secret'           => isset( $saved['publish_secret'] ) ? (string) $saved['publish_secret'] : '',
			'publishing_connected_at'  => isset( $saved['publishing_connected_at'] ) ? (int) $saved['publishing_connected_at'] : 0,
			'publishing_site_url'      => isset( $saved['publishing_site_url'] ) ? (string) $saved['publishing_site_url'] : '',
			'publishing_rest_base_url' => isset( $saved['publishing_rest_base_url'] ) ? (string) $saved['publishing_rest_base_url'] : '',
		);
	}

	/**
	 * Register the admin menu, settings, and plugin-list link.
	 */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_VERIFY_ACTION, array( $this, 'ajax_verify' ) );
		add_action(
			'wp_ajax_' . self::AJAX_CONNECT_PUBLISHING_ACTION,
			array( $this, 'ajax_connect_publishing' )
		);
		add_filter(
			'plugin_action_links_' . plugin_basename( TEMSO_FILE ),
			array( $this, 'action_links' )
		);
	}

	/**
	 * Enqueue the verify-button script — only on the Temso settings page.
	 *
	 * @param string $hook Admin page hook suffix passed by WordPress.
	 */
	public function enqueue_assets( $hook ) {
		if ( self::SETTINGS_PAGE_HOOK !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'temso-verify',
			plugins_url( 'assets/js/verify.js', TEMSO_FILE ),
			array(),
			TEMSO_VERSION,
			true
		);
		wp_localize_script(
			'temso-verify',
			'temsoVerify',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_VERIFY_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_VERIFY_ACTION ),
				'i18n'    => array(
					'testing'      => __( 'Testing…', 'temso-ai' ),
					'success'      => __( 'Connected. Credentials verified.', 'temso-ai' ),
					'missing'      => __( 'Enter both an Ingest URL and an API key first.', 'temso-ai' ),
					'unauthorized' => __( 'API key not recognized.', 'temso-ai' ),
					'forbidden'    => __( 'API key lacks the required scope.', 'temso-ai' ),
					'revoked'      => __( 'Source revoked — generate a new one in Temso.', 'temso-ai' ),
					'not_found'    => __( 'The key is valid but does not own that source.', 'temso-ai' ),
					'network'      => __( 'Could not reach Temso. Check the URL and your server connectivity.', 'temso-ai' ),
					'unknown'      => __( 'Unexpected error. Check the URL and key.', 'temso-ai' ),
				),
			)
		);

		wp_enqueue_script(
			'temso-publishing-setup',
			plugins_url( 'assets/js/publishing-setup.js', TEMSO_FILE ),
			array(),
			TEMSO_VERSION,
			true
		);
		wp_localize_script(
			'temso-publishing-setup',
			'temsoPublishing',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_CONNECT_PUBLISHING_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_CONNECT_PUBLISHING_ACTION ),
				'i18n'    => array(
					'connecting' => __( 'Connecting…', 'temso-ai' ),
					'success'    => __( 'Publishing connected.', 'temso-ai' ),
					'missing'    => __( 'Paste the setup link or code from Temso first.', 'temso-ai' ),
					'invalid'    => __( 'Setup link expired or invalid. Generate a new one in Temso.', 'temso-ai' ),
					'forbidden'  => __( 'You do not have permission to connect publishing.', 'temso-ai' ),
					'server'     => __( 'Temso had a problem completing setup. Try again.', 'temso-ai' ),
					'network'    => __( 'Could not reach Temso. Try again.', 'temso-ai' ),
					'unknown'    => __( 'Unexpected error. Try generating a new setup link in Temso.', 'temso-ai' ),
				),
			)
		);
	}

	/**
	 * AJAX handler for the "Test connection" button.
	 *
	 * Accepts the URL/key from the form (not from saved settings) so the user
	 * can verify a freshly pasted pair before saving. Returns a structured
	 * `code` the JS maps to a translated message.
	 */
	public function ajax_verify() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'FORBIDDEN' ), 403 );
		}
		check_ajax_referer( self::AJAX_VERIFY_ACTION, 'nonce' );

		$url = isset( $_POST['ingest_url'] )
			? esc_url_raw( wp_unslash( $_POST['ingest_url'] ), array( 'https' ) )
			: '';
		$key = isset( $_POST['api_key'] )
			? sanitize_key( wp_unslash( $_POST['api_key'] ) )
			: '';

		if ( '' === $url || '' === $key ) {
			wp_send_json_error( array( 'code' => 'missing' ) );
		}

		$result = self::verify_credentials( $url, $key );
		if ( $result['ok'] ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	/**
	 * Call the Temso `/verify` endpoint and return a structured result.
	 *
	 * Pure helper — no WP options or hooks touched, so it can also be reused
	 * later (e.g. to surface a failure notice from the dispatcher path).
	 *
	 * @param string $ingest_url Configured ingest URL.
	 * @param string $api_key    Configured API key.
	 * @return array{ok:bool,code?:string,status?:int}
	 */
	public static function verify_credentials( $ingest_url, $api_key ) {
		$verify_url = rtrim( $ingest_url, '/' ) . '/verify';

		$response = wp_remote_post(
			$verify_url,
			array(
				'timeout'   => 10,
				'sslverify' => true,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'   => false,
				'code' => 'network',
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status ) {
			return array(
				'ok'     => true,
				'status' => 200,
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = is_array( $body ) && isset( $body['code'] ) ? (string) $body['code'] : 'unknown';

		return array(
			'ok'     => false,
			'status' => $status,
			'code'   => $code,
		);
	}

	/**
	 * AJAX handler for the "Connect publishing" button.
	 *
	 * Takes the one-time setup link/code the admin pasted from Temso, claims it
	 * against Temso, and on success stores the publish shared secret locally.
	 * The response is intentionally free of the secret — only a status code the
	 * JS maps to a translated message is returned.
	 */
	public function ajax_connect_publishing() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::AJAX_CONNECT_PUBLISHING_ACTION, 'nonce' );

		$raw = isset( $_POST['setup'] )
			? sanitize_text_field( wp_unslash( $_POST['setup'] ) )
			: '';

		$setup = self::parse_setup( $raw );
		$token = $setup['token'];
		if ( '' === $token ) {
			wp_send_json_error( array( 'code' => 'missing_token' ) );
		}

		$result = $this->connect_publishing( $token, $setup['claim_url'] );
		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success(
				array(
					'code'        => 'connected',
					'connectedAt' => isset( $result['connected_at'] ) ? (int) $result['connected_at'] : 0,
				)
			);
		}

		wp_send_json_error( array( 'code' => isset( $result['code'] ) ? $result['code'] : 'unknown' ) );
	}

	/**
	 * Extract the setup token from a pasted link or raw code.
	 *
	 * Temso shows either a bare token or a full setup URL carrying the token in
	 * a query parameter; both are accepted. Pure string handling — no options or
	 * network — so it is unit-testable in isolation.
	 *
	 * @param string $raw The value pasted into the setup field.
	 * @return string The token, or '' when none could be extracted.
	 */
	public static function parse_setup_token( $raw ) {
		$setup = self::parse_setup( $raw );
		return $setup['token'];
	}

	/**
	 * Extract the setup token and trusted claim endpoint from a pasted setup link.
	 *
	 * @param string $raw The value pasted into the setup field.
	 * @return array{token:string,claim_url:string}
	 */
	public static function parse_setup( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return array(
				'token'     => '',
				'claim_url' => '',
			);
		}

		// Not a URL — treat the whole paste as the token verbatim.
		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			return array(
				'token'     => $raw,
				'claim_url' => '',
			);
		}

		$query = wp_parse_url( $raw, PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return array(
				'token'     => '',
				'claim_url' => '',
			);
		}

		$params = array();
		wp_parse_str( $query, $params );
		$token = '';
		foreach ( array( 'wordpress_setup', 'setup_token', 'setupToken', 'token' ) as $key ) {
			if ( ! empty( $params[ $key ] ) && is_string( $params[ $key ] ) ) {
				$token = trim( $params[ $key ] );
				break;
			}
		}

		$claim_url = '';
		if ( ! empty( $params['claim_url'] ) && is_string( $params['claim_url'] ) ) {
			$claim_url = self::trusted_claim_url( $params['claim_url'] );
		}

		return array(
			'token'     => $token,
			'claim_url' => $claim_url,
		);
	}

	/**
	 * Accept only Temso-owned claim endpoints from pasted setup links.
	 *
	 * The setup link is pasted input, and the plugin sends the generated publish
	 * secret to the claim URL. Keep this allowlist narrow so a hostile link cannot
	 * redirect that secret to an arbitrary host.
	 *
	 * @param string $url Candidate claim URL from the setup link.
	 * @return string Normalized trusted claim URL, or '' when untrusted.
	 */
	private static function trusted_claim_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$path   = wp_parse_url( $url, PHP_URL_PATH );
		if ( 'https' !== $scheme || ! is_string( $host ) || ! is_string( $path ) ) {
			return '';
		}

		if ( '/v1/integrations/wordpress/setup-claim' !== rtrim( $path, '/' ) ) {
			return '';
		}

		if ( 'api.temso.ai' === $host ) {
			return self::DEFAULT_CLAIM_URL;
		}
		if ( 'api.development.temso.ai' === $host ) {
			return self::DEVELOPMENT_CLAIM_URL;
		}

		return '';
	}

	/**
	 * Resolve the Temso publishing claim endpoint.
	 *
	 * Defaults to the production endpoint. A setup link may carry a known Temso
	 * development endpoint; anything else must be configured explicitly with the
	 * TEMSO_PUBLISHING_CLAIM_URL constant or the `temso_publishing_claim_url`
	 * filter. The plugin POSTs the freshly generated shared secret to this URL,
	 * so pasted-link endpoints are hard-allowlisted before use.
	 *
	 * @param string $setup_claim_url Trusted setup-link claim URL, when present.
	 * @return string
	 */
	public static function claim_url( $setup_claim_url = '' ) {
		$url = self::trusted_claim_url( $setup_claim_url );
		if ( '' === $url ) {
			$url = self::DEFAULT_CLAIM_URL;
		}
		if ( defined( 'TEMSO_PUBLISHING_CLAIM_URL' ) && is_string( TEMSO_PUBLISHING_CLAIM_URL ) && '' !== TEMSO_PUBLISHING_CLAIM_URL ) {
			$url = TEMSO_PUBLISHING_CLAIM_URL;
		}

		/**
		 * Filter the Temso publishing claim endpoint URL.
		 *
		 * @param string $url The claim endpoint the setup token is POSTed to.
		 */
		return (string) apply_filters( 'temso_publishing_claim_url', $url );
	}

	/**
	 * Claim a publishing setup token against Temso.
	 *
	 * Generates a publish shared secret when the site has none, then POSTs the
	 * claim payload (token, site URL, REST base URL, version, capabilities, and
	 * the secret) to Temso. The secret is persisted *before* the claim because
	 * Temso verifies this site's capabilities endpoint during the claim, and
	 * that only reports `publish: true` once a secret is saved. An existing
	 * secret is reused so manual setups are not rotated out from under Temso.
	 *
	 * The returned array never contains the secret, so callers can forward it to
	 * the browser safely.
	 *
	 * @param string $token     Setup token extracted from the pasted link/code.
	 * @param string $claim_url Trusted claim endpoint from the pasted setup link, when present.
	 * @return array{ok:bool,code:string,status?:int,connected_at?:int}
	 */
	public function connect_publishing( $token, $claim_url = '' ) {
		$settings = self::get();
		$secret   = isset( $settings['publish_secret'] ) ? (string) $settings['publish_secret'] : '';

		if ( '' === $secret ) {
			$secret = self::generate_publish_secret();
			$this->save_option_fields( array( 'publish_secret' => $secret ) );
		}

		$site_url      = home_url();
		$rest_base_url = untrailingslashit( rest_url( 'temso/v1' ) );

		$payload = array(
			'setupToken'    => $token,
			'siteUrl'       => $site_url,
			'restBaseUrl'   => $rest_base_url,
			'sharedSecret'  => $secret,
			'pluginVersion' => TEMSO_VERSION,
			'features'      => Temso_Publisher::capability_features( $secret ),
		);

		$response = wp_remote_post(
			self::claim_url( $claim_url ),
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'   => false,
				'code' => 'network',
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status < 300 ) {
			$connected_at = time();
			$this->save_option_fields(
				array(
					'publishing_connected_at'  => $connected_at,
					'publishing_site_url'      => $site_url,
					'publishing_rest_base_url' => $rest_base_url,
				)
			);

			return array(
				'ok'           => true,
				'code'         => 'connected',
				'connected_at' => $connected_at,
			);
		}

		if ( $status >= 500 ) {
			return array(
				'ok'     => false,
				'code'   => 'server_error',
				'status' => $status,
			);
		}

		// 4xx — invalid, expired, or already-claimed token. Temso returns a
		// generic error here on purpose, so a single clear message is surfaced.
		return array(
			'ok'     => false,
			'code'   => 'invalid_token',
			'status' => $status,
		);
	}

	/**
	 * Generate a high-entropy, case-preserving publish shared secret.
	 *
	 * Base64url over 32 random bytes: URL-safe, no padding, and never run
	 * through sanitize_key() (which would lowercase and corrupt it).
	 *
	 * @return string
	 */
	private static function generate_publish_secret() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding random bytes into a URL-safe secret, not obfuscating code.
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Merge a set of fields into the saved settings option.
	 *
	 * Reads the current option fresh and writes only the given keys, so the
	 * setup-token AJAX path never clobbers ingest settings or each other.
	 *
	 * @param array $changes Key/value pairs to persist.
	 */
	private function save_option_fields( array $changes ) {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		update_option( self::OPTION, array_merge( $saved, $changes ) );
	}

	/**
	 * Prepend a Settings link to the plugin's row actions.
	 *
	 * @param array $links Existing plugin row action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=temso-ai' ) ),
			esc_html__( 'Settings', 'temso-ai' )
		);
		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the options page under Settings.
	 */
	public function add_page() {
		add_options_page(
			__( 'Temso AI', 'temso-ai' ),
			__( 'Temso AI', 'temso-ai' ),
			'manage_options',
			'temso-ai',
			array( $this, 'render' )
		);
	}

	/**
	 * Register the settings group and its sanitizer.
	 */
	public function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);
	}

	/**
	 * Sanitize the submitted settings.
	 *
	 * @param mixed $input Raw posted value.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();

		$existing = get_option( self::OPTION, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		// https only — the API key travels in the Authorization header, so a
		// plain-http endpoint would leak it in cleartext.
		$url = isset( $input['ingest_url'] ) ? esc_url_raw( trim( (string) $input['ingest_url'] ), array( 'https' ) ) : '';

		// The publish shared secret is high-entropy and compared byte-for-byte
		// in the HMAC check, so it must survive verbatim. sanitize_key() would
		// lowercase it and sanitize_text_field() would strip `%xx` octets —
		// either could silently corrupt a base64url secret — so only stray
		// surrounding whitespace from a paste is trimmed. The field is never
		// rendered with its saved value (printing a live credential into the
		// page source would leak it), so an empty submit means "keep the saved
		// secret" rather than "clear it" — only a freshly typed value replaces
		// it. This also preserves a secret the setup-token flow generated.
		$existing_secret        = isset( $existing['publish_secret'] ) ? (string) $existing['publish_secret'] : '';
		$submitted_secret       = isset( $input['publish_secret'] ) ? trim( (string) $input['publish_secret'] ) : '';
		$publish_secret         = '' !== $submitted_secret
			? $submitted_secret
			: $existing_secret;
		$publish_secret_changed = '' !== $submitted_secret
			&& ! hash_equals( $existing_secret, $submitted_secret );

		// Temso keys are `tms_<lowercase-hex>`; sanitize_key() keeps exactly
		// that character set, so it preserves any valid key while stripping
		// stray whitespace, quotes, or control bytes that would corrupt the
		// Authorization header. sanitize_text_field() must not be used here:
		// it collapses whitespace and strips `%xx` octets, both of which can
		// silently mangle a secret.
		$sanitized = array(
			'enabled'        => ! empty( $input['enabled'] ),
			'ingest_url'     => $url,
			'api_key'        => isset( $input['api_key'] ) ? sanitize_key( $input['api_key'] ) : '',
			'publish_secret' => $publish_secret,
		);

		// Connection status fields. register_setting() runs this sanitizer on
		// EVERY update_option() for this option — including the setup-token AJAX
		// claim's own save_option_fields() write — so they must be sourced from
		// the incoming value, not only from $existing, or the claim's freshly
		// recorded status would be stripped right back out before it ever hits
		// the database. A normal settings-form save carries no connection fields,
		// so it falls back to whatever the last claim recorded. Either way the
		// status is dropped when the admin manually replaces the secret, because
		// Temso then still holds the old secret and the connection is stale.
		if ( ! $publish_secret_changed ) {
			$connection_fields = array(
				'publishing_connected_at'  => 'absint',
				'publishing_site_url'      => 'esc_url_raw',
				'publishing_rest_base_url' => 'esc_url_raw',
			);
			foreach ( $connection_fields as $key => $sanitizer ) {
				if ( isset( $input[ $key ] ) ) {
					$sanitized[ $key ] = call_user_func( $sanitizer, $input[ $key ] );
				} elseif ( isset( $existing[ $key ] ) ) {
					$sanitized[ $key ] = $existing[ $key ];
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Render the settings page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings  = self::get();
		$last_sent = (int) get_option( Temso_Dispatcher::LAST_SENT_OPTION, 0 );
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:10px;">
				<?php if ( file_exists( TEMSO_PATH . 'assets/logo.svg' ) ) : ?>
					<img src="<?php echo esc_url( plugins_url( 'assets/logo.svg', TEMSO_FILE ) ); ?>"
						alt="<?php esc_attr_e( 'Temso AI', 'temso-ai' ); ?>" height="28" width="28" />
				<?php endif; ?>
				<?php esc_html_e( 'Temso AI', 'temso-ai' ); ?>
			</h1>
			<p>
				<?php esc_html_e( 'Paste the Ingest URL and API key from your Temso project (Crawlers → Add source → WordPress).', 'temso-ai' ); ?>
			</p>
			<form action="options.php" method="post">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Tracking', 'temso-ai' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="1" <?php checked( $settings['enabled'] ); ?> />
								<?php esc_html_e( 'Send all server-side requests to Temso', 'temso-ai' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="temso-ingest-url"><?php esc_html_e( 'Ingest URL', 'temso-ai' ); ?></label>
						</th>
						<td>
							<input type="url" id="temso-ingest-url" class="regular-text code"
								name="<?php echo esc_attr( self::OPTION ); ?>[ingest_url]"
								value="<?php echo esc_attr( $settings['ingest_url'] ); ?>"
								placeholder="https://api.temso.ai/v1/traffic/ingest/wordpress/&lt;source-id&gt;" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="temso-api-key"><?php esc_html_e( 'API key', 'temso-ai' ); ?></label>
						</th>
						<td>
							<input type="password" id="temso-api-key" class="regular-text code"
								name="<?php echo esc_attr( self::OPTION ); ?>[api_key]"
								value="<?php echo esc_attr( $settings['api_key'] ); ?>"
								autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Test connection', 'temso-ai' ); ?></th>
						<td>
							<button type="button" class="button" id="temso-verify-btn">
								<?php esc_html_e( 'Test now', 'temso-ai' ); ?>
							</button>
							<span id="temso-verify-result" aria-live="polite" style="margin-left:8px;"></span>
							<p class="description">
								<?php esc_html_e( 'Calls Temso to confirm the URL and key are valid. Uses the values currently in the form — no need to save first.', 'temso-ai' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last batch sent', 'temso-ai' ); ?></th>
						<td>
							<?php
							if ( $last_sent > 0 ) {
								echo esc_html(
									sprintf(
										/* translators: %s: human-readable time difference. */
										__( '%s ago', 'temso-ai' ),
										human_time_diff( $last_sent )
									)
								);
							} else {
								esc_html_e( 'No batches sent yet.', 'temso-ai' );
							}
							?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Publishing', 'temso-ai' ); ?></h2>
				<p>
					<?php esc_html_e( 'Let Temso create and update posts on this site. In Temso, open Settings → Integrations → WordPress to generate a one-time setup link, paste it below, and click Connect publishing. The plugin and Temso exchange the publish secret for you — there is nothing to copy by hand.', 'temso-ai' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'temso-ai' ); ?></th>
						<td>
							<?php if ( $settings['publishing_connected_at'] > 0 ) : ?>
								<strong><?php esc_html_e( 'Publishing connected.', 'temso-ai' ); ?></strong>
								<?php
								echo ' ';
								echo esc_html(
									sprintf(
										/* translators: %s: human-readable time difference. */
										__( 'Connected %s ago.', 'temso-ai' ),
										human_time_diff( $settings['publishing_connected_at'] )
									)
								);
								?>
							<?php elseif ( '' !== $settings['publish_secret'] ) : ?>
								<?php esc_html_e( 'A publish secret is configured, but no Temso connection has been recorded yet. Paste a setup link to (re)connect.', 'temso-ai' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Not connected.', 'temso-ai' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="temso-setup-link"><?php esc_html_e( 'Publishing setup link', 'temso-ai' ); ?></label>
						</th>
						<td>
							<input type="text" id="temso-setup-link" class="regular-text code"
								autocomplete="off"
								placeholder="https://app.temso.ai/…?wordpress_setup=…" />
							<p>
								<button type="button" class="button button-primary" id="temso-connect-publishing-btn">
									<?php esc_html_e( 'Connect publishing', 'temso-ai' ); ?>
								</button>
								<span id="temso-publishing-result" aria-live="polite" style="margin-left:8px;"></span>
							</p>
							<p class="description">
								<?php esc_html_e( 'Paste the full setup link or just the code from Temso. The plugin generates a publish secret if needed and registers this site with Temso.', 'temso-ai' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<details style="margin:1em 0;">
					<summary><?php esc_html_e( 'Advanced: set the publish secret manually', 'temso-ai' ); ?></summary>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="temso-publish-secret"><?php esc_html_e( 'Publish shared secret', 'temso-ai' ); ?></label>
							</th>
							<td>
								<input type="password" id="temso-publish-secret" class="regular-text code"
									name="<?php echo esc_attr( self::OPTION ); ?>[publish_secret]"
									value="" autocomplete="off" />
								<p class="description">
									<?php esc_html_e( 'For staging or manual recovery only — the setup link above is the recommended path. The saved secret is never displayed; leave this blank to keep the current one, or enter a new value and Save to replace it.', 'temso-ai' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</details>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
