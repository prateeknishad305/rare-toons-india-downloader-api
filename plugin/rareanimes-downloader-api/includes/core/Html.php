<?php
/**
 * HTML helpers shared by the parser and the resolver.
 *
 * @package RAI
 */

namespace RAI;

final class Html {

	/**
	 * Strip tags and normalise whitespace/entity encoding.
	 */
	public static function text( string $html ): string {
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#<(br|hr)\b[^>]*>#i', ' ', $html );
		$html = strip_tags( $html );
		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$html = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $html );
		$html = preg_replace( '/\s+/u', ' ', $html );

		return trim( $html );
	}

	/**
	 * Split a document into block-level chunks.
	 *
	 * Each block carries its visible text, the text that precedes the first
	 * anchor ("prefix"), the anchors it contains and the tag the block came
	 * from. That is exactly the shape the post templates use: a label line
	 * followed by one or more link lines.
	 *
	 * @return array<int,array{tag:string,text:string,prefix:string,links:array<int,array{url:string,label:string}>}>
	 */
	public static function blocks( string $html ): array {
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html );

		$tokens = preg_split(
			'#(</p\s*>|</h[1-6]\s*>|</li\s*>|</div\s*>|</tr\s*>|<hr\s*/?>|<br\s*/?>)#i',
			$html,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		$blocks = array();
		$tag    = 'p';

		foreach ( (array) $tokens as $token ) {
			if ( ! is_string( $token ) || '' === trim( $token ) ) {
				continue;
			}

			// A closing tag updates the current block type.
			if ( preg_match( '#^</\s*(p|h[1-6]|li|div|tr)\b#i', $token, $match ) ) {
				$tag = strtolower( $match[1] );
				continue;
			}

			// Void tags (hr, br) only separate blocks.
			if ( preg_match( '#^<\s*(hr|br)\b#i', $token ) ) {
				continue;
			}

			$part = trim( $token );
			if ( '' === $part ) {
				continue;
			}

			// An opening tag tells us what this block is.
			if ( preg_match( '#^<\s*(p|h[1-6]|li|div|tr)\b#i', $part, $match ) ) {
				$tag = strtolower( $match[1] );
			}

			$text   = self::text( $part );
			$links  = self::anchors( $part );
			// The templates wrap links in square brackets; dropping them keeps
			// the prefix usable as a label.
			$prefix = str_replace( array( '[', ']' ), '', self::text( self::strip_anchors( $part ) ) );
			if ( '' === $text && ! $links ) {
				continue;
			}

			$blocks[] = array(
				'tag'    => $tag,
				'text'   => $text,
				'prefix' => $prefix,
				'links'  => $links,
			);
		}

		return $blocks;
	}

	/**
	 * Remove anchors from a chunk, keeping the surrounding text.
	 */
	public static function strip_anchors( string $html ): string {
		return preg_replace( '#<a\b[^>]*>.*?</a>#is', ' ', $html );
	}

	/**
	 * Extract every anchor in a chunk.
	 *
	 * @return array<int,array{url:string,label:string}>
	 */
	public static function anchors( string $html ): array {
		$out = array();

		if ( ! preg_match_all( '#<a\b[^>]*?href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $matches as $match ) {
			$url = html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$url = trim( $url );
			if ( '' === $url || 0 === strpos( $url, '#' ) || 0 === strpos( $url, 'javascript:' ) ) {
				continue;
			}
			$out[] = array(
				'url'   => $url,
				'label' => self::text( $match[3] ),
			);
		}

		return $out;
	}

	/**
	 * Decode HTML entities without touching other characters.
	 */
	public static function decode( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Normalise a title for searching: lowercase, punctuation to spaces.
	 */
	public static function slug_text( string $value ): string {
		$value = self::decode( $value );
		$value = mb_strtolower( $value, 'UTF-8' );
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value );

		return trim( (string) $value );
	}
}
