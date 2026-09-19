<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MLA Journal Article's own fixed catalogue of MCQ "variant" templates —
 * mirrors Citex_MLA_Book_Mcq_Variants's structure exactly, swapping
 * Book's own 'publisher_year_punctuation' for two Journal-Article-
 * specific rules: 'article_title_quotation' (double quotes, period
 * INSIDE — never Harvard's own single-quote-then-comma style) and
 * 'vol_no_labels' ("vol."/"no." — never Harvard's bare "V(I)"
 * shorthand).
 *
 * 'two_author_joining' requires exactly 2 authors; 'et_al_convention'
 * requires 3 or more — every other variant works with any author count.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_MLA_Journal_Article_Mcq_Variants {

	/**
	 * @return string[] the variant ids.
	 */
	public static function variants() {
		return array(
			'complete_reference',
			'author_name_format',
			'two_author_joining',
			'et_al_convention',
			'article_title_quotation',
			'vol_no_labels',
			'identify_the_error',
			'not_a_correct_reference',
		);
	}

	/**
	 * @return array{0:int,1:int}|null
	 */
	public static function variant_author_requirement( $variant ) {
		$map = array(
			'two_author_joining' => array( 2, 2 ),
			'et_al_convention'   => array( 3, PHP_INT_MAX ),
		);
		return $map[ $variant ] ?? null;
	}

	/**
	 * @param string|int $seed         Typically the question's own id (e.g. "MJ04").
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
		$index = abs( crc32( 'mla_journal_article_mcq_variant|' . (string) $seed ) ) % count( $compatible );
		return $compatible[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical MLA journal
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
			case 'et_al_convention':
				return self::build_et_al_convention( $fields );
			case 'article_title_quotation':
				return self::build_article_title_quotation( $fields );
			case 'vol_no_labels':
				return self::build_vol_no_labels( $fields );
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
		return Citex_MLA_Reference_Rules::build_reference( Citex_MLA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields );
	}

	private static function full_reference( $author_segment, $article_title, $journal_title, $volume, $issue, $year, $pages ) {
		return sprintf( '%s "%s." %s, vol. %s, no. %s, %s, pp. %s.', $author_segment, $article_title, $journal_title, $volume, $issue, $year, Citex_Reference_Rules::format_page_range( $pages ) );
	}

	private static function error_kinds() {
		return array( 'author_not_inverted', 'title_not_quoted', 'wrong_labels', 'missing_comma_before_year', 'missing_final_period' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'author_not_inverted'       => 'The first author\'s name is not inverted (surname first).',
			'title_not_quoted'           => 'The article title is not enclosed in double quotation marks.',
			'wrong_labels'                => 'The volume and issue are shown as "Volume(Issue)" instead of "vol. Volume, no. Issue".',
			'missing_comma_before_year'   => 'The comma between the issue and the year is missing.',
			'missing_final_period'        => 'The reference is missing its final full stop.',
		);
		return $statements[ $kind ] ?? '';
	}

	private static function broken_reference( $kind, array $authors, $article_title, $journal_title, $volume, $issue, $year, $pages ) {
		$author_segment = Citex_MLA_Reference_Rules::join_people( $authors );
		$pages_rendered = Citex_Reference_Rules::format_page_range( $pages );
		switch ( $kind ) {
			case 'author_not_inverted':
				return self::full_reference( self::author_segment_not_inverted( $authors ), $article_title, $journal_title, $volume, $issue, $year, $pages );
			case 'title_not_quoted':
				return sprintf( '%s %s. %s, vol. %s, no. %s, %s, pp. %s.', $author_segment, $article_title, $journal_title, $volume, $issue, $year, $pages_rendered );
			case 'wrong_labels':
				return sprintf( '%s "%s." %s, %s(%s), %s, pp. %s.', $author_segment, $article_title, $journal_title, $volume, $issue, $year, $pages_rendered );
			case 'missing_comma_before_year':
				return sprintf( '%s "%s." %s, vol. %s, no. %s %s, pp. %s.', $author_segment, $article_title, $journal_title, $volume, $issue, $year, $pages_rendered );
			case 'missing_final_period':
				return rtrim( self::full_reference( $author_segment, $article_title, $journal_title, $volume, $issue, $year, $pages ), '.' );
		}
		return self::full_reference( $author_segment, $article_title, $journal_title, $volume, $issue, $year, $pages );
	}

	private static function author_segment_not_inverted( array $authors ) {
		$first = $authors[0];
		if ( 1 === count( $authors ) ) {
			return $first['fullName'] . '.';
		}
		if ( 2 === count( $authors ) ) {
			return sprintf( '%s, and %s.', $first['fullName'], $authors[1]['fullName'] );
		}
		return sprintf( '%s, et al.', $first['fullName'] );
	}

	private static function pick_error_kind( $record_seed ) {
		$kinds = self::error_kinds();
		return $kinds[ abs( crc32( 'mla_journal_article_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	/**
	 * A small, fixed pool of ready-made, fully self-contained, correctly
	 * formatted MLA journal article references for DIFFERENT invented
	 * articles — used only by not_a_correct_reference.
	 *
	 * @return string[] 6 ready-built, correctly-formatted references.
	 */
	private static function valid_reference_pool() {
		return array(
			self::full_reference( 'Bennett, Clara.', 'Ocean Currents Revisited', 'Marine Studies', '4', '1', '2018', '10-25' ),
			self::full_reference( 'Diaz, Marco, and Priya Nair.', 'Trade and Growth', 'Economic Review', '7', '2', '2019', '88-101' ),
			self::full_reference( 'Chen, Wei, et al.', 'Neural Networks in Practice', 'Computing Today', '15', '3', '2020', '200-215' ),
			self::full_reference( 'Okafor, Grace.', 'Cities of the Future', 'Urban Studies Quarterly', '9', '4', '2017', '55-70' ),
			self::full_reference( 'Novak, Sara, and Tom Bishop.', 'Rethinking Climate Models', 'Environmental Science', '11', '1', '2022', '1-18' ),
			self::full_reference( 'Ibrahim, Youssef, et al.', 'Global Vaccination Trends', 'Public Health Journal', '6', '2', '2021', '120-140' ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 1 — Complete Reference.
	// -----------------------------------------------------------------
	private static function build_complete_reference( array $fields ) {
		$authors = $fields['authors'];
		$correct = self::correct_reference( $fields );
		$args    = array( $authors, $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['year'], $fields['pages'] );
		return array(
			'stem'          => 'Which option is the correctly formatted reference for a journal article?',
			'wrongOptions'  => array(
				self::broken_reference( 'author_not_inverted', ...$args ),
				self::broken_reference( 'title_not_quoted', ...$args ),
				self::broken_reference( 'wrong_labels', ...$args ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 2 — Author Name Format (fragment only).
	// -----------------------------------------------------------------
	private static function build_author_name_format( array $fields ) {
		$person = $fields['authors'][0];
		return array(
			'stem'          => "Which option correctly formats the author's name?",
			'wrongOptions'  => array(
				(string) $person['fullName'],
				sprintf( '%s, %s.', $person['surname'], mb_substr( (string) $person['givenName'], 0, 1 ) . '.' ),
				sprintf( '%s %s', $person['surname'], $person['givenName'] ),
			),
			'correctAnswer' => sprintf( '%s, %s', $person['surname'], $person['givenName'] ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 3 — Two-Author Joining (exactly 2 authors required).
	// -----------------------------------------------------------------
	private static function build_two_author_joining( array $fields ) {
		$first  = $fields['authors'][0];
		$second = $fields['authors'][1];
		$correct = sprintf( '%s, %s, and %s.', $first['surname'], $first['givenName'], $second['fullName'] );
		return array(
			'stem'          => 'Which option correctly joins two authors for the MLA Works Cited entry?',
			'wrongOptions'  => array(
				sprintf( '%s, %s and %s.', $first['surname'], $first['givenName'], $second['fullName'] ),
				sprintf( '%s, %s, & %s.', $first['surname'], $first['givenName'], $second['fullName'] ),
				sprintf( '%s, %s, and %s, %s.', $first['surname'], $first['givenName'], $second['surname'], $second['givenName'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 4 — "et al." Convention (3 or more authors required).
	// -----------------------------------------------------------------
	private static function build_et_al_convention( array $fields ) {
		$authors = $fields['authors'];
		$first   = $authors[0];
		$correct = sprintf( '%s, %s, et al.', $first['surname'], $first['givenName'] );
		$harvard_style = Citex_Reference_Rules::join_people(
			array_map(
				function ( $person ) {
					return array( 'surname' => $person['surname'], 'initials' => $person['givenName'] );
				},
				$authors
			)
		);
		return array(
			'stem'          => 'Which option correctly names the authors for an article with three or more authors?',
			'wrongOptions'  => array(
				$harvard_style . '.',
				sprintf( '%s, %s, et al', $first['surname'], $first['givenName'] ),
				sprintf( '%s, %s, and others.', $first['surname'], $first['givenName'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 5 — Article Title Quotation (fragment only). The single
	// most MLA-distinctive rule for this category: double quotes, period
	// INSIDE the closing quote.
	// -----------------------------------------------------------------
	private static function build_article_title_quotation( array $fields ) {
		$title = $fields['articleTitle'];
		return array(
			'stem'          => 'Which option correctly punctuates the article title?',
			'wrongOptions'  => array(
				sprintf( '‘%s’,', $title ),
				sprintf( '"%s".', $title ),
				sprintf( '%s.', $title ),
			),
			'correctAnswer' => sprintf( '"%s."', $title ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 6 — Volume/Issue Labels (fragment only). "vol."/"no." —
	// never Harvard's bare "V(I)" shorthand.
	// -----------------------------------------------------------------
	private static function build_vol_no_labels( array $fields ) {
		$volume = $fields['volume'];
		$issue  = $fields['issue'];
		// Every wrong option here must be more than a bare case/whitespace
		// variant of the correct "vol. V, no. I" — see the matching
		// comment in Citex_MLA_Edited_Book_Mcq_Variants::
		// build_designation_singular_plural() for why (a case-only
		// mistake like "Vol. V, No. I" would collide with the correct
		// answer under the case-insensitive MCQ_OPTION_MATCHES_ANSWER
		// check every mechanic in this codebase applies).
		return array(
			'stem'          => 'Which option correctly labels the volume and issue?',
			'wrongOptions'  => array(
				sprintf( '%s(%s)', $volume, $issue ),
				sprintf( 'Volume %s, Issue %s', $volume, $issue ),
				sprintf( 'vol %s, no %s', $volume, $issue ),
			),
			'correctAnswer' => sprintf( 'vol. %s, no. %s', $volume, $issue ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 7 — Identify the Error.
	// -----------------------------------------------------------------
	private static function build_identify_the_error( array $fields ) {
		$authors     = $fields['authors'];
		$record_seed = implode( '|', array( $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['year'], (string) count( $authors ) ) );
		$kind        = self::pick_error_kind( $record_seed );
		$args        = array( $authors, $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['year'], $fields['pages'] );
		$broken      = self::broken_reference( $kind, ...$args );
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
		$record_seed = implode( '|', array( $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['year'], (string) count( $authors ) ) );
		$kind        = self::pick_error_kind( $record_seed . '|not_correct' );
		$args        = array( $authors, $fields['articleTitle'], $fields['journalTitle'], $fields['volume'], $fields['issue'], $fields['year'], $fields['pages'] );
		$broken      = self::broken_reference( $kind, ...$args );

		$pool = self::valid_reference_pool();
		usort(
			$pool,
			function ( $a, $b ) use ( $record_seed ) {
				$hash_a = crc32( 'mla_journal_article_mcq_not_correct_pool|' . $record_seed . '|' . $a );
				$hash_b = crc32( 'mla_journal_article_mcq_not_correct_pool|' . $record_seed . '|' . $b );
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
