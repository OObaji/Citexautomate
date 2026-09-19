<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MLA Book DragDrop's dynamic 3-part question builder — mirrors
 * Citex_Book_Dragdrop_Parts's own "always the full reference, N random
 * blanks" model exactly, but for MLA's genuinely different Book rule (see
 * Citex_MLA_Reference_Rules's own docblock): no place of publication at
 * all, the FULL first name is used (never initials), and only the FIRST
 * author is ever named literally — a second author is folded in as plain
 * "First Last" text, and 3 or more authors collapse everyone after the
 * first into "et al." entirely (the opposite of Harvard's own "always list
 * every author in full" rule).
 *
 * Because only the first author is ever split into draggable pieces
 * (unlike Harvard Book, which randomly picks WHICH author to split), the
 * drawn author is always index 0 — there is no drawn-index selection here
 * at all.
 *
 * Eligible content candidates: author_name (costs 2: surname + given name),
 * year, title, publisher — no `place` candidate exists at all. The
 * structural "joiner" candidate (correct value "and" for exactly 2
 * authors, "et al." for 3 or more) is eligible only when there are 2+
 * authors, exactly mirroring Harvard Book's own "and" eligibility rule.
 *
 * Every distractor is authored deterministically by Citex, reusing
 * Citex_Reference_Rules's own public "SHARED DETERMINISTIC DISTRACTOR
 * PRIMITIVES" (title_like_distractor(), publisher_pool()/pick_from_pool(),
 * year_distractor()) rather than duplicating that logic a further time —
 * only the author/joiner distractors, genuinely MLA-specific, are written
 * here directly.
 *
 * Pure and static, no WordPress/ACF calls, exactly like
 * Citex_Reference_Rules/Citex_MLA_Reference_Rules and
 * Citex_Book_Dragdrop_Parts — unit-testable directly.
 */
class Citex_MLA_Book_Dragdrop_Parts {

	/**
	 * @return string[]
	 *
	 * 'author_name' costs 2 concrete parts (surname + given name together);
	 * every other slot here costs 1. There is no 'place' slot at all — MLA
	 * has no place-of-publication element.
	 */
	private static function content_slots() {
		return array( 'author_name', 'year', 'title', 'publisher' );
	}

	/**
	 * The structural "joiner" slot — only eligible when there are 2 or more
	 * authors (a single author has no joining word/abbreviation at all).
	 */
	private static function structural_slots( $author_count ) {
		return $author_count >= 2 ? array( 'joiner' ) : array();
	}

