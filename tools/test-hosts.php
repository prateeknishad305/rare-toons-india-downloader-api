<?php
/**
 * Resolves one live link per host and prints what came back.
 *
 * Usage: php tools/test-hosts.php
 */

require_once __DIR__ . '/../core/bootstrap.php';

use RAI\Resolver;

$links = array(
	'hubcloud'    => 'https://hubcloud.mov/drive/qbbatvvsaqjiivv',
	'hubcloud.ist' => 'https://hubcloud.ist/drive/1xvobphzsd3xgbu',
	'pixeldrain' => 'https://pixeldrain.com/u/7AgY7b6q',
	'argon'      => 'https://argon.razorshell.space/downlead/lKVn5khoubgmkgA',
	'mega'       => 'https://mega.nz/file/YnpxwTJa#AlM_8YVyyLFsKJOe7-QZatI_OYRubQFs8AAJXGFh48w',
	'gofile'     => 'https://gofile.io/d/1Sb7sn',
	'zipcloud'   => 'https://animetoonhindi.com/zipcloud/?evx2ts3x3vuv3v1',
);

foreach ( $links as $name => $url ) {
	$resolution = Resolver::resolve( $url );

	printf(
		"%-13s resolved=%-5s %-10s %-40s %s\n",
		$name,
		$resolution['resolved'] ? 'yes' : 'no',
		$resolution['size_human'] ?? '-',
		substr( (string) ( $resolution['filename'] ?? '' ), 0, 40 ),
		$resolution['reason'] ?? ''
	);

	if ( ! empty( $resolution['meta']['members'] ) ) {
		printf( "               zip members: %d (first: %s)\n", count( $resolution['meta']['members'] ), $resolution['meta']['members'][0]['name'] );
	}
}
