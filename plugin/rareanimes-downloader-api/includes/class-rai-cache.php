<?php
/**
 * Transient backed cache.
 *
 * @package RAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores parsed series and resolved links in WordPress transients.
 */
class RAI_Cache implements RAI\CacheInterface {

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Constructor.
	 *
	 * @param string $prefix Transient prefix.
	 */
	public function __construct( string $prefix = 'rai_' ) {
		$this->prefix = $prefix;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key ): ?array {
		$value = get_transient( $this->prefix . $key );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $key, array $value, int $ttl ): void {
		set_transient( $this->prefix . $key, $value, max( 1, $ttl ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $key ): void {
		delete_transient( $this->prefix . $key );
	}

	/**
	 * {@inheritDoc}
	 */
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * Clear every cache entry this plugin owns.
	 */
	public function flush(): void {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . $this->prefix ) . '%';

		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$like,
				$wpdb->esc_like( '_transient_timeout_' . $this->prefix ) . '%'
			)
		);

		foreach ( (array) $names as $name ) {
			$key = str_replace( array( '_transient_', '_transient_timeout_' ), '', $name );
			delete_transient( $key );
		}
	}
}
