<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category-specific Harvard reference rules — the pluggable layer the MCQ
 * and DragDrop engines (Citex_AI_V2, Citex_Generated_Validator) consult for
 * "what does a correct reference look like, and what are its draggable
 * pieces" for one Reference Category. Adding a new category means adding a
 * case here (and its Gemini prompt/schema text in Citex_AI_V2) — the MCQ
 * and DragDrop generation/validation/population engines themselves never
 * change.
 *
 * Every method here is pure (no WordPress/ACF calls, no side effects) so it
 * can be unit-tested directly. Citex_Populator never calls into this class
 * at all: it only ever writes whatever questionParts/fixedText/options a
 * candidate record already contains, regardless of which category built
 * them — this is what already makes population category-agnostic.
 */
class Citex_Reference_Rules {

	const CATEGORY_BOOK            = 'Book';
	const CATEGORY_EDITED_BOOK     = 'Edited Book';
	const CATEGORY_JOURNAL_ARTICLE = 'Journal Article';
	/**
	 * The Liverpool Hope "referencing a website/webpage" category. No live
	 * WordPress site is accessible to this build (see README/prior session
	 * notes), so the exact real taxonomy term name could not be inspected
	 * directly — 'Website' is used because it is the one name this codebase's
	 * own pre-existing conventions already anticipate (see the old
	 * Citex_Validator subsystem's docblock and
	 * tests/populator-category-exercise-assignment.test.php's long-standing
	 * "Website" fixture). If the live site actually uses "Web Resource" (or
	 * another exact term), this is a one-line rename here — no architecture
	 * change — since every taxonomy lookup elsewhere is purely name-driven.
	 */
	const CATEGORY_WEBSITE = 'Website';

	/**
	 * HARD MOBILE-LAYOUT RULE for Journal Article DragDrop questions (never
	 * MCQ — see Citex_Question_Scenarios's Journal Article MCQ-only
	 * scenarios): every generated DragDrop question must have between 3 and
	 * 4 draggable Question Parts, no fewer, no more. Enforced at generation
	 * time (Citex_AI_V2's quality gate, reject-and-regenerate) AND
	 * independently at validation time (Citex_Generated_Validator::
	 * validate_dragdrop()), so a record can never enter the queue with a
	 * part count outside this range even if the generation-time check were
	 * ever bypassed.
	 */
	const JOURNAL_ARTICLE_DRAGDROP_MIN_PARTS = 3;
	const JOURNAL_ARTICLE_DRAGDROP_MAX_PARTS = 4;

	public static function categories() {
		return array( self::CATEGORY_BOOK, self::CATEGORY_EDITED_BOOK, self::CATEGORY_JOURNAL_ARTICLE, self::CATEGORY_WEBSITE );
	}

	public static function is_known_category( $category ) {
		return in_array( (string) $category, self::categories(), true );
	}

