<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Website's own fixed catalogue of MCQ "variant" templates — mirrors
 * Citex_Book_Mcq_Variants exactly (same "Citex authors the ENTIRE
 * question deterministically from one canonical record; Gemini supplies
 * nothing beyond that record" principle, same crc32-seeded per-question
 * variant selection, same exact-match validator story), replacing the
 * previous single fixed "Which of the following is the correct Harvard
 * reference for a website?" mechanic (which asked Gemini to author 3
 * plausible-but-wrong `distractors` itself) — added after a request for
 * more question variety, matching what Book already has.
 *
 * Unlike Book, Website's canonical record is never multi-person (always
 * exactly one author-or-organisation — see
 * Citex_Reference_Rules::format_website_author()), so there is no
 * author-count eligibility filtering here: every variant works with any
 * record. Both author types (individual and organisation) are handled by
 * every variant that touches the author field.
 *
 * Pure and static, no WordPress/ACF calls, exactly like
 * Citex_Reference_Rules and Citex_Book_Mcq_Variants — unit-testable
 * directly.
 */
class Citex_Website_Mcq_Variants {

	/**
	 * Variants whose `correctAnswer` and `wrongOptions` are entirely
	 * fixed/pool-based, independent of this specific record's own
	 * author/year/title/url — mirrors
	 * Citex_Book_Mcq_Variants::book_independent_answer_variants()'s exact
	 * rationale and must be skipped the same way by duplicate-reference
	 * detection, or a second question landing on the same variant always
	 * looks like a duplicate.
	 *
	 * @return string[] variant ids.
	 */
	public static function website_independent_answer_variants() {
		return array( 'reference_structure' );
	}

	/**
	 * @return string[] the variant ids.
	 */
	public static function variants() {
		return array(
			'complete_reference',
			'publication_year',
			'reference_structure',
			'online_and_available_from',
			'url_formatting',
			'accessed_date',
			'author_or_organisation_format',
			'identify_the_error',
			'not_a_correct_reference',
		);
	}

