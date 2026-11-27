<?php
/**
 * Standalone front controller.
 *
 * Runs the same API as the WordPress plugin without needing WordPress:
 * it reads the catalogue through the public WordPress REST API.
 *
 * Point a virtual host at this directory and every request is routed here.
 *
 * @package RAI
 */

require_once __DIR__ . '/../core/bootstrap.php';

use RAI\FileCache;
use RAI\RestSource;
use RAI\Service;

$config_file = __DIR__ . '/config.php';
$config      = file_exists( $config_file ) ? require $config_file : array();

$config = array_merge(
	array(
		'api_base'           => 'https://www.rareanimes.foo/wp-json/wp/v2',
		'base_url'           => '',
		'api_key'            => '',
		'cors_origin'        => '*',
		'cache_dir'          => __DIR__ . '/cache',
		'cache_ttl'          => 3600,
		'resolve_ttl'        => 1800,
		'per_page'           => 20,
		'max_per_page'       => 50,
		'enable_resolution'  => true,
		'resolve_timeout'    => 25,
	),
	(array) $config
);

header( 'Content-Type: application/json; charset=utf-8' );
header( 'Access-Control-Allow-Origin: ' . $config['cors_origin'] );
header( 'Access-Control-Allow-Headers: X-RAI-Key, Content-Type, Authorization' );
header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
header( 'X-Robots-Tag: noindex' );

if ( 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
	http_response_code( 204 );
	exit;
}

/**
 * Send a JSON payload and stop.
 *
 * @param mixed $payload Payload.
 * @param int   $status  HTTP status.
 */
function rai_send( $payload, int $status = 200 ): void {
	http_response_code( $status );
	echo json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	exit;
}

/**
 * Send an error payload and stop.
 *
 * @param string $code    Error code.
 * @param string $message Message.
 * @param int    $status  HTTP status.
 */
function rai_error( string $code, string $message, int $status = 400 ): void {
	rai_send(
		array(
			'error' => array(
				'code'    => $code,
				'message' => $message,
			),
		),
		$status
	);
}

// Authentication. The health check stays open so a monitor can reach it.
$api_key = (string) $config['api_key'];
if ( '' !== $api_key ) {
	$supplied = '';
	if ( isset( $_SERVER['HTTP_X_RAI_KEY'] ) ) {
		$supplied = (string) $_SERVER['HTTP_X_RAI_KEY'];
	} elseif ( isset( $_GET['api_key'] ) ) {
		$supplied = (string) $_GET['api_key'];
	}

	$request_path = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

	if ( ! hash_equals( $api_key, $supplied ) && ! str_ends_with( rtrim( $request_path, '/' ), '/health' ) ) {
		rai_error( 'unauthorized', 'A valid API key is required.', 401 );
	}
}

$cache = new FileCache( (string) $config['cache_dir'] );
$source = new RestSource( (string) $config['api_base'], $cache );

$service = new Service(
	$source,
	$cache,
	array(
		'cache_ttl'       => (int) $config['cache_ttl'],
		'resolve_ttl'     => (int) $config['resolve_ttl'],
		'per_page'        => (int) $config['per_page'],
		'max_per_page'    => (int) $config['max_per_page'],
		'resolve'         => (bool) $config['enable_resolution'],
		'resolve_timeout' => (int) $config['resolve_timeout'],
		'gofile_account'  => (string) $config['gofile_account'],
	)
);

$path   = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
$path   = '/' . trim( $path, '/' );
$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

if ( 'GET' !== $method ) {
	rai_error( 'method_not_allowed', 'Only GET is supported.', 405 );
}

// Drop a trailing file name so the API can live in a sub directory.
if ( false !== strpos( $path, '.php' ) ) {
	$path = (string) preg_replace( '#/index\.php$#', '', $path );
}

$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );

$get = static function ( string $key, $default = null ) {
	return isset( $_GET[ $key ] ) ? $_GET[ $key ] : $default;
};

