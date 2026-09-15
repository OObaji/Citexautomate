<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MHRA Edited Book's own fixed catalogue of MCQ "variant" templates —
 * mirrors Citex_MHRA_Book_Mcq_Variants exactly (same "Citex authors the
 * ENTIRE question deterministically from one canonical record" principle),
 * but swaps Book's own 'reference_structure' variant for
 * 'designation_singular_plural' — this category's own defining rule
 * (whether "ed." or "eds" is used) — mirroring
 * Citex_Chicago_Edited_Book_Mcq_Variants's own equivalent swap.
 *
 * 'two_editor_joining' requires exactly 2 editors; 'three_or_more_editor_joining'
 * requires 3 or more — every other variant works with any editor count.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_MHRA_Edited_Book_Mcq_Variants {

	/**
	 * Variants whose `correctAnswer` is entirely fixed/static (one of only 2
	 * possible strings, "ed." or "eds"), independent of this specific
	 * record's own editor/title/place/publisher/year content — must be
	 * skipped by duplicate-reference detection.
	 *
	 * @return string[] variant ids.
	 */
	public static function mhra_edited_book_independent_answer_variants() {
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
			'year_placement',
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
	 * @param string|int $seed         Typically the question's own id (e.g. "HE04").
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
		$index = abs( crc32( 'mhra_edited_book_mcq_variant|' . (string) $seed ) ) % count( $compatible );
		return $compatible[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical MHRA edited
	 * book record.
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
			case 'year_placement':
				return self::build_year_placement( $fields );
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
		return Citex_MHRA_Reference_Rules::build_reference( Citex_MHRA_Reference_Rules::CATEGORY_EDITED_BOOK, $fields );
	}

	/** Builds the full reference from an explicit editor-list segment, mirroring Citex_MHRA_Reference_Rules::build_edited_book_reference()'s own shape. */
	private static function full_reference( $editor_segment, $designation, $title, $place, $publisher, $year ) {
		return sprintf( '%s, %s, %s (%s: %s, %s).', $editor_segment, $designation, $title, $place, $publisher, $year );
	}

	/**
	 * The first editor's segment rendered with an INITIAL instead of their
	 * full given name — same technique as
	 * Citex_MHRA_Book_Mcq_Variants::author_segment_initial().
	 */
	private static function editor_segment_initial( array $editors ) {
		$modified = $editors;
		$letter   = mb_substr( trim( (string) $modified[0]['givenName'] ), 0, 1 );
		if ( '' !== $letter ) {
			$modified[0]['givenName'] = mb_strtoupper( $letter );
		}
		return Citex_MHRA_Reference_Rules::join_people( $modified );
	}

	/**
	 * The 5 structural error kinds shared by identify_the_error and
	 * not_a_correct_reference.
	 *
	 * @return string[] error kind ids, in a fixed order.
	 */
	private static function error_kinds() {
		return array( 'editor_not_full_given_name', 'wrong_designation', 'year_outside_parentheses', 'colon_replaced_by_comma', 'missing_final_period' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'editor_not_full_given_name' => 'The first editor\'s given name is abbreviated to an initial instead of shown in full.',
			'wrong_designation'          => 'The "ed."/"eds" designation does not match how many editors are named.',
			'year_outside_parentheses'   => 'The year is placed outside the parentheses instead of alongside the place and publisher.',
			'colon_replaced_by_comma'    => 'The colon between the place of publication and the publisher is replaced with a comma.',
			'missing_final_period'       => 'The reference is missing its final full stop.',
		);
		return $statements[ $kind ] ?? '';
	}

	/** Builds a reference with exactly one structural mistake injected, per $kind. */
	private static function broken_reference( $kind, array $editors, $title, $place, $publisher, $year ) {
		$editor_segment = Citex_MHRA_Reference_Rules::join_people( $editors );
		$designation    = Citex_Reference_Rules::designation_for_editor_count( count( $editors ) );
		switch ( $kind ) {
			case 'editor_not_full_given_name':
				return self::full_reference( self::editor_segment_initial( $editors ), $designation, $title, $place, $publisher, $year );
			case 'wrong_designation':
				$wrong = count( $editors ) > 1 ? 'ed.' : 'eds';
				return self::full_reference( $editor_segment, $wrong, $title, $place, $publisher, $year );
			case 'year_outside_parentheses':
				return sprintf( '%s, %s, %s (%s: %s). %s.', $editor_segment, $designation, $title, $place, $publisher, $year );
			case 'colon_replaced_by_comma':
				return sprintf( '%s, %s, %s (%s, %s, %s).', $editor_segment, $designation, $title, $place, $publisher, $year );
			case 'missing_final_period':
				return rtrim( self::full_reference( $editor_segment, $designation, $title, $place, $publisher, $year ), '.' );
		}
		return self::full_reference( $editor_segment, $designation, $title, $place, $publisher, $year );
	}

	/** Deterministically picks one error kind, seeded by the record's own content. */
	private static function pick_error_kind( $record_seed ) {
		$kinds = self::error_kinds();
		return $kinds[ abs( crc32( 'mhra_edited_book_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	/**
	 * A small, fixed pool of ready-made, fully self-contained, correctly
	 * formatted MHRA edited book references for DIFFERENT invented books —
	 * used only by not_a_correct_reference.
	 *
	 * @return string[] 6 ready-built, correctly-formatted references.
	 */
	private static function valid_reference_pool() {
		return array(
			self::full_reference( 'Bennett, Clara', 'ed.', 'The Silent Ocean', 'London', 'Faber', '2018' ),
			self::full_reference( 'Diaz, Marco, and Priya Nair', 'eds', 'Modern Economies', 'New York', 'Wiley', '2019' ),
			self::full_reference( 'Chen, Wei, George Okafor, and Tom Bishop', 'eds', 'Machine Learning Basics', 'Cambridge', 'MIT Press', '2020' ),
			self::full_reference( 'Okafor, George', 'ed.', 'Urban Planning Today', 'Abingdon', 'Routledge', '2017' ),
			self::full_reference( 'Novak, Sofia, and Tom Bishop', 'eds', 'Climate Futures', 'Berlin', 'Springer', '2022' ),
			self::full_reference( 'Ibrahim, Yara, Wei Chen, and Marco Diaz', 'eds', 'Global Health Policy', 'Thousand Oaks', 'SAGE', '2021' ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 1 — Complete Reference (the baseline mechanic).
	// -----------------------------------------------------------------
	private static function build_complete_reference( array $fields ) {
		$editors = $fields['editors'];
		$correct = self::correct_reference( $fields );
		return array(
			'stem'          => 'Which option is the correctly formatted MHRA Bibliography reference for an edited book?',
			'wrongOptions'  => array(
				self::broken_reference( 'editor_not_full_given_name', $editors, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] ),
				self::broken_reference( 'wrong_designation', $editors, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] ),
				self::broken_reference( 'year_outside_parentheses', $editors, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] ),
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
			'stem'          => "Which option correctly formats the first editor's name for the MHRA Bibliography?",
			'wrongOptions'  => array(
				sprintf( '%s %s', $person['givenName'], $person['surname'] ),
				sprintf( '%s, %s.', $person['surname'], mb_strtoupper( mb_substr( trim( (string) $person['givenName'] ), 0, 1 ) ) ),
				sprintf( '%s %s.', $person['surname'], $person['givenName'] ),
			),
			'correctAnswer' => sprintf( '%s, %s', $person['surname'], $person['givenName'] ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 3 — Two-Editor Joining (exactly 2 editors required). Only
	// the FIRST editor is inverted; the second keeps natural word order,
	// with a comma before "and" even at exactly 2.
	// -----------------------------------------------------------------
	private static function build_two_editor_joining( array $fields ) {
		$editors = $fields['editors'];
		$first   = $editors[0];
		$second  = $editors[1];
		$correct = Citex_MHRA_Reference_Rules::join_people( $editors );
		return array(
			'stem'          => 'Which option correctly joins two editors for the MHRA Bibliography?',
			'wrongOptions'  => array(
				sprintf( '%s, %s and %s %s', $first['surname'], $first['givenName'], $second['givenName'], $second['surname'] ),
				sprintf( '%s, %s, & %s %s', $first['surname'], $first['givenName'], $second['givenName'], $second['surname'] ),
				sprintf( '%s, %s, and %s, %s', $first['surname'], $first['givenName'], $second['surname'], $second['givenName'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 4 — Three-or-More Editor Joining (3+ editors required).
	// Every editor after the first stays in natural word order,
	// comma-separated, "and" before the last — wrong options swap in
	// MLA's "et al.", Chicago's "every editor inverted", and Harvard's
	// plain "and" (no comma before it).
	// -----------------------------------------------------------------
	private static function build_three_or_more_editor_joining( array $fields ) {
		$editors   = $fields['editors'];
		$first     = $editors[0];
		$correct   = Citex_MHRA_Reference_Rules::join_people( $editors );
		$mla_style = sprintf( '%s, %s, et al.', $first['surname'], $first['givenName'] );

		$all_inverted = array();
		foreach ( $editors as $person ) {
			$all_inverted[] = sprintf( '%s, %s', $person['surname'], $person['givenName'] );
		}
		$last_inverted  = array_pop( $all_inverted );
		$chicago_style  = implode( ', ', $all_inverted ) . ', and ' . $last_inverted;

		$natural_segments = array();
		foreach ( array_slice( $editors, 1 ) as $person ) {
			$natural_segments[] = sprintf( '%s %s', $person['givenName'], $person['surname'] );
		}
		$last_natural    = array_pop( $natural_segments );
		$no_oxford_comma = sprintf( '%s, %s', $first['surname'], $first['givenName'] );
		if ( ! empty( $natural_segments ) ) {
			$no_oxford_comma .= ', ' . implode( ', ', $natural_segments );
		}
		$no_oxford_comma .= ' and ' . $last_natural;

		return array(
			'stem'          => 'Which option correctly names three or more editors for the MHRA Bibliography?',
			'wrongOptions'  => array(
				$mla_style,
				$chicago_style,
				$no_oxford_comma,
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 5 — Designation Singular/Plural. Fully static (one of only 2
	// possible answers) — see mhra_edited_book_independent_answer_variants().
	// -----------------------------------------------------------------
	private static function build_designation_singular_plural( array $fields ) {
		$correct = Citex_Reference_Rules::designation_for_editor_count( count( $fields['editors'] ) );
		$wrong_options = 'ed.' === $correct
			? array( 'eds', 'editor', '(ed.)' )
			: array( 'ed.', 'editors', '(eds)' );
		return array(
			'stem'          => 'Which option shows the correct editor designation for this MHRA Bibliography reference?',
			'wrongOptions'  => $wrong_options,
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 6 — Year Placement. Tests the single most MHRA-distinctive
	// structural quirk: place, publisher AND year all sit together inside
	// ONE parenthesis.
	// -----------------------------------------------------------------
	private static function build_year_placement( array $fields ) {
		$editor_segment = Citex_MHRA_Reference_Rules::join_people( $fields['editors'] );
		$designation    = Citex_Reference_Rules::designation_for_editor_count( count( $fields['editors'] ) );
		return array(
			'stem'          => 'Which option correctly places the year for the MHRA Bibliography?',
			'wrongOptions'  => array(
				sprintf( '%s, %s, %s (%s: %s). %s.', $editor_segment, $designation, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] ),
				sprintf( '%s, %s, %s (%s: %s) (%s).', $editor_segment, $designation, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] ),
				sprintf( '%s, %s, %s (%s: %s %s).', $editor_segment, $designation, $fields['title'], $fields['place'], $fields['publisher'], $fields['year'] ),
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
				$hash_a = crc32( 'mhra_edited_book_mcq_not_correct_pool|' . $record_seed . '|' . $a );
				$hash_b = crc32( 'mhra_edited_book_mcq_not_correct_pool|' . $record_seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);

		return array(
			'stem'          => 'Which of the following is NOT a correct MHRA Bibliography reference for an edited book?',
			'wrongOptions'  => array_slice( $pool, 0, 3 ),
			'correctAnswer' => $broken,
		);
	}
}
