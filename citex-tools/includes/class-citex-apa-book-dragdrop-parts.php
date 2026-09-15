<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * APA Book DragDrop's dynamic 3-part question builder — mirrors
 * Citex_Book_Dragdrop_Parts's own "always the full reference, N random
 * blanks" model exactly, but for APA's own rule (see
 * Citex_APA_Reference_Rules's own docblock): initials (not a full given
 * name, unlike MLA), every author always listed in full (never "et al.",
 * unlike MLA), no place of publication at all (like MLA, unlike Harvard),
 * "&" preceded by a comma even at exactly two authors (unlike Harvard's
 * plain "and" with no comma there), and a full stop immediately after the
 * year's closing parenthesis (unlike Harvard, which has none).
 *
 * Eligible content candidates: author_name (costs 2: surname + initials),
 * year, title, publisher — no `place` candidate exists at all, the same
 * 4-slot set MLA Book uses (for the same reason: neither style has a place
 * element). The structural "ampersand" candidate (correct value "&") is
 * eligible only when there are 2+ authors, exactly mirroring Harvard
 * Book's own "and" eligibility rule.
 *
 * Only one author's name is ever split into parts per question (surname
 * and initials ALWAYS as two separate parts) — every other author stays
 * literal, folded in at its own position exactly as it would appear in
 * the full reference — identical to Harvard Book's own "drawn author
 * index" model (unlike MLA Book, which only ever draws the first author).
 *
 * Every distractor is authored deterministically by Citex, reusing
 * Citex_Reference_Rules's own public "SHARED DETERMINISTIC DISTRACTOR
 * PRIMITIVES" (title_like_distractor(), publisher_pool()/pick_from_pool(),
 * year_distractor()) rather than duplicating that logic a further time —
 * only the author/ampersand distractors, genuinely APA-specific, are
 * written here directly.
 *
 * Pure and static, no WordPress/ACF calls, exactly like
 * Citex_Reference_Rules/Citex_APA_Reference_Rules and
 * Citex_Book_Dragdrop_Parts — unit-testable directly.
 */
class Citex_APA_Book_Dragdrop_Parts {

	/**
	 * @return string[]
	 *
	 * 'author_name' costs 2 concrete parts (surname + initials together);
	 * every other slot here costs 1. There is no 'place' slot at all — APA
	 * has no place-of-publication element.
	 */
	private static function content_slots() {
		return array( 'author_name', 'year', 'title', 'publisher' );
	}

	/**
	 * The structural "ampersand" slot — only eligible when there are 2 or
	 * more authors (a single author has no joining symbol at all).
	 */
	private static function structural_slots( $author_count ) {
		return $author_count >= 2 ? array( 'ampersand' ) : array();
	}

