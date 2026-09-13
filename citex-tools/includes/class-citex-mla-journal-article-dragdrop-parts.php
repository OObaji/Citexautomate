<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MLA Journal Article DragDrop's dynamic question builder — mirrors
 * Citex_MLA_Book_Dragdrop_Parts's own "select 3 of a content pool, always
 * split the first author into surname/given-name" model exactly, applied
 * to MLA's Journal Article rule (see Citex_MLA_Reference_Rules's own
 * docblock): the article title in double quotation marks with the period
 * INSIDE them, "vol."/"no." labels, and the page range after "pp." —
 * genuinely different content from Book/Edited Book, so its own content
 * slot catalogue (articleTitle/journalTitle/volume/issue/pages, no
 * publisher) is used instead.
 *
 * The author-segment tokens (surname/given-name split, "and"/"et al."
 * joiner) are IDENTICAL in shape to Citex_MLA_Book_Dragdrop_Parts's own —
 * MLA's author-joining rule does not vary by category — only the trailing
 * literal after the segment differs (Book: nothing further to say before
 * the title; here: straight into the quoted article title).
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_MLA_Journal_Article_Dragdrop_Parts {

	/**
	 * @return string[]
	 *
	 * 'author_name' costs 2 concrete parts (surname + given name
	 * together); every other slot here costs 1. There is no `publisher`
	 * slot at all — Journal Article has no publisher/place element,
	 * mirroring Harvard's own Journal Article shape.
	 */
	private static function content_slots() {
		// NOTE: keys/kinds here are lowercase snake_case, never camelCase —
		// dragdropPartKeys is persisted (and read back) through
		// sanitize_key(), which lowercases everything; a camelCase key
		// like "articleTitle" would silently become "articletitle" on
		// round-trip and stop matching build_tokens()'s own token keys,
		// dropping that candidate from reconstruction (the exact
		// regression this comment exists to prevent — caught via an
		// end-to-end generate+validate smoke test).
		return array( 'author_name', 'article_title', 'journal_title', 'volume', 'issue', 'year', 'pages' );
	}

	private static function structural_slots( $author_count ) {
		return $author_count >= 2 ? array( 'joiner' ) : array();
	}

	/**
	 * Builds the full ordered token list for one MLA journal article
	 * record. The author-segment tokens are identical in shape to
	 * Citex_MLA_Book_Dragdrop_Parts::build_tokens()'s own leading segment;
	 * the page range renders via Citex_Reference_Rules::format_page_range()
	 * — the SAME en-dash conversion every other category's page range
	 * already uses, reused directly rather than reimplemented.
	 *
	 * @param array $authors array<{surname, givenName, fullName}>, 1 or more.
	 * @param array $fields  {articleTitle, journalTitle, volume, issue, year, pages}.
	 * @return array<{key: string|null, kind: string, value: string, literal: bool}>
	 */
	public static function build_tokens( array $authors, array $fields ) {
		$count = count( $authors );
		$first = $authors[0];

		$tokens   = array();
		$tokens[] = array( 'key' => 'author_surname', 'kind' => 'author_surname', 'value' => (string) $first['surname'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
		$tokens[] = array( 'key' => 'author_given', 'kind' => 'author_given', 'value' => (string) $first['givenName'], 'literal' => false );

		if ( 1 === $count ) {
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '. "', 'literal' => true );
		} elseif ( 2 === $count ) {
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
			$tokens[] = array( 'key' => 'joiner', 'kind' => 'joiner', 'value' => 'and', 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ' . (string) $authors[1]['fullName'] . '. "', 'literal' => true );
		} else {
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
			$tokens[] = array( 'key' => 'joiner', 'kind' => 'joiner', 'value' => 'et al.', 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' "', 'literal' => true );
		}

		$tokens[] = array( 'key' => 'article_title', 'kind' => 'article_title', 'value' => (string) $fields['articleTitle'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '." ', 'literal' => true );
		$tokens[] = array( 'key' => 'journal_title', 'kind' => 'journal_title', 'value' => (string) $fields['journalTitle'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', vol. ', 'literal' => true );
		$tokens[] = array( 'key' => 'volume', 'kind' => 'volume', 'value' => (string) $fields['volume'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', no. ', 'literal' => true );
		$tokens[] = array( 'key' => 'issue', 'kind' => 'issue', 'value' => (string) $fields['issue'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
		$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => (string) $fields['year'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', pp. ', 'literal' => true );
		$tokens[] = array( 'key' => 'pages', 'kind' => 'pages', 'value' => Citex_Reference_Rules::format_page_range( $fields['pages'] ), 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '.', 'literal' => true );
		return $tokens;
	}

	/**
	 * Deterministically, but effectively unpredictably, picks which
	 * candidate keys to draw for one question, seeded by that question's
	 * own id — identical technique to
	 * Citex_MLA_Book_Dragdrop_Parts::select_parts(): one "seed" content
	 * slot guarantees the content floor, the remaining budget (target
	 * always exactly 3) is filled from the other eligible cost-1 slots
	 * (including 'joiner' when eligible), deterministically shuffled.
	 *
	 * @param string|int $seed    Typically the question's own id (e.g. "MJ04").
	 * @param array      $authors array<{surname, givenName, fullName}>, 1 or more.
	 * @return string[] ordered candidate keys.
	 */
	public static function select_parts( $seed, array $authors ) {
		$seed         = (string) $seed;
		$author_count = count( $authors );
		$target_count = 3;

		$content_slots = self::content_slots();
		$seed_slot     = $content_slots[ abs( crc32( 'mla_journal_article_dragdrop_seed|' . $seed ) ) % count( $content_slots ) ];
		$seed_cost     = ( 'author_name' === $seed_slot ) ? 2 : 1;
		$remaining     = max( 0, $target_count - $seed_cost );

		$pool = array_values( array_diff( $content_slots, array( 'author_name', $seed_slot ) ) );
		$pool = array_merge( $pool, self::structural_slots( $author_count ) );
		usort(
			$pool,
			function ( $a, $b ) use ( $seed ) {
				$hash_a = crc32( 'mla_journal_article_dragdrop_shuffle|' . $seed . '|' . $a );
				$hash_b = crc32( 'mla_journal_article_dragdrop_shuffle|' . $seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);
		$fill = array_slice( $pool, 0, $remaining );

		$selected_abstract = array_merge( array( $seed_slot ), $fill );
		$selected_concrete = array();
		foreach ( $selected_abstract as $slot ) {
			if ( 'author_name' === $slot ) {
				$selected_concrete[] = 'author_surname';
				$selected_concrete[] = 'author_given';
			} else {
				$selected_concrete[] = $slot;
			}
		}
		$selected_set = array_fill_keys( $selected_concrete, true );

		$ordered = array();
		foreach ( self::build_tokens( $authors, array( 'articleTitle' => '', 'journalTitle' => '', 'volume' => '', 'issue' => '', 'year' => '', 'pages' => '0-0' ) ) as $token ) {
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
	 * @param array    $authors array<{surname, givenName, fullName}>, 1 or more.
	 * @param array    $fields  {articleTitle, journalTitle, volume, issue, year, pages}.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
	 */
	public static function build( array $selected_keys, array $authors, array $fields ) {
		$selected_set = array_fill_keys( array_map( 'strval', $selected_keys ), true );
		$tokens       = self::build_tokens( $authors, $fields );
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
				$confusing[] = self::distractor_for( $token['kind'], $token['value'], $authors, $fields, $record_seed );
			} else {
				$fixed .= $token['value'];
			}
		}
		if ( empty( $parts ) ) {
			return null;
		}
		return array( 'parts' => $parts, 'fixedText' => $fixed, 'confusingWords' => $confusing );
	}

	private static function distractor_for( $kind, $value, array $authors, array $fields, $record_seed ) {
		switch ( $kind ) {
			case 'author_surname':
				return self::author_surname_distractor( $value, $authors, $record_seed );
			case 'author_given':
				return self::author_given_distractor( $value, $authors, $record_seed );
			case 'joiner':
				return self::joiner_distractor( $value, $record_seed );
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

	private static function author_surname_distractor( $value, array $authors, $record_seed ) {
		if ( 2 === count( $authors ) && 0 === ( abs( crc32( 'mla_journal_article_dragdrop_surname_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $authors[1]['surname'], $value ) ) {
			return (string) $authors[1]['surname'];
		}
		return $value . "'s";
	}

	private static function author_given_distractor( $value, array $authors, $record_seed ) {
		if ( 2 === count( $authors ) && 0 === ( abs( crc32( 'mla_journal_article_dragdrop_given_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $authors[1]['givenName'], $value ) ) {
			return (string) $authors[1]['givenName'];
		}
		$initial = '' !== $value ? mb_substr( $value, 0, 1 ) . '.' : $value;
		return $initial !== $value ? $initial : $value . "'s";
	}

	private static function joiner_distractor( $value, $record_seed ) {
		if ( 'and' === $value ) {
			return '&';
		}
		$variants = array( 'et al', 'and others' );
		$pick     = $variants[ abs( crc32( 'mla_journal_article_dragdrop_joiner|' . $record_seed ) ) % count( $variants ) ];
		return $pick !== $value ? $pick : 'and others';
	}

	/**
	 * The page-range distractor — a deterministic off-by-a-few mutation of
	 * the end page, keeping the start page and the en dash intact, so the
	 * mistake reads as a plausible (wrong) page range rather than garbled
	 * text.
	 */
	private static function pages_distractor( $value, $record_seed ) {
		if ( ! preg_match( '/^(\d+)–(\d+)$/u', (string) $value, $m ) ) {
			return $value . '?';
		}
		$delta = 1 + ( abs( crc32( 'mla_journal_article_dragdrop_pages|' . $record_seed ) ) % 9 );
		$end   = max( (int) $m[1] + 1, (int) $m[2] + $delta );
		return $m[1] . '–' . $end;
	}
}
