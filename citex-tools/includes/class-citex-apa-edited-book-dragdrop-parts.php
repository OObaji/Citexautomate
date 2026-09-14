<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * APA Edited Book DragDrop's dynamic question builder — mirrors
 * Citex_MLA_Edited_Book_Dragdrop_Parts's own "select 3 of a content pool,
 * always split the first editor into surname/initials, designation always
 * drawn" model exactly, but for APA's own rule (see
 * Citex_APA_Reference_Rules's own docblock): initials (not a full given
 * name), "(Ed.)"/"(Eds.)" designation (never Harvard's "(ed.)"/"(eds)" or
 * MLA's spelled-out "editor"/"editors"), a comma before "&" even at exactly
 * two editors, and a full stop immediately after the year's closing
 * parenthesis.
 *
 * Eligible content candidates: editor_name (costs 2: surname + initials),
 * year, title, publisher — no `place` candidate at all. The structural
 * "ampersand" candidate (correct value "&") is eligible only when there are
 * 2+ editors. `designation` is ALWAYS drawn, on top of the same 3-part
 * content budget — never part of the competitive selection, since it is
 * this category's own defining rule.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_APA_Edited_Book_Dragdrop_Parts {

	/**
	 * @return string[]
	 *
	 * 'editor_name' costs 2 concrete parts (surname + initials together);
	 * every other slot here costs 1. There is no 'place' slot at all — APA
	 * has no place-of-publication element.
	 */
	private static function content_slots() {
		return array( 'editor_name', 'year', 'title', 'publisher' );
	}

	/**
	 * The structural "ampersand" slot — only eligible when there are 2 or
	 * more editors (a single editor has no joining symbol at all).
	 */
	private static function structural_slots( $editor_count ) {
		return $editor_count >= 2 ? array( 'ampersand' ) : array();
	}

	/**
	 * Builds the full ordered token list for one APA edited book record. The
	 * FIRST editor is always the one split into surname/initials candidates;
	 * a second editor (exactly 2) is folded in as plain, non-draggable
	 * "Surname, I." text with a literal "&" preceded by a comma; 3 or more
	 * collapse the middle editors into comma-separated literal text with the
	 * final "&" still preceded by a comma. The "designation" candidate
	 * ("(Ed.)" for exactly 1, "(Eds.)" for 2+) always sits immediately after
	 * the editor segment, before the year.
	 *
	 * @param array $editors array<{surname, initials}>, 1 or more.
	 * @param array $fields  {year, title, publisher}.
	 * @return array<{key: string|null, kind: string, value: string, literal: bool}>
	 */
	public static function build_tokens( array $editors, array $fields ) {
		$count       = count( $editors );
		$first       = $editors[0];
		$designation = Citex_APA_Reference_Rules::designation_for_editor_count( $count );

		$tokens   = array();
		$tokens[] = array( 'key' => 'editor_surname', 'kind' => 'editor_surname', 'value' => (string) $first['surname'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
		$tokens[] = array( 'key' => 'editor_initials', 'kind' => 'editor_initials', 'value' => (string) $first['initials'], 'literal' => false );

		if ( 1 === $count ) {
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' (', 'literal' => true );
			$tokens[] = array( 'key' => 'designation', 'kind' => 'designation', 'value' => $designation, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '). ', 'literal' => true );
		} else {
			$middle = array_slice( $editors, 1, $count - 2 );
			$middle_text = '';
			foreach ( $middle as $person ) {
				$middle_text .= sprintf( ', %s, %s', $person['surname'], $person['initials'] );
			}
			$last = $editors[ $count - 1 ];
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => $middle_text . ', ', 'literal' => true );
			$tokens[] = array( 'key' => 'ampersand', 'kind' => 'ampersand', 'value' => '&', 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => sprintf( ' %s, %s (', $last['surname'], $last['initials'] ), 'literal' => true );
			$tokens[] = array( 'key' => 'designation', 'kind' => 'designation', 'value' => $designation, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '). ', 'literal' => true );
		}

		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '(', 'literal' => true );
		$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => (string) $fields['year'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '). ', 'literal' => true );
		$tokens[] = array( 'key' => 'title', 'kind' => 'title', 'value' => (string) $fields['title'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '. ', 'literal' => true );
		$tokens[] = array( 'key' => 'publisher', 'kind' => 'publisher', 'value' => (string) $fields['publisher'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '.', 'literal' => true );
		return $tokens;
	}

	/**
	 * Deterministically, but effectively unpredictably, picks which
	 * candidate keys to draw for one question, seeded by that question's own
	 * id — same technique as Citex_MLA_Edited_Book_Dragdrop_Parts::select_parts().
	 *
	 * @param string|int $seed    Typically the question's own id (e.g. "AE04").
	 * @param array      $editors array<{surname, initials}>, 1 or more.
	 * @return string[] ordered candidate keys.
	 */
	public static function select_parts( $seed, array $editors ) {
		$seed         = (string) $seed;
		$editor_count = count( $editors );
		$target_count = 3;

		$content_slots = self::content_slots();
		$seed_slot     = $content_slots[ abs( crc32( 'apa_edited_book_dragdrop_seed|' . $seed ) ) % count( $content_slots ) ];
		$seed_cost     = ( 'editor_name' === $seed_slot ) ? 2 : 1;
		$remaining     = max( 0, $target_count - $seed_cost );

		$pool = array_values( array_diff( $content_slots, array( 'editor_name', $seed_slot ) ) );
		$pool = array_merge( $pool, self::structural_slots( $editor_count ) );
		usort(
			$pool,
			function ( $a, $b ) use ( $seed ) {
				$hash_a = crc32( 'apa_edited_book_dragdrop_shuffle|' . $seed . '|' . $a );
				$hash_b = crc32( 'apa_edited_book_dragdrop_shuffle|' . $seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);
		$fill = array_slice( $pool, 0, $remaining );

		$selected_abstract = array_merge( array( $seed_slot ), $fill );
		$selected_concrete = array();
		foreach ( $selected_abstract as $slot ) {
			if ( 'editor_name' === $slot ) {
				$selected_concrete[] = 'editor_surname';
				$selected_concrete[] = 'editor_initials';
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
	 * selection of candidate keys and the record's own canonical fields.
	 *
	 * @param string[] $selected_keys
	 * @param array    $editors array<{surname, initials}>, 1 or more.
	 * @param array    $fields  {year, title, publisher}.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
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
			case 'editor_initials':
				return self::editor_initials_distractor( $value, $editors, $record_seed );
			case 'ampersand':
				// Tests "two or more editors are joined with '&', never
				// 'and'" — the OPPOSITE of Harvard's own reference-list
				// rule, swapped in here as the wrong answer.
				return 'and';
			case 'designation':
				return self::designation_distractor( $value, $record_seed );
			case 'year':
				return Citex_Reference_Rules::year_distractor( $value, $record_seed );
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
	 * The first editor's surname distractor — same two-flavour rotation as
	 * Citex_APA_Book_Dragdrop_Parts's own: attributing the OTHER editor's
	 * surname (only eligible with 2+ editors), or a wrongly possessive form
	 * as the single-editor fallback.
	 */
	private static function editor_surname_distractor( $value, array $editors, $record_seed ) {
		$other = isset( $editors[1] ) ? $editors[1] : null;
		if ( null !== $other && 0 === ( abs( crc32( 'apa_edited_book_dragdrop_surname_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $other['surname'], $value ) ) {
			return (string) $other['surname'];
		}
		return $value . "'s";
	}

	/**
	 * The first editor's initials distractor — rotates between "initials
	 * need full stops" (stripping them, single-editor fallback) and
	 * attributing the OTHER editor's initials (correct format, wrong
	 * editor — only eligible with 2+ editors).
	 */
	private static function editor_initials_distractor( $value, array $editors, $record_seed ) {
		$other = isset( $editors[1] ) ? $editors[1] : null;
		if ( null !== $other && 0 === ( abs( crc32( 'apa_edited_book_dragdrop_initials_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $other['initials'], $value ) ) {
			return (string) $other['initials'];
		}
		$stripped = str_replace( '.', '', $value );
		return ( '' !== $stripped && $stripped !== $value ) ? $stripped : $value . "'";
	}

	/**
	 * The "designation" distractor — rotates between the wrong
	 * singular/plural form and MLA's own spelled-out "editor"/"editors"
	 * wrongly bleeding into an APA reference.
	 */
	private static function designation_distractor( $value, $record_seed ) {
		$variants = 'Ed.' === $value ? array( 'Eds.', 'editor' ) : array( 'Ed.', 'editors' );
		$pick     = $variants[ abs( crc32( 'apa_edited_book_dragdrop_designation|' . $record_seed ) ) % count( $variants ) ];
		return $pick;
	}
}
