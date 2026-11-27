<?php
/**
 * Smoke test for the GoFile resolver: lists a folder, then the members of the
 * archive inside it.
 *
 * Usage: php tools/test-gofile.php [gofile-code]
 */

require_once __DIR__ . '/../core/bootstrap.php';

use RAI\Resolver;

$code = $argv[1] ?? '1Sb7sn';

$resolution = Resolver::resolve( 'https://gofile.io/d/' . $code );

printf( "resolved : %s\n", $resolution['resolved'] ? 'yes' : 'no' );
printf( "filename : %s\n", $resolution['filename'] ?? '-' );
printf( "size     : %s (%s)\n", $resolution['size'] ?? '-', $resolution['size_human'] ?? '-' );
printf( "direct   : %s\n", substr( (string) ( $resolution['direct_url'] ?? '' ), 0, 110 ) );
printf( "reason   : %s\n", $resolution['reason'] ?? '-' );

$files = $resolution['meta']['files'] ?? array();
foreach ( $files as $file ) {
	printf( "  file   : %-42s %8.1f MB  md5=%s\n", $file['name'], $file['size'] / 1048576, $file['md5'] );
}

$members = $resolution['meta']['members'] ?? array();
printf( "  members: %d\n", count( $members ) );
foreach ( array_slice( $members, 0, 8 ) as $member ) {
	printf( "    %-42s %8.1f MB  method=%d\n", $member['name'], $member['size'] / 1048576, $member['method'] );
}
if ( isset( $resolution['meta']['members_error'] ) ) {
	printf( "  members error: %s\n", $resolution['meta']['members_error'] );
}
