<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * APA (7th edition) referencing rules — covers all 4 categories (Book,
 * Edited Book, Journal Article, Website), mirroring
 * Citex_MLA_Reference_Rules's own 4-category shape (which itself started
 * with Book only before being extended). A genuinely separate sibling of
 * both Citex_Reference_Rules (Harvard) and Citex_MLA_Reference_Rules, never
 * a retrofit of either:
 *
 * - Author/editor name uses INITIALS, not a full given name — the SAME
 *   {surname, initials} shape and derive_author_parts() Harvard already
 *   uses (unlike MLA, which needed full given names and its own
 *   derive_mla_author_parts()). Only the JOINING rule differs from Harvard.
 * - Two or more people are always joined with "&", with a comma before it
 *   even at exactly two people — e.g. "Smith, J., & Jones, B." — unlike
 *   Harvard's plain "and" with no comma at two, and unlike MLA's "et al."
 *   from three authors onward. Every author/editor is always listed in full
 *   for any count this app generates (real APA 7 only truncates to a
 *   first-19-then-ellipsis form at 21+ authors, entirely outside this app's
 *   1/2/3/4-or-more scenario-bucket range) — "et al." is NEVER used in the
 *   reference list here, matching Harvard's own rule, not MLA's.
 * - The year sits in parentheses, FOLLOWED BY A FULL STOP — "(2020)." — the
 *   single most APA-distinctive structural quirk, unlike Harvard (no period
 *   after the year) and MLA (no parentheses around the year at all).
 * - There is no place of publication at all (dropped from APA since the 6th
 *   edition, same as MLA).
 * - Titles are SENTENCE CASE (only the first word and any proper nouns
 *   capitalised) — enforced by instructing the AI to invent titles that way
 *   directly (this app's established "invent it correctly formatted
 *   directly" convention), never by a PHP case-transformation step.
 *
 * Book:            `Surname, F. (Year). Title of the work. Publisher.`
 *   1 author:  Smith, J. (2020). Life among the giants. Penguin.
 *   2 authors: Ross, A., & Carter, B. (2021). Digital culture. Routledge.
 *   3+ authors: Ross, A., Carter, B., & Diaz, K. (2021). Digital culture. Routledge.
 *
 * Edited Book:      `Surname, F. (Ed.). (Year). Title of the work. Publisher.`
 *   1 editor:  Ross, A. (Ed.). (2019). Urban planning today. Routledge.
 *   2 editors: Ross, A., & Carter, B. (Eds.). (2021). Digital culture. Routledge.
 *   The designation is singular "(Ed.)" only for exactly one editor,
 *   "(Eds.)" for two or more — the SAME 1-vs-2+ threshold as Harvard's own
 *   designation_for_editor_count(), just APA's own abbreviation.
 *
 * Journal Article:  `Surname, F. (Year). Title of the article. Journal Title, Volume(Issue), pages.`
 *   Sentence-case article title, NO quotes (unlike Harvard's single quotes
 *   and MLA's double quotes); bare "Volume(Issue)" (Harvard's own shorthand,
 *   never MLA's "vol./no." labels); the page range reuses
 *   Citex_Reference_Rules::format_page_range() for the en dash, but —
 *   critically — with NO "pp." prefix at all, unlike Harvard. This is a
 *   real, meaningful APA rule: "pp." is used for book chapters, never for a
 *   journal article's own page range in APA 7.
 *
 * Website:          `Surname, F. or Organisation. (Year). Title of the page. URL.`
 *   No "Available at:" label (unlike Harvard); no accessed/retrieval date
 *   field at all (modern APA 7 guidance: a retrieval date is only required
 *   for content expected to change, which this app's invented stable
 *   sources never are — unlike both Harvard and MLA, APA's Website format
 *   has no accessedDate field to invent or drag at all); "(n.d.)" for a
 *   missing year, exactly like Harvard (never MLA's "omit the segment"
 *   approach). The single author-or-organisation abstraction mirrors
 *   Harvard's own format_website_author() pattern, just with initials.
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
	 * Question-id prefix — deliberately DIFFERENT from every Harvard and MLA
	 * category's own prefix, so a shared pending-queue can never collide an
	 * APA question onto the same id: "AB"/"AE"/"AJ"/"AW" are an "A" prefixed
	 * onto each Harvard category's own letter (BK -> AB, ED -> AE, JA -> AJ,
	 * WR -> AW), the exact same pattern used for MLA's "MB"/"ME"/"MJ"/"MW".
	 */
	public static function id_prefix( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'AE';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'AJ';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'AW';
		}
		return 'AB';
	}

	/**
	 * The single, correctly-formatted APA reference string for this
	 * category — the same string DragDrop reconstructs from its pieces and
	 * MCQ places as its correct option.
	 *
	 * @param string $category
	 * @param array  $fields Book: {authors: array<{surname, initials}>, year, title, publisher}.
	 *               Edited Book: {editors: array<{surname, initials}>, year, title, publisher}.
	 *               Journal Article: {authors: array<{surname, initials}>, year, articleTitle,
	 *               journalTitle, volume, issue, pages}.
	 *               Website: {author: {type: 'individual'|'organisation', surname, initials, name},
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

	private static function build_book_reference( array $fields ) {
		return sprintf(
			'%s (%s). %s. %s.',
			self::join_people( $fields['authors'] ),
			$fields['year'],
			$fields['title'],
			$fields['publisher']
		);
	}

	private static function build_edited_book_reference( array $fields ) {
		$editors     = $fields['editors'];
		$designation = self::designation_for_editor_count( count( $editors ) );
		return sprintf(
			'%s (%s). (%s). %s. %s.',
			self::join_people( $editors ),
			$designation,
			$fields['year'],
			$fields['title'],
			$fields['publisher']
		);
	}

	/**
	 * APA — Journal Articles: `Surname, F. (Year). Title of the article.
	 * Journal Title, Volume(Issue), pages.` — see this class's own docblock
	 * for the "no quotes, sentence-case title, bare Volume(Issue), no 'pp.'
	 * prefix" rules. ALL authors are always listed in full (join_people()'s
	 * exact joining algorithm, same as Book/Edited Book), "et al." is NEVER
	 * used in the reference list.
	 */
	private static function build_journal_article_reference( array $fields ) {
		return sprintf(
			'%s (%s). %s. %s, %s(%s), %s.',
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
	 * The Harvard typographic en dash conversion, reused directly — see
	 * Citex_Reference_Rules::format_page_range()'s own docblock for the
	 * rationale (stored field stays a plain hyphen; the dash is applied only
	 * at render time, identically everywhere).
	 */
	public static function format_page_range( $pages ) {
		return Citex_Reference_Rules::format_page_range( $pages );
	}

	/**
	 * APA — Websites/webpages: `Author/Organisation (Year|n.d.). Title. URL.`
	 * — no "Available at:" label, no accessed-date field at all (see this
	 * class's own docblock). There is only ever ONE author-or-organisation,
	 * same single-entity abstraction as Harvard's own build_website_reference().
	 */
	private static function build_website_reference( array $fields ) {
		return sprintf(
			'%s (%s). %s. %s.',
			self::format_website_author( $fields['author'] ),
			$fields['year'],
			$fields['title'],
			$fields['url']
		);
	}

	/**
	 * A Website reference's single author is EITHER a named individual
	 * (rendered "Surname, I." — the same join_people() single-person shape,
	 * initials never a full given name) OR the organisation responsible for
	 * the page, rendered exactly as given.
	 *
	 * @param array $author {type: 'individual'|'organisation', surname?, initials?, name?}.
	 */
	public static function format_website_author( array $author ) {
		if ( 'organisation' === ( $author['type'] ?? '' ) ) {
			return (string) ( $author['name'] ?? '' );
		}
		return sprintf( '%s, %s', $author['surname'] ?? '', $author['initials'] ?? '' );
	}

	/**
	 * "(Ed.)" for exactly one editor, "(Eds.)" for two or more — this is the
	 * one rule this whole category exists to test, computed in exactly one
	 * place, mirroring Citex_Reference_Rules::designation_for_editor_count()'s
	 * own 1-vs-2+ threshold with APA's own abbreviation.
	 */
	public static function designation_for_editor_count( $editor_count ) {
		return $editor_count > 1 ? 'Eds.' : 'Ed.';
	}

	/**
	 * "Smith, J." for one person; "Smith, J., & Jones, B." for two (the
	 * comma before "&" even at exactly two is APA's own rule, unlike
	 * Harvard's plain "and" with no comma there); "Smith, J., Jones, B., &
	 * Lee, K." for three or more — every person always listed in full, "et
	 * al." never used in a reference-list entry. Shared by both Book/Journal
	 * Article authors and Edited Book editors, exactly like Harvard's own
	 * join_people().
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
	 * The shared "one or more 'Surname, Initials' groups, comma-separated,
	 * with a final ', & ' before the last" author/editor-list regex fragment
	 * — reused by every category's own format_regex() below so the joining
	 * rule is enforced identically everywhere. Never matches a plain "and"
	 * or a bare "&" with no preceding comma, and never matches "et al." (no
	 * literal comma/initials group precedes it).
	 */
	private static function person_group_pattern() {
		return '[^,]+,\s+(?:[A-Z]\.\s*)+(?:(?:,\s+[^,]+,\s+(?:[A-Z]\.\s*)+)*,\s+&\s+[^,]+,\s+(?:[A-Z]\.\s*)+)?';
	}

	/**
	 * The overall-shape regex confirming a completed reference string
	 * actually looks like this category's APA format — the APA counterpart
	 * to Citex_Reference_Rules::format_regex()/Citex_MLA_Reference_Rules::format_regex().
	 */
	public static function format_regex( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			// Surname(s), Initials [, & Surname, Initials] (Ed.|Eds.). (Year). Title. Publisher.
			return '/^' . self::person_group_pattern() . '\s+\((?:Ed\.|Eds\.)\)\.\s+\(\d{4}\)\.\s+.+\.\s+.+\.\s*$/u';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			// Surname(s), Initials (Year). Article title. Journal title, Volume(Issue), Start–End.
			// — no quotes around the article title, no "pp." before the page range.
			return '/^' . self::person_group_pattern() . '\(\d{4}\)\.\s+.+\.\s+.+,\s+\d+\(\d+\),\s+\d+–\d+\.\s*$/u';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			// Author/Organisation (Year|n.d.). Title. URL. — the author
			// segment is deliberately `.+` (not the repeating Surname,
			// Initials group) because it may be a raw organisation name;
			// there is no "Available at:" label and no accessed date at all.
			return '/^.+\s+\((?:\d{4}|n\.d\.)\)\.\s+.+\.\s+\S+\.\s*$/u';
		}
		// Book.
		return '/^' . self::person_group_pattern() . '\(\d{4}\)\.\s+.+\.\s+.+\.\s*$/u';
	}

	/**
	 * The fixed, student-facing MCQ question stem for this category —
	 * Citex authors this itself, exactly like Harvard's and MLA's own
	 * mcq_question_stem().
	 */
	public static function mcq_question_stem( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Which of the following is the correct APA reference for an edited book?';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'Which of the following is the correct APA reference for a journal article?';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'Which of the following is the correct APA reference for a webpage?';
		}
		return 'Which of the following is the correct APA reference for a book?';
	}

	/**
	 * The fixed, non-revealing MCQ hint for this category.
	 */
	public static function mcq_hint( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Check whether the editor\'s initials (not full first name) follow the surname, whether "(Ed.)" or "(Eds.)" matches how many editors are named, whether two or more editors are joined with "&" preceded by a comma, and whether a full stop follows the year\'s closing parenthesis.';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'Check whether the article title has no quotation marks around it and is in sentence case, whether the volume and issue appear as a bare "Volume(Issue)" with no labels, and whether the page range has NO "pp." prefix — unlike a book chapter.';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'Check whether the author or organisation is named correctly (initials, never a full first name, for a named individual), whether "(n.d.)" is used only when no year can genuinely be identified, and that there is no "Available at:" label and no accessed date at all.';
		}
		return 'Check whether the author\'s initials (not full first name) follow the surname, whether two or more authors are joined with "&" preceded by a comma, whether a full stop follows the year\'s closing parenthesis, and that there is no place of publication before the publisher.';
	}

	/**
	 * The fixed, non-revealing hint for the "Identify the error" MCQ
	 * scenario — same "never reveals the answer" rationale as
	 * Citex_Reference_Rules::identify_error_hint()/Citex_MLA_Reference_Rules::identify_error_hint().
	 */
	public static function identify_error_hint( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Work through the reference rule by rule: whether the editor is reduced to initials, whether "(Ed.)"/"(Eds.)" matches the real editor count, how two or more editors are joined, and whether a full stop sits immediately after the year\'s closing parenthesis.';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'Work through the reference rule by rule: whether the article title is wrongly wrapped in quotation marks, whether the volume/issue are wrongly labelled "vol."/"no.", and whether the page range has a wrongly-included "pp." prefix.';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'Work through the reference rule by rule: whether the author/organisation is named correctly, whether the year is wrongly omitted or shown incorrectly, and whether an "Available at:" label or an accessed date has been wrongly added — APA has neither.';
		}
		return 'Work through the reference rule by rule: whether the author is reduced to initials (not a full first name), how two or more authors are joined (a comma before "&", never a plain "and"), whether a full stop sits immediately after the year\'s closing parenthesis, and whether a place of publication has been wrongly included before the publisher.';
	}
}
