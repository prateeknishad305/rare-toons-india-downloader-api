<?php
/**
 * Reads posts straight from the WordPress database.
 *
 * @package RAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data source backed by WP_Query.
 */
class RAI_Source implements RAI\SourceInterface {

	/**
	 * {@inheritDoc}
	 */
	public function list_posts( array $args ): array {
		$per_page = (int) ( $args['per_page'] ?? 20 );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		$query_args = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => $per_page,
			'offset'              => ( $page - 1 ) * $per_page,
			'orderby'             => $this->orderby( (string) ( $args['orderby'] ?? 'date' ) ),
			'order'               => ( 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) ) ) ? 'ASC' : 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( (string) $args['search'] );
		}

		if ( ! empty( $args['category'] ) ) {
			$query_args['cat'] = (int) $args['category'];
		}

		$query = new WP_Query( $query_args );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->to_array( $post );
		}

		return array(
			'items'    => $items,
			'total'    => (int) $query->found_posts,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) $query->max_num_pages,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_post( string $slug ): ?array {
		$posts = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);

		if ( ! $posts ) {
			return null;
		}

		return $this->to_array( $posts[0] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function categories(): array {
		$categories = get_categories(
			array(
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		$out = array();
		foreach ( (array) $categories as $category ) {
			$out[ (int) $category->term_id ] = (string) $category->name;
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function total_posts(): int {
		$counts = wp_count_posts( 'post' );

		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Translate a REST style orderby value into a WP_Query one.
	 */
	private function orderby( string $value ): string {
		switch ( $value ) {
			case 'title':
				return 'title';
			case 'modified':
				return 'modified';
			case 'id':
			case 'include':
				return 'ID';
			default:
				return 'date';
		}
	}

	/**
	 * Convert a WP_Post into the array shape the parser expects.
	 *
	 * The raw post content is used on purpose: the rendered content contains
	 * injected advertisement markup, and those links are not downloads.
	 */
	private function to_array( WP_Post $post ): array {
		return array(
			'id'         => (int) $post->ID,
			'slug'       => (string) $post->post_name,
			'link'       => (string) get_permalink( $post ),
			'title'      => array( 'rendered' => (string) get_the_title( $post ) ),
			'content'    => array( 'rendered' => (string) $post->post_content ),
			'date'       => (string) get_post_time( 'c', true, $post ),
			'modified'   => (string) get_post_modified_time( 'c', true, $post ),
			'categories' => wp_get_post_categories( (int) $post->ID ),
		);
	}
}