	/**
	 * Deterministically, but effectively unpredictably, picks one variant
	 * per generated question, seeded by that question's own id — same
	 * crc32-seeding pattern as Citex_Book_Mcq_Variants::variant_for(). Every
	 * variant is compatible with both author types, so unlike Book's
	 * equivalent there is no eligibility filter — all variants are equally
	 * likely.
	 *
	 * @param string|int $seed Typically the question's own id (e.g. "WR04").
	 * @return string variant id.
	 */
	public static function variant_for( $seed ) {
		$variants = self::variants();
		$index    = abs( crc32( 'website_mcq_variant|' . (string) $seed ) ) % count( $variants );
		return $variants[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical website
	 * record.
	 *
	 * @param string $variant One of self::variants().
	 * @param array  $fields  {author: {type, surname?, initials?, fullName?, name?}, year, title, url, accessedDate}.
	 * @return array{stem: string, wrongOptions: string[], correctAnswer: string}|null null for an unrecognised variant id.
	 */
	public static function build( $variant, array $fields ) {
		switch ( $variant ) {
			case 'complete_reference':
				return self::build_complete_reference( $fields );
			case 'publication_year':
				return self::build_publication_year( $fields );
			case 'reference_structure':
				return self::build_reference_structure( $fields );
			case 'online_and_available_from':
				return self::build_online_and_available_from( $fields );
			case 'url_formatting':
				return self::build_url_formatting( $fields );
			case 'accessed_date':
				return self::build_accessed_date( $fields );
			case 'author_or_organisation_format':
				return self::build_author_or_organisation_format( $fields );
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
		return Citex_Reference_Rules::format_website_author( $author );
	}

	private static function correct_reference( array $fields ) {
		return Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_WEBSITE, $fields );
	}

	private static function full_reference( $author_display, $year, $title, $url, $accessed ) {
		return sprintf( '%s (%s) %s. Available at: %s (Accessed: %s).', $author_display, $year, $title, $url, $accessed );
	}

	/**
	 * The 5 structural error kinds shared by identify_the_error and
	 * not_a_correct_reference — each pairs a plain-English statement of
	 * what is wrong with a transform that injects exactly that mistake
	 * into an otherwise-correct reference. There is no publisher and no
	 * "[online]" marker in this format at all, so every kind here concerns
	 * only the year parentheses, "Available at:", or the "(Accessed: ...)"
	 * element.
	 *
	 * @return string[] error kind ids, in a fixed order.
	 */
	private static function error_kinds() {
		return array( 'wrong_available_wording', 'missing_available_colon', 'missing_accessed_parens', 'missing_accessed_colon', 'missing_year_parens' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'wrong_available_wording' => 'The reference uses "Available from:" instead of "Available at:".',
			'missing_available_colon' => '"Available at" is missing its colon.',
			'missing_accessed_parens' => 'The accessed date is not enclosed in parentheses.',
			'missing_accessed_colon'  => 'The word "Accessed" is missing its colon.',
			'missing_year_parens'     => 'The year (or "n.d.") is not enclosed in parentheses.',
		);
		return $statements[ $kind ] ?? '';
	}

	/** Builds a reference with exactly one structural mistake injected, per $kind. */
	private static function broken_reference( $kind, $author_display, $year, $title, $url, $accessed ) {
		switch ( $kind ) {
			case 'wrong_available_wording':
				return sprintf( '%s (%s) %s. Available from: %s (Accessed: %s).', $author_display, $year, $title, $url, $accessed );
			case 'missing_available_colon':
				return sprintf( '%s (%s) %s. Available at %s (Accessed: %s).', $author_display, $year, $title, $url, $accessed );
			case 'missing_accessed_parens':
				return sprintf( '%s (%s) %s. Available at: %s Accessed: %s.', $author_display, $year, $title, $url, $accessed );
			case 'missing_accessed_colon':
				return sprintf( '%s (%s) %s. Available at: %s (Accessed %s).', $author_display, $year, $title, $url, $accessed );
			case 'missing_year_parens':
				return sprintf( '%s %s %s. Available at: %s (Accessed: %s).', $author_display, $year, $title, $url, $accessed );
		}
		return self::full_reference( $author_display, $year, $title, $url, $accessed );
	}

	/** Deterministically picks one error kind, seeded by the record's own content. */
	private static function pick_error_kind( $record_seed ) {
		$kinds = self::error_kinds();
		return $kinds[ abs( crc32( 'website_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	/**
	 * A small, fixed pool of ready-made, fully self-contained, correctly
	 * formatted Website references for DIFFERENT invented pages — used
	 * only by not_a_correct_reference, where the question needs 3 options
	 * that are genuinely valid (not distractors to be broken) alongside
	 * one flawed reference for the record actually being asked about.
	 *
	 * @return string[] 6 ready-built, correctly-formatted references.
	 */
	private static function valid_reference_pool() {
		return array(
			self::full_reference( 'Ross, T.', '2019', 'Digital skills', 'https://www.sage.com', '3 March 2025' ),
			self::full_reference( 'World Health Organization', 'n.d.', 'Global health', 'https://www.who.int', '10 June 2024' ),
			self::full_reference( 'Dale, R.', '2021', 'Climate data', 'https://www.bbc.co.uk', '22 January 2026' ),
			self::full_reference( 'Open University', '2020', 'Study skills', 'https://www.mit.edu', '15 May 2025' ),
			self::full_reference( 'Kaur, A.', 'n.d.', 'Ethics review', 'https://www.un.org', '7 August 2024' ),
			self::full_reference( 'National Health Service', '2018', 'Wellbeing guide', 'https://www.nhs.uk', '19 December 2025' ),
		);
	}

	/** "Initials, Surname" — the surname/initials ORDER SWAP mistake (e.g. "L., Cole" instead of "Cole, L."). */
	private static function person_initials_surname( array $person ) {
		return sprintf( '%s, %s', $person['initials'], $person['surname'] );
	}

	/** "Surname, Given Name" — the "full given name instead of initials" mistake. */
	private static function person_surname_given_name( array $person ) {
		return sprintf( '%s, %s', $person['surname'], self::given_name_portion( $person['fullName'] ?? '', $person['surname'] ?? '' ) );
	}

	/** "Surname Initials" — the missing-comma-and-full-stop mistake. */
	private static function person_missing_punctuation( array $person ) {
		return sprintf( '%s %s', $person['surname'], str_replace( '.', '', $person['initials'] ) );
	}

	private static function given_name_portion( $full_name, $surname ) {
		$full_name = trim( (string) $full_name );
		$surname   = trim( (string) $surname );
		if ( '' !== $surname && '' !== $full_name && strlen( $full_name ) > strlen( $surname )
			&& 0 === strcasecmp( substr( $full_name, -strlen( $surname ) ), $surname ) ) {
			return trim( substr( $full_name, 0, strlen( $full_name ) - strlen( $surname ) ) );
		}
		$words = preg_split( '/\s+/', $full_name );
		if ( count( $words ) > 1 ) {
			array_pop( $words );
			return implode( ' ', $words );
		}
		return '' !== $full_name ? $full_name : $surname;
	}

	/** "Last, Rest" — treats an organisation name as if it were a person's surname/given-name, the never-comma-invert mistake. */
	private static function organisation_comma_inverted( $name ) {
		$words = preg_split( '/\s+/', trim( (string) $name ) );
		if ( count( $words ) < 2 ) {
			return $name . ',';
		}
		$last = array_pop( $words );
		return sprintf( '%s, %s', $last, implode( ' ', $words ) );
	}

	// -----------------------------------------------------------------
	// Variant 1 — Complete Reference (the original baseline mechanic).
	// -----------------------------------------------------------------
	private static function build_complete_reference( array $fields ) {
		$author = self::author_display( $fields['author'] );
		$correct = self::correct_reference( $fields );
		return array(
			'stem'          => 'Which option is the correctly formatted Harvard website reference?',
			'wrongOptions'  => array(
				self::broken_reference( 'wrong_available_wording', $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ),
				self::broken_reference( 'missing_available_colon', $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ),
				self::broken_reference( 'missing_accessed_parens', $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 2 — Publication Year (format only — never a content swap
	// between a real year and "n.d.", which the validator can never
	// accept as a genuine format error; see the system instruction's own
	// explicit ban on this mistake type for the same reason).
	// -----------------------------------------------------------------
	private static function build_publication_year( array $fields ) {
		$author = self::author_display( $fields['author'] );
		return array(
			'stem'          => 'Which option correctly formats the year (or "n.d." when no date is available)?',
			'wrongOptions'  => array(
				sprintf( '%s %s %s. Available at: %s (Accessed: %s).', $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ),
				sprintf( '%s (%s). %s. Available at: %s (Accessed: %s).', $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ),
				sprintf( '%s [%s] %s. Available at: %s (Accessed: %s).', $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 3 — Reference Structure. Fully static — no website data at
	// all (see website_independent_answer_variants()).
	// -----------------------------------------------------------------
	private static function build_reference_structure( array $fields ) {
		return array(
			'stem'          => 'Which option shows the correct order of the main Website reference elements?',
			'wrongOptions'  => array(
				'Author/Organisation → Title → Year → URL → Accessed Date',
				'Title → Author/Organisation → Year → URL → Accessed Date',
				'Author/Organisation → Year → URL → Title → Accessed Date',
			),
			'correctAnswer' => 'Author/Organisation → Year → Title → URL → Accessed Date',
		);
	}

	// -----------------------------------------------------------------
	// Variant 4 — "Available at:" wording and placement.
	// -----------------------------------------------------------------
	private static function build_online_and_available_from( array $fields ) {
		$author = self::author_display( $fields['author'] );
		return array(
			'stem'          => 'Which option correctly formats "Available at:"?',
			'wrongOptions'  => array(
				sprintf( '%s (%s) %s. Available from: %s (Accessed: %s).', $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ),
				sprintf( '%s (%s) %s. Available at %s (Accessed: %s).', $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ),
				sprintf( '%s (%s) %s Available at: %s (Accessed: %s).', $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 5 — URL Formatting.
	// -----------------------------------------------------------------
	private static function build_url_formatting( array $fields ) {
		$author = self::author_display( $fields['author'] );
		return array(
			'stem'          => 'Which option correctly formats the web address?',
			'wrongOptions'  => array(
				sprintf( '%s (%s) %s. Available at: <%s> (Accessed: %s).', $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ),
				sprintf( '%s (%s) %s. Available at: [%s] (Accessed: %s).', $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ),
				sprintf( '%s (%s) %s. Available at: (%s) (Accessed: %s).', $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 6 — Accessed Date Formatting.
	// -----------------------------------------------------------------
	private static function build_accessed_date( array $fields ) {
		$author = self::author_display( $fields['author'] );
		return array(
			'stem'          => 'Which option correctly formats the accessed date?',
			'wrongOptions'  => array(
				sprintf( '%s (%s) %s. Available at: %s Accessed: %s.', $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ),
				sprintf( '%s (%s) %s. Available at: %s (Accessed %s).', $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ),
				sprintf( '%s (%s) %s. Available at: %s (Access: %s).', $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 7 — Author/Organisation name formatting (fragment only,
	// not a whole reference — mirrors Book's author_initials variant).
	// -----------------------------------------------------------------
	private static function build_author_or_organisation_format( array $fields ) {
		$author = $fields['author'];
		if ( 'organisation' === ( $author['type'] ?? '' ) ) {
			$name = (string) ( $author['name'] ?? '' );
			return array(
				'stem'          => "Which option correctly formats the organisation's name?",
				'wrongOptions'  => array(
					self::organisation_comma_inverted( $name ),
					'The ' . $name,
					$name . '.',
				),
				'correctAnswer' => $name,
			);
		}
		return array(
			'stem'          => "Which option correctly formats the author's name?",
			'wrongOptions'  => array(
				self::person_initials_surname( $author ),
				self::person_surname_given_name( $author ),
				self::person_missing_punctuation( $author ),
			),
			'correctAnswer' => sprintf( '%s, %s', $author['surname'], $author['initials'] ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 8 — Identify the Error (one deliberate mistake shown; pick
	// the statement that correctly names it).
	// -----------------------------------------------------------------
	private static function build_identify_the_error( array $fields ) {
		$author       = self::author_display( $fields['author'] );
		$record_seed  = implode( '|', array( $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ) );
		$kind         = self::pick_error_kind( $record_seed );
		$broken       = self::broken_reference( $kind, $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] );
		$correct_statement = self::error_statement( $kind );

		$wrong_statements = array();
		foreach ( self::error_kinds() as $other_kind ) {
			if ( $other_kind !== $kind ) {
				$wrong_statements[] = self::error_statement( $other_kind );
			}
		}
		// Deterministically take the first 3 of the remaining 4 statements
		// — stable across recomputation, exactly what the exact-match
		// validator needs.
		$wrong_statements = array_slice( $wrong_statements, 0, 3 );

		return array(
			'stem'          => sprintf( "Which option correctly identifies the error in this reference?\n\n%s", $broken ),
			'wrongOptions'  => $wrong_statements,
			'correctAnswer' => $correct_statement,
		);
	}

	// -----------------------------------------------------------------
	// Variant 9 — "Which is NOT a correct reference?" Unlike every other
	// variant, `correctAnswer` here is the ONE FLAWED reference (the
	// thing the student must pick out), and `wrongOptions` are 3
	// genuinely, independently VALID references for different invented
	// pages — never distractors to be broken. This inversion is exactly
	// what the requested stem needs: with only one canonical record per
	// question, there is no way to construct 3 different but equally
	// "correct" versions of the SAME record, so the other 3 options are
	// instead complete, self-contained, correctly-formatted references
	// for different sources entirely — a real exam would do the same.
	// -----------------------------------------------------------------
	private static function build_not_a_correct_reference( array $fields ) {
		$author      = self::author_display( $fields['author'] );
		$record_seed = implode( '|', array( $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] ) );
		$kind        = self::pick_error_kind( $record_seed . '|not_correct' );
		$broken      = self::broken_reference( $kind, $author, $fields['year'], $fields['title'], $fields['url'], $fields['accessedDate'] );

		$pool = self::valid_reference_pool();
		usort(
			$pool,
			function ( $a, $b ) use ( $record_seed ) {
				$hash_a = crc32( 'website_mcq_not_correct_pool|' . $record_seed . '|' . $a );
				$hash_b = crc32( 'website_mcq_not_correct_pool|' . $record_seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);

		return array(
			'stem'          => 'Which of the following is NOT a correct Harvard reference for a website?',
			'wrongOptions'  => array_slice( $pool, 0, 3 ),
			'correctAnswer' => $broken,
		);
	}
}
