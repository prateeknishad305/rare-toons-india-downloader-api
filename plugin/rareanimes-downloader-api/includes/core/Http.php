<?php
/**
 * Minimal cURL wrapper.
 *
 * @package RAI
 */

namespace RAI;

final class Http {

	/**
	 * Default request timeout in seconds.
	 */
	public const TIMEOUT = 25;

	/**
	 * Browser-ish user agent. The hosts in front of the files are picky.
	 */
	public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

	/**
	 * Perform a request.
	 *
	 * @param string $url     Target URL.
	 * @param array  $options method, body, headers, follow, timeout, range, max_bytes.
	 * @return array{ok:bool,status:int,headers:array<string,string>,body:string,error:string,url:string}
	 */
	public static function request( string $url, array $options = array() ): array {
		$defaults = array(
			'method'    => 'GET',
			'body'      => null,
			'headers'   => array(),
			'follow'    => true,
			'timeout'   => self::TIMEOUT,
			'range'     => null,
			'max_bytes' => 0,
			'referer'   => null,
		);
		$options = array_merge( $defaults, $options );

		$result = array(
			'ok'      => false,
			'status'  => 0,
			'headers' => array(),
			'body'    => '',
			'error'   => '',
			'url'     => $url,
		);

		if ( ! function_exists( 'curl_init' ) ) {
			$result['error'] = 'The cURL extension is required.';

			return $result;
		}

		$handle = curl_init();
		$header_lines = array();

		$request_headers = array_merge(
			array(
				'Accept: */*',
				'Accept-Language: en-US,en;q=0.9',
				'User-Agent: ' . self::USER_AGENT,
			),
			$options['headers']
		);

		if ( $options['referer'] ) {
			$request_headers[] = 'Referer: ' . $options['referer'];
		}

		curl_setopt_array(
			$handle,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER         => true,
				CURLOPT_FOLLOWLOCATION => (bool) $options['follow'],
				CURLOPT_MAXREDIRS      => 8,
				CURLOPT_TIMEOUT        => (int) $options['timeout'],
				CURLOPT_CONNECTTIMEOUT => min( 15, (int) $options['timeout'] ),
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => 0,
				CURLOPT_ENCODING       => '',
				CURLOPT_HTTPHEADER     => $request_headers,
			)
		);

		if ( 'HEAD' === strtoupper( (string) $options['method'] ) ) {
			curl_setopt( $handle, CURLOPT_NOBODY, true );
		} elseif ( 'POST' === strtoupper( (string) $options['method'] ) ) {
			curl_setopt( $handle, CURLOPT_POST, true );
			curl_setopt( $handle, CURLOPT_POSTFIELDS, (string) $options['body'] );
		}

		if ( $options['range'] ) {
			$request_headers[] = 'Range: ' . $options['range'];
			curl_setopt( $handle, CURLOPT_HTTPHEADER, $request_headers );
		}

		$raw = curl_exec( $handle );

		if ( false === $raw ) {
			$result['error'] = curl_error( $handle );
			curl_close( $handle );

			return $result;
		}

		$header_size = (int) curl_getinfo( $handle, CURLINFO_HEADER_SIZE );
		$status      = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
		$final_url   = (string) curl_getinfo( $handle, CURLINFO_EFFECTIVE_URL );
		$content_type = (string) curl_getinfo( $handle, CURLINFO_CONTENT_TYPE );

		curl_close( $handle );

		$raw_headers = substr( (string) $raw, 0, $header_size );
		$body        = substr( (string) $raw, $header_size );

		foreach ( explode( "\n", $raw_headers ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = explode( ':', $line, 2 );
			$name  = strtolower( trim( $name ) );
			$value = trim( $value );
			if ( isset( $result['headers'][ $name ] ) ) {
				$result['headers'][ $name ] .= ', ' . $value;
			} else {
				$result['headers'][ $name ] = $value;
			}
		}

		if ( '' !== $content_type && ! isset( $result['headers']['content-type'] ) ) {
			$result['headers']['content-type'] = $content_type;
		}

		if ( $options['max_bytes'] > 0 && strlen( $body ) > (int) $options['max_bytes'] ) {
			$body = substr( $body, 0, (int) $options['max_bytes'] );
		}

		$result['status']  = $status;
		$result['headers'] = $result['headers'];
		$result['body']    = (string) $body;
		$result['url']     = $final_url;
		$result['ok']     = $status >= 200 && $status < 400;

		return $result;
	}

	/**
	 * Whether a response looks like a Cloudflare interstitial rather than content.
	 */
	public static function is_challenge( array $response ): bool {
		if ( 403 !== (int) $response['status'] && 503 !== (int) $response['status'] ) {
			return false;
		}
		if ( false !== stripos( (string) $response['body'], 'Just a moment' ) ) {
			return true;
		}
		if ( false !== stripos( (string) $response['body'], 'challenge-platform' ) ) {
			return true;
		}
		$server = strtolower( (string) ( $response['headers']['server'] ?? '' ) );

		return false !== strpos( $server, 'cloudflare' ) && false !== stripos( (string) $response['body'], 'challenge' );
	}

	/**
	 * Extract the total size of a remote file without downloading it.
	 *
	 * Uses a one byte range request, which every host used here honours.
	 *
	 * @return array{size:?int,filename:?string,mime:?string,status:int}
	 */
	public static function probe( string $url ): array {
		$out = array(
			'size'     => null,
			'filename' => null,
			'mime'     => null,
			'status'   => 0,
		);

		$response = self::request(
			$url,
			array(
				'range'   => 'bytes=0-0',
				'follow'  => true,
				'timeout' => 20,
			)
		);

		$out['status'] = (int) $response['status'];
		$headers      = $response['headers'];

		if ( isset( $headers['content-range'] ) && preg_match( '#/\s*(\d+)\s*$#', $headers['content-range'], $m ) ) {
			$out['size'] = (int) $m[1];
		} elseif ( isset( $headers['content-length'] ) ) {
			$out['size'] = (int) $headers['content-length'];
		}

		if ( isset( $headers['content-disposition'] ) ) {
			$out['filename'] = self::filename_from_disposition( $headers['content-disposition'] );
		}

		if ( isset( $headers['content-type'] ) ) {
			$out['mime'] = trim( explode( ';', $headers['content-type'] )[0] );
		}

		return $out;
	}

	/**
	 * Pull a filename out of a Content-Disposition header.
	 */
	public static function filename_from_disposition( string $header ): ?string {
		if ( preg_match( '/filename\*\s*=\s*[^\']*\'[^\']*\'([^;]+)/i', $header, $m ) ) {
			return trim( rawurldecode( trim( $m[1], " \t\"'" ) ) );
		}
		if ( preg_match( '/filename\s*=\s*"([^"]+)"/i', $header, $m ) ) {
			return trim( $m[1] );
		}
		if ( preg_match( '/filename\s*=\s*([^;]+)/i', $header, $m ) ) {
			return trim( trim( $m[1] ), '"' );
		}

		return null;
	}

	/**
	 * Human readable byte count.
	 */
	public static function human_bytes( ?int $bytes ): ?string {
		if ( null === $bytes ) {
			return null;
		}
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$value = (float) $bytes;
		$index = 0;
		while ( $value >= 1024 && $index < count( $units ) - 1 ) {
			$value /= 1024;
			++$index;
		}

		return ( 0 === $index ? (string) (int) $value : number_format( $value, 1 ) ) . ' ' . $units[ $index ];
	}
}
