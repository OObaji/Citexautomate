<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MHRA Journal Article's own fixed catalogue of MCQ "variant" templates —
 * mirrors Citex_MHRA_Edited_Book_Mcq_Variants exactly (same "Citex authors
 * the ENTIRE question deterministically from one canonical record"
 * principle), swapping Edited Book's own designation-shaped variant for this
 * category's own defining rule — 'volume_issue_and_year_format' — testing
 * whether the volume and issue are combined into one "Volume.Issue" token
 * with the year in its OWN parenthesis straight after it, the single most
 * MHRA-distinctive Journal Article rule (see Citex_MHRA_Reference_Rules's
 * own docblock).
 *
 * 'two_author_joining' requires exactly 2 authors; 'three_or_more_author_joining'
 * requires 3 or more — every other variant works with any author count.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_MHRA_Journal_Article_Mcq_Variants {

	/**
	 * Variants whose `correctAnswer` and `wrongOptions` are entirely
	 * fixed/static, independent of this specific record's own content — must
	 * be skipped by duplicate-reference detection.
	 *
	 * @return string[] variant ids.
	 */
	public static function mhra_journal_article_independent_answer_variants() {
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
			'volume_issue_and_year_format',
			'identify_the_error',
			'not_a_correct_reference',
		);
	}

	/**
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
	 * @param string|int $seed         Typically the question's own id (e.g. "HJ04").
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
		$index = abs( crc32( 'mhra_journal_article_mcq_variant|' . (string) $seed ) ) % count( $compatible );
		return $compatible[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical MHRA journal
	 * article record.
	 *
	 * @param string $variant One of self::variants().
	 * @param array  $fields  {authors: array<{surname, givenName, fullName}>, articleTitle, journalTitle, volume, issue, year, pages}.
	 * @return array{stem: string, wrongOptions: string[], correctAnswer: string}|null
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
			case 'volume_issue_and_year_format':
				return self::build_volume_issue_and_year_format( $fields );
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
		return Citex_MHRA_Reference_Rules::build_reference( Citex_MHRA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields );
	}

	/** Builds the full reference from explicit parts, mirroring Citex_MHRA_Reference_Rules::build_journal_article_reference()'s own shape. */
	private static function full_reference( $author_segment, $article_title, $journal_title, $volume, $issue, $year, $pages ) {
		return sprintf( "%s, '%s', %s, %s.%s (%s), %s.", $author_segment, $article_title, $journal_title, $volume, $issue, $year, $pages );
	}

	/**
	 * The first author's segment rendered with an INITIAL instead of their
	 * full given name — same technique as
	 * Citex_MHRA_Edited_Book_Mcq_Variants::editor_segment_initial().
	 */
	private static function author_segment_initial( array $authors ) {
		$modified = $authors;
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
		return array( 'author_not_full_given_name', 'article_title_double_quoted', 'volume_issue_not_combined', 'year_wrong_placement', 'missing_final_period' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'author_not_full_given_name'  => 'The first author\'s given name is abbreviated to an initial instead of shown in full.',
			'article_title_double_quoted' => 'The article title is wrapped in double quotation marks instead of single quotation marks.',
			'volume_issue_not_combined'   => 'The volume and issue are kept separate instead of being combined into one "Volume.Issue" token.',
			'year_wrong_placement'        => 'The year is placed straight after the authors instead of in its own parenthesis after the volume and issue.',
			'missing_final_period'        => 'The reference is missing its final full stop.',
		);
		return $statements[ $kind ] ?? '';
	}

	/** Builds a reference with exactly one structural mistake injected, per $kind. */
	private static function broken_reference( $kind, array $authors, $article_title, $journal_title, $volume, $issue, $year, $pages ) {
		$author_segment  = Citex_MHRA_Reference_Rules::join_people( $authors );
		$formatted_pages = Citex_MHRA_Reference_Rules::format_page_range( $pages );
		switch ( $kind ) {
			case 'author_not_full_given_name':
				return self::full_reference( self::author_segment_initial( $authors ), $article_title, $journal_title, $volume, $issue, $year, $formatted_pages );
			case 'article_title_double_quoted':
				return sprintf( '%s, "%s", %s, %s.%s (%s), %s.', $author_segment, $article_title, $journal_title, $volume, $issue, $year, $formatted_pages );
			case 'volume_issue_not_combined':
				return sprintf( "%s, '%s', %s, %s(%s) (%s), %s.", $author_segment, $article_title, $journal_title, $volume, $issue, $year, $formatted_pages );
			case 'year_wrong_placement':
				return sprintf( "%s (%s), '%s', %s, %s.%s, %s.", $author_segment, $year, $article_title, $journal_title, $volume, $issue, $formatted_pages );
			case 'missing_final_period':
				return rtrim( self::full_reference( $author_segment, $article_title, $journal_title, $volume, $issue, $year, $formatted_pages ), '.' );
		}
		return self::full_reference( $author_segment, $article_title, $journal_title, $volume, $issue, $year, $formatted_pages );
	}

	/** Deterministically picks one error kind, seeded by the record's own content. */
	private static function pick_error_kind( $record_seed ) {
		$kinds = self::error_kinds();
		return $kinds[ abs( crc32( 'mhra_journal_article_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	/**
	 * A small, fixed pool of ready-made, fully self-contained, correctly
	 * formatted MHRA journal article references for DIFFERENT invented
	 * articles — used only by not_a_correct_reference.
	 *
	 * @return string[] 6 ready-built, correctly-formatted references.
	 */
	private static function valid_reference_pool() {
		return array(
			self::full_reference( 'Bennett, Clara', 'The Silent Ocean', 'Marine Studies Review', '4', '2', '2018', '12–20' ),
			self::full_reference( 'Diaz, Marco, and Priya Nair', 'Modern Economies', 'Economic Perspectives', '11', '1', '2019', '55–70' ),
			self::full_reference( 'Chen, Wei, George Okafor, and Tom Bishop', 'Machine Learning Basics', 'Computing Review', '8', '4', '2020', '101–115' ),
			self::full_reference( 'Okafor, George', 'Urban Planning Today', 'Planning Quarterly', '6', '3', '2017', '33–48' ),
			self::full_reference( 'Novak, Sofia, and Tom Bishop', 'Climate Futures', 'Environmental Studies', '15', '2', '2022', '5–19' ),
			self::full_reference( 'Ibrahim, Yara, Wei Chen, and Marco Diaz', 'Global Health Policy', 'Health Policy Journal', '9', '1', '2021', '77–90' ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 1 — Complete Reference (the baseline mechanic).
	// -----------------------------------------------------------------
	private static function build_complete_reference( array $fields ) {
		$authors = $fields['authors'];
		$correct = self::correct_reference( $fields );
		return array(
			'stem'          => 'Which option is the correctly formatted reference for a journal article?',
			'wrongOptions'  => array(
				self::broken_reference( 'author_not_full_given_name', $authors, $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['year'], $fields['pages'] ),
				self::broken_reference( 'article_title_double_quoted', $authors, $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['year'], $fields['pages'] ),
				self::broken_reference( 'volume_issue_not_combined', $authors, $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['year'], $fields['pages'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 2 — Author Name Format (fragment only, the FIRST author only,
	// for any author count). The full given name, never an initial.
	// -----------------------------------------------------------------
	private static function build_author_name_format( array $fields ) {
		$person = $fields['authors'][0];
		return array(
			'stem'          => "Which option correctly formats the first author's name for the MHRA Bibliography?",
			'wrongOptions'  => array(
				sprintf( '%s %s', $person['givenName'], $person['surname'] ),
				sprintf( '%s, %s.', $person['surname'], mb_strtoupper( mb_substr( trim( (string) $person['givenName'] ), 0, 1 ) ) ),
				sprintf( '%s %s.', $person['surname'], $person['givenName'] ),
			),
			'correctAnswer' => sprintf( '%s, %s', $person['surname'], $person['givenName'] ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 3 — Two-Author Joining (exactly 2 authors required). Only the
	// FIRST author is inverted; the second keeps natural word order, with a
	// comma before "and" even at exactly 2.
	// -----------------------------------------------------------------
	private static function build_two_author_joining( array $fields ) {
		$authors = $fields['authors'];
		$first   = $authors[0];
		$second  = $authors[1];
		$correct = Citex_MHRA_Reference_Rules::join_people( $authors );
		return array(
			'stem'          => 'Which option correctly joins two authors for this reference?',
			'wrongOptions'  => array(
				sprintf( '%s, %s and %s %s', $first['surname'], $first['givenName'], $second['givenName'], $second['surname'] ),
				sprintf( '%s, %s, & %s %s', $first['surname'], $first['givenName'], $second['givenName'], $second['surname'] ),
				sprintf( '%s, %s, and %s, %s', $first['surname'], $first['givenName'], $second['surname'], $second['givenName'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 4 — Three-or-More Author Joining (3+ authors required). Every
	// author after the first stays in natural word order, comma-separated,
	// "and" before the last — wrong options swap in MLA's "et al.",
	// Chicago's "every author inverted", and Harvard's plain "and" (no
	// comma before it).
	// -----------------------------------------------------------------
	private static function build_three_or_more_author_joining( array $fields ) {
		$authors   = $fields['authors'];
		$first     = $authors[0];
		$correct   = Citex_MHRA_Reference_Rules::join_people( $authors );
		$mla_style = sprintf( '%s, %s, et al.', $first['surname'], $first['givenName'] );

		$all_inverted = array();
		foreach ( $authors as $person ) {
			$all_inverted[] = sprintf( '%s, %s', $person['surname'], $person['givenName'] );
		}
		$last_inverted = array_pop( $all_inverted );
		$chicago_style = implode( ', ', $all_inverted ) . ', and ' . $last_inverted;

		$natural_segments = array();
		foreach ( array_slice( $authors, 1 ) as $person ) {
			$natural_segments[] = sprintf( '%s %s', $person['givenName'], $person['surname'] );
		}
		$last_natural    = array_pop( $natural_segments );
		$no_oxford_comma = sprintf( '%s, %s', $first['surname'], $first['givenName'] );
		if ( ! empty( $natural_segments ) ) {
			$no_oxford_comma .= ', ' . implode( ', ', $natural_segments );
		}
		$no_oxford_comma .= ' and ' . $last_natural;

		return array(
			'stem'          => 'Which option correctly names three or more authors for this reference?',
			'wrongOptions'  => array(
				$mla_style,
				$chicago_style,
				$no_oxford_comma,
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 5 — Reference Structure. Fully static — no record data at all.
	// -----------------------------------------------------------------
	private static function build_reference_structure( array $fields ) {
		return array(
			'stem'          => 'Which option shows the correct order of the main Journal Article reference elements?',
			'wrongOptions'  => array(
				'Author → Year → Article title → Journal title, Volume.Issue, Pages',
				'Author → Article title → Year → Journal title, Volume.Issue, Pages',
				'Journal title → Author → Article title → Volume.Issue (Year), Pages',
			),
			'correctAnswer' => 'Author → Article title → Journal title, Volume.Issue (Year), Pages',
		);
	}

	// -----------------------------------------------------------------
	// Variant 6 — Volume, Issue and Year Format. Tests the single most
	// MHRA-distinctive Journal Article rule: volume and issue combined
	// into one "Volume.Issue" token, with the year in its OWN parenthesis
	// straight after it.
	// -----------------------------------------------------------------
	private static function build_volume_issue_and_year_format( array $fields ) {
		$author_segment  = Citex_MHRA_Reference_Rules::join_people( $fields['authors'] );
		$formatted_pages = Citex_MHRA_Reference_Rules::format_page_range( $fields['pages'] );
		return array(
			'stem'          => 'Which option correctly formats the volume, issue and year for the journal article reference?',
			'wrongOptions'  => array(
				sprintf( "%s, '%s', %s, %s(%s) (%s), %s.", $author_segment, $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['year'], $formatted_pages ),
				sprintf( "%s, '%s', %s, %s, %s (%s), %s.", $author_segment, $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['year'], $formatted_pages ),
				sprintf( "%s, '%s', %s, %s.%s, %s, %s.", $author_segment, $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['year'], $formatted_pages ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 7 — Identify the Error.
	// -----------------------------------------------------------------
	private static function build_identify_the_error( array $fields ) {
		$authors           = $fields['authors'];
		$record_seed       = implode( '|', array( $fields['articleTitle'], $fields['journalTitle'], $fields['year'], (string) count( $authors ) ) );
		$kind              = self::pick_error_kind( $record_seed );
		$broken            = self::broken_reference( $kind, $authors, $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['year'], $fields['pages'] );
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
		$authors     = $fields['authors'];
		$record_seed = implode( '|', array( $fields['articleTitle'], $fields['journalTitle'], $fields['year'], (string) count( $authors ) ) );
		$kind        = self::pick_error_kind( $record_seed . '|not_correct' );
		$broken      = self::broken_reference( $kind, $authors, $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['year'], $fields['pages'] );

		$pool = self::valid_reference_pool();
		usort(
			$pool,
			function ( $a, $b ) use ( $record_seed ) {
				$hash_a = crc32( 'mhra_journal_article_mcq_not_correct_pool|' . $record_seed . '|' . $a );
				$hash_b = crc32( 'mhra_journal_article_mcq_not_correct_pool|' . $record_seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);

		return array(
			'stem'          => 'Which of the following is NOT a correct reference for a journal article?',
			'wrongOptions'  => array_slice( $pool, 0, 3 ),
			'correctAnswer' => $broken,
		);
	}
}
