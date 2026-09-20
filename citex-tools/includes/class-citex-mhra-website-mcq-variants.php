<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MHRA Website's own fixed catalogue of MCQ "variant" templates — mirrors
 * Citex_Chicago_Website_Mcq_Variants's own shape (author-format-agnostic:
 * exactly one author-or-organisation via
 * Citex_MHRA_Reference_Rules::format_website_author(), so no variant here
 * ever needs to distinguish the two), adapted for MHRA's own rules (see
 * Citex_MHRA_Reference_Rules's own docblock): a single-quoted page title,
 * angle brackets around the URL, square brackets around the accessed date,
 * and — the single biggest structural difference from every other style's
 * own Website format in this codebase — NO publication-year field at all.
 * 'year_wrongly_added' is this category's own defining variant, testing
 * exactly that missing-year rule by swapping in another style's
 * year-showing convention as the wrong answer.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_MHRA_Website_Mcq_Variants {

	/**
	 * Variants whose `correctAnswer` and `wrongOptions` are entirely
	 * fixed/static, independent of this specific record's own content —
	 * must be skipped by duplicate-reference detection.
	 *
	 * @return string[] variant ids.
	 */
	public static function mhra_website_independent_answer_variants() {
		return array( 'reference_structure' );
	}

	/**
	 * @return string[] the variant ids.
	 */
	public static function variants() {
		return array(
			'complete_reference',
			'url_format',
			'reference_structure',
			'accessed_date_format',
			'title_quoting',
			'year_wrongly_added',
			'identify_the_error',
			'not_a_correct_reference',
		);
	}

	/**
	 * Deterministically, but effectively unpredictably, picks one variant
	 * per generated question, seeded by that question's own id — every
	 * variant is compatible with both author types (individual and
	 * organisation), so there is no eligibility filter.
	 *
	 * @param string|int $seed Typically the question's own id (e.g. "HW04").
	 * @return string variant id.
	 */
	public static function variant_for( $seed ) {
		$variants = self::variants();
		$index    = abs( crc32( 'mhra_website_mcq_variant|' . (string) $seed ) ) % count( $variants );
		return $variants[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical MHRA website
	 * record.
	 *
	 * @param string $variant One of self::variants().
	 * @param array  $fields  {author: {type, surname?, givenName?, fullName?, name?}, title, url, accessedDate}.
	 * @return array{stem: string, wrongOptions: string[], correctAnswer: string}|null
	 */
	public static function build( $variant, array $fields ) {
		switch ( $variant ) {
			case 'complete_reference':
				return self::build_complete_reference( $fields );
			case 'url_format':
				return self::build_url_format( $fields );
			case 'reference_structure':
				return self::build_reference_structure( $fields );
			case 'accessed_date_format':
				return self::build_accessed_date_format( $fields );
			case 'title_quoting':
				return self::build_title_quoting( $fields );
			case 'year_wrongly_added':
				return self::build_year_wrongly_added( $fields );
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

	private static function author_display( array $author ) {
		return Citex_MHRA_Reference_Rules::format_website_author( $author );
	}

	private static function correct_reference( array $fields ) {
		return Citex_MHRA_Reference_Rules::build_reference( Citex_MHRA_Reference_Rules::CATEGORY_WEBSITE, $fields );
	}

	/** Builds the full reference from explicit parts, mirroring Citex_MHRA_Reference_Rules::build_website_reference()'s own shape. */
	private static function full_reference( $author_display, $title, $url, $accessed_date ) {
		return sprintf( "%s, '%s', <%s> [accessed %s].", $author_display, $title, $url, $accessed_date );
	}

	/**
	 * A plausible-looking fake publication year, deterministically derived
	 * from the record's own accessed date (its own trailing 4-digit year) —
	 * used only by 'year_wrongly_added'/'identify_the_error'/
	 * 'not_a_correct_reference' to inject a wrong-but-believable year, since
	 * MHRA's own Website fields carry no publication year at all to draw one
	 * from.
	 */
	private static function fake_year_from_accessed_date( $accessed_date ) {
		if ( preg_match( '/(\d{4})\s*$/', (string) $accessed_date, $m ) ) {
			return $m[1];
		}
		return 'n.d.';
	}

	/**
	 * The 5 structural error kinds shared by identify_the_error and
	 * not_a_correct_reference — every kind works for both author types.
	 *
	 * @return string[] error kind ids, in a fixed order.
	 */
	private static function error_kinds() {
		return array( 'url_missing_angle_brackets', 'accessed_date_missing_square_brackets', 'title_double_quoted', 'year_wrongly_added', 'missing_final_period' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'url_missing_angle_brackets'            => 'The URL is missing its angle brackets.',
			'accessed_date_missing_square_brackets' => 'The accessed date is missing its square brackets.',
			'title_double_quoted'                   => 'The page title is wrapped in double quotation marks instead of single quotation marks.',
			'year_wrongly_added'                     => 'A publication year is wrongly added — MHRA shows no publication year for a webpage.',
			'missing_final_period'                  => 'The reference is missing its final full stop.',
		);
		return $statements[ $kind ] ?? '';
	}

	/** Builds a reference with exactly one structural mistake injected, per $kind. */
	private static function broken_reference( $kind, $author_display, $title, $url, $accessed_date ) {
		switch ( $kind ) {
			case 'url_missing_angle_brackets':
				return sprintf( "%s, '%s', %s [accessed %s].", $author_display, $title, $url, $accessed_date );
			case 'accessed_date_missing_square_brackets':
				return sprintf( "%s, '%s', <%s> accessed %s.", $author_display, $title, $url, $accessed_date );
			case 'title_double_quoted':
				return sprintf( '%s, "%s", <%s> [accessed %s].', $author_display, $title, $url, $accessed_date );
			case 'year_wrongly_added':
				$year = self::fake_year_from_accessed_date( $accessed_date );
				return sprintf( "%s (%s), '%s', <%s> [accessed %s].", $author_display, $year, $title, $url, $accessed_date );
			case 'missing_final_period':
				return rtrim( self::full_reference( $author_display, $title, $url, $accessed_date ), '.' );
		}
		return self::full_reference( $author_display, $title, $url, $accessed_date );
	}

	/** Deterministically picks one error kind, seeded by the record's own content. */
	private static function pick_error_kind( $record_seed ) {
		$kinds = self::error_kinds();
		return $kinds[ abs( crc32( 'mhra_website_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	/**
	 * A small, fixed pool of ready-made, fully self-contained, correctly
	 * formatted MHRA website references for DIFFERENT invented pages — used
	 * only by not_a_correct_reference.
	 *
	 * @return string[] 6 ready-built, correctly-formatted references.
	 */
	private static function valid_reference_pool() {
		return array(
			self::full_reference( 'Ross, Tom', 'Digital Skills', 'https://www.sage.com', '3 January 2019' ),
			self::full_reference( 'World Health Organisation', 'Global Health', 'https://www.who.int', '14 June 2022' ),
			self::full_reference( 'Dale, Rachel', 'Climate Data', 'https://www.bbc.co.uk', '9 February 2021' ),
			self::full_reference( 'Open University', 'Study Skills', 'https://www.mit.edu', '25 November 2020' ),
			self::full_reference( 'Kaur, Amrit', 'Ethics Review', 'https://www.un.org', '2 August 2023' ),
			self::full_reference( 'National Health Service', 'Wellbeing Guide', 'https://www.nhs.uk', '17 April 2018' ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 1 — Complete Reference (the baseline mechanic).
	// -----------------------------------------------------------------
	private static function build_complete_reference( array $fields ) {
		$author  = self::author_display( $fields['author'] );
		$correct = self::correct_reference( $fields );
		return array(
			'stem'          => 'Which option is the correctly formatted reference for a webpage?',
			'wrongOptions'  => array(
				self::broken_reference( 'url_missing_angle_brackets', $author, $fields['title'], $fields['url'], $fields['accessedDate'] ),
				self::broken_reference( 'accessed_date_missing_square_brackets', $author, $fields['title'], $fields['url'], $fields['accessedDate'] ),
				self::broken_reference( 'year_wrongly_added', $author, $fields['title'], $fields['url'], $fields['accessedDate'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 2 — URL Format. Tests the angle-bracket requirement.
	// -----------------------------------------------------------------
	private static function build_url_format( array $fields ) {
		$author = self::author_display( $fields['author'] );
		return array(
			'stem'          => 'Which option correctly presents the URL for the webpage reference?',
			'wrongOptions'  => array(
				sprintf( "%s, '%s', %s [accessed %s].", $author, $fields['title'], $fields['url'], $fields['accessedDate'] ),
				sprintf( "%s, '%s', (%s) [accessed %s].", $author, $fields['title'], $fields['url'], $fields['accessedDate'] ),
				sprintf( "%s, '%s', \"%s\" [accessed %s].", $author, $fields['title'], $fields['url'], $fields['accessedDate'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 3 — Reference Structure. Fully static — no record data at all.
	// -----------------------------------------------------------------
	private static function build_reference_structure( array $fields ) {
		return array(
			'stem'          => 'Which option shows the correct order of the main Website reference elements?',
			'wrongOptions'  => array(
				'Author → Accessed date → Title → URL',
				'Title → Author → URL → Accessed date',
				'Author → Title → Accessed date → URL',
			),
			'correctAnswer' => 'Author → Title → URL → Accessed date',
		);
	}

	// -----------------------------------------------------------------
	// Variant 4 — Accessed Date Format. Tests the square-bracket requirement.
	// -----------------------------------------------------------------
	private static function build_accessed_date_format( array $fields ) {
		$author = self::author_display( $fields['author'] );
		return array(
			'stem'          => 'Which option correctly presents the accessed date for the webpage reference?',
			'wrongOptions'  => array(
				sprintf( "%s, '%s', <%s> accessed %s.", $author, $fields['title'], $fields['url'], $fields['accessedDate'] ),
				sprintf( "%s, '%s', <%s> (accessed %s).", $author, $fields['title'], $fields['url'], $fields['accessedDate'] ),
				sprintf( "%s, '%s', <%s>, accessed %s.", $author, $fields['title'], $fields['url'], $fields['accessedDate'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 5 — Title Quoting. Single quotes — MHRA's own convention,
	// matching Harvard's, unlike MLA/Chicago's double quotes.
	// -----------------------------------------------------------------
	private static function build_title_quoting( array $fields ) {
		$title = (string) $fields['title'];
		return array(
			'stem'          => 'Which option correctly presents the page title for this reference?',
			'wrongOptions'  => array(
				sprintf( '%s,', $title ),
				sprintf( '"%s",', $title ),
				sprintf( "'%s'.", $title ),
			),
			'correctAnswer' => sprintf( "'%s',", $title ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 6 — Year Wrongly Added. This category's own defining rule:
	// MHRA shows NO publication year at all for a webpage, only the
	// accessed date.
	// -----------------------------------------------------------------
	private static function build_year_wrongly_added( array $fields ) {
		$author = self::author_display( $fields['author'] );
		$year   = self::fake_year_from_accessed_date( $fields['accessedDate'] );
		return array(
			'stem'          => 'Which option correctly presents this webpage reference?',
			'wrongOptions'  => array(
				sprintf( "%s (%s), '%s', <%s> [accessed %s].", $author, $year, $fields['title'], $fields['url'], $fields['accessedDate'] ),
				sprintf( "%s, '%s', <%s> (%s) [accessed %s].", $author, $fields['title'], $fields['url'], $year, $fields['accessedDate'] ),
				sprintf( "%s, '%s', <%s> [accessed %s], %s.", $author, $fields['title'], $fields['url'], $fields['accessedDate'], $year ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 7 — Identify the Error.
	// -----------------------------------------------------------------
	private static function build_identify_the_error( array $fields ) {
		$author            = self::author_display( $fields['author'] );
		$record_seed       = implode( '|', array( $fields['title'], $fields['url'], (string) $fields['accessedDate'] ) );
		$kind              = self::pick_error_kind( $record_seed );
		$broken            = self::broken_reference( $kind, $author, $fields['title'], $fields['url'], $fields['accessedDate'] );
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
		$author      = self::author_display( $fields['author'] );
		$record_seed = implode( '|', array( $fields['title'], $fields['url'], (string) $fields['accessedDate'] ) );
		$kind        = self::pick_error_kind( $record_seed . '|not_correct' );
		$broken      = self::broken_reference( $kind, $author, $fields['title'], $fields['url'], $fields['accessedDate'] );

		$pool = self::valid_reference_pool();
		usort(
			$pool,
			function ( $a, $b ) use ( $record_seed ) {
				$hash_a = crc32( 'mhra_website_mcq_not_correct_pool|' . $record_seed . '|' . $a );
				$hash_b = crc32( 'mhra_website_mcq_not_correct_pool|' . $record_seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);

		return array(
			'stem'          => 'Which of the following is NOT a correct reference for a webpage?',
			'wrongOptions'  => array_slice( $pool, 0, 3 ),
			'correctAnswer' => $broken,
		);
	}
}
