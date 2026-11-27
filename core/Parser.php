<?php
/**
 * Turns a raw post into a structured series payload.
 *
 * The post templates on rareanimes.foo are consistent enough to parse
 * reliably:
 *
 *   <p>Episode 01 – It Is What It Is</p>
 *   <p><span>Hindi FanDub</span> – [<a href="…">WatchMultiQuality</a>]</p>
 *   <p><span>Hindi Sub</span> – [<a href="…">HubCloud</a>] [<a href="…">WatchNow</a>]</p>
 *
 * Movie posts skip the episode header and use headings instead:
 *
 *   <h4>Hindi – Download</h4>
 *   <h3><a href="…">HubCloud</a></h3>
 *
 * The parser works on blocks (paragraphs and headings) rather than on raw
 * markup, so cosmetic template changes do not break it.
 *
 * @package RAI
 */

namespace RAI;

final class Parser {

	/**
	 * Info fields, in the order they appear in a post.
	 */
	private const INFO_FIELDS = array(
		'full_name'     => '/(?:📰\s*)?Full\s*Name\s*:\s*(.+)/iu',
		'season'        => '/(?:🍂\s*)?Season\s*:\s*(.+)/iu',
		'episode_count' => '/(?:🎞\s*)?Episodes\s*:\s*(\d+)/iu',
		'release_year'  => '/(?:🕓\s*)?Release\s*Year\s*:\s*(.+)/iu',
		'runtime'       => '/(?:⌛\s*)?Run\s*Time\s*:\s*(.+)/iu',
		'genre'         => '/(?:🎭\s*)?Genre\s*:\s*(.+)/iu',
		'language'      => '/(?:🔊\s*)?Language\s*:\s*(.+)/iu',
		'quality'       => '/(?:🎬\s*)?Quality\s*:\s*(.+)/iu',
		'synopsis'      => '/(?:✨\s*)?(?:Synopsis|Storyline|Movie\s*Story)\s*:\s*(.+)/iu',
	);

	/**
	 * Episode header, e.g. "Episode 01 – It Is What It Is".
	 */
	private const EPISODE_RE = '/^(?:ep|episode)s?\.?\s*(\d{1,3})\s*(?:[–—\-:.)]\s*)?(.*)$/iu';

	/**
	 * Parse a post.
	 *
	 * @param array $post Post payload, shaped like a WP REST API post.
	 * @return array Structured series.
	 */
	public static function parse( array $post ): array {
		$html = (string) ( $post['content']['rendered'] ?? $post['content'] ?? '' );
		$blocks = Html::blocks( $html );

		list( $info, $consumed ) = self::parse_info( $blocks );
		$episodes = self::parse_episodes( $blocks, $consumed );

		$title = Html::text( (string) ( $post['title']['rendered'] ?? $post['title'] ?? '' ) );

		$type = 'series';
		if ( ! $episodes || ( ! isset( $info['season'] ) && ! isset( $info['episode_count'] ) ) ) {
			if ( false !== stripos( $title, 'movie' ) || isset( $info['runtime'] ) && ! isset( $info['episode_count'] ) ) {
				$type = 'movie';
			}
		}

		$links = array();
		foreach ( $episodes as $episode ) {
			foreach ( $episode['variants'] as $variant ) {
				foreach ( $variant['links'] as $link ) {
					$links[] = $link;
				}
			}
		}

		$hosts = array();
		foreach ( $links as $link ) {
			$hosts[ $link['host'] ] = ( $hosts[ $link['host'] ] ?? 0 ) + 1;
		}
		arsort( $hosts );

		return array(
			'id'            => isset( $post['id'] ) ? (int) $post['id'] : 0,
			'slug'          => (string) ( $post['slug'] ?? '' ),
			'title'         => $title,
			'url'           => (string) ( $post['link'] ?? '' ),
			'type'          => $type,
			'published_at'  => (string) ( $post['date'] ?? '' ),
			'updated_at'    => (string) ( $post['modified_gmt'] ?? $post['modified'] ?? '' ),
			'categories'    => array_values( (array) ( $post['categories_names'] ?? array() ) ),
			'description'   => self::description( $blocks ),
			'info'          => $info,
			'episodes'      => $episodes,
			'counts'        => array(
				'episodes' => count( $episodes ),
				'links'    => count( $links ),
				'hosts'    => $hosts,
			),
		);
	}

