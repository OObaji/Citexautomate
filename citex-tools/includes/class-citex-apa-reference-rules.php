<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * APA (7th edition) referencing rules — Phase 1 covers Book only, mirroring
 * how Citex_MLA_Reference_Rules itself started with Book before being
 * extended to Edited Book/Journal Article/Website. A genuinely separate
 * sibling of both Citex_Reference_Rules (Harvard) and Citex_MLA_Reference_Rules,
 * never a retrofit of either:
 *
 * - Author name uses INITIALS, not a full given name — the SAME
 *   {surname, initials} shape and derive_author_parts() Harvard already
 *   uses (unlike MLA, which needed full given names and its own
 *   derive_mla_author_parts()). Only the JOINING rule differs from Harvard.
 * - Two or more people are always joined with "&", with a comma before it
 *   even at exactly two people — e.g. "Smith, J., & Jones, B." — unlike
 *   Harvard's plain "and" with no comma at two, and unlike MLA's "et al."
 *   from three authors onward. Every author is always listed in full for
 *   any count this app generates (real APA 7 only truncates to a
 *   first-19-then-ellipsis form at 21+ authors, entirely outside this
 *   app's 1/2/3/4-or-more scenario-bucket range) — "et al." is NEVER used
 *   in the reference list here, matching Harvard's own rule, not MLA's.
 * - The year sits in parentheses immediately after the author list,
 *   FOLLOWED BY A FULL STOP — "Smith, J. (2020). Title..." — unlike
 *   Harvard, which has no full stop after the year's closing parenthesis.
 * - There is no place of publication at all (dropped from APA since the
 *   6th edition, same as MLA).
 * - Titles are SENTENCE CASE (only the first word and any proper nouns
 *   capitalised) — enforced by instructing the AI to invent titles that
 *   way directly (this app's established "invent it correctly formatted
 *   directly" convention, e.g. the existing per-word title length cap),
 *   never by a PHP case-transformation step.
 *
 * Book: `Surname, F. (Year). Title of the work. Publisher.`
 *   1 author:  Smith, J. (2020). Life among the giants. Penguin.
 *   2 authors: Ross, A., & Carter, B. (2021). Digital culture. Routledge.
 *   3+ authors: Ross, A., Carter, B., & Diaz, K. (2021). Digital culture. Routledge.
 *
 * Pure and static, no WordPress/ACF calls, exactly like
 * Citex_Reference_Rules and Citex_MLA_Reference_Rules — unit-testable
 * directly.
 */
class Citex_APA_Reference_Rules {

	const CATEGORY_BOOK            = 'Book';
	const CATEGORY_EDITED_BOOK     = 'Edited Book';
	const CATEGORY_JOURNAL_ARTICLE = 'Journal Article';
	const CATEGORY_WEBSITE         = 'Website';

	/**
	 * Question-id prefix — deliberately DIFFERENT from every Harvard and
	 * MLA category's own prefix, so a shared pending-queue can never
	 * collide an APA question onto the same id: "AB" (an "A" prefixed onto
	 * Harvard's own "BK" -> "B", the same pattern used for MLA's "MB").
	 * Phase 1 covers Book only.
	 */
	public static function id_prefix( $category ) {
		return 'AB';
	}

	/**
	 * The single, correctly-formatted APA reference string for this
	 * category — the same string DragDrop reconstructs from its pieces and
	 * MCQ places as its correct option. Phase 1 covers Book only.
	 *
	 * @param string $category
	 * @param array  $fields Book: {authors: array<{surname, initials}>, year, title, publisher}.
	 */
	public static function build_reference( $category, array $fields ) {
		return self::build_book_reference( $fields );
	}

	private static function build_book_reference( array $fields ) {
		return sprintf(
			'%s (%s). %s. %s.',
			self::join_people( $fields['authors'] ),
			$fields['year'],
			$fields['title'],
			$fields['publisher']
		);
	}

	/**
	 * "Smith, J." for one person; "Smith, J., & Jones, B." for two (the
	 * comma before "&" even at exactly two is APA's own rule, unlike
	 * Harvard's plain "and" with no comma there); "Smith, J., Jones, B., &
	 * Lee, K." for three or more — every person always listed in full,
	 * "et al." never used in a reference-list entry.
	 *
	 * @param array $people array<{surname, initials}>, 1 or more.
	 */
	public static function join_people( array $people ) {
		$parts = array();
		foreach ( $people as $person ) {
			$parts[] = sprintf( '%s, %s', $person['surname'], $person['initials'] );
		}
		if ( 1 === count( $parts ) ) {
			return $parts[0];
		}
		$last = array_pop( $parts );
		return implode( ', ', $parts ) . ', & ' . $last;
	}

	/**
	 * The overall-shape regex confirming a completed reference string
	 * actually looks like APA's Book format — the APA counterpart to
	 * Citex_Reference_Rules::format_regex()/Citex_MLA_Reference_Rules::format_regex().
	 * One or more "Surname, Initials" groups (join_people()'s exact
	 * joining grammar: every pair before the last is comma-separated, and
	 * for 2+ people the LAST joiner must specifically be ", & " — never a
	 * plain "and", never "&" with no preceding comma), followed by
	 * "(Year). Title. Publisher." — note the full stop immediately after
	 * the year's closing parenthesis, which Harvard's own Book format does
	 * NOT have.
	 */
	public static function format_regex( $category ) {
		return '/^[^,]+,\s+(?:[A-Z]\.\s*)+(?:(?:,\s+[^,]+,\s+(?:[A-Z]\.\s*)+)*,\s+&\s+[^,]+,\s+(?:[A-Z]\.\s*)+)?\(\d{4}\)\.\s+.+\.\s+.+\.\s*$/u';
	}

	/**
	 * The fixed, student-facing MCQ question stem for this category —
	 * Citex authors this itself, exactly like Harvard's and MLA's own
	 * mcq_question_stem().
	 */
	public static function mcq_question_stem( $category ) {
		return 'Which of the following is the correct APA reference for a book?';
	}

	/**
	 * The fixed, non-revealing MCQ hint for this category.
	 */
	public static function mcq_hint( $category ) {
		return 'Check whether the author\'s initials (not full first name) follow the surname, whether two or more authors are joined with "&" preceded by a comma, whether a full stop follows the year\'s closing parenthesis, and that there is no place of publication before the publisher.';
	}

	/**
	 * The fixed, non-revealing hint for the "Identify the error" MCQ
	 * scenario — same "never reveals the answer" rationale as
	 * Citex_Reference_Rules::identify_error_hint()/Citex_MLA_Reference_Rules::identify_error_hint().
	 */
	public static function identify_error_hint( $category ) {
		return 'Work through the reference rule by rule: whether the author is reduced to initials (not a full first name), how two or more authors are joined (a comma before "&", never a plain "and"), whether a full stop sits immediately after the year\'s closing parenthesis, and whether a place of publication has been wrongly included before the publisher.';
	}
}
