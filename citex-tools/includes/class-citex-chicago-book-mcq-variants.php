<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chicago (Author-Date) Book's own fixed catalogue of MCQ "variant"
 * templates — mirrors Citex_Book_Mcq_Variants/Citex_MLA_Book_Mcq_Variants/
 * Citex_APA_Book_Mcq_Variants exactly (same "Citex authors the ENTIRE
 * question deterministically from one canonical record; Gemini supplies
 * nothing beyond that record" principle, same crc32-seeded per-question
 * variant selection, same exact-match validator story).
 *
 * A deliberately CURATED 8-variant catalogue, chosen to cover Chicago's own
 * distinctive rules (the FULL given name — never an initial; two or more
 * authors always joined with "and" preceded by a comma, even at exactly
 * two; every author always listed in full, "et al." never used at any
 * count this app generates; no parentheses around the year at all, just its
 * own trailing full stop; place of publication IS kept) — several
 * distractors deliberately swap in ANOTHER real style's own rule as the
 * wrong answer (Harvard's plain "and" with no comma, APA's "&", MLA's
 * "only the first author inverted"), mirroring the established
 * cross-style distractor design already used by MLA-vs-Harvard and
 * APA-vs-Harvard/MLA.
 *
 * 'two_author_joining' requires exactly 2 authors; 'three_or_more_author_joining'
 * requires 3 or more — every other variant works with any author count (see
 * variant_author_requirement()).
 *
 * Pure and static, no WordPress/ACF calls, exactly like
 * Citex_Reference_Rules/Citex_Chicago_Reference_Rules and
 * Citex_Book_Mcq_Variants/Citex_MLA_Book_Mcq_Variants/Citex_APA_Book_Mcq_Variants —
 * unit-testable directly.
 */
class Citex_Chicago_Book_Mcq_Variants {

	/**
	 * Variants whose `correctAnswer` and `wrongOptions` are entirely
	 * fixed/static, independent of this specific record's own
	 * author/title/place/publisher/year — must be skipped by
	 * duplicate-reference detection, or a second question landing on the
	 * same variant always looks like a duplicate.
	 *
	 * @return string[] variant ids.
	 */
	public static function chicago_book_independent_answer_variants() {
		return array( 'reference_structure' );
	}

	/**
	 * @return string[] the variant ids.
	 */
	public static function variants() {
		return array(
			'complete_reference',
			'author_name_format',
			'two_author_joining',
			'three_or_more_author_joining',
			'reference_structure',
			'year_punctuation',
			'identify_the_error',
			'not_a_correct_reference',
		);
	}

	/**
	 * The exact author count a variant requires — [min, max] inclusive — or
	 * null when the variant works with any author count.
	 *
	 * @return array{0:int,1:int}|null
	 */
	public static function variant_author_requirement( $variant ) {
		$map = array(
			'two_author_joining'           => array( 2, 2 ),
			'three_or_more_author_joining' => array( 3, PHP_INT_MAX ),
		);
		return $map[ $variant ] ?? null;
	}

