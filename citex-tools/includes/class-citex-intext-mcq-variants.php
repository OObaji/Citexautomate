<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Harvard in-text citation MCQ catalogue — mirrors Citex_Book_Mcq_Variants/
 * Citex_Website_Mcq_Variants's own "Citex authors the ENTIRE question
 * deterministically from one canonical record" principle, same crc32-seeded
 * per-question variant selection, same exact-match validator story.
 * Category-agnostic like every other in-text class (see
 * Citex_Intext_Citation_Rules's own docblock).
 *
 * A deliberately curated 4-variant catalogue, gated by BOTH citation form
 * (`variant_form_requirement()`) and author count
 * (`variant_author_requirement()`) — 'page_punctuation' only makes sense
 * for a direct quote; 'join_convention' only makes sense with 2+ people
 * (a single source has no joining convention to test, mirroring this
 * codebase's own established "no one_author/one_editor choose_treatment
 * variant" reasoning in Citex_Question_Scenarios).
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_Intext_Mcq_Variants {

	/**
	 * @return string[] the variant ids.
	 */
	public static function variants() {
		return array( 'correct_format', 'join_convention', 'page_punctuation', 'identify_the_error' );
	}

	/**
	 * Which citation form(s) a variant is eligible for, or null for any.
	 *
	 * @return string[]|null
	 */
	public static function variant_form_requirement( $variant ) {
		if ( 'page_punctuation' === $variant ) {
			return array( Citex_Intext_Citation_Rules::FORM_PARENTHETICAL_QUOTE );
		}
		return null;
	}

	/**
	 * The exact person count a variant requires — [min, max] inclusive —
	 * or null for any count.
	 *
	 * @return array{0:int,1:int}|null
	 */
	public static function variant_author_requirement( $variant ) {
		if ( 'join_convention' === $variant ) {
			return array( 2, PHP_INT_MAX );
		}
		return null;
	}

	/**
	 * Deterministically, but effectively unpredictably, picks one
	 * form-and-count-compatible variant per generated question, seeded by
	 * that question's own id.
	 *
	 * @param string|int $seed
	 * @param string     $form         One of Citex_Intext_Citation_Rules::forms().
	 * @param int        $author_count The real number of people (or 1 for
	 *                                 a Website individual/organisation).
	 * @return string variant id.
	 */
	public static function variant_for( $seed, $form, $author_count ) {
		$compatible = array();
		foreach ( self::variants() as $variant ) {
			$forms = self::variant_form_requirement( $variant );
			if ( null !== $forms && ! in_array( $form, $forms, true ) ) {
				continue;
			}
			$bounds = self::variant_author_requirement( $variant );
			if ( null !== $bounds && ( $author_count < $bounds[0] || $author_count > $bounds[1] ) ) {
				continue;
			}
			$compatible[] = $variant;
		}
		if ( empty( $compatible ) ) {
			$compatible = array( 'correct_format' );
		}
		$index = abs( crc32( 'intext_mcq_variant|' . (string) $seed ) ) % count( $compatible );
		return $compatible[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical in-text
	 * citation record.
	 *
	 * @param string $variant One of self::variants().
	 * @param array  $fields  {form, who, surnames: string[], year, clause,
	 *                        page (quote only), quote (quote only)}.
	 * @return array{stem: string, wrongOptions: string[], correctAnswer: string}|null
	 *         null for an unrecognised variant id, or a variant/form
	 *         mismatch (e.g. 'page_punctuation' for a narrative record).
	 */
	public static function build( $variant, array $fields ) {
		$form = $fields['form'];
		$forms = self::variant_form_requirement( $variant );
		if ( null !== $forms && ! in_array( $form, $forms, true ) ) {
			return null;
		}
		switch ( $variant ) {
			case 'correct_format':
				return self::build_correct_format( $fields );
			case 'join_convention':
				return self::build_join_convention( $fields );
			case 'page_punctuation':
				return self::build_page_punctuation( $fields );
			case 'identify_the_error':
				return self::build_identify_the_error( $fields );
		}
		return null;
	}

	// -----------------------------------------------------------------
	// Shared helpers.
	// -----------------------------------------------------------------

	private static function full_sentence( array $fields ) {
		$form = $fields['form'];
		if ( Citex_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
			return Citex_Intext_Citation_Rules::narrative_sentence( $fields['who'], $fields['year'], $fields['clause'] );
		}
		if ( Citex_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
			return Citex_Intext_Citation_Rules::parenthetical_sentence( $fields['who'], $fields['year'], $fields['clause'] );
		}
		return Citex_Intext_Citation_Rules::parenthetical_quote_sentence( $fields['who'], $fields['year'], $fields['page'], $fields['quote'] );
	}

	private static function clause_for( array $fields ) {
		return rtrim( trim( (string) ( $fields['clause'] ?? '' ) ), '.' );
	}

	/**
	 * The error kinds this record's citation FORM can meaningfully test,
	 * in a fixed order — every kind maps to exactly one deliberate
	 * mistake, injected by broken_sentence() below.
	 *
	 * @return string[]
	 */
	private static function error_kinds( $form ) {
		if ( Citex_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
			return array( 'author_inside_parens', 'wrong_who', 'wrong_year' );
		}
		if ( Citex_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
			return array( 'missing_comma', 'wrong_who', 'wrong_year' );
		}
		return array( 'missing_comma', 'missing_page', 'missing_p_prefix', 'wrong_who' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'author_inside_parens' => 'The author\'s surname is placed inside the parentheses instead of before them.',
			'wrong_who'             => 'The authors are not joined correctly for this number of authors ("and"/"et al." is used wrongly).',
			'wrong_year'            => 'The year shown does not match the source\'s actual publication year.',
			'missing_comma'         => 'A comma is missing between the surname and the year (or page).',
			'missing_page'          => 'No page number is given for a direct quotation.',
			'missing_p_prefix'      => 'The page number is missing "p." before it.',
		);
		return $statements[ $kind ] ?? '';
	}

	/** Builds a full sentence with exactly one deliberate mistake injected, per $kind. */
	private static function broken_sentence( $kind, array $fields ) {
		$who  = $fields['who'];
		$form = $fields['form'];
		switch ( $kind ) {
			case 'author_inside_parens':
				// The exact screenshot-1 regression this mechanic exists
				// to guard against: the author trapped inside the
				// parentheses alongside the year, in the narrative form.
				return sprintf( '(%s, %s) %s.', $who, $fields['year'], self::clause_for( $fields ) );
			case 'wrong_who':
				return self::full_sentence( array_merge( $fields, array( 'who' => Citex_Intext_Citation_Rules::wrong_join( $fields['surnames'], $who ) ) ) );
			case 'wrong_year':
				$wrong_year = Citex_Reference_Rules::year_distractor( (string) $fields['year'], $who . '|' . $fields['year'] . '|intext_mcq_year' );
				return self::full_sentence( array_merge( $fields, array( 'year' => $wrong_year ) ) );
			case 'missing_comma':
				if ( Citex_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
					return sprintf( '%s (%s %s).', self::clause_for( $fields ), $who, $fields['year'] );
				}
				return sprintf( '"%s" (%s %s, p. %s).', trim( (string) $fields['quote'] ), $who, $fields['year'], $fields['page'] );
			case 'missing_page':
				return sprintf( '"%s" (%s, %s).', trim( (string) $fields['quote'] ), $who, $fields['year'] );
			case 'missing_p_prefix':
				return sprintf( '"%s" (%s, %s, %s).', trim( (string) $fields['quote'] ), $who, $fields['year'], $fields['page'] );
		}
		return self::full_sentence( $fields );
	}

	/** Deterministically picks one error kind, seeded by the record's own content. */
	private static function pick_error_kind( array $fields, $salt = '' ) {
		$kinds       = self::error_kinds( $fields['form'] );
		$record_seed = implode( '|', array( $fields['who'], (string) $fields['year'], (string) ( $fields['page'] ?? '' ) ) ) . $salt;
		return $kinds[ abs( crc32( 'intext_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	// -----------------------------------------------------------------
	// Variant 1 — Correct Format (the baseline mechanic). Exactly 3
	// curated mistakes per form, front-loaded rather than randomly
	// picked, so every question of this variant tests the SAME 3
	// distinctions for that form.
	// -----------------------------------------------------------------
	private static function build_correct_format( array $fields ) {
		$form    = $fields['form'];
		$correct = self::full_sentence( $fields );
		if ( Citex_Intext_Citation_Rules::FORM_PARENTHETICAL_QUOTE === $form ) {
			$kinds = array( 'missing_comma', 'missing_page', 'missing_p_prefix' );
		} else {
			$kinds = array(
				Citex_Intext_Citation_Rules::FORM_NARRATIVE === $form ? 'author_inside_parens' : 'missing_comma',
				'wrong_who',
				'wrong_year',
			);
		}
		return array(
			'stem'          => Citex_Intext_Citation_Rules::mcq_question_stem( $form ),
			'wrongOptions'  => array_map(
				function ( $kind ) use ( $fields ) {
					return self::broken_sentence( $kind, $fields );
				},
				$kinds
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 2 — Join Convention (2+ people required). Tests the
	// in-text-only "et al. at 4+" threshold directly, with 3 join
	// mistakes distinct from whichever count this record actually has.
	// -----------------------------------------------------------------
	private static function build_join_convention( array $fields ) {
		$surnames = $fields['surnames'];
		$count    = count( $surnames );
		$correct  = self::full_sentence( $fields );

		if ( $count >= 4 ) {
			$copy = $surnames;
			$last = array_pop( $copy );
			$wrong_whos = array(
				implode( ', ', $copy ) . ' and ' . $last, // the reference-list-style "list everyone" mistake
				$surnames[0] . ' et al',                  // missing the abbreviation period
				$surnames[0] . ' and others',              // wrong phrase entirely
			);
		} else {
			$wrong_whos = array(
				str_replace( ' and ', ' & ', $fields['who'] ), // "&" instead of "and"
				$surnames[0] . ' et al.',                       // "et al." misused below 4
				implode( ', ', $surnames ),                     // comma throughout, no final "and"
			);
		}

		return array(
			'stem'          => 'Which option correctly names the author(s) for this in-text citation?',
			'wrongOptions'  => array_map(
				function ( $who ) use ( $fields ) {
					return self::full_sentence( array_merge( $fields, array( 'who' => $who ) ) );
				},
				$wrong_whos
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 3 — Page Punctuation (parenthetical_quote only). Focuses
	// specifically on the "p." convention, distinct from correct_format's
	// own quote-form mistakes (missing comma/page entirely).
	// -----------------------------------------------------------------
	private static function build_page_punctuation( array $fields ) {
		$who  = $fields['who'];
		$year = $fields['year'];
		$page = $fields['page'];
		$quote = trim( (string) $fields['quote'] );
		return array(
			'stem'          => 'Which option correctly punctuates the page reference for this direct quotation?',
			'wrongOptions'  => array(
				sprintf( '"%s" (%s, %s, p %s).', $quote, $who, $year, $page ),       // "p" with no period
				sprintf( '"%s" (%s, %s, pg. %s).', $quote, $who, $year, $page ),     // "pg." instead of "p."
				sprintf( '"%s" (%s, %s, p. %s,).', $quote, $who, $year, $page ),     // stray trailing comma
			),
			'correctAnswer' => self::full_sentence( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 4 — Identify the Error (one deliberate mistake shown; pick
	// the statement that correctly names it).
	// -----------------------------------------------------------------
	private static function build_identify_the_error( array $fields ) {
		$kind              = self::pick_error_kind( $fields );
		$broken            = self::broken_sentence( $kind, $fields );
		$correct_statement = self::error_statement( $kind );

		$wrong_statements = array();
		foreach ( self::error_kinds( $fields['form'] ) as $other_kind ) {
			if ( $other_kind !== $kind ) {
				$wrong_statements[] = self::error_statement( $other_kind );
			}
		}
		$wrong_statements = array_slice( $wrong_statements, 0, 3 );
		// A form with fewer than 4 kinds needs a filler distinct from
		// every kind actually eligible for this form, so the option
		// count never drops below 3.
		while ( count( $wrong_statements ) < 3 ) {
			$wrong_statements[] = self::error_statement( 'missing_p_prefix' ) ?: 'The citation is missing required punctuation.';
		}

		return array(
			'stem'          => sprintf( "Which option correctly identifies the error in this in-text citation?\n\n%s", $broken ),
			'wrongOptions'  => array_slice( $wrong_statements, 0, 3 ),
			'correctAnswer' => $correct_statement,
		);
	}
}
