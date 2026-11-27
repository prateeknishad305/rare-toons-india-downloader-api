<?php
/**
 * Download host registry.
 *
 * Every link that appears on rareanimes.foo points at one of a small number of
 * file hosts. This class maps a URL onto a host id plus the identifier that the
 * resolver needs, so the resolver itself stays a simple switch.
 *
 * @package RAI
 */

namespace RAI;

final class Hosts {

	/**
	 * Host definitions keyed by id.
	 *
	 * - label      human readable name shown in the API payload
	 * - resolve    whether the resolver can turn this into a direct file URL
	 * - hosts      the domains that belong to this host
	 * - link_label the anchor text the site uses for this host
	 */
	private const REGISTRY = array(
		'hubcloud' => array(
			'label'      => 'HubCloud',
			'resolve'    => true,
			'link_label' => 'HubCloud',
			'hosts'      => array( 'hubcloud.mov', 'hubcloud.ist', 'hubcloud.pw', 'hubcloud.club', 'yournewdomain.com' ),
		),
		'pixeldrain' => array(
			'label'      => 'Pixeldrain',
			'resolve'    => true,
			'link_label' => 'Pixeldrain',
			'hosts'      => array( 'pixeldrain.com', 'pixeldrain.dev' ),
		),
		'mega' => array(
			'label'      => 'MEGA',
			'resolve'    => true,
			'link_label' => 'Mega',
			'hosts'      => array( 'mega.nz', 'mega.co.nz' ),
		),
		'argon' => array(
			'label'      => 'Argon (WatchMultiQuality)',
			'resolve'    => true,
			'link_label' => 'WatchMultiQuality',
			'hosts'      => array( 'argon.razorshell.space' ),
		),
		'zipcloud' => array(
			'label'      => 'ZipCloud',
			'resolve'    => false,
			'link_label' => 'ZIP – CLOUD',
			'hosts'      => array( 'animetoonhindi.com' ),
		),
		'gofile' => array(
			'label'      => 'GoFile',
			'resolve'    => true,
			'link_label' => 'ZIP – GoFile',
			'hosts'      => array( 'gofile.io', 'gofile.com', 'gofile.me' ),
		),
		'direct' => array(
			'label'      => 'Direct file',
			'resolve'    => true,
			'link_label' => 'Direct',
			'hosts'      => array(),
		),
	);

	/**
	 * Extensions that are treated as a direct file link.
	 */
	private const DIRECT_EXTENSIONS = array(
		'mkv', 'mp4', 'avi', 'webm', 'mov', 'm4v', 'flv', 'wmv',
		'zip', 'rar', '7z', 'tar', 'gz', 'mp3', 'm4a', 'wav', 'epub', 'pdf',
	);

	/**
	 * Classify a URL.
	 *
	 * @return array{id:string,label:string,resolve:bool,host:string,file_id:?string}
	 */
	public static function classify( string $url ): array {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

		foreach ( self::REGISTRY as $id => $definition ) {
			if ( in_array( $host, $definition['hosts'], true ) ) {
				return array(
					'id'      => $id,
					'label'   => $definition['label'],
					'resolve' => $definition['resolve'],
					'host'    => $host,
					'file_id' => self::extract_id( $id, $url ),
				);
			}
		}

		$extension = strtolower( (string) pathinfo( (string) parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$is_file  = in_array( $extension, self::DIRECT_EXTENSIONS, true );

		return array(
			'id'      => $is_file ? 'direct' : 'external',
			'label'   => $is_file ? 'Direct file' : 'External',
			'resolve' => $is_file,
			'host'    => $host,
			'file_id' => null,
		);
	}

	/**
	 * Pull the host specific identifier out of a URL.
	 */
	private static function extract_id( string $host_id, string $url ): ?string {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		$frag = (string) parse_url( $url, PHP_URL_FRAGMENT );

		switch ( $host_id ) {
			case 'hubcloud':
				if ( preg_match( '#/drive/([A-Za-z0-9_-]+)#', $path, $m ) ) {
					return $m[1];
				}
				break;

			case 'pixeldrain':
				if ( preg_match( '#/(?:u|api/file)/([A-Za-z0-9_-]+)#', $path, $m ) ) {
					return $m[1];
				}
				break;

			case 'mega':
				if ( preg_match( '#/file/([A-Za-z0-9_-]+)#', $path, $m ) ) {
					return $m[1] . ( '' !== $frag ? '#' . $frag : '' );
				}
				break;

			case 'argon':
				if ( preg_match( '#/downlead/([A-Za-z0-9_-]+)#', $path, $m ) ) {
					return $m[1];
				}
				break;

			case 'zipcloud':
				$query = (string) parse_url( $url, PHP_URL_QUERY );
				if ( preg_match( '#(?:^|&)id=([A-Za-z0-9_-]+)#', $query, $m ) ) {
					return $m[1];
				}
				if ( preg_match( '#/zipcloud/([A-Za-z0-9_-]+)#', $path, $m ) ) {
					return $m[1];
				}
				break;

			case 'gofile':
				if ( preg_match( '#/d/([A-Za-z0-9]+)#', $path, $m ) ) {
					return $m[1];
				}
				break;
		}

		return null;
	}

	/**
	 * Public list of hosts, used by /stats and the settings screen.
	 */
	public static function all(): array {
		$out = array();
		foreach ( self::REGISTRY as $id => $definition ) {
			$out[ $id ] = array(
				'label'   => $definition['label'],
				'resolve' => $definition['resolve'],
				'hosts'   => $definition['hosts'],
			);
		}

		return $out;
	}
}

