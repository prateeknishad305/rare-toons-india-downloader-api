<?php
/**
 * Cache contract.
 *
 * @package RAI
 */

namespace RAI;

interface CacheInterface {

	/**
	 * Read a cached value.
	 *
	 * @param string $key Cache key.
	 * @return array|null Stored value, or null when missing.
	 */
	public function get( string $key ): ?array;

	/**
	 * Store a value.
	 *
	 * @param string $key   Cache key.
	 * @param array  $value Value to store.
	 * @param int    $ttl   Lifetime in seconds.
	 */
	public function set( string $key, array $value, int $ttl ): void;

	/**
	 * Remove a cached value.
	 *
	 * @param string $key Cache key.
	 */
	public function delete( string $key ): void;

	/**
	 * Prefix used for every key this cache stores.
	 */
	public function prefix(): string;
}
