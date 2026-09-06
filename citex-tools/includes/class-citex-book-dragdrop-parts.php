<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Book DragDrop's dynamic 3-4-part question builder — replaces the fixed
 * 8-design catalogue (Citex_Reference_Rules::book_dragdrop_designs() and
 * friends, now removed) with a genuinely dynamic system: every question
 * draws a random subset of 3-4 "parts" from a pool of author name, year,
 * title, place, publisher, and the joining word "and" — and every wrong
 * "distractor" chip is authored deterministically by Citex, never Gemini
 * (mirrors the Book MCQ variant overhaul's philosophy: Gemini supplies only
 * the canonical record; Citex authors the entire student-facing question
 * from it).
 *
 * Every distractor tests genuine HARVARD-REFERENCING knowledge — never a
 * cosmetic, trivially-spotted difference (a random misspelling, a different
 * case, an unrelated fact) — and never a value that could collide with
 * another correct part drawn in the same question:
 * - author surname  -> either the SAME author's own given name (surname vs.
 *   given-name confusion), or — when a second author exists — that OTHER
 *   author's surname (tests "which author does this name belong to"),
 *   rotating deterministically per record.
 * - author initials -> either the same initials with their full stops
 *   removed (tests "initials need full stops"), or — when a second author
 *   exists — that OTHER author's initials (correct format, wrong author),
 *   rotating deterministically per record.
 * - "and"           -> "&" (tests "and", never "&", in a reference list).
 * - year            -> either a nearby wrong year (tests the student
 *   actually knows the real year, not just its shape), or the year with a
 *   punctuation mistake (a stray full stop or parenthesis attached to the
 *   bare year — tests that the surrounding parentheses are FIXED text, not
 *   part of the draggable year itself), rotating deterministically per
 *   record.
 * - title           -> a genuine title-boundary or title-content mistake:
 *   trailing punctuation wrongly attached, the year wrongly folded into the
 *   title, or a subtle wording alteration — never a random misspelling.
 * - place           -> a real, globally recognised place of publication
 *   drawn from a fixed pool spanning multiple countries (never limited to
 *   the UK), excluding the record's own place and, when publisher is also
 *   drawn, the record's own publisher too.
 * - publisher       -> the mirror image of place's rule: a real, globally
 *   recognised academic publisher drawn from a fixed pool, excluding the
 *   record's own publisher and, when place is also drawn, the record's own
 *   place too.
 * Punctuation characters themselves ( `(` `)` `:` ) are never draggable —
 * they always stay literal/fixed text, per the class's own established
 * convention; a "punctuation mistake" distractor (year's flavour above)
 * is a self-contained wrong STRING occupying the year's own blank, not a
 * change to the surrounding fixed literal punctuation.
 *
 * The reference is modelled as an ordered TOKEN STREAM (see build_tokens())
 * alternating literal text (never draggable) and candidate slots (each with
 * a stable key, e.g. "author_1_surname", "and", "year", "title" — a "kind"
 * for distractor generation, and its correct literal value).
 * select_parts() deterministically (crc32-seeded, same pattern as
 * Citex_Book_Mcq_Variants::variant_for()) decides which candidates get
 * drawn for one question; build() turns that decision plus the record's own
 * fields into {parts, fixedText, confusingWords} — and can be re-run by the
 * validator from the STORED selection (dragdropPartKeys) to recompute and
 * exactly compare the whole question, exactly like
 * Citex_Generated_Validator::validate_book_mcq_variant() does for MCQ.
 * Every distractor flavour rotation is itself derived purely from the
 * record's own fields (never from an external seed build() doesn't
 * receive), so the validator's recomputation always matches exactly.
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
	 * costs 1.
	 *
	 * @return string[]
	 */
	private static function content_slots() {
		return array( 'author_name', 'year', 'title', 'place', 'publisher' );
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
	 * A fixed pool of real, globally recognised places of publication —
	 * deliberately spanning multiple countries/continents, not just the
	 * UK, so a place distractor is always a genuinely plausible city a
	 * student might mistake for the real one.
	 *
	 * @return string[]
	 */
	private static function place_pool() {
		return array(
			'London', 'Oxford', 'Cambridge', 'Manchester', 'Edinburgh', 'Dublin',
			'New York', 'Boston', 'Chicago', 'San Francisco', 'Toronto', 'Vancouver',
			'Sydney', 'Melbourne', 'Singapore', 'Delhi', 'Mumbai', 'Tokyo',
			'Paris', 'Berlin', 'Amsterdam', 'Cape Town',
		);
	}

	/**
	 * A fixed pool of real, globally recognised academic publishers — used
	 * for the publisher distractor the same way place_pool() is used for
	 * place.
	 *
	 * @return string[]
	 */
	private static function publisher_pool() {
		return array(
			'Routledge', 'Pearson', 'SAGE', 'Palgrave Macmillan', 'Oxford University Press',
			'Cambridge University Press', 'Wiley', 'Wiley-Blackwell', 'Springer', 'Elsevier',
			'Taylor & Francis', 'Bloomsbury', 'McGraw-Hill', 'Harvard University Press',
			'Yale University Press', 'University of Chicago Press',
		);
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
	 * own id (same crc32-seeding pattern as
	 * Citex_Book_Mcq_Variants::variant_for()):
	 * 1. Pick a drawn author index (any of count($authors), uniformly).
	 * 2. Pick a target part count, uniform in {3, 4}.
	 * 3. Pick one "seed" content slot from {author_name, year, title,
	 *    place, publisher} — guarantees the content floor (every question
	 *    tests at least one real bibliographic field, never only the
	 *    structural "and" chip). 'author_name' costs 2 parts; everything
	 *    else costs 1.
	 * 4. Fill the remaining budget from the other eligible cost-1 slots
	 *    (the content slots not already used, plus 'and' when eligible),
	 *    deterministically ordered and taking as many as fit exactly.
	 *    'author_name' can never be picked twice — once decided as the
	 *    seed (or not), it never re-enters selection — so at most one
	 *    author's name is ever drawn per question.
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
	 * grammar (Citex_Reference_Rules::name_template()'s docblock): a lone
	 * "|" only when NOTHING at all precedes it (the very beginning of Fixed
	 * Text) or NOTHING at all follows it (the very end) — "||" for every
	 * other, mid-string position. In practice only the very FIRST selected
	 * candidate can ever qualify (whichever author is drawn always starts
	 * the reference, so it is the only candidate that can ever sit at
	 * position 0), never the last: Fixed Text always ends with a literal
	 * final full stop after 'publisher' (see build_tokens()), so something
	 * always follows the last selected candidate — the "end" case is
	 * checked for correctness/symmetry but structurally can never fire here.
	 *
	 * Every distractor flavour (which author-mix-up variant, which year
	 * mistake, which title mistake, which pool entries are eligible) is
	 * derived purely from this record's OWN fields (see $record_seed
	 * below) — never from an external per-call seed — so the validator's
	 * recomputation from the same stored fields always matches exactly.
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
		$token_count  = count( $tokens );
		$record_seed  = implode( '|', array( (string) $fields['year'], (string) $fields['title'], (string) $fields['place'], (string) $fields['publisher'] ) );

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
				$confusing[] = self::distractor_for( $token['kind'], $token['value'], $full_name, $fields, $selected_set, $authors, $drawn_index, $record_seed );
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
	 */
	private static function distractor_for( $kind, $value, $full_name, array $fields, array $selected_set, array $authors, $drawn_index, $record_seed ) {
		switch ( $kind ) {
			case 'author_surname':
				return self::author_surname_distractor( $value, $full_name, $authors, $drawn_index, $record_seed );
			case 'author_initials':
				return self::author_initials_distractor( $value, $authors, $drawn_index, $record_seed );
			case 'and':
				// Tests "authors are joined with 'and', never '&'".
				return '&';
			case 'year':
				return self::year_distractor( $value, $record_seed );
			case 'title':
				return self::title_distractor( $value, $fields, $record_seed );
			case 'place':
				return self::place_distractor( $value, $fields, $selected_set, $record_seed );
			case 'publisher':
				return self::publisher_distractor( $value, $fields, $selected_set, $record_seed );
			default:
				return $value . '?';
		}
	}

	/**
	 * The drawn author's surname distractor. Rotates deterministically
	 * (per record) between two genuine referencing-knowledge tests:
	 * - surname-vs-given-name confusion (single-author fallback), and
	 * - attributing the OTHER author's surname to this position (tests
	 *   "which author does this name actually belong to" — only eligible
	 *   with 2+ authors).
	 */
	private static function author_surname_distractor( $value, $full_name, array $authors, $drawn_index, $record_seed ) {
		$other = self::other_author( $authors, $drawn_index );
		if ( null !== $other && 0 === ( abs( crc32( 'book_dragdrop_surname_flavor|' . $record_seed ) ) % 2 )
			&& 0 !== strcasecmp( (string) $other['surname'], $value ) ) {
			return (string) $other['surname'];
		}
		$given = self::given_name_portion( $full_name, $value );
		return ( '' !== $given && $given !== $value ) ? $given : $value . "'s";
	}

	/**
	 * The drawn author's initials distractor. Rotates deterministically
	 * (per record) between:
	 * - "initials need full stops" (single-author fallback), and
	 * - attributing the OTHER author's initials to this position (correct
	 *   FORMAT, wrong author — only eligible with 2+ authors).
	 */
	private static function author_initials_distractor( $value, array $authors, $drawn_index, $record_seed ) {
		$other = self::other_author( $authors, $drawn_index );
		if ( null !== $other && 0 === ( abs( crc32( 'book_dragdrop_initials_flavor|' . $record_seed ) ) % 2 )
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

	/**
	 * The year distractor. Rotates deterministically (per record) between:
	 * - a nearby wrong year (year +/- 1) — tests that the student actually
	 *   knows the real publication year, not just its shape, and
	 * - a punctuation mistake attached to the bare year (a stray full stop
	 *   or parenthesis) — tests that the surrounding parentheses belong to
	 *   the FIXED text, never to the draggable year chip itself.
	 */
	private static function year_distractor( $value, $record_seed ) {
		$numeric = ctype_digit( $value ) ? (int) $value : null;
		if ( null !== $numeric && 0 === ( abs( crc32( 'book_dragdrop_year_flavor|' . $record_seed ) ) % 2 ) ) {
			$delta     = ( 0 === ( abs( crc32( 'book_dragdrop_year_delta|' . $record_seed ) ) % 2 ) ) ? -1 : 1;
			$candidate = (string) ( $numeric + $delta );
			if ( $candidate !== $value ) {
				return $candidate;
			}
		}
		$variants = array( $value . '.', '(' . $value, $value . ')' );
		$pick     = $variants[ abs( crc32( 'book_dragdrop_year_punct|' . $record_seed ) ) % count( $variants ) ];
		return $pick !== $value ? $pick : $value . '.';
	}

	/**
	 * The title distractor. Rotates deterministically (per record) between
	 * four genuine title-boundary/content mistakes — never a random
	 * character-level misspelling:
	 * - a full stop wrongly attached to the title itself (that full stop
	 *   belongs to the FIXED text after it, not the draggable title chip),
	 * - a comma wrongly attached the same way,
	 * - the year wrongly folded into the title chip (tests the title/year
	 *   boundary), and
	 * - a subtle wording alteration (a plural/singular flip on the title's
	 *   last word) — tests actual attentiveness to the real title, not
	 *   just its rough shape.
	 */
	private static function title_distractor( $value, array $fields, $record_seed ) {
		$flavor = abs( crc32( 'book_dragdrop_title_flavor|' . $record_seed ) ) % 4;
		if ( 0 === $flavor ) {
			$candidate = $value . '.';
		} elseif ( 1 === $flavor ) {
			$candidate = $value . ',';
		} elseif ( 2 === $flavor ) {
			$candidate = $value . ' (' . (string) $fields['year'] . ')';
		} else {
			$words = preg_split( '/\s+/', trim( $value ) );
			$last  = array_pop( $words );
			if ( null === $last ) {
				$last = '';
			}
			if ( '' !== $last && 's' === strtolower( substr( $last, -1 ) ) ) {
				$last = substr( $last, 0, -1 );
			} else {
				$last .= 's';
			}
			$words[]   = $last;
			$candidate = trim( implode( ' ', $words ) );
		}
		return ( '' !== $candidate && $candidate !== $value ) ? $candidate : $value . '.';
	}

	/**
	 * The place distractor — a real, globally recognised city drawn from
	 * place_pool(), excluding the record's own place and (when publisher
	 * is also drawn this question) the record's own publisher too, so it
	 * can never duplicate another correct part.
	 */
	private static function place_distractor( $value, array $fields, array $selected_set, $record_seed ) {
		$exclude = array( $value );
		if ( isset( $selected_set['publisher'] ) ) {
			$exclude[] = $fields['publisher'];
		}
		$pick = self::pick_from_pool( self::place_pool(), $exclude, 'book_dragdrop_place_pool|' . $record_seed );
		return null !== $pick ? $pick : 'n.p.';
	}

	/**
	 * The publisher distractor — the mirror image of place_distractor(),
	 * drawing from publisher_pool().
	 */
	private static function publisher_distractor( $value, array $fields, array $selected_set, $record_seed ) {
		$exclude = array( $value );
		if ( isset( $selected_set['place'] ) ) {
			$exclude[] = $fields['place'];
		}
		$pick = self::pick_from_pool( self::publisher_pool(), $exclude, 'book_dragdrop_publisher_pool|' . $record_seed );
		return null !== $pick ? $pick : 'n.pub.';
	}

	/**
	 * Deterministically (crc32-seeded) picks one entry from $pool, having
	 * removed every value in $exclude (case-insensitively) first. Returns
	 * null only if every pool entry was excluded.
	 *
	 * @param string[] $pool
	 * @param string[] $exclude
	 * @param string   $seed_key
	 * @return string|null
	 */
	private static function pick_from_pool( array $pool, array $exclude, $seed_key ) {
		$exclude_lower = array_map( 'strtolower', array_map( 'strval', $exclude ) );
		$eligible      = array_values(
			array_filter(
				$pool,
				function ( $candidate ) use ( $exclude_lower ) {
					return ! in_array( strtolower( $candidate ), $exclude_lower, true );
				}
			)
		);
		if ( empty( $eligible ) ) {
			return null;
		}
		usort(
			$eligible,
			function ( $a, $b ) use ( $seed_key ) {
				$hash_a = crc32( $seed_key . '|' . $a );
				$hash_b = crc32( $seed_key . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);
		return $eligible[0];
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
