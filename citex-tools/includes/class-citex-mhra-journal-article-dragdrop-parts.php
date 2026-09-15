<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MHRA Journal Article DragDrop's dynamic question builder — mirrors
 * Citex_MHRA_Book_Dragdrop_Parts's own "select 3 of a content pool, only
 * the FIRST author ever inverted, others in natural word order" author
 * segment, applied to MHRA's own Journal Article rule (see
 * Citex_MHRA_Reference_Rules's own docblock): a single-quoted article
 * title (matching Harvard's own quoting convention), volume and issue
 * combined into one "Volume.Issue" token, and the year sitting in its OWN
 * parenthesis straight after that token — genuinely different from every
 * other style's own year placement in this codebase.
 *
 * Eligible content candidates: author_name (costs 2: surname + given
 * name), article_title, journal_title, volume, issue, year, pages — no
 * `place`/`publisher` slot at all, since a journal article has no
 * publisher/place element.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_MHRA_Journal_Article_Dragdrop_Parts {

	/**
	 * @return string[]
	 *
	 * 'author_name' costs 2 concrete parts (surname + given name
	 * together); every other slot here costs 1.
	 */
	private static function content_slots() {
		return array( 'author_name', 'article_title', 'journal_title', 'volume', 'issue', 'year', 'pages' );
	}

	private static function structural_slots( $author_count ) {
		return $author_count >= 2 ? array( 'and' ) : array();
	}

	/**
	 * Builds the full ordered token list for one MHRA journal article
	 * record. The author-list segment is identical in shape to
	 * Citex_MHRA_Book_Dragdrop_Parts::build_tokens()'s own author loop; the
	 * page range renders via Citex_MHRA_Reference_Rules::format_page_range()
	 * — the same en-dash conversion every other category's page range
	 * already uses.
	 *
	 * @param array $authors array<{surname, givenName}>, 1 or more.
	 * @param array $fields  {articleTitle, journalTitle, volume, issue, year, pages}.
	 * @param int   $drawn_author_index
	 * @return array<{key: string|null, kind: string, value: string, literal: bool}>
	 */
	public static function build_tokens( array $authors, array $fields, $drawn_author_index = 0 ) {
		$tokens = array();
		$count  = count( $authors );
		for ( $i = 0; $i < $count; $i++ ) {
			$is_first = ( 0 === $i );
			if ( $i === $drawn_author_index ) {
				if ( $is_first ) {
					$tokens[] = array( 'key' => 'author_' . $i . '_surname', 'kind' => 'author_surname', 'value' => (string) $authors[ $i ]['surname'], 'literal' => false );
					$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
					$tokens[] = array( 'key' => 'author_' . $i . '_givenname', 'kind' => 'author_givenname', 'value' => (string) $authors[ $i ]['givenName'], 'literal' => false );
				} else {
					$tokens[] = array( 'key' => 'author_' . $i . '_givenname', 'kind' => 'author_givenname', 'value' => (string) $authors[ $i ]['givenName'], 'literal' => false );
					$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ', 'literal' => true );
					$tokens[] = array( 'key' => 'author_' . $i . '_surname', 'kind' => 'author_surname', 'value' => (string) $authors[ $i ]['surname'], 'literal' => false );
				}
			} else {
				$tokens[] = array(
					'key'     => null,
					'kind'    => 'literal',
					'value'   => $is_first
						? sprintf( '%s, %s', $authors[ $i ]['surname'], $authors[ $i ]['givenName'] )
						: sprintf( '%s %s', $authors[ $i ]['givenName'], $authors[ $i ]['surname'] ),
					'literal' => true,
				);
			}
			if ( $i < $count - 1 ) {
				$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
				if ( $i === $count - 2 ) {
					$tokens[] = array( 'key' => 'and', 'kind' => 'and', 'value' => 'and', 'literal' => false );
					$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ', 'literal' => true );
				}
			}
		}
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ", '", 'literal' => true );
		$tokens[] = array( 'key' => 'article_title', 'kind' => 'article_title', 'value' => (string) $fields['articleTitle'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => "', ", 'literal' => true );
		$tokens[] = array( 'key' => 'journal_title', 'kind' => 'journal_title', 'value' => (string) $fields['journalTitle'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
		$tokens[] = array( 'key' => 'volume', 'kind' => 'volume', 'value' => (string) $fields['volume'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '.', 'literal' => true );
		$tokens[] = array( 'key' => 'issue', 'kind' => 'issue', 'value' => (string) $fields['issue'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' (', 'literal' => true );
		$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => (string) $fields['year'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '), ', 'literal' => true );
		$tokens[] = array( 'key' => 'pages', 'kind' => 'pages', 'value' => Citex_MHRA_Reference_Rules::format_page_range( $fields['pages'] ), 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '.', 'literal' => true );
		return $tokens;
	}

	/**
	 * Deterministically, but effectively unpredictably, picks which
	 * candidate keys to draw for one question, seeded by that question's
	 * own id — same technique as
	 * Citex_MHRA_Book_Dragdrop_Parts::select_parts().
	 *
	 * @param string|int $seed    Typically the question's own id (e.g. "HJ04").
	 * @param array      $authors array<{surname, givenName}>, 1 or more.
	 * @return string[] ordered candidate keys.
	 */
	public static function select_parts( $seed, array $authors ) {
		$seed         = (string) $seed;
		$author_count = count( $authors );
		$drawn_index  = abs( crc32( 'mhra_journal_article_dragdrop_author|' . $seed ) ) % max( 1, $author_count );
		$target_count = 3;

		$content_slots = self::content_slots();
		$seed_slot     = $content_slots[ abs( crc32( 'mhra_journal_article_dragdrop_seed|' . $seed ) ) % count( $content_slots ) ];
		$seed_cost     = ( 'author_name' === $seed_slot ) ? 2 : 1;
		$remaining     = max( 0, $target_count - $seed_cost );

		$pool = array_values( array_diff( $content_slots, array( 'author_name', $seed_slot ) ) );
		$pool = array_merge( $pool, self::structural_slots( $author_count ) );
		usort(
			$pool,
			function ( $a, $b ) use ( $seed ) {
				$hash_a = crc32( 'mhra_journal_article_dragdrop_shuffle|' . $seed . '|' . $a );
				$hash_b = crc32( 'mhra_journal_article_dragdrop_shuffle|' . $seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);
		$fill = array_slice( $pool, 0, $remaining );

		$selected_abstract = array_merge( array( $seed_slot ), $fill );
		$selected_concrete = array();
		foreach ( $selected_abstract as $slot ) {
			if ( 'author_name' === $slot ) {
				$selected_concrete[] = 'author_' . $drawn_index . '_surname';
				$selected_concrete[] = 'author_' . $drawn_index . '_givenname';
			} else {
				$selected_concrete[] = $slot;
			}
		}
		$selected_set = array_fill_keys( $selected_concrete, true );

		$ordered = array();
		foreach ( self::build_tokens( $authors, array( 'articleTitle' => '', 'journalTitle' => '', 'volume' => '', 'issue' => '', 'year' => '', 'pages' => '0-0' ), $drawn_index ) as $token ) {
			if ( ! $token['literal'] && isset( $selected_set[ $token['key'] ] ) ) {
				$ordered[] = $token['key'];
			}
		}
		return $ordered;
	}

	/**
	 * Builds {parts, fixedText, confusingWords} for one question from a
	 * selection of candidate keys and the record's own canonical fields.
	 *
	 * @param string[] $selected_keys
	 * @param array    $authors array<{surname, givenName}>, 1 or more.
	 * @param array    $fields  {articleTitle, journalTitle, volume, issue, year, pages}.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
	 */
	public static function build( array $selected_keys, array $authors, array $fields ) {
		$drawn_index = 0;
		foreach ( $selected_keys as $key ) {
			if ( 1 === preg_match( '/^author_(\d+)_(?:surname|givenname)$/', (string) $key, $matches ) ) {
				$drawn_index = (int) $matches[1];
				break;
			}
		}
		if ( $drawn_index >= count( $authors ) ) {
			return null;
		}

		$selected_set = array_fill_keys( array_map( 'strval', $selected_keys ), true );
		$tokens       = self::build_tokens( $authors, $fields, $drawn_index );
		$token_count  = count( $tokens );
		$record_seed  = implode( '|', array( (string) $fields['articleTitle'], (string) $fields['journalTitle'], (string) $fields['volume'], (string) $fields['issue'], (string) $fields['year'], (string) count( $authors ) ) );

		$parts     = array();
		$confusing = array();
		$fixed     = '';
		foreach ( $tokens as $index => $token ) {
			if ( $token['literal'] ) {
				$fixed .= $token['value'];
				continue;
			}
			if ( isset( $selected_set[ $token['key'] ] ) ) {
				$is_first    = '' === trim( $fixed );
				$is_last     = ( $index === $token_count - 1 );
				$fixed      .= ( $is_first || $is_last ) ? '|' : '||';
				$parts[]     = $token['value'];
				$confusing[] = self::distractor_for( $token['kind'], $token['value'], $authors, $drawn_index, $fields, $record_seed );
			} else {
				$fixed .= $token['value'];
			}
		}
		if ( empty( $parts ) ) {
			return null;
		}
		return array( 'parts' => $parts, 'fixedText' => $fixed, 'confusingWords' => $confusing );
	}

	private static function distractor_for( $kind, $value, array $authors, $drawn_index, array $fields, $record_seed ) {
		switch ( $kind ) {
			case 'author_surname':
				return self::author_surname_distractor( $value, $authors, $drawn_index, $record_seed );
			case 'author_givenname':
				return self::author_givenname_distractor( $value, $authors, $drawn_index, $record_seed );
			case 'and':
				return '&';
			case 'year':
				return Citex_Reference_Rules::year_distractor( $value, $record_seed );
			case 'article_title':
				return Citex_Reference_Rules::title_like_distractor( $value, $fields['year'], $record_seed . '|article_title' );
			case 'journal_title':
				return Citex_Reference_Rules::title_like_distractor( $value, $fields['year'], $record_seed . '|journal_title' );
			case 'volume':
				return Citex_Reference_Rules::year_distractor( $value, $record_seed . '|volume' );
			case 'issue':
				return Citex_Reference_Rules::year_distractor( $value, $record_seed . '|issue' );
			case 'pages':
				return self::pages_distractor( $value, $record_seed );
			default:
				return $value . '?';
		}
	}

	private static function author_surname_distractor( $value, array $authors, $drawn_index, $record_seed ) {
		$other = self::other_author( $authors, $drawn_index );
		if ( null !== $other && 0 === ( abs( crc32( 'mhra_journal_article_dragdrop_surname_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $other['surname'], $value ) ) {
			return (string) $other['surname'];
		}
		return $value . "'s";
	}

	private static function author_givenname_distractor( $value, array $authors, $drawn_index, $record_seed ) {
		$other = self::other_author( $authors, $drawn_index );
		if ( null !== $other && 0 === ( abs( crc32( 'mhra_journal_article_dragdrop_givenname_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $other['givenName'], $value ) ) {
			return (string) $other['givenName'];
		}
		$letter  = mb_substr( trim( (string) $value ), 0, 1 );
		$initial = '' !== $letter ? mb_strtoupper( $letter ) . '.' : $value;
		return $initial !== $value ? $initial : $value . "'s";
	}

	/**
	 * The first author in $authors that is NOT the drawn author, or null
	 * for a single-author record.
	 */
	private static function other_author( array $authors, $drawn_index ) {
		foreach ( $authors as $i => $author ) {
			if ( $i !== $drawn_index ) {
				return $author;
			}
		}
		return null;
	}

	/**
	 * The page-range distractor — a deterministic off-by-a-few mutation of
	 * the end page, keeping the start page and the en dash intact.
	 */
	private static function pages_distractor( $value, $record_seed ) {
		if ( ! preg_match( '/^(\d+)–(\d+)$/u', (string) $value, $m ) ) {
			return $value . '?';
		}
		$delta = 1 + ( abs( crc32( 'mhra_journal_article_dragdrop_pages|' . $record_seed ) ) % 9 );
		$end   = max( (int) $m[1] + 1, (int) $m[2] + $delta );
		return $m[1] . '–' . $end;
	}
}
