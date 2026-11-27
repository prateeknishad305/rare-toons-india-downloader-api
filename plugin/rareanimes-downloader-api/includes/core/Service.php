<?php
/**
 * The API business logic.
 *
 * Both deployments (WordPress plugin and standalone) use this class, so the
 * payload shape is identical no matter where it runs.
 *
 * @package RAI
 */

namespace RAI;

final class Service {

	/**
	 * Data source.
	 *
	 * @var SourceInterface
	 */
	private $source;

	/**
	 * Cache.
	 *
	 * @var CacheInterface
	 */
	private $cache;

	/**
	 * Options.
	 *
	 * @var array<string,mixed>
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param SourceInterface $source  Data source.
	 * @param CacheInterface  $cache   Cache.
	 * @param array           $options cache_ttl, resolve_ttl, per_page, max_per_page.
	 */
	public function __construct( SourceInterface $source, CacheInterface $cache, array $options = array() ) {
		$this->source  = $source;
		$this->cache   = $cache;
		$this->options = array_merge(
			array(
				'cache_ttl'     => 3600,
				'resolve_ttl'   => 1800,
				'per_page'      => 20,
				'max_per_page'  => 50,
				'resolve'       => true,
				'gofile_account' => '',
			),
			$options
		);
	}

	/**
	 * List series.
	 *
	 * @param array $args page, per_page, search, category, orderby, order.
	 * @return array
	 */
	public function list_series( array $args ): array {
		$per_page = (int) ( $args['per_page'] ?? $this->options['per_page'] );
		$per_page = max( 1, min( $per_page, (int) $this->options['max_per_page'] ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		$query = array(
			'page'     => $page,
			'per_page' => $per_page,
		);

		foreach ( array( 'search', 'category', 'orderby', 'order' ) as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$query[ $key ] = $args[ $key ];
			}
		}

		$cache_key = 'list:' . md5( (string) wp_json_encode( $query ) );
		$cached    = $this->cache->get( $cache_key );

		if ( null !== $cached ) {
			$cached['cached'] = true;

			return $cached;
		}

		$result = $this->source->list_posts( $query );

		$items = array();
		foreach ( $result['items'] as $post ) {
			$items[] = $this->summarize( $this->parse( $post ) );
		}

		$payload = array(
			'items'    => $items,
			'total'    => (int) $result['total'],
			'page'     => (int) $result['page'],
			'per_page' => (int) $result['per_page'],
			'pages'    => (int) $result['pages'],
			'cached'   => false,
		);

		$this->cache->set( $cache_key, $payload, (int) $this->options['cache_ttl'] );

		return $payload;
	}

	/**
	 * Fetch one series with its episodes.
	 *
	 * @param string $slug Post slug.
	 * @return array|null
	 */
	public function get_series( string $slug ): ?array {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$cache_key = 'series:' . $slug;
		$cached    = $this->cache->get( $cache_key );

		if ( null !== $cached ) {
			$cached['cached'] = true;

			return $cached;
		}

		$post = $this->source->get_post( $slug );
		if ( null === $post ) {
			return null;
		}

		$series               = $this->parse( $post );
		$series['cached']     = false;

		$this->cache->set( $cache_key, $series, (int) $this->options['cache_ttl'] );

		return $series;
	}

	/**
	 * Search series.
	 *
	 * @param string $query Search term.
	 * @param array  $args  Extra list arguments.
	 * @return array
	 */
	public function search( string $query, array $args = array() ): array {
		$args['search'] = $query;

		return $this->list_series( $args );
	}

	/**
	 * Categories with post counts.
	 *
	 * @return array
	 */
	public function categories(): array {
		$cached = $this->cache->get( 'categories' );
		if ( null !== $cached ) {
			return $cached;
		}

		$categories = array();
		foreach ( $this->source->categories() as $id => $name ) {
			$categories[] = array(
				'id'   => (int) $id,
				'name' => (string) $name,
				'slug' => sanitize_title( (string) $name ),
			);
		}

		$this->cache->set( 'categories', $categories, (int) $this->options['cache_ttl'] );

		return $categories;
	}

	/**
	 * Resolve a single URL.
	 *
	 * @param string $url      URL to resolve.
	 * @param bool   $no_cache Skip the cache.
	 * @return array
	 */
	public function resolve_url( string $url, bool $no_cache = false ): array {
		$url = trim( $url );
		if ( '' === $url ) {
			return array(
				'error' => array(
					'code'    => 'missing_url',
					'message' => 'A url parameter is required.',
				),
			);
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return array(
				'error' => array(
					'code'    => 'invalid_url',
					'message' => 'Only http and https URLs are supported.',
				),
			);
		}

		$cache_key = 'resolve:' . md5( $url );

		if ( ! $no_cache ) {
			$cached = $this->cache->get( $cache_key );
			if ( null !== $cached ) {
				$cached['cached'] = true;

				return $cached;
			}
		}

		if ( empty( $this->options['resolve'] ) ) {
			$resolution = array(
				'url'         => $url,
				'resolved'    => false,
				'direct_url'  => null,
				'filename'    => null,
				'size'        => null,
				'size_human'  => null,
				'mime_type'   => null,
				'expires_at'  => null,
				'reason'      => 'Link resolution is switched off on this server.',
				'meta'        => array(),
				'resolved_at' => gmdate( 'c' ),
				'cached'      => false,
			);

			$this->cache->set( $cache_key, $resolution, (int) $this->options['resolve_ttl'] );

			return $resolution;
		}

		$resolution = Resolver::resolve(
			$url,
			array(
				'timeout'        => (int) ( $this->options['resolve_timeout'] ?? Http::TIMEOUT ),
				'gofile_account' => (string) ( $this->options['gofile_account'] ?? '' ),
			)
		);
		$resolution['cached'] = false;

		$this->cache->set( $cache_key, $resolution, (int) $this->options['resolve_ttl'] );

		return $resolution;
	}

	/**
	 * Resolve every link of one episode.
	 *
	 * @param string $slug       Series slug.
	 * @param int    $number     Episode number.
	 * @param bool   $no_cache   Skip the cache.
	 * @return array|null
	 */
	public function resolve_episode( string $slug, int $number, bool $no_cache = false ): ?array {
		$series = $this->get_series( $slug );
		if ( null === $series ) {
			return null;
		}

		$episode = $this->find_episode( $series, $number );
		if ( null === $episode ) {
			return null;
		}

		foreach ( $episode['variants'] as $variant_index => $variant ) {
			foreach ( $variant['links'] as $link_index => $link ) {
				if ( ! $link['resolvable'] ) {
					$episode['variants'][ $variant_index ]['links'][ $link_index ]['resolution'] = array(
						'resolved' => false,
						'reason'   => $link['internal'] ? 'Internal site link.' : 'This host cannot be resolved automatically.',
					);
					continue;
				}

				$resolution = $this->resolve_url( $link['url'], $no_cache );
				if ( isset( $resolution['error'] ) ) {
					$resolution = array(
						'resolved' => false,
						'reason'   => $resolution['error']['message'],
					);
				}

				$episode['variants'][ $variant_index ]['links'][ $link_index ]['resolution'] = $resolution;
			}
		}

		$episode['series'] = array(
			'id'    => $series['id'],
			'slug'  => $series['slug'],
			'title' => $series['title'],
			'url'   => $series['url'],
		);

		return $episode;
	}

	/**
	 * Find an episode inside a parsed series.
	 *
	 * @param array $series Parsed series.
	 * @param int   $number Episode number.
	 * @return array|null
	 */
	public function find_episode( array $series, int $number ): ?array {
		foreach ( (array) $series['episodes'] as $episode ) {
			if ( (int) $episode['number'] === $number ) {
				return $episode;
			}
		}

		return null;
	}

	/**
	 * Aggregate statistics.
	 *
	 * @return array
	 */
	public function stats(): array {
		$cached = $this->cache->get( 'stats' );
		if ( null !== $cached ) {
			$cached['cached'] = true;

			return $cached;
		}

		$payload = array(
			'series'     => $this->source->total_posts(),
			'categories' => count( $this->source->categories() ),
			'hosts'      => Hosts::all(),
			'generated_at' => gmdate( 'c' ),
			'cached'     => false,
		);

		$this->cache->set( 'stats', $payload, 600 );

		return $payload;
	}

	/**
	 * Parse a raw post, adding the category names.
	 */
	private function parse( array $post ): array {
		$post['categories_names'] = $this->category_names( $post );

		return Parser::parse( $post );
	}

	/**
	 * Resolve the category ids of a post into names.
	 *
	 * @return array<int,string>
	 */
	private function category_names( array $post ): array {
		$ids = (array) ( $post['categories'] ?? array() );
		if ( ! $ids ) {
			return array();
		}

		$categories = $this->source->categories();
		$names      = array();

		foreach ( $ids as $id ) {
			if ( isset( $categories[ $id ] ) ) {
				$names[] = (string) $categories[ $id ];
			}
		}

		return $names;
	}

	/**
	 * Reduce a parsed series to the fields used in list responses.
	 *
	 * @return array
	 */
	private function summarize( array $series ): array {
		return array(
			'id'           => $series['id'],
			'slug'         => $series['slug'],
			'title'        => $series['title'],
			'url'          => $series['url'],
			'type'         => $series['type'],
			'updated_at'   => $series['updated_at'],
			'categories'   => $series['categories'],
			'description'  => $series['description'],
			'info'         => $series['info'],
			'counts'       => $series['counts'],
		);
	}
}

if ( ! function_exists( 'RAI\sanitize_key' ) ) {
	/**
	 * Lowercase key safe string.
	 *
	 * @param string $key Raw key.
	 */
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'RAI\sanitize_title' ) ) {
	/**
	 * Slug safe title.
	 *
	 * @param string $title Raw title.
	 */
	function sanitize_title( string $title ): string {
		$title = strtolower( trim( $title ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );

		return trim( (string) $title, '-' );
	}
}