	/**
	 * Pull the labelled info fields out of the post.
	 *
	 * @return array{0:array<string,mixed>,1:array<int,bool>} Info plus consumed block map.
	 */
	private static function parse_info( array $blocks ): array {
		$info      = array();
		$consumed  = array();

		foreach ( $blocks as $index => $block ) {
			$text = $block['text'];
			if ( '' === $text ) {
				continue;
			}

			foreach ( self::INFO_FIELDS as $field => $pattern ) {
				if ( ! preg_match( $pattern, $text, $match ) ) {
					continue;
				}
				$consumed[ $index ] = true;

				$value = trim( $match[1] );
				$value = trim( $value, " \t\n\r\0\x0B:," );

				switch ( $field ) {
					case 'season':
						$info['season'] = self::clean_season( $value );
						break;

					case 'episode_count':
						$info['episode_count'] = (int) $value;
						break;

					case 'release_year':
						$info['release_year'] = $value;
						break;

					case 'runtime':
						$info['runtime'] = $value;
						break;

					case 'genre':
						$info['genres'] = self::split_list( $value );
						break;

					case 'language':
						$info['languages'] = self::split_list( $value );
						break;

					case 'quality':
						$info['qualities'] = self::split_list( trim( $value, '()' ) );
						break;

					case 'synopsis':
						$info['synopsis'] = $value;
						break;

					default:
						$info['full_name'] = $value;
						break;
				}
				break;
			}
		}

		return array( $info, $consumed );
	}

	/**
	 * Build the episode list.
	 *
	 * @param array $blocks   All blocks.
	 * @param array $consumed Blocks already used by the info parser.
	 */
	private static function parse_episodes( array $blocks, array $consumed ): array {
		$episodes    = array();
		$current     = null;
		$pending     = null;
		$section     = null;
		$new_section = false;

		foreach ( $blocks as $index => $block ) {
			if ( isset( $consumed[ $index ] ) ) {
				continue;
			}

			$prefix     = trim( $block['prefix'] );
			$links      = $block['links'];
			$is_heading = ( 0 === strpos( $block['tag'], 'h' ) );

			// An episode header. Some posts put the header and its links in the
			// same paragraph, so the header is checked before the links.
			if ( ! self::is_info_line( $prefix ) && preg_match( self::EPISODE_RE, $prefix, $match ) ) {
				$number = (int) $match[1];
				list( $title, $header_label ) = self::split_title_label( (string) $match[2] );

				// A few posts repeat the episode number for the multi audio
				// pack, so an existing entry is extended instead of duplicated.
				$existing = null;
				foreach ( $episodes as $episode_index => $episode ) {
					if ( $episode['number'] === $number ) {
						$existing = $episode_index;
						break;
					}
				}

				if ( null === $existing ) {
					$episodes[] = array(
						'number'   => $number,
						'title'    => '' !== $title ? $title : null,
						'variants' => array(),
					);
					$existing = count( $episodes ) - 1;
				} elseif ( null === $episodes[ $existing ]['title'] && '' !== $title ) {
					$episodes[ $existing ]['title'] = $title;
				}

				$current     = $existing;
				$pending     = null;
				$section     = null;
				$new_section = false;

				if ( $links ) {
					// The rest of the header ("Multi Audio", for example) names
					// the variant.
					$variant_label = $header_label;
					if ( null === $variant_label && '' !== $title ) {
						$variant_label = $title;
					}
					self::add_links( $episodes, $current, self::external_links( $links ), $variant_label );
				}
				continue;
			}

			// A text only block.
			if ( ! $links ) {
				if ( self::is_label( $prefix ) ) {
					$pending = self::normalize_label( $prefix );
					continue;
				}
				if ( $is_heading && '' !== $prefix ) {
					$section     = $prefix;
					$new_section = true;
					$pending     = null;
				}
				continue;
			}

			// Links that point back at the site are navigation, not downloads.
			$external = self::external_links( $links );
			if ( ! $external ) {
				continue;
			}

			// A heading opened a new section, so these links belong to a new
			// group rather than to the previous episode (batch downloads, and
			// movie posts that have no episode list at all).
			if ( $new_section && null !== $current ) {
				$episodes[] = array(
					'number'   => null,
					'title'    => $section,
					'variants' => array(),
				);
				$current     = count( $episodes ) - 1;
				$new_section = false;
			}

			if ( null === $current ) {
				$episodes[] = array(
					'number'   => null,
					'title'    => $section,
					'variants' => array(),
				);
				$current     = count( $episodes ) - 1;
				$new_section = false;
			}

			$label = self::label_from_prefix( $prefix );
			if ( null === $label ) {
				$label = $pending;
			}
			if ( null === $label ) {
				$label = self::label_from_anchor( $external );
			}
			if ( null === $label ) {
				$label = 'Main';
			} elseif ( 'Main' !== $label ) {
				// The label applies to every link block that follows it, until
				// the next label or episode header.
				$pending = $label;
			}

			self::add_links( $episodes, $current, $external, $label );
		}

		return array_values( $episodes );
	}

