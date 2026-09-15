<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chicago (Author-Date) Journal Article's own fixed catalogue of MCQ
 * "variant" templates — mirrors Citex_Chicago_Book_Mcq_Variants exactly
 * (same "Citex authors the ENTIRE question deterministically from one
 * canonical record" principle), swapping Book's own place/publisher-shaped
 * variants for this category's own defining rules: the article title MUST
 * be double-quoted (like MLA, unlike Harvard's single quotes and APA's no
 * quotes at all), a bare "Volume (Issue)" WITH a space before the
 * parenthesis, a colon (not a comma) before the page range, and NO "pp."
 * prefix at all (like APA, unlike Harvard).
 *
 * 'two_author_joining' requires exactly 2 authors; 'three_or_more_author_joining'
 * requires 3 or more — every other variant works with any author count.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_Chicago_Journal_Article_Mcq_Variants {

	/**
	 * Variants whose `correctAnswer` and `wrongOptions` are entirely
	 * fixed/static, independent of this specific record's own content —
	 * must be skipped by duplicate-reference detection.
	 *
	 * @return string[] variant ids.
	 */
	public static function chicago_journal_article_independent_answer_variants() {
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
			'page_range_punctuation',
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
	 * @param string|int $seed         Typically the question's own id (e.g. "CJ04").
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
		$index = abs( crc32( 'chicago_journal_article_mcq_variant|' . (string) $seed ) ) % count( $compatible );
		return $compatible[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical Chicago
	 * journal article record.
	 *
	 * @param string $variant One of self::variants().
	 * @param array  $fields  {authors: array<{surname, givenName, fullName}>, year, articleTitle, journalTitle, volume, issue, pages}.
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
			case 'page_range_punctuation':
				return self::build_page_range_punctuation( $fields );
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
		return Citex_Chicago_Reference_Rules::build_reference( Citex_Chicago_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields );
	}

	/**
	 * Builds the full reference from explicit parts ($author_segment
	 * expected to already end in its own full stop, exactly like
	 * join_people()'s own output), mirroring
	 * Citex_Chicago_Reference_Rules::build_journal_article_reference()'s
	 * own shape.
	 */
	private static function full_reference( $author_segment, $year, $article_title, $journal_title, $volume, $issue, $pages ) {
		return sprintf( '%s %s. "%s." %s %s (%s): %s.', $author_segment, $year, $article_title, $journal_title, $volume, $issue, $pages );
	}

	private static function author_segment_initial( array $authors ) {
		$modified = $authors;
		$letter   = mb_substr( trim( (string) $modified[0]['givenName'] ), 0, 1 );
		if ( '' !== $letter ) {
			$modified[0]['givenName'] = mb_strtoupper( $letter );
		}
		return Citex_Chicago_Reference_Rules::join_people( $modified );
	}

	/**
	 * The 5 structural error kinds shared by identify_the_error and
	 * not_a_correct_reference.
	 *
	 * @return string[] error kind ids, in a fixed order.
	 */
	private static function error_kinds() {
		return array( 'author_not_full_given_name', 'year_missing_period', 'article_title_not_quoted', 'pp_prefix_wrongly_included', 'missing_final_period' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'author_not_full_given_name' => 'The author\'s given name is abbreviated to an initial instead of shown in full.',
			'year_missing_period'        => 'The full stop after the year is missing.',
			'article_title_not_quoted'   => 'The article title is missing its double quotation marks.',
			'pp_prefix_wrongly_included' => 'A "pp." prefix is wrongly included before the page range.',
			'missing_final_period'       => 'The reference is missing its final full stop.',
		);
		return $statements[ $kind ] ?? '';
	}

	/** Builds a reference with exactly one structural mistake injected, per $kind. */
	private static function broken_reference( $kind, array $authors, $year, $article_title, $journal_title, $volume, $issue, $pages ) {
		$author_segment  = Citex_Chicago_Reference_Rules::join_people( $authors );
		$formatted_pages = Citex_Chicago_Reference_Rules::format_page_range( $pages );
		switch ( $kind ) {
			case 'author_not_full_given_name':
				return self::full_reference( self::author_segment_initial( $authors ), $year, $article_title, $journal_title, $volume, $issue, $formatted_pages );
			case 'year_missing_period':
				return sprintf( '%s %s "%s." %s %s (%s): %s.', $author_segment, $year, $article_title, $journal_title, $volume, $issue, $formatted_pages );
			case 'article_title_not_quoted':
				return sprintf( '%s %s. %s. %s %s (%s): %s.', $author_segment, $year, $article_title, $journal_title, $volume, $issue, $formatted_pages );
			case 'pp_prefix_wrongly_included':
				return sprintf( '%s %s. "%s." %s %s (%s): pp. %s.', $author_segment, $year, $article_title, $journal_title, $volume, $issue, $formatted_pages );
			case 'missing_final_period':
				return rtrim( self::full_reference( $author_segment, $year, $article_title, $journal_title, $volume, $issue, $formatted_pages ), '.' );
		}
		return self::full_reference( $author_segment, $year, $article_title, $journal_title, $volume, $issue, $formatted_pages );
	}

	/** Deterministically picks one error kind, seeded by the record's own content. */
	private static function pick_error_kind( $record_seed ) {
		$kinds = self::error_kinds();
		return $kinds[ abs( crc32( 'chicago_journal_article_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	/**
	 * A small, fixed pool of ready-made, fully self-contained, correctly
	 * formatted Chicago journal article references for DIFFERENT invented
	 * articles — used only by not_a_correct_reference.
	 *
	 * @return string[] 6 ready-built, correctly-formatted references.
	 */
	private static function valid_reference_pool() {
		return array(
			self::full_reference( 'Bennett, Clara.', '2018', 'The Silent Ocean', 'Marine Studies Review', '4', '2', '12–20' ),
			self::full_reference( 'Diaz, Marco, and Priya Nair.', '2019', 'Modern Economies', 'Economic Perspectives', '11', '1', '55–70' ),
			self::full_reference( 'Chen, Wei, George Okafor, and Tom Bishop.', '2020', 'Machine Learning Basics', 'Computing Review', '8', '4', '101–115' ),
			self::full_reference( 'Okafor, George.', '2017', 'Urban Planning Today', 'Planning Quarterly', '6', '3', '33–48' ),
			self::full_reference( 'Novak, Sofia, and Tom Bishop.', '2022', 'Climate Futures', 'Environmental Studies', '15', '2', '5–19' ),
			self::full_reference( 'Ibrahim, Yara, Wei Chen, and Marco Diaz.', '2021', 'Global Health Policy', 'Health Policy Journal', '9', '1', '77–90' ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 1 — Complete Reference (the baseline mechanic).
	// -----------------------------------------------------------------
	private static function build_complete_reference( array $fields ) {
		$authors = $fields['authors'];
		$correct = self::correct_reference( $fields );
		return array(
			'stem'          => 'Which option is the correctly formatted Chicago (Author-Date) journal article reference?',
			'wrongOptions'  => array(
				self::broken_reference( 'author_not_full_given_name', $authors, $fields['year'], $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['pages'] ),
				self::broken_reference( 'article_title_not_quoted', $authors, $fields['year'], $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['pages'] ),
				self::broken_reference( 'pp_prefix_wrongly_included', $authors, $fields['year'], $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['pages'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 2 — Author Name Format (fragment only, the FIRST author
	// only, for any author count).
	// -----------------------------------------------------------------
	private static function build_author_name_format( array $fields ) {
		$person = $fields['authors'][0];
		return array(
			'stem'          => "Which option correctly formats the author's name for the Chicago (Author-Date) reference list?",
			'wrongOptions'  => array(
				sprintf( '%s, %s.', $person['surname'], mb_strtoupper( mb_substr( trim( (string) $person['givenName'] ), 0, 1 ) ) ),
				sprintf( '%s %s', $person['givenName'], $person['surname'] ),
				sprintf( '%s %s.', $person['surname'], $person['givenName'] ),
			),
			'correctAnswer' => sprintf( '%s, %s.', $person['surname'], $person['givenName'] ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 3 — Two-Author Joining (exactly 2 authors required).
	// -----------------------------------------------------------------
	private static function build_two_author_joining( array $fields ) {
		$authors = $fields['authors'];
		$first   = $authors[0];
		$second  = $authors[1];
		$correct = Citex_Chicago_Reference_Rules::join_people( $authors );
		return array(
			'stem'          => 'Which option correctly joins two authors for the Chicago (Author-Date) reference list?',
			'wrongOptions'  => array(
				sprintf( '%s, %s and %s, %s.', $first['surname'], $first['givenName'], $second['surname'], $second['givenName'] ),
				sprintf( '%s, %s, & %s, %s.', $first['surname'], $first['givenName'], $second['surname'], $second['givenName'] ),
				sprintf( '%s, %s, and %s %s.', $first['surname'], $first['givenName'], $second['givenName'], $second['surname'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 4 — Three-or-More Author Joining (3+ authors required).
	// -----------------------------------------------------------------
	private static function build_three_or_more_author_joining( array $fields ) {
		$authors       = $fields['authors'];
		$first         = $authors[0];
		$correct       = Citex_Chicago_Reference_Rules::join_people( $authors );
		$harvard_style = Citex_Reference_Rules::join_people( self::as_harvard_shaped( $authors ) );
		$mla_style     = sprintf( '%s, %s, et al.', $first['surname'], $first['givenName'] );
		$segments      = array();
		foreach ( $authors as $person ) {
			$segments[] = sprintf( '%s, %s', $person['surname'], $person['givenName'] );
		}
		$apa_style = implode( ', ', array_slice( $segments, 0, -1 ) ) . ', & ' . end( $segments ) . '.';
		return array(
			'stem'          => 'Which option correctly names three or more authors for the Chicago (Author-Date) reference list?',
			'wrongOptions'  => array(
				$harvard_style,
				$mla_style,
				$apa_style,
			),
			'correctAnswer' => $correct,
		);
	}

	/** Reshapes {surname, givenName} people into Harvard's own {surname, initials} shape, purely so Citex_Reference_Rules::join_people() can render the Harvard-style comparison string. */
	private static function as_harvard_shaped( array $authors ) {
		return array_map(
			function ( $person ) {
				$letter = mb_substr( trim( (string) $person['givenName'] ), 0, 1 );
				return array( 'surname' => $person['surname'], 'initials' => '' !== $letter ? mb_strtoupper( $letter ) . '.' : '' );
			},
			$authors
		);
	}

	// -----------------------------------------------------------------
	// Variant 5 — Reference Structure. Fully static — no record data at all.
	// -----------------------------------------------------------------
	private static function build_reference_structure( array $fields ) {
		return array(
			'stem'          => 'Which option shows the correct order of the main Chicago (Author-Date) Journal Article reference elements?',
			'wrongOptions'  => array(
				'Author → Article title → Year → Journal title Volume (Issue): Pages',
				'Year → Author → Article title → Journal title Volume (Issue): Pages',
				'Author → Journal title → Year → Article title Volume (Issue): Pages',
			),
			'correctAnswer' => 'Author → Year → Article title → Journal title Volume (Issue): Pages',
		);
	}

	// -----------------------------------------------------------------
	// Variant 6 — Page Range Punctuation. Tests the single most
	// Chicago-distinctive Journal Article rule: a colon (not a comma)
	// before the page range, with NO "pp." prefix.
	// -----------------------------------------------------------------
	private static function build_page_range_punctuation( array $fields ) {
		$author_segment  = Citex_Chicago_Reference_Rules::join_people( $fields['authors'] );
		$formatted_pages = Citex_Chicago_Reference_Rules::format_page_range( $fields['pages'] );
		return array(
			'stem'          => 'Which option correctly shows the page range for the Chicago (Author-Date) reference list?',
			'wrongOptions'  => array(
				sprintf( '%s %s. "%s." %s %s (%s): pp. %s.', $author_segment, $fields['year'], $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $formatted_pages ),
				sprintf( '%s %s. "%s." %s %s (%s), %s.', $author_segment, $fields['year'], $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $formatted_pages ),
				sprintf( '%s %s. "%s." %s %s (%s) %s.', $author_segment, $fields['year'], $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $formatted_pages ),
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
		$broken            = self::broken_reference( $kind, $authors, $fields['year'], $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['pages'] );
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
		$broken      = self::broken_reference( $kind, $authors, $fields['year'], $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['pages'] );

		$pool = self::valid_reference_pool();
		usort(
			$pool,
			function ( $a, $b ) use ( $record_seed ) {
				$hash_a = crc32( 'chicago_journal_article_mcq_not_correct_pool|' . $record_seed . '|' . $a );
				$hash_b = crc32( 'chicago_journal_article_mcq_not_correct_pool|' . $record_seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);

		return array(
			'stem'          => 'Which of the following is NOT a correct Chicago (Author-Date) reference for a journal article?',
			'wrongOptions'  => array_slice( $pool, 0, 3 ),
			'correctAnswer' => $broken,
		);
	}
}
