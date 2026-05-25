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

	const OPTION             = 'temso_settings';
	const GROUP              = 'temso_settings_group';
	const AJAX_VERIFY_ACTION = 'temso_verify';
	const SETTINGS_PAGE_HOOK = 'settings_page_temso-ai';

	/**
	 * Current settings, with defaults applied.
	 *
	 * @return array{enabled:bool,ingest_url:string,api_key:string}
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array(
			'enabled'    => ! empty( $saved['enabled'] ),
			'ingest_url' => isset( $saved['ingest_url'] ) ? (string) $saved['ingest_url'] : '',
			'api_key'    => isset( $saved['api_key'] ) ? (string) $saved['api_key'] : '',
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

		// https only — the API key travels in the Authorization header, so a
		// plain-http endpoint would leak it in cleartext.
		$url = isset( $input['ingest_url'] ) ? esc_url_raw( trim( (string) $input['ingest_url'] ), array( 'https' ) ) : '';

		// Temso keys are `tms_<lowercase-hex>`; sanitize_key() keeps exactly
		// that character set, so it preserves any valid key while stripping
		// stray whitespace, quotes, or control bytes that would corrupt the
		// Authorization header. sanitize_text_field() must not be used here:
		// it collapses whitespace and strips `%xx` octets, both of which can
		// silently mangle a secret.
		return array(
			'enabled'    => ! empty( $input['enabled'] ),
			'ingest_url' => $url,
			'api_key'    => isset( $input['api_key'] ) ? sanitize_key( $input['api_key'] ) : '',
		);
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
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
