<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Edited Book DragDrop's dynamic 3-part question builder — replaces the
 * fixed named-design catalogue (Citex_Reference_Rules::edited_book_dragdrop_designs()
 * and edited_book_dragdrop_shape_variant(), both still used by MCQ and left
 * untouched) with the same "always the full reference, N random blanks"
 * model Book DragDrop already uses (see Citex_Book_Dragdrop_Parts): the
 * complete reference — "Editor(s) (ed./eds) (Year) Title. Place: Publisher."
 * — is always rendered in full, and exactly 3 of its fields are drawn as
 * draggable blanks; every other field stays visible as literal text.
 *
 * Unlike Book/Journal Article/Website, the editor's name AND its "(ed.)"/
 * "(eds)" designation are a FORCED pair — this category's own established,
 * never-traded-away rule (a real reference-list distinction Edited Book
 * exists specifically to teach) — never left to the random pool. The one
 * remaining slot (of the 3) is drawn from {year, title, place, publisher},
 * plus "and" when there are 2+ editors.
 *
 * Every distractor is authored deterministically by Citex, from the
 * record's own fields, via Citex_Reference_Rules's "SHARED DETERMINISTIC
 * DISTRACTOR PRIMITIVES" section — never Gemini.
 *
 * The reference is modelled as an ordered TOKEN STREAM (see build_tokens()),
 * exactly like Citex_Book_Dragdrop_Parts/Citex_Journal_Article_Dragdrop_Parts.
 * select_parts() deterministically (crc32-seeded, same pattern as
 * Citex_Book_Mcq_Variants::variant_for()) decides which 3 candidates get
 * drawn; build() turns that decision plus the record's own fields into
 * {parts, fixedText, confusingWords}, and can be re-run by the validator
 * from the STORED selection (dragdropPartKeys) to recompute and exactly
 * compare the whole question.
 *
 * The drawn editor's name is ONE combined "Surname, I." candidate (never
 * split into separate surname/initials parts), matching what this category
 * has always done — this also means the old `editor_split_designation`
 * design (which spent the whole 3-part budget on a split surname/initials
 * pair with zero other field) is not reproduced; a deliberate
 * simplification for consistency with the other categories.
 *
 * Pure and static, no WordPress/ACF calls, exactly like
 * Citex_Reference_Rules and Citex_Book_Dragdrop_Parts — unit-testable
 * directly.
 */
class Citex_Edited_Book_Dragdrop_Parts {

	/**
	 * The one extra field drawn alongside the forced editor+designation
	 * pair — every slot here costs exactly 1 concrete part.
	 *
	 * @return string[]
	 */
	private static function fill_slots( $editor_count ) {
		$slots = array( 'year', 'title', 'place', 'publisher' );
		if ( $editor_count >= 2 ) {
			$slots[] = 'and';
		}
		return $slots;
	}

