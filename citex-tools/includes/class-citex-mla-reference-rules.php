<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MLA (9th edition) referencing rules — Phase 1, Book category only. A
 * genuinely separate sibling of Citex_Reference_Rules (which covers Harvard
 * only), not a retrofit of it: MLA's own Book rule differs from Harvard's in
 * ways that go well beyond punctuation —
 *
 * - Author name is the FULL first name, never initials (e.g. "Smith, John."
 *   not "Smith, J.").
 * - There is no place of publication at all (dropped from MLA since the 7th
 *   edition) — Publisher and Year are simply comma-separated.
 * - The year comes LAST, after the publisher, with no surrounding
 *   parentheses at all.
 * - Two authors: only the FIRST is inverted ("Last, First, and First
 *   Last."); the second keeps natural word order.
 * - Three or more authors: ONLY the first author is named at all, followed
 *   by "et al." — the OPPOSITE of Harvard's own rule, which forbids "et
 *   al." in a reference-list entry and always lists every author in full.
 *
 * Reference shape: `Author. Title of Book. Publisher, Year.`
 *   1 author:  Smith, John. The Great Adventure. Penguin, 2020.
 *   2 authors: Ross, Amy, and Ben Carter. Digital Culture. Routledge, 2021.
 *   3+ authors: Ross, Amy, et al. Digital Culture. Routledge, 2021.
 *
 * Titles are plain text here, with no italics/quotation-mark markup —
 * matching this app's existing convention of never emitting rich-text for
 * titles anywhere (Book's own Harvard titles are already plain).
 *
 * Pure and static, no WordPress/ACF calls, exactly like
 * Citex_Reference_Rules — unit-testable directly.
 */
class Citex_MLA_Reference_Rules {

	const CATEGORY_BOOK = 'Book';

	/**
	 * Question-id prefix — deliberately DIFFERENT from Harvard Book's own
	 * "BK", so a shared pending-queue can never collide an MLA and a
	 * Harvard question onto the same id. "ME"/"MJ"/"MW" are reserved for
	 * MLA's later Edited Book/Journal Article/Website categories.
	 */
	public static function id_prefix( $category ) {
		return 'MB';
	}

	/**
	 * The single, correctly-formatted MLA reference string for this
	 * category — the same string DragDrop reconstructs from its pieces and
	 * MCQ places as its correct option.
	 *
	 * @param array $fields {authors: array<{surname, givenName, fullName}>, year, title, publisher}.
	 */
	public static function build_reference( $category, array $fields ) {
		return self::build_book_reference( $fields );
	}

	private static function build_book_reference( array $fields ) {
		return sprintf(
			'%s %s. %s, %s.',
			self::join_people( $fields['authors'] ),
			$fields['title'],
			$fields['publisher'],
			$fields['year']
		);
	}

	/**
	 * MLA's own author-list joining rule for the Works Cited entry —
	 * genuinely different from Harvard's join_people(), not just a
	 * punctuation variant:
	 * - 1 author: "Last, First."
	 * - 2 authors: "Last, First, and First Last." (only the FIRST author is
	 *   inverted; the second keeps natural word order, joined by "and" —
	 *   note the comma before "and" even at exactly 2, unlike Harvard).
	 * - 3+ authors: "Last, First, et al." — every author after the first is
	 *   dropped entirely, replaced by the abbreviation. This is the single
	 *   most important rule inversion from Harvard, which never uses
	 *   "et al." in a reference-list entry at all.
	 *
	 * @param array $people array<{surname, givenName, fullName}>, 1 or more.
	 */
	public static function join_people( array $people ) {
		$first = $people[0];
		$head  = sprintf( '%s, %s', $first['surname'], $first['givenName'] );
		if ( 1 === count( $people ) ) {
			return $head . '.';
		}
		if ( 2 === count( $people ) ) {
			$second = $people[1];
			return sprintf( '%s, and %s.', $head, $second['fullName'] );
		}
		return sprintf( '%s, et al.', $head );
	}

	/**
	 * The overall-shape regex confirming a completed reference string
	 * actually looks like MLA's Book format — the MLA counterpart to
	 * Citex_Reference_Rules::format_regex(). No parentheses around the
	 * year at all (year sits bare after the publisher comma), and the
	 * author segment tolerates either the "and First Last" or "et al."
	 * tail (or neither, for a single author) via a non-greedy `.+?`
	 * before the title's own full stop.
	 */
	public static function format_regex( $category ) {
		return '/^.+?,\s+.+?\.\s+.+\.\s+.+,\s+\d{4}\.\s*$/u';
	}

	/**
	 * The fixed, student-facing MCQ question stem for MLA Book — Citex
	 * authors this itself, exactly like Harvard's mcq_question_stem().
	 */
	public static function mcq_question_stem( $category ) {
		return 'Which of the following is the correct MLA reference for a book?';
	}

	/**
	 * The fixed, non-revealing MCQ hint for MLA Book.
	 */
	public static function mcq_hint( $category ) {
		return 'Check whether the first author\'s name is inverted with their full first name (not initials), how a second author or "et al." is used for 3+ authors, and the order of the title, publisher and year (with no place of publication and no parentheses around the year).';
	}

	/**
	 * The fixed, non-revealing hint for the "Identify the error" MCQ
	 * scenario — same "never reveals the answer" rationale as
	 * Citex_Reference_Rules::identify_error_hint().
	 */
	public static function identify_error_hint( $category ) {
		return 'Work through the reference rule by rule: whether the first author\'s full first name is used and correctly inverted, how a second author or "et al." is handled, and the order and punctuation of the title, publisher and year.';
	}
}
