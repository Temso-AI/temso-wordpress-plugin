<?php
/**
 * Settings → Temso AI admin page.
 *
 * @package Temso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Temso_Settings {

	const OPTION = 'temso_settings';
	const GROUP  = 'temso_settings_group';

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

	public function boot() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( TEMSO_FILE ),
			array( $this, 'action_links' )
		);
	}

	/**
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

	public function add_page() {
		add_options_page(
			__( 'Temso AI', 'temso-ai' ),
			__( 'Temso AI', 'temso-ai' ),
			'manage_options',
			'temso-ai',
			array( $this, 'render' )
		);
	}

	public function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);
	}

	/**
	 * @param mixed $input Raw posted value.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();

		// https only — the API key travels in the Authorization header, so a
		// plain-http endpoint would leak it in cleartext.
		$url = isset( $input['ingest_url'] ) ? esc_url_raw( trim( (string) $input['ingest_url'] ), array( 'https' ) ) : '';

		return array(
			'enabled'    => ! empty( $input['enabled'] ),
			'ingest_url' => $url,
			'api_key'    => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '',
		);
	}

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
