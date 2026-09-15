<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MLA in-text citation MCQ catalogue — mirrors Citex_Intext_Mcq_Variants's
 * own structure exactly (same 4-variant shape, same crc32-seeded
 * per-question selection, same exact-match validator story), but for
 * MLA's own rule (see Citex_MLA_Intext_Citation_Rules): no year at all
 * in-text, and no comma between the surname(s) and the page in the quote
 * form — the single most-tested MLA-vs-Harvard distractor.
 *
 * Category-agnostic like every other in-text class.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_MLA_Intext_Mcq_Variants {

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
			return array( Citex_MLA_Intext_Citation_Rules::FORM_PARENTHETICAL_QUOTE );
		}
		return null;
	}

	/**
	 * The exact person count a variant requires — [min, max] inclusive —
	 * or null for any count. MLA's own "et al." threshold is 3+ (not
	 * Harvard's 4+), so a joining convention only exists to test from 2
	 * people upward, same reasoning as Harvard's own class.
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
	 * @param string|int $seed
	 * @param string     $form         One of Citex_MLA_Intext_Citation_Rules::forms().
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
		$index = abs( crc32( 'mla_intext_mcq_variant|' . (string) $seed ) ) % count( $compatible );
		return $compatible[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical MLA in-text
	 * citation record.
	 *
	 * @param string $variant One of self::variants().
	 * @param array  $fields  {form, who, surnames: string[], clause, page
	 *                        (optional for narrative, absent for
	 *                        parenthetical, required for
	 *                        parenthetical_quote), quote (quote only)}.
	 * @return array{stem: string, wrongOptions: string[], correctAnswer: string}|null
	 */
	public static function build( $variant, array $fields ) {
		$form  = $fields['form'];
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
		if ( Citex_MLA_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
			return Citex_MLA_Intext_Citation_Rules::narrative_sentence( $fields['who'], $fields['clause'], $fields['page'] ?? null );
		}
		if ( Citex_MLA_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
			return Citex_MLA_Intext_Citation_Rules::parenthetical_sentence( $fields['who'], $fields['clause'] );
		}
		return Citex_MLA_Intext_Citation_Rules::parenthetical_quote_sentence( $fields['who'], $fields['page'], $fields['quote'] );
	}

	private static function clause_for( array $fields ) {
		return rtrim( trim( (string) ( $fields['clause'] ?? '' ) ), '.' );
	}

	private static function has_page( array $fields ) {
		return ! empty( $fields['page'] );
	}

	/** A deterministic, plausible-looking year — used only to inject the "year wrongly included" mistake. */
	private static function fake_year( $seed ) {
		return '20' . str_pad( (string) ( abs( crc32( 'mla_intext_fake_year|' . $seed ) ) % 25 ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * The error kinds this record's citation FORM (and whether it carries
	 * a page) can meaningfully test, in a fixed order.
	 *
	 * @return string[]
	 */
	private static function error_kinds( array $fields ) {
		$form = $fields['form'];
		if ( Citex_MLA_Intext_Citation_Rules::FORM_PARENTHETICAL_QUOTE === $form ) {
			return array( 'wrong_who', 'year_wrongly_included', 'comma_before_page', 'missing_page' );
		}
		if ( Citex_MLA_Intext_Citation_Rules::FORM_NARRATIVE === $form && self::has_page( $fields ) ) {
			return array( 'wrong_who', 'year_wrongly_included', 'missing_page_parens' );
		}
		return array( 'wrong_who', 'year_wrongly_included', 'missing_final_period' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'wrong_who'             => 'The author(s) are not joined correctly for this number of authors.',
			'year_wrongly_included' => 'A publication year is wrongly included — MLA in-text citations never show the year.',
			'missing_page_parens'   => 'The page number is not enclosed in its own parentheses.',
			'missing_final_period'  => 'The citation is missing its final full stop.',
			'comma_before_page'     => 'A comma is wrongly inserted between the surname(s) and the page number — MLA never uses one there.',
			'missing_page'          => 'No page number is given for a direct quotation.',
		);
		return $statements[ $kind ] ?? '';
	}

	/** Builds a full sentence with exactly one deliberate mistake injected, per $kind. */
	private static function broken_sentence( $kind, array $fields ) {
		$form = $fields['form'];
		$who  = $fields['who'];
		switch ( $kind ) {
			case 'wrong_who':
				return self::full_sentence( array_merge( $fields, array( 'who' => Citex_MLA_Intext_Citation_Rules::wrong_join( $fields['surnames'], $who ) ) ) );
			case 'year_wrongly_included':
				$year = self::fake_year( $who . '|' . self::clause_for( $fields ) );
				if ( Citex_MLA_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
					if ( self::has_page( $fields ) ) {
						return sprintf( '%s (%s) %s (%s).', $who, $year, self::clause_for( $fields ), $fields['page'] );
					}
					return sprintf( '%s (%s) %s.', $who, $year, self::clause_for( $fields ) );
				}
				if ( Citex_MLA_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
					return sprintf( '%s (%s, %s).', self::clause_for( $fields ), $who, $year );
				}
				return sprintf( '"%s" (%s, %s, p. %s).', trim( (string) $fields['quote'] ), $who, $year, $fields['page'] );
			case 'missing_page_parens':
				return sprintf( '%s %s, %s.', $who, self::clause_for( $fields ), $fields['page'] );
			case 'missing_final_period':
				return rtrim( self::full_sentence( $fields ), '.' );
			case 'comma_before_page':
				return sprintf( '"%s" (%s, %s).', trim( (string) $fields['quote'] ), $who, $fields['page'] );
			case 'missing_page':
				return sprintf( '"%s" (%s).', trim( (string) $fields['quote'] ), $who );
		}
		return self::full_sentence( $fields );
	}

	private static function pick_error_kind( array $fields ) {
		$kinds       = self::error_kinds( $fields );
		$record_seed = implode( '|', array( $fields['who'], self::clause_for( $fields ), (string) ( $fields['page'] ?? '' ) ) );
		return $kinds[ abs( crc32( 'mla_intext_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	// -----------------------------------------------------------------
	// Variant 1 — Correct Format (the baseline mechanic).
	// -----------------------------------------------------------------
	private static function build_correct_format( array $fields ) {
		$form = $fields['form'];
		if ( Citex_MLA_Intext_Citation_Rules::FORM_PARENTHETICAL_QUOTE === $form ) {
			$kinds = array( 'wrong_who', 'year_wrongly_included', 'missing_page' );
		} elseif ( Citex_MLA_Intext_Citation_Rules::FORM_NARRATIVE === $form && self::has_page( $fields ) ) {
			$kinds = array( 'wrong_who', 'year_wrongly_included', 'missing_page_parens' );
		} else {
			$kinds = array( 'wrong_who', 'year_wrongly_included', 'missing_final_period' );
		}
		return array(
			'stem'          => Citex_MLA_Intext_Citation_Rules::mcq_question_stem( $form ),
			'wrongOptions'  => array_map(
				function ( $kind ) use ( $fields ) {
					return self::broken_sentence( $kind, $fields );
				},
				$kinds
			),
			'correctAnswer' => self::full_sentence( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 2 — Join Convention (2+ people required). MLA's own et al.
	// threshold is 3+ — the SAME threshold as its reference-list rule,
	// unlike Harvard's split thresholds.
	// -----------------------------------------------------------------
	private static function build_join_convention( array $fields ) {
		$surnames = $fields['surnames'];
		$count    = count( $surnames );
		$correct  = self::full_sentence( $fields );

		if ( $count >= 3 ) {
			$copy = $surnames;
			$last = array_pop( $copy );
			$wrong_whos = array(
				implode( ', ', $copy ) . ' and ' . $last, // the reference-list-style "list everyone" mistake
				$surnames[0] . ' et al',                    // missing the abbreviation period
				$surnames[0] . ', and others',               // wrong phrase, plus a wrongly-inserted comma
			);
		} else {
			$wrong_whos = array(
				$surnames[0] . ', and ' . $surnames[1], // comma wrongly inserted (MLA's OWN reference-list rule bleeding into in-text)
				$surnames[0] . ' et al.',                 // "et al." misused below 3
				$surnames[0] . ' & ' . $surnames[1],       // "&" instead of "and"
			);
		}

		return array(
			'stem'          => 'Which option correctly names the author(s) for this MLA in-text citation?',
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
	// Variant 3 — Page Punctuation (parenthetical_quote only). The two
	// biggest MLA-vs-Harvard page distinctions in one variant: no comma,
	// and no "p." prefix at all.
	// -----------------------------------------------------------------
	private static function build_page_punctuation( array $fields ) {
		$who   = $fields['who'];
		$page  = $fields['page'];
		$quote = trim( (string) $fields['quote'] );
		return array(
			'stem'          => 'Which option correctly punctuates the page reference for this direct quotation in MLA style?',
			'wrongOptions'  => array(
				sprintf( '"%s" (%s, %s).', $quote, $who, $page ),   // comma wrongly inserted (the Harvard mistake)
				sprintf( '"%s" (%s p. %s).', $quote, $who, $page ), // "p." wrongly added (the Harvard mistake)
				sprintf( '"%s" (%s %s,).', $quote, $who, $page ),    // stray trailing comma
			),
			'correctAnswer' => self::full_sentence( $fields ),
		);
	}

	/** Every error kind this class knows about, across every form — used only to pad build_identify_the_error()'s wrong-statement pool to 3, never to build a broken sentence for a form it doesn't fit. */
	private static function all_kinds() {
		return array( 'wrong_who', 'year_wrongly_included', 'missing_page_parens', 'missing_final_period', 'comma_before_page', 'missing_page' );
	}

	// -----------------------------------------------------------------
	// Variant 4 — Identify the Error. Wrong statements are drawn first
	// from this record's own form-relevant kinds, then padded from the
	// full cross-form catalogue if that form has fewer than 4 kinds —
	// error_statement() text is static regardless of form, so padding
	// with an off-form kind is always safe; only broken_sentence()
	// (never called for the padding kinds) is form-sensitive.
	// -----------------------------------------------------------------
	private static function build_identify_the_error( array $fields ) {
		$kind              = self::pick_error_kind( $fields );
		$broken            = self::broken_sentence( $kind, $fields );
		$correct_statement = self::error_statement( $kind );

		$relevant = array_values( array_diff( self::error_kinds( $fields ), array( $kind ) ) );
		$rest     = array_values( array_diff( self::all_kinds(), $relevant, array( $kind ) ) );

		$wrong_statements = array();
		foreach ( array_merge( $relevant, $rest ) as $candidate_kind ) {
			$statement = self::error_statement( $candidate_kind );
			if ( '' !== $statement && ! in_array( $statement, $wrong_statements, true ) ) {
				$wrong_statements[] = $statement;
			}
			if ( 3 === count( $wrong_statements ) ) {
				break;
			}
		}

		return array(
			'stem'          => sprintf( "Which option correctly identifies the error in this MLA in-text citation?\n\n%s", $broken ),
			'wrongOptions'  => $wrong_statements,
			'correctAnswer' => $correct_statement,
		);
	}
}
