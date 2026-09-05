<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The user's own fixed catalogue of 16 Book MCQ "variant" templates —
 * replaces every previous Book MCQ mechanic (the original "select the
 * correct full reference" pattern, "Identify the error", and "Choose the
 * correct rule/treatment", all of which stay in place for Edited
 * Book/Journal Article/Website, which this request does not touch).
 *
 * Every variant's stem and all 4 options (one correct, three wrong) are
 * fully Citex-authored, deterministically, from ONE canonical book record
 * {authors, year, title, place, publisher} — the exact same record every
 * other Book mechanic already asks Gemini for. Unlike every prior Book MCQ
 * mechanic, Gemini supplies NOTHING beyond that canonical record: no
 * distractor text, no error reasons, nothing that needs its own
 * plausibility/leakage checking. This is what makes
 * Citex_Generated_Validator::validate_book_mcq_variant() able to recompute
 * and exactly compare the whole question, not just sanity-check it.
 *
 * Pure and static, no WordPress/ACF calls, exactly like
 * Citex_Reference_Rules (which this class depends on for join_people()/
 * build_reference()) — unit-testable directly.
 */
class Citex_Book_Mcq_Variants {

	/**
	 * @return string[] the 16 variant ids.
	 */
	public static function variants() {
		return array(
			'complete_reference',
			'two_authors',
			'three_authors',
			'four_or_more_authors',
			'publication_year',
			'reference_structure',
			'place_and_publisher',
			'missing_publisher',
			'reference_element_order',
			'complete_reference_elements',
			'missing_information',
			'identify_the_error',
			'correct_the_error',
			'compare_similar_references',
			'author_initials',
			'author_order',
		);
	}

	/**
	 * The exact author count a variant requires — [min, max] inclusive —
	 * or null when the variant works with any author count (it only ever
	 * uses the first author, or no author data at all).
	 *
	 * @return array{0:int,1:int}|null
	 */
	public static function variant_author_requirement( $variant ) {
		$map = array(
			'two_authors'           => array( 2, 2 ),
			'three_authors'         => array( 3, 3 ),
			'four_or_more_authors'  => array( 4, PHP_INT_MAX ),
			'author_order'          => array( 2, 2 ),
		);
		return $map[ $variant ] ?? null;
	}

	/**
	 * Deterministically, but effectively unpredictably, picks one variant
	 * per generated question, seeded by that question's own id (same
	 * crc32-seeding pattern as Citex_Reference_Rules::book_dragdrop_design_for()) —
	 * so repeated calls for different questions in one batch land on
	 * different variants, while a re-run with the same id is reproducible
	 * for testing. Every variant compatible with $author_count is equally
	 * likely; there is no single "baseline" here the way the DragDrop
	 * field-variety designs have one — all 16 are equally the point.
	 *
	 * @param string|int $seed         Typically the question's own id (e.g. "BK04").
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
		$index = abs( crc32( 'book_mcq_variant|' . (string) $seed ) ) % count( $compatible );
		return $compatible[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical book record.
	 *
	 * @param string $variant One of self::variants().
	 * @param array  $fields  {authors: array<{surname,initials,fullName}>, year, title, place, publisher}.
	 * @return array{stem: string, wrongOptions: string[], correctAnswer: string}|null null for an unrecognised variant id.
	 */
	public static function build( $variant, array $fields ) {
		switch ( $variant ) {
			case 'complete_reference':
				return self::build_complete_reference( $fields );
			case 'two_authors':
				return self::build_two_authors( $fields );
			case 'three_authors':
				return self::build_three_authors( $fields );
			case 'four_or_more_authors':
				return self::build_four_or_more_authors( $fields );
			case 'publication_year':
				return self::build_publication_year( $fields );
			case 'reference_structure':
				return self::build_reference_structure( $fields );
			case 'place_and_publisher':
				return self::build_place_and_publisher( $fields );
			case 'missing_publisher':
				return self::build_missing_publisher( $fields );
			case 'reference_element_order':
				return self::build_reference_element_order( $fields );
			case 'complete_reference_elements':
				return self::build_complete_reference_elements( $fields );
			case 'missing_information':
				return self::build_missing_information( $fields );
			case 'identify_the_error':
				return self::build_identify_the_error( $fields );
			case 'correct_the_error':
				return self::build_correct_the_error( $fields );
			case 'compare_similar_references':
				return self::build_compare_similar_references( $fields );
			case 'author_initials':
				return self::build_author_initials( $fields );
			case 'author_order':
				return self::build_author_order( $fields );
		}
		return null;
	}

