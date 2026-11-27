<?php
/**
 * Core bootstrap.
 *
 * Loads the shared classes. Safe to include from WordPress or from a plain
 * PHP script.
 *
 * @package RAI
 */

namespace RAI;

if ( ! defined( 'RAI_CORE_VERSION' ) ) {
	define( 'RAI_CORE_VERSION', '1.0.0' );
}

spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'RAI\\' ) ) {
			return;
		}

		$relative = substr( $class, 4 );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);

require_once __DIR__ . '/functions.php';
