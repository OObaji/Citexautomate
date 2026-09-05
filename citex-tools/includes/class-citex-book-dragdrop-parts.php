<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Book DragDrop's dynamic 3-4-part question builder — replaces the fixed
 * 8-design catalogue (Citex_Reference_Rules::book_dragdrop_designs() and
 * friends, now removed) with a genuinely dynamic system: every question
 * draws a random subset of 3-4 "parts" from a pool of author name, year,
 * place, publisher, and the joining word "and" — and every wrong
 * "distractor" chip is authored deterministically by Citex, never Gemini
 * (mirrors the Book MCQ variant overhaul's philosophy: Gemini supplies only
 * the canonical record; Citex authors the entire student-facing question
 * from it).
 *
 * Every distractor is a genuine HARVARD-FORMATTING mistake — never a
 * cosmetically "wrong-looking" value (a misspelling, a different case, a
 * different fact) that a student could spot by eye alone without any
 * referencing knowledge, and never a value that could collide with another
 * correct part drawn in the same question:
 * - author surname  -> the SAME author's own given name (tests surname vs.
 *   given-name confusion — only the surname belongs in a reference).
 * - author initials -> the same initials with their full stops removed
 *   (tests the "initials need full stops" punctuation rule).
 * - "and"           -> "&" (tests "and", never "&", in a reference list).
 * - year            -> the year with a trailing full stop appended (tests
 *   "no full stop after the year in its parentheses").
 * - place           -> the record's own publisher name, UNLESS publisher is
 *   also drawn in this question (in which case "n.p." — a real "place not
 *   identified" notation — is used instead, so it can never duplicate a
 *   correct part). Tests place-vs-publisher confusion.
 * - publisher       -> the mirror image of place's rule, using "n.pub."
 *   when place is also drawn.
 * Title and punctuation (parentheses, colon) were tried as further
 * candidates and dropped — a wrong title or a wrong punctuation mark is
 * trivially spotted by comparing it to the scenario text, without needing
 * any actual referencing knowledge, so both are always literal now, never
 * draggable.
 *
 * The reference is modelled as an ordered TOKEN STREAM (see build_tokens())
 * alternating literal text (never draggable) and candidate slots (each with
 * a stable key, e.g. "author_1_surname", "and", "year" — a "kind" for
 * distractor generation, and its correct literal value).
 * select_parts() deterministically (crc32-seeded, same pattern as
 * Citex_Book_Mcq_Variants::variant_for()) decides which candidates get
 * drawn for one question; build() turns that decision plus the record's own
 * fields into {parts, fixedText, confusingWords} — and can be re-run by the
 * validator from the STORED selection (dragdropPartKeys) to recompute and
 * exactly compare the whole question, exactly like
 * Citex_Generated_Validator::validate_book_mcq_variant() does for MCQ.
 *
 * Only one author's name is ever split into parts per question (surname and
 * initials ALWAYS as two separate parts, never combined into one "Surname,
 * I." chunk) — every other author stays literal, folded in at its own
 * position exactly as it would appear in the full reference.
 *
 * Pure and static, no WordPress/ACF calls, exactly like
 * Citex_Reference_Rules (which this class depends on for join_people()/
 * build_reference()) and Citex_Book_Mcq_Variants — unit-testable directly.
 */
class Citex_Book_Dragdrop_Parts {

	/**
	 * The abstract "content" slots — each one, alone, satisfies the
	 * requirement that every question draw at least one real bibliographic
	 * field, not just the structural "and" chip. 'author_name' costs 2
	 * concrete parts (surname + initials together); every other slot here
	 * costs 1. Title is deliberately absent — see the class docblock.
	 *
	 * @return string[]
	 */
	private static function content_slots() {
		return array( 'author_name', 'year', 'place', 'publisher' );
	}

	/**
	 * The abstract structural slots — always cost 1 concrete part each.
	 * 'and' is only eligible when there are 2 or more authors (a single
	 * author has no joining word at all).
	 *
	 * @return string[]
	 */
	private static function structural_slots( $author_count ) {
		return $author_count >= 2 ? array( 'and' ) : array();
	}

