<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MLA Edited Book DragDrop's dynamic question builder — mirrors
 * Citex_MLA_Book_Dragdrop_Parts's own "select 3 of a content pool, always
 * split the first person into surname/given-name" model exactly, but for
 * MLA's Edited Book rule (see Citex_MLA_Reference_Rules's own docblock):
 * a trailing "editor"/"editors" designation that Citex authors, never
 * traded away — the SAME "designation never traded away" principle as
 * Harvard's own Edited Book DragDrop shape (edited_book_dragdrop_shape_variant()),
 * just for MLA's spelled-out designation instead of Harvard's "(ed.)"/
 * "(eds)" abbreviation.
 *
 * Only the FIRST editor is ever split into draggable pieces — MLA never
 * inverts any editor but the first, exactly like MLA Book's own authors.
 *
 * Eligible content candidates: editor_name (costs 2: surname + given
 * name), year, title, publisher — no `place` candidate exists at all.
 * The structural "joiner" candidate (correct value "and" for exactly 2
 * editors, "et al." for 3 or more) is eligible only when there are 2+
 * editors. `designation` is ALWAYS drawn, on top of the same 3-part
 * content budget MLA Book itself uses — never part of the competitive
 * selection, since it is this category's own defining rule.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_MLA_Edited_Book_Dragdrop_Parts {

	/**
	 * @return string[]
	 *
	 * 'editor_name' costs 2 concrete parts (surname + given name
	 * together); every other slot here costs 1. There is no 'place' slot
	 * at all — MLA has no place-of-publication element.
	 */
	private static function content_slots() {
		return array( 'editor_name', 'year', 'title', 'publisher' );
	}

	/**
	 * The structural "joiner" slot — only eligible when there are 2 or
	 * more editors (a single editor has no joining word/abbreviation at
	 * all).
	 */
	private static function structural_slots( $editor_count ) {
		return $editor_count >= 2 ? array( 'joiner' ) : array();
	}

	/**
	 * Builds the full ordered token list for one MLA edited book record.
	 * The FIRST editor is always the one split into surname/given-name
	 * candidates; a second editor (exactly 2) is folded in as plain,
	 * non-draggable "First Last" text; 3 or more collapse everyone after
	 * the first into the "joiner" candidate's own "et al." value. The
	 * "designation" candidate ("editor" for exactly 1, "editors" for 2+)
	 * always sits immediately before the title, regardless of editor
	 * count — see Citex_MLA_Reference_Rules::join_editors()'s own docblock
	 * for why "et al."'s abbreviation period must never be touched when
	 * the designation is appended after it.
	 *
	 * @param array $editors array<{surname, givenName, fullName}>, 1 or more.
	 * @param array $fields  {year, title, publisher}.
	 * @return array<{key: string|null, kind: string, value: string, literal: bool}>
	 */
	public static function build_tokens( array $editors, array $fields ) {
		$count       = count( $editors );
		$first       = $editors[0];
		$designation = $count > 1 ? 'editors' : 'editor';

		$tokens   = array();
		$tokens[] = array( 'key' => 'editor_surname', 'kind' => 'editor_surname', 'value' => (string) $first['surname'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
		$tokens[] = array( 'key' => 'editor_given', 'kind' => 'editor_given', 'value' => (string) $first['givenName'], 'literal' => false );

		if ( 1 === $count ) {
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
			$tokens[] = array( 'key' => 'designation', 'kind' => 'designation', 'value' => $designation, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '. ', 'literal' => true );
		} elseif ( 2 === $count ) {
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
			$tokens[] = array( 'key' => 'joiner', 'kind' => 'joiner', 'value' => 'and', 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ' . (string) $editors[1]['fullName'] . ', ', 'literal' => true );
			$tokens[] = array( 'key' => 'designation', 'kind' => 'designation', 'value' => $designation, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '. ', 'literal' => true );
		} else {
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
			$tokens[] = array( 'key' => 'joiner', 'kind' => 'joiner', 'value' => 'et al.', 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
			$tokens[] = array( 'key' => 'designation', 'kind' => 'designation', 'value' => $designation, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '. ', 'literal' => true );
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
	 * Deterministically, but effectively unpredictably, picks which
	 * candidate keys to draw for one question, seeded by that question's
	 * own id — same technique as Citex_MLA_Book_Dragdrop_Parts::select_parts():
	 * one "seed" content slot guarantees the content floor, the remaining
	 * budget (target always exactly 3) is filled from the other eligible
	 * cost-1 slots (including 'joiner' when eligible), deterministically
	 * shuffled — then 'designation' is appended UNCONDITIONALLY, on top of
	 * that budget, since it is never traded away.
	 *
	 * @param string|int $seed    Typically the question's own id (e.g. "ME04").
	 * @param array      $editors array<{surname, givenName, fullName}>, 1 or more.
	 * @return string[] ordered candidate keys.
	 */
	public static function select_parts( $seed, array $editors ) {
		$seed         = (string) $seed;
		$editor_count = count( $editors );
		$target_count = 3;

		$content_slots = self::content_slots();
		$seed_slot     = $content_slots[ abs( crc32( 'mla_edited_book_dragdrop_seed|' . $seed ) ) % count( $content_slots ) ];
		$seed_cost     = ( 'editor_name' === $seed_slot ) ? 2 : 1;
		$remaining     = max( 0, $target_count - $seed_cost );

		$pool = array_values( array_diff( $content_slots, array( 'editor_name', $seed_slot ) ) );
		$pool = array_merge( $pool, self::structural_slots( $editor_count ) );
		usort(
			$pool,
			function ( $a, $b ) use ( $seed ) {
				$hash_a = crc32( 'mla_edited_book_dragdrop_shuffle|' . $seed . '|' . $a );
				$hash_b = crc32( 'mla_edited_book_dragdrop_shuffle|' . $seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);
		$fill = array_slice( $pool, 0, $remaining );

		$selected_abstract = array_merge( array( $seed_slot ), $fill );
		$selected_concrete = array();
		foreach ( $selected_abstract as $slot ) {
			if ( 'editor_name' === $slot ) {
				$selected_concrete[] = 'editor_surname';
				$selected_concrete[] = 'editor_given';
			} else {
				$selected_concrete[] = $slot;
			}
		}
		// The designation is this category's own defining rule — always
		// drawn, in addition to the budget above.
		$selected_concrete[] = 'designation';
		$selected_set        = array_fill_keys( $selected_concrete, true );

		$ordered = array();
		foreach ( self::build_tokens( $editors, array( 'year' => '', 'title' => '', 'publisher' => '' ) ) as $token ) {
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
	 * @param array    $editors array<{surname, givenName, fullName}>, 1 or more.
	 * @param array    $fields  {year, title, publisher}.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
	 *         null when $selected_keys selects nothing at all.
	 */
	public static function build( array $selected_keys, array $editors, array $fields ) {
		$selected_set = array_fill_keys( array_map( 'strval', $selected_keys ), true );
		$tokens       = self::build_tokens( $editors, $fields );
		$token_count  = count( $tokens );
		$record_seed  = implode( '|', array( (string) $fields['year'], (string) $fields['title'], (string) $fields['publisher'], (string) count( $editors ) ) );

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
				$confusing[] = self::distractor_for( $token['kind'], $token['value'], $editors, $fields, $record_seed );
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
	private static function distractor_for( $kind, $value, array $editors, array $fields, $record_seed ) {
		switch ( $kind ) {
			case 'editor_surname':
				return self::editor_surname_distractor( $value, $editors, $record_seed );
			case 'editor_given':
				return self::editor_given_distractor( $value, $editors, $record_seed );
			case 'joiner':
				return self::joiner_distractor( $value, $record_seed );
			case 'designation':
				return self::designation_distractor( $value, $record_seed );
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
	 * The first editor's surname distractor — same "rotate with the second
	 * editor's own surname at exactly 2, else a wrongly possessive form"
	 * pattern as Citex_MLA_Book_Dragdrop_Parts::author_surname_distractor().
	 */
	private static function editor_surname_distractor( $value, array $editors, $record_seed ) {
		if ( 2 === count( $editors ) && 0 === ( abs( crc32( 'mla_edited_book_dragdrop_surname_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $editors[1]['surname'], $value ) ) {
			return (string) $editors[1]['surname'];
		}
		return $value . "'s";
	}

	/**
	 * The first editor's given-name distractor — the single most
	 * MLA-distinctive mistake: wrongly abbreviating the required FULL
	 * first name down to a Harvard-style initial.
	 */
	private static function editor_given_distractor( $value, array $editors, $record_seed ) {
		if ( 2 === count( $editors ) && 0 === ( abs( crc32( 'mla_edited_book_dragdrop_given_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $editors[1]['givenName'], $value ) ) {
			return (string) $editors[1]['givenName'];
		}
		$initial = '' !== $value ? mb_substr( $value, 0, 1 ) . '.' : $value;
		return $initial !== $value ? $initial : $value . "'s";
	}

	/**
	 * The "joiner" distractor — identical shape to
	 * Citex_MLA_Book_Dragdrop_Parts::joiner_distractor().
	 */
	private static function joiner_distractor( $value, $record_seed ) {
		if ( 'and' === $value ) {
			return '&';
		}
		$variants = array( 'et al', 'and others' );
		$pick     = $variants[ abs( crc32( 'mla_edited_book_dragdrop_joiner|' . $record_seed ) ) % count( $variants ) ];
		return $pick !== $value ? $pick : 'and others';
	}

	/**
	 * The "designation" distractor — rotates between the wrong
	 * singular/plural form and Harvard's own "(ed.)"/"(eds)" abbreviation
	 * wrongly bleeding into an MLA reference, the single most-tested
	 * MLA-vs-Harvard distractor for this category.
	 */
	private static function designation_distractor( $value, $record_seed ) {
		$variants = 'editor' === $value ? array( 'editors', '(ed.)' ) : array( 'editor', '(eds)' );
		$pick     = $variants[ abs( crc32( 'mla_edited_book_dragdrop_designation|' . $record_seed ) ) % count( $variants ) ];
		return $pick;
	}
}