	/**
	 * Add links to an episode, reusing the variant with the same label.
	 *
	 * @param array      $episodes Episode list, by reference.
	 * @param int        $index    Episode index.
	 * @param array      $links    Links to add.
	 * @param string|null $label   Variant label.
	 */
	private static function add_links( array &$episodes, int $index, array $links, ?string $label ): void {
		if ( ! $links ) {
			return;
		}

		$label = null === $label ? 'Main' : $label;
		$slot  = self::variant_index( $episodes[ $index ], $label );

		if ( null === $slot ) {
			$episodes[ $index ]['variants'][] = array(
				'label' => $label,
				'links' => array(),
			);
			$slot = count( $episodes[ $index ]['variants'] ) - 1;
		}

		foreach ( $links as $link ) {
			$episodes[ $index ]['variants'][ $slot ]['links'][] = self::normalise_link( $link );
		}
	}

	/**
	 * Keep only the links that leave the site.
	 */
	private static function external_links( array $links ): array {
		$out = array();

		foreach ( $links as $link ) {
			if ( ! self::is_internal( $link['url'] ) ) {
				$out[] = $link;
			}
		}

		return $out;
	}

	/**
	 * Does this URL point back at rareanimes.foo?
	 */
	private static function is_internal( string $url ): bool {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

		return false !== strpos( $host, 'rareanimes' ) || false !== strpos( $host, 'raretoonsindia' );
	}

	/**
	 * Split the remainder of an episode header into a title and a variant label.
	 *
	 * Templates sometimes glue the language onto the title ("Lost
	 * TechnologyHindi FanDub"), and sometimes separate them with a dash.
	 *
	 * @return array{0:string,1:?string}
	 */
	private static function split_title_label( string $rest ): array {
		$rest = rtrim( trim( $rest ), " \t–—-:" );

		// No leading word boundary: the templates often glue the label onto
		// the title ("The Seven KnightsHindi").
		$pattern = '/(Hindi|English|Tamil|Telugu|Marathi|Bengali|Kannada|Malayalam|Punjabi|Gujarati|Odia|Assamese|Nepali|Japanese|Korean|Chinese)\b(\s+(?:Fan\s?Dub|Dub|Dubbed|Sub|Subbed|Uncut|NF|AMZN|BluRay|CR))*$/iu';

		if ( preg_match( $pattern, $rest, $match, PREG_OFFSET_CAPTURE ) ) {
			$label = trim( $match[0][0] );
			$title = rtrim( substr( $rest, 0, (int) $match[0][1] ), " \t–—-:" );

			if ( '' !== $label ) {
				return array( $title, $label );
			}
		}

		return array( $rest, null );
	}

	/**
	 * Info lines such as "Episodes: 13" are not episode headers.
	 */
	private static function is_info_line( string $text ): bool {
		return (bool) preg_match( '/^\W*episodes?\s*[:=]/iu', $text );
	}

	/**
	 * Use the anchor text as a variant label when it says something useful.
	 *
	 * The site uses fixed anchor texts for the known hosts ("HubCloud",
	 * "WatchNow", "Mega"), so those do not make a good variant name.
	 */
	private static function label_from_anchor( array $links ): ?string {
		if ( ! isset( $links[0]['label'] ) ) {
			return null;
		}

		$label = trim( $links[0]['label'] );
		if ( '' === $label ) {
			return null;
		}

		$markers = array(
			'watchmultiquality',
			'watchmultiquality',
			'hubcloud',
			'watchnow',
			'dlbeta',
			'mega',
			'zip',
			'zip – pixel',
			'zip – cloud',
			'raretoons',
			'rareanimesindia',
			'click here',
			'watch/download',
			'download',
		);

		if ( in_array( mb_strtolower( $label, 'UTF-8' ), $markers, true ) ) {
			return null;
		}

		return $label;
	}