	/**
	 * Deterministically, but effectively unpredictably, picks one
	 * author-count-compatible variant per generated question, seeded by
	 * that question's own id — same crc32-seeding pattern as
	 * Citex_Book_Mcq_Variants::variant_for()/Citex_MLA_Book_Mcq_Variants::variant_for()/
	 * Citex_APA_Book_Mcq_Variants::variant_for().
	 *
	 * @param string|int $seed         Typically the question's own id (e.g. "CB04").
	 * @param int        $author_count The real number of authors this question's record has.
	 * @return string variant id.
	 */
	public static function variant_for( $seed, $author_count ) {
		$compatible = array();
		foreach ( self::variants() as $variant ) {
			$bounds = self::variant_author_requirement( $variant );
			if ( null === $bounds || ( $author_count >= $bounds[0] && $author_count <= $bounds[1] ) ) {
				$compatible[] = $variant;
			}
		}
		if ( empty( $compatible ) ) {
			$compatible = array( 'complete_reference' );
		}
		$index = abs( crc32( 'chicago_book_mcq_variant|' . (string) $seed ) ) % count( $compatible );
		return $compatible[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical Chicago book
	 * record.
	 *
	 * @param string $variant One of self::variants().
	 * @param array  $fields  {authors: array<{surname, givenName, fullName}>, title, place, publisher, year}.
	 * @return array{stem: string, wrongOptions: string[], correctAnswer: string}|null null for an unrecognised variant id.
	 */
	public static function build( $variant, array $fields ) {
		switch ( $variant ) {
			case 'complete_reference':
				return self::build_complete_reference( $fields );
			case 'author_name_format':
				return self::build_author_name_format( $fields );
			case 'two_author_joining':
				return self::build_two_author_joining( $fields );
			case 'three_or_more_author_joining':
				return self::build_three_or_more_author_joining( $fields );
			case 'reference_structure':
				return self::build_reference_structure( $fields );
			case 'year_punctuation':
				return self::build_year_punctuation( $fields );
			case 'identify_the_error':
				return self::build_identify_the_error( $fields );
			case 'not_a_correct_reference':
				return self::build_not_a_correct_reference( $fields );
		}
		return null;
	}

	// -----------------------------------------------------------------
	// Shared helpers.
	// -----------------------------------------------------------------

	private static function correct_reference( array $fields ) {
		return Citex_Chicago_Reference_Rules::build_reference( Citex_Chicago_Reference_Rules::CATEGORY_BOOK, $fields );
	}

	/** Builds the full reference from an explicit author-list segment, mirroring Citex_Chicago_Reference_Rules::build_book_reference()'s own shape. $author_segment is expected to already end in its own full stop, exactly like join_people()'s own output. */
	private static function full_reference( $author_segment, $year, $title, $place, $publisher ) {
		return sprintf( '%s %s. %s. %s: %s.', $author_segment, $year, $title, $place, $publisher );
	}

	/**
	 * The first author's segment rendered with an INITIAL instead of their
	 * full given name — reruns join_people() itself on a copy of $authors
	 * with just the first person's given name shortened, so the ONE
	 * trailing full stop join_people() itself adds lands in the right place
	 * (never doubled up against an abbreviation dot of our own).
	 */
	private static function author_segment_initial( array $authors ) {
		$modified = $authors;
		$letter   = mb_substr( trim( (string) $modified[0]['givenName'] ), 0, 1 );
		if ( '' !== $letter ) {
			$modified[0]['givenName'] = mb_strtoupper( $letter );
		}
		return Citex_Chicago_Reference_Rules::join_people( $modified );
	}

	/**
	 * The 5 structural error kinds shared by identify_the_error and
	 * not_a_correct_reference — each pairs a plain-English statement of what
	 * is wrong with a transform that injects exactly that mistake into an
	 * otherwise-correct reference. Every kind works at any author count
	 * (none depend on there being 2+ authors), mirroring
	 * Citex_APA_Book_Mcq_Variants::error_kinds()'s own count-agnostic
	 * design.
	 *
	 * @return string[] error kind ids, in a fixed order.
	 */
	private static function error_kinds() {
		return array( 'author_not_full_given_name', 'year_wrongly_parenthesised', 'year_missing_period', 'title_period_replaced_by_comma', 'missing_final_period' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'author_not_full_given_name'     => 'The author\'s given name is abbreviated to an initial instead of shown in full.',
			'year_wrongly_parenthesised'      => 'The year is wrongly enclosed in parentheses instead of standing on its own.',
			'year_missing_period'             => 'The full stop after the year is missing.',
			'title_period_replaced_by_comma'  => 'The full stop after the title is replaced with a comma.',
			'missing_final_period'            => 'The reference is missing its final full stop.',
		);
		return $statements[ $kind ] ?? '';
	}

	/** Builds a reference with exactly one structural mistake injected, per $kind. */
	private static function broken_reference( $kind, array $authors, $title, $place, $publisher, $year ) {
		$author_segment = Citex_Chicago_Reference_Rules::join_people( $authors );
		switch ( $kind ) {
			case 'author_not_full_given_name':
				return self::full_reference( self::author_segment_initial( $authors ), $year, $title, $place, $publisher );
			case 'year_wrongly_parenthesised':
				return sprintf( '%s (%s). %s. %s: %s.', $author_segment, $year, $title, $place, $publisher );
			case 'year_missing_period':
				// Deliberately drop the period after the year (the space
				// before the title is kept, so this reads as a genuine
				// missing-punctuation mistake, not a missing-space one).
				return sprintf( '%s %s %s. %s: %s.', $author_segment, $year, $title, $place, $publisher );
			case 'title_period_replaced_by_comma':
				return sprintf( '%s %s. %s, %s: %s.', $author_segment, $year, $title, $place, $publisher );
			case 'missing_final_period':
				return rtrim( self::full_reference( $author_segment, $year, $title, $place, $publisher ), '.' );
		}
		return self::full_reference( $author_segment, $year, $title, $place, $publisher );
	}

	/** Deterministically picks one error kind, seeded by the record's own content. */
	private static function pick_error_kind( $record_seed ) {
		$kinds = self::error_kinds();
		return $kinds[ abs( crc32( 'chicago_book_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	/**
	 * A small, fixed pool of ready-made, fully self-contained, correctly
	 * formatted Chicago book references for DIFFERENT invented books — used
	 * only by not_a_correct_reference, where the question needs 3 options
	 * that are genuinely valid (not distractors to be broken) alongside one
	 * flawed reference for the record actually being asked about. Spans
	 * 1/2/3-author examples so the pool itself models every count.
	 *
	 * @return string[] 6 ready-built, correctly-formatted references.
	 */
	private static function valid_reference_pool() {
		return array(
			self::full_reference( 'Bennett, Clara.', '2018', 'The Silent Ocean', 'London', 'Faber' ),
			self::full_reference( 'Diaz, Marco, and Priya Nair.', '2019', 'Modern Economies', 'New York', 'Wiley' ),
			self::full_reference( 'Chen, Wei, George Okafor, and Tom Bishop.', '2020', 'Machine Learning Basics', 'Cambridge', 'MIT Press' ),
			self::full_reference( 'Okafor, George.', '2017', 'Urban Planning Today', 'Abingdon', 'Routledge' ),
			self::full_reference( 'Novak, Sofia, and Tom Bishop.', '2022', 'Climate Futures', 'Berlin', 'Springer' ),
			self::full_reference( 'Ibrahim, Yara, Wei Chen, and Marco Diaz.', '2021', 'Global Health Policy', 'Thousand Oaks', 'SAGE' ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 1 — Complete Reference (the baseline mechanic).
	// -----------------------------------------------------------------
	private static function build_complete_reference( array $fields ) {
		$authors = $fields['authors'];
		$correct = self::correct_reference( $fields );
		return array(
			'stem'          => 'Which option is the correctly formatted book reference?',
			'wrongOptions'  => array(
				self::broken_reference( 'author_not_full_given_name', $authors, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] ),
				self::broken_reference( 'year_wrongly_parenthesised', $authors, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] ),
				self::broken_reference( 'year_missing_period', $authors, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 2 — Author Name Format (fragment only, not a whole
	// reference — the FIRST author only, for any author count). The single
	// most Chicago-distinctive rule (shared with MLA, opposite of
	// Harvard/APA): the FULL given name, never an initial.
	// -----------------------------------------------------------------
	private static function build_author_name_format( array $fields ) {
		$person = $fields['authors'][0];
		return array(
			'stem'          => "Which option correctly formats the author's name for the Chicago (Author-Date) reference list?",
			'wrongOptions'  => array(
				sprintf( '%s, %s.', $person['surname'], mb_strtoupper( mb_substr( trim( (string) $person['givenName'] ), 0, 1 ) ) ),
				sprintf( '%s %s', $person['givenName'], $person['surname'] ),
				sprintf( '%s %s.', $person['surname'], $person['givenName'] ),
			),
			'correctAnswer' => sprintf( '%s, %s.', $person['surname'], $person['givenName'] ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 3 — Two-Author Joining (exactly 2 authors required). Chicago
	// keeps a comma before "and" even at exactly 2 — unlike Harvard, which
	// uses a plain "and" with no comma there — so that comma is itself
	// worth testing directly.
	// -----------------------------------------------------------------
	private static function build_two_author_joining( array $fields ) {
		$authors = $fields['authors'];
		$first   = $authors[0];
		$second  = $authors[1];
		$correct = Citex_Chicago_Reference_Rules::join_people( $authors );
		return array(
			'stem'          => 'Which option correctly joins two authors for the reference list?',
			'wrongOptions'  => array(
				sprintf( '%s, %s and %s, %s.', $first['surname'], $first['givenName'], $second['surname'], $second['givenName'] ),
				sprintf( '%s, %s, & %s, %s.', $first['surname'], $first['givenName'], $second['surname'], $second['givenName'] ),
				sprintf( '%s, %s, and %s %s.', $first['surname'], $first['givenName'], $second['givenName'], $second['surname'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 4 — Three-or-More Author Joining (3+ authors required). Every
	// author is always listed in full with "and" before the last, preceded
	// by a comma — the wrong options each swap in a DIFFERENT real style's
	// own rule: Harvard's plain "and" (no comma before it), MLA's "et al."
	// (only the first author inverted, the rest dropped), and APA's "&".
	// -----------------------------------------------------------------
	private static function build_three_or_more_author_joining( array $fields ) {
		$authors        = $fields['authors'];
		$first           = $authors[0];
		$correct         = Citex_Chicago_Reference_Rules::join_people( $authors );
		$harvard_style   = Citex_Reference_Rules::join_people( self::as_harvard_shaped( $authors ) );
		$mla_style       = sprintf( '%s, %s, et al.', $first['surname'], $first['givenName'] );
		$segments        = array();
		foreach ( $authors as $person ) {
			$segments[] = sprintf( '%s, %s', $person['surname'], $person['givenName'] );
		}
		$apa_style = implode( ', ', array_slice( $segments, 0, -1 ) ) . ', & ' . end( $segments ) . '.';
		return array(
			'stem'          => 'Which option correctly names three or more authors for the reference list?',
			'wrongOptions'  => array(
				// Harvard's own join_people() already ends the last segment
				// in its initial's own abbreviation period ("Lee, K.") — no
				// further period is appended, or it would read "K..".
				$harvard_style,
				$mla_style,
				$apa_style,
			),
			'correctAnswer' => $correct,
		);
	}

	/** Reshapes {surname, givenName} people into Harvard's own {surname, initials} shape, purely so Citex_Reference_Rules::join_people() can render the Harvard-style comparison string. */
	private static function as_harvard_shaped( array $authors ) {
		return array_map(
			function ( $person ) {
				$letter = mb_substr( trim( (string) $person['givenName'] ), 0, 1 );
				return array( 'surname' => $person['surname'], 'initials' => '' !== $letter ? mb_strtoupper( $letter ) . '.' : '' );
			},
			$authors
		);
	}

	// -----------------------------------------------------------------
	// Variant 5 — Reference Structure. Fully static — no record data at all
	// (see chicago_book_independent_answer_variants()).
	// -----------------------------------------------------------------
	private static function build_reference_structure( array $fields ) {
		return array(
			'stem'          => 'Which option shows the correct order of the main Book reference elements?',
			'wrongOptions'  => array(
				'Author → Title → Year → Place: Publisher',
				'Year → Author → Title → Place: Publisher',
				'Author → Place: Publisher → Year → Title',
			),
			'correctAnswer' => 'Author → Year → Title → Place: Publisher',
		);
	}

	// -----------------------------------------------------------------
	// Variant 6 — Year Punctuation. Tests the single most
	// Chicago-distinctive structural quirk: NO parentheses around the year
	// at all, just its own trailing full stop — the opposite of both
	// Harvard's and APA's own Book format.
	// -----------------------------------------------------------------
	private static function build_year_punctuation( array $fields ) {
		$author_segment = Citex_Chicago_Reference_Rules::join_people( $fields['authors'] );
		return array(
			'stem'          => 'Which option correctly punctuates the year for the reference list?',
			'wrongOptions'  => array(
				sprintf( '%s (%s). %s. %s: %s.', $author_segment, $fields['year'], $fields['title'], $fields['place'], $fields['publisher'] ),
				sprintf( '%s %s, %s. %s: %s.', $author_segment, $fields['year'], $fields['title'], $fields['place'], $fields['publisher'] ),
				sprintf( '%s %s %s. %s: %s.', $author_segment, $fields['year'], $fields['title'], $fields['place'], $fields['publisher'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 7 — Identify the Error (one deliberate mistake shown; pick the
	// statement that correctly names it).
	// -----------------------------------------------------------------
	private static function build_identify_the_error( array $fields ) {
		$authors           = $fields['authors'];
		$record_seed       = implode( '|', array( $fields['title'], $fields['place'], $fields['publisher'], $fields['year'], (string) count( $authors ) ) );
		$kind              = self::pick_error_kind( $record_seed );
		$broken            = self::broken_reference( $kind, $authors, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] );
		$correct_statement = self::error_statement( $kind );

		$wrong_statements = array();
		foreach ( self::error_kinds() as $other_kind ) {
			if ( $other_kind !== $kind ) {
				$wrong_statements[] = self::error_statement( $other_kind );
			}
		}
		$wrong_statements = array_slice( $wrong_statements, 0, 3 );

		return array(
			'stem'          => sprintf( "Which option correctly identifies the error in this reference?\n\n%s", $broken ),
			'wrongOptions'  => $wrong_statements,
			'correctAnswer' => $correct_statement,
		);
	}

	// -----------------------------------------------------------------
	// Variant 8 — "Which is NOT a correct reference?" correctAnswer is the
	// ONE FLAWED reference; wrongOptions are 3 genuinely, independently
	// VALID references for different invented books.
	// -----------------------------------------------------------------
	private static function build_not_a_correct_reference( array $fields ) {
		$authors     = $fields['authors'];
		$record_seed = implode( '|', array( $fields['title'], $fields['place'], $fields['publisher'], $fields['year'], (string) count( $authors ) ) );
		$kind        = self::pick_error_kind( $record_seed . '|not_correct' );
		$broken      = self::broken_reference( $kind, $authors, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] );

		$pool = self::valid_reference_pool();
		usort(
			$pool,
			function ( $a, $b ) use ( $record_seed ) {
				$hash_a = crc32( 'chicago_book_mcq_not_correct_pool|' . $record_seed . '|' . $a );
				$hash_b = crc32( 'chicago_book_mcq_not_correct_pool|' . $record_seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);

		return array(
			'stem'          => 'Which of the following is NOT a correct reference for a book?',
			'wrongOptions'  => array_slice( $pool, 0, 3 ),
			'correctAnswer' => $broken,
		);
	}
}
