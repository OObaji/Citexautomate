<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chicago Book DragDrop's dynamic 3-part question builder — mirrors
 * Citex_Book_Dragdrop_Parts's own "always the full reference, N random
 * blanks" model exactly, but for Chicago Author-Date's own rule (see
 * Citex_Chicago_Reference_Rules's own docblock): the FULL given name (not
 * an initial, like MLA — unlike Harvard/APA's own initials), place of
 * publication IS kept (like Harvard, unlike MLA/APA), "and" preceded by a
 * comma even at exactly two authors (unlike Harvard's plain "and" with no
 * comma there — matching APA's own comma-before-"&" rule instead), and no
 * parentheses around the year at all (unlike Harvard/APA, both of which
 * parenthesise it) — the year is followed by its own full stop.
 *
 * Eligible content candidates: author_name (costs 2: surname + given name),
 * year, title, place, publisher — the same 5-slot set Harvard Book uses
 * (Chicago is the only non-Harvard style in this codebase that keeps a
 * place element). The structural "and" candidate (correct value "and") is
 * eligible only when there are 2+ authors, exactly mirroring Harvard/APA
 * Book's own structural-joiner eligibility rule.
 *
 * Only one author's name is ever split into parts per question (surname
 * and given name ALWAYS as two separate parts) — every other author stays
 * literal, folded in at its own position exactly as it would appear in the
 * full reference — identical to Harvard/APA Book's own "drawn author
 * index" model.
 *
 * Every distractor is authored deterministically by Citex, reusing
 * Citex_Reference_Rules's own public "SHARED DETERMINISTIC DISTRACTOR
 * PRIMITIVES" (title_like_distractor(), place_pool()/publisher_pool()/
 * pick_from_pool(), year_distractor()) rather than duplicating that logic a
 * further time — only the author/"and" distractors, genuinely
 * Chicago-specific, are written here directly.
 *
 * Pure and static, no WordPress/ACF calls, exactly like
 * Citex_Reference_Rules/Citex_Chicago_Reference_Rules and
 * Citex_Book_Dragdrop_Parts — unit-testable directly.
 */
class Citex_Chicago_Book_Dragdrop_Parts {

	/**
	 * @return string[]
	 *
	 * 'author_name' costs 2 concrete parts (surname + given name together);
	 * every other slot here costs 1.
	 */
	private static function content_slots() {
		return array( 'author_name', 'year', 'title', 'place', 'publisher' );
	}

	/**
	 * The structural "and" slot — only eligible when there are 2 or more
	 * authors (a single author has no joining word at all).
	 */
	private static function structural_slots( $author_count ) {
		return $author_count >= 2 ? array( 'and' ) : array();
	}

