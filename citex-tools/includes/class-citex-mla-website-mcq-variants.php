<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MLA Website's own fixed catalogue of MCQ "variant" templates — mirrors
 * Citex_Website_Mcq_Variants's own "Citex authors the ENTIRE question
 * deterministically from one canonical record" principle, applied to
 * MLA's own Website rule (see Citex_MLA_Reference_Rules's own docblock):
 * the page title in double quotation marks, and — the single biggest
 * structural difference — no "n.d." convention at all, so an undated
 * source simply omits the year segment and relies on the Accessed date.
 *
 * 'author_name_format' only makes sense for a named individual (an
 * organisation has no given-name-vs-initial distinction to test) —
 * gated the same way Website's other MCQ catalogues already gate an
 * author-type-specific variant.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_MLA_Website_Mcq_Variants {

	/**
	 * @return string[] the variant ids.
	 */
	public static function variants() {
		return array(
			'complete_reference',
			'author_name_format',
			'title_quotation',
			'undated_source',
			'accessed_date_placement',
			'identify_the_error',
			'not_a_correct_reference',
		);
	}

	/**
	 * 'author_name_format' requires a named individual author (never an
	 * organisation); every other variant works for either author type.
	 */
	public static function variant_requires_individual( $variant ) {
		return 'author_name_format' === $variant;
	}

	/**
	 * @param string|int $seed            Typically the question's own id.
	 * @param bool       $is_individual   Whether this record's author is a named individual (not an organisation).
	 * @return string variant id.
	 */
	public static function variant_for( $seed, $is_individual ) {
		$compatible = array();
		foreach ( self::variants() as $variant ) {
			if ( self::variant_requires_individual( $variant ) && ! $is_individual ) {
				continue;
			}
			$compatible[] = $variant;
		}
		if ( empty( $compatible ) ) {
			$compatible = array( 'complete_reference' );
		}
		$index = abs( crc32( 'mla_website_mcq_variant|' . (string) $seed ) ) % count( $compatible );
		return $compatible[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical MLA website
	 * record.
	 *
	 * @param string $variant One of self::variants().
	 * @param array  $fields  {author: {type, surname?, givenName?, name?, fullName?}, title, year (string, '' when unknown), url, accessedDate}.
	 * @return array{stem: string, wrongOptions: string[], correctAnswer: string}|null
	 */
	public static function build( $variant, array $fields ) {
		if ( self::variant_requires_individual( $variant ) && 'individual' !== ( $fields['author']['type'] ?? '' ) ) {
			return null;
		}
		switch ( $variant ) {
			case 'complete_reference':
				return self::build_complete_reference( $fields );
			case 'author_name_format':
				return self::build_author_name_format( $fields );
			case 'title_quotation':
				return self::build_title_quotation( $fields );
			case 'undated_source':
				return self::build_undated_source( $fields );
			case 'accessed_date_placement':
				return self::build_accessed_date_placement( $fields );
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
		return Citex_MLA_Reference_Rules::build_reference( Citex_MLA_Reference_Rules::CATEGORY_WEBSITE, $fields );
	}

	private static function has_year( array $fields ) {
		return '' !== trim( (string) ( $fields['year'] ?? '' ) );
	}

	private static function error_kinds( array $fields ) {
		if ( self::has_year( $fields ) ) {
			return array( 'title_not_quoted', 'n_d_wrongly_shown', 'missing_accessed_word', 'missing_final_period' );
		}
		return array( 'title_not_quoted', 'missing_accessed_word', 'missing_final_period' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'title_not_quoted'        => 'The page title is not enclosed in double quotation marks.',
			'n_d_wrongly_shown'        => 'A publication year is wrongly replaced with "n.d." — MLA never uses that convention.',
			'missing_accessed_word'    => 'The word "Accessed" is missing before the access date.',
			'missing_final_period'     => 'The reference is missing its final full stop.',
		);
		return $statements[ $kind ] ?? '';
	}

	/** Builds a reference with exactly one structural mistake injected, per $kind. */
	private static function broken_reference( $kind, array $fields ) {
		$author = Citex_MLA_Reference_Rules::format_website_author( $fields['author'] );
		$title  = $fields['title'];
		$url    = $fields['url'];
		$accessed = $fields['accessedDate'];
		$has_year = self::has_year( $fields );
		switch ( $kind ) {
			case 'title_not_quoted':
				return $has_year
					? sprintf( '%s %s. %s, %s. Accessed %s.', $author, $title, $fields['year'], $url, $accessed )
					: sprintf( '%s %s. %s. Accessed %s.', $author, $title, $url, $accessed );
			case 'n_d_wrongly_shown':
				return sprintf( '%s "%s." n.d., %s. Accessed %s.', $author, $title, $url, $accessed );
			case 'missing_accessed_word':
				return $has_year
					? sprintf( '%s "%s." %s, %s. %s.', $author, $title, $fields['year'], $url, $accessed )
					: sprintf( '%s "%s." %s. %s.', $author, $title, $url, $accessed );
			case 'missing_final_period':
				return rtrim( self::correct_reference( $fields ), '.' );
		}
		return self::correct_reference( $fields );
	}

	private static function pick_error_kind( array $fields, $salt = '' ) {
		$kinds       = self::error_kinds( $fields );
		$record_seed = implode( '|', array( $fields['title'], $fields['url'], $fields['accessedDate'] ) ) . $salt;
		return $kinds[ abs( crc32( 'mla_website_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	/**
	 * A small, fixed pool of ready-made, fully self-contained, correctly
	 * formatted MLA website references for DIFFERENT invented pages —
	 * used only by not_a_correct_reference.
	 *
	 * @return string[] 6 ready-built, correctly-formatted references.
	 */
	private static function valid_reference_pool() {
		return array(
			'Bennett, Clara. "Understanding Tides." 2019, https://oceanwatch.example.com. Accessed 3 May 2023.',
			'World Health Organization. "Global Health Facts." https://who.example.com. Accessed 14 Jan. 2024.',
			'Diaz, Marco. "Economic Outlook." 2021, https://econreport.example.com. Accessed 9 Sept. 2022.',
			'National Trust. "Woodland Conservation." https://nationaltrust.example.com. Accessed 20 Feb. 2021.',
			'Okafor, Grace. "City Planning Basics." 2020, https://urbanplan.example.com. Accessed 1 Nov. 2023.',
			'Climate Research Group. "Rising Sea Levels." https://climategroup.example.com. Accessed 30 June 2022.',
		);
	}

	// -----------------------------------------------------------------
	// Variant 1 — Complete Reference.
	// -----------------------------------------------------------------
	private static function build_complete_reference( array $fields ) {
		$correct = self::correct_reference( $fields );
		return array(
			'stem'          => 'Which option is the correctly formatted MLA reference for a webpage?',
			'wrongOptions'  => array(
				self::broken_reference( 'title_not_quoted', $fields ),
				self::broken_reference( 'n_d_wrongly_shown', $fields ),
				self::broken_reference( 'missing_accessed_word', $fields ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 2 — Author Name Format (fragment only; individual authors
	// only). The single most MLA-distinctive mistake: wrongly abbreviating
	// the required full given name to a Harvard-style initial.
	// -----------------------------------------------------------------
	private static function build_author_name_format( array $fields ) {
		$author = $fields['author'];
		return array(
			'stem'          => "Which option correctly formats the author's name?",
			'wrongOptions'  => array(
				(string) ( $author['fullName'] ?? trim( ( $author['givenName'] ?? '' ) . ' ' . ( $author['surname'] ?? '' ) ) ),
				sprintf( '%s, %s.', $author['surname'], mb_substr( (string) $author['givenName'], 0, 1 ) . '.' ),
				sprintf( '%s %s', $author['surname'], $author['givenName'] ),
			),
			'correctAnswer' => sprintf( '%s, %s', $author['surname'], $author['givenName'] ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 3 — Title Quotation (fragment only).
	// -----------------------------------------------------------------
	private static function build_title_quotation( array $fields ) {
		$title = $fields['title'];
		return array(
			'stem'          => 'Which option correctly punctuates the page title?',
			'wrongOptions'  => array(
				sprintf( '%s.', $title ),
				sprintf( '‘%s’,', $title ),
				sprintf( '"%s".', $title ),
			),
			'correctAnswer' => sprintf( '"%s."', $title ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 4 — Undated Source. Tests the biggest MLA-vs-Harvard rule
	// directly: whether "n.d." is wrongly used at all. Built as if the
	// record were undated regardless of its own year, so this variant
	// always exercises the same real distinction (a fragment-only
	// comparison, not a whole reconstructed reference, so it stays
	// meaningful either way).
	// -----------------------------------------------------------------
	private static function build_undated_source( array $fields ) {
		$author = Citex_MLA_Reference_Rules::format_website_author( $fields['author'] );
		return array(
			'stem'          => 'A webpage has no identifiable publication date. Which option correctly handles this in MLA style?',
			'wrongOptions'  => array(
				sprintf( '%s "%s." n.d., %s. Accessed %s.', $author, $fields['title'], $fields['url'], $fields['accessedDate'] ),
				sprintf( '%s "%s." (n.d.) %s. Accessed %s.', $author, $fields['title'], $fields['url'], $fields['accessedDate'] ),
				sprintf( '%s "%s." Undated, %s. Accessed %s.', $author, $fields['title'], $fields['url'], $fields['accessedDate'] ),
			),
			'correctAnswer' => sprintf( '%s "%s." %s. Accessed %s.', $author, $fields['title'], $fields['url'], $fields['accessedDate'] ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 5 — Accessed Date Placement (fragment only).
	// -----------------------------------------------------------------
	private static function build_accessed_date_placement( array $fields ) {
		$accessed = $fields['accessedDate'];
		// Every wrong option here must be more than a bare case/whitespace
		// variant of the correct "Accessed DATE." — see the matching
		// comment in Citex_MLA_Edited_Book_Mcq_Variants::
		// build_designation_singular_plural() for why (the case-insensitive
		// MCQ_OPTION_MATCHES_ANSWER check every mechanic in this codebase
		// applies would otherwise treat "accessed DATE." as a duplicate of
		// the correct answer, not a genuine distractor).
		return array(
			'stem'          => 'Which option correctly shows the access date at the end of the reference?',
			'wrongOptions'  => array(
				sprintf( '(Accessed: %s).', $accessed ),
				sprintf( 'Date accessed: %s.', $accessed ),
				sprintf( 'Accessed %s', $accessed ),
			),
			'correctAnswer' => sprintf( 'Accessed %s.', $accessed ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 6 — Identify the Error.
	// -----------------------------------------------------------------
	private static function build_identify_the_error( array $fields ) {
		$kind              = self::pick_error_kind( $fields );
		$broken            = self::broken_reference( $kind, $fields );
		$correct_statement = self::error_statement( $kind );

		$wrong_statements = array();
		foreach ( self::error_kinds( $fields ) as $other_kind ) {
			if ( $other_kind !== $kind ) {
				$wrong_statements[] = self::error_statement( $other_kind );
			}
		}
		$wrong_statements = array_slice( $wrong_statements, 0, 3 );
		while ( count( $wrong_statements ) < 3 ) {
			$wrong_statements[] = self::error_statement( 'n_d_wrongly_shown' ) ?: 'The reference is missing required punctuation.';
		}

		return array(
			'stem'          => sprintf( "Which option correctly identifies the error in this reference?\n\n%s", $broken ),
			'wrongOptions'  => array_slice( $wrong_statements, 0, 3 ),
			'correctAnswer' => $correct_statement,
		);
	}

	// -----------------------------------------------------------------
	// Variant 7 — "Which is NOT a correct reference?"
	// -----------------------------------------------------------------
	private static function build_not_a_correct_reference( array $fields ) {
		$record_seed = implode( '|', array( $fields['title'], $fields['url'], $fields['accessedDate'] ) );
		$kind        = self::pick_error_kind( $fields, '|not_correct' );
		$broken      = self::broken_reference( $kind, $fields );

		$pool = self::valid_reference_pool();
		usort(
			$pool,
			function ( $a, $b ) use ( $record_seed ) {
				$hash_a = crc32( 'mla_website_mcq_not_correct_pool|' . $record_seed . '|' . $a );
				$hash_b = crc32( 'mla_website_mcq_not_correct_pool|' . $record_seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);

		return array(
			'stem'          => 'Which of the following is NOT a correct MLA reference for a webpage?',
			'wrongOptions'  => array_slice( $pool, 0, 3 ),
			'correctAnswer' => $broken,
		);
	}
}
