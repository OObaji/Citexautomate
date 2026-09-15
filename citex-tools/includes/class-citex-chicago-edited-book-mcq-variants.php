<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chicago (Author-Date) Edited Book's own fixed catalogue of MCQ "variant"
 * templates — mirrors Citex_Chicago_Book_Mcq_Variants exactly (same "Citex
 * authors the ENTIRE question deterministically from one canonical record"
 * principle), but swaps Book's own 'reference_structure' variant for
 * 'designation_singular_plural' — this category's own defining rule
 * (whether "ed" or "eds" is used) — mirroring
 * Citex_APA_Edited_Book_Mcq_Variants's own equivalent swap.
 *
 * 'two_editor_joining' requires exactly 2 editors; 'three_or_more_editor_joining'
 * requires 3 or more — every other variant works with any editor count.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_Chicago_Edited_Book_Mcq_Variants {

	/**
	 * Variants whose `correctAnswer` is entirely fixed/static (one of only 2
	 * possible strings, "ed." or "eds."), independent of this specific
	 * record's own editor/title/place/publisher/year content — must be
	 * skipped by duplicate-reference detection.
	 *
	 * @return string[] variant ids.
	 */
	public static function chicago_edited_book_independent_answer_variants() {
		return array( 'designation_singular_plural' );
	}

	/**
	 * @return string[] the variant ids.
	 */
	public static function variants() {
		return array(
			'complete_reference',
			'editor_name_format',
			'two_editor_joining',
			'three_or_more_editor_joining',
			'designation_singular_plural',
			'year_punctuation',
			'identify_the_error',
			'not_a_correct_reference',
		);
	}

	/**
	 * The exact editor count a variant requires — [min, max] inclusive — or
	 * null when the variant works with any editor count.
	 *
	 * @return array{0:int,1:int}|null
	 */
	public static function variant_editor_requirement( $variant ) {
		$map = array(
			'two_editor_joining'           => array( 2, 2 ),
			'three_or_more_editor_joining' => array( 3, PHP_INT_MAX ),
		);
		return $map[ $variant ] ?? null;
	}

	/**
	 * @param string|int $seed         Typically the question's own id (e.g. "CE04").
	 * @param int        $editor_count The real number of editors this question's record has.
	 * @return string variant id.
	 */
	public static function variant_for( $seed, $editor_count ) {
		$compatible = array();
		foreach ( self::variants() as $variant ) {
			$bounds = self::variant_editor_requirement( $variant );
			if ( null === $bounds || ( $editor_count >= $bounds[0] && $editor_count <= $bounds[1] ) ) {
				$compatible[] = $variant;
			}
		}
		if ( empty( $compatible ) ) {
			$compatible = array( 'complete_reference' );
		}
		$index = abs( crc32( 'chicago_edited_book_mcq_variant|' . (string) $seed ) ) % count( $compatible );
		return $compatible[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical Chicago
	 * edited book record.
	 *
	 * @param string $variant One of self::variants().
	 * @param array  $fields  {editors: array<{surname, givenName, fullName}>, title, place, publisher, year}.
	 * @return array{stem: string, wrongOptions: string[], correctAnswer: string}|null null for an unrecognised variant id.
	 */
	public static function build( $variant, array $fields ) {
		switch ( $variant ) {
			case 'complete_reference':
				return self::build_complete_reference( $fields );
			case 'editor_name_format':
				return self::build_editor_name_format( $fields );
			case 'two_editor_joining':
				return self::build_two_editor_joining( $fields );
			case 'three_or_more_editor_joining':
				return self::build_three_or_more_editor_joining( $fields );
			case 'designation_singular_plural':
				return self::build_designation_singular_plural( $fields );
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
		return Citex_Chicago_Reference_Rules::build_reference( Citex_Chicago_Reference_Rules::CATEGORY_EDITED_BOOK, $fields );
	}

	/**
	 * Builds the full reference from an explicit editor-list segment
	 * (expected to already end in its own full stop, exactly like
	 * join_people()'s own output — that trailing period is stripped here
	 * before the designation is appended), mirroring
	 * Citex_Chicago_Reference_Rules::build_edited_book_reference()'s own
	 * shape.
	 */
	private static function full_reference( $editor_segment, $designation, $year, $title, $place, $publisher ) {
		return sprintf( '%s, %s. %s. %s. %s: %s.', rtrim( $editor_segment, '.' ), $designation, $year, $title, $place, $publisher );
	}

	/**
	 * The first editor's segment rendered with an INITIAL instead of their
	 * full given name — same technique as
	 * Citex_Chicago_Book_Mcq_Variants::author_segment_initial().
	 */
	private static function editor_segment_initial( array $editors ) {
		$modified = $editors;
		$letter   = mb_substr( trim( (string) $modified[0]['givenName'] ), 0, 1 );
		if ( '' !== $letter ) {
			$modified[0]['givenName'] = mb_strtoupper( $letter );
		}
		return Citex_Chicago_Reference_Rules::join_people( $modified );
	}

	/**
	 * The 5 structural error kinds shared by identify_the_error and
	 * not_a_correct_reference.
	 *
	 * @return string[] error kind ids, in a fixed order.
	 */
	private static function error_kinds() {
		return array( 'editor_not_full_given_name', 'wrong_designation', 'year_missing_period', 'title_period_replaced_by_comma', 'missing_final_period' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'editor_not_full_given_name'    => 'The editor\'s given name is abbreviated to an initial instead of shown in full.',
			'wrong_designation'             => 'The "ed"/"eds" designation does not match how many editors are named.',
			'year_missing_period'           => 'The full stop after the year is missing.',
			'title_period_replaced_by_comma' => 'The full stop after the title is replaced with a comma.',
			'missing_final_period'          => 'The reference is missing its final full stop.',
		);
		return $statements[ $kind ] ?? '';
	}

	/** Builds a reference with exactly one structural mistake injected, per $kind. */
	private static function broken_reference( $kind, array $editors, $title, $place, $publisher, $year ) {
		$editor_segment = Citex_Chicago_Reference_Rules::join_people( $editors );
		$designation    = Citex_Chicago_Reference_Rules::designation_for_editor_count( count( $editors ) );
		switch ( $kind ) {
			case 'editor_not_full_given_name':
				return self::full_reference( self::editor_segment_initial( $editors ), $designation, $year, $title, $place, $publisher );
			case 'wrong_designation':
				$wrong = count( $editors ) > 1 ? 'ed' : 'eds';
				return self::full_reference( $editor_segment, $wrong, $year, $title, $place, $publisher );
			case 'year_missing_period':
				return sprintf( '%s, %s. %s %s. %s: %s.', rtrim( $editor_segment, '.' ), $designation, $year, $title, $place, $publisher );
			case 'title_period_replaced_by_comma':
				return sprintf( '%s, %s. %s. %s, %s: %s.', rtrim( $editor_segment, '.' ), $designation, $year, $title, $place, $publisher );
			case 'missing_final_period':
				return rtrim( self::full_reference( $editor_segment, $designation, $year, $title, $place, $publisher ), '.' );
		}
		return self::full_reference( $editor_segment, $designation, $year, $title, $place, $publisher );
	}

	/** Deterministically picks one error kind, seeded by the record's own content. */
	private static function pick_error_kind( $record_seed ) {
		$kinds = self::error_kinds();
		return $kinds[ abs( crc32( 'chicago_edited_book_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	/**
	 * A small, fixed pool of ready-made, fully self-contained, correctly
	 * formatted Chicago edited book references for DIFFERENT invented
	 * books — used only by not_a_correct_reference.
	 *
	 * @return string[] 6 ready-built, correctly-formatted references.
	 */
	private static function valid_reference_pool() {
		return array(
			self::full_reference( 'Bennett, Clara.', 'ed', '2018', 'The Silent Ocean', 'London', 'Faber' ),
			self::full_reference( 'Diaz, Marco, and Priya Nair.', 'eds', '2019', 'Modern Economies', 'New York', 'Wiley' ),
			self::full_reference( 'Chen, Wei, George Okafor, and Tom Bishop.', 'eds', '2020', 'Machine Learning Basics', 'Cambridge', 'MIT Press' ),
			self::full_reference( 'Okafor, George.', 'ed', '2017', 'Urban Planning Today', 'Abingdon', 'Routledge' ),
			self::full_reference( 'Novak, Sofia, and Tom Bishop.', 'eds', '2022', 'Climate Futures', 'Berlin', 'Springer' ),
			self::full_reference( 'Ibrahim, Yara, Wei Chen, and Marco Diaz.', 'eds', '2021', 'Global Health Policy', 'Thousand Oaks', 'SAGE' ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 1 — Complete Reference (the baseline mechanic).
	// -----------------------------------------------------------------
	private static function build_complete_reference( array $fields ) {
		$editors = $fields['editors'];
		$correct = self::correct_reference( $fields );
		return array(
			'stem'          => 'Which option is the correctly formatted Chicago (Author-Date) reference for an edited book?',
			'wrongOptions'  => array(
				self::broken_reference( 'editor_not_full_given_name', $editors, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] ),
				self::broken_reference( 'wrong_designation', $editors, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] ),
				self::broken_reference( 'year_missing_period', $editors, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 2 — Editor Name Format (fragment only, the FIRST editor
	// only, for any editor count). The full given name, never an initial.
	// -----------------------------------------------------------------
	private static function build_editor_name_format( array $fields ) {
		$person = $fields['editors'][0];
		return array(
			'stem'          => "Which option correctly formats the first editor's name for the Chicago (Author-Date) reference list?",
			'wrongOptions'  => array(
				sprintf( '%s, %s.', $person['surname'], mb_strtoupper( mb_substr( trim( (string) $person['givenName'] ), 0, 1 ) ) ),
				sprintf( '%s %s', $person['givenName'], $person['surname'] ),
				sprintf( '%s %s.', $person['surname'], $person['givenName'] ),
			),
			'correctAnswer' => sprintf( '%s, %s', $person['surname'], $person['givenName'] ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 3 — Two-Editor Joining (exactly 2 editors required). Chicago
	// keeps a comma before "and" even at exactly 2.
	// -----------------------------------------------------------------
	private static function build_two_editor_joining( array $fields ) {
		$editors = $fields['editors'];
		$first   = $editors[0];
		$second  = $editors[1];
		$correct = rtrim( Citex_Chicago_Reference_Rules::join_people( $editors ), '.' ) . ', ' . Citex_Chicago_Reference_Rules::designation_for_editor_count( 2 );
		return array(
			'stem'          => 'Which option correctly joins two editors for the Chicago (Author-Date) reference list?',
			'wrongOptions'  => array(
				sprintf( '%s, %s and %s, %s, eds', $first['surname'], $first['givenName'], $second['surname'], $second['givenName'] ),
				sprintf( '%s, %s, & %s, %s, eds', $first['surname'], $first['givenName'], $second['surname'], $second['givenName'] ),
				sprintf( '%s, %s, and %s %s, eds', $first['surname'], $first['givenName'], $second['givenName'], $second['surname'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 4 — Three-or-More Editor Joining (3+ editors required). Every
	// editor is always listed in full with "and" before the last, preceded
	// by a comma — wrong options swap in Harvard's plain "and", MLA's
	// "et al.", and APA's "&".
	// -----------------------------------------------------------------
	private static function build_three_or_more_editor_joining( array $fields ) {
		$editors     = $fields['editors'];
		$first       = $editors[0];
		$designation = Citex_Chicago_Reference_Rules::designation_for_editor_count( count( $editors ) );
		$correct     = rtrim( Citex_Chicago_Reference_Rules::join_people( $editors ), '.' ) . ', ' . $designation;
		$harvard_style = Citex_Reference_Rules::join_people( self::as_harvard_shaped( $editors ) ) . ', eds';
		$mla_style     = sprintf( '%s, %s, et al., eds', $first['surname'], $first['givenName'] );
		$segments      = array();
		foreach ( $editors as $person ) {
			$segments[] = sprintf( '%s, %s', $person['surname'], $person['givenName'] );
		}
		$apa_style = implode( ', ', array_slice( $segments, 0, -1 ) ) . ', & ' . end( $segments ) . ', eds';
		return array(
			'stem'          => 'Which option correctly names three or more editors for the Chicago (Author-Date) reference list?',
			'wrongOptions'  => array(
				$harvard_style,
				$mla_style,
				$apa_style,
			),
			'correctAnswer' => $correct,
		);
	}

	/** Reshapes {surname, givenName} people into Harvard's own {surname, initials} shape, purely so Citex_Reference_Rules::join_people() can render the Harvard-style comparison string. */
	private static function as_harvard_shaped( array $editors ) {
		return array_map(
			function ( $person ) {
				$letter = mb_substr( trim( (string) $person['givenName'] ), 0, 1 );
				return array( 'surname' => $person['surname'], 'initials' => '' !== $letter ? mb_strtoupper( $letter ) . '.' : '' );
			},
			$editors
		);
	}

	// -----------------------------------------------------------------
	// Variant 5 — Designation Singular/Plural. Fully static (one of only 2
	// possible answers) — see chicago_edited_book_independent_answer_variants().
	// -----------------------------------------------------------------
	private static function build_designation_singular_plural( array $fields ) {
		$bare    = Citex_Chicago_Reference_Rules::designation_for_editor_count( count( $fields['editors'] ) );
		$correct = $bare . '.';
		$wrong_options = 'ed' === $bare
			? array( 'eds.', '(ed.)', 'editor' )
			: array( 'ed.', '(eds.)', 'editors' );
		return array(
			'stem'          => 'Which option shows the correct editor designation for this Chicago (Author-Date) reference?',
			'wrongOptions'  => $wrong_options,
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 6 — Year Punctuation. No parentheses around the year at all.
	// -----------------------------------------------------------------
	private static function build_year_punctuation( array $fields ) {
		$editor_segment = Citex_Chicago_Reference_Rules::join_people( $fields['editors'] );
		$designation    = Citex_Chicago_Reference_Rules::designation_for_editor_count( count( $fields['editors'] ) );
		return array(
			'stem'          => 'Which option correctly punctuates the year for the Chicago (Author-Date) reference list?',
			'wrongOptions'  => array(
				sprintf( '%s, %s (%s). %s. %s: %s.', rtrim( $editor_segment, '.' ), $designation, $fields['year'], $fields['title'], $fields['place'], $fields['publisher'] ),
				sprintf( '%s, %s %s, %s. %s: %s.', rtrim( $editor_segment, '.' ), $designation, $fields['year'], $fields['title'], $fields['place'], $fields['publisher'] ),
				sprintf( '%s, %s %s %s. %s: %s.', rtrim( $editor_segment, '.' ), $designation, $fields['year'], $fields['title'], $fields['place'], $fields['publisher'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 7 — Identify the Error.
	// -----------------------------------------------------------------
	private static function build_identify_the_error( array $fields ) {
		$editors           = $fields['editors'];
		$record_seed       = implode( '|', array( $fields['title'], $fields['place'], $fields['publisher'], $fields['year'], (string) count( $editors ) ) );
		$kind              = self::pick_error_kind( $record_seed );
		$broken            = self::broken_reference( $kind, $editors, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] );
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
	// Variant 8 — "Which is NOT a correct reference?"
	// -----------------------------------------------------------------
	private static function build_not_a_correct_reference( array $fields ) {
		$editors     = $fields['editors'];
		$record_seed = implode( '|', array( $fields['title'], $fields['place'], $fields['publisher'], $fields['year'], (string) count( $editors ) ) );
		$kind        = self::pick_error_kind( $record_seed . '|not_correct' );
		$broken      = self::broken_reference( $kind, $editors, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] );

		$pool = self::valid_reference_pool();
		usort(
			$pool,
			function ( $a, $b ) use ( $record_seed ) {
				$hash_a = crc32( 'chicago_edited_book_mcq_not_correct_pool|' . $record_seed . '|' . $a );
				$hash_b = crc32( 'chicago_edited_book_mcq_not_correct_pool|' . $record_seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);

		return array(
			'stem'          => 'Which of the following is NOT a correct Chicago (Author-Date) reference for an edited book?',
			'wrongOptions'  => array_slice( $pool, 0, 3 ),
			'correctAnswer' => $broken,
		);
	}
}
