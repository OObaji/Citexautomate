<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chicago (Author-Date) in-text citation MCQ catalogue — mirrors
 * Citex_Intext_Mcq_Variants's own "Citex authors the ENTIRE question
 * deterministically from one canonical record" principle, same crc32-seeded
 * per-question variant selection, same exact-match validator story.
 * Category-agnostic like every other in-text class.
 *
 * Every distractor here is built around Chicago's OWN distinctive rules
 * (see Citex_Chicago_Intext_Citation_Rules's own docblock): the most
 * valuable wrong options are ones that apply another style's rule —
 * wrongly inserting a comma before the year, or wrongly adding a "p."
 * prefix before a page number — exactly the mistake a student used to
 * Harvard/APA would actually make with Chicago.
 *
 * A deliberately curated 4-variant catalogue, gated by BOTH citation form
 * (`variant_form_requirement()`) and author count
 * (`variant_author_requirement()`) — mirrors Citex_Intext_Mcq_Variants's
 * own gating exactly.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_Chicago_Intext_Mcq_Variants {

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
			return array( Citex_Chicago_Intext_Citation_Rules::FORM_PARENTHETICAL_QUOTE );
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
	 * @param string     $form         One of Citex_Chicago_Intext_Citation_Rules::forms().
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
		$index = abs( crc32( 'chicago_intext_mcq_variant|' . (string) $seed ) ) % count( $compatible );
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
		if ( Citex_Chicago_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
			return Citex_Chicago_Intext_Citation_Rules::narrative_sentence( $fields['who'], $fields['year'], $fields['clause'] );
		}
		if ( Citex_Chicago_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
			return Citex_Chicago_Intext_Citation_Rules::parenthetical_sentence( $fields['who'], $fields['year'], $fields['clause'] );
		}
		return Citex_Chicago_Intext_Citation_Rules::parenthetical_quote_sentence( $fields['who'], $fields['year'], $fields['page'], $fields['quote'] );
	}

	private static function clause_for( array $fields ) {
		return rtrim( trim( (string) ( $fields['clause'] ?? '' ) ), '.' );
	}

	/**
	 * The error kinds this record's citation FORM can meaningfully test,
	 * in a fixed order. 'added_comma' is used for BOTH parenthetical forms
	 * (broken_sentence() itself branches on $form to build the right
	 * shape) since it is the same underlying mistake — wrongly borrowing
	 * Harvard/APA/MHRA's comma-before-year rule.
	 *
	 * @return string[]
	 */
	private static function error_kinds( $form ) {
		if ( Citex_Chicago_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
			return array( 'author_inside_parens', 'wrong_who', 'wrong_year' );
		}
		if ( Citex_Chicago_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
			return array( 'added_comma', 'wrong_who', 'wrong_year' );
		}
		return array( 'added_comma', 'added_p_prefix', 'missing_page', 'wrong_who' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'author_inside_parens' => 'The author\'s surname is placed inside the parentheses instead of before them.',
			'wrong_who'             => 'The authors are not joined correctly for this number of authors ("and"/"et al." is used wrongly).',
			'wrong_year'            => 'The year shown does not match the source\'s actual publication year.',
			'added_comma'           => 'A comma has been wrongly added between the surname and the year — Chicago author-date never has a comma there.',
			'added_p_prefix'        => 'The page number wrongly carries a "p." before it — Chicago author-date never uses "p." in-text.',
			'missing_page'          => 'No page number is given for a direct quotation.',
		);
		return $statements[ $kind ] ?? '';
	}

	/** Builds a full sentence with exactly one deliberate mistake injected, per $kind. */
	private static function broken_sentence( $kind, array $fields ) {
		$who   = $fields['who'];
		$form  = $fields['form'];
		$year  = $fields['year'];
		$page  = $fields['page'];
		$quote = trim( (string) $fields['quote'] );
		switch ( $kind ) {
			case 'author_inside_parens':
				// The exact narrative-form regression this mechanic exists
				// to guard against: the author trapped inside the
				// parentheses alongside the year.
				return sprintf( '(%s %s) %s.', $who, $year, self::clause_for( $fields ) );
			case 'wrong_who':
				return self::full_sentence( array_merge( $fields, array( 'who' => Citex_Chicago_Intext_Citation_Rules::wrong_join( $fields['surnames'], $who ) ) ) );
			case 'wrong_year':
				$wrong_year = Citex_Reference_Rules::year_distractor( (string) $year, $who . '|' . $year . '|chicago_intext_mcq_year' );
				return self::full_sentence( array_merge( $fields, array( 'year' => $wrong_year ) ) );
			case 'added_comma':
				if ( Citex_Chicago_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
					return sprintf( '%s (%s, %s).', self::clause_for( $fields ), $who, $year );
				}
				return sprintf( '"%s" (%s, %s, %s).', $quote, $who, $year, $page );
			case 'added_p_prefix':
				return sprintf( '"%s" (%s %s, p. %s).', $quote, $who, $year, $page );
			case 'missing_page':
				return sprintf( '"%s" (%s %s).', $quote, $who, $year );
		}
		return self::full_sentence( $fields );
	}

	/** Deterministically picks one error kind, seeded by the record's own content. */
	private static function pick_error_kind( array $fields, $salt = '' ) {
		$kinds       = self::error_kinds( $fields['form'] );
		$record_seed = implode( '|', array( $fields['who'], (string) $fields['year'], (string) ( $fields['page'] ?? '' ) ) ) . $salt;
		return $kinds[ abs( crc32( 'chicago_intext_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	// -----------------------------------------------------------------
	// Variant 1 — Correct Format (the baseline mechanic). Exactly 3
	// curated mistakes per form, front-loaded rather than randomly picked.
	// -----------------------------------------------------------------
	private static function build_correct_format( array $fields ) {
		$form    = $fields['form'];
		$correct = self::full_sentence( $fields );
		if ( Citex_Chicago_Intext_Citation_Rules::FORM_PARENTHETICAL_QUOTE === $form ) {
			$kinds = array( 'added_comma', 'added_p_prefix', 'missing_page' );
		} else {
			$kinds = array(
				Citex_Chicago_Intext_Citation_Rules::FORM_NARRATIVE === $form ? 'author_inside_parens' : 'added_comma',
				'wrong_who',
				'wrong_year',
			);
		}
		return array(
			'stem'          => Citex_Chicago_Intext_Citation_Rules::mcq_question_stem( $form ),
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
	// in-text-only "et al. at 4+" threshold — identical to Harvard's own
	// rule, since Chicago's author-joining convention is the same.
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
	// Variant 3 — Page Punctuation (parenthetical_quote only). Focuses on
	// Chicago's own distinctive "no p., comma only before the page"
	// convention, distinct from correct_format's own quote-form mistakes.
	// -----------------------------------------------------------------
	private static function build_page_punctuation( array $fields ) {
		$who   = $fields['who'];
		$year  = $fields['year'];
		$page  = $fields['page'];
		$quote = trim( (string) $fields['quote'] );
		return array(
			'stem'          => 'Which option correctly punctuates the page reference for this direct quotation?',
			'wrongOptions'  => array(
				sprintf( '"%s" (%s %s, p. %s).', $quote, $who, $year, $page ),  // wrongly adds "p." — Chicago never does
				sprintf( '"%s" (%s %s %s).', $quote, $who, $year, $page ),      // missing the comma before the page
				sprintf( '"%s" (%s, %s, %s).', $quote, $who, $year, $page ),    // wrongly adds a comma before the year too
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
		while ( count( $wrong_statements ) < 3 ) {
			$wrong_statements[] = self::error_statement( 'added_p_prefix' ) ?: 'The citation is missing required punctuation.';
		}

		return array(
			'stem'          => sprintf( "Which option correctly identifies the error in this in-text citation?\n\n%s", $broken ),
			'wrongOptions'  => array_slice( $wrong_statements, 0, 3 ),
			'correctAnswer' => $correct_statement,
		);
	}
}