	/**
	 * Builds the full ordered token list for one APA book record —
	 * identical structure to Citex_Book_Dragdrop_Parts::build_tokens(),
	 * except: no place segment at all, the joiner before the final author
	 * is ALWAYS preceded by a literal comma (APA's own Oxford-comma-before-
	 * "&" rule, even at exactly two authors — unlike Harvard, which only
	 * gets a comma there once there are 3+ authors, and never before "and"
	 * itself), and a literal full stop sits immediately after the year's
	 * closing parenthesis.
	 *
	 * @param array $authors array<{surname, initials}>, 1 or more.
	 * @param array $fields  {year, title, publisher}.
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
				$tokens[] = array( 'key' => 'author_' . $i . '_initials', 'kind' => 'author_initials', 'value' => (string) $authors[ $i ]['initials'], 'literal' => false );
			} else {
				$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => sprintf( '%s, %s', $authors[ $i ]['surname'], $authors[ $i ]['initials'] ), 'literal' => true );
			}
			if ( $i < $count - 1 ) {
				if ( $i === $count - 2 ) {
					$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
					$tokens[] = array( 'key' => 'ampersand', 'kind' => 'ampersand', 'value' => '&', 'literal' => false );
					$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ', 'literal' => true );
				} else {
					$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
				}
			}
		}
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' (', 'literal' => true );
		$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => (string) $fields['year'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '). ', 'literal' => true );
		$tokens[] = array( 'key' => 'title', 'kind' => 'title', 'value' => (string) $fields['title'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '. ', 'literal' => true );
		$tokens[] = array( 'key' => 'publisher', 'kind' => 'publisher', 'value' => (string) $fields['publisher'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '.', 'literal' => true );
		return $tokens;
	}

	/**
	 * Deterministically, but effectively unpredictably, picks exactly which
	 * candidate keys to draw for one question, seeded by that question's
	 * own id — same technique as Citex_Book_Dragdrop_Parts::select_parts().
	 *
	 * @param string|int $seed    Typically the question's own id (e.g. "AB04").
	 * @param array      $authors array<{surname, initials}>, 1 or more.
	 * @return string[] ordered candidate keys.
	 */
	public static function select_parts( $seed, array $authors ) {
		$seed         = (string) $seed;
		$author_count = count( $authors );
		$drawn_index  = abs( crc32( 'apa_book_dragdrop_author|' . $seed ) ) % max( 1, $author_count );
		$target_count = 3;

		$content_slots = self::content_slots();
		$seed_slot     = $content_slots[ abs( crc32( 'apa_book_dragdrop_seed|' . $seed ) ) % count( $content_slots ) ];
		$seed_cost     = ( 'author_name' === $seed_slot ) ? 2 : 1;
		$remaining     = max( 0, $target_count - $seed_cost );

		$pool = array_values( array_diff( $content_slots, array( 'author_name', $seed_slot ) ) );
		$pool = array_merge( $pool, self::structural_slots( $author_count ) );
		usort(
			$pool,
			function ( $a, $b ) use ( $seed ) {
				$hash_a = crc32( 'apa_book_dragdrop_shuffle|' . $seed . '|' . $a );
				$hash_b = crc32( 'apa_book_dragdrop_shuffle|' . $seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);
		$fill = array_slice( $pool, 0, $remaining );

		$selected_abstract = array_merge( array( $seed_slot ), $fill );
		$selected_concrete = array();
		foreach ( $selected_abstract as $slot ) {
			if ( 'author_name' === $slot ) {
				$selected_concrete[] = 'author_' . $drawn_index . '_surname';
				$selected_concrete[] = 'author_' . $drawn_index . '_initials';
			} else {
				$selected_concrete[] = $slot;
			}
		}
		$selected_set = array_fill_keys( $selected_concrete, true );

		$ordered = array();
		foreach ( self::build_tokens( $authors, array( 'year' => '', 'title' => '', 'publisher' => '' ), $drawn_index ) as $token ) {
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
	 * recovered directly from $selected_keys, same as Citex_Book_Dragdrop_Parts::build().
	 *
	 * @param string[] $selected_keys
	 * @param array    $authors array<{surname, initials}>, 1 or more.
	 * @param array    $fields  {year, title, publisher}.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
	 *         null when $selected_keys names an author index out of range
	 *         for $authors, or selects nothing at all.
	 */
	public static function build( array $selected_keys, array $authors, array $fields ) {
		$drawn_index = 0;
		foreach ( $selected_keys as $key ) {
			if ( 1 === preg_match( '/^author_(\d+)_(?:surname|initials)$/', (string) $key, $matches ) ) {
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
		$record_seed  = implode( '|', array( (string) $fields['year'], (string) $fields['title'], (string) $fields['publisher'], (string) count( $authors ) ) );

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
			case 'author_initials':
				return self::author_initials_distractor( $value, $authors, $drawn_index, $record_seed );
			case 'ampersand':
				// Tests "two or more authors are joined with '&', never
				// 'and'" — the OPPOSITE of Harvard's own reference-list
				// rule, swapped in here as the wrong answer.
				return 'and';
			case 'year':
				return Citex_Reference_Rules::year_distractor( $value, $record_seed . '|year' );
			case 'title':
				return Citex_Reference_Rules::title_like_distractor( $value, $fields['year'], $record_seed . '|title' );
			case 'publisher':
				$pick = Citex_Reference_Rules::pick_from_pool( Citex_Reference_Rules::publisher_pool(), array( $value ), $record_seed . '|publisher' );
				return null !== $pick ? $pick : 'n.p.';
			default:
				return $value . '?';
		}
	}

	/**
	 * The drawn author's surname distractor — same two-flavour rotation as
	 * Citex_Book_Dragdrop_Parts's own: attributing the OTHER author's
	 * surname (only eligible with 2+ authors), or a wrongly possessive
	 * form as the single-author fallback.
	 */
	private static function author_surname_distractor( $value, array $authors, $drawn_index, $record_seed ) {
		$other = self::other_author( $authors, $drawn_index );
		if ( null !== $other && 0 === ( abs( crc32( 'apa_book_dragdrop_surname_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $other['surname'], $value ) ) {
			return (string) $other['surname'];
		}
		return $value . "'s";
	}

	/**
	 * The drawn author's initials distractor — rotates between "initials
	 * need full stops" (stripping them, single-author fallback) and
	 * attributing the OTHER author's initials (correct format, wrong
	 * author — only eligible with 2+ authors).
	 */
	private static function author_initials_distractor( $value, array $authors, $drawn_index, $record_seed ) {
		$other = self::other_author( $authors, $drawn_index );
		if ( null !== $other && 0 === ( abs( crc32( 'apa_book_dragdrop_initials_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $other['initials'], $value ) ) {
			return (string) $other['initials'];
		}
		$stripped = str_replace( '.', '', $value );
		return ( '' !== $stripped && $stripped !== $value ) ? $stripped : $value . "'";
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
}
