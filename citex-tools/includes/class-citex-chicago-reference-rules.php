<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chicago (17th edition, Author-Date) referencing rules — covers all 4
 * categories (Book, Edited Book, Journal Article, Website), mirroring
 * Citex_APA_Reference_Rules's/Citex_MLA_Reference_Rules's own 4-category
 * shape (each started Book-only before being extended). A genuinely
 * separate sibling of Citex_Reference_Rules/Citex_MLA_Reference_Rules/
 * Citex_APA_Reference_Rules — never a retrofit of any of them — since
 * Chicago Author-Date's own rules are a distinct combination not shared
 * exactly by any of the other three styles:
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
 * Book:             `Surname, GivenName. Year. Title of the Work. Place: Publisher.`
 *   1 author:  Smith, John. 2020. Life among the Giants. New York: Penguin.
 *   2 authors: Ross, Amy, and Ben Carter. 2021. Digital Culture. London: Routledge.
 *   3+ authors: Ross, Amy, Ben Carter, and Kim Lee. 2021. Digital Culture. London: Routledge.
 *
 * Edited Book:      `Surname, GivenName, ed[s]. Year. Title of the Work. Place: Publisher.`
 *   1 editor:  Ross, Amy, ed. 2019. Urban Planning Today. London: Routledge.
 *   2 editors: Ross, Amy, and Ben Carter, eds. 2021. Digital Culture. London: Routledge.
 *   The designation sits INSIDE the person segment itself (comma-separated,
 *   lowercase, never parenthesised — unlike Harvard's "(ed.)"/"(eds)" and
 *   APA's "(Ed.)"/"(Eds.)"), replacing join_people()'s own trailing full
 *   stop; "ed." for exactly one editor, "eds." for two or more — the SAME
 *   1-vs-2+ threshold every other style already uses.
 *
 * Journal Article:  `Surname, GivenName. Year. "Article Title." Journal Title Volume (Issue): pages.`
 *   Double-quoted article title (like MLA, unlike Harvard's single quotes
 *   and APA's no quotes at all); bare "Volume (Issue)" with a SPACE before
 *   the parenthesis (genuinely Chicago's own shape — neither Harvard/APA's
 *   tight "V(I)" nor MLA's "vol. V, no. I" labels); a colon (not a comma)
 *   before the page range, and — like APA, unlike Harvard — NO "pp."
 *   prefix on the page range at all.
 *
 * Website:          `Surname, GivenName or Organisation. Year|n.d.. "Page Title." URL.`
 *   Double-quoted title (matching Journal Article's own quoting rule); no
 *   "Available at:" label and no accessed-date field at all (like APA's own
 *   modern reasoning: this app's invented sources are stable, so a
 *   retrieval date is never required); "n.d." for a missing year, exactly
 *   like Harvard/APA (never MLA's "omit the segment" approach). The single
 *   author-or-organisation abstraction mirrors every other style's own
 *   format_website_author() pattern, just with a full given name.
 *
 * Titles are rendered in Title Case (like MLA, unlike APA's sentence
 * case) — enforced the same way every other content-shape rule in this app
 * is enforced, via an explicit AI prompt instruction ("invent the title
 * directly in Title Case"), never a PHP case-transformation step.
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
	 * MCQ places as its correct option.
	 *
	 * @param string $category
	 * @param array  $fields Book: {authors: array<{surname, givenName, fullName}>, year, title, place, publisher}.
	 *               Edited Book: {editors: array<{surname, givenName, fullName}>, year, title, place, publisher}.
	 *               Journal Article: {authors: array<{surname, givenName, fullName}>, year,
	 *               articleTitle, journalTitle, volume, issue, pages}.
	 *               Website: {author: {type: 'individual'|'organisation', surname, givenName, fullName, name},
	 *               year (4-digit string or literal 'n.d.'), title, url}.
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
	 * Chicago — Edited Books: `Surname, GivenName, ed[s]. Year. Title of the
	 * Work. Place: Publisher.` The designation sits INSIDE the person
	 * segment, comma-separated and lowercase (never parenthesised, unlike
	 * Harvard's "(ed.)"/"(eds)" and APA's "(Ed.)"/"(Eds.)") — join_people()'s
	 * own trailing full stop is stripped and replaced by the designation's
	 * own comma-then-full-stop, exactly mirroring how
	 * Citex_MLA_Reference_Rules::join_editors() strips its own join_people()
	 * period before appending "editor."/"editors.".
	 */
	private static function build_edited_book_reference( array $fields ) {
		$editors     = $fields['editors'];
		$designation = self::designation_for_editor_count( count( $editors ) );
		$segment     = rtrim( self::join_people( $editors ), '.' );
		return sprintf(
			'%s, %s. %s. %s. %s: %s.',
			$segment,
			$designation,
			$fields['year'],
			$fields['title'],
			$fields['place'],
			$fields['publisher']
		);
	}

	/**
	 * "ed" for exactly one editor, "eds" for two or more — Chicago's own
	 * lowercase, non-parenthesised abbreviation, deliberately returned
	 * WITHOUT its own trailing period: build_edited_book_reference()'s own
	 * format string supplies that period itself (". " immediately follows
	 * this designation before the year), so returning "ed."/"eds." here
	 * would double it up into "ed.." — the same double-period bug already
	 * fixed once in Citex_Chicago_Book_Mcq_Variants::author_segment_initial().
	 * Same 1-vs-2+ threshold as every other style's own designation rule.
	 */
	public static function designation_for_editor_count( $editor_count ) {
		return $editor_count > 1 ? 'eds' : 'ed';
	}

	/**
	 * Chicago — Journal Articles: `Surname, GivenName. Year. "Article
	 * Title." Journal Title Volume (Issue): pages.` Double-quoted article
	 * title (like MLA, unlike Harvard's single quotes and APA's no quotes at
	 * all); a SPACE before the parenthesised issue number (genuinely
	 * Chicago's own shape — neither Harvard/APA's tight "V(I)" nor MLA's
	 * "vol. V, no. I" labels); a colon (not a comma) before the page range,
	 * and — like APA, unlike Harvard — NO "pp." prefix on the page range at
	 * all. ALL authors are always listed in full (join_people()'s exact
	 * joining algorithm, same as Book/Edited Book), "et al." is NEVER used.
	 */
	private static function build_journal_article_reference( array $fields ) {
		return sprintf(
			'%s %s. "%s." %s %s (%s): %s.',
			self::join_people( $fields['authors'] ),
			$fields['year'],
			$fields['articleTitle'],
			$fields['journalTitle'],
			$fields['volume'],
			$fields['issue'],
			self::format_page_range( $fields['pages'] )
		);
	}

	/**
	 * The Harvard/APA typographic en-dash conversion, reused directly — see
	 * Citex_Reference_Rules::format_page_range()'s own docblock for the
	 * rationale (stored field stays a plain hyphen; the dash is applied only
	 * at render time, identically everywhere).
	 */
	public static function format_page_range( $pages ) {
		return Citex_Reference_Rules::format_page_range( $pages );
	}

	/**
	 * Chicago — Websites/webpages: `Surname, GivenName or Organisation.
	 * Year|n.d.. "Page Title." URL.` Double-quoted title (matching Journal
	 * Article's own quoting rule); no "Available at:" label and no
	 * accessed-date field at all (like APA's own modern reasoning: this
	 * app's invented sources are stable, so a retrieval date is never
	 * required); "n.d." for a missing year, exactly like Harvard/APA (never
	 * MLA's "omit the segment" approach). There is only ever ONE
	 * author-or-organisation, same single-entity abstraction as every other
	 * style's own build_website_reference().
	 */
	private static function build_website_reference( array $fields ) {
		$year         = (string) $fields['year'];
		// "n.d." already carries its own abbreviation period — appending
		// another would read "n.d.." (the same double-period bug already
		// fixed once in Citex_Chicago_Book_Mcq_Variants::author_segment_initial()
		// for an initial's own period), so only a real 4-digit year gets one
		// added here.
		$year_segment = ( 'n.d.' === $year ) ? 'n.d.' : $year . '.';
		return sprintf(
			'%s %s "%s." %s.',
			self::format_website_author( $fields['author'] ),
			$year_segment,
			$fields['title'],
			$fields['url']
		);
	}

	/**
	 * A Website reference's single author is EITHER a named individual
	 * (rendered "Surname, GivenName" — Chicago's own full-given-name rule,
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
	 * actually looks like this category's Chicago format — the Chicago
	 * counterpart to Citex_Reference_Rules::format_regex()/
	 * Citex_MLA_Reference_Rules::format_regex()/Citex_APA_Reference_Rules::format_regex().
	 *
	 * Book/Edited Book share one general shape: one or more "Surname,
	 * GivenName" groups — join_people()'s exact grammar, tolerating Edited
	 * Book's own ", ed."/", eds." designation inside the same free-form
	 * leading segment (mirroring how Citex_MLA_Reference_Rules::format_regex()
	 * shares one pattern between its own Book/Edited Book) — followed by a
	 * bare 4-digit year (no parentheses at all) and its own full stop, then
	 * Title. Place: Publisher. A reference that abbreviates to "Smith et
	 * al." can never match: there is no literal comma/given-name group
	 * before the year in that string.
	 */
	public static function format_regex( $category ) {
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			// Surname(s), GivenName(s). Year. "Article title." Journal
			// title Volume (Issue): pages. — double-quoted title, a space
			// before the parenthesised issue, and a colon (never a comma)
			// before the page range with no "pp." prefix.
			return '/^[^,]+,\s+\S.*?\.\s+\d{4}\.\s+".+\."\s+.+\s+\d+\s+\(\d+\):\s+[\d–]+\.\s*$/u';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			// Author/Organisation Year|n.d. "Page Title." URL. — the author
			// segment is deliberately `.+` (not the strict comma/given-name
			// group) because it may be a raw organisation name; there is no
			// "Available at:" label and no accessed date at all.
			return '/^.+\s+(?:\d{4}\.|n\.d\.)\s+".+\."\s+\S+\.\s*$/u';
		}
		// Book / Edited Book.
		return '/^[^,]+,\s+\S.*?\.\s+\d{4}\.\s+.+\.\s+[^:]+:\s+.+\.\s*$/u';
	}

	/**
	 * The fixed, student-facing MCQ question stem for this category — Citex
	 * authors this itself.
	 */
	public static function mcq_question_stem( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Which of the following is the correct Chicago (Author-Date) reference for an edited book?';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'Which of the following is the correct Chicago (Author-Date) reference for a journal article?';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'Which of the following is the correct Chicago (Author-Date) reference for a webpage?';
		}
		return 'Which of the following is the correct Chicago (Author-Date) reference for a book?';
	}

	/**
	 * The fixed, non-revealing MCQ hint for this category.
	 */
	public static function mcq_hint( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Check whether the editor\'s full given name (not an initial) follows the surname, whether "ed."/"eds." (lowercase, no parentheses) matches how many editors are named, how a second editor is joined with a comma before "and", and whether the year sits right after the person segment with no parentheses.';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'Check whether the article title is in double quotation marks with the full stop INSIDE them, whether the volume and a bare parenthesised issue number are separated by a space, and whether a colon (not a comma) precedes the page range with NO "pp." prefix at all.';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'Check whether the author or organisation is named correctly (a full given name, never an initial, for a named individual), whether "n.d." is used only when no year can genuinely be identified, and that there is no "Available at:" label and no accessed date at all.';
		}
		return 'Check whether every author\'s name is inverted with their full given name (not an initial), how a second or third author is joined with a comma before "and", whether the year sits right after the author list with no parentheses, and the order of the title, place and publisher.';
	}

	/**
	 * The fixed, non-revealing hint for the "Identify the error" MCQ
	 * scenario — same "never reveals the answer" rationale as
	 * Citex_Reference_Rules::identify_error_hint().
	 */
	public static function identify_error_hint( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Work through the reference rule by rule: whether the editor\'s full given name is used and correctly inverted, whether "ed."/"eds." matches the real editor count and sits unparenthesised, how a second editor is joined, and whether the year is wrongly parenthesised instead of sitting bare after the person segment.';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'Work through the reference rule by rule: whether the article title is wrongly left unquoted or wrapped in single quotes, whether the issue number is wrongly tight against the volume with no space, and whether a wrongly-included "pp." prefix or a comma (instead of a colon) precedes the page range.';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'Work through the reference rule by rule: whether the author/organisation is named correctly, whether the year is wrongly shown in parentheses or omitted instead of "n.d.", and whether an "Available at:" label or an accessed date has been wrongly added — Chicago has neither.';
		}
		return 'Work through the reference rule by rule: whether every author\'s full given name is used and correctly inverted, how a second or third author is joined (a comma before "and" even at exactly two), whether the year is wrongly parenthesised instead of sitting bare after the author list, and the order and punctuation of the title, place and publisher.';
	}
}