	/**
	 * Builds the full ordered token list for one book record — every
	 * candidate slot that COULD be drawn, plus every literal character
	 * between them, in the exact order they appear in the final reference.
	 * $drawn_author_index picks which single author's surname/initials are
	 * represented as two separate candidate tokens instead of one literal
	 * "Surname, I." token — harmless to the OUTPUT even when that
	 * particular candidate ends up not selected at all (two unselected
	 * candidate tokens either side of a literal ", " render identically to
	 * one combined literal token), which is what lets build() recover the
	 * drawn index straight from a stored key list rather than needing it
	 * passed in separately.
	 *
	 * @param array $authors array<{surname, initials}>, 1 or more.
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
				$tokens[] = array( 'key' => 'author_' . $i . '_initials', 'kind' => 'author_initials', 'value' => (string) $authors[ $i ]['initials'], 'literal' => false );
			} else {
				$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => sprintf( '%s, %s', $authors[ $i ]['surname'], $authors[ $i ]['initials'] ), 'literal' => true );
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
		$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => (string) $fields['year'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ') ' . $fields['title'] . '. ', 'literal' => true );
		$tokens[] = array( 'key' => 'place', 'kind' => 'place', 'value' => (string) $fields['place'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ': ', 'literal' => true );
		$tokens[] = array( 'key' => 'publisher', 'kind' => 'publisher', 'value' => (string) $fields['publisher'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '.', 'literal' => true );
		return $tokens;
	}

	/**
	 * Deterministically, but effectively unpredictably, picks exactly which
	 * candidate keys to draw for one question, seeded by that question's
	 * own id (same crc32-seeding pattern as
	 * Citex_Book_Mcq_Variants::variant_for()):
	 * 1. Pick a drawn author index (any of count($authors), uniformly).
	 * 2. Pick a target part count, uniform in {3, 4}.
	 * 3. Pick one "seed" content slot from {author_name, year, place,
	 *    publisher} — guarantees the content floor (every question tests
	 *    at least one real bibliographic field, never only the structural
	 *    "and" chip). 'author_name' costs 2 parts; everything else costs 1.
	 * 4. Fill the remaining budget from the other eligible cost-1 slots
	 *    (the content slots not already used, plus 'and' when eligible),
	 *    deterministically ordered and taking as many as fit exactly.
	 *    'author_name' can never be picked twice — once decided as the
	 *    seed (or not), it never re-enters selection — so at most one
	 *    author's name is ever drawn per question. With only 3 other cost-1
	 *    slots available (4 for a multi-author record, since 'and' also
	 *    becomes eligible), a single-author record whose seed is NOT
	 *    'author_name' cannot reach a 4th part at all — that batch simply
	 *    settles at 3, rather than forcing a duplicate or invalid selection.
	 * 5. Returns the selected keys in REFERENCE order (not selection
	 *    order), by walking build_tokens()'s own output.
	 *
	 * @param string|int $seed    Typically the question's own id (e.g. "BK04").
	 * @param array      $authors array<{surname, initials}>, 1 or more.
	 * @return string[] ordered candidate keys.
	 */
	public static function select_parts( $seed, array $authors ) {
		$seed         = (string) $seed;
		$author_count = count( $authors );
		$drawn_index  = abs( crc32( 'book_dragdrop_author|' . $seed ) ) % max( 1, $author_count );
		$target_count = 3 + ( abs( crc32( 'book_dragdrop_count|' . $seed ) ) % 2 );

		$content_slots = self::content_slots();
		$seed_slot     = $content_slots[ abs( crc32( 'book_dragdrop_seed|' . $seed ) ) % count( $content_slots ) ];
		$seed_cost     = ( 'author_name' === $seed_slot ) ? 2 : 1;
		$remaining     = max( 0, $target_count - $seed_cost );

		$pool = array_values( array_diff( $content_slots, array( 'author_name', $seed_slot ) ) );
		$pool = array_merge( $pool, self::structural_slots( $author_count ) );
		usort(
			$pool,
			function ( $a, $b ) use ( $seed ) {
				$hash_a = crc32( 'book_dragdrop_shuffle|' . $seed . '|' . $a );
				$hash_b = crc32( 'book_dragdrop_shuffle|' . $seed . '|' . $b );
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
	 * recovered directly from $selected_keys (an "author_N_surname"/
	 * "author_N_initials" key names it) rather than needing to be passed
	 * separately — harmless when no author key is present at all (see
	 * build_tokens()'s docblock).
	 *
	 * Every selected candidate is rendered with Citex's established pipe
	 * grammar (Citex_Reference_Rules::name_template()'s docblock) — always
	 * "||", never a lone "|", since a lone "|" is only valid at the very
	 * start or end of Fixed Text (Citex_Generated_Validator::reconstruct()'s
	 * own rule) and a selected candidate here can land anywhere in the
	 * string; "||" is valid in every position, so it is used uniformly.
	 *
	 * @param string[] $selected_keys
	 * @param array    $authors array<{surname, initials, fullName}>, 1 or more.
	 * @param array    $fields  {year, title, place, publisher}.
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
		$full_name    = isset( $authors[ $drawn_index ]['fullName'] ) ? (string) $authors[ $drawn_index ]['fullName'] : '';

		$parts     = array();
		$confusing = array();
		$fixed     = '';
		foreach ( $tokens as $token ) {
			if ( $token['literal'] ) {
				$fixed .= $token['value'];
				continue;
			}
			if ( isset( $selected_set[ $token['key'] ] ) ) {
				$fixed      .= '||';
				$parts[]     = $token['value'];
				$confusing[] = self::distractor_for( $token['kind'], $token['value'], $full_name, $fields, $selected_set );
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
	 * "kind" — never Gemini-authored, so there is nothing left for a
	 * quality gate to sanity-check; the validator can recompute and
	 * exact-match these exactly like every other part of the question.
	 * Every case here is a genuine Harvard-formatting mistake, never a
	 * cosmetically different value (see the class docblock) — see the
	 * case comments for the specific rule each one tests.
	 */
	private static function distractor_for( $kind, $value, $full_name, array $fields, array $selected_set ) {
		switch ( $kind ) {
			case 'author_surname':
				// Tests surname-vs-given-name confusion: only the surname
				// belongs in a Harvard reference. Falls back to a synthetic
				// marker (never real generated data lacks a full name — see
				// Citex_AI_V2::derive_author_parts()'s own requirement for
				// one) rather than silently matching the correct surname.
				$given = self::given_name_portion( $full_name, $value );
				return ( '' !== $given && $given !== $value ) ? $given : $value . "'s";
			case 'author_initials':
				// Tests "initials must carry a full stop after each letter".
				$stripped = str_replace( '.', '', $value );
				return ( '' !== $stripped && $stripped !== $value ) ? $stripped : $value . "'";
			case 'and':
				// Tests "authors are joined with 'and', never '&'".
				return '&';
			case 'year':
				// Tests "no full stop after the year inside its parentheses".
				return $value . '.';
			case 'place':
				// Tests place-vs-publisher confusion — falls back to a real
				// "place not identified" notation instead when publisher is
				// ALSO drawn in this question, so it can never duplicate
				// that correct part.
				return isset( $selected_set['publisher'] ) ? 'n.p.' : (string) $fields['publisher'];
			case 'publisher':
				// Mirror image of 'place', using "publisher not identified".
				return isset( $selected_set['place'] ) ? 'n.pub.' : (string) $fields['place'];
			default:
				return $value . '?';
		}
	}

	/**
	 * Extracts the given-name portion of a full name once its surname is
	 * known — e.g. ("Andrew Brown", "Brown") -> "Andrew" — same technique as
	 * Citex_Book_Mcq_Variants::given_name_portion(), duplicated here to keep
	 * this file self-contained. Every author full name reaching this method
	 * is guaranteed by Citex_AI_V2::derive_author_parts() to contain a real
	 * given name (a surname-only name is rejected at generation time), so
	 * this never degrades to returning the surname itself.
	 */
	private static function given_name_portion( $full_name, $surname ) {
		$full_name = trim( (string) $full_name );
		$surname   = trim( (string) $surname );
		if ( '' !== $surname && '' !== $full_name && strlen( $full_name ) > strlen( $surname )
			&& 0 === strcasecmp( substr( $full_name, -strlen( $surname ) ), $surname ) ) {
			return trim( substr( $full_name, 0, strlen( $full_name ) - strlen( $surname ) ) );
		}
		$words = preg_split( '/\s+/', $full_name );
		if ( count( $words ) > 1 ) {
			array_pop( $words );
			return implode( ' ', $words );
		}
		return '' !== $full_name ? $full_name : $surname;
	}
}
