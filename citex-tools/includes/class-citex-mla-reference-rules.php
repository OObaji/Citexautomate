<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MLA (9th edition) referencing rules — covers all 4 categories (Book,
 * Edited Book, Journal Article, Website), mirroring
 * Citex_Reference_Rules's own 4-category shape. A genuinely separate
 * sibling of that class (which covers Harvard only), not a retrofit of it:
 * MLA's own rules differ from Harvard's in ways that go well beyond
 * punctuation —
 *
 * - Author/editor name is the FULL first name, never initials (e.g.
 *   "Smith, John." not "Smith, J.").
 * - There is no place of publication at all (dropped from MLA since the 7th
 *   edition) — Publisher and Year are simply comma-separated.
 * - The year comes LAST (Book/Edited Book: after the publisher; Journal
 *   Article: after the issue), with no surrounding parentheses at all.
 * - Two people: only the FIRST is inverted ("Last, First, and First
 *   Last."); the second keeps natural word order.
 * - Three or more people: ONLY the first is named at all, followed by
 *   "et al." — the OPPOSITE of Harvard's own reference-list rule, which
 *   forbids "et al." there and always lists every person in full.
 *
 * Book:            `Author. Title of Book. Publisher, Year.`
 *   1 author:  Smith, John. The Great Adventure. Penguin, 2020.
 *   2 authors: Ross, Amy, and Ben Carter. Digital Culture. Routledge, 2021.
 *   3+ authors: Ross, Amy, et al. Digital Culture. Routledge, 2021.
 *
 * Edited Book:      `Editor(s), editor|editors. Title of Book. Publisher, Year.`
 *   1 editor:  Ross, Amy, editor. Title. Publisher, Year.
 *   2 editors: Ross, Amy, and Ben Carter, editors. Title. Publisher, Year.
 *   3+ editors: Ross, Amy, et al., editors. Title. Publisher, Year.
 *   The designation is singular ("editor") only for exactly one editor —
 *   the SAME 1-vs-2+ threshold as Harvard's own designation_for_editor_count(),
 *   just spelled out in full rather than abbreviated to "(ed.)"/"(eds)".
 *
 * Journal Article:  `Author. "Article Title." Journal Title, vol. V, no. I, Year, pp. X–Y.`
 *   double quotation marks (never single, unlike Harvard) around the
 *   article title; "vol."/"no." labels precede the volume/issue numbers
 *   (never Harvard's own bare "V(I)" shorthand); the year sits after the
 *   issue, comma-separated, with no parentheses at all — matching Book's
 *   own "no parentheses around the year" rule exactly.
 *
 * Website:          `Author/Org. "Page Title." Year, URL. Accessed Day Month Year.`
 *   when no year can be identified, the year segment is omitted entirely
 *   (never a literal "n.d." — unlike Harvard, MLA has no such convention)
 *   and the citation relies on the Accessed date alone:
 *   `Author/Org. "Page Title." URL. Accessed Day Month Year.`
 *   The author-or-organisation is the SAME single-entity abstraction as
 *   Harvard's own format_website_author() — never a joined list — just
 *   rendered with a full given name instead of an initial for an
 *   individual (mirroring every other MLA category's own "no initials"
 *   rule).
 *
 * Titles are plain text for Book/Edited Book (no italics markup, matching
 * this app's existing convention of never emitting rich-text for titles
 * anywhere); Journal Article/Website titles are wrapped in double
 * quotation marks, matching real MLA 9 style for a title within a larger
 * work.
 *
 * Pure and static, no WordPress/ACF calls, exactly like
 * Citex_Reference_Rules — unit-testable directly.
 */
class Citex_MLA_Reference_Rules {

	const CATEGORY_BOOK            = 'Book';
	const CATEGORY_EDITED_BOOK     = 'Edited Book';
	const CATEGORY_JOURNAL_ARTICLE = 'Journal Article';
	const CATEGORY_WEBSITE         = 'Website';

	/**
	 * Question-id prefix — deliberately DIFFERENT from every Harvard
	 * category's own prefix, so a shared pending-queue can never collide an
	 * MLA and a Harvard question onto the same id: "MB"/"ME"/"MJ"/"MW" are
	 * an "M" prefixed onto each Harvard category's own letter (BK -> MB,
	 * ED -> ME, JA -> MJ, WR -> MW), the exact same pattern used for
	 * in-text citation's own id prefixes (see Citex_Generator::intext_id_prefix()).
	 */
	public static function id_prefix( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'ME';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'MJ';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'MW';
		}
		return 'MB';
	}

	/**
	 * The single, correctly-formatted MLA reference string for this
	 * category — the same string DragDrop reconstructs from its pieces and
	 * MCQ places as its correct option.
	 *
	 * @param string $category
	 * @param array  $fields Book: {authors: array<{surname, givenName, fullName}>, year, title, publisher}.
	 *               Edited Book: {editors: array<{surname, givenName, fullName}>, year, title, publisher}.
	 *               Journal Article: {authors: array<{surname, givenName, fullName}>, year,
	 *               articleTitle, journalTitle, volume, issue, pages}.
	 *               Website: {author: {type: 'individual'|'organisation', surname, givenName, fullName, name},
	 *               year (4-digit string or ''), title, url, accessedDate}.
	 */
	public static function build_reference( $category, array $fields ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return self::build_edited_book_reference( $fields );
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return self::build_journal_article_reference( $fields );
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return self::build_website_reference( $fields );
		}
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

	private static function build_edited_book_reference( array $fields ) {
		return sprintf(
			'%s %s. %s, %s.',
			self::join_editors( $fields['editors'] ),
			$fields['title'],
			$fields['publisher'],
			$fields['year']
		);
	}

	/**
	 * "Ross, Amy, editor." for one editor; "Ross, Amy, and Ben Carter,
	 * editors." for two; "Ross, Amy, et al., editors." for three or more —
	 * join_people()'s own person-list segment with the designation
	 * appended. "editors" (plural) applies from exactly 2 editors upward —
	 * the SAME 1-vs-2+ threshold as Harvard's own
	 * designation_for_editor_count(), even though the underlying
	 * person-JOINING rule itself changes again at 3 (to "et al.") —
	 * designation and joining are independent dimensions here, exactly as
	 * they are for Harvard's own Edited Book.
	 *
	 * For 1 or 2 editors, join_people()'s trailing full stop is a genuine
	 * SENTENCE-ending period, so it is stripped and replaced by a comma
	 * before the designation. For 3+, join_people()'s output already ends
	 * in "et al." — an ABBREVIATION whose own period must never be
	 * stripped — so the designation is appended after a comma WITHOUT
	 * touching that period at all: "et al." + ", editors." = "et al.,
	 * editors." (not the bug this docblock exists to guard against:
	 * stripping "al."'s own period down to a bare "al").
	 *
	 * @param array $editors array<{surname, givenName, fullName}>, 1 or more.
	 */
	public static function join_editors( array $editors ) {
		$count       = count( $editors );
		$segment     = self::join_people( $editors );
		$designation = $count > 1 ? 'editors' : 'editor';
		if ( $count >= 3 ) {
			return $segment . ', ' . $designation . '.';
		}
		return rtrim( $segment, '.' ) . ', ' . $designation . '.';
	}

	/**
	 * MLA — Journal Articles: `Author. "Article Title." Journal Title, vol.
	 * V, no. I, Year, pp. X–Y.` Double quotation marks (never Harvard's own
	 * single quotes) around the article title; "vol."/"no." labels (never
	 * Harvard's bare "V(I)" shorthand); the year sits after the issue, with
	 * no parentheses at all — matching Book's own "no parentheses around
	 * the year" rule. ALL authors are joined via join_people() — the SAME
	 * full-name/"et al. at 3+" rule as Book, since MLA's author-joining
	 * rule does not vary by category (unlike Harvard, where it happens to
	 * be identical across categories only by coincidence of this app's own
	 * confirmed rules).
	 */
	private static function build_journal_article_reference( array $fields ) {
		return sprintf(
			'%s "%s." %s, vol. %s, no. %s, %s, pp. %s.',
			self::join_people( $fields['authors'] ),
			$fields['articleTitle'],
			$fields['journalTitle'],
			$fields['volume'],
			$fields['issue'],
			$fields['year'],
			Citex_Reference_Rules::format_page_range( $fields['pages'] )
		);
	}

	/**
	 * MLA — Websites/webpages: `Author/Org. "Page Title." Year, URL.
	 * Accessed Day Month Year.` When no year can be identified at all, the
	 * year segment is omitted entirely (real MLA 9 style has no "n.d."
	 * convention — unlike Harvard, which shows it verbatim) and the
	 * citation relies on the Accessed date alone: `Author/Org. "Page
	 * Title." URL. Accessed Day Month Year.` The single author-or-
	 * organisation abstraction is unchanged from Harvard's own
	 * format_website_author() (see format_website_author() below) — there
	 * is still only ever ONE entity, never a joined list.
	 */
	private static function build_website_reference( array $fields ) {
		$year = trim( (string) ( $fields['year'] ?? '' ) );
		if ( '' !== $year ) {
			return sprintf(
				'%s "%s." %s, %s. Accessed %s.',
				self::format_website_author( $fields['author'] ),
				$fields['title'],
				$year,
				$fields['url'],
				$fields['accessedDate']
			);
		}
		return sprintf(
			'%s "%s." %s. Accessed %s.',
			self::format_website_author( $fields['author'] ),
			$fields['title'],
			$fields['url'],
			$fields['accessedDate']
		);
	}

	/**
	 * A Website reference's single author is EITHER a named individual
	 * (rendered "Surname, GivenName" — MLA's own full-given-name rule,
	 * never an initial, unlike Harvard's format_website_author()) OR the
	 * organisation responsible for the page, rendered exactly as given.
	 *
	 * @param array $author {type: 'individual'|'organisation', surname?, givenName?, name?}.
	 */
	public static function format_website_author( array $author ) {
		if ( 'organisation' === ( $author['type'] ?? '' ) ) {
			return (string) ( $author['name'] ?? '' );
		}
		return sprintf( '%s, %s', $author['surname'] ?? '', $author['givenName'] ?? '' );
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
	 * actually looks like this category's MLA format — the MLA
	 * counterpart to Citex_Reference_Rules::format_regex(). Book and
	 * Edited Book share one shape (person-segment, title, publisher-year —
	 * Edited Book's ", editor."/", editors." designation sits INSIDE the
	 * free-form leading `.+?` segment, so the same pattern matches both);
	 * Journal Article and Website each have their own genuinely different
	 * shape.
	 */
	public static function format_regex( $category ) {
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			// Author(s). "Article Title." Journal Title, vol. V, no. I,
			// Year, pp. X–Y. — double quotes (never Harvard's single
			// quotes) with the period INSIDE the closing quote, "vol."/
			// "no." labels, and no parentheses around the year at all.
			return '/^.+\.\s+".+\."\s+.+,\s+vol\.\s+[^,]+,\s+no\.\s+[^,]+,\s+\d{4},\s+pp\.\s+[\d–]+\.\s*$/u';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			// Author/Org "Page Title." [Year,] URL. Accessed Day Month
			// Year. — the year segment is optional (real MLA 9 has no
			// "n.d." convention; an unpaginated/undated source simply
			// omits it and relies on the Accessed date alone).
			return '/^.+\s+".+\."\s+(?:\d{4},\s+)?.+\.\s+Accessed\s+.+\.\s*$/u';
		}
		// Book / Edited Book: no parentheses around the year at all (year
		// sits bare after the publisher comma), and the leading person
		// segment tolerates a plain single author, an "and First Last"
		// second author, an "et al." abbreviation, or an Edited Book's own
		// ", editor."/", editors." designation — all via a non-greedy
		// `.+?` before the title's own full stop.
		return '/^.+?,\s+.+?\.\s+.+\.\s+.+,\s+\d{4}\.\s*$/u';
	}

	/**
	 * The fixed, student-facing MCQ question stem for this category —
	 * Citex authors this itself, exactly like Harvard's
	 * mcq_question_stem().
	 */
	public static function mcq_question_stem( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Which of the following is the correct MLA reference for an edited book?';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'Which of the following is the correct MLA reference for a journal article?';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'Which of the following is the correct MLA reference for a webpage?';
		}
		return 'Which of the following is the correct MLA reference for a book?';
	}

	/**
	 * The fixed, non-revealing MCQ hint for this category.
	 */
	public static function mcq_hint( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Check whether the first editor\'s name is inverted with their full first name (not initials), whether "editor"/"editors" matches how many people are named, how a second editor or "et al." is used for 3+ editors, and the order of the title, publisher and year.';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'Check the article title is in double quotation marks with the full stop INSIDE them, that "vol." and "no." both label their numbers, and that the year sits after the issue with no parentheses at all.';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'Check whether the author or organisation is named correctly (a full first name, never an initial, for a named individual), whether a year is shown only when one can genuinely be identified, and that the Accessed date is present.';
		}
		return 'Check whether the first author\'s name is inverted with their full first name (not initials), how a second author or "et al." is used for 3+ authors, and the order of the title, publisher and year (with no place of publication and no parentheses around the year).';
	}

	/**
	 * The fixed, non-revealing hint for the "Identify the error" MCQ
	 * scenario — same "never reveals the answer" rationale as
	 * Citex_Reference_Rules::identify_error_hint().
	 */
	public static function identify_error_hint( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Work through the reference rule by rule: whether the first editor\'s full first name is inverted, whether the "editor"/"editors" designation matches the real editor count, how a second editor or "et al." is handled, and the order and punctuation of the title, publisher and year.';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'Work through the reference rule by rule: whether the article title sits in double quotation marks with the period inside them, whether "vol."/"no." both label their numbers, and whether the year is wrongly placed in parentheses instead of after the issue.';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'Work through the reference rule by rule: whether the author/organisation is named correctly, whether a year has been wrongly shown as "n.d." (a Harvard convention MLA never uses), and whether the Accessed date is present and correctly placed.';
		}
		return 'Work through the reference rule by rule: whether the first author\'s full first name is used and correctly inverted, how a second author or "et al." is handled, and the order and punctuation of the title, publisher and year.';
	}
}