	/**
	 * Builds the full ordered token list for one MLA book record. The FIRST
	 * author is always the one split into surname/given-name candidates —
	 * MLA's own rule never inverts any author but the first, so there is
	 * nothing else to draw a name from. For exactly 2 authors, the second
	 * author is folded in as plain, non-draggable "First Last" text
	 * (MLA never inverts a second author); for 3 or more, every author
	 * after the first is dropped entirely and replaced by the "joiner"
	 * candidate's own "et al." value, which already carries its own
	 * abbreviation period — no separate full stop follows it.
	 *
	 * @param array $authors array<{surname, givenName, fullName}>, 1 or more.
	 * @param array $fields  {year, title, publisher}.
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
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '. ', 'literal' => true );
		} elseif ( 2 === $count ) {
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
			$tokens[] = array( 'key' => 'joiner', 'kind' => 'joiner', 'value' => 'and', 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ' . (string) $authors[1]['fullName'] . '. ', 'literal' => true );
		} else {
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
			$tokens[] = array( 'key' => 'joiner', 'kind' => 'joiner', 'value' => 'et al.', 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ', 'literal' => true );
		}

		$tokens[] = array( 'key' => 'title', 'kind' => 'title', 'value' => (string) $fields['title'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '. ', 'literal' => true );
		$tokens[] = array( 'key' => 'publisher', 'kind' => 'publisher', 'value' => (string) $fields['publisher'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
		$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => (string) $fields['year'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '.', 'literal' => true );
		return $tokens;
	}

	/**
	 * Deterministically, but effectively unpredictably, picks exactly which
	 * candidate keys to draw for one question, seeded by that question's
	 * own id — same technique as Citex_Book_Dragdrop_Parts::select_parts():
	 * one "seed" content slot guarantees the content floor, the remaining
	 * budget (target always exactly 3) is filled from the other eligible
	 * cost-1 slots (including 'joiner' when eligible), deterministically
	 * shuffled. Returned in REFERENCE order.
	 *
	 * @param string|int $seed    Typically the question's own id (e.g. "MB04").
	 * @param array      $authors array<{surname, givenName, fullName}>, 1 or more.
	 * @return string[] ordered candidate keys.
	 */
	public static function select_parts( $seed, array $authors ) {
		$seed         = (string) $seed;
		$author_count = count( $authors );
		$target_count = 3;

		$content_slots = self::content_slots();
		$seed_slot     = $content_slots[ abs( crc32( 'mla_book_dragdrop_seed|' . $seed ) ) % count( $content_slots ) ];
		$seed_cost     = ( 'author_name' === $seed_slot ) ? 2 : 1;
		$remaining     = max( 0, $target_count - $seed_cost );

		$pool = array_values( array_diff( $content_slots, array( 'author_name', $seed_slot ) ) );
		$pool = array_merge( $pool, self::structural_slots( $author_count ) );
		usort(
			$pool,
			function ( $a, $b ) use ( $seed ) {
				$hash_a = crc32( 'mla_book_dragdrop_shuffle|' . $seed . '|' . $a );
				$hash_b = crc32( 'mla_book_dragdrop_shuffle|' . $seed . '|' . $b );
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
		foreach ( self::build_tokens( $authors, array( 'year' => '', 'title' => '', 'publisher' => '' ) ) as $token ) {
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
	 * and the record's own canonical fields.
	 *
	 * @param string[] $selected_keys
	 * @param array    $authors array<{surname, givenName, fullName}>, 1 or more.
	 * @param array    $fields  {year, title, publisher}.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
	 *         null when $selected_keys selects nothing at all.
	 */
	public static function build( array $selected_keys, array $authors, array $fields ) {
		$selected_set = array_fill_keys( array_map( 'strval', $selected_keys ), true );
		$tokens       = self::build_tokens( $authors, $fields );
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

	/**
	 * One deterministic wrong chip for a drawn candidate, keyed by its
	 * "kind" — never Gemini-authored, so the validator can recompute and
	 * exact-match these exactly like every other part of the question.
	 */
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
			case 'title':
				return Citex_Reference_Rules::title_like_distractor( $value, $fields['year'], $record_seed . '|title' );
			case 'publisher':
				$pick = Citex_Reference_Rules::pick_from_pool( Citex_Reference_Rules::publisher_pool(), array( $value ), $record_seed . '|publisher' );
				return null !== $pick ? $pick : 'n.pub.';
			default:
				return $value . '?';
		}
	}

	/**
	 * The first author's surname distractor. With exactly 2 authors,
	 * rotates with the SECOND author's own surname (tests "which author
	 * does this surname belong to" — the second author's name is visible
	 * as literal text, so this is a genuine name-attribution mistake).
	 * Never falls back to the author's OWN given name: 'author_surname' and
	 * 'author_given' are always drawn together (both cost the same
	 * 'author_name' abstract slot — see select_parts()), so the given name
	 * is always itself a live, correct Question Part in the same question
	 * whenever this distractor runs, and reusing it here would make this
	 * distractor case-insensitively identical to that OTHER correct part
	 * (the same class of bug already fixed once this session for Website's
	 * organisation-name MCQ distractors). The safe fallback is a wrongly
	 * possessive surname instead.
	 */
	private static function author_surname_distractor( $value, array $authors, $record_seed ) {
		if ( 2 === count( $authors ) && 0 === ( abs( crc32( 'mla_book_dragdrop_surname_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $authors[1]['surname'], $value ) ) {
			return (string) $authors[1]['surname'];
		}
		return $value . "'s";
	}

	/**
	 * The first author's given-name distractor. The single most
	 * MLA-distinctive mistake: wrongly abbreviating the required FULL
	 * first name down to a Harvard-style initial (e.g. "Amy" -> "A.") —
	 * rotates with attributing the SECOND author's given name instead, when
	 * exactly 2 authors exist.
	 */
	private static function author_given_distractor( $value, array $authors, $record_seed ) {
		if ( 2 === count( $authors ) && 0 === ( abs( crc32( 'mla_book_dragdrop_given_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $authors[1]['givenName'], $value ) ) {
			return (string) $authors[1]['givenName'];
		}
		$initial = '' !== $value ? mb_substr( $value, 0, 1 ) . '.' : $value;
		return $initial !== $value ? $initial : $value . "'s";
	}

	/**
	 * The "joiner" distractor — correct value is "and" for exactly 2
	 * authors (mistake: "&", the same "and never &" rule every other
	 * category's join tests) or "et al." for 3 or more (mistake: rotating
	 * between the missing-abbreviation-period "et al" and the wrong
	 * phrase "and others").
	 */
	private static function joiner_distractor( $value, $record_seed ) {
		if ( 'and' === $value ) {
			return '&';
		}
		$variants = array( 'et al', 'and others' );
		$pick     = $variants[ abs( crc32( 'mla_book_dragdrop_joiner|' . $record_seed ) ) % count( $variants ) ];
		return $pick !== $value ? $pick : 'and others';
	}
}
