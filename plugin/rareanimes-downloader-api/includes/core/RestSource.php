<?php
/**
 * Reads posts through the public WordPress REST API.
 *
 * This is what the standalone deployment uses: it needs no database access,
 * only the public site.
 *
 * @package RAI
 */

namespace RAI;

final class RestSource implements SourceInterface {

	/**
	 * REST base URL, without a trailing slash.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Cache.
	 *
	 * @var CacheInterface
	 */
	private $cache;

	/**
	 * Request timeout.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Constructor.
	 *
	 * @param string          $base    REST base URL.
	 * @param CacheInterface  $cache   Cache for categories and counts.
	 * @param int             $timeout Request timeout.
	 */
	public function __construct( string $base, CacheInterface $cache, int $timeout = 25 ) {
		$this->base    = rtrim( $base, '/' );
		$this->cache   = $cache;
		$this->timeout = $timeout;
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_posts( array $args ): array {
		$per_page = (int) ( $args['per_page'] ?? 20 );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		$query = array(
			'per_page' => $per_page,
			'page'     => $page,
			'orderby'  => $args['orderby'] ?? 'date',
			'order'    => $args['order'] ?? 'desc',
		);

		if ( ! empty( $args['search'] ) ) {
			$query['search'] = $args['search'];
		}

		if ( ! empty( $args['category'] ) ) {
			$query['categories'] = (int) $args['category'];
		}

		$response = Http::request(
			$this->base . '/posts?' . http_build_query( $query ),
			array( 'timeout' => $this->timeout )
		);

		if ( ! $response['ok'] ) {
			throw new \RuntimeException( 'WordPress REST API returned HTTP ' . $response['status'] . '.' );
		}

		$items = json_decode( $response['body'], true );
		if ( ! is_array( $items ) ) {
			throw new \RuntimeException( 'WordPress REST API returned an unexpected payload.' );
		}

		$total = (int) ( $response['headers']['x-wp-total'] ?? count( $items ) );
		$pages = (int) ( $response['headers']['x-wp-totalpages'] ?? 1 );

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $pages,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_post( string $slug ): ?array {
		$response = Http::request(
			$this->base . '/posts?' . http_build_query( array( 'slug' => $slug, 'per_page' => 1 ) ),
			array( 'timeout' => $this->timeout )
		);

		if ( ! $response['ok'] ) {
			return null;
		}

		$items = json_decode( $response['body'], true );
		if ( ! is_array( $items ) || ! isset( $items[0] ) ) {
			return null;
		}

		return $items[0];
	}

	/**
	 * {@inheritDoc}
	 */
	public function categories(): array {
		$cached = $this->cache->get( 'source:categories' );
		if ( null !== $cached ) {
			return $cached;
		}

		$categories = array();
		$page       = 1;

		do {
			$response = Http::request(
				$this->base . '/categories?' . http_build_query(
					array(
						'per_page' => 100,
						'page'     => $page,
					)
				),
				array( 'timeout' => $this->timeout )
			);

			if ( ! $response['ok'] ) {
				break;
			}

			$items = json_decode( $response['body'], true );
			if ( ! is_array( $items ) || ! $items ) {
				break;
			}

			foreach ( $items as $item ) {
				$categories[ (int) $item['id'] ] = (string) $item['name'];
			}

			$pages = (int) ( $response['headers']['x-wp-totalpages'] ?? 1 );
			++$page;
		} while ( $page <= $pages );

		$this->cache->set( 'source:categories', $categories, 21600 );

		return $categories;
	}

	/**
	 * {@inheritDoc}
	 */
	public function total_posts(): int {
		$cached = $this->cache->get( 'source:total' );
		if ( null !== $cached ) {
			return (int) $cached['total'];
		}

		$response = Http::request(
			$this->base . '/posts?per_page=1',
			array( 'timeout' => $this->timeout )
		);

		$total = $response['ok'] ? (int) ( $response['headers']['x-wp-total'] ?? 0 ) : 0;
		$this->cache->set( 'source:total', array( 'total' => $total ), 3600 );

		return $total;
	}
}
