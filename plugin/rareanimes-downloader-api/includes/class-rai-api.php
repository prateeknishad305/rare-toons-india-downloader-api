<?php
/**
 * REST routes for the downloader API.
 *
 * @package RAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the rai/v1 namespace.
 */
class RAI_API {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'rai/v1';

	/**
	 * Register every route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/series',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_series' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'search'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'category' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'orderby'  => array(
						'type'    => 'string',
						'enum'    => array( 'date', 'modified', 'title', 'id' ),
						'default' => 'date',
					),
					'order'    => array(
						'type' => 'string',
						'enum' => array( 'asc', 'desc' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/series/(?P<slug>[a-z0-9\-_]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_series_item' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/series/(?P<slug>[a-z0-9\-_]+)/episodes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_episodes' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/series/(?P<slug>[a-z0-9\-_]+)/episodes/(?P<number>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_episode' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/series/(?P<slug>[a-z0-9\-_]+)/episodes/(?P<number>\d+)/links',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_episode_links' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/resolve',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_resolve' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
				'args'                => array(
					'url'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
					'refresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_search' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
				'args'                => array(
					'q'       => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_categories' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_stats' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_health' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Permission callback: the API key is optional unless it is required.
	 */
	public static function can_access(): bool {
		if ( ! (int) RAI_Settings::get( 'require_key', 0 ) ) {
			return true;
		}

		$key = RAI_Settings::ensure_api_key();

		$header = '';
		if ( isset( $_SERVER['HTTP_X_RAI_KEY'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_RAI_KEY'] ) );
		}

		$query = isset( $_GET['api_key'] ) ? sanitize_text_field( wp_unslash( $_GET['api_key'] ) ) : '';

		if ( '' !== $header && hash_equals( $key, $header ) ) {
			return true;
		}

		if ( '' !== $query && hash_equals( $key, $query ) ) {
			return true;
		}

		return false;
	}

	/**
	 * GET /rai/v1/series
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_series( WP_REST_Request $request ): WP_REST_Response {
		$service = RAI_Plugin::service();

		$result = $service->list_series(
			array(
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
				'search'   => (string) $request->get_param( 'search' ),
				'category' => (int) $request->get_param( 'category' ),
				'orderby'  => (string) $request->get_param( 'orderby' ),
				'order'    => (string) $request->get_param( 'order' ),
			)
		);

		return self::respond( $result );
	}

	/**
	 * GET /rai/v1/series/{slug}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_series_item( WP_REST_Request $request ): WP_REST_Response {
		$series = RAI_Plugin::service()->get_series( (string) $request->get_param( 'slug' ) );

		if ( null === $series ) {
			return self::respond(
				array(
					'error' => array(
						'code'    => 'series_not_found',
						'message' => 'No series matches that slug.',
					),
				),
				404
			);
		}

		return self::respond( array( 'series' => $series ) );
	}

	/**
	 * GET /rai/v1/series/{slug}/episodes
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_episodes( WP_REST_Request $request ): WP_REST_Response {
		$series = RAI_Plugin::service()->get_series( (string) $request->get_param( 'slug' ) );

		if ( null === $series ) {
			return self::respond(
				array(
					'error' => array(
						'code'    => 'series_not_found',
						'message' => 'No series matches that slug.',
					),
				),
				404
			);
		}

		return self::respond(
			array(
				'series'   => array(
					'id'    => $series['id'],
					'slug'  => $series['slug'],
					'title' => $series['title'],
					'url'   => $series['url'],
					'type'  => $series['type'],
					'info'  => $series['info'],
				),
				'episodes' => $series['episodes'],
				'counts'   => $series['counts'],
			)
		);
	}

	/**
	 * GET /rai/v1/series/{slug}/episodes/{number}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_episode( WP_REST_Request $request ): WP_REST_Response {
		$series  = RAI_Plugin::service()->get_series( (string) $request->get_param( 'slug' ) );
		$number  = (int) $request->get_param( 'number' );

		if ( null === $series ) {
			return self::respond(
				array(
					'error' => array(
						'code'    => 'series_not_found',
						'message' => 'No series matches that slug.',
					),
				),
				404
			);
		}

		$episode = RAI_Plugin::service()->find_episode( $series, $number );

		if ( null === $episode ) {
			return self::respond(
				array(
					'error' => array(
						'code'    => 'episode_not_found',
						'message' => 'That episode does not exist in this series.',
					),
				),
				404
			);
		}

		return self::respond( array( 'episode' => $episode ) );
	}

	/**
	 * GET /rai/v1/series/{slug}/episodes/{number}/links
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_episode_links( WP_REST_Request $request ): WP_REST_Response {
		$refresh = filter_var( $request->get_param( 'refresh' ), FILTER_VALIDATE_BOOLEAN );

		$episode = RAI_Plugin::service()->resolve_episode(
			(string) $request->get_param( 'slug' ),
			(int) $request->get_param( 'number' ),
			$refresh
		);

		if ( null === $episode ) {
			return self::respond(
				array(
					'error' => array(
						'code'    => 'episode_not_found',
						'message' => 'That episode does not exist in this series.',
					),
				),
				404
			);
		}

		return self::respond( array( 'episode' => $episode ) );
	}

	/**
	 * GET /rai/v1/resolve
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_resolve( WP_REST_Request $request ): WP_REST_Response {
		$refresh = filter_var( $request->get_param( 'refresh' ), FILTER_VALIDATE_BOOLEAN );

		$resolution = RAI_Plugin::service()->resolve_url(
			(string) $request->get_param( 'url' ),
			$refresh
		);

		if ( isset( $resolution['error'] ) ) {
			return self::respond( $resolution, 400 );
		}

		return self::respond( $resolution );
	}

	/**
	 * GET /rai/v1/search
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_search( WP_REST_Request $request ): WP_REST_Response {
		$result = RAI_Plugin::service()->search(
			(string) $request->get_param( 'q' ),
			array(
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
			)
		);

		return self::respond( $result );
	}

	/**
	 * GET /rai/v1/categories
	 */
	public static function get_categories(): WP_REST_Response {
		return self::respond( array( 'categories' => RAI_Plugin::service()->categories() ) );
	}

	/**
	 * GET /rai/v1/stats
	 */
	public static function get_stats(): WP_REST_Response {
		return self::respond( RAI_Plugin::service()->stats() );
	}

	/**
	 * GET /rai/v1/health
	 */
	public static function get_health(): WP_REST_Response {
		return self::respond(
			array(
				'status'         => 'ok',
				'plugin_version' => RAI_PLUGIN_VERSION,
				'core_version'   => RAI_CORE_VERSION,
				'php_version'    => PHP_VERSION,
				'resolution'     => (bool) RAI_Settings::get( 'enable_resolution', 1 ),
				'time'           => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Wrap a payload in a response and add the CORS headers.
	 *
	 * @param mixed $payload Payload.
	 * @param int   $status  HTTP status.
	 */
	private static function respond( $payload, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $payload, $status );

		$origin = (string) RAI_Settings::get( 'cors_origin', '*' );
		if ( '' === $origin ) {
			$origin = '*';
		}

		$response->header( 'Access-Control-Allow-Origin', $origin );
		$response->header( 'Access-Control-Allow-Headers', 'X-RAI-Key, Content-Type, Authorization' );
		$response->header( 'Access-Control-Allow-Methods', 'GET, OPTIONS' );
		$response->header( 'Vary', 'Origin' );

		return $response;
	}
}