	/**
	 * Builds the full ordered token list for one Chicago book record —
	 * identical structure to Citex_Book_Dragdrop_Parts::build_tokens(),
	 * except: each drawn/literal author is rendered "Surname, GivenName"
	 * (the full given name, never an initial), the joiner before the FINAL
	 * author is ALWAYS preceded by a literal comma (Chicago's own rule, even
	 * at exactly two authors — unlike Harvard, which only gets a comma there
	 * once there are 3+ authors), and the year has no surrounding
	 * parentheses at all — just its own trailing full stop.
	 *
	 * @param array $authors array<{surname, givenName}>, 1 or more.
	 * @param array $fields  {year, title, place, publisher}.
	 * @param int   $drawn_author_index
	 * @return array<{key: string|null, kind: string, value: string, literal: bool}>
	 */
	public static function build_tokens( array $authors, array $fields, $drawn_author_index = 0 ) {
		$tokens = array();
		$count  = count( $authors );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( $i === $drawn_author_index ) {
				$tokens[] = array( 'key' => 'author_' . $i . '_surname', 'kind' => 'author_surname', 'value' => (string) $authors[ $i ]['surname'], 'literal' => false );
				$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
				$tokens[] = array( 'key' => 'author_' . $i . '_givenname', 'kind' => 'author_givenname', 'value' => (string) $authors[ $i ]['givenName'], 'literal' => false );
			} else {
				$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => sprintf( '%s, %s', $authors[ $i ]['surname'], $authors[ $i ]['givenName'] ), 'literal' => true );
			}
			if ( $i < $count - 1 ) {
				$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
				if ( $i === $count - 2 ) {
					$tokens[] = array( 'key' => 'and', 'kind' => 'and', 'value' => 'and', 'literal' => false );
					$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ', 'literal' => true );
				}
			}
		}
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '. ', 'literal' => true );
		$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => (string) $fields['year'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '. ', 'literal' => true );
		$tokens[] = array( 'key' => 'title', 'kind' => 'title', 'value' => (string) $fields['title'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '. ', 'literal' => true );
		$tokens[] = array( 'key' => 'place', 'kind' => 'place', 'value' => (string) $fields['place'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ': ', 'literal' => true );
		$tokens[] = array( 'key' => 'publisher', 'kind' => 'publisher', 'value' => (string) $fields['publisher'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '.', 'literal' => true );
		return $tokens;
	}

	/**
	 * Deterministically, but effectively unpredictably, picks exactly which
	 * candidate keys to draw for one question, seeded by that question's own
	 * id — same technique as Citex_Book_Dragdrop_Parts::select_parts()/
	 * Citex_APA_Book_Dragdrop_Parts::select_parts().
	 *
	 * @param string|int $seed    Typically the question's own id (e.g. "CB04").
	 * @param array      $authors array<{surname, givenName}>, 1 or more.
	 * @return string[] ordered candidate keys.
	 */
	public static function select_parts( $seed, array $authors ) {
		$seed         = (string) $seed;
		$author_count = count( $authors );
		$drawn_index  = abs( crc32( 'chicago_book_dragdrop_author|' . $seed ) ) % max( 1, $author_count );
		$target_count = 3;

		$content_slots = self::content_slots();
		$seed_slot     = $content_slots[ abs( crc32( 'chicago_book_dragdrop_seed|' . $seed ) ) % count( $content_slots ) ];
		$seed_cost     = ( 'author_name' === $seed_slot ) ? 2 : 1;
		$remaining     = max( 0, $target_count - $seed_cost );

		$pool = array_values( array_diff( $content_slots, array( 'author_name', $seed_slot ) ) );
		$pool = array_merge( $pool, self::structural_slots( $author_count ) );
		usort(
			$pool,
			function ( $a, $b ) use ( $seed ) {
				$hash_a = crc32( 'chicago_book_dragdrop_shuffle|' . $seed . '|' . $a );
				$hash_b = crc32( 'chicago_book_dragdrop_shuffle|' . $seed . '|' . $b );
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
		foreach ( self::build_tokens( $authors, array( 'year' => '', 'title' => '', 'place' => '', 'publisher' => '' ), $drawn_index ) as $token ) {
			if ( ! $token['literal'] && isset( $selected_set[ $token['key'] ] ) ) {
				$ordered[] = $token['key'];
			}
		}
		return $ordered;
	}

	/**
	 * Builds {parts, fixedText, confusingWords} for one question from a
	 * selection of candidate keys (as returned by select_parts(), or
	 * re-supplied by the validator from a stored `dragdropPartKeys` field)
	 * and the record's own canonical fields. The drawn author index is
	 * recovered directly from $selected_keys, same as
	 * Citex_Book_Dragdrop_Parts::build()/Citex_APA_Book_Dragdrop_Parts::build().
	 *
	 * @param string[] $selected_keys
	 * @param array    $authors array<{surname, givenName}>, 1 or more.
	 * @param array    $fields  {year, title, place, publisher}.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
	 *         null when $selected_keys names an author index out of range
	 *         for $authors, or selects nothing at all.
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
		$record_seed  = implode( '|', array( (string) $fields['year'], (string) $fields['title'], (string) $fields['place'], (string) $fields['publisher'], (string) count( $authors ) ) );

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

	/**
	 * One deterministic wrong chip for a drawn candidate, keyed by its
	 * "kind" — never Gemini-authored, so the validator can recompute and
	 * exact-match these exactly like every other part of the question.
	 */
	private static function distractor_for( $kind, $value, array $authors, $drawn_index, array $fields, $record_seed ) {
		switch ( $kind ) {
			case 'author_surname':
				return self::author_surname_distractor( $value, $authors, $drawn_index, $record_seed );
			case 'author_givenname':
				return self::author_givenname_distractor( $value, $authors, $drawn_index, $record_seed );
			case 'and':
				// Tests "authors are joined with the word 'and', never '&'".
				return '&';
			case 'year':
				return Citex_Reference_Rules::year_distractor( $value, $record_seed . '|year' );
			case 'title':
				return Citex_Reference_Rules::title_like_distractor( $value, $fields['year'], $record_seed . '|title' );
			case 'place':
				$pick = Citex_Reference_Rules::pick_from_pool( Citex_Reference_Rules::place_pool(), array( $value ), $record_seed . '|place' );
				return null !== $pick ? $pick : 'n.p.';
			case 'publisher':
				$pick = Citex_Reference_Rules::pick_from_pool( Citex_Reference_Rules::publisher_pool(), array( $value ), $record_seed . '|publisher' );
				return null !== $pick ? $pick : 'n.pub.';
			default:
				return $value . '?';
		}
	}

	/**
	 * The drawn author's surname distractor — same two-flavour rotation as
	 * Citex_Book_Dragdrop_Parts's/Citex_APA_Book_Dragdrop_Parts's own:
	 * attributing the OTHER author's surname (only eligible with 2+
	 * authors), or a wrongly possessive form as the single-author fallback.
	 */
	private static function author_surname_distractor( $value, array $authors, $drawn_index, $record_seed ) {
		$other = self::other_author( $authors, $drawn_index );
		if ( null !== $other && 0 === ( abs( crc32( 'chicago_book_dragdrop_surname_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $other['surname'], $value ) ) {
			return (string) $other['surname'];
		}
		return $value . "'s";
	}

	/**
	 * The drawn author's given-name distractor — rotates between attributing
	 * the OTHER author's given name (correct format, wrong author — only
	 * eligible with 2+ authors) and truncating it to a single initial (tests
	 * "the FULL given name is required, never an initial" — the single most
	 * Chicago-distinctive rule alongside APA/Harvard's own opposite
	 * convention).
	 */
	private static function author_givenname_distractor( $value, array $authors, $drawn_index, $record_seed ) {
		$other = self::other_author( $authors, $drawn_index );
		if ( null !== $other && 0 === ( abs( crc32( 'chicago_book_dragdrop_givenname_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $other['givenName'], $value ) ) {
			return (string) $other['givenName'];
		}
		$letter = mb_substr( trim( (string) $value ), 0, 1 );
		$initial = '' !== $letter ? mb_strtoupper( $letter ) . '.' : $value;
		return $initial !== $value ? $initial : $value . "'s";
	}

	/**
	 * The first author in $authors that is NOT the drawn author, or null for
	 * a single-author record.
	 */
	private static function other_author( array $authors, $drawn_index ) {
		foreach ( $authors as $i => $author ) {
			if ( $i !== $drawn_index ) {
				return $author;
			}
		}
		return null;
	}
}