	/**
	 * Find an existing variant with the same label.
	 *
	 * @return int|null Index, or null when the label is new.
	 */
	private static function variant_index( array $episode, string $label ): ?int {
		foreach ( $episode['variants'] as $index => $variant ) {
			if ( 0 === strcasecmp( $variant['label'], $label ) ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Normalise a single link into the API shape.
	 */
	private static function normalise_link( array $link ): array {
		$url     = $link['url'];
		$host    = Hosts::classify( $url );
		$is_own  = self::is_internal( $url );

		return array(
			'label'      => $link['label'],
			'url'        => $url,
			'host'       => $host['id'],
			'host_label' => $host['label'],
			'file_id'    => $host['file_id'],
			'resolvable' => $host['resolve'] && ! $is_own,
			'internal'   => $is_own,
		);
	}

	/**
	 * Extract a variant label from the text in front of the links.
	 */
	private static function label_from_prefix( string $prefix ): ?string {
		$prefix = trim( $prefix );
		if ( '' === $prefix ) {
			return null;
		}

		if ( preg_match( '/^(.*?)\s*[–—-]\s*(?:\[|\()?.*$/u', $prefix, $match ) ) {
			$label = trim( $match[1] );
		} elseif ( preg_match( '/^(.*?)\s*[–—-]\s*$/u', $prefix, $match ) ) {
			$label = trim( $match[1] );
		} else {
			return null;
		}

		$label = self::normalize_label( $label );

		return '' === $label ? null : $label;
	}

	/**
	 * Does this text look like a variant label such as "Hindi – Download"?
	 */
	private static function is_label( string $text ): bool {
		$text = trim( $text );
		if ( '' === $text || mb_strlen( $text, 'UTF-8' ) > 60 ) {
			return false;
		}

		if ( preg_match( '/^.*?\s*[–—-]\s*(?:Download|Watch|Links?|Download\s+Links?)\s*$/iu', $text ) ) {
			return true;
		}

		return (bool) preg_match(
			'/^(?:Hindi|English|Tamil|Telugu|Marathi|Bengali|Kannada|Malayalam|Punjabi|Gujarati|Odia|Assamese|Nepali|Japanese|Korean|Chinese)\b(?:\s+(?:Fan\s?Dub|Dub|Dubbed|Sub|Subbed|ORG|Dual\s+Audio|FanSub))*$/iu',
			$text
		);
	}

	/**
	 * Strip decoration such as the trailing "– Download".
	 */
	private static function normalize_label( string $label ): string {
		$label = trim( $label );
		$label = preg_replace( '/\s*[–—-]\s*(?:Download|Watch|Links?|Download\s+Links?)\s*$/iu', '', $label );
		$label = preg_replace( '/\s*[\[\(]\s*[\]\)]\s*$/u', '', $label );

		return trim( (string) $label );
	}

	/**
	 * Season values are sometimes "02", sometimes "Season 2", sometimes "2".
	 */
	private static function clean_season( string $value ): string {
		$value = trim( $value );
		if ( preg_match( '/\d+/', $value, $match ) ) {
			return $match[0];
		}

		return $value;
	}

	/**
	 * Split a comma separated list, dropping filler words such as "and".
	 */
	private static function split_list( string $value ): array {
		$parts = preg_split( '/\s*,\s*/u', trim( $value ) );
		$out   = array();

		foreach ( (array) $parts as $part ) {
			$part = trim( $part, " \t\n\r\0\x0B()" );
			if ( '' === $part ) {
				continue;
			}
			if ( preg_match( '/^and\s+/iu', $part ) ) {
				$part = trim( preg_replace( '/^and\s+/iu', '', $part ) );
			}
			if ( '' === $part ) {
				continue;
			}
			$out[] = $part;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * The opening paragraph doubles as a short description.
	 */
	private static function description( array $blocks ): string {
		foreach ( $blocks as $block ) {
			if ( '' === $block['text'] || $block['links'] ) {
				continue;
			}
			if ( preg_match( '/^(?:✅|📌|🔥)/u', $block['text'] ) ) {
				return trim( $block['text'] );
			}
		}

		return '';
	}
}
