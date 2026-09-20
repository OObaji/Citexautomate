<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chicago Edited Book DragDrop's dynamic question builder — mirrors
 * Citex_Chicago_Book_Dragdrop_Parts's own "select 3 of a content pool,
 * always split the first editor into surname/given name" model, extended
 * with APA/MLA Edited Book's own "designation always drawn on top of the
 * budget" convention, but for Chicago's own rule (see
 * Citex_Chicago_Reference_Rules's own docblock): the designation
 * ("ed"/"eds") sits comma-separated INSIDE the person segment itself,
 * lowercase and never parenthesised (unlike Harvard's "(ed.)"/"(eds)" and
 * APA's "(Ed.)"/"(Eds.)"), and — like Chicago Book — every editor is always
 * listed in full with a comma before "and" even at exactly two, and the
 * year has no surrounding parentheses at all.
 *
 * Eligible content candidates: editor_name (costs 2: surname + given name),
 * year, title, place, publisher. The structural "and" candidate (correct
 * value "and") is eligible only when there are 2+ editors, exactly
 * mirroring Chicago Book's own eligibility rule. `designation` is ALWAYS
 * drawn, on top of the same 3-part content budget — never part of the
 * competitive selection, since it is this category's own defining rule
 * (matching APA/MLA Edited Book's own convention).
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_Chicago_Edited_Book_Dragdrop_Parts {

	/**
	 * @return string[]
	 *
	 * 'editor_name' costs 2 concrete parts (surname + given name together);
	 * every other slot here costs 1.
	 */
	private static function content_slots() {
		return array( 'editor_name', 'year', 'title', 'place', 'publisher' );
	}

	/**
	 * The structural "and" slot — only eligible when there are 2 or more
	 * editors (a single editor has no joining word at all).
	 */
	private static function structural_slots( $editor_count ) {
		return $editor_count >= 2 ? array( 'and' ) : array();
	}

	/**
	 * Builds the full ordered token list for one Chicago edited book
	 * record — the editor-list segment is identical in shape to
	 * Citex_Chicago_Book_Dragdrop_Parts::build_tokens()'s own author loop
	 * (only the FIRST editor ever split into draggable surname/given-name
	 * parts, comma before "and" even at exactly two), immediately followed
	 * by the "designation" candidate ("ed" for exactly one editor, "eds"
	 * for two or more — deliberately without its own trailing period, see
	 * Citex_Chicago_Reference_Rules::designation_for_editor_count()'s own
	 * docblock) before the year.
	 *
	 * @param array $editors array<{surname, givenName}>, 1 or more.
	 * @param array $fields  {year, title, place, publisher}.
	 * @return array<{key: string|null, kind: string, value: string, literal: bool}>
	 */
	public static function build_tokens( array $editors, array $fields ) {
		$tokens = array();
		$count  = count( $editors );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( 0 === $i ) {
				$tokens[] = array( 'key' => 'editor_0_surname', 'kind' => 'editor_surname', 'value' => (string) $editors[ $i ]['surname'], 'literal' => false );
				$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
				$tokens[] = array( 'key' => 'editor_0_givenname', 'kind' => 'editor_givenname', 'value' => (string) $editors[ $i ]['givenName'], 'literal' => false );
			} else {
				$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => sprintf( '%s, %s', $editors[ $i ]['surname'], $editors[ $i ]['givenName'] ), 'literal' => true );
			}
			if ( $i < $count - 1 ) {
				$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
				if ( $i === $count - 2 ) {
					$tokens[] = array( 'key' => 'and', 'kind' => 'and', 'value' => 'and', 'literal' => false );
					$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ', 'literal' => true );
				}
			}
		}
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
		$tokens[] = array( 'key' => 'designation', 'kind' => 'designation', 'value' => Citex_Chicago_Reference_Rules::designation_for_editor_count( $count ), 'literal' => false );
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
	 * Deterministically, but effectively unpredictably, picks which
	 * candidate keys to draw for one question, seeded by that question's
	 * own id — same technique as
	 * Citex_Chicago_Book_Dragdrop_Parts::select_parts()/
	 * Citex_APA_Edited_Book_Dragdrop_Parts::select_parts().
	 *
	 * @param string|int $seed    Typically the question's own id (e.g. "CE04").
	 * @param array      $editors array<{surname, givenName}>, 1 or more.
	 * @return string[] ordered candidate keys.
	 */
	public static function select_parts( $seed, array $editors ) {
		$seed         = (string) $seed;
		$editor_count = count( $editors );
		$target_count = 3;

		$content_slots = self::content_slots();
		$seed_slot     = $content_slots[ abs( crc32( 'chicago_edited_book_dragdrop_seed|' . $seed ) ) % count( $content_slots ) ];
		$seed_cost     = ( 'editor_name' === $seed_slot ) ? 2 : 1;
		$remaining     = max( 0, $target_count - $seed_cost );

		$pool = array_values( array_diff( $content_slots, array( 'editor_name', $seed_slot ) ) );
		$pool = array_merge( $pool, self::structural_slots( $editor_count ) );
		usort(
			$pool,
			function ( $a, $b ) use ( $seed ) {
				$hash_a = crc32( 'chicago_edited_book_dragdrop_shuffle|' . $seed . '|' . $a );
				$hash_b = crc32( 'chicago_edited_book_dragdrop_shuffle|' . $seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);
		$fill = array_slice( $pool, 0, $remaining );

		$selected_abstract = array_merge( array( $seed_slot ), $fill );
		$selected_concrete = array();
		foreach ( $selected_abstract as $slot ) {
			if ( 'editor_name' === $slot ) {
				$selected_concrete[] = 'editor_0_surname';
				$selected_concrete[] = 'editor_0_givenname';
			} else {
				$selected_concrete[] = $slot;
			}
		}
		// The designation is this category's own defining rule — always
		// drawn, in addition to the budget above.
		$selected_concrete[] = 'designation';
		$selected_set        = array_fill_keys( $selected_concrete, true );

		$ordered = array();
		foreach ( self::build_tokens( $editors, array( 'year' => '', 'title' => '', 'place' => '', 'publisher' => '' ) ) as $token ) {
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
	 * @param array    $editors array<{surname, givenName}>, 1 or more.
	 * @param array    $fields  {year, title, place, publisher}.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
	 */
	public static function build( array $selected_keys, array $editors, array $fields ) {
		$selected_set = array_fill_keys( array_map( 'strval', $selected_keys ), true );
		$tokens       = self::build_tokens( $editors, $fields );
		$token_count  = count( $tokens );
		$record_seed  = implode( '|', array( (string) $fields['year'], (string) $fields['title'], (string) $fields['place'], (string) $fields['publisher'], (string) count( $editors ) ) );

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
			case 'editor_givenname':
				return self::editor_givenname_distractor( $value, $editors, $record_seed );
			case 'and':
				// Tests "editors are joined with the word 'and', never '&'".
				return '&';
			case 'designation':
				return self::designation_distractor( $value, $record_seed );
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
	 * The first editor's surname distractor — same two-flavour rotation as
	 * Citex_Chicago_Book_Dragdrop_Parts's own: attributing the OTHER
	 * editor's surname (only eligible with 2+ editors), or a wrongly
	 * possessive form as the single-editor fallback.
	 */
	private static function editor_surname_distractor( $value, array $editors, $record_seed ) {
		$other = isset( $editors[1] ) ? $editors[1] : null;
		if ( null !== $other && 0 === ( abs( crc32( 'chicago_edited_book_dragdrop_surname_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $other['surname'], $value ) ) {
			return (string) $other['surname'];
		}
		return $value . "'s";
	}

	/**
	 * The first editor's given-name distractor — rotates between
	 * attributing the OTHER editor's given name (correct format, wrong
	 * editor — only eligible with 2+ editors) and truncating it to a
	 * single initial (tests "the FULL given name is required, never an
	 * initial").
	 */
	private static function editor_givenname_distractor( $value, array $editors, $record_seed ) {
		$other = isset( $editors[1] ) ? $editors[1] : null;
		if ( null !== $other && 0 === ( abs( crc32( 'chicago_edited_book_dragdrop_givenname_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $other['givenName'], $value ) ) {
			return (string) $other['givenName'];
		}
		$letter  = mb_substr( trim( (string) $value ), 0, 1 );
		$initial = '' !== $letter ? mb_strtoupper( $letter ) . '.' : $value;
		return $initial !== $value ? $initial : $value . "'s";
	}

	/**
	 * The "designation" distractor — rotates between the wrong
	 * singular/plural form and Harvard's/APA's own parenthesised shape
	 * wrongly bleeding into a Chicago reference.
	 */
	private static function designation_distractor( $value, $record_seed ) {
		$variants = 'ed' === $value ? array( 'eds', '(Ed.)' ) : array( 'ed', '(Eds.)' );
		return $variants[ abs( crc32( 'chicago_edited_book_dragdrop_designation|' . $record_seed ) ) % count( $variants ) ];
	}
}
