<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MLA Edited Book's own fixed catalogue of MCQ "variant" templates —
 * mirrors Citex_MLA_Book_Mcq_Variants exactly (same "Citex authors the
 * ENTIRE question deterministically from one canonical record" principle,
 * same crc32-seeded per-question variant selection, same exact-match
 * validator story), swapping Book's own 'reference_structure' variant for
 * 'designation_singular_plural' — the category's own defining rule
 * (whether "editor" or "editors" is used) — since a generic field-order
 * variant adds little beyond what Book's own already covers.
 *
 * 'two_editor_joining' requires exactly 2 editors; 'et_al_convention'
 * requires 3 or more — every other variant works with any editor count.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_MLA_Edited_Book_Mcq_Variants {

	/**
	 * Variants whose `correctAnswer` is entirely fixed/static (one of only
	 * 2 possible strings, "editor" or "editors"), independent of this
	 * specific record's own editor/title/publisher/year content — mirrors
	 * Citex_MLA_Book_Mcq_Variants::mla_book_independent_answer_variants()'s
	 * exact rationale and must be skipped the same way by duplicate-
	 * reference detection.
	 *
	 * @return string[] variant ids.
	 */
	public static function mla_edited_book_independent_answer_variants() {
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
			'et_al_convention',
			'designation_singular_plural',
			'publisher_year_punctuation',
			'identify_the_error',
			'not_a_correct_reference',
		);
	}

	/**
	 * The exact editor count a variant requires — [min, max] inclusive —
	 * or null when the variant works with any editor count.
	 *
	 * @return array{0:int,1:int}|null
	 */
	public static function variant_editor_requirement( $variant ) {
		$map = array(
			'two_editor_joining' => array( 2, 2 ),
			'et_al_convention'   => array( 3, PHP_INT_MAX ),
		);
		return $map[ $variant ] ?? null;
	}

	/**
	 * @param string|int $seed         Typically the question's own id (e.g. "ME04").
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
		$index = abs( crc32( 'mla_edited_book_mcq_variant|' . (string) $seed ) ) % count( $compatible );
		return $compatible[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical MLA edited
	 * book record.
	 *
	 * @param string $variant One of self::variants().
	 * @param array  $fields  {editors: array<{surname, givenName, fullName}>, title, publisher, year}.
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
			case 'et_al_convention':
				return self::build_et_al_convention( $fields );
			case 'designation_singular_plural':
				return self::build_designation_singular_plural( $fields );
			case 'publisher_year_punctuation':
				return self::build_publisher_year_punctuation( $fields );
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
		return Citex_MLA_Reference_Rules::build_reference( Citex_MLA_Reference_Rules::CATEGORY_EDITED_BOOK, $fields );
	}

	/** Builds the full reference from an explicit editor-segment (already including its own designation), mirroring build_edited_book_reference()'s own shape. */
	private static function full_reference( $editor_segment, $title, $publisher, $year ) {
		return sprintf( '%s %s. %s, %s.', $editor_segment, $title, $publisher, $year );
	}

	/**
	 * The 5 structural error kinds shared by identify_the_error and
	 * not_a_correct_reference.
	 *
	 * @return string[] error kind ids, in a fixed order.
	 */
	private static function error_kinds() {
		return array( 'editor_not_inverted', 'wrong_designation', 'missing_comma_before_year', 'title_period_replaced_by_comma', 'missing_final_period' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'editor_not_inverted'            => 'The first editor\'s name is not inverted (surname first).',
			'wrong_designation'               => 'The "editor"/"editors" designation does not match how many editors are named.',
			'missing_comma_before_year'       => 'The comma between the publisher and the year is missing.',
			'title_period_replaced_by_comma'  => 'The full stop after the title is replaced with a comma.',
			'missing_final_period'            => 'The reference is missing its final full stop.',
		);
		return $statements[ $kind ] ?? '';
	}

	/** Builds a reference with exactly one structural mistake injected, per $kind. */
	private static function broken_reference( $kind, array $editors, $title, $publisher, $year ) {
		$editor_segment = Citex_MLA_Reference_Rules::join_editors( $editors );
		switch ( $kind ) {
			case 'editor_not_inverted':
				return self::full_reference( self::editor_segment_not_inverted( $editors ), $title, $publisher, $year );
			case 'wrong_designation':
				$wrong           = count( $editors ) > 1 ? 'editor' : 'editors';
				$person_segment  = Citex_MLA_Reference_Rules::join_people( $editors );
				// 3+ editors' join_people() output ends in "et al." — an
				// abbreviation whose own period must never be stripped
				// (see Citex_MLA_Reference_Rules::join_editors()'s own
				// docblock); below 3, it ends in a real sentence period
				// that must be stripped before appending the (wrong)
				// designation.
				if ( count( $editors ) < 3 ) {
					$person_segment = rtrim( $person_segment, '.' );
				}
				return self::full_reference( $person_segment . ', ' . $wrong . '.', $title, $publisher, $year );
			case 'missing_comma_before_year':
				return sprintf( '%s %s. %s %s.', $editor_segment, $title, $publisher, $year );
			case 'title_period_replaced_by_comma':
				return sprintf( '%s %s, %s, %s.', $editor_segment, $title, $publisher, $year );
			case 'missing_final_period':
				return rtrim( self::full_reference( $editor_segment, $title, $publisher, $year ), '.' );
		}
		return self::full_reference( $editor_segment, $title, $publisher, $year );
	}

	/**
	 * The editor segment with the FIRST editor's name left in natural (not
	 * inverted) word order, designation still correctly appended — the
	 * "editor_not_inverted" mistake tests specifically the first editor's
	 * own inversion, not the designation or joining rules.
	 */
	private static function editor_segment_not_inverted( array $editors ) {
		$first       = $editors[0];
		$designation = count( $editors ) > 1 ? 'editors' : 'editor';
		if ( 1 === count( $editors ) ) {
			return $first['fullName'] . ', ' . $designation . '.';
		}
		if ( 2 === count( $editors ) ) {
			return sprintf( '%s, and %s, %s.', $first['fullName'], $editors[1]['fullName'], $designation );
		}
		return sprintf( '%s, et al., %s.', $first['fullName'], $designation );
	}

	/** Deterministically picks one error kind, seeded by the record's own content. */
	private static function pick_error_kind( $record_seed ) {
		$kinds = self::error_kinds();
		return $kinds[ abs( crc32( 'mla_edited_book_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	/**
	 * A small, fixed pool of ready-made, fully self-contained, correctly
	 * formatted MLA edited book references for DIFFERENT invented books —
	 * used only by not_a_correct_reference.
	 *
	 * @return string[] 6 ready-built, correctly-formatted references.
	 */
	private static function valid_reference_pool() {
		return array(
			self::full_reference( 'Bennett, Clara, editor.', 'The Silent Ocean', 'Faber', '2018' ),
			self::full_reference( 'Diaz, Marco, and Priya Nair, editors.', 'Modern Economies', 'Wiley', '2019' ),
			self::full_reference( 'Chen, Wei, et al., editors.', 'Machine Learning Basics', 'MIT Press', '2020' ),
			self::full_reference( 'Okafor, Grace, editor.', 'Urban Planning Today', 'Routledge', '2017' ),
			self::full_reference( 'Novak, Sara, and Tom Bishop, editors.', 'Climate Futures', 'Springer', '2022' ),
			self::full_reference( 'Ibrahim, Youssef, et al., editors.', 'Global Health Policy', 'SAGE', '2021' ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 1 — Complete Reference (the baseline mechanic).
	// -----------------------------------------------------------------
	private static function build_complete_reference( array $fields ) {
		$editors = $fields['editors'];
		$correct = self::correct_reference( $fields );
		return array(
			'stem'          => 'Which option is the correctly formatted MLA reference for an edited book?',
			'wrongOptions'  => array(
				self::broken_reference( 'editor_not_inverted', $editors, $fields['title'], $fields['publisher'], $fields['year'] ),
				self::broken_reference( 'wrong_designation', $editors, $fields['title'], $fields['publisher'], $fields['year'] ),
				self::broken_reference( 'missing_comma_before_year', $editors, $fields['title'], $fields['publisher'], $fields['year'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 2 — Editor Name Format (fragment only, the FIRST editor
	// only, for any editor count).
	// -----------------------------------------------------------------
	private static function build_editor_name_format( array $fields ) {
		$person = $fields['editors'][0];
		return array(
			'stem'          => "Which option correctly formats the first editor's name?",
			'wrongOptions'  => array(
				(string) $person['fullName'],
				sprintf( '%s, %s.', $person['surname'], mb_substr( (string) $person['givenName'], 0, 1 ) . '.' ),
				sprintf( '%s %s', $person['surname'], $person['givenName'] ),
			),
			'correctAnswer' => sprintf( '%s, %s', $person['surname'], $person['givenName'] ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 3 — Two-Editor Joining (exactly 2 editors required).
	// -----------------------------------------------------------------
	private static function build_two_editor_joining( array $fields ) {
		$first  = $fields['editors'][0];
		$second = $fields['editors'][1];
		$correct = sprintf( '%s, %s, and %s, editors.', $first['surname'], $first['givenName'], $second['fullName'] );
		return array(
			'stem'          => 'Which option correctly joins two editors for the MLA Works Cited entry?',
			'wrongOptions'  => array(
				sprintf( '%s, %s and %s, editors.', $first['surname'], $first['givenName'], $second['fullName'] ),
				sprintf( '%s, %s, & %s, editors.', $first['surname'], $first['givenName'], $second['fullName'] ),
				sprintf( '%s, %s, and %s, editor.', $first['surname'], $first['givenName'], $second['fullName'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 4 — "et al." Convention (3 or more editors required).
	// -----------------------------------------------------------------
	private static function build_et_al_convention( array $fields ) {
		$editors = $fields['editors'];
		$first   = $editors[0];
		$correct = sprintf( '%s, %s, et al., editors.', $first['surname'], $first['givenName'] );
		$harvard_style = Citex_Reference_Rules::join_people(
			array_map(
				function ( $person ) {
					return array( 'surname' => $person['surname'], 'initials' => $person['givenName'] );
				},
				$editors
			)
		);
		return array(
			'stem'          => 'Which option correctly names the editors for a book with three or more editors in MLA style?',
			'wrongOptions'  => array(
				rtrim( $harvard_style, '.' ) . ' (eds).',
				sprintf( '%s, %s, et al editors.', $first['surname'], $first['givenName'] ),
				sprintf( '%s, %s, and others, editors.', $first['surname'], $first['givenName'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 5 — Designation Singular/Plural. Fully static (one of only
	// 2 possible answers) — see mla_edited_book_independent_answer_variants().
	// -----------------------------------------------------------------
	private static function build_designation_singular_plural( array $fields ) {
		$count   = count( $fields['editors'] );
		$correct = $count > 1 ? 'editors' : 'editor';
		// Every wrong option here must be more than a bare case/whitespace
		// variant of $correct — a mistake like "Editors" for "editors"
		// would be indistinguishable from the correct answer under the
		// case-insensitive duplicate-option check every MCQ mechanic in
		// this codebase applies (see Citex_Generated_Validator's own
		// MCQ_OPTION_MATCHES_ANSWER check).
		$wrong_options = $count > 1
			? array( 'editor', '(eds)', 'eds.' )
			: array( 'editors', '(ed.)', 'ed.' );
		return array(
			'stem'          => 'Which option shows the correct editor designation for this reference?',
			'wrongOptions'  => $wrong_options,
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 6 — Publisher/Year Punctuation.
	// -----------------------------------------------------------------
	private static function build_publisher_year_punctuation( array $fields ) {
		$editor_segment = Citex_MLA_Reference_Rules::join_editors( $fields['editors'] );
		return array(
			'stem'          => 'Which option correctly punctuates the publisher and year?',
			'wrongOptions'  => array(
				sprintf( '%s %s. %s. %s.', $editor_segment, $fields['title'], $fields['publisher'], $fields['year'] ),
				sprintf( '%s %s. %s %s.', $editor_segment, $fields['title'], $fields['publisher'], $fields['year'] ),
				sprintf( '%s %s. (%s) %s.', $editor_segment, $fields['title'], $fields['year'], $fields['publisher'] ),
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
				$hash_a = crc32( 'mla_edited_book_mcq_not_correct_pool|' . $record_seed . '|' . $a );
				$hash_b = crc32( 'mla_edited_book_mcq_not_correct_pool|' . $record_seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);

		return array(
			'stem'          => 'Which of the following is NOT a correct MLA reference for an edited book?',
			'wrongOptions'  => array_slice( $pool, 0, 3 ),
			'correctAnswer' => $broken,
		);
	}
}
