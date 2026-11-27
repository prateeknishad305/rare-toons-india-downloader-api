<?php
/**
 * Pulls a single file out of a remote season pack.
 *
 * The API resolves a GoFile folder to its archive and lists the members inside
 * it. This script fetches just the one member you name, using range requests,
 * so a five gigabyte pack never has to be downloaded whole.
 *
 * Usage:
 *   php tools/extract-member.php <pack-url> <member-name> [output-path]
 *
 * Example:
 *   php tools/extract-member.php https://gofile.io/d/1Sb7sn S01E01 ./Naruto.S01E01.mkv
 *
 * @package RAI
 */

require_once __DIR__ . '/../core/bootstrap.php';

use RAI\RemoteZip;
use RAI\Resolver;

$pack = $argv[1] ?? '';
$name = $argv[2] ?? '';
$out  = $argv[3] ?? '';

if ( '' === $pack || '' === $name ) {
	fwrite( STDERR, "Usage: php tools/extract-member.php <pack-url> <member-name> [output-path]\n" );
	exit( 1 );
}

$resolution = Resolver::resolve( $pack );

if ( empty( $resolution['resolved'] ) ) {
	fwrite( STDERR, 'Could not resolve the pack: ' . ( $resolution['reason'] ?? 'unknown error' ) . "\n" );
	exit( 1 );
}

$members = $resolution['meta']['members'] ?? array();

if ( ! $members ) {
	fwrite( STDERR, "The archive has no member list.\n" );
	exit( 1 );
}

$target = null;
foreach ( $members as $member ) {
	if ( false !== stripos( $member['name'], $name ) ) {
		$target = $member;
		break;
	}
}

if ( null === $target ) {
	fwrite( STDERR, "No member matches '{$name}'.\n" );
	exit( 1 );
}

if ( '' === $out ) {
	$out = preg_replace( '/[^\w.\- ]+/', '_', $target['name'] );
}

printf( "Member : %s\n", $target['name'] );
printf( "Size   : %s\n", number_format( $target['size'] ) . ' bytes' );
printf( "Method : %s\n", 8 === (int) $target['method'] ? 'deflate' : 'stored' );

$link = $resolution['direct_url'];
$headers = array();

// Some hosts (GoFile) need the guest session cookie for the download itself.
$headers = $resolution['meta']['session_headers'] ?? array();

// Read the local file header to find where the payload starts.
$head = RAI\Http::request(
	$link,
	array(
		'range'   => 'bytes=' . $target['offset'] . '-' . ( $target['offset'] + 29 ),
		'headers' => $headers,
	)
);

if ( "PK\x03\x04" !== substr( $head['body'], 0, 4 ) ) {
	fwrite( STDERR, "Could not read the local header.\n" );
	exit( 1 );
}

$lengths = unpack( 'vnamelen/vextralen', substr( $head['body'], 26, 4 ) );
$start   = (int) $target['offset'] + 30 + (int) $lengths['namelen'] + (int) $lengths['extralen'];
$end     = $start + (int) $target['compressed'] - 1;

printf( "Fetching bytes %d-%d …\n", $start, $end );

$payload = RAI\Http::request(
	$link,
	array(
		'range'   => 'bytes=' . $start . '-' . $end,
		'headers' => $headers,
	)
);

$body = $payload['body'];

if ( 8 === (int) $target['method'] ) {
	$body = gzinflate( $body );
	if ( false === $body ) {
		fwrite( STDERR, "Could not inflate the member.\n" );
		exit( 1 );
	}
}

if ( strlen( $body ) !== (int) $target['size'] ) {
	fwrite( STDERR, sprintf( "Size mismatch: got %d, expected %d.\n", strlen( $body ), $target['size'] ) );
	exit( 1 );
}

file_put_contents( $out, $body );
printf( "Wrote %s (%s bytes)\n", $out, number_format( strlen( $body ) ) );
