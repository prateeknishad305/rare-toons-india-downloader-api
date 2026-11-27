<?php
/**
 * File backed cache, used by the standalone deployment.
 *
 * @package RAI
 */

namespace RAI;

final class FileCache implements CacheInterface {

	/**
	 * Directory that holds the cache files.
	 *
	 * @var string
	 */
	private $directory;

	/**
	 * Key prefix.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Constructor.
	 *
	 * @param string $directory Cache directory.
	 * @param string $prefix    Key prefix.
	 */
	public function __construct( string $directory, string $prefix = 'rai_' ) {
		$this->directory = rtrim( $directory, '/' );
		$this->prefix    = $prefix;

		if ( ! is_dir( $this->directory ) ) {
			@mkdir( $this->directory, 0755, true );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key ): ?array {
		$file = $this->file( $key );
		if ( ! is_readable( $file ) ) {
			return null;
		}

		$raw = file_get_contents( $file );
		if ( false === $raw ) {
			return null;
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['expires'] ) ) {
			return null;
		}

		if ( $data['expires'] < time() ) {
			@unlink( $file );

			return null;
		}

		return isset( $data['value'] ) && is_array( $data['value'] ) ? $data['value'] : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $key, array $value, int $ttl ): void {
		$file = $this->file( $key );
		$dir  = dirname( $file );

		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			return;
		}

		$payload = wp_json_encode(
			array(
				'expires' => time() + max( 1, $ttl ),
				'value'   => $value,
			)
		);

		@file_put_contents( $file, $payload, LOCK_EX );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $key ): void {
		$file = $this->file( $key );
		if ( is_file( $file ) ) {
			@unlink( $file );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * Absolute path of the file that backs a key.
	 */
	private function file( string $key ): string {
		$hash = hash( 'sha256', $this->prefix . $key );

		return $this->directory . '/' . substr( $hash, 0, 2 ) . '/' . substr( $hash, 2 ) . '.json';
	}
}

