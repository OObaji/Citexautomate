<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chicago (17th edition, Author-Date) referencing rules — Phase 1: Book
 * only (mirrors how Harvard/MLA/APA were each first shipped Book-only,
 * before being extended to Edited Book/Journal Article/Website in a later
 * phase). A genuinely separate sibling of Citex_Reference_Rules/
 * Citex_MLA_Reference_Rules/Citex_APA_Reference_Rules — never a retrofit of
 * any of them — since Chicago Author-Date's own rules are a distinct
 * combination not shared exactly by any of the other three styles:
 *
 * - Author/editor name is the FULL given name, never an initial (like MLA,
 *   unlike Harvard/APA) — e.g. "Smith, John." not "Smith, J.".
 * - The place of publication IS kept, `Place: Publisher.` (like Harvard,
 *   unlike MLA/APA, both of which dropped it).
 * - The year sits immediately after the author list, with NO surrounding
 *   parentheses at all (unlike Harvard/APA, which both parenthesise it) —
 *   but is still followed by its own full stop, exactly like every other
 *   sentence-ending element in this reference (unlike MLA, which moves the
 *   year to the very end instead).
 * - Every author is always listed in full, for any count — "et al." is
 *   NEVER used in the reference list (matching Harvard's/APA's own
 *   reference-list rule, the OPPOSITE of MLA's "et al. from 3+"). Two or
 *   more authors are always comma-separated, with a comma before "and"
 *   even at exactly two (matching APA's own comma-before-"&" rule, just
 *   with the word "and" instead of the symbol "&").
 *
 * Book: `Surname, GivenName. Year. Title of the Work. Place: Publisher.`
 *   1 author:  Smith, John. 2020. Life among the Giants. New York: Penguin.
 *   2 authors: Ross, Amy, and Ben Carter. 2021. Digital Culture. London: Routledge.
 *   3+ authors: Ross, Amy, Ben Carter, and Kim Lee. 2021. Digital Culture. London: Routledge.
 *
 * Titles are rendered in Title Case (like MLA, unlike APA's sentence
 * case) — enforced the same way every other content-shape rule in this app
 * is enforced, via an explicit AI prompt instruction ("invent the title
 * directly in Title Case"), never a PHP case-transformation step.
 *
 * Only Book is implemented in this phase — CATEGORY_EDITED_BOOK/
 * CATEGORY_JOURNAL_ARTICLE/CATEGORY_WEBSITE are declared (for forward
 * reference by later wiring, matching the exact same "declare all 4, wire
 * only Book" pattern Citex_APA_Reference_Rules used in its own Phase 1)
 * but have no build_reference()/format_regex() support yet.
 *
 * Pure and static, no WordPress/ACF calls, exactly like every other
 * reference-rules class in this codebase — unit-testable directly.
 */
class Citex_Chicago_Reference_Rules {

	const CATEGORY_BOOK            = 'Book';
	const CATEGORY_EDITED_BOOK     = 'Edited Book';
	const CATEGORY_JOURNAL_ARTICLE = 'Journal Article';
	const CATEGORY_WEBSITE         = 'Website';

	/**
	 * Question-id prefix — deliberately DIFFERENT from every Harvard/MLA/APA
	 * category's own prefix, so a shared pending-queue can never collide a
	 * Chicago and a Harvard/MLA/APA question onto the same id. Phase 1 only
	 * implements Book ("CB" — a "C" prefixed onto Harvard's own "BK" letter,
	 * the same "style letter + category letter" pattern MB/AB already use).
	 */
	public static function id_prefix( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'CE';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'CJ';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'CW';
		}
		return 'CB';
	}

	/**
	 * The single, correctly-formatted Chicago reference string for this
	 * category — the same string DragDrop reconstructs from its pieces and
	 * MCQ places as its correct option. Phase 1 only implements Book.
	 *
	 * @param string $category
	 * @param array  $fields Book: {authors: array<{surname, givenName, fullName}>, year, title, place, publisher}.
	 */
	public static function build_reference( $category, array $fields ) {
		return self::build_book_reference( $fields );
	}

	/**
	 * Chicago Author-Date — Books: `Author(s). Year. Title of the Work.
	 * Place: Publisher.` The year sits bare (no parentheses at all — unlike
	 * Harvard's/APA's own Book format) immediately after the author list's
	 * own full stop, followed by its own full stop; place and publisher are
	 * colon-separated exactly like Harvard's own Book format (kept, unlike
	 * MLA/APA which both dropped place entirely).
	 */
	private static function build_book_reference( array $fields ) {
		return sprintf(
			'%s %s. %s. %s: %s.',
			self::join_people( $fields['authors'] ),
			$fields['year'],
			$fields['title'],
			$fields['place'],
			$fields['publisher']
		);
	}

	/**
	 * Chicago's own author-list joining rule for the reference list —
	 * genuinely its own combination, not identical to any other style
	 * already in this codebase:
	 * - 1 author: "Surname, GivenName."
	 * - 2 authors: "Surname1, GivenName1, and Surname2, GivenName2." — EVERY
	 *   author (not just the second) is rendered "Surname, GivenName", and a
	 *   comma sits before "and" even at exactly two — mirroring APA's own
	 *   comma-before-"&" rule (Citex_APA_Reference_Rules::join_people()),
	 *   just with the word "and" in place of the symbol "&".
	 * - 3+ authors: "Surname1, GivenName1, Surname2, GivenName2, and
	 *   Surname3, GivenName3." — every author is always listed in full, at
	 *   any count this app generates; "et al." is NEVER used in the
	 *   reference list (matching Harvard's/APA's own rule, the OPPOSITE of
	 *   MLA's "et al. from 3+" rule).
	 *
	 * @param array $people array<{surname, givenName, fullName}>, 1 or more.
	 */
	public static function join_people( array $people ) {
		$parts = array();
		foreach ( $people as $person ) {
			$parts[] = sprintf( '%s, %s', $person['surname'], $person['givenName'] );
		}
		if ( 1 === count( $parts ) ) {
			return $parts[0] . '.';
		}
		$last = array_pop( $parts );
		return implode( ', ', $parts ) . ', and ' . $last . '.';
	}

	/**
	 * The overall-shape regex confirming a completed reference string
	 * actually looks like Chicago's Book format — the Chicago counterpart to
	 * Citex_Reference_Rules::format_regex()/Citex_MLA_Reference_Rules::format_regex()/
	 * Citex_APA_Reference_Rules::format_regex(). Phase 1 only implements Book.
	 *
	 * One or more "Surname, GivenName" groups — join_people()'s exact
	 * grammar: every pair before the last is comma-separated, and the FINAL
	 * joiner must specifically be ", and " (a comma before "and" even at
	 * exactly two authors) — followed by a bare 4-digit year (no
	 * parentheses at all) and its own full stop, then Title. Place:
	 * Publisher. A reference that abbreviates to "Smith et al." can never
	 * match: there is no literal comma/given-name group before the year in
	 * that string.
	 */
	public static function format_regex( $category ) {
		return '/^[^,]+,\s+\S.*?\.\s+\d{4}\.\s+.+\.\s+[^:]+:\s+.+\.\s*$/u';
	}

	/**
	 * The fixed, student-facing MCQ question stem for this category — Citex
	 * authors this itself. Phase 1 only implements Book.
	 */
	public static function mcq_question_stem( $category ) {
		return 'Which of the following is the correct Chicago (Author-Date) reference for a book?';
	}

	/**
	 * The fixed, non-revealing MCQ hint for this category.
	 */
	public static function mcq_hint( $category ) {
		return 'Check whether every author\'s name is inverted with their full given name (not an initial), how a second or third author is joined with a comma before "and", whether the year sits right after the author list with no parentheses, and the order of the title, place and publisher.';
	}

	/**
	 * The fixed, non-revealing hint for the "Identify the error" MCQ
	 * scenario — same "never reveals the answer" rationale as
	 * Citex_Reference_Rules::identify_error_hint().
	 */
	public static function identify_error_hint( $category ) {
		return 'Work through the reference rule by rule: whether every author\'s full given name is used and correctly inverted, how a second or third author is joined (a comma before "and" even at exactly two), whether the year is wrongly parenthesised instead of sitting bare after the author list, and the order and punctuation of the title, place and publisher.';
	}
}
