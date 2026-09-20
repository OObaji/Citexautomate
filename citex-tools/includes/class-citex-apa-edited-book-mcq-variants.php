<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * APA Edited Book's own fixed catalogue of MCQ "variant" templates — mirrors
 * Citex_APA_Book_Mcq_Variants exactly (same "Citex authors the ENTIRE
 * question deterministically from one canonical record" principle), but
 * swaps Book's own 'reference_structure' variant for
 * 'designation_singular_plural' — this category's own defining rule
 * (whether "(Ed.)" or "(Eds.)" is used) — mirroring
 * Citex_MLA_Edited_Book_Mcq_Variants's own equivalent swap.
 *
 * 'two_editor_joining' requires exactly 2 editors; 'three_or_more_editor_joining'
 * requires 3 or more — every other variant works with any editor count.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_APA_Edited_Book_Mcq_Variants {

	/**
	 * Variants whose `correctAnswer` is entirely fixed/static (one of only 2
	 * possible strings, "Ed." or "Eds."), independent of this specific
	 * record's own editor/title/publisher/year content — must be skipped by
	 * duplicate-reference detection, mirroring
	 * Citex_APA_Book_Mcq_Variants::apa_book_independent_answer_variants()'s
	 * exact rationale.
	 *
	 * @return string[] variant ids.
	 */
	public static function apa_edited_book_independent_answer_variants() {
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
			'year_period_punctuation',
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
	 * @param string|int $seed         Typically the question's own id (e.g. "AE04").
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
		$index = abs( crc32( 'apa_edited_book_mcq_variant|' . (string) $seed ) ) % count( $compatible );
		return $compatible[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical APA edited
	 * book record.
	 *
	 * @param string $variant One of self::variants().
	 * @param array  $fields  {editors: array<{surname, initials, fullName}>, title, publisher, year}.
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
			case 'year_period_punctuation':
				return self::build_year_period_punctuation( $fields );
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
		return Citex_APA_Reference_Rules::build_reference( Citex_APA_Reference_Rules::CATEGORY_EDITED_BOOK, $fields );
	}

	/** Builds the full reference from an explicit editor-list segment, mirroring build_edited_book_reference()'s own shape. */
	private static function full_reference( $editor_segment, $designation, $year, $title, $publisher ) {
		return sprintf( '%s (%s). (%s). %s. %s.', $editor_segment, $designation, $year, $title, $publisher );
	}

	/**
	 * The first editor's segment rendered as their FULL name instead of
	 * "Surname, Initials" — mirrors
	 * Citex_APA_Book_Mcq_Variants::author_segment_full_name()'s own targeted
	 * substring substitution.
	 */
	private static function editor_segment_full_name( array $editors ) {
		$correct       = Citex_APA_Reference_Rules::join_people( $editors );
		$first         = $editors[0];
		$initials_form = sprintf( '%s, %s', $first['surname'], $first['initials'] );
		$pos           = strpos( $correct, $initials_form );
		if ( false === $pos ) {
			return $correct;
		}
		return substr_replace( $correct, (string) $first['fullName'], $pos, strlen( $initials_form ) );
	}

	/**
	 * The 5 structural error kinds shared by identify_the_error and
	 * not_a_correct_reference.
	 *
	 * @return string[] error kind ids, in a fixed order.
	 */
	private static function error_kinds() {
		return array( 'editor_not_initials', 'wrong_designation', 'year_missing_period', 'title_period_replaced_by_comma', 'missing_final_period' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'editor_not_initials'            => 'The editor\'s full first name is used instead of initials.',
			'wrong_designation'               => 'The "(Ed.)"/"(Eds.)" designation does not match how many editors are named.',
			'year_missing_period'             => 'The full stop after the year\'s closing parenthesis is missing.',
			'title_period_replaced_by_comma'  => 'The full stop after the title is replaced with a comma.',
			'missing_final_period'            => 'The reference is missing its final full stop.',
		);
		return $statements[ $kind ] ?? '';
	}

	/** Builds a reference with exactly one structural mistake injected, per $kind. */
	private static function broken_reference( $kind, array $editors, $title, $publisher, $year ) {
		$editor_segment = Citex_APA_Reference_Rules::join_people( $editors );
		$designation    = Citex_APA_Reference_Rules::designation_for_editor_count( count( $editors ) );
		switch ( $kind ) {
			case 'editor_not_initials':
				return self::full_reference( self::editor_segment_full_name( $editors ), $designation, $year, $title, $publisher );
			case 'wrong_designation':
				$wrong = count( $editors ) > 1 ? 'Ed.' : 'Eds.';
				return self::full_reference( $editor_segment, $wrong, $year, $title, $publisher );
			case 'year_missing_period':
				return sprintf( '%s (%s). (%s) %s. %s.', $editor_segment, $designation, $year, $title, $publisher );
			case 'title_period_replaced_by_comma':
				return sprintf( '%s (%s). (%s). %s, %s.', $editor_segment, $designation, $year, $title, $publisher );
			case 'missing_final_period':
				return rtrim( self::full_reference( $editor_segment, $designation, $year, $title, $publisher ), '.' );
		}
		return self::full_reference( $editor_segment, $designation, $year, $title, $publisher );
	}

	/** Deterministically picks one error kind, seeded by the record's own content. */
	private static function pick_error_kind( $record_seed ) {
		$kinds = self::error_kinds();
		return $kinds[ abs( crc32( 'apa_edited_book_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	/**
	 * A small, fixed pool of ready-made, fully self-contained, correctly
	 * formatted APA edited book references for DIFFERENT invented books —
	 * used only by not_a_correct_reference.
	 *
	 * @return string[] 6 ready-built, correctly-formatted references.
	 */
	private static function valid_reference_pool() {
		return array(
			self::full_reference( 'Bennett, C.', 'Ed.', '2018', 'The silent ocean', 'Faber' ),
			self::full_reference( 'Diaz, M., & Nair, P.', 'Eds.', '2019', 'Modern economies', 'Wiley' ),
			self::full_reference( 'Chen, W., Okafor, G., & Bishop, T.', 'Eds.', '2020', 'Machine learning basics', 'MIT Press' ),
			self::full_reference( 'Okafor, G.', 'Ed.', '2017', 'Urban planning today', 'Routledge' ),
			self::full_reference( 'Novak, S., & Bishop, T.', 'Eds.', '2022', 'Climate futures', 'Springer' ),
			self::full_reference( 'Ibrahim, Y., Chen, W., & Diaz, M.', 'Eds.', '2021', 'Global health policy', 'SAGE' ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 1 — Complete Reference (the baseline mechanic).
	// -----------------------------------------------------------------
	private static function build_complete_reference( array $fields ) {
		$editors = $fields['editors'];
		$correct = self::correct_reference( $fields );
		return array(
			'stem'          => 'Which option is the correctly formatted reference for an edited book?',
			'wrongOptions'  => array(
				self::broken_reference( 'editor_not_initials', $editors, $fields['title'], $fields['publisher'], $fields['year'] ),
				self::broken_reference( 'wrong_designation', $editors, $fields['title'], $fields['publisher'], $fields['year'] ),
				self::broken_reference( 'year_missing_period', $editors, $fields['title'], $fields['publisher'], $fields['year'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 2 — Editor Name Format (fragment only, the FIRST editor only,
	// for any editor count).
	// -----------------------------------------------------------------
	private static function build_editor_name_format( array $fields ) {
		$person = $fields['editors'][0];
		return array(
			'stem'          => "Which option correctly formats the first editor's name for the APA reference list?",
			'wrongOptions'  => array(
				(string) $person['fullName'],
				sprintf( '%s %s', $person['initials'], $person['surname'] ),
				sprintf( '%s, %s', $person['surname'], str_replace( '.', '', (string) $person['initials'] ) ),
			),
			'correctAnswer' => sprintf( '%s, %s', $person['surname'], $person['initials'] ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 3 — Two-Editor Joining (exactly 2 editors required). APA
	// keeps a comma before "&" even at exactly 2.
	// -----------------------------------------------------------------
	private static function build_two_editor_joining( array $fields ) {
		$first   = $fields['editors'][0];
		$second  = $fields['editors'][1];
		$correct = sprintf( '%s, %s, & %s, %s (Eds.)', $first['surname'], $first['initials'], $second['surname'], $second['initials'] );
		return array(
			'stem'          => 'Which option correctly joins two editors for the reference list?',
			'wrongOptions'  => array(
				sprintf( '%s, %s and %s, %s (Eds.)', $first['surname'], $first['initials'], $second['surname'], $second['initials'] ),
				sprintf( '%s, %s & %s, %s (Eds.)', $first['surname'], $first['initials'], $second['surname'], $second['initials'] ),
				sprintf( '%s, %s, & %s, %s (Ed.)', $first['surname'], $first['initials'], $second['surname'], $second['initials'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 4 — Three-or-More Editor Joining (3+ editors required). Every
	// editor is always listed in full with "&" before the last — wrong
	// options swap in Harvard's own plain "and" and MLA's own "et al.".
	// -----------------------------------------------------------------
	private static function build_three_or_more_editor_joining( array $fields ) {
		$editors       = $fields['editors'];
		$first         = $editors[0];
		$correct       = Citex_APA_Reference_Rules::join_people( $editors );
		$harvard_style = Citex_Reference_Rules::join_people( $editors );
		$mla_style     = sprintf( '%s, %s, et al.', $first['surname'], $first['initials'] );
		return array(
			'stem'          => 'Which option correctly names three or more editors for the reference list?',
			'wrongOptions'  => array(
				$harvard_style,
				$mla_style,
				sprintf( '%s, %s, %s (Eds.)', $first['surname'], $first['initials'], $editors[1]['surname'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 5 — Designation Singular/Plural. Fully static (one of only 2
	// possible answers) — see apa_edited_book_independent_answer_variants().
	// -----------------------------------------------------------------
	private static function build_designation_singular_plural( array $fields ) {
		$count   = count( $fields['editors'] );
		$correct = Citex_APA_Reference_Rules::designation_for_editor_count( $count );
		$wrong_options = 'Ed.' === $correct
			? array( 'Eds.', 'editor', '(Ed.)' )
			: array( 'Ed.', 'editors', '(Eds.)' );
		return array(
			'stem'          => 'Which option shows the correct editor designation for this reference?',
			'wrongOptions'  => $wrong_options,
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 6 — Year Punctuation.
	// -----------------------------------------------------------------
	private static function build_year_period_punctuation( array $fields ) {
		$editor_segment = Citex_APA_Reference_Rules::join_people( $fields['editors'] );
		$designation    = Citex_APA_Reference_Rules::designation_for_editor_count( count( $fields['editors'] ) );
		return array(
			'stem'          => 'Which option correctly punctuates the year for the reference list?',
			'wrongOptions'  => array(
				sprintf( '%s (%s). (%s) %s. %s.', $editor_segment, $designation, $fields['year'], $fields['title'], $fields['publisher'] ),
				sprintf( '%s (%s). %s. %s. %s.', $editor_segment, $designation, $fields['year'], $fields['title'], $fields['publisher'] ),
				sprintf( '%s (%s). (%s), %s. %s.', $editor_segment, $designation, $fields['year'], $fields['title'], $fields['publisher'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 7 — Identify the Error.
	// -----------------------------------------------------------------
	private static function build_identify_the_error( array $fields ) {
		$editors           = $fields['editors'];
		$record_seed       = implode( '|', array( $fields['title'], $fields['publisher'], $fields['year'], (string) count( $editors ) ) );
		$kind              = self::pick_error_kind( $record_seed );
		$broken            = self::broken_reference( $kind, $editors, $fields['title'], $fields['publisher'], $fields['year'] );
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
		$record_seed = implode( '|', array( $fields['title'], $fields['publisher'], $fields['year'], (string) count( $editors ) ) );
		$kind        = self::pick_error_kind( $record_seed . '|not_correct' );
		$broken      = self::broken_reference( $kind, $editors, $fields['title'], $fields['publisher'], $fields['year'] );

		$pool = self::valid_reference_pool();
		usort(
			$pool,
			function ( $a, $b ) use ( $record_seed ) {
				$hash_a = crc32( 'apa_edited_book_mcq_not_correct_pool|' . $record_seed . '|' . $a );
				$hash_b = crc32( 'apa_edited_book_mcq_not_correct_pool|' . $record_seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);

		return array(
			'stem'          => 'Which of the following is NOT a correct reference for an edited book?',
			'wrongOptions'  => array_slice( $pool, 0, 3 ),
			'correctAnswer' => $broken,
		);
	}
}