	/**
	 * Builds the full ordered token list for one Edited Book record.
	 * $drawn_editor_index picks which single editor's combined
	 * "Surname, I." is represented as a candidate token instead of literal
	 * text; the designation ("(ed.)"/"(eds)") is always its own candidate.
	 *
	 * @param array $editors             array<{surname, initials}>, 1 or more.
	 * @param array $fields              {year, title, place, publisher}.
	 * @param int   $drawn_editor_index
	 * @return array<{key: string|null, kind: string, value: string, literal: bool}>
	 */
	public static function build_tokens( array $editors, array $fields, $drawn_editor_index = 0 ) {
		$tokens = array();
		$count  = count( $editors );
		for ( $i = 0; $i < $count; $i++ ) {
			$combined = sprintf( '%s, %s', $editors[ $i ]['surname'], $editors[ $i ]['initials'] );
			if ( $i === $drawn_editor_index ) {
				$tokens[] = array( 'key' => 'editor_' . $i, 'kind' => 'editor_name', 'value' => $combined, 'literal' => false );
			} else {
				$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => $combined, 'literal' => true );
			}
			if ( $i < $count - 1 ) {
				if ( $i === $count - 2 ) {
					$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ', 'literal' => true );
					$tokens[] = array( 'key' => 'and', 'kind' => 'and', 'value' => 'and', 'literal' => false );
					$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ', 'literal' => true );
				} else {
					$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
				}
			}
		}
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' (', 'literal' => true );
		$tokens[] = array( 'key' => 'designation', 'kind' => 'designation', 'value' => Citex_Reference_Rules::designation_for_editor_count( $count ), 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ') (', 'literal' => true );
		$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => (string) $fields['year'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ') ', 'literal' => true );
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
	 * candidate keys to draw for one question, seeded by that question's
	 * own id:
	 * 1. Pick a drawn editor index (any of count($editors), uniformly).
	 * 2. Always force the drawn editor's `editor_N` candidate and
	 *    `designation` (the category's own never-traded-away rule) — 2 of
	 *    the 3 slots.
	 * 3. Fill the 1 remaining slot from {year, title, place, publisher},
	 *    plus 'and' when 2+ editors, deterministically shuffled.
	 * 4. Return the selected keys in REFERENCE order (not selection
	 *    order), by walking build_tokens()'s own output.
	 *
	 * @param string|int $seed    Typically the question's own id.
	 * @param array      $editors array<{surname, initials}>, 1 or more.
	 * @return string[] ordered candidate keys.
	 */
	public static function select_parts( $seed, array $editors ) {
		$seed         = (string) $seed;
		$editor_count = count( $editors );
		$drawn_index  = abs( crc32( 'edited_book_dragdrop_editor|' . $seed ) ) % max( 1, $editor_count );

		$pool = self::fill_slots( $editor_count );
		usort(
			$pool,
			function ( $a, $b ) use ( $seed ) {
				$hash_a = crc32( 'edited_book_dragdrop_shuffle|' . $seed . '|' . $a );
				$hash_b = crc32( 'edited_book_dragdrop_shuffle|' . $seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);
		$fill = array_slice( $pool, 0, 1 );

		$selected_concrete = array_merge( array( 'editor_' . $drawn_index, 'designation' ), $fill );
		$selected_set       = array_fill_keys( $selected_concrete, true );

		$empty_fields = array( 'year' => '', 'title' => '', 'place' => '', 'publisher' => '' );
		$ordered      = array();
		foreach ( self::build_tokens( $editors, $empty_fields, $drawn_index ) as $token ) {
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
	 * and the record's own canonical fields. The drawn editor index is
	 * recovered directly from $selected_keys (an "editor_N" key names it).
	 *
	 * @param string[] $selected_keys
	 * @param array    $editors array<{surname, initials, fullName}>, 1 or more.
	 * @param array    $fields  {year, title, place, publisher}.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
	 */
	public static function build( array $selected_keys, array $editors, array $fields ) {
		$drawn_index = 0;
		foreach ( $selected_keys as $key ) {
			if ( 1 === preg_match( '/^editor_(\d+)$/', (string) $key, $matches ) ) {
				$drawn_index = (int) $matches[1];
				break;
			}
		}
		if ( $drawn_index >= count( $editors ) ) {
			return null;
		}

		$selected_set   = array_fill_keys( array_map( 'strval', $selected_keys ), true );
		$tokens         = self::build_tokens( $editors, $fields, $drawn_index );
		$token_count    = count( $tokens );
		$full_name      = isset( $editors[ $drawn_index ]['fullName'] ) ? (string) $editors[ $drawn_index ]['fullName'] : '';
		$surname        = (string) $editors[ $drawn_index ]['surname'];
		$other          = self::other_editor( $editors, $drawn_index );
		$other_combined = null !== $other ? sprintf( '%s, %s', $other['surname'], $other['initials'] ) : null;
		$editor_count   = count( $editors );
		$record_seed    = implode( '|', array( (string) $fields['year'], (string) $fields['title'], (string) $fields['place'], (string) $fields['publisher'] ) );

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
				$confusing[] = self::distractor_for( $token['kind'], $token['value'], $full_name, $surname, $other_combined, $editor_count, $selected_set, $fields, $record_seed );
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
	 * "kind" — every flavour here is a shared primitive from
	 * Citex_Reference_Rules's "SHARED DETERMINISTIC DISTRACTOR PRIMITIVES"
	 * section, never Gemini-authored.
	 */
	private static function distractor_for( $kind, $value, $full_name, $surname, $other_combined, $editor_count, array $selected_set, array $fields, $record_seed ) {
		switch ( $kind ) {
			case 'editor_name':
				return Citex_Reference_Rules::combined_person_distractor( $value, $other_combined, $full_name, $surname, $record_seed . '|editor' );
			case 'and':
				// Tests "editors are joined with 'and', never '&'".
				return '&';
			case 'designation':
				return Citex_Reference_Rules::designation_mistake_distractor( $value, $editor_count, $record_seed . '|designation' );
			case 'year':
				return Citex_Reference_Rules::year_distractor( $value, $record_seed . '|year' );
			case 'title':
				return Citex_Reference_Rules::title_like_distractor( $value, $fields['year'], $record_seed . '|title' );
			case 'place':
				$exclude = array( $value );
				if ( isset( $selected_set['publisher'] ) ) {
					$exclude[] = $fields['publisher'];
				}
				$pick = Citex_Reference_Rules::pick_from_pool( Citex_Reference_Rules::place_pool(), $exclude, $record_seed . '|place' );
				return null !== $pick ? $pick : 'n.p.';
			case 'publisher':
				$exclude = array( $value );
				if ( isset( $selected_set['place'] ) ) {
					$exclude[] = $fields['place'];
				}
				$pick = Citex_Reference_Rules::pick_from_pool( Citex_Reference_Rules::publisher_pool(), $exclude, $record_seed . '|publisher' );
				return null !== $pick ? $pick : 'n.pub.';
			default:
				return $value . '?';
		}
	}

	/**
	 * The first editor in $editors that is NOT the drawn editor, or null
	 * for a single-editor record.
	 */
	private static function other_editor( array $editors, $drawn_index ) {
		foreach ( $editors as $i => $editor ) {
			if ( $i !== $drawn_index ) {
				return $editor;
			}
		}
		return null;
	}
}