	// -----------------------------------------------------------------
	// Shared helpers.
	// -----------------------------------------------------------------

	private static function correct_reference( array $fields ) {
		return Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_BOOK, $fields );
	}

	private static function author_join( array $authors ) {
		return Citex_Reference_Rules::join_people( $authors );
	}

	/** "Surname, I." — the same per-person format join_people() itself uses. */
	private static function person_surname_initials( array $person ) {
		return sprintf( '%s, %s', $person['surname'], $person['initials'] );
	}

	/** "I. Surname" — the "initials before surname" mistake. */
	private static function person_initials_surname( array $person ) {
		return sprintf( '%s %s', $person['initials'], $person['surname'] );
	}

	/** "Surname, Given Name" — the "full given name instead of initials" mistake. */
	private static function person_surname_given_name( array $person ) {
		return sprintf( '%s, %s', $person['surname'], self::given_name_portion( $person['fullName'] ?? '', $person['surname'] ?? '' ) );
	}

	/**
	 * The given-name portion of a full name once its surname is removed —
	 * used only to build a deliberately WRONG "full name instead of
	 * initials" distractor, never a correct value.
	 */
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

	/** Joins pre-formatted per-person strings the same way join_people() joins "Surname, I." strings: comma-separated, final "and". */
	private static function join_formatted( array $formatted ) {
		if ( 1 === count( $formatted ) ) {
			return $formatted[0];
		}
		$last = array_pop( $formatted );
		return implode( ', ', $formatted ) . ' and ' . $last;
	}

	/** Same as join_formatted() but "&" instead of "and" before the final one. */
	private static function join_formatted_ampersand( array $formatted ) {
		if ( 1 === count( $formatted ) ) {
			return $formatted[0];
		}
		$last = array_pop( $formatted );
		return implode( ', ', $formatted ) . ' & ' . $last;
	}

	/** Joins every pair with "and" instead of commas — the "and" between every name mistake. */
	private static function join_formatted_all_and( array $formatted ) {
		return implode( ' and ', $formatted );
	}

	/**
	 * A deterministic, plausible-looking misspelling of a place name — the
	 * last vowel is swapped for a different one — used only to build a
	 * deliberately WRONG distractor, never a correct value.
	 */
	private static function misspell( $word ) {
		$word = (string) $word;
		for ( $i = strlen( $word ) - 1; $i >= 0; $i-- ) {
			if ( false !== stripos( 'aeiou', $word[ $i ] ) ) {
				$replacement = ( 'a' === strtolower( $word[ $i ] ) ) ? 'e' : 'a';
				return substr( $word, 0, $i ) . $replacement . substr( $word, $i + 1 );
			}
		}
		return $word . 'a';
	}

	/** The letter after this one, wrapping Z back to A — used only for a deliberately WRONG initial. */
	private static function next_letter( $letter ) {
		$letter = strtoupper( substr( (string) $letter, 0, 1 ) );
		if ( '' === $letter || ! ctype_alpha( $letter ) ) {
			return 'X';
		}
		return 'Z' === $letter ? 'A' : chr( ord( $letter ) + 1 );
	}

	// -----------------------------------------------------------------
	// Variant 1 — Complete Reference.
	// -----------------------------------------------------------------
	private static function build_complete_reference( array $fields ) {
		$authors = $fields['authors'];
		$correct = self::correct_reference( $fields );
		$initials_first = self::join_formatted( array_map( function ( $p ) { return self::person_initials_surname( $p ); }, $authors ) );
		return array(
			'stem'          => 'Which option is the correctly formatted Harvard book reference?',
			'wrongOptions'  => array(
				sprintf( '%s (%s) %s. %s: %s.', self::author_join( $authors ), $fields['year'], $fields['title'], $fields['publisher'], $fields['place'] ),
				sprintf( '%s (%s) %s. %s: %s.', $initials_first, $fields['year'], $fields['title'], $fields['place'], $fields['publisher'] ),
				sprintf( '%s %s %s. %s: %s.', self::author_join( $authors ), $fields['year'], $fields['title'], $fields['place'], $fields['publisher'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 2 — Two Authors (exactly 2 authors required).
	// -----------------------------------------------------------------
	private static function build_two_authors( array $fields ) {
		$authors = $fields['authors'];
		return array(
			'stem'          => 'Which option correctly formats the authors?',
			'wrongOptions'  => array(
				self::join_formatted( array_map( function ( $p ) { return self::person_surname_given_name( $p ); }, $authors ) ),
				self::join_formatted( array_map( function ( $p ) { return self::person_initials_surname( $p ); }, $authors ) ),
				self::join_formatted_ampersand( array_map( function ( $p ) { return self::person_surname_initials( $p ); }, $authors ) ),
			),
			'correctAnswer' => self::author_join( $authors ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 3 — Three Authors (exactly 3 authors required).
	// -----------------------------------------------------------------
	private static function build_three_authors( array $fields ) {
		$authors = $fields['authors'];
		return array(
			'stem'          => 'Which option correctly formats three authors?',
			'wrongOptions'  => array(
				self::join_formatted_all_and( array_map( function ( $p ) { return self::person_surname_initials( $p ); }, $authors ) ),
				self::join_formatted( array_map( function ( $p ) { return self::person_initials_surname( $p ); }, $authors ) ),
				self::join_formatted_ampersand( array_map( function ( $p ) { return self::person_surname_initials( $p ); }, $authors ) ),
			),
			'correctAnswer' => self::author_join( $authors ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 4 — Four (or more) Authors. Harvard's real rule is "list
	// every author in full, never et al." — the correct answer lists ALL
	// of them, however many there are; only the distractors truncate.
	// -----------------------------------------------------------------
	private static function build_four_or_more_authors( array $fields ) {
		$authors = $fields['authors'];
		$first   = self::person_surname_initials( $authors[0] );
		$first_two = self::join_formatted( array_map( function ( $p ) { return self::person_surname_initials( $p ); }, array_slice( $authors, 0, 2 ) ) );
		$truncated = self::author_join( array_slice( $authors, 0, count( $authors ) - 1 ) );
		return array(
			'stem'          => 'Which option correctly formats four authors?',
			'wrongOptions'  => array(
				$first . ' et al.',
				$first_two . ' et al.',
				$truncated,
			),
			'correctAnswer' => self::author_join( $authors ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 5 — Publication Year.
	// -----------------------------------------------------------------
	private static function build_publication_year( array $fields ) {
		$authors = self::author_join( $fields['authors'] );
		return array(
			'stem'          => 'Which option correctly formats the publication year?',
			'wrongOptions'  => array(
				sprintf( '%s (%s.) %s. %s: %s.', $authors, $fields['year'], $fields['title'], $fields['place'], $fields['publisher'] ),
				sprintf( '%s %s %s. %s: %s.', $authors, $fields['year'], $fields['title'], $fields['place'], $fields['publisher'] ),
				sprintf( '%s [%s] %s. %s: %s.', $authors, $fields['year'], $fields['title'], $fields['place'], $fields['publisher'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 6 — Reference Structure. Fully static — no book data at all.
	// -----------------------------------------------------------------
	private static function build_reference_structure( array $fields ) {
		return array(
			'stem'          => 'Which option shows the correct order of the main reference elements?',
			'wrongOptions'  => array(
				'Author → Title → Year → Publisher → Place',
				'Title → Author → Year → Place → Publisher',
				'Author → Publisher → Title → Year → Place',
			),
			'correctAnswer' => 'Author → Year → Title → Place → Publisher',
		);
	}

	// -----------------------------------------------------------------
	// Variant 7 — Place and Publisher.
	// -----------------------------------------------------------------
	private static function build_place_and_publisher( array $fields ) {
		return array(
			'stem'          => 'Which option correctly orders the place of publication and publisher?',
			'wrongOptions'  => array(
				sprintf( '%s: %s', $fields['publisher'], $fields['place'] ),
				sprintf( '%s, %s', $fields['place'], $fields['publisher'] ),
				sprintf( '%s, %s', $fields['publisher'], $fields['place'] ),
			),
			'correctAnswer' => sprintf( '%s: %s', $fields['place'], $fields['publisher'] ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 8 — Missing Publisher (fill in the blank).
	// -----------------------------------------------------------------
	private static function build_missing_publisher( array $fields ) {
		$authors = self::author_join( $fields['authors'] );
		$stem = sprintf(
			"Which option correctly completes the reference?\n\n%s (%s) %s. %s: ______.",
			$authors,
			$fields['year'],
			$fields['title'],
			$fields['place']
		);
		return array(
			'stem'          => $stem,
			'wrongOptions'  => array(
				$fields['authors'][0]['surname'],
				(string) $fields['year'],
				$fields['title'],
			),
			'correctAnswer' => $fields['publisher'],
		);
	}

	// -----------------------------------------------------------------
	// Variant 9 — Reference Element Order (arrow-joined, real values).
	// -----------------------------------------------------------------
	private static function build_reference_element_order( array $fields ) {
		$authors = self::author_join( $fields['authors'] );
		$year_paren = sprintf( '(%s)', $fields['year'] );
		return array(
			'stem'          => 'Which option places the reference information in the correct order?',
			'wrongOptions'  => array(
				sprintf( '%s → %s → %s → %s → %s', $authors, $fields['title'], $year_paren, $fields['publisher'], $fields['place'] ),
				sprintf( '%s → %s → %s → %s → %s', $fields['title'], $authors, $year_paren, $fields['place'], $fields['publisher'] ),
				sprintf( '%s → %s → %s → %s → %s', $authors, $fields['publisher'], $year_paren, $fields['title'], $fields['place'] ),
			),
			'correctAnswer' => sprintf( '%s → %s → %s → %s → %s', $authors, $year_paren, $fields['title'], $fields['place'], $fields['publisher'] ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 10 — Complete Reference (contains all required elements).
	// -----------------------------------------------------------------
	private static function build_complete_reference_elements( array $fields ) {
		$authors = self::author_join( $fields['authors'] );
		return array(
			'stem'          => 'Which option contains all the required core elements of a book reference?',
			'wrongOptions'  => array(
				sprintf( '%s (%s) %s. %s.', $authors, $fields['year'], $fields['title'], $fields['place'] ),
				sprintf( '%s %s. %s: %s.', $authors, $fields['title'], $fields['place'], $fields['publisher'] ),
				sprintf( '%s (%s) %s: %s.', $authors, $fields['year'], $fields['place'], $fields['publisher'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 11 — Missing Information (identify the missing element).
	// -----------------------------------------------------------------
	private static function build_missing_information( array $fields ) {
		$authors = self::author_join( $fields['authors'] );
		$stem = sprintf(
			"Which option identifies the missing element?\n\n%s (%s) %s. ______: %s.",
			$authors,
			$fields['year'],
			$fields['title'],
			$fields['publisher']
		);
		return array(
			'stem'          => $stem,
			'wrongOptions'  => array( 'Author', 'Publication year', 'Book title' ),
			'correctAnswer' => 'Place of publication',
		);
	}

	// -----------------------------------------------------------------
	// Variant 12 — Identify the Error (place/publisher shown reversed).
	// -----------------------------------------------------------------
	private static function build_identify_the_error( array $fields ) {
		$authors = self::author_join( $fields['authors'] );
		$stem = sprintf(
			"Which option correctly identifies the error?\n\n%s (%s) %s. %s: %s.",
			$authors,
			$fields['year'],
			$fields['title'],
			$fields['publisher'],
			$fields['place']
		);
		return array(
			'stem'          => $stem,
			'wrongOptions'  => array(
				'The author is incorrectly formatted.',
				'The publication year is incorrectly positioned.',
				'The title is incorrectly positioned.',
			),
			'correctAnswer' => 'The place and publisher are reversed.',
		);
	}

	// -----------------------------------------------------------------
	// Variant 13 — Correct the Error (place/publisher shown reversed).
	// -----------------------------------------------------------------
	private static function build_correct_the_error( array $fields ) {
		$authors = self::author_join( $fields['authors'] );
		$stem = sprintf(
			"Which option correctly fixes the reference?\n\n%s (%s) %s. %s: %s.",
			$authors,
			$fields['year'],
			$fields['title'],
			$fields['publisher'],
			$fields['place']
		);
		return array(
			'stem'          => $stem,
			'wrongOptions'  => array(
				sprintf( '%s (%s) %s. %s, %s.', $authors, $fields['year'], $fields['title'], $fields['publisher'], $fields['place'] ),
				sprintf( '%s (%s) %s: %s. %s.', $authors, $fields['year'], $fields['place'], $fields['title'], $fields['publisher'] ),
				sprintf( '%s (%s) %s: %s. %s.', $authors, $fields['year'], $fields['publisher'], $fields['title'], $fields['place'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 14 — Compare Similar References.
	// -----------------------------------------------------------------
	private static function build_compare_similar_references( array $fields ) {
		$authors = self::author_join( $fields['authors'] );
		return array(
			'stem'          => 'Which option is correctly formatted?',
			'wrongOptions'  => array(
				sprintf( '%s (%s) %s. %s: %s.', $authors, $fields['year'], $fields['title'], $fields['publisher'], $fields['place'] ),
				sprintf( '%s %s %s. %s: %s.', $authors, $fields['year'], $fields['title'], $fields['place'], $fields['publisher'] ),
				sprintf( '%s (%s) %s: %s. %s.', $authors, $fields['year'], self::misspell( $fields['place'] ), $fields['title'], $fields['publisher'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 15 — Author Initials (uses the first author only).
	// -----------------------------------------------------------------
	private static function build_author_initials( array $fields ) {
		$person       = $fields['authors'][0];
		$real_initial = strtoupper( substr( (string) $person['initials'], 0, 1 ) );
		$wrong_initial = self::next_letter( $real_initial ) . '.';
		$given_name    = self::given_name_portion( $person['fullName'] ?? '', $person['surname'] );
		return array(
			'stem'          => "Which option correctly formats the author's name?",
			'wrongOptions'  => array(
				sprintf( '%s, %s', $person['surname'], $wrong_initial ),
				sprintf( '%s %s, %s', $person['initials'], $person['surname'], $wrong_initial ),
				sprintf( '%s, %s.', $given_name, substr( $person['surname'], 0, 1 ) ),
			),
			'correctAnswer' => sprintf( '%s, %s', $person['surname'], $person['initials'] ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 16 — Author Order (exactly 2 authors required — Harvard
	// preserves the given order, never reorders alphabetically).
	// -----------------------------------------------------------------
	private static function build_author_order( array $fields ) {
		$authors = $fields['authors'];
		return array(
			'stem'          => 'Which option correctly preserves the author order?',
			'wrongOptions'  => array(
				self::join_formatted( array_map( function ( $p ) { return self::person_surname_initials( $p ); }, array_reverse( $authors ) ) ),
				self::join_formatted( array_map( function ( $p ) { return self::person_initials_surname( $p ); }, $authors ) ),
				self::join_formatted( array_map( function ( $p ) { return self::person_surname_given_name( $p ); }, $authors ) ),
			),
			'correctAnswer' => self::author_join( $authors ),
		);
	}
}
