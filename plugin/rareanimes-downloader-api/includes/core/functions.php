<?php
/**
 * Shared helper functions.
 *
 * These are namespaced so they never collide with WordPress or with the host
 * application when the core is used on its own.
 *
 * @package RAI
 */

namespace RAI;

if ( ! function_exists( 'RAI\wp_json_encode' ) ) {
	/**
	 * JSON encode helper.
	 *
	 * @param mixed $value Value to encode.
	 */
	function wp_json_encode( $value ): string {
		return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}

if ( ! function_exists( 'RAI\wp_rand_id' ) ) {
	/**
	 * A short random integer, used for API sequence numbers.
	 */
	function wp_rand_id(): int {
		try {
			return random_int( 1, 999999 );
		} catch ( \Throwable $error ) {
			return mt_rand( 1, 999999 );
		}
	}
}

if ( ! function_exists( 'RAI\wp_json_decode' ) ) {
	/**
	 * JSON decode helper that never throws.
	 *
	 * @param string $value JSON string.
	 * @return mixed
	 */
	function wp_json_decode( string $value ) {
		return json_decode( $value, true );
	}
}
