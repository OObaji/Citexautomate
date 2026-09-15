<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MHRA (Modern Humanities Research Association, 11th edition) referencing
 * rules — Phase 1: Book only (mirrors how Harvard/MLA/APA/Chicago were each
 * first shipped Book-only, before being extended to Edited Book/Journal
 * Article/Website in a later phase). A genuinely separate sibling of
 * Citex_Reference_Rules/Citex_MLA_Reference_Rules/Citex_APA_Reference_Rules/
 * Citex_Chicago_Reference_Rules — never a retrofit of any of them.
 *
 * Real MHRA style is primarily a FOOTNOTE/endnote citation system with an
 * accompanying Bibliography — this app has no footnote mechanic anywhere
 * (the Question Focus dropdown only ever offers Reference List or In-Text
 * Citation, exactly the architectural constraint that already decided
 * Chicago's own Author-Date-not-Notes-Bibliography choice). Phase 1 targets
 * MHRA's own Bibliography entry shape, the closest fit to this app's
 * "Reference List" mechanic.
 *
 * MHRA's own rule combination, genuinely distinct from every other style
 * already in this codebase:
 * - Author/editor name is the FULL given name, never an initial (like
 *   MLA/Chicago, unlike Harvard/APA).
 * - Only the FIRST author is inverted ("Surname, First"); every author
 *   AFTER the first keeps natural word order ("First Surname") — the SAME
 *   naming shape as MLA's own join_people() — but, unlike MLA, "et al." is
 *   NEVER used in the Bibliography at any count this app generates: every
 *   author is always listed in full (Harvard's/APA's/Chicago's own rule).
 * - The place of publication IS kept, `(Place: Publisher, Year)` — but
 *   place, publisher AND year all sit together inside ONE parenthesis
 *   (unlike Harvard/Chicago, which both keep the year separate from the
 *   place/publisher segment).
 *
 * Book: `Surname, First, Title of the Work (Place: Publisher, Year).`
 *   1 author:  Smith, John, Life among the Giants (New York: Penguin, 2020).
 *   2 authors: Smith, John, and Amy Ross, Digital Culture (London: Routledge, 2021).
 *   3+ authors: Smith, John, Amy Ross, and Ben Carter, Digital Culture (London: Routledge, 2021).
 *
 * Titles are rendered in Title Case (like MLA/Chicago, unlike APA's
 * sentence case) — enforced the same way every other content-shape rule in
 * this app is enforced, via an explicit AI prompt instruction, never a PHP
 * case-transformation step.
 *
 * Only Book is implemented in this phase — CATEGORY_EDITED_BOOK/
 * CATEGORY_JOURNAL_ARTICLE/CATEGORY_WEBSITE are declared (for forward
 * reference by later wiring, matching the exact same "declare all 4, wire
 * only Book" pattern every prior style's Phase 1 used) but have no
 * build_reference()/format_regex() support yet.
 *
 * Pure and static, no WordPress/ACF calls, exactly like every other
 * reference-rules class in this codebase — unit-testable directly.
 */
class Citex_MHRA_Reference_Rules {

	const CATEGORY_BOOK            = 'Book';
	const CATEGORY_EDITED_BOOK     = 'Edited Book';
	const CATEGORY_JOURNAL_ARTICLE = 'Journal Article';
	const CATEGORY_WEBSITE         = 'Website';

	/**
	 * Question-id prefix — deliberately DIFFERENT from every Harvard/MLA/
	 * APA/Chicago category's own prefix, so a shared pending-queue can never
	 * collide an MHRA question onto the same id. Phase 1 only implements
	 * Book ("HB" — an "H" prefixed onto Harvard's own "BK" letter, the same
	 * "style letter + category letter" pattern MB/AB/CB already use).
	 */
	public static function id_prefix( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'HE';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'HJ';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'HW';
		}
		return 'HB';
	}

	/**
	 * The single, correctly-formatted MHRA Bibliography reference string for
	 * this category — the same string DragDrop reconstructs from its pieces
	 * and MCQ places as its correct option. Phase 1 only implements Book.
	 *
	 * @param string $category
	 * @param array  $fields Book: {authors: array<{surname, givenName, fullName}>, year, title, place, publisher}.
	 */
	public static function build_reference( $category, array $fields ) {
		return self::build_book_reference( $fields );
	}

	/**
	 * MHRA — Books: `Author(s), Title of the Work (Place: Publisher,
	 * Year).` Place, publisher and year all sit together inside ONE
	 * parenthesis (unlike Harvard's/Chicago's own Book format, which both
	 * keep the year outside/separate from the place/publisher segment) —
	 * the single most MHRA-distinctive structural rule.
	 */
	private static function build_book_reference( array $fields ) {
		return sprintf(
			'%s, %s (%s: %s, %s).',
			self::join_people( $fields['authors'] ),
			$fields['title'],
			$fields['place'],
			$fields['publisher'],
			$fields['year']
		);
	}

	/**
	 * MHRA's own author-list joining rule for the Bibliography — a
	 * genuinely distinct combination from every other style already in this
	 * codebase:
	 * - 1 author: "Surname, First"
	 * - 2 authors: "Surname, First, and First2 Surname2" — only the FIRST
	 *   author is inverted (MLA's own naming shape); the second keeps
	 *   natural word order, with a comma before "and" even at exactly two
	 *   (matching Chicago's/APA's own comma-before-the-joiner rule).
	 * - 3+ authors: "Surname, First, First2 Surname2, and First3 Surname3" —
	 *   every author AFTER the first stays in natural word order,
	 *   comma-separated, with a final Oxford comma before "and". Every
	 *   author is ALWAYS listed in full at any count this app generates —
	 *   "et al." is NEVER used in the Bibliography (the OPPOSITE of MLA's
	 *   own "et al. from 3+" rule, despite sharing MLA's own naming shape).
	 *
	 * @param array $people array<{surname, givenName, fullName}>, 1 or more.
	 */
	public static function join_people( array $people ) {
		$first = $people[0];
		$head  = sprintf( '%s, %s', $first['surname'], $first['givenName'] );
		if ( 1 === count( $people ) ) {
			return $head;
		}
		$rest = array_slice( $people, 1 );
		$rest_natural = array();
		foreach ( $rest as $person ) {
			$rest_natural[] = sprintf( '%s %s', $person['givenName'], $person['surname'] );
		}
		$last = array_pop( $rest_natural );
		if ( empty( $rest_natural ) ) {
			return $head . ', and ' . $last;
		}
		return $head . ', ' . implode( ', ', $rest_natural ) . ', and ' . $last;
	}

	/**
	 * The overall-shape regex confirming a completed reference string
	 * actually looks like MHRA's Book format — the MHRA counterpart to
	 * Citex_Reference_Rules::format_regex()/Citex_Chicago_Reference_Rules::format_regex().
	 * Phase 1 only implements Book.
	 *
	 * "Surname, First" (only the first author inverted), then optionally
	 * further authors in natural "First Last" word order, comma-separated
	 * with a final ", and " joiner (never "et al." — there is no literal
	 * comma/given-name group of that shape before the title in an
	 * abbreviated reference), followed by the title, then a SINGLE
	 * parenthesis containing place, a colon, publisher, a comma, and a
	 * 4-digit year, closed and followed by a final full stop.
	 */
	public static function format_regex( $category ) {
		return '/^[^,]+,\s+\S.*?,\s+\S.+\s+\([^:]+:\s+[^,]+,\s+\d{4}\)\.\s*$/u';
	}

	/**
	 * The fixed, student-facing MCQ question stem for this category — Citex
	 * authors this itself. Phase 1 only implements Book.
	 */
	public static function mcq_question_stem( $category ) {
		return 'Which of the following is the correct MHRA Bibliography reference for a book?';
	}

	/**
	 * The fixed, non-revealing MCQ hint for this category.
	 */
	public static function mcq_hint( $category ) {
		return 'Check whether only the first author\'s name is inverted (surname first) while later authors keep natural word order, whether every author is named in full with a comma before "and", and whether the place, publisher and year all sit together inside one set of parentheses.';
	}

	/**
	 * The fixed, non-revealing hint for the "Identify the error" MCQ
	 * scenario — same "never reveals the answer" rationale as
	 * Citex_Reference_Rules::identify_error_hint().
	 */
	public static function identify_error_hint( $category ) {
		return 'Work through the reference rule by rule: whether only the first author is inverted and every full given name is used, how a second or third author is joined (natural word order, comma before "and"), and whether place, publisher and year are correctly grouped together inside a single set of parentheses.';
	}
}
