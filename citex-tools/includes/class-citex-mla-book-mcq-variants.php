<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MLA Book's own fixed catalogue of MCQ "variant" templates — mirrors
 * Citex_Book_Mcq_Variants/Citex_Website_Mcq_Variants exactly (same "Citex
 * authors the ENTIRE question deterministically from one canonical record;
 * Gemini supplies nothing beyond that record" principle, same crc32-seeded
 * per-question variant selection, same exact-match validator story).
 *
 * A deliberately CURATED 8-variant catalogue, not a mechanical port of
 * Harvard Book's own 16 — chosen to cover MLA's own distinctive rules
 * (full first names never abbreviated, the "et al." rule for 3+ authors,
 * no place of publication, the year coming last with no parentheses)
 * rather than testing rules that don't even apply the same way under MLA
 * (e.g. Harvard's "author_initials" variant has no direct MLA
 * counterpart, since MLA never uses initials at all).
 *
 * 'two_author_joining' requires exactly 2 authors; 'et_al_convention'
 * requires 3 or more — every other variant works with any author count
 * (see variant_author_requirement()), mirroring
 * Citex_Book_Mcq_Variants::variant_author_requirement()'s identical
 * gating mechanism.
 *
 * Pure and static, no WordPress/ACF calls, exactly like
 * Citex_Reference_Rules/Citex_MLA_Reference_Rules and
 * Citex_Book_Mcq_Variants — unit-testable directly.
 */
class Citex_MLA_Book_Mcq_Variants {

