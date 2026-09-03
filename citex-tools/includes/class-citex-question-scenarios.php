<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The dynamic question-generation scenario catalogue — per category, the
 * set of distinct Harvard-rule-testing scenarios a question can be built
 * around, so generating N questions for a category tests N different
 * pieces of referencing knowledge instead of the same "select the correct
 * reference" pattern N times with a different book title.
 *
 * Pure/static, like Citex_Reference_Rules (no WordPress/ACF calls) — this
 * is the catalogue Citex_Question_Diversity selects from and
 * Citex_AI_V2/Citex_Generator consult to know which author/editor count to
 * target and which rule a question's blueprint should name. Adding a new
 * category means adding one more catalog() case here, driven by that
 * category's own confirmed Liverpool Hope rules — never inventing a rule
 * this catalogue doesn't already implement elsewhere (see
 * Citex_Reference_Rules).
 *
 * This increment's catalogue covers the author/editor-COUNT dimension —
 * the one Liverpool Hope rule confirmed to genuinely change the correct
 * treatment for both existing categories. Later increments add further
 * scenario entries (e.g. "identify_error", "choose_correct_treatment")
 * without changing this shape.
 */
class Citex_Question_Scenarios {

	/**
	 * @param string $category      Citex_Reference_Rules::CATEGORY_*.
	 * @param string $question_type 'MCQ' or 'DragDrop'.
	 * @return array[] Each entry: {id, questionType, ruleTested, targetCounts, label}.
	 *                 targetCounts is the set of real author/editor counts
	 *                 this scenario may ask Gemini for — a single value for
	 *                 an exact-count scenario, or several for an "N or
	 *                 more" bucket (e.g. Book's four_or_more_authors tests
	 *                 at 4, 5 or 6 — the rule is identical for any of them,
	 *                 so testing at more than one count is what proves the
	 *                 rule itself, not a specific number, is understood).
	 */
	public static function catalog( $category, $question_type ) {
		$question_type = 'MCQ' === $question_type ? 'MCQ' : 'DragDrop';
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
			$buckets = self::edited_book_buckets();
		} elseif ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
			$buckets = self::journal_article_buckets();
		} elseif ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
			$buckets = self::website_buckets();
		} else {
			$buckets = self::book_buckets();
		}

		$out = array();
		foreach ( $buckets as $bucket ) {
			$bucket['questionType'] = $question_type;
			$out[]                  = $bucket;
		}

		// MCQ-only scenarios: mechanics that have no DragDrop equivalent
		// (DragDrop only ever constructs a complete reference — see
		// Citex_Reference_Rules::dragdrop_shape() — so it has nothing
		// analogous to "identify the error in this shown reference").
		if ( 'MCQ' === $question_type ) {
			foreach ( self::mcq_only_scenarios( $category ) as $scenario ) {
				$scenario['questionType'] = 'MCQ';
				$out[]                    = $scenario;
			}
		}

		return $out;
	}

	/**
	 * MCQ mechanics beyond "select the correct reference" (which itself
	 * lives in the count buckets above, tagged by author/editor count).
	 *
	 * `identify_error` is deliberately kept to a single real author/editor
	 * count (targetCounts = [1]) for now — combining "identify a
	 * deliberately broken reference" with "and also vary the author count"
	 * compounds two kinds of complexity in the same question; author-count
	 * variation for that mechanic is explicit backlog, not silently out of
	 * scope.
	 *
	 * `choose_treatment_*` tests the joining/designation RULE directly —
	 * "which statement is correct", not "which reference is correct" — one
	 * scenario per count bucket where the rule's wording genuinely differs
	 * (matches the user's own worked examples exactly, including the
	 * four_or_more_authors "et al." misconception test). No one_author/
	 * one_editor variant: with only one person there is no joining
	 * convention to state a rule about, so there is nothing genuinely
	 * different from select_correct's own one_author/one_editor scenario to
	 * test here.
	 */
	private static function mcq_only_scenarios( $category ) {
		// Journal Article's ONE MCQ-only mechanic: author_initials — a
		// single author's "Surname, I." tested in isolation (design
		// 'author_only'). This is deliberately MCQ-only: a 1-part answer
		// can never satisfy DragDrop's 3-meaningful-parts floor (see
		// Citex_Reference_Rules::journal_article_designs()'s docblock), but
		// MCQ has no such floor — testing one meaningful formatting
		// decision is exactly what requirement 8's "correct initials"
		// objective asks for. Journal Article has no identify_error/
		// choose_treatment prompt/schema/normaliser support — those remain
		// out of scope for this category.
		if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return array(
				array( 'id' => 'author_initials', 'ruleTested' => 'author_initial_format', 'targetCounts' => array( 1 ), 'label' => 'Author initials format', 'exerciseDesign' => 'author_only' ),
			);
		}
		// Website implements only the required select_correct (MCQ) and
		// construct_reference (DragDrop) mechanics in this task — no
		// identify_error/choose_treatment prompt/schema/normaliser support
		// exists for it yet.
		if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
			return array();
		}
		$scenarios = array(
			array( 'id' => 'identify_error', 'ruleTested' => 'error_identification', 'targetCounts' => array( 1 ), 'label' => 'Identify the error' ),
		);
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
			$scenarios[] = array( 'id' => 'choose_treatment_two_editors', 'ruleTested' => 'editor_designation', 'targetCounts' => array( 2 ), 'label' => 'Choose the correct treatment (two editors)' );
			$scenarios[] = array( 'id' => 'choose_treatment_three_or_more_editors', 'ruleTested' => 'editor_joining', 'targetCounts' => array( 3 ), 'label' => 'Choose the correct treatment (three or more editors)' );
		} else {
			$scenarios[] = array( 'id' => 'choose_treatment_two_authors', 'ruleTested' => 'author_joining', 'targetCounts' => array( 2 ), 'label' => 'Choose the correct treatment (two authors)' );
			$scenarios[] = array( 'id' => 'choose_treatment_three_authors', 'ruleTested' => 'author_joining', 'targetCounts' => array( 3 ), 'label' => 'Choose the correct treatment (three authors)' );
			$scenarios[] = array( 'id' => 'choose_treatment_four_or_more_authors', 'ruleTested' => 'reference_list_all_authors', 'targetCounts' => array( 4 ), 'label' => 'Choose the correct treatment (four or more authors)' );
		}
		return $scenarios;
	}

	/**
	 * Book author-count buckets — Liverpool Hope's confirmed reference-list
	 * rule: 1 author alone; 2 joined with "and"; 3+ comma-separated with a
	 * final "and"; this never changes at 4+ ("et al." is never used in the
	 * reference list — see Citex_Reference_Rules::build_reference()'s
	 * docblock). The four buckets exist because the STYLE of joining
	 * changes at 1/2/3 authors and then stays fixed — testing at 4, 5 and 6
	 * within the last bucket proves the student (and the generator) isn't
	 * just pattern-matching a specific number.
	 */
	private static function book_buckets() {
		return array(
			array( 'id' => 'one_author', 'ruleTested' => 'author_formatting', 'targetCounts' => array( 1 ), 'label' => 'One author' ),
			array( 'id' => 'two_authors', 'ruleTested' => 'author_joining', 'targetCounts' => array( 2 ), 'label' => 'Two authors' ),
			array( 'id' => 'three_authors', 'ruleTested' => 'author_joining', 'targetCounts' => array( 3 ), 'label' => 'Three authors' ),
			array( 'id' => 'four_or_more_authors', 'ruleTested' => 'reference_list_all_authors', 'targetCounts' => array( 4, 5, 6 ), 'label' => 'Four or more authors' ),
		);
	}

	/**
	 * Edited Book editor-count buckets — the designation rule ("(ed.)" for
	 * one, "(eds)" for two or more) is the same for 2 and 3+ editors, but
	 * the JOINING style still changes at 3 (comma-separated with a final
	 * "and", exactly like Book authors) — so three_or_more_editors is
	 * tagged with the joining rule specifically, distinguishing it from
	 * two_editors' designation-only test.
	 */
	private static function edited_book_buckets() {
		return array(
			array( 'id' => 'one_editor', 'ruleTested' => 'editor_designation', 'targetCounts' => array( 1 ), 'label' => 'One editor' ),
			array( 'id' => 'two_editors', 'ruleTested' => 'editor_designation', 'targetCounts' => array( 2 ), 'label' => 'Two editors' ),
			array( 'id' => 'three_or_more_editors', 'ruleTested' => 'editor_joining', 'targetCounts' => array( 3, 4 ), 'label' => 'Three or more editors' ),
		);
	}

	/**
	 * Journal Article learning-objective buckets — REDESIGNED so every
	 * DragDrop question tests at least 3 meaningful referencing components
	 * in small, individually-draggable chips (never a giant pre-joined
	 * author string, never a punctuation-only objective — see
	 * Citex_Reference_Rules::journal_article_designs()'s docblock for the
	 * full rationale). Each entry's 'exerciseDesign' is read by
	 * Citex_AI_V2::normalise() to pick the matching
	 * Citex_Reference_Rules::journal_article_dragdrop_shape() case; the
	 * full canonical source record is still always required and validated
	 * regardless of which of these is assigned (see
	 * Citex_Generated_Validator::validate_journal_article_consistency()).
	 *
	 * Author-count buckets (1, 2, 3, 4, 5+) use 'author_context' for 1-3
	 * authors (author chip(s) + year + article title — always >= 3 parts)
	 * and 'author_list_year' for 4+ (author chips + year only, deliberately
	 * dropping the article title to keep a 4-7-chip question mobile-sized —
	 * the author list alone already clears the 3-part floor). 'full_reference'
	 * is offered as its own separate, occasional variety scenario across a
	 * range of counts, now built from one small chip PER AUTHOR rather than
	 * one giant pre-joined string for 2+ authors.
	 */
	private static function journal_article_buckets() {
		return array(
			array( 'id' => 'one_author', 'ruleTested' => 'author_formatting', 'targetCounts' => array( 1 ), 'label' => 'One author (author + year + title)', 'exerciseDesign' => 'author_context' ),
			array( 'id' => 'two_authors', 'ruleTested' => 'author_joining', 'targetCounts' => array( 2 ), 'label' => 'Two authors (author + year + title)', 'exerciseDesign' => 'author_context' ),
			array( 'id' => 'three_authors', 'ruleTested' => 'author_joining', 'targetCounts' => array( 3 ), 'label' => 'Three authors (author + year + title)', 'exerciseDesign' => 'author_context' ),
			array( 'id' => 'four_authors', 'ruleTested' => 'reference_list_all_authors', 'targetCounts' => array( 4 ), 'label' => 'Four authors (author list + year)', 'exerciseDesign' => 'author_list_year' ),
			array( 'id' => 'five_or_more_authors', 'ruleTested' => 'reference_list_all_authors', 'targetCounts' => array( 5, 6, 7 ), 'label' => 'Five or more authors (author list + year)', 'exerciseDesign' => 'author_list_year' ),
			array( 'id' => 'full_reference', 'ruleTested' => 'full_reference_construction', 'targetCounts' => array( 1, 2, 3, 4, 5 ), 'label' => 'Full reference (all fields, one chip per author)', 'exerciseDesign' => 'full_reference' ),
			array( 'id' => 'volume_issue_pages', 'ruleTested' => 'volume_issue_pages_structure', 'targetCounts' => array( 1, 2, 3 ), 'label' => 'Volume/issue/page range structure', 'exerciseDesign' => 'volume_issue_pages' ),
			array( 'id' => 'journal_volume_issue', 'ruleTested' => 'journal_title_placement', 'targetCounts' => array( 1, 2, 3 ), 'label' => 'Journal title, volume and issue', 'exerciseDesign' => 'journal_volume_issue' ),
			array( 'id' => 'title_journal_volume', 'ruleTested' => 'article_title_placement', 'targetCounts' => array( 1, 2, 3 ), 'label' => 'Article title, journal title and volume', 'exerciseDesign' => 'title_journal_volume' ),
			array( 'id' => 'partial_reference', 'ruleTested' => 'reference_body_structure', 'targetCounts' => array( 1, 2, 3 ), 'label' => 'Partial reference (title/journal/volume/issue/pages)', 'exerciseDesign' => 'reference_body' ),
		);
	}

	/**
	 * Website scenario buckets — NOT an author-count dimension at all (this
	 * category's Liverpool Hope rule only ever has ONE author-or-
	 * organisation, never a joined list — see
	 * Citex_Reference_Rules::format_website_author()). Instead the two
	 * genuinely rule-changing dimensions this category tests are: (a)
	 * individual named author vs. organisation-as-author (affects whether
	 * "Surname, I." derivation applies at all), and (b) a dated vs. an
	 * undated ("n.d.") source (affects whether a real year or the literal
	 * "n.d." is correct) — combined into four buckets so generation and
	 * validation can require and check both dimensions explicitly per
	 * batch. `targetCounts => [1]` is a harmless placeholder satisfying the
	 * shared scaffold (this category has no count concept to vary) —
	 * Citex_AI_V2 parses the bucket id string directly for the
	 * author-type/dated-ness constraints instead of using target_count_for().
	 */
	private static function website_buckets() {
		return array(
			array( 'id' => 'individual_author_dated', 'ruleTested' => 'date_handling', 'targetCounts' => array( 1 ), 'label' => 'Individual author, dated' ),
			array( 'id' => 'individual_author_undated', 'ruleTested' => 'date_handling', 'targetCounts' => array( 1 ), 'label' => 'Individual author, undated (n.d.)' ),
			array( 'id' => 'organisation_author_dated', 'ruleTested' => 'author_type', 'targetCounts' => array( 1 ), 'label' => 'Organisation author, dated' ),
			array( 'id' => 'organisation_author_undated', 'ruleTested' => 'author_type', 'targetCounts' => array( 1 ), 'label' => 'Organisation author, undated (n.d.)' ),
		);
	}

	/**
	 * Look up one scenario's catalog entry by id, for the given
	 * category/questionType — used once a scenario id has already been
	 * assigned (by Citex_Question_Diversity::assign_scenarios()) and the
	 * caller needs its ruleTested/targetCounts back.
	 *
	 * @return array|null
	 */
	public static function find( $category, $question_type, $scenario_id ) {
		foreach ( self::catalog( $category, $question_type ) as $entry ) {
			if ( $entry['id'] === $scenario_id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Deterministically pick one real target count from a scenario's
	 * targetCounts, varying by $seed (e.g. a question ID or slot index) so
	 * repeated calls within one "four_or_more_authors" batch don't all land
	 * on the same count — while staying reproducible for the same seed,
	 * unlike true randomness, so this is straightforward to unit test.
	 */
	public static function target_count_for( array $scenario, $seed ) {
		$counts = array_values( $scenario['targetCounts'] ?? array( 1 ) );
		if ( empty( $counts ) ) {
			return 1;
		}
		if ( 1 === count( $counts ) ) {
			return (int) $counts[0];
		}
		$index = abs( crc32( (string) $seed ) ) % count( $counts );
		return (int) $counts[ $index ];
	}
}
