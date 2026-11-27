<?php
/**
 * Data source contract.
 *
 * Two implementations exist: one that reads the WordPress database directly
 * (used by the plugin) and one that reads the public WordPress REST API
 * (used by the standalone deployment).
 *
 * @package RAI
 */

namespace RAI;

interface SourceInterface {

	/**
	 * List raw posts.
	 *
	 * @param array $args page, per_page, search, category, orderby, order.
	 * @return array{items:array<int,array>,total:int,page:int,per_page:int,pages:int}
	 */
	public function list_posts( array $args ): array;

	/**
	 * Fetch a single raw post by slug.
	 *
	 * @param string $slug Post slug.
	 * @return array|null Raw post, or null when not found.
	 */
	public function get_post( string $slug ): ?array;

	/**
	 * All categories, keyed by id.
	 *
	 * @return array<int,string>
	 */
	public function categories(): array;

	/**
	 * Total number of published posts.
	 */
	public function total_posts(): int;
}