switch ( true ) {
	case empty( $segments ):
		rai_send(
			array(
				'service'   => 'RareToons Downloader API',
				'version'   => RAI_CORE_VERSION,
				'base_url'  => $config['base_url'],
				'endpoints' => array(
					'/series',
					'/series/{slug}',
					'/series/{slug}/episodes',
					'/series/{slug}/episodes/{number}',
					'/series/{slug}/episodes/{number}/links',
					'/resolve?url=',
					'/search?q=',
					'/categories',
					'/stats',
					'/health',
				),
			)
		);
		break;

	case 'series' === $segments[0] && 1 === count( $segments ):
		rai_send(
			$service->list_series(
				array(
					'page'     => (int) $get( 'page', 1 ),
					'per_page' => (int) $get( 'per_page', 0 ),
					'search'   => (string) $get( 'search', '' ),
					'category' => (int) $get( 'category', 0 ),
					'orderby'  => (string) $get( 'orderby', 'date' ),
					'order'    => (string) $get( 'order', 'desc' ),
				)
			)
		);
		break;

	case 'series' === $segments[0] && 2 === count( $segments ):
		$series = $service->get_series( (string) $segments[1] );
		if ( null === $series ) {
			rai_error( 'series_not_found', 'No series matches that slug.', 404 );
		}
		rai_send( array( 'series' => $series ) );
		break;

	case 'series' === $segments[0] && 3 === count( $segments ) && 'episodes' === $segments[2]:
		$series = $service->get_series( (string) $segments[1] );
		if ( null === $series ) {
			rai_error( 'series_not_found', 'No series matches that slug.', 404 );
		}
		rai_send(
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
		break;

	case 'series' === $segments[0] && 4 === count( $segments ) && 'episodes' === $segments[2]:
		$series = $service->get_series( (string) $segments[1] );
		if ( null === $series ) {
			rai_error( 'series_not_found', 'No series matches that slug.', 404 );
		}
		$episode = $service->find_episode( $series, (int) $segments[3] );
		if ( null === $episode ) {
			rai_error( 'episode_not_found', 'That episode does not exist in this series.', 404 );
		}
		rai_send( array( 'episode' => $episode ) );
		break;

	case 'series' === $segments[0] && 5 === count( $segments ) && 'episodes' === $segments[2] && 'links' === $segments[4]:
		$episode = $service->resolve_episode(
			(string) $segments[1],
			(int) $segments[3],
			filter_var( $get( 'refresh', false ), FILTER_VALIDATE_BOOLEAN )
		);
		if ( null === $episode ) {
			rai_error( 'episode_not_found', 'That episode does not exist in this series.', 404 );
		}
		rai_send( array( 'episode' => $episode ) );
		break;

	case 'resolve' === $segments[0]:
		$url = (string) $get( 'url', '' );
		if ( '' === $url ) {
			rai_error( 'missing_url', 'A url parameter is required.' );
		}
		$resolution = $service->resolve_url( $url, filter_var( $get( 'refresh', false ), FILTER_VALIDATE_BOOLEAN ) );
		if ( isset( $resolution['error'] ) ) {
			rai_error( $resolution['error']['code'], $resolution['error']['message'] );
		}
		rai_send( $resolution );
		break;

	case 'search' === $segments[0]:
		$query = (string) $get( 'q', '' );
		if ( '' === $query ) {
			rai_error( 'missing_query', 'A q parameter is required.' );
		}
		rai_send(
			$service->search(
				$query,
				array(
					'page'     => (int) $get( 'page', 1 ),
					'per_page' => (int) $get( 'per_page', 0 ),
				)
			)
		);
		break;

	case 'categories' === $segments[0]:
		rai_send( array( 'categories' => $service->categories() ) );
		break;

	case 'stats' === $segments[0]:
		rai_send( $service->stats() );
		break;

	case 'health' === $segments[0]:
		rai_send(
			array(
				'status'       => 'ok',
				'core_version' => RAI_CORE_VERSION,
				'php_version'  => PHP_VERSION,
				'resolution'   => (bool) $config['enable_resolution'],
				'time'         => gmdate( 'c' ),
			)
		);
		break;

	default:
		rai_error( 'not_found', 'Unknown endpoint.', 404 );
}
