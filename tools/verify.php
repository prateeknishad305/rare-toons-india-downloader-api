<?php
/**
 * End to end verification against the live site.
 *
 * Usage:
 *   php tools/verify.php [posts] [--resolve]
 *
 * @package RAI
 */

require_once __DIR__ . '/../core/bootstrap.php';

use RAI\FileCache;
use RAI\Hosts;
use RAI\Parser;
use RAI\RestSource;
use RAI\Resolver;

$site     = 'https://www.rareanimes.foo/wp-json/wp/v2';
$count    = (int) ( $argv[1] ?? 25 );
$resolve  = in_array( '--resolve', $argv, true );
$cache    = new FileCache( sys_get_temp_dir() . '/rai-verify-cache', 'verify_' );
$source   = new RestSource( $site, $cache );

echo "Fetching {$count} posts from {$site}\n\n";

$response = RAI\Http::request( $site . '/posts?per_page=' . $count );
$posts    = json_decode( $response['body'], true );

if ( ! is_array( $posts ) ) {
	echo "Could not fetch posts: " . substr( (string) $response['body'], 0, 300 ) . "\n";
	exit( 1 );
}

$stats = array(
	'posts'          => 0,
	'episodes'       => 0,
	'links'          => 0,
	'with_episodes'  => 0,
	'with_info'      => 0,
	'labels'         => array(),
	'hosts'          => array(),
	'unlabelled'     => 0,
	'empty_episodes' => array(),
);

$sample_links = array();

foreach ( $posts as $post ) {
	$series = Parser::parse( $post );

	++$stats['posts'];
	$stats['episodes'] += (int) $series['counts']['episodes'];
	$stats['links']    += (int) $series['counts']['links'];

	if ( $series['counts']['episodes'] > 0 ) {
		++$stats['with_episodes'];
	} else {
		$stats['empty_episodes'][] = $series['slug'];
	}

	if ( ! empty( $series['info']['full_name'] ) ) {
		++$stats['with_info'];
	}

	foreach ( $series['episodes'] as $episode ) {
		foreach ( $episode['variants'] as $variant ) {
			$stats['labels'][ $variant['label'] ] = ( $stats['labels'][ $variant['label'] ] ?? 0 ) + 1;

			if ( 'Main' === $variant['label'] ) {
				++$stats['unlabelled'];
			}

			foreach ( $variant['links'] as $link ) {
				$stats['hosts'][ $link['host'] ] = ( $stats['hosts'][ $link['host'] ] ?? 0 ) + 1;

				if ( ! isset( $sample_links[ $link['host'] ] ) && $link['resolvable'] ) {
					$sample_links[ $link['host'] ] = $link['url'];
				}
			}
		}
	}
}

printf(
	"Parsed %d posts | %d episodes | %d links | episodes found in %d | info in %d | unlabelled variants %d\n",
	$stats['posts'],
	$stats['episodes'],
	$stats['links'],
	$stats['with_episodes'],
	$stats['with_info'],
	$stats['unlabelled']
);

echo "\nVariant labels:\n";
arsort( $stats['labels'] );
foreach ( $stats['labels'] as $label => $total ) {
	printf( "  %-28s %d\n", $label, $total );
}

echo "\nHosts:\n";
arsort( $stats['hosts'] );
foreach ( $stats['hosts'] as $host => $total ) {
	printf( "  %-28s %d\n", $host, $total );
}

if ( $stats['empty_episodes'] ) {
	echo "\nPosts without episodes:\n";
	foreach ( $stats['empty_episodes'] as $slug ) {
		echo "  - {$slug}\n";
	}
}

// Show one fully parsed episode for a visual check.
echo "\n--- sample episode ---\n";
$first = Parser::parse( $posts[0] );
echo json_encode(
	array(
		'title'    => $first['title'],
		'info'     => $first['info'],
		'type'     => $first['type'],
		'counts'   => $first['counts'],
		'episodes' => array_slice( $first['episodes'], 0, 2 ),
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
), "\n";

if ( $resolve ) {
	echo "\n--- resolver ---\n";
	foreach ( $sample_links as $host => $url ) {
		$resolution = Resolver::resolve( $url );
		printf(
			"%-12s resolved=%-5s %-10s %-38s %s\n",
			$host,
			$resolution['resolved'] ? 'yes' : 'no',
			$resolution['size_human'] ?? '-',
			substr( (string) $resolution['filename'], 0, 38 ),
			$resolution['reason'] ?? ''
		);
	}
}
