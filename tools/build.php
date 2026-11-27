<?php
/**
 * Builds the distributable plugin.
 *
 * Copies the shared core into the plugin folder and zips the result so that
 * WordPress can install it directly.
 *
 * Usage: php tools/build.php
 *
 * @package RAI
 */

$root      = dirname( __DIR__ );
$core      = $root . '/core';
$plugin    = $root . '/plugin/rareanimes-downloader-api';
$target    = $plugin . '/includes/core';
$dist      = $root . '/dist';
$zip_path  = $dist . '/rareanimes-downloader-api.zip';

if ( ! is_dir( $target ) ) {
	mkdir( $target, 0755, true );
}

// Copy the core files.
$copied = 0;
foreach ( (array) glob( $core . '/*.php' ) as $file ) {
	$destination = $target . '/' . basename( $file );
	if ( copy( $file, $destination ) ) {
		++$copied;
	}
}
echo "Copied {$copied} core files into the plugin.\n";

// The plugin must not ship the development helpers.
foreach ( array( 'build.php' ) as $unused ) {
	unset( $unused );
}

if ( ! is_dir( $dist ) ) {
	mkdir( $dist, 0755, true );
}

if ( file_exists( $zip_path ) ) {
	unlink( $zip_path );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Could not create the archive.\n" );
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $plugin, FilesystemIterator::SKIP_DOTS )
);

foreach ( $iterator as $file ) {
	if ( ! $file->isFile() ) {
		continue;
	}
	$path = $file->getPathname();
	if ( false !== strpos( $path, '/.git/' ) ) {
		continue;
	}
	$zip->addFile( $path, 'rareanimes-downloader-api/' . ltrim( str_replace( $plugin, '', $path ), '/' ) );
}

$zip->close();

printf( "Wrote %s (%s)\n", $zip_path, number_format( (int) filesize( $zip_path ) / 1024 ) . ' KB' );
