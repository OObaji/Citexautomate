<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chicago (Author-Date) Website's own fixed catalogue of MCQ "variant"
 * templates — mirrors Citex_APA_Website_Mcq_Variants's own shape
 * (author-format-agnostic: exactly one author-or-organisation via
 * Citex_Chicago_Reference_Rules::format_website_author(), so no variant
 * here ever needs to distinguish the two), adapted for Chicago's own rule
 * (see Citex_Chicago_Reference_Rules's own docblock): NO "Available at:"
 * label and NO accessed date at all (like APA, unlike Harvard/MLA), a
 * double-quoted title (like Journal Article's own quoting rule — the one
 * genuine difference from APA's own unquoted Website title), and "n.d."
 * for a missing year exactly like Harvard/APA.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_Chicago_Website_Mcq_Variants {

	/**
	 * Variants whose `correctAnswer` and `wrongOptions` are entirely
	 * fixed/static, independent of this specific record's own content —
	 * must be skipped by duplicate-reference detection.
	 *
	 * @return string[] variant ids.
	 */
	public static function chicago_website_independent_answer_variants() {
		return array( 'reference_structure' );
	}

	/**
	 * @return string[] the variant ids.
	 */
	public static function variants() {
		return array(
			'complete_reference',
			'publication_year_format',
			'reference_structure',
			'available_at_wrongly_used',
			'accessed_date_wrongly_included',
			'title_quoting',
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
	 * @param string|int $seed Typically the question's own id (e.g. "CW04").
	 * @return string variant id.
	 */
	public static function variant_for( $seed ) {
		$variants = self::variants();
		$index    = abs( crc32( 'chicago_website_mcq_variant|' . (string) $seed ) ) % count( $variants );
		return $variants[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical Chicago
	 * website record.
	 *
	 * @param string $variant One of self::variants().
	 * @param array  $fields  {author: {type, surname?, givenName?, fullName?, name?}, year, title, url}.
	 * @return array{stem: string, wrongOptions: string[], correctAnswer: string}|null
	 */
	public static function build( $variant, array $fields ) {
		switch ( $variant ) {
			case 'complete_reference':
				return self::build_complete_reference( $fields );
			case 'publication_year_format':
				return self::build_publication_year_format( $fields );
			case 'reference_structure':
				return self::build_reference_structure( $fields );
			case 'available_at_wrongly_used':
				return self::build_available_at_wrongly_used( $fields );
			case 'accessed_date_wrongly_included':
				return self::build_accessed_date_wrongly_included( $fields );
			case 'title_quoting':
				return self::build_title_quoting( $fields );
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
		return Citex_Chicago_Reference_Rules::format_website_author( $author );
	}

	private static function correct_reference( array $fields ) {
		return Citex_Chicago_Reference_Rules::build_reference( Citex_Chicago_Reference_Rules::CATEGORY_WEBSITE, $fields );
	}

	/**
	 * "n.d." already carries its own abbreviation period — appending
	 * another would read "n.d.." — so only a real 4-digit year gets one
	 * added here, exactly mirroring
	 * Citex_Chicago_Reference_Rules::build_website_reference()'s own
	 * reasoning.
	 */
	private static function year_segment( $year ) {
		$year = (string) $year;
		return ( 'n.d.' === $year ) ? 'n.d.' : $year . '.';
	}

	/** Builds the full reference from explicit parts, mirroring build_website_reference()'s own shape. */
	private static function full_reference( $author_display, $year, $title, $url ) {
		return sprintf( '%s %s "%s." %s.', $author_display, self::year_segment( $year ), $title, $url );
	}

	/**
	 * The 5 structural error kinds shared by identify_the_error and
	 * not_a_correct_reference — every kind works for both author types.
	 *
	 * @return string[] error kind ids, in a fixed order.
	 */
	private static function error_kinds() {
		return array( 'available_at_wrongly_used', 'accessed_date_wrongly_included', 'title_not_quoted', 'year_wrongly_parenthesised', 'missing_final_period' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'available_at_wrongly_used'      => 'An "Available at:" label is wrongly included — Chicago has no such label.',
			'accessed_date_wrongly_included' => 'An accessed date is wrongly included — Chicago has no accessed-date element for a stable source.',
			'title_not_quoted'               => 'The page title is missing its double quotation marks.',
			'year_wrongly_parenthesised'     => 'The year is wrongly enclosed in parentheses instead of standing on its own.',
			'missing_final_period'           => 'The reference is missing its final full stop.',
		);
		return $statements[ $kind ] ?? '';
	}

	/** Builds a reference with exactly one structural mistake injected, per $kind. */
	private static function broken_reference( $kind, $author_display, $year, $title, $url ) {
		switch ( $kind ) {
			case 'available_at_wrongly_used':
				return sprintf( '%s %s "%s." Available at: %s.', $author_display, self::year_segment( $year ), $title, $url );
			case 'accessed_date_wrongly_included':
				return sprintf( '%s %s "%s." %s (Accessed: 12 March 2024).', $author_display, self::year_segment( $year ), $title, $url );
			case 'title_not_quoted':
				return sprintf( '%s %s %s. %s.', $author_display, self::year_segment( $year ), $title, $url );
			case 'year_wrongly_parenthesised':
				return sprintf( '%s (%s). "%s." %s.', $author_display, $year, $title, $url );
			case 'missing_final_period':
				return rtrim( self::full_reference( $author_display, $year, $title, $url ), '.' );
		}
		return self::full_reference( $author_display, $year, $title, $url );
	}

	/** Deterministically picks one error kind, seeded by the record's own content. */
	private static function pick_error_kind( $record_seed ) {
		$kinds = self::error_kinds();
		return $kinds[ abs( crc32( 'chicago_website_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	/**
	 * A small, fixed pool of ready-made, fully self-contained, correctly
	 * formatted Chicago website references for DIFFERENT invented pages —
	 * used only by not_a_correct_reference.
	 *
	 * @return string[] 6 ready-built, correctly-formatted references.
	 */
	private static function valid_reference_pool() {
		return array(
			self::full_reference( 'Ross, Tom', '2019', 'Digital Skills', 'https://www.sage.com' ),
			self::full_reference( 'World Health Organization', 'n.d.', 'Global Health', 'https://www.who.int' ),
			self::full_reference( 'Dale, Rachel', '2021', 'Climate Data', 'https://www.bbc.co.uk' ),
			self::full_reference( 'Open University', '2020', 'Study Skills', 'https://www.mit.edu' ),
			self::full_reference( 'Kaur, Amrit', 'n.d.', 'Ethics Review', 'https://www.un.org' ),
			self::full_reference( 'National Health Service', '2018', 'Wellbeing Guide', 'https://www.nhs.uk' ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 1 — Complete Reference (the baseline mechanic).
	// -----------------------------------------------------------------
	private static function build_complete_reference( array $fields ) {
		$author  = self::author_display( $fields['author'] );
		$correct = self::correct_reference( $fields );
		return array(
			'stem'          => 'Which option is the correctly formatted Chicago (Author-Date) reference for a webpage?',
			'wrongOptions'  => array(
				self::broken_reference( 'available_at_wrongly_used', $author, $fields['year'], $fields['title'], $fields['url'] ),
				self::broken_reference( 'title_not_quoted', $author, $fields['year'], $fields['title'], $fields['url'] ),
				self::broken_reference( 'year_wrongly_parenthesised', $author, $fields['year'], $fields['title'], $fields['url'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 2 — Publication Year Format (bare year/"n.d." punctuation,
	// no surrounding parentheses at all).
	// -----------------------------------------------------------------
	private static function build_publication_year_format( array $fields ) {
		$year    = (string) $fields['year'];
		$correct = self::year_segment( $year );
		// "n.d." already IS the bare, unpunctuated correct form (see
		// year_segment()'s own docblock), so the bare-$year wrong option
		// used for a real year would collide with the correct answer here
		// — a dedicated wrong-option set keeps every option distinct.
		$wrong_options = ( 'n.d.' === $year )
			? array( '(n.d.)', '(n.d.).', 'n.d' )
			: array( sprintf( '(%s)', $year ), $year, sprintf( '(%s).', $year ) );
		return array(
			'stem'          => 'Which option correctly shows the publication year for the Chicago (Author-Date) reference list?',
			'wrongOptions'  => $wrong_options,
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 3 — Reference Structure. Fully static — no record data at all.
	// -----------------------------------------------------------------
	private static function build_reference_structure( array $fields ) {
		return array(
			'stem'          => 'Which option shows the correct order of the main Chicago (Author-Date) Website reference elements?',
			'wrongOptions'  => array(
				'Author → Title → Year → URL',
				'Year → Author → Title → URL',
				'Author → URL → Year → Title',
			),
			'correctAnswer' => 'Author → Year → Title → URL',
		);
	}

	// -----------------------------------------------------------------
	// Variant 4 — "Available at:" Wrongly Used.
	// -----------------------------------------------------------------
	private static function build_available_at_wrongly_used( array $fields ) {
		$author = self::author_display( $fields['author'] );
		return array(
			'stem'          => 'Which option correctly presents the URL for the Chicago (Author-Date) reference list?',
			'wrongOptions'  => array(
				sprintf( '%s %s "%s." Available at: %s.', $author, self::year_segment( $fields['year'] ), $fields['title'], $fields['url'] ),
				sprintf( '%s %s "%s." Available from: %s.', $author, self::year_segment( $fields['year'] ), $fields['title'], $fields['url'] ),
				sprintf( '%s %s "%s." URL: %s.', $author, self::year_segment( $fields['year'] ), $fields['title'], $fields['url'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 5 — Accessed Date Wrongly Included.
	// -----------------------------------------------------------------
	private static function build_accessed_date_wrongly_included( array $fields ) {
		$author = self::author_display( $fields['author'] );
		return array(
			'stem'          => 'Which option correctly ends the Chicago (Author-Date) reference for this webpage?',
			'wrongOptions'  => array(
				sprintf( '%s %s "%s." %s (Accessed: 12 March 2024).', $author, self::year_segment( $fields['year'] ), $fields['title'], $fields['url'] ),
				sprintf( '%s %s "%s." %s. Retrieved March 12, 2024.', $author, self::year_segment( $fields['year'] ), $fields['title'], $fields['url'] ),
				sprintf( '%s %s "%s." %s (accessed 12/3/24).', $author, self::year_segment( $fields['year'] ), $fields['title'], $fields['url'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 6 — Title Quoting. The single most Chicago-distinctive
	// Website rule alongside APA's own opposite convention: the page
	// title MUST be double-quoted.
	// -----------------------------------------------------------------
	private static function build_title_quoting( array $fields ) {
		$title = (string) $fields['title'];
		return array(
			'stem'          => 'Which option correctly presents the page title for the Chicago (Author-Date) reference list?',
			'wrongOptions'  => array(
				sprintf( '%s.', $title ),
				sprintf( "'%s.'", $title ),
				sprintf( '"%s".', $title ),
			),
			'correctAnswer' => sprintf( '"%s."', $title ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 7 — Identify the Error.
	// -----------------------------------------------------------------
	private static function build_identify_the_error( array $fields ) {
		$author            = self::author_display( $fields['author'] );
		$record_seed       = implode( '|', array( $fields['title'], (string) $fields['year'], $fields['url'] ) );
		$kind              = self::pick_error_kind( $record_seed );
		$broken            = self::broken_reference( $kind, $author, $fields['year'], $fields['title'], $fields['url'] );
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
		$record_seed = implode( '|', array( $fields['title'], (string) $fields['year'], $fields['url'] ) );
		$kind        = self::pick_error_kind( $record_seed . '|not_correct' );
		$broken      = self::broken_reference( $kind, $author, $fields['year'], $fields['title'], $fields['url'] );

		$pool = self::valid_reference_pool();
		usort(
			$pool,
			function ( $a, $b ) use ( $record_seed ) {
				$hash_a = crc32( 'chicago_website_mcq_not_correct_pool|' . $record_seed . '|' . $a );
				$hash_b = crc32( 'chicago_website_mcq_not_correct_pool|' . $record_seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);

		return array(
			'stem'          => 'Which of the following is NOT a correct Chicago (Author-Date) reference for a webpage?',
			'wrongOptions'  => array_slice( $pool, 0, 3 ),
			'correctAnswer' => $broken,
		);
	}
}