	/**
	 * The short, visually-distinct question-ID prefix for this category —
	 * "BK" for Book, "ED" for Edited Book — so a question ID alone (BK21 vs
	 * ED01) makes its category obvious at a glance in the pending-questions
	 * table and the real Reference List, without having to read the full
	 * category name. Citex_Generator uses this both to default/auto-correct
	 * the "Starting Question ID" field to the selected category and to make
	 * each category's numbering start fresh at 01 instead of continuing
	 * another category's count — since a prefix from one category can never
	 * collide with a different prefix, the existing global "skip already-
	 * used IDs" logic in Citex_AI_V2::build_ids() already keeps each
	 * category's own sequence gap-free without any other change.
	 */
	public static function id_prefix( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'ED';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'JA';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'WR';
		}
		return 'BK';
	}

	/**
	 * The single, correctly-formatted Harvard reference string for this
	 * category — the same string DragDrop reconstructs from its pieces and
	 * MCQ places as its correct option.
	 *
	 * @param string $category
	 * @param array  $fields Book: {authors: array<{surname, initials}>, year, title, place, publisher}.
	 *               Edited Book: {editors: array<{surname, initials}>, year, title, place, publisher}.
	 *               Journal Article: {authors: array<{surname, initials}>, year, articleTitle,
	 *               journalTitle, volume, issue, pages}.
	 *               Website: {author: {type: 'individual'|'organisation', surname, initials, name},
	 *               year (4-digit string or literal 'n.d.'), title, publisher, url, accessedDate}.
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
	 * Liverpool Hope Harvard — Books, reference-list author count rule
	 * (confirmed against Liverpool Hope's current guidance): 1 author is
	 * listed alone; 2 are joined with "and"; 3+ are comma-separated with a
	 * final "and" before the last — ALL authors are always listed in full,
	 * for any count, and "et al." is NEVER used. ("et al." is Liverpool
	 * Hope's IN-TEXT CITATION convention for 4+ authors — Citex never
	 * generates in-text citations, only reference-list entries, so that
	 * abbreviation must never appear in build_book_reference()'s output.)
	 * This is exactly join_people()'s joining algorithm, already proven by
	 * Edited Book's editor list — Book authors and Edited Book editors are
	 * joined identically.
	 */
	private static function build_book_reference( array $fields ) {
		return sprintf(
			'%s (%s) %s. %s: %s.',
			self::join_people( $fields['authors'] ),
			$fields['year'],
			$fields['title'],
			$fields['place'],
			$fields['publisher']
		);
	}

	private static function build_edited_book_reference( array $fields ) {
		$editors     = $fields['editors'];
		$designation = self::designation_for_editor_count( count( $editors ) );
		return sprintf(
			'%s (%s) (%s) %s. %s: %s.',
			self::join_people( $editors ),
			$designation,
			$fields['year'],
			$fields['title'],
			$fields['place'],
			$fields['publisher']
		);
	}

	/**
	 * Liverpool Hope Harvard — Journal Articles: Author surname(s), initial(s).
	 * (Year) Article title. Journal title, Volume(Issue), pp.xx-xx. — ALL
	 * authors are always listed in full (join_people()'s exact joining
	 * algorithm, same as Book/Edited Book), "et al." is NEVER used in the
	 * reference list, and there is no place/publisher concept for a journal
	 * article (unlike Book/Edited Book) — volume, issue and the page range
	 * replace them entirely.
	 */
	private static function build_journal_article_reference( array $fields ) {
		return sprintf(
			'%s (%s) %s. %s, %s(%s), pp.%s.',
			self::join_people( $fields['authors'] ),
			$fields['year'],
			$fields['articleTitle'],
			$fields['journalTitle'],
			$fields['volume'],
			$fields['issue'],
			$fields['pages']
		);
	}

	/**
	 * Liverpool Hope Harvard — Websites/webpages (the "Web Resource"
	 * category): Author/Organisation (Year|n.d.) Title [online]. Publisher.
	 * Available from: <URL> [accessed date]. Unlike Book/Edited Book/Journal
	 * Article there is only ever ONE author-or-organisation (no multi-person
	 * joining rule applies to this category at all — see
	 * format_website_author()), and there is no place/publisher-as-baked-in-
	 * fixed-text convention: publisher, URL and accessed date are all
	 * genuinely variable per source, so all six pieces are draggable (see
	 * website_dragdrop_shape()). "(n.d.)" replaces the year verbatim — never
	 * a guessed year — when no publication/creation date can be identified.
	 */
	private static function build_website_reference( array $fields ) {
		return sprintf(
			'%s (%s) %s [online]. %s. Available from: <%s> [accessed %s].',
			self::format_website_author( $fields['author'] ),
			$fields['year'],
			$fields['title'],
			$fields['publisher'],
			$fields['url'],
			$fields['accessedDate']
		);
	}

	/**
	 * A Website reference's single author is EITHER a named individual
	 * (rendered "Surname, I." — the same join_people() single-person shape,
	 * never a joined list, since Liverpool Hope's website rule has no
	 * multi-author convention) OR the organisation responsible for the page,
	 * rendered exactly as given (never comma-inverted or abbreviated to
	 * initials — an organisation name is not a person's name).
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
	 * "(ed.)" for exactly one editor, "(eds)" for two or more — this is the
	 * one rule this whole category exists to test (see
	 * Citex_Generated_Validator's designation/editor-count cross-check),
	 * so it is computed in exactly one place.
	 */
	public static function designation_for_editor_count( $editor_count ) {
		return $editor_count > 1 ? 'eds' : 'ed.';
	}

	/**
	 * "Smith, J." for one person; "Smith, J. and Jones, A." for two;
	 * "Smith, J., Jones, A. and Lee, K." for three or more (Harvard's
	 * standard comma-separated-with-a-final-"and" list joining). Shared by
	 * both Book authors and Edited Book editors — Liverpool Hope joins both
	 * lists identically, and neither is ever abbreviated to "et al." in a
	 * reference-list entry.
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
		return implode( ', ', $parts ) . ' and ' . $last;
	}

	/**
	 * @deprecated Use join_people() — kept only so any external reference to
	 * the old editor-specific name keeps working.
	 */
	public static function join_editors( array $editors ) {
		return self::join_people( $editors );
	}

	/**
	 * The DragDrop shape for this category: the ordered draggable Question
	 * Parts, and the Fixed Text template (Citex's established |/|| pipe
	 * grammar — see class-citex-populator.php's docblock) that the parts
	 * slot into. Editor(s)/author(s), designation, year and title are
	 * draggable; place and publisher are baked into the fixed template
	 * directly, from the record — this plugin has never made
	 * place/publisher draggable for any category.
	 *
	 * Book branches on author count: a SINGLE author keeps the original
	 * 4-part shape (surname and initials as two separate draggable parts —
	 * every existing single-author question is completely unaffected). TWO
	 * OR MORE authors use a 3-part shape (the whole author list, already
	 * joined via join_people(), as ONE draggable part; year; title) —
	 * exactly how Edited Book already treats its joined editor string as a
	 * single draggable part, so a multi-author DragDrop question tests
	 * "drag the correctly-joined author list into place," not "drag each of
	 * N authors' 2N sub-parts," which would make the draggable-part count
	 * (and therefore the UI) vary per question in a way the app was never
	 * built to support.
	 *
	 * @return array{parts: string[], fixedText: string}
	 */
	public static function dragdrop_shape( $category, array $fields, $design = null ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			$editors     = $fields['editors'];
			$designation = self::designation_for_editor_count( count( $editors ) );
			return array(
				'parts'     => array( self::join_people( $editors ), $designation, $fields['year'], $fields['title'] ),
				'fixedText' => sprintf( '| (||) (||) ||. %s: %s.', $fields['place'], $fields['publisher'] ),
			);
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return self::journal_article_dragdrop_shape( $fields, $design );
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return self::website_dragdrop_shape( $fields );
		}
		$authors = $fields['authors'];
		if ( 1 === count( $authors ) ) {
			return array(
				'parts'     => array( $authors[0]['surname'], $authors[0]['initials'], $fields['year'], $fields['title'] ),
				'fixedText' => sprintf( '|, || (||) ||. %s: %s.', $fields['place'], $fields['publisher'] ),
			);
		}
		return array(
			'parts'     => array( self::join_people( $authors ), $fields['year'], $fields['title'] ),
			'fixedText' => sprintf( '| (||) ||. %s: %s.', $fields['place'], $fields['publisher'] ),
		);
	}

	/**
	 * Journal Article's catalogue of DragDrop/MCQ "exercise designs".
	 *
	 * HARD RULE (see JOURNAL_ARTICLE_DRAGDROP_MIN_PARTS/MAX_PARTS): every
	 * DragDrop design below produces EXACTLY 3 or 4 draggable Question
	 * Parts, for ANY real author count — never fewer, never more. This is
	 * only achievable because the whole author list (1 author or several)
	 * is always ONE joined chip (e.g. "Bennett, S." or "Bennett, S., Maton,
	 * K. and Kervin, L.", via join_people()) — never one chip per author,
	 * and never "et al." (Liverpool Hope's reference-list rule always lists
	 * every author in full; "et al." is only ever a wrong MCQ distractor,
	 * never a correct DragDrop/MCQ answer — see build_reference()'s
	 * docblock). A single joined author chip stays compact for realistic
	 * author counts; the mobile-suitability length gate below is the
	 * backstop against a genuinely oversized real name list.
	 *
	 * DragDrop-eligible designs (each names its 3-4 tested facts):
	 * - author_year_volume_pages (4 parts): author(s), year, volume, pages.
	 * - author_year_issue (3 parts): author(s), year, issue.
	 * - author_year_journal (3 parts): author(s), year, journal title.
	 * - volume_issue_pages (3 parts): volume, issue, pages — a genuine
	 *   contiguous Harvard fragment, "Volume(Issue), pp.Start-End.".
	 * - journal_volume_issue (3 parts): journal title, volume, issue — a
	 *   genuine contiguous Harvard fragment, "Journal title, Volume(Issue)".
	 * - year_volume_issue_pages (4 parts, no author at all): year, volume,
	 *   issue, pages — a genuinely different focus for variety.
	 *
	 * MCQ-only designs (no DragDrop part-count constraint applies — see
	 * Citex_Question_Scenarios's Journal Article MCQ-only scenarios):
	 * - full_reference: the complete reference (all fields) — the original
	 *   "select the correct reference" MCQ mechanic, unchanged.
	 * - author_only: a single author's "Surname, I." in isolation, always
	 *   exactly 1 real author.
	 *
	 * Never generate a design whose only tested "fact" is a punctuation
	 * mark (full stop, comma, colon, apostrophe, brackets) — punctuation
	 * stays part of the fixed reference structure/correctness validation,
	 * never the learning objective itself, for every design above.
	 *
	 * @return string[] design ids.
	 */
	public static function journal_article_designs() {
		return array( 'author_year_volume_pages', 'author_year_issue', 'author_year_journal', 'volume_issue_pages', 'journal_volume_issue', 'year_volume_issue_pages', 'full_reference', 'author_only' );
	}

	/**
	 * Design ids permitted for a Journal Article DragDrop question — every
	 * design except the two MCQ-only ones (full_reference is too large at
	 * 7 parts; author_only is too small at 1 part — both violate the 3-4
	 * part hard rule). Used by Citex_AI_V2's quality gate and
	 * Citex_Generated_Validator to reject a DragDrop candidate assigned an
	 * MCQ-only design outright, rather than letting it fail some other,
	 * less specific check.
	 *
	 * @return string[]
	 */
	public static function journal_article_dragdrop_designs() {
		return array_values( array_diff( self::journal_article_designs(), array( 'full_reference', 'author_only' ) ) );
	}

	/**
	 * Which canonical fields a given design's reconstructed STRING actually
	 * contains — used by the validator to gate its "reference/scenario must
	 * mention canonical fact X" checks per design, since a short partial
	 * design's correct answer legitimately does not contain every field
	 * (e.g. author_format's "Mitchell, S." contains no article/journal
	 * title at all), while punctuation_final_stop still shows the complete
	 * content with only the trailing full stop blanked.
	 *
	 * @return string[]|null null for an unrecognised design id.
	 */
	public static function journal_article_design_fields( $design ) {
		$map = array(
			'full_reference'           => array( 'authors', 'year', 'articleTitle', 'journalTitle', 'volume', 'issue', 'pages' ),
			'author_year_volume_pages' => array( 'authors', 'year', 'volume', 'pages' ),
			'author_year_issue'        => array( 'authors', 'year', 'issue' ),
			'author_year_journal'      => array( 'authors', 'year', 'journalTitle' ),
			'volume_issue_pages'       => array( 'volume', 'issue', 'pages' ),
			'journal_volume_issue'     => array( 'journalTitle', 'volume', 'issue' ),
			'year_volume_issue_pages'  => array( 'year', 'volume', 'issue', 'pages' ),
			'author_only'              => array( 'authors' ),
		);
		return $map[ $design ] ?? null;
	}

	/**
	 * Whether a design's reconstructed string is a genuine COMPLETE sentence
	 * ending in a real Harvard full stop, or a fragment that legitimately
	 * stops mid-reference with no full stop at that point (only
	 * 'journal_volume_issue's "Journal title, Volume(Issue)" — the real
	 * reference has no punctuation there before ", pp.Start-End." follows).
	 * Every comma-separated field-combo design (author_year_volume_pages,
	 * author_year_issue, author_year_journal, year_volume_issue_pages)
	 * deliberately ends its own list with a real full stop, precisely so
	 * this never needs special-casing for them. Used by
	 * Citex_Generated_Validator to avoid flagging a legitimate mid-reference
	 * fragment as MISSING_FINAL_PERIOD.
	 */
	public static function journal_article_design_skips_final_period( $design ) {
		return 'journal_volume_issue' === $design;
	}

	/**
	 * The real author-count range a design requires, or null when the
	 * design has no author-count constraint of its own beyond whatever the
	 * assigned scenario's targetCounts already enforce. Defence in depth —
	 * see the call site in Citex_AI_V2::normalise() — for a direct caller
	 * that bypasses scenario assignment entirely.
	 *
	 * @return array{0:int,1:int}|null [min, max] inclusive.
	 */
	public static function journal_article_design_author_bounds( $design ) {
		$map = array(
			'author_only' => array( 1, 1 ),
		);
		return $map[ $design ] ?? null;
	}

	/**
	 * Journal Article's DragDrop shape, per exercise design. $design of
	 * null or 'full_reference' reconstructs the complete reference (7
	 * parts, MCQ-only — see journal_article_dragdrop_designs()). Every
	 * other design produces EXACTLY 3 or 4 parts (the hard DragDrop rule —
	 * see JOURNAL_ARTICLE_DRAGDROP_MIN_PARTS/MAX_PARTS), built from a
	 * SINGLE joined author-list chip (via join_people() — "Bennett, S." for
	 * one author, "Bennett, S., Maton, K. and Kervin, L." for three, never
	 * "et al." and never one chip per author) plus 2-3 other short fields.
	 * There is no place/publisher to bake into the fixed template for any
	 * design — this category has none.
	 */
	private static function journal_article_dragdrop_shape( array $fields, $design = null ) {
		$design      = $design ?: 'full_reference';
		$author_chip = self::join_people( $fields['authors'] );

		if ( 'author_only' === $design ) {
			return array(
				'parts'     => array( $author_chip ),
				'fixedText' => '|',
			);
		}
		if ( 'author_year_volume_pages' === $design ) {
			// "Author(s) (Year) Volume, pp.Start-End." — real Harvard
			// punctuation throughout (parentheses for the year, "pp."
			// prefix), just skipping the title/journal/issue segment.
			return array(
				'parts'     => array( $author_chip, $fields['year'], $fields['volume'], $fields['pages'] ),
				'fixedText' => '| (||) ||, pp.||.',
			);
		}
		if ( 'author_year_issue' === $design ) {
			// A plain, unambiguous "fact list" — deliberately NOT styled
			// like a real Harvard fragment (issue alone is never shown in
			// its own parentheses immediately after the year in a real
			// reference; doing so here would misteach that placement).
			return array(
				'parts'     => array( $author_chip, $fields['year'], $fields['issue'] ),
				'fixedText' => '|, ||, ||.',
			);
		}
		if ( 'author_year_journal' === $design ) {
			return array(
				'parts'     => array( $author_chip, $fields['year'], $fields['journalTitle'] ),
				'fixedText' => '|, ||, ||.',
			);
		}
		if ( 'volume_issue_pages' === $design ) {
			return array(
				'parts'     => array( $fields['volume'], $fields['issue'], $fields['pages'] ),
				'fixedText' => '|(||), pp.||.',
			);
		}
		if ( 'journal_volume_issue' === $design ) {
			return array(
				'parts'     => array( $fields['journalTitle'], $fields['volume'], $fields['issue'] ),
				'fixedText' => '||, ||(||)',
			);
		}
		if ( 'year_volume_issue_pages' === $design ) {
			return array(
				'parts'     => array( $fields['year'], $fields['volume'], $fields['issue'], $fields['pages'] ),
				'fixedText' => '|, ||, ||, ||.',
			);
		}
		return array(
			'parts'     => array(
				$author_chip,
				$fields['year'],
				$fields['articleTitle'],
				$fields['journalTitle'],
				$fields['volume'],
				$fields['issue'],
				$fields['pages'],
			),
			'fixedText' => '| (||) ||. ||, ||(||), pp.||.',
		);
	}

	/**
	 * Reconstructs the reference string a DragDrop shape (parts + fixedText)
	 * produces, using the exact same |/|| grammar
	 * Citex_Generated_Validator::reconstruct() parses — but WITHOUT that
	 * method's malformed-input error handling, since a shape built by
	 * journal_article_dragdrop_shape() (or any other dragdrop_shape() call)
	 * is always well-formed by construction. Used at CONSTRUCTION time
	 * (Citex_AI_V2's normalisers) so a design's MCQ correct answer and its
	 * DragDrop reconstruction are always computed by the identical
	 * algorithm and can never silently disagree, for any design including
	 * the original full_reference one (this is a pure refactor for that
	 * design — the string produced is unchanged).
	 */
	public static function reconstruct_reference( array $shape ) {
		$fixed  = (string) ( $shape['fixedText'] ?? '' );
		$parts  = array_values( (array) ( $shape['parts'] ?? array() ) );
		$result = '';
		$index  = 0;
		$length = strlen( $fixed );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( '|' !== $fixed[ $i ] ) {
				$result .= $fixed[ $i ];
				continue;
			}
			if ( $i + 1 < $length && '|' === $fixed[ $i + 1 ] ) {
				$result .= (string) ( $parts[ $index++ ] ?? '' );
				$i++;
				continue;
			}
			$result .= (string) ( $parts[ $index++ ] ?? '' );
		}
		return trim( $result );
	}

	/**
	 * A generation-time UX heuristic (NOT a correctness rule — kept
	 * entirely separate from Citex_Generated_Validator, which never judges
	 * question size) assessing whether a set of draggable Question Parts
	 * will comfortably fit the real Citex mobile DragDrop interface.
	 * Deliberately not a single crude character cap: it checks each
	 * component's own size against a generous per-component threshold
	 * (catching one excessively long part, e.g. an unusually long journal
	 * title or a 4-author joined block) AND the combined size of every
	 * component together (catching "individually fine, but too many
	 * large pieces at once"). Called from Citex_AI_V2's quality gate for
	 * Journal Article DragDrop/MCQ candidates; feeds the existing
	 * regenerate-with-feedback retry loop exactly like every other
	 * validation failure.
	 *
	 * @return string|null A human-readable rejection reason, or null when suitable.
	 */
	public static function journal_article_mobile_suitability( array $parts ) {
		// Generous enough that a genuinely typical 4-5 real-author joined
		// list, or a normally-worded real title, is never rejected (section
		// 5's explicit "occasional 5+ author examples where mobile layout
		// remains usable" and "FOUR-AUTHOR: test complete author
		// construction" both require full_reference to keep working for
		// realistic multi-author sources) — this is a backstop against
		// genuinely excessive cases (an unusually long title, or 6+ authors
		// with long names), not a filter on ordinary variation.
		$max_single_component = 70;
		$max_combined_total   = 220;
		$total                = 0;
		foreach ( $parts as $part ) {
			$text = (string) $part;
			// Punctuation must never itself be the draggable answer being
			// tested — a part that is nothing but punctuation/whitespace
			// (e.g. a lone ".") means the learning objective has drifted
			// onto punctuation, which requirement 1 explicitly forbids.
			if ( '' !== trim( $text ) && 1 === preg_match( '/^[\p{P}\s]+$/u', $text ) ) {
				return sprintf(
					'A draggable component ("%s") consists only of punctuation — punctuation may be part of reference correctness, but it must never be the learning objective of a draggable answer part.',
					$text
				);
			}
			$length = mb_strlen( $text );
			$total += $length;
			if ( $length > $max_single_component ) {
				return sprintf(
					'A single draggable component is %1$d characters long ("%2$s…"), too large for a comfortable mobile DragDrop layout — prefer a shorter real source, or a smaller exercise design.',
					$length,
					mb_substr( $text, 0, 30 )
				);
			}
		}
		if ( $total > $max_combined_total ) {
			return sprintf(
				'The combined length of all draggable components (%d characters) is too large for a comfortable mobile DragDrop layout — prefer a shorter real source, or a smaller exercise design.',
				$total
			);
		}
		return null;
	}

	/**
	 * Website's DragDrop shape: 6 draggable parts in the Liverpool Hope
	 * order — author/organisation, year (or "n.d."), title, publisher, URL,
	 * accessed date. "[online]" and "Available from:" are constant literal
	 * markers present in EVERY correct Website reference — they never vary
	 * per source, so (exactly like Book's "Place: " / ": " colon and Journal
	 * Article's "pp." prefix) they are baked into the fixed template rather
	 * than made draggable. There is no author-count branching at all for
	 * this category — Liverpool Hope's website rule only ever has ONE
	 * author-or-organisation.
	 */
	private static function website_dragdrop_shape( array $fields ) {
		return array(
			'parts'     => array(
				self::format_website_author( $fields['author'] ),
				$fields['year'],
				$fields['title'],
				$fields['publisher'],
				$fields['url'],
				$fields['accessedDate'],
			),
			'fixedText' => '| (||) || [online]. ||. Available from: <||> [accessed ||].',
		);
	}

	/**
	 * The overall-shape regex used to confirm a completed reference string
	 * (DragDrop's reconstruction, or MCQ's correct option) actually looks
	 * like this category's Harvard format — the category-specific
	 * counterpart to the shared punctuation/spacing checks in
	 * Citex_Generated_Validator::validate_reference_format(), which apply
	 * to every category identically.
	 */
	public static function format_regex( $category, $design = null ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			// Surname(s), Initials [and Surname, Initials ...] (ed.|eds) (Year) Title. Place: Publisher.
			return '/^.+\s+\((?:ed\.|eds)\)\s+\(\d{4}\)\s+.+\.\s+[^:]+:\s+.+\.\s*$/u';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			// A design other than 'full_reference' (or none) reconstructs a
			// short PARTIAL segment, not a complete reference — e.g.
			// author_only's "Mitchell, S." or volume_issue_pages's
			// "12(2), pp.27-35." — so it needs its own, much narrower shape
			// regex rather than the full-reference one below, which such a
			// segment could never satisfy (and must not be judged against).
			if ( null !== $design && 'full_reference' !== $design ) {
				return self::journal_article_partial_format_regex( $design );
			}
			// One or more "Surname, Initials" author groups (same join_people()
			// grammar as Book/Edited Book — a comma-joined-throughout list with
			// no final "and", or an "et al." abbreviation, both fail to match),
			// followed by (Year) Article title. Journal title, Volume(Issue),
			// pp.Start-End.
			return '/^[^,]+,\s+(?:[A-Z]\.\s*)+(?:(?:,\s+[^,]+,\s+(?:[A-Z]\.\s*)+)*\s+and\s+[^,]+,\s+(?:[A-Z]\.\s*)+)?\(\d{4}\)\s+.+\.\s+.+,\s+\d+\(\d+\),\s+pp\.\d+-\d+\.\s*$/u';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			// Author/Organisation (Year|n.d.) Title [online]. Publisher.
			// Available from: <URL> [accessed date]. The author segment is
			// deliberately `.+` (NOT the Book-style "Surname, Initials"
			// repeating group) because it may be a raw organisation name with
			// no comma/initials structure at all — the individual-vs-
			// organisation distinction is checked separately, by
			// Citex_Generated_Validator's dedicated Website consistency
			// check, not by this shape regex. The year group requires either
			// exactly 4 digits or the literal "n.d." — no other placeholder
			// text is accepted. The URL must be wrapped in literal angle
			// brackets with no whitespace inside them, and "[online]",
			// "Available from:" (with its colon) and "[accessed ...]" must
			// all be present literally — this is what makes a distractor
			// that drops any one of them, or that omits the colon after
			// "Available from", fail to match.
			return '/^.+\s+\((?:\d{4}|n\.d\.)\)\s+.+\s+\[online\]\.\s+.+\.\s+Available from:\s+<[^<>\s]+>\s+\[accessed\s+[^\]]+\]\.\s*$/u';
		}
		// One or more "Surname, Initials" groups (join_people()'s exact
		// joining grammar: every pair before the last is comma-separated,
		// and the LAST joiner must specifically be " and " — a reference
		// that is comma-joined all the way through, with no "and" before
		// the final author, is a real Harvard style violation and must NOT
		// match), followed by (Year) Title. Place: Publisher. This is a
		// real repeating group — not `.+` — specifically so a
		// reference-list entry that abbreviates to "Smith et al." can never
		// match: there is no literal comma/initials-group before "(Year)"
		// in that string, so it fails this regex the same way any other
		// malformed author list would.
		return '/^[^,]+,\s+(?:[A-Z]\.\s*)+(?:(?:,\s+[^,]+,\s+(?:[A-Z]\.\s*)+)*\s+and\s+[^,]+,\s+(?:[A-Z]\.\s*)+)?\(\d{4}\)\s+.+\.\s+[^:]+:\s+.+\.\s*$/u';
	}

	/**
	 * Shape regex for a Journal Article partial exercise design's own
	 * short reconstructed segment — deliberately much narrower than the
	 * full-reference regex above, since these strings are not, and are
	 * never claimed to be, a complete Harvard reference on their own (the
	 * validator separately, always, checks that the FULL canonical
	 * reference built from all the source data is well-formed — see
	 * Citex_Generated_Validator::validate_journal_article_consistency()).
	 */
	private static function journal_article_partial_format_regex( $design ) {
		// The same "one or more Surname, Initials groups, comma-separated
		// with a final 'and'" author-list grammar the full-reference regex
		// uses — reused here so every author-including design enforces the
		// identical joining rule, never a looser one. Never matches "et
		// al." (there is no literal comma/initials group before it).
		$author_group = '[^,]+,\s+(?:[A-Z]\.\s*)+(?:(?:,\s+[^,]+,\s+(?:[A-Z]\.\s*)+)*\s+and\s+[^,]+,\s+(?:[A-Z]\.\s*)+)?';
		if ( 'author_only' === $design ) {
			// "Surname, I." (or a joined multi-author list) — no year/title/etc.
			return '/^' . $author_group . '$/u';
		}
		if ( 'author_year_volume_pages' === $design ) {
			// "Author(s) (Year) Volume, pp.Start-End." — a genuine complete
			// sentence, ending in a real full stop.
			return '/^' . $author_group . '\s+\(\d{4}\)\s+\d+,\s+pp\.\d+-\d+\.$/u';
		}
		if ( 'author_year_issue' === $design ) {
			// "Author(s), Year, Issue." — a plain fact list, not styled as
			// a Harvard fragment (see the dragdrop_shape() docblock for why).
			return '/^' . $author_group . ',\s+\d{4},\s+\d+\.$/u';
		}
		if ( 'author_year_journal' === $design ) {
			// "Author(s), Year, Journal title." — same plain fact-list style.
			return '/^' . $author_group . ',\s+\d{4},\s+.+\.$/u';
		}
		if ( 'volume_issue_pages' === $design ) {
			// "Volume(Issue), pp.Start-End." — no author/year/title at all.
			return '/^\d+\(\d+\),\s+pp\.\d+-\d+\.$/u';
		}
		if ( 'journal_volume_issue' === $design ) {
			// "Journal title, Volume(Issue)" — no trailing full stop: the
			// real reference continues straight into ", pp.Start-End."
			return '/^.+,\s+\d+\(\d+\)$/u';
		}
		if ( 'year_volume_issue_pages' === $design ) {
			// "Year, Volume, Issue, Pages." — a plain fact list, no author.
			return '/^\d{4},\s+\d+,\s+\d+,\s+\d+-\d+\.$/u';
		}
		// An unrecognised design id must never accidentally match
		// everything — fail closed, not open.
		return '/(?!)/';
	}

	/**
	 * The catalogue of named, realistic Harvard rule-violations MCQ
	 * distractors for this category should be built from — the
	 * category-specific "common mistakes" a new category supplies alongside
	 * its rules, so Citex_AI_V2's MCQ prompts can ask Gemini for a specific,
	 * rule-based mistake per distractor (and require it to name which one it
	 * used as that distractor's error_reason) instead of leaving Gemini to
	 * invent arbitrary "different-looking" references that can accidentally
	 * still be fully valid. This never changes what counts as a "correct"
	 * reference — format_regex()/build_reference() remain the sole
	 * authority for that — it only shapes what kind of wrong Gemini is
	 * asked to produce.
	 *
	 * @return string[]
	 */
	public static function mcq_distractor_patterns( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return array(
				'Missing the editor designation "(ed.)"/"(eds)" entirely, as if it were a Book reference with no editor marked.',
				'Using "(eds)" for a question with only one editor, or "(ed.)" for a question with two editors — the wrong designation for the stated editor count.',
				'Using the full word "(editor)" or "(author)" instead of the correct "(ed.)"/"(eds)" abbreviation.',
				'Placing the designation after the year instead of immediately after the editor name(s) — e.g. "(2020) (ed.)" instead of "(ed.) (2020)".',
				'Swapping the place of publication and publisher — e.g. "Publisher: Place" instead of "Place: Publisher".',
				'Missing the full stop after the book title, or an extra comma before the year.',
				'For two editors, omitting "and" between them or joining them with the wrong punctuation.',
			);
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return array(
				'Using the author\'s full first name instead of initials — e.g. "John Smith" instead of "Smith, J.".',
				'Placing the initials before the surname — e.g. "J. Smith" instead of "Smith, J.".',
				'Placing the year outside its parentheses, or in the wrong position relative to the author.',
				'Missing the full stop after the article title, or an extra comma before the year.',
				'Missing the comma after the journal title, before the volume.',
				'Swapping the volume and issue, or placing the issue outside its parentheses — e.g. "(2)12" instead of "12(2)".',
				'Missing the "pp." prefix before the page range, or using "p." instead of "pp.".',
				'Reversing the page range — e.g. "pp.35-27" instead of "pp.27-35".',
				'Missing the final full stop at the end of the reference.',
				'For two or more authors, joining them with "&" instead of "and".',
				'For two or more authors, omitting "and" before the final author and using a comma instead.',
				'For three or more authors, joining every pair with "and" instead of separating all but the last with commas.',
				'Using "et al." after the first author\'s name in the reference list for four or more authors, instead of listing every author in full — "et al." is only Liverpool Hope\'s in-text-citation convention, never used in a reference-list entry.',
				'Listing the authors in a different order than their real published order (e.g. alphabetically by surname) instead of the order they actually appear on the article.',
			);
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return array(
				'Missing "[online]" from the reference entirely.',
				'Missing "Available from:" or omitting the colon after it — e.g. "Available from <URL>" instead of "Available from: <URL>".',
				'The URL not enclosed in angled brackets — e.g. "Available from: http://example.com" instead of "Available from: <http://example.com>".',
				'Missing the "[accessed date]" element entirely.',
				'Using a guessed or invented year instead of "(n.d.)" when no publication/creation date can be identified for the real source.',
				'Using "(n.d.)" for a real source that actually has a clearly identifiable publication/creation year.',
				'Writing an individual author\'s full name unformatted (e.g. "Sarah Mitchell") instead of the required "Surname, I." form.',
				'Missing the publisher entirely.',
				'Placing the publisher after "Available from:" instead of immediately after "[online].".',
				'Placing the URL before "Available from:" instead of after it.',
				'Missing the full stop after the page/document title, immediately before "[online]".',
				'Missing the final full stop at the end of the reference.',
			);
		}
		return array(
			'Using the author\'s full first name instead of initials — e.g. "John Smith" instead of "Smith, J.".',
			'Placing the initials before the surname — e.g. "J. Smith" instead of "Smith, J.".',
			'Placing the year outside its parentheses, or in the wrong position relative to the author.',
			'Swapping the place of publication and publisher — e.g. "Publisher: Place" instead of "Place: Publisher".',
			'Missing the full stop after the book title, or an extra comma between surname and initials.',
			'Missing the parentheses around the publication year entirely.',
			// Multi-author-specific mistakes (only realistic when the
			// question has 2+ authors — Citex_AI_V2 only surfaces these to
			// Gemini for questions it has assigned more than one author):
			'For two or more authors, joining them with "&" instead of "and".',
			'For two or more authors, omitting "and" before the final author and using a comma instead.',
			'For three or more authors, joining every pair with "and" instead of separating all but the last with commas.',
			'Using "et al." after the first author\'s name in the reference list for four or more authors, instead of listing every author in full — "et al." is only Liverpool Hope\'s in-text-citation convention, never used in a reference-list entry.',
		);
	}

	/**
	 * The fixed, student-facing MCQ question stem for this category — Citex
	 * authors this itself, deterministically, rather than asking Gemini for
	 * a per-book "scenario" describing the record. A generic "which of
	 * these is the correct reference?" question cannot leak any
	 * bibliographic fact (there is none in it to leak) and keeps MCQ
	 * questions straightforward and student-facing: the four options
	 * themselves — not the question — carry every bibliographic detail the
	 * student needs. This is the one piece of MCQ question text Citex
	 * never delegates to Gemini at all.
	 */
	public static function mcq_question_stem( $category, $design = null ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Which of the following is the correct Harvard reference for an edited book?';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			$partial_stem = self::journal_article_partial_mcq_stem( $design );
			return $partial_stem ?? 'Which of the following is the correct Harvard reference for a journal article?';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'Which of the following is the correct Harvard reference for a website/web resource?';
		}
		return 'Which of the following is the correct Harvard reference for a book?';
	}

	/**
	 * The fixed MCQ stem for a Journal Article partial exercise design —
	 * null for 'full_reference'/'punctuation_final_stop' (both use the
	 * standard full-reference stem above, since both test/show the
	 * complete reference) or an unrecognised design.
	 */
	private static function journal_article_partial_mcq_stem( $design ) {
		$stems = array(
			'author_only'              => 'Which of the following correctly formats this author\'s name for the Harvard reference list?',
			'author_year_volume_pages' => 'Which of the following correctly identifies the author(s), year, volume and page range for the Harvard reference list?',
			'author_year_issue'        => 'Which of the following correctly identifies the author(s), year and issue for the Harvard reference list?',
			'author_year_journal'      => 'Which of the following correctly identifies the author(s), year and journal title for the Harvard reference list?',
			'volume_issue_pages'       => 'Which of the following correctly formats the volume, issue and page range for the Harvard reference list?',
			'journal_volume_issue'     => 'Which of the following correctly formats the journal title, volume and issue for the Harvard reference list?',
			'year_volume_issue_pages'  => 'Which of the following correctly identifies the year, volume, issue and page range for the Harvard reference list?',
		);
		return $stems[ $design ] ?? null;
	}

	/**
	 * The fixed, non-revealing MCQ hint for this category — a general clue
	 * about which Harvard rule the question tests, written so it helps the
	 * student reason about the rule WITHOUT ever naming which option is
	 * correct, stating a specific option letter, or reproducing the
	 * correct reference. Citex authors this deterministically for the same
	 * reason it authors the question stem: nothing question-specific needs
	 * saying beyond "here is the rule this category tests," and free-form
	 * prose (from Gemini, or text built from which option happens to be
	 * correct) risks leaking the answer by construction.
	 */
	public static function mcq_hint( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Check how the editor(s) are identified, whether the designation used matches the number of editors, and the order of the year, title, place and publisher.';
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'Check the order of the author\'s surname and initials, the position of the year, and the punctuation between the article title, journal title, volume, issue and page range.';
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return 'Check whether an individual author or an organisation is used, whether a real year or "(n.d.)" is correct, and the order of the title, "[online]", the publisher, "Available from:", the URL and the accessed date.';
		}
		return 'Check the order of the author\'s surname and initials, the position of the year, and the punctuation between the title, place and publisher.';
	}

	/**
	 * The fixed, non-revealing hint for the "Identify the error" MCQ
	 * scenario — Citex authors this itself for the exact same reason as
	 * mcq_hint(): the student sees a reference with one deliberate mistake
	 * in it, and this hint must help them reason about HOW to check a
	 * reference rule-by-rule without ever naming which specific rule was
	 * broken or which option is correct (see mcq_hint()'s docblock for the
	 * full "hint never reveals" rationale — identical here, just phrased
	 * for a "spot the error" question instead of a "pick the correct
	 * reference" one).
	 */
	public static function identify_error_hint( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Work through the reference rule by rule: the editor designation and whether it matches the editor count, how the editor(s) are joined, the position of the year, and the punctuation between title, place and publisher.';
		}
		return 'Work through the reference rule by rule: the order of surname and initials, how multiple authors are joined (if there is more than one), the position of the year, and the punctuation between title, place and publisher.';
	}

	/**
	 * "Choose the correct rule" MCQ scenario — the fixed, Citex-authored
	 * question stem and the ONE TRUE rule statement for one author/editor-
	 * count bucket, keyed by the same bucket ids Citex_Question_Scenarios
	 * already uses for select_correct/construct_reference (e.g.
	 * "two_authors", "four_or_more_authors"). Citex is the sole authority
	 * for BOTH the stem and the correct statement — this question tests
	 * pure rule knowledge, not any specific real book, so unlike every
	 * other MCQ pattern there is no bibliographic record for Gemini to
	 * verify or leak an answer through at all; Gemini's only job is
	 * supplying three plausible-but-wrong statements (see
	 * Citex_AI_V2::build_prompt_choose_treatment()).
	 *
	 * Wording for "four_or_more_authors" matches the user's own confirmed
	 * example exactly (including naming the "et al." misconception this
	 * bucket exists to test — the exact confusion between Liverpool Hope's
	 * reference-list rule, which never uses "et al.", and its separate
	 * in-text-citation convention, which does).
	 *
	 * @return array{stem: string, correctStatement: string}|null null for
	 *         an unrecognised bucket id.
	 */
	public static function treatment_question( $category, $bucket_id ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			$catalogue = array(
				'two_editors'            => array(
					'stem'             => 'Which of the following statements is correct about referencing a book edited by two people in the Liverpool Hope Harvard reference list?',
					'correctStatement' => 'Both editors are included, joined by "and", followed by the designation "(eds)" — e.g. Smith, J. and Jones, A. (eds).',
				),
				'three_or_more_editors'  => array(
					'stem'             => 'Which of the following statements is correct about referencing a book edited by three or more people in the Liverpool Hope Harvard reference list?',
					'correctStatement' => 'All editors are included, separated by commas with "and" before the final editor, followed by the designation "(eds)" — e.g. Smith, J., Jones, A. and Brown, T. (eds).',
				),
			);
			return $catalogue[ $bucket_id ] ?? null;
		}
		$catalogue = array(
			'two_authors'            => array(
				'stem'             => 'Which of the following statements is correct about referencing a book written by two authors in the Liverpool Hope Harvard reference list?',
				'correctStatement' => 'Both authors are included, joined by "and" — e.g. Smith, J. and Jones, A.',
			),
			'three_authors'          => array(
				'stem'             => 'Which of the following statements is correct about referencing a book written by three authors in the Liverpool Hope Harvard reference list?',
				'correctStatement' => 'All three authors are included, separated by commas with "and" before the final author — e.g. Smith, J., Jones, A. and Brown, T.',
			),
			'four_or_more_authors'   => array(
				'stem'             => 'Which statement is correct about a book with four or more authors in the Liverpool Hope Harvard reference list?',
				'correctStatement' => 'All authors should be included; et al. is not used in the reference list.',
			),
		);
		return $catalogue[ $bucket_id ] ?? null;
	}

	/**
	 * The fixed, non-revealing hint for the "Choose the correct rule"
	 * scenario — same "never name the answer" standard as mcq_hint()/
	 * identify_error_hint(), phrased for a pure rule-knowledge question:
	 * points the student at the general distinction to reason about
	 * (author/editor-count joining conventions, and the reference-list vs
	 * in-text-citation distinction) without stating which statement is true.
	 */
	public static function treatment_hint( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Think about how the editor designation and the joining of multiple editor names change (or don\'t change) as the editor count grows — and remember this is the reference-list rule, not the separate in-text-citation convention.';
		}
		return 'Think about how the joining of multiple author names changes (or doesn\'t change) as the author count grows — and remember this is the reference-list rule, not the separate in-text-citation convention (which does use "et al.").';
	}
}
