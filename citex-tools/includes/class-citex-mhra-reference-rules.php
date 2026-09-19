<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MHRA (Modern Humanities Research Association, 11th edition) referencing
 * rules — covers all 4 categories (Book, Edited Book, Journal Article,
 * Website), mirroring Citex_Chicago_Reference_Rules's own 4-category shape
 * (which itself started Book-only before being extended). A genuinely
 * separate sibling of Citex_Reference_Rules/Citex_MLA_Reference_Rules/
 * Citex_APA_Reference_Rules/Citex_Chicago_Reference_Rules — never a retrofit
 * of any of them.
 *
 * Real MHRA style is primarily a FOOTNOTE/endnote citation system with an
 * accompanying Bibliography — this app has no footnote mechanic anywhere
 * (the Question Focus dropdown only ever offers Reference List or In-Text
 * Citation, exactly the architectural constraint that already decided
 * Chicago's own Author-Date-not-Notes-Bibliography choice). This class
 * targets MHRA's own Bibliography entry shape throughout, the closest fit
 * to this app's "Reference List" mechanic.
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
 * Book:             `Surname, First, Title of the Work (Place: Publisher, Year).`
 *   1 author:  Smith, John, Life among the Giants (New York: Penguin, 2020).
 *   2 authors: Smith, John, and Amy Ross, Digital Culture (London: Routledge, 2021).
 *   3+ authors: Smith, John, Amy Ross, and Ben Carter, Digital Culture (London: Routledge, 2021).
 *
 * Edited Book:      `Surname, First, ed[s], Title of the Work (Place: Publisher, Year).`
 *   1 editor:  Ross, Amy, ed., Urban Planning Today (London: Routledge, 2019).
 *   2 editors: Ross, Amy, and Ben Carter, eds, Digital Culture (London: Routledge, 2021).
 *   The designation sits comma-separated inside the person segment itself,
 *   reusing Citex_Reference_Rules::designation_for_editor_count() directly
 *   ("ed." for exactly one editor, "eds" for two or more — the same
 *   abbreviation Harvard's own Edited Book already uses, just unparenthesised
 *   here, matching Chicago's own designation-placement rule).
 *
 * Journal Article:  `Surname, First, 'Article Title', Journal Title, Volume.Issue (Year), pages.`
 *   Single-quoted article title (matching Harvard's own quoting convention —
 *   never MLA/Chicago's double quotes or APA's no quotes at all); volume and
 *   issue combined with a full stop into one "Volume.Issue" token (a real,
 *   distinctively MHRA/humanities-journal convention, e.g. "12.3"); the year
 *   sits in its OWN parenthesis straight after that combined token — this is
 *   the single most MHRA-distinctive Journal Article rule, since the year
 *   sits somewhere else entirely in every other style already in this
 *   codebase.
 *
 * Website:          `Surname, First or Organisation, 'Title of the Page', <URL> [accessed Day Month Year].`
 *   Angle brackets around the URL and square brackets around the accessed
 *   date — real, distinctive MHRA typographic conventions. There is
 *   deliberately NO publication-year field at all for this category — real
 *   MHRA guidance treats the accessed date as the one temporal anchor a web
 *   reference needs, never a separate (and often unverifiable) publication
 *   date — the single biggest structural difference from every other
 *   style's own Website format in this codebase, all of which show some
 *   form of year (a real year, "n.d.", or an omitted-but-still-modelled
 *   segment). The accessed date is computed by Citex itself, never asked of
 *   Gemini, exactly like Harvard's/MLA's own Website mechanic.
 *
 * Titles are rendered in Title Case (like MLA/Chicago, unlike APA's
 * sentence case) — enforced the same way every other content-shape rule in
 * this app is enforced, via an explicit AI prompt instruction, never a PHP
 * case-transformation step.
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
	 * and MCQ places as its correct option.
	 *
	 * @param string $category
	 * @param array  $fields Book: {authors: array<{surname, givenName, fullName}>, year, title, place, publisher}.
	 *               Edited Book: {editors: array<{surname, givenName, fullName}>, year, title, place, publisher}.
	 *               Journal Article: {authors: array<{surname, givenName, fullName}>, year,
	 *               articleTitle, journalTitle, volume, issue, pages}.
	 *               Website: {author: {type: 'individual'|'organisation', surname, givenName, fullName, name},
	 *               title, url, accessedDate}.
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
	 * MHRA — Edited Books: `Author(s), ed[s], Title of the Work (Place:
	 * Publisher, Year).` The designation sits comma-separated INSIDE the
	 * person segment itself, reusing
	 * Citex_Reference_Rules::designation_for_editor_count() directly ("ed."
	 * for exactly one editor, "eds" for two or more) — join_people() carries
	 * no trailing punctuation of its own, so no double-punctuation risk
	 * exists here the way it did for Chicago's own designation placement.
	 */
	private static function build_edited_book_reference( array $fields ) {
		$editors     = $fields['editors'];
		$designation = Citex_Reference_Rules::designation_for_editor_count( count( $editors ) );
		return sprintf(
			'%s, %s, %s (%s: %s, %s).',
			self::join_people( $editors ),
			$designation,
			$fields['title'],
			$fields['place'],
			$fields['publisher'],
			$fields['year']
		);
	}

	/**
	 * MHRA — Journal Articles: `Author(s), 'Article Title', Journal Title,
	 * Volume.Issue (Year), pages.` Single-quoted article title (matching
	 * Harvard's own quoting convention); volume and issue combined into one
	 * "Volume.Issue" token with a full stop (a real, distinctively
	 * MHRA/humanities-journal convention); the year sits in its OWN
	 * parenthesis immediately after that combined token — genuinely
	 * different from every other style's own year placement in this
	 * codebase. ALL authors are always listed in full (join_people()'s exact
	 * joining algorithm, same as Book/Edited Book), "et al." is NEVER used.
	 */
	private static function build_journal_article_reference( array $fields ) {
		return sprintf(
			"%s, '%s', %s, %s.%s (%s), %s.",
			self::join_people( $fields['authors'] ),
			$fields['articleTitle'],
			$fields['journalTitle'],
			$fields['volume'],
			$fields['issue'],
			$fields['year'],
			self::format_page_range( $fields['pages'] )
		);
	}

	/**
	 * The Harvard/Chicago typographic en-dash conversion, reused directly —
	 * see Citex_Reference_Rules::format_page_range()'s own docblock for the
	 * rationale (stored field stays a plain hyphen; the dash is applied only
	 * at render time, identically everywhere).
	 */
	public static function format_page_range( $pages ) {
		return Citex_Reference_Rules::format_page_range( $pages );
	}

	/**
	 * MHRA — Websites/webpages: `Author/Organisation, 'Page Title', <URL>
	 * [accessed Day Month Year].` Angle brackets around the URL and square
	 * brackets around the accessed date — real, distinctive MHRA
	 * typographic conventions. There is deliberately NO publication-year
	 * field at all (see this class's own docblock: real MHRA guidance treats
	 * the accessed date as the reference's one temporal anchor for a web
	 * source). There is only ever ONE author-or-organisation, same
	 * single-entity abstraction as every other style's own
	 * build_website_reference().
	 */
	private static function build_website_reference( array $fields ) {
		return sprintf(
			"%s, '%s', <%s> [accessed %s].",
			self::format_website_author( $fields['author'] ),
			$fields['title'],
			$fields['url'],
			$fields['accessedDate']
		);
	}

	/**
	 * A Website reference's single author is EITHER a named individual
	 * (rendered "Surname, GivenName" — MHRA's own full-given-name rule,
	 * never an initial) OR the organisation responsible for the page,
	 * rendered exactly as given.
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
	 * actually looks like this category's MHRA format — the MHRA
	 * counterpart to Citex_Reference_Rules::format_regex()/
	 * Citex_Chicago_Reference_Rules::format_regex().
	 *
	 * Book/Edited Book share one general shape: "Surname, First" (only the
	 * first author inverted), then optionally further authors in natural
	 * "First Last" word order, comma-separated with a final ", and " joiner
	 * (never "et al."), tolerating Edited Book's own ", ed."/", eds"
	 * designation inside the same free-form leading segment (mirroring how
	 * Citex_MLA_Reference_Rules::format_regex() shares one pattern between
	 * its own Book/Edited Book) — followed by the title, then a SINGLE
	 * parenthesis containing place, a colon, publisher, a comma, and a
	 * 4-digit year, closed and followed by a final full stop.
	 */
	public static function format_regex( $category ) {
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			// Author(s), 'Article title', Journal title, Volume.Issue
			// (Year), pages. — single-quoted title, a combined
			// "Volume.Issue" token, and the year in its OWN parenthesis
			// straight after that token (never grouped with place/publisher,
			// since a journal article has neither).
			return '/^[^,]+,\s+\S.*?,\s+\'.+\',\s+.+,\s+\d+\.\d+\s+\(\d{4}\),\s+[\d–]+\.\s*$/u';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			// Author/Organisation, 'Page title', <URL> [accessed Day Month
			// Year]. — the author segment is deliberately `.+` (not the
			// strict comma/given-name group) because it may be a raw
			// organisation name; angle brackets around the URL and square
			// brackets around the accessed date; no publication year at all.
			return '/^.+,\s+\'.+\',\s+<\S+>\s+\[accessed\s+.+\]\.\s*$/u';
		}
		// Book / Edited Book.
		return '/^[^,]+,\s+\S.*?,\s+\S.+\s+\([^:]+:\s+[^,]+,\s+\d{4}\)\.\s*$/u';
	}

	/**
	 * The fixed, student-facing MCQ question stem for this category — Citex
	 * authors this itself.
	 */
	public static function mcq_question_stem( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Which of the following is the correct reference for an edited book?';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'Which of the following is the correct reference for a journal article?';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'Which of the following is the correct reference for a webpage?';
		}
		return 'Which of the following is the correct reference for a book?';
	}

	/**
	 * The fixed, non-revealing MCQ hint for this category.
	 */
	public static function mcq_hint( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Check whether only the first editor\'s name is inverted while later editors keep natural word order, whether "ed."/"eds" matches how many editors are named, and whether place, publisher and year all sit together inside one set of parentheses.';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'Check whether the article title sits in single quotation marks, whether the volume and issue are combined into one "Volume.Issue" token, and whether the year sits in its own parenthesis straight after that token — never grouped with a place or publisher, since a journal article has neither.';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'Check whether the author or organisation is named correctly (a full given name, never an initial, for a named individual), whether the URL sits inside angle brackets, and whether the accessed date sits inside square brackets — MHRA shows no publication year at all for a webpage.';
		}
		return 'Check whether only the first author\'s name is inverted (surname first) while later authors keep natural word order, whether every author is named in full with a comma before "and", and whether the place, publisher and year all sit together inside one set of parentheses.';
	}

	/**
	 * The fixed, non-revealing hint for the "Identify the error" MCQ
	 * scenario — same "never reveals the answer" rationale as
	 * Citex_Reference_Rules::identify_error_hint().
	 */
	public static function identify_error_hint( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Work through the reference rule by rule: whether only the first editor is inverted and every full given name is used, whether "ed."/"eds" matches the real editor count, and whether place, publisher and year are correctly grouped together inside a single set of parentheses.';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'Work through the reference rule by rule: whether the article title is wrongly left unquoted or wrapped in the wrong quotation marks, whether the volume and issue are wrongly kept separate instead of combined into one "Volume.Issue" token, and whether the year is wrongly placed somewhere other than its own parenthesis right after that token.';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'Work through the reference rule by rule: whether the author/organisation is named correctly, whether the URL is missing its angle brackets or the accessed date its square brackets, and whether a publication year has been wrongly added — MHRA shows none for a webpage.';
		}
		return 'Work through the reference rule by rule: whether only the first author is inverted and every full given name is used, how a second or third author is joined (natural word order, comma before "and"), and whether place, publisher and year are correctly grouped together inside a single set of parentheses.';
	}
}
