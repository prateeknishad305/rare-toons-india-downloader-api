<?php
/**
 * Plugin wiring.
 *
 * @package RAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin and owns the shared service instance.
 */
class RAI_Plugin {

	/**
	 * Singleton.
	 *
	 * @var RAI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Service.
	 *
	 * @var RAI\Service|null
	 */
	private $service = null;

	/**
	 * Boot the plugin.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( 'RAI_API', 'register_routes' ) );
		add_action( 'admin_menu', array( 'RAI_Settings', 'register_page' ) );
		add_action( 'admin_post_rai_flush_cache', array( $this, 'handle_flush_cache' ) );
	}

	/**
	 * Singleton accessor.
	 *
	 * @return RAI_Plugin
	 */
	public static function instance(): RAI_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Shared service, built from the current settings.
	 *
	 * @return RAI\Service
	 */
	public static function service(): RAI\Service {
		return self::instance()->get_service();
	}

	/**
	 * Build the service.
	 *
	 * @return RAI\Service
	 */
	private function get_service(): RAI\Service {
		if ( null !== $this->service ) {
			return $this->service;
		}

		$settings = RAI_Settings::all();

		$this->service = new RAI\Service(
			new RAI_Source(),
			new RAI_Cache(),
			array(
				'cache_ttl'       => (int) $settings['cache_ttl'],
				'resolve_ttl'     => (int) $settings['resolve_ttl'],
				'per_page'        => (int) $settings['per_page'],
				'max_per_page'    => (int) $settings['max_per_page'],
				'resolve'         => (bool) $settings['enable_resolution'],
				'resolve_timeout' => (int) $settings['resolve_timeout'],
				'gofile_account'  => (string) $settings['gofile_account'],
			)
		);

		return $this->service;
	}

	/**
	 * Run on activation.
	 */
	public static function on_activation(): void {
		RAI_Settings::ensure_api_key();

		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Clear the cache from the admin screen.
	 */
	public function handle_flush_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'rareanimes-downloader-api' ) );
		}

		check_admin_referer( 'rai_flush_cache' );

		$cache = new RAI_Cache();
		$cache->flush();

		wp_safe_redirect( add_query_arg( 'rai_flushed', '1', admin_url( 'options-general.php?page=' . RAI_Settings::PAGE ) ) );
		exit;
	}
}