	/**
	 * Variants whose `correctAnswer` and `wrongOptions` are entirely
	 * fixed/static, independent of this specific record's own
	 * author/title/publisher/year — mirrors
	 * Citex_Book_Mcq_Variants::book_independent_answer_variants()'s exact
	 * rationale and must be skipped the same way by duplicate-reference
	 * detection, or a second question landing on the same variant always
	 * looks like a duplicate.
	 *
	 * @return string[] variant ids.
	 */
	public static function mla_book_independent_answer_variants() {
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
			'et_al_convention',
			'reference_structure',
			'publisher_year_punctuation',
			'identify_the_error',
			'not_a_correct_reference',
		);
	}

	/**
	 * The exact author count a variant requires — [min, max] inclusive —
	 * or null when the variant works with any author count.
	 *
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
	 * Deterministically, but effectively unpredictably, picks one
	 * author-count-compatible variant per generated question, seeded by
	 * that question's own id — same crc32-seeding pattern as
	 * Citex_Book_Mcq_Variants::variant_for().
	 *
	 * @param string|int $seed         Typically the question's own id (e.g. "MB04").
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
		$index = abs( crc32( 'mla_book_mcq_variant|' . (string) $seed ) ) % count( $compatible );
		return $compatible[ $index ];
	}

	/**
	 * Builds one variant's stem and 4 options from a canonical MLA book
	 * record.
	 *
	 * @param string $variant One of self::variants().
	 * @param array  $fields  {authors: array<{surname, givenName, fullName}>, title, publisher, year}.
	 * @return array{stem: string, wrongOptions: string[], correctAnswer: string}|null null for an unrecognised variant id.
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
			case 'reference_structure':
				return self::build_reference_structure( $fields );
			case 'publisher_year_punctuation':
				return self::build_publisher_year_punctuation( $fields );
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
		return Citex_MLA_Reference_Rules::build_reference( Citex_MLA_Reference_Rules::CATEGORY_BOOK, $fields );
	}

	/** Builds the full reference from an explicit author-list segment, mirroring Citex_MLA_Reference_Rules::build_book_reference()'s own shape. */
	private static function full_reference( $author_segment, $title, $publisher, $year ) {
		return sprintf( '%s %s. %s, %s.', $author_segment, $title, $publisher, $year );
	}

	/**
	 * The 5 structural error kinds shared by identify_the_error and
	 * not_a_correct_reference — each pairs a plain-English statement of
	 * what is wrong with a transform that injects exactly that mistake
	 * into an otherwise-correct reference.
	 *
	 * @return string[] error kind ids, in a fixed order.
	 */
	private static function error_kinds() {
		return array( 'author_not_inverted', 'wrong_year_position', 'missing_comma_before_year', 'title_period_replaced_by_comma', 'missing_final_period' );
	}

	private static function error_statement( $kind ) {
		$statements = array(
			'author_not_inverted'             => 'The first author\'s name is not inverted (surname first).',
			'wrong_year_position'              => 'The year is placed in parentheses right after the author, instead of after the publisher.',
			'missing_comma_before_year'        => 'The comma between the publisher and the year is missing.',
			'title_period_replaced_by_comma'   => 'The full stop after the title is replaced with a comma.',
			'missing_final_period'             => 'The reference is missing its final full stop.',
		);
		return $statements[ $kind ] ?? '';
	}

	/** Builds a reference with exactly one structural mistake injected, per $kind. */
	private static function broken_reference( $kind, array $authors, $title, $publisher, $year ) {
		$author_segment = Citex_MLA_Reference_Rules::join_people( $authors );
		switch ( $kind ) {
			case 'author_not_inverted':
				$not_inverted = self::author_segment_not_inverted( $authors );
				return self::full_reference( $not_inverted, $title, $publisher, $year );
			case 'wrong_year_position':
				return sprintf( '%s (%s) %s. %s.', rtrim( $author_segment, '.' ) . '.', $year, $title, $publisher );
			case 'missing_comma_before_year':
				return sprintf( '%s %s. %s %s.', $author_segment, $title, $publisher, $year );
			case 'title_period_replaced_by_comma':
				return sprintf( '%s %s, %s, %s.', $author_segment, $title, $publisher, $year );
			case 'missing_final_period':
				return rtrim( self::full_reference( $author_segment, $title, $publisher, $year ), '.' );
		}
		return self::full_reference( $author_segment, $title, $publisher, $year );
	}

	/**
	 * The author segment with the FIRST author's name left in natural
	 * (not inverted) word order — the "author_not_inverted" mistake — for
	 * any author count. A second author (exactly 2) or "et al." (3+) is
	 * still appended exactly as the correct join_people() would, since the
	 * mistake being tested is specifically the first author's own
	 * inversion, not the rest of the author-list rule.
	 */
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

	/** Deterministically picks one error kind, seeded by the record's own content. */
	private static function pick_error_kind( $record_seed ) {
		$kinds = self::error_kinds();
		return $kinds[ abs( crc32( 'mla_book_mcq_error_kind|' . $record_seed ) ) % count( $kinds ) ];
	}

	/**
	 * A small, fixed pool of ready-made, fully self-contained, correctly
	 * formatted MLA book references for DIFFERENT invented books — used
	 * only by not_a_correct_reference, where the question needs 3 options
	 * that are genuinely valid (not distractors to be broken) alongside
	 * one flawed reference for the record actually being asked about.
	 * Spans 1/2/3-author examples so the pool itself models every count.
	 *
	 * @return string[] 6 ready-built, correctly-formatted references.
	 */
	private static function valid_reference_pool() {
		return array(
			self::full_reference( 'Bennett, Clara.', 'The Silent Ocean', 'Faber', '2018' ),
			self::full_reference( 'Diaz, Marco, and Priya Nair.', 'Modern Economies', 'Wiley', '2019' ),
			self::full_reference( 'Chen, Wei, et al.', 'Machine Learning Basics', 'MIT Press', '2020' ),
			self::full_reference( 'Okafor, Grace.', 'Urban Planning Today', 'Routledge', '2017' ),
			self::full_reference( 'Novak, Sara, and Tom Bishop.', 'Climate Futures', 'Springer', '2022' ),
			self::full_reference( 'Ibrahim, Youssef, et al.', 'Global Health Policy', 'SAGE', '2021' ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 1 — Complete Reference (the baseline mechanic).
	// -----------------------------------------------------------------
	private static function build_complete_reference( array $fields ) {
		$authors = $fields['authors'];
		$correct = self::correct_reference( $fields );
		return array(
			'stem'          => 'Which option is the correctly formatted MLA book reference?',
			'wrongOptions'  => array(
				self::broken_reference( 'author_not_inverted', $authors, $fields['title'], $fields['publisher'], $fields['year'] ),
				self::broken_reference( 'wrong_year_position', $authors, $fields['title'], $fields['publisher'], $fields['year'] ),
				self::broken_reference( 'missing_comma_before_year', $authors, $fields['title'], $fields['publisher'], $fields['year'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 2 — Author Name Format (fragment only, not a whole
	// reference — the FIRST author only, for any author count). The single
	// most MLA-distinctive rule: the full first name, never an initial.
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
	// Variant 3 — Two-Author Joining (exactly 2 authors required). MLA
	// keeps a comma before "and" even at exactly 2 — unlike Harvard, which
	// only does this from 3 authors — so that comma is itself worth
	// testing directly.
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
	// Variant 4 — "et al." Convention (3 or more authors required). The
	// single biggest rule INVERSION from Harvard: MLA drops every author
	// after the first for 3+, replacing them with "et al." — Harvard
	// instead forbids "et al." entirely and always lists everyone.
	// -----------------------------------------------------------------
	private static function build_et_al_convention( array $fields ) {
		$authors = $fields['authors'];
		$first   = $authors[0];
		$correct = sprintf( '%s, %s, et al.', $first['surname'], $first['givenName'] );
		// The classic Harvard-style mistake, wrongly applied here: list
		// every author in full, joined Harvard's own way (comma-separated
		// with a final "and") — reuses Citex_Reference_Rules::join_people()
		// directly, feeding each author's given name in as the "initials"
		// field it expects; the field's own content is opaque to that
		// method, so this is a safe, correctly-joined reuse.
		$harvard_style = Citex_Reference_Rules::join_people(
			array_map(
				function ( $person ) {
					return array( 'surname' => $person['surname'], 'initials' => $person['givenName'] );
				},
				$authors
			)
		);
		return array(
			'stem'          => 'Which option correctly names the authors for a book with three or more authors in MLA style?',
			'wrongOptions'  => array(
				$harvard_style . '.',
				sprintf( '%s, %s, et al', $first['surname'], $first['givenName'] ),
				sprintf( '%s, %s, and others.', $first['surname'], $first['givenName'] ),
			),
			'correctAnswer' => $correct,
		);
	}

	// -----------------------------------------------------------------
	// Variant 5 — Reference Structure. Fully static — no record data at
	// all (see mla_book_independent_answer_variants()).
	// -----------------------------------------------------------------
	private static function build_reference_structure( array $fields ) {
		return array(
			'stem'          => 'Which option shows the correct order of the main MLA Book reference elements?',
			'wrongOptions'  => array(
				'Author → Publisher → Title → Year',
				'Year → Author → Title → Publisher',
				'Author → Year → Title → Publisher',
			),
			'correctAnswer' => 'Author → Title → Publisher → Year',
		);
	}

	// -----------------------------------------------------------------
	// Variant 6 — Publisher/Year Punctuation.
	// -----------------------------------------------------------------
	private static function build_publisher_year_punctuation( array $fields ) {
		$author_segment = Citex_MLA_Reference_Rules::join_people( $fields['authors'] );
		return array(
			'stem'          => 'Which option correctly punctuates the publisher and year?',
			'wrongOptions'  => array(
				sprintf( '%s %s. %s. %s.', $author_segment, $fields['title'], $fields['publisher'], $fields['year'] ),
				sprintf( '%s %s. %s %s.', $author_segment, $fields['title'], $fields['publisher'], $fields['year'] ),
				sprintf( '%s %s. (%s) %s.', $author_segment, $fields['title'], $fields['year'], $fields['publisher'] ),
			),
			'correctAnswer' => self::correct_reference( $fields ),
		);
	}

	// -----------------------------------------------------------------
	// Variant 7 — Identify the Error (one deliberate mistake shown; pick
	// the statement that correctly names it).
	// -----------------------------------------------------------------
	private static function build_identify_the_error( array $fields ) {
		$authors           = $fields['authors'];
		$record_seed       = implode( '|', array( $fields['title'], $fields['publisher'], $fields['year'], (string) count( $authors ) ) );
		$kind              = self::pick_error_kind( $record_seed );
		$broken            = self::broken_reference( $kind, $authors, $fields['title'], $fields['publisher'], $fields['year'] );
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
	// Variant 8 — "Which is NOT a correct reference?" Inverted like
	// Website's own not_a_correct_reference: correctAnswer is the ONE
	// FLAWED reference; wrongOptions are 3 genuinely, independently VALID
	// references for different invented books.
	// -----------------------------------------------------------------
	private static function build_not_a_correct_reference( array $fields ) {
		$authors     = $fields['authors'];
		$record_seed = implode( '|', array( $fields['title'], $fields['publisher'], $fields['year'], (string) count( $authors ) ) );
		$kind        = self::pick_error_kind( $record_seed . '|not_correct' );
		$broken      = self::broken_reference( $kind, $authors, $fields['title'], $fields['publisher'], $fields['year'] );

		$pool = self::valid_reference_pool();
		usort(
			$pool,
			function ( $a, $b ) use ( $record_seed ) {
				$hash_a = crc32( 'mla_book_mcq_not_correct_pool|' . $record_seed . '|' . $a );
				$hash_b = crc32( 'mla_book_mcq_not_correct_pool|' . $record_seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);

		return array(
			'stem'          => 'Which of the following is NOT a correct MLA reference for a book?',
			'wrongOptions'  => array_slice( $pool, 0, 3 ),
			'correctAnswer' => $broken,
		);
	}
}
