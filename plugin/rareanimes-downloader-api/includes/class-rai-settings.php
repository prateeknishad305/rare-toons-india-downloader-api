<?php
/**
 * Settings screen and option handling.
 *
 * @package RAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin options.
 */
class RAI_Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'rai_api_settings';

	/**
	 * Settings page slug.
	 */
	const PAGE = 'rareanimes-downloader-api';

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'api_key'            => '',
			'require_key'        => 0,
			'cors_origin'        => '*',
			'cache_ttl'          => 3600,
			'resolve_ttl'        => 1800,
			'per_page'           => 20,
			'max_per_page'       => 50,
			'enable_resolution'  => 1,
			'resolve_timeout'    => 25,
			'gofile_account'     => '',
		);
	}

	/**
	 * All settings, merged with the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array_merge( self::defaults(), $saved );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Store settings.
	 *
	 * @param array $values Values to save.
	 */
	public static function update( array $values ): void {
		update_option( self::OPTION, array_merge( self::all(), $values ) );
	}

	/**
	 * Create an API key if there is none yet.
	 */
	public static function ensure_api_key(): string {
		$key = (string) self::get( 'api_key', '' );

		if ( '' === $key ) {
			$key = self::generate_key();
			self::update( array( 'api_key' => $key ) );
		}

		return $key;
	}

	/**
	 * Generate a random API key.
	 */
	public static function generate_key(): string {
		if ( function_exists( 'wp_generate_password' ) ) {
			return 'rai_' . wp_generate_password( 32, false, false );
		}

		return 'rai_' . bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Register the settings screen.
	 */
	public static function register_page(): void {
		add_options_page(
			__( 'Downloader API', 'rareanimes-downloader-api' ),
			__( 'Downloader API', 'rareanimes-downloader-api' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the settings screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['rai_save_settings'] ) ) {
			check_admin_referer( 'rai_save_settings' );

			$origin = isset( $_POST['cors_origin'] ) ? sanitize_text_field( wp_unslash( $_POST['cors_origin'] ) ) : '*';

			self::update(
				array(
					'require_key'       => isset( $_POST['require_key'] ) ? 1 : 0,
					'cors_origin'       => '' === $origin ? '*' : $origin,
					'cache_ttl'         => max( 60, (int) ( $_POST['cache_ttl'] ?? 3600 ) ),
					'resolve_ttl'       => max( 60, (int) ( $_POST['resolve_ttl'] ?? 1800 ) ),
					'per_page'          => max( 1, (int) ( $_POST['per_page'] ?? 20 ) ),
					'max_per_page'      => max( 1, (int) ( $_POST['max_per_page'] ?? 50 ) ),
					'enable_resolution' => isset( $_POST['enable_resolution'] ) ? 1 : 0,
					'resolve_timeout'   => max( 5, (int) ( $_POST['resolve_timeout'] ?? 25 ) ),
					'gofile_account'    => isset( $_POST['gofile_account'] ) ? sanitize_text_field( wp_unslash( $_POST['gofile_account'] ) ) : '',
				)
			);

			if ( ! empty( $_POST['regenerate_key'] ) ) {
				self::update( array( 'api_key' => self::generate_key() ) );
			}

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'rareanimes-downloader-api' ) . '</p></div>';
		}

		$settings = self::all();
		$key      = self::ensure_api_key();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Downloader API', 'rareanimes-downloader-api' ); ?></h1>
			<p><?php esc_html_e( 'JSON API for the anime catalogue. Base URL:', 'rareanimes-downloader-api' ); ?> <code><?php echo esc_html( rest_url( 'rai/v1' ) ); ?></code></p>

			<form method="post">
				<?php wp_nonce_field( 'rai_save_settings' ); ?>
				<input type="hidden" name="rai_save_settings" value="1">

				<h2><?php esc_html_e( 'Access', 'rareanimes-downloader-api' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rai_api_key"><?php esc_html_e( 'API key', 'rareanimes-downloader-api' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="rai_api_key" value="<?php echo esc_attr( $key ); ?>" readonly>
							<p class="description"><?php esc_html_e( 'Send it as the X-RAI-Key header or as an api_key query parameter.', 'rareanimes-downloader-api' ); ?></p>
							<label><input type="checkbox" name="regenerate_key" value="1"> <?php esc_html_e( 'Generate a new key', 'rareanimes-downloader-api' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Require the key', 'rareanimes-downloader-api' ); ?></th>
						<td><label><input type="checkbox" name="require_key" value="1" <?php checked( (int) $settings['require_key'], 1 ); ?>> <?php esc_html_e( 'Reject requests without a valid key', 'rareanimes-downloader-api' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="rai_cors_origin"><?php esc_html_e( 'Allowed origin', 'rareanimes-downloader-api' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="rai_cors_origin" name="cors_origin" value="<?php echo esc_attr( (string) $settings['cors_origin'] ); ?>">
							<p class="description"><?php esc_html_e( 'Use * to allow every origin, or a full origin such as https://app.example.com.', 'rareanimes-downloader-api' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Performance', 'rareanimes-downloader-api' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rai_cache_ttl"><?php esc_html_e( 'Series cache (seconds)', 'rareanimes-downloader-api' ); ?></label></th>
						<td><input type="number" min="60" id="rai_cache_ttl" name="cache_ttl" value="<?php echo esc_attr( (string) $settings['cache_ttl'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="rai_resolve_ttl"><?php esc_html_e( 'Link cache (seconds)', 'rareanimes-downloader-api' ); ?></label></th>
						<td><input type="number" min="60" id="rai_resolve_ttl" name="resolve_ttl" value="<?php echo esc_attr( (string) $settings['resolve_ttl'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="rai_per_page"><?php esc_html_e( 'Results per page', 'rareanimes-downloader-api' ); ?></label></th>
						<td><input type="number" min="1" id="rai_per_page" name="per_page" value="<?php echo esc_attr( (string) $settings['per_page'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="rai_max_per_page"><?php esc_html_e( 'Maximum per page', 'rareanimes-downloader-api' ); ?></label></th>
						<td><input type="number" min="1" id="rai_max_per_page" name="max_per_page" value="<?php echo esc_attr( (string) $settings['max_per_page'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="rai_resolve_timeout"><?php esc_html_e( 'Resolver timeout (seconds)', 'rareanimes-downloader-api' ); ?></label></th>
						<td><input type="number" min="5" id="rai_resolve_timeout" name="resolve_timeout" value="<?php echo esc_attr( (string) $settings['resolve_timeout'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="rai_gofile_account"><?php esc_html_e( 'GoFile account token', 'rareanimes-downloader-api' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="rai_gofile_account" name="gofile_account" value="<?php echo esc_attr( (string) $settings['gofile_account'] ); ?>">
							<p class="description"><?php esc_html_e( 'Optional. GoFile limits how many guest accounts an address may create. Open gofile.io in a browser, copy the accountToken cookie value, and paste it here.', 'rareanimes-downloader-api' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Link resolution', 'rareanimes-downloader-api' ); ?></th>
						<td><label><input type="checkbox" name="enable_resolution" value="1" <?php checked( (int) $settings['enable_resolution'], 1 ); ?>> <?php esc_html_e( 'Resolve host pages into direct file URLs', 'rareanimes-downloader-api' ); ?></label></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
