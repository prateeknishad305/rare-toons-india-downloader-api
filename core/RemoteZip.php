<?php
/**
 * Reads the file listing of a remote ZIP through range requests.
 *
 * The season packs on rareanimes.foo are multi gigabyte ZIP files. This class
 * reads the central directory from the end of the archive so a client can
 * discover the members (one per episode) and then pull out just the one it
 * wants, without downloading the whole pack.
 *
 * @package RAI
 */

namespace RAI;

final class RemoteZip {

	/**
	 * How much of the tail to read when hunting for the directory.
	 */
	private const TAIL_BYTES = 8388608;

	/**
	 * List the members of a remote ZIP.
	 *
	 * @param string $url     Direct URL of the archive.
	 * @param array  $headers Extra request headers, for example a session cookie.
	 * @param int    $timeout Request timeout.
	 * @return array<int,array{name:string,size:int,compressed:int,offset:int,method:int}>
	 */
	public static function list_members( string $url, array $headers = array(), int $timeout = 25 ): array {
		$size = self::file_size( $url, $headers, $timeout );
		if ( null === $size ) {
			throw new \RuntimeException( 'The archive size could not be read.' );
		}

		$start    = max( 0, $size - self::TAIL_BYTES );
		$response = Http::request(
			$url,
			array(
				'range'   => 'bytes=' . $start . '-' . ( $size - 1 ),
				'headers' => $headers,
				'timeout' => $timeout,
			)
		);

		$tail = $response['body'];
		if ( strlen( $tail ) < 22 ) {
			throw new \RuntimeException( 'The archive tail could not be read.' );
		}

		$eocd = strrpos( $tail, "PK\x05\x06" );
		if ( false === $eocd ) {
			throw new \RuntimeException( 'No ZIP directory found; the server may ignore range requests.' );
		}

		list( $cd_offset, $cd_size ) = self::directory_location( $tail, $eocd, $start );

		if ( $cd_offset >= $start ) {
			$directory = substr( $tail, $cd_offset - $start, $cd_size > 0 ? $cd_size : null );
		} else {
			$response  = Http::request(
				$url,
				array(
					'range'   => 'bytes=' . $cd_offset . '-' . ( $cd_offset + max( 0, $cd_size ) - 1 ),
					'headers' => $headers,
					'timeout' => $timeout,
				)
			);
			$directory = $response['body'];
		}

		return self::parse_directory( $directory );
	}

	/**
	 * Size of the remote file.
	 *
	 * @return int|null
	 */
	private static function file_size( string $url, array $headers, int $timeout ): ?int {
		$response = Http::request(
			$url,
			array(
				'method'  => 'HEAD',
				'headers' => $headers,
				'timeout' => $timeout,
			)
		);

		$length = $response['headers']['content-length'] ?? '';

		return ( '' !== $length && ctype_digit( (string) $length ) ) ? (int) $length : null;
	}

	/**
	 * Locate the central directory, following the ZIP64 record when needed.
	 *
	 * @return array{0:int,1:int} Offset and size of the central directory.
	 */
	private static function directory_location( string $tail, int $eocd, int $tail_start ): array {
		$cd_size  = self::u32( $tail, $eocd + 12 );
		$cd_offset = self::u32( $tail, $eocd + 16 );

		if ( 0xFFFFFFFF !== $cd_offset && 0xFFFFFFFF !== $cd_size ) {
			return array( $cd_offset, $cd_size );
		}

		// ZIP64: the locator sits right before the classic record.
		$locator = strrpos( substr( $tail, 0, $eocd ), "PK\x06\x07" );
		if ( false === $locator ) {
			return array( $cd_offset, $cd_size );
		}

		$z64_offset = self::u64( $tail, $locator + 8 );
		$position   = $z64_offset - $tail_start;

		if ( $position < 0 || "PK\x06\x06" !== substr( $tail, $position, 4 ) ) {
			return array( $z64_offset, 0 );
		}

		return array( self::u64( $tail, $position + 48 ), self::u64( $tail, $position + 40 ) );
	}

	/**
	 * Parse central directory records.
	 *
	 * @return array<int,array{name:string,size:int,compressed:int,offset:int,method:int}>
	 */
	private static function parse_directory( string $directory ): array {
		$entries = array();
		$length  = strlen( $directory );
		$offset  = 0;

		while ( $offset + 46 <= $length && "PK\x01\x02" === substr( $directory, $offset, 4 ) ) {
			// The fixed part starts after the signature and the two version
			// fields, so the order is flags, method, time, date.
			$header = unpack(
				'vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnamelen/vextralen/vcommentlen/vdisk/vattrs/Veattrs/Voffset',
				substr( $directory, $offset + 8, 38 )
			);

			$name_length  = (int) $header['namelen'];
			$extra_length = (int) $header['extralen'];
			$name         = substr( $directory, $offset + 46, $name_length );
			$extra        = substr( $directory, $offset + 46 + $name_length, $extra_length );

			$compressed = (int) $header['compressed'];
			$size       = (int) $header['size'];
			$local      = (int) $header['offset'];

			// ZIP64 extra field holds the real values when the 32 bit ones are saturated.
			$cursor = 0;
			while ( $cursor + 4 <= strlen( $extra ) ) {
				$field = unpack( 'vid/vsize', substr( $extra, $cursor, 4 ) );
				$body  = substr( $extra, $cursor + 4, (int) $field['size'] );
				if ( 1 === (int) $field['id'] ) {
					$position = 0;
					if ( 0xFFFFFFFF === $size && $position + 8 <= strlen( $body ) ) {
						$size     = self::u64( $body, $position );
						$position += 8;
					}
					if ( 0xFFFFFFFF === $compressed && $position + 8 <= strlen( $body ) ) {
						$compressed = self::u64( $body, $position );
						$position  += 8;
					}
					if ( 0xFFFFFFFF === $local && $position + 8 <= strlen( $body ) ) {
						$local = self::u64( $body, $position );
					}
				}
				$cursor += 4 + (int) $field['size'];
			}

			$entries[] = array(
				'name'       => $name,
				'size'       => $size,
				'compressed' => $compressed,
				'offset'     => $local,
				'method'     => (int) $header['method'],
			);

			$offset += 46 + $name_length + $extra_length + (int) $header['commentlen'];
		}

		return $entries;
	}

	/**
	 * Little endian unsigned 32 bit integer.
	 */
	private static function u32( string $data, int $offset ): int {
		$parts = unpack( 'V', substr( $data, $offset, 4 ) );

		return $parts ? (int) $parts[1] : 0;
	}

	/**
	 * Little endian unsigned 64 bit integer.
	 */
	private static function u64( string $data, int $offset ): int {
		$parts = unpack( 'P', substr( $data, $offset, 8 ) );

		return $parts ? (int) $parts[1] : 0;
	}
}
