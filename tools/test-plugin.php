<?php
/**
 * Exercises the WordPress plugin without a WordPress install.
 *
 * The stubs below mimic the parts of WordPress the plugin uses, and the data
 * comes from the live site, so the plugin code paths are tested for real.
 *
 * Usage: php tools/test-plugin.php
 *
 * @package RAI
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

$api_base = 'https://www.rareanimes.foo/wp-json/wp/v2';

/**
 * Minimal WordPress function stubs.
 */
function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/' ) . '/'; }
function plugin_dir_url( $file ) { return 'https://www.rareanimes.foo/wp-content/plugins/rareanimes-downloader-api/'; }
function register_activation_hook( $file, $callback ) {}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['wp_actions'][ $hook ][] = $callback; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}
function add_options_page( $page_title, $menu_title, $capability, $slug, $callback = null ) {}
function register_rest_route( $namespace, $route, $args = array() ) { $GLOBALS['wp_routes'][ $namespace . $route ] = $args; }
function rest_url( $path = '' ) { return 'https://www.rareanimes.foo/wp-json/' . ltrim( $path, '/' ); }
function get_option( $name, $default = false ) { return $GLOBALS['wp_options'][ $name ] ?? $default; }
function update_option( $name, $value ) { $GLOBALS['wp_options'][ $name ] = $value; }
function get_transient( $key ) { return $GLOBALS['wp_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['wp_transients'][ $key ] = $value; }
function delete_transient( $key ) { unset( $GLOBALS['wp_transients'][ $key ] ); }
function current_user_can( $cap ) { return true; }
function check_admin_referer( $action ) { return true; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function esc_url_raw( $url ) { return $url; }
function sanitize_title( $title ) { return strtolower( preg_replace( '/[^a-z0-9]+/', '-', $title ) ); }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ); }
function wp_generate_password( $length = 12, $special = true, $extra = false ) { return substr( str_shuffle( str_repeat( 'abcdefghijklmnopqrstuvwxyz0123456789', 4 ) ), 0, $length ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return $text; }
function esc_attr( $text ) { return $text; }
function esc_html_e( $text, $domain = '' ) { echo $text; }
function checked( $a, $b = true ) { echo $a === $b ? 'checked' : ''; }
function submit_button() {}
function wp_nonce_field( $action ) {}
function add_query_arg( $k, $v, $url ) { return $url . '&' . $k . '=' . $v; }
function admin_url( $path ) { return 'https://www.rareanimes.foo/wp-admin/' . $path; }
function wp_safe_redirect( $url ) {}
function wp_die( $message ) { throw new Exception( $message ); }
function flush_rewrite_rules( $hard = true ) {}
function wp_count_posts( $type ) { return (object) array( 'publish' => 1946 ); }
function get_permalink( $post ) { return 'https://www.rareanimes.foo/' . $post->post_name . '/'; }
function get_the_title( $post ) { return $post->post_title; }
function get_post_time( $format, $gmt, $post ) { return $post->post_date_gmt; }
function get_post_modified_time( $format, $gmt, $post ) { return $post->post_modified_gmt; }
function wp_get_post_categories( $id ) { return $GLOBALS['wp_post_categories'][ $id ] ?? array( 1 ); }

/**
 * Stub post object.
 */
class WP_Post {
	public $ID = 0;
	public $post_name = '';
	public $post_content = '';
	public $post_title = '';
	public $post_date_gmt = '';
	public $post_modified_gmt = '';
}

/**
 * Stub WP_Query that reads the live catalogue.
 */
class WP_Query {
	public $posts = array();
	public $found_posts = 0;
	public $max_num_pages = 0;

	public function __construct( $args = array() ) {
		global $api_base;

		$per_page = (int) ( $args['posts_per_page'] ?? 10 );
		$offset   = (int) ( $args['offset'] ?? 0 );
		$page     = (int) floor( $offset / max( 1, $per_page ) ) + 1;

		$query = array(
			'per_page' => $per_page,
			'page'     => $page,
		);

		if ( ! empty( $args['s'] ) ) {
			$query['search'] = $args['s'];
		}

		if ( ! empty( $args['cat'] ) ) {
			$query['categories'] = (int) $args['cat'];
		}

		if ( ! empty( $args['orderby'] ) ) {
			$query['orderby'] = ( $args['orderby'] === 'modified' ) ? 'modified' : ( $args['orderby'] === 'title' ? 'title' : 'date' );
			$query['order']   = strtolower( (string) ( $args['order'] ?? 'desc' ) );
		}

		$response = RAI\Http::request( $api_base . '/posts?' . http_build_query( $query ) );

		$items   = json_decode( $response['body'], true );
		$items   = ( is_array( $items ) && array_is_list( $items ) ) ? $items : array();
		$total   = (int) ( $response['headers']['x-wp-total'] ?? count( $items ) );
		$pages   = (int) ( $response['headers']['x-wp-totalpages'] ?? 1 );

		foreach ( $items as $item ) {
			$post              = new WP_Post();
			$post->ID          = (int) $item['id'];
			$post->post_name   = (string) $item['slug'];
			$post->post_title  = (string) $item['title']['rendered'];
			$post->post_content = (string) $item['content']['rendered'];
			$post->post_date_gmt     = (string) $item['date_gmt'];
			$post->post_modified_gmt = (string) $item['modified_gmt'];

			$GLOBALS['wp_post_categories'][ $post->ID ] = (array) $item['categories'];

			$this->posts[] = $post;
		}

		$this->found_posts   = $total;
		$this->max_num_pages = $pages;
	}
}

/**
 * Stub get_posts().
 */
function get_posts( $args = array() ) {
	global $api_base;

	$response = RAI\Http::request( $api_base . '/posts?' . http_build_query( array( 'slug' => $args['name'] ?? '', 'per_page' => 1 ) ) );
	$items    = json_decode( $response['body'], true );

	if ( ! is_array( $items ) || ! $items ) {
		return array();
	}

	$post                = new WP_Post();
	$post->ID            = (int) $items[0]['id'];
	$post->post_name     = (string) $items[0]['slug'];
	$post->post_title    = (string) $items[0]['title']['rendered'];
	$post->post_content  = (string) $items[0]['content']['rendered'];
	$post->post_date_gmt = (string) $items[0]['date_gmt'];
	$post->post_modified_gmt = (string) $items[0]['modified_gmt'];

	$GLOBALS['wp_post_categories'][ $post->ID ] = (array) $items[0]['categories'];

	return array( $post );
}

/**
 * Stub get_categories().
 */
function get_categories( $args = array() ) {
	global $api_base;

	$response = RAI\Http::request( $api_base . '/categories?per_page=100' );
	$items    = json_decode( $response['body'], true );
	$out      = array();

	foreach ( (array) $items as $item ) {
		$category              = new stdClass();
		$category->term_id     = (int) $item['id'];
		$category->name        = (string) $item['name'];
		$out[]                 = $category;
	}

	return $out;
}

/**
 * Stub request class.
 */
class WP_REST_Request {
	private $params = array();

	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}

/**
 * Stub response class.
 */
class WP_REST_Response {
	public $data;
	public $status;
	public $headers = array();

	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function header( $key, $value ) {
		$this->headers[ $key ] = $value;
	}
}

/**
 * Stub server constants holder.
 */
class WP_REST_Server {
	const READABLE = 'GET';
}

// Load the plugin exactly like WordPress would.
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../plugin/rareanimes-downloader-api/rareanimes-downloader-api.php';

// Fire rest_api_init the way WordPress does once every plugin is loaded.
foreach ( (array) ( $GLOBALS['wp_actions']['rest_api_init'] ?? array() ) as $callback ) {
	call_user_func( $callback );
}

$failures = 0;
$checks   = 0;

/**
 * Assert helper.
 *
 * @param string $name     Check name.
 * @param bool   $ok       Result.
 * @param string $details  Details.
 */
function check( string $name, bool $ok, string $details = '' ): void {
	global $failures, $checks;
	++$checks;
	if ( $ok ) {
		echo "  PASS  {$name}\n";
		return;
	}
	++$failures;
	echo "  FAIL  {$name}" . ( '' !== $details ? " -> {$details}" : '' ) . "\n";
}

echo "\n== Plugin bootstrap ==\n";
check( 'plugin file loaded', class_exists( 'RAI_Plugin' ) );
check( 'routes registered', ! empty( $GLOBALS['wp_routes'] ?? array() ) );
check(
	'rai/v1 namespace routes',
	count( array_filter( array_keys( $GLOBALS['wp_routes'] ?? array() ), static fn( $route ) => 0 === strpos( $route, 'rai/v1' ) ) ) === 10,
	implode( ', ', array_keys( $GLOBALS['wp_routes'] ?? array() ) )
);

echo "\n== Settings ==\n";
$key = RAI_Settings::ensure_api_key();
check( 'api key generated', is_string( $key ) && 0 === strpos( $key, 'rai_' ), $key );
check( 'api key stable', RAI_Settings::ensure_api_key() === $key );
check( 'defaults present', 3600 === (int) RAI_Settings::get( 'cache_ttl' ) );

echo "\n== Series listing ==\n";
$response = RAI_API::get_series( new WP_REST_Request( array( 'page' => 1, 'per_page' => 3 ) ) );
$payload  = $response->data;

check( 'status 200', 200 === $response->status );
check( 'items returned', ! empty( $payload['items'] ), 'no items' );
check( 'total reported', (int) $payload['total'] > 1000, (string) $payload['total'] );
check(
	'item has info',
	isset( $payload['items'][0]['info']['full_name'] ),
	json_encode( $payload['items'][0]['info'] ?? array() )
);
check(
	'item has counts',
	isset( $payload['items'][0]['counts']['episodes'] ) && isset( $payload['items'][0]['counts']['hosts'] )
);
check( 'CORS header set', isset( $response->headers['Access-Control-Allow-Origin'] ) );

echo "\n== Series detail ==\n";
$slug     = $payload['items'][0]['slug'];
$response = RAI_API::get_series_item( new WP_REST_Request( array( 'slug' => $slug ) ) );
$series   = $response->data['series'];

check( 'status 200', 200 === $response->status );
check( 'slug matches', $slug === $series['slug'] );
check( 'episodes present', ! empty( $series['episodes'] ) );
check( 'episode has variants', ! empty( $series['episodes'][0]['variants'] ) );
check(
	'links carry host info',
	isset( $series['episodes'][0]['variants'][0]['links'][0]['host'] ),
	json_encode( $series['episodes'][0]['variants'][0]['links'][0] ?? array() )
);

echo "\n== Episode ==\n";
$response = RAI_API::get_episode( new WP_REST_Request( array( 'slug' => $slug, 'number' => 1 ) ) );
check( 'status 200', 200 === $response->status );
check( 'episode number', 1 === (int) ( $response->data['episode']['number'] ?? 0 ) );

$response = RAI_API::get_episode( new WP_REST_Request( array( 'slug' => $slug, 'number' => 9999 ) ) );
check( 'missing episode gives 404', 404 === $response->status );

$response = RAI_API::get_episode_links( new WP_REST_Request( array( 'slug' => $slug, 'number' => 1 ) ) );
$links    = $response->data['episode']['variants'][0]['links'][0]['resolution'] ?? array();
check( 'episode links resolve', 200 === $response->status );
check( 'resolution block present', ! empty( $links ) );
check( 'resolution carries a verdict', array_key_exists( 'resolved', $links ) );

$response = RAI_API::get_episode_links( new WP_REST_Request( array( 'slug' => $slug, 'number' => 9999 ) ) );
check( 'missing episode links give 404', 404 === $response->status );

echo "\n== Resolution ==\n";
$response = RAI_API::get_resolve( new WP_REST_Request( array( 'url' => 'https://pixeldrain.com/u/7AgY7b6q' ) ) );
$resolved = $response->data;

check( 'status 200', 200 === $response->status );
check( 'resolved flag', ! empty( $resolved['resolved'] ) );
check( 'direct url', ! empty( $resolved['direct_url'] ) );
check( 'filename', ! empty( $resolved['filename'] ), json_encode( $resolved ) );
check( 'size', ! empty( $resolved['size'] ) );

$response = RAI_API::get_resolve( new WP_REST_Request( array( 'url' => 'not-a-url' ) ) );
check( 'invalid url gives 400', 400 === $response->status );

echo "\n== Search, categories, stats, health ==\n";
$response = RAI_API::get_search( new WP_REST_Request( array( 'q' => 'doraemon', 'per_page' => 3 ) ) );
check( 'search returns items', ! empty( $response->data['items'] ) );

$response = RAI_API::get_categories();
check( 'categories listed', ! empty( $response->data['categories'] ) );

$response = RAI_API::get_stats();
check( 'stats report series', (int) $response->data['series'] > 0 );

$response = RAI_API::get_health();
check( 'health ok', 'ok' === $response->data['status'] );

echo "\n== Authentication ==\n";
RAI_Settings::update( array( 'require_key' => 1 ) );
check( 'request without key is rejected', ! RAI_API::can_access() );

$_SERVER['HTTP_X_RAI_KEY'] = RAI_Settings::ensure_api_key();
check( 'request with header key accepted', RAI_API::can_access() );

unset( $_SERVER['HTTP_X_RAI_KEY'] );
$_GET['api_key'] = RAI_Settings::ensure_api_key();
check( 'request with query key accepted', RAI_API::can_access() );
unset( $_GET['api_key'] );

RAI_Settings::update( array( 'require_key' => 0 ) );
check( 'open mode allows requests', RAI_API::can_access() );

echo "\n" . ( $failures ? "{$failures} of {$checks} checks FAILED\n" : "All {$checks} checks passed\n" );
exit( $failures ? 1 : 0 );
