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
	const JOURNAL_ARTICLE_DRAGDROP_MAX_PARTS = 3;

	/**
	 * HARD RULE for Book DragDrop questions (never MCQ): every generated
	 * question must draw exactly 3 draggable Question Parts, no fewer, no
	 * more — Citex_Book_Dragdrop_Parts::select_parts() only ever produces a
	 * 3-part selection. Enforced independently at validation time
	 * (Citex_Generated_Validator::validate_dragdrop()'s Book-only block),
	 * mirroring JOURNAL_ARTICLE_DRAGDROP_MIN_PARTS/MAX_PARTS's existing
	 * pattern (every category's DragDrop hard rule is exactly 3 parts).
	 */
	const BOOK_DRAGDROP_MIN_PARTS = 3;
	const BOOK_DRAGDROP_MAX_PARTS = 3;

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
	 * Breaks a list of people (authors/editors) into up to $max individual
	 * draggable Question Parts — the mechanism that lets DragDrop show
	 * "individual names ... as question parts" (never one joined
	 * multi-person chunk) while every design still lands on exactly 3
	 * total parts. Any person beyond $max is folded into $overflow, a
	 * correctly-joined literal continuation meant to be appended into
	 * fixedText as ordinary (non-draggable) text — so the reconstructed
	 * reference still names every person in full (the "always list every
	 * author, never et al." rule is fully preserved) even though only the
	 * first $max are graded as draggable parts. $joiners are the literal
	 * connector strings (", " or " and ") to place between consecutive
	 * drawn parts in fixedText, chosen so concatenating
	 * drawn[0] . joiners[0] . drawn[1] . ... . $overflow always reproduces
	 * exactly what join_people() would return for the WHOLE list — see
	 * name_template() for the fixedText-building counterpart.
	 *
	 * $max is 1 at every call site in this class — only ever the FIRST
	 * author/editor becomes an individual draggable Question Part, so a
	 * part is never lengthened by joining multiple people's names
	 * together; the parameter stays general (rather than hardcoding 1
	 * internally) purely so a future design can deliberately ask for more.
	 *
	 * @param array $people array<{surname, initials}>, 1 or more.
	 * @param int   $max
	 * @return array{0: string[], 1: string[], 2: string} [drawn, joiners, overflow]
	 */
	public static function person_parts( array $people, $max = 1 ) {
		$draw_count      = min( count( $people ), max( 1, (int) $max ) );
		$drawn_people    = array_slice( $people, 0, $draw_count );
		$overflow_people = array_slice( $people, $draw_count );

		$drawn = array();
		foreach ( $drawn_people as $person ) {
			$drawn[] = sprintf( '%s, %s', $person['surname'], $person['initials'] );
		}

		$joiners = array();
		for ( $i = 0; $i < $draw_count - 1; $i++ ) {
			$is_last_pair = empty( $overflow_people ) && ( $draw_count - 2 === $i );
			$joiners[]    = $is_last_pair ? ' and ' : ', ';
		}

		// The connector from the last DRAWN part into the overflow must
		// itself follow the "comma throughout, 'and' only before the very
		// last person" rule: when exactly one person overflows, that person
		// IS the last person overall, so the connector is " and "; when 2+
		// overflow, the "and" belongs INSIDE join_people($overflow_people)
		// (between ITS last two), so the connector here is a plain ", ".
		if ( empty( $overflow_people ) ) {
			$overflow = '';
		} elseif ( 1 === count( $overflow_people ) ) {
			$overflow = ' and ' . self::join_people( $overflow_people );
		} else {
			$overflow = ', ' . self::join_people( $overflow_people );
		}

		return array( $drawn, $joiners, $overflow );
	}

	/**
	 * Builds the fixedText fragment for a set of drawn person-parts (see
	 * person_parts()) — a placeholder token per drawn part, with each
	 * $joiners entry as literal (non-draggable) text between consecutive
	 * tokens. Callers append their own $overflow string (already correctly
	 * formatted by person_parts()) immediately after this fragment.
	 *
	 * Every call site in this class places this fragment's output at the
	 * very start of the whole fixedText string (nothing before it), so —
	 * matching Citex's established pipe grammar, where a placeholder at the
	 * absolute start or end of Fixed Text is written as a single "|" and
	 * every other (internal) placeholder as "||" (see the single-author
	 * Book baseline's own '|, || (||) ||. ...' template) — the FIRST drawn
	 * token is written as a single "|"; any further drawn token (only
	 * possible if a future design ever passes person_parts() a $max greater
	 * than 1) is an internal "||", since only the very first token can ever
	 * be the leading character.
	 *
	 * @param string[] $drawn
	 * @param string[] $joiners
	 * @return string
	 */
	public static function name_template( array $drawn, array $joiners ) {
		$out = '';
		foreach ( $drawn as $index => $unused_part ) {
			$out .= ( 0 === $index ) ? '|' : '||';
			if ( isset( $joiners[ $index ] ) ) {
				$out .= $joiners[ $index ];
			}
		}
		return $out;
	}

	/**
	 * The DragDrop shape for this category: the ordered draggable Question
	 * Parts, and the Fixed Text template (Citex's established |/|| pipe
	 * grammar — see class-citex-populator.php's docblock) that the parts
	 * slot into.
	 *
	 * Book is NOT handled here — its DragDrop shape is built dynamically,
	 * per question, by Citex_Book_Dragdrop_Parts (a 3-part selection from a
	 * wider pool that includes the joining word "and", not just whole
	 * bibliographic fields), replacing the fixed catalogue this method used
	 * to serve for it.
	 *
	 * Edited Book routes every design (including the DEFAULT, null or the
	 * id returned by edited_book_dragdrop_designs()[0]) through
	 * edited_book_dragdrop_shape_variant() — every design draws the editor
	 * (as one combined chip, or split into surname/initials for
	 * editor_split_designation) and its designation ("(ed.)"/"(eds)",
	 * never traded away — this category's own defining rule, always
	 * tested), plus exactly ONE further field (year, title, place, or
	 * publisher) so every design stays at exactly 3 parts — see
	 * edited_book_dragdrop_designs()'s own docblock for the full catalogue,
	 * and Citex_AI_V2::normalise_edited_book_item() for where a design is
	 * actually picked, at random, per question.
	 *
	 * @return array{parts: string[], fixedText: string}
	 */
	public static function dragdrop_shape( $category, array $fields, $design = null ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return self::edited_book_dragdrop_shape_variant( $design ?: self::edited_book_dragdrop_designs()[0], $fields['editors'], $fields );
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return self::journal_article_dragdrop_shape( $fields, $design );
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return self::website_dragdrop_shape( $fields, $design );
		}
		return null;
	}

	/**
	 * Edited Book's DragDrop "exercise design" catalogue — a fixed
	 * catalogue of author/field-swap designs (Book's own equivalent
	 * catalogue was replaced by Citex_Book_Dragdrop_Parts's dynamic 3-part
	 * selection — see dragdrop_shape()'s docblock). The designation
	 * ("(ed.)"/"(eds)") part is never traded away (it is this category's
	 * own defining rule, always tested) — every design draws it plus the
	 * editor, leaving room for exactly ONE further field so every design
	 * stays at exactly 3 parts (the hard, category-wide DragDrop rule):
	 * - editor_designation_year (baseline): editor, designation, year —
	 *   title/place/publisher baked into fixedText.
	 * - editor_designation_title: editor, designation, title — year/place/
	 *   publisher baked into fixedText.
	 * - editor_designation_place: editor, designation, place — year/title/
	 *   publisher baked into fixedText.
	 * - editor_designation_publisher: editor, designation, publisher —
	 *   year/title/place baked into fixedText.
	 * - editor_split_designation: surname, initials, designation — the
	 *   drawn editor as two separate parts instead of one combined
	 *   "Surname, I." chip, already filling the 3-part budget on its own
	 *   (designation can never be traded away, so there is no room left for
	 *   a further field in a split design); year/title/place/publisher all
	 *   baked into fixedText.
	 *
	 * @return string[] design ids, baseline first.
	 */
	public static function edited_book_dragdrop_designs() {
		return array( 'editor_designation_year', 'editor_designation_title', 'editor_designation_place', 'editor_designation_publisher', 'editor_split_designation' );
	}

	/**
	 * @return string[]|null null for an unrecognised design id.
	 */
	public static function edited_book_dragdrop_design_fields( $design ) {
		$map = array(
			'editor_designation_year'      => array( 'editors', 'designation', 'year' ),
			'editor_designation_title'     => array( 'editors', 'designation', 'title' ),
			'editor_designation_place'     => array( 'editors', 'designation', 'place' ),
			'editor_designation_publisher' => array( 'editors', 'designation', 'publisher' ),
			'editor_split_designation'     => array( 'editors', 'designation' ),
		);
		return $map[ $design ] ?? null;
	}

	/**
	 * Edited Book counterpart to book_dragdrop_design_for() — same
	 * seeded-but-unpredictable selection, weighted so the baseline is
	 * picked a third of the time and each of the 4 variety designs a
	 * further sixth.
	 *
	 * @param string|int $seed Typically the question's own id (e.g. "EB04").
	 * @return string design id.
	 */
	public static function edited_book_dragdrop_design_for( $seed ) {
		$weighted = array_merge(
			array_fill( 0, 2, 'editor_designation_year' ),
			array( 'editor_designation_title', 'editor_designation_place', 'editor_designation_publisher', 'editor_split_designation' )
		);
		$index = abs( crc32( 'edited_book|' . (string) $seed ) ) % count( $weighted );
		return $weighted[ $index ];
	}

	/**
	 * Builds the DragDrop shape for any of edited_book_dragdrop_designs()'s
	 * ids. The designation part is always drawn, in every design — see
	 * this method's own docblock (and edited_book_dragdrop_designs()'s).
	 * editor_split_designation renders the drawn editor as two separate
	 * parts (surname, initials) instead of one combined chip, reusing
	 * person_parts() purely for its $overflow computation and Citex's
	 * established leading-pipe fragment "|, ||" — see
	 * book_dragdrop_shape_variant()'s identical treatment for Book's own
	 * split designs. Every other design draws the editor as one combined
	 * chip plus designation plus exactly one further field, keeping every
	 * design at exactly 3 parts.
	 *
	 * `confusingWords` is now also computed HERE, deterministically from the
	 * record's own fields — never Gemini-authored (see the "SHARED
	 * DETERMINISTIC DISTRACTOR PRIMITIVES" section at the bottom of this
	 * class) — so Citex_AI_V2's generation-time candidate and
	 * Citex_Generated_Validator's later recomputation can never silently
	 * disagree, exactly like `parts`/`fixedText` already couldn't.
	 *
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}
	 */
	private static function edited_book_dragdrop_shape_variant( $design, array $editors, array $fields ) {
		$designation    = self::designation_for_editor_count( count( $editors ) );
		$record_seed    = 'edited_book_dragdrop|' . implode( '|', array( (string) $fields['year'], (string) $fields['title'], (string) $fields['place'], (string) $fields['publisher'] ) );
		$other          = isset( $editors[1] ) ? $editors[1] : null;
		$other_combined = null !== $other ? sprintf( '%s, %s', $other['surname'], $other['initials'] ) : null;

		list( $drawn, $joiners, $overflow ) = self::person_parts( $editors, 1 );

		if ( 'editor_split_designation' === $design ) {
			$surname_distractor  = self::split_person_distractor( 'surname', $editors[0]['surname'], null !== $other ? $other['surname'] : null, $editors[0]['fullName'] ?? '', $record_seed . '|surname' );
			$initials_distractor = self::split_person_distractor( 'initials', $editors[0]['initials'], null !== $other ? $other['initials'] : null, '', $record_seed . '|initials' );
			return array(
				'parts'          => array( $editors[0]['surname'], $editors[0]['initials'], $designation ),
				'fixedText'      => sprintf( '|, ||%s (||) (%s) %s. %s: %s.', $overflow, $fields['year'], $fields['title'], $fields['place'], $fields['publisher'] ),
				'confusingWords' => array( $surname_distractor, $initials_distractor, self::designation_mistake_distractor( $designation, count( $editors ), $record_seed . '|designation' ) ),
			);
		}

		$editor_template        = self::name_template( $drawn, $joiners ) . $overflow;
		$editor_distractor      = self::combined_person_distractor( $drawn[0], $other_combined, $editors[0]['fullName'] ?? '', $editors[0]['surname'], $record_seed . '|editor' );
		$designation_distractor = self::designation_mistake_distractor( $designation, count( $editors ), $record_seed . '|designation' );

		if ( 'editor_designation_title' === $design ) {
			return array(
				'parts'          => array_merge( $drawn, array( $designation, $fields['title'] ) ),
				'fixedText'      => sprintf( '%s (||) (%s) ||. %s: %s.', $editor_template, $fields['year'], $fields['place'], $fields['publisher'] ),
				'confusingWords' => array( $editor_distractor, $designation_distractor, self::title_like_distractor( $fields['title'], $fields['year'], $record_seed . '|title' ) ),
			);
		}
		if ( 'editor_designation_place' === $design ) {
			return array(
				'parts'          => array_merge( $drawn, array( $designation, $fields['place'] ) ),
				'fixedText'      => sprintf( '%s (||) (%s) %s. ||: %s.', $editor_template, $fields['year'], $fields['title'], $fields['publisher'] ),
				'confusingWords' => array( $editor_distractor, $designation_distractor, self::pick_from_pool( self::place_pool(), array( $fields['place'] ), $record_seed . '|place' ) ?? 'n.p.' ),
			);
		}
		if ( 'editor_designation_publisher' === $design ) {
			return array(
				'parts'          => array_merge( $drawn, array( $designation, $fields['publisher'] ) ),
				'fixedText'      => sprintf( '%s (||) (%s) %s. %s: ||.', $editor_template, $fields['year'], $fields['title'], $fields['place'] ),
				'confusingWords' => array( $editor_distractor, $designation_distractor, self::pick_from_pool( self::publisher_pool(), array( $fields['publisher'] ), $record_seed . '|publisher' ) ?? 'n.pub.' ),
			);
		}
		// 'editor_designation_year' (baseline).
		return array(
			'parts'          => array_merge( $drawn, array( $designation, $fields['year'] ) ),
			'fixedText'      => sprintf( '%s (||) (||) %s. %s: %s.', $editor_template, $fields['title'], $fields['place'], $fields['publisher'] ),
			'confusingWords' => array( $editor_distractor, $designation_distractor, self::year_distractor( $fields['year'], $record_seed . '|year' ) ),
		);
	}

	/**
	 * Journal Article's catalogue of DragDrop/MCQ "exercise designs".
	 *
	 * HARD RULE (see JOURNAL_ARTICLE_DRAGDROP_MIN_PARTS/MAX_PARTS): every
	 * DragDrop design below produces EXACTLY 3 draggable Question
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
	 * DragDrop-eligible designs (each names its 3 tested facts). Two design
	 * ids keep their historical name even though they no longer draw every
	 * field their name suggests (renaming would have meant threading a new
	 * id through the scenario catalogue, the prompt notes, and every test
	 * that exercises them, for no behavioural benefit) — the dropped field
	 * stays in fixedText as ordinary literal text, exactly like every other
	 * non-tested field on every design:
	 * - author_year_volume_pages (3 parts, despite the name): author(s),
	 *   year, volume — pages is baked into fixedText as literal text, not
	 *   drawn.
	 * - author_year_issue (3 parts): author(s), year, issue.
	 * - author_year_journal (3 parts): author(s), year, journal title.
	 * - volume_issue_pages (3 parts): volume, issue, pages — a genuine
	 *   contiguous Harvard fragment, "Volume(Issue), pp.Start-End.".
	 * - journal_volume_issue (3 parts): journal title, volume, issue — a
	 *   genuine contiguous Harvard fragment, "Journal title, Volume(Issue)".
	 * - year_volume_issue_pages (3 parts, despite the name, no author at
	 *   all): year, volume, issue — pages is baked into fixedText as
	 *   literal text, not drawn, for the same reason as
	 *   author_year_volume_pages above.
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
	 * 7 parts; author_only is too small at 1 part — both violate the
	 * exactly-3-part hard rule). Used by Citex_AI_V2's quality gate and
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
			'author_year_volume_pages' => array( 'authors', 'year', 'volume' ),
			'author_year_issue'        => array( 'authors', 'year', 'issue' ),
			'author_year_journal'      => array( 'authors', 'year', 'journalTitle' ),
			'volume_issue_pages'       => array( 'volume', 'issue', 'pages' ),
			'journal_volume_issue'     => array( 'journalTitle', 'volume', 'issue' ),
			'year_volume_issue_pages'  => array( 'year', 'volume', 'issue' ),
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
	 * other design produces EXACTLY 3 parts (the hard DragDrop rule —
	 * see JOURNAL_ARTICLE_DRAGDROP_MIN_PARTS/MAX_PARTS).
	 *
	 * The 3 author-testing designs (author_year_volume_pages,
	 * author_year_issue, author_year_journal) draw only the FIRST author
	 * individually, via person_parts() — exactly the same "one short chip,
	 * the rest folded into fixedText as a literal continuation" technique
	 * already used for Book's own author and Edited Book's editor — rather
	 * than the WHOLE joined author list as one chip. A real multi-author
	 * article (routinely 3-6+ authors in the sciences) produced a genuinely
	 * unusable, multi-line drag chip under the old whole-list approach — a
	 * real reported bug, fixed the same way Book/Edited Book already avoid
	 * it. The author-joining rule ("and", never "&"; never "et al.") is
	 * still fully present in the reconstructed reference text (via the
	 * literal overflow) and still checked at validation time — it is no
	 * longer itself a draggable chip for these 3 designs, matching
	 * Book/Edited Book's own baseline designs, which never draw "and" as a
	 * chip either.
	 *
	 * `confusingWords` is now also computed HERE, deterministically from the
	 * record's own fields — never Gemini-authored (see the "SHARED
	 * DETERMINISTIC DISTRACTOR PRIMITIVES" section at the bottom of this
	 * class). Only the 6 real DragDrop-eligible designs populate it with a
	 * meaningful value; 'author_only'/'full_reference' are MCQ-only (their
	 * own mechanic builds its own distractor options elsewhere) and return
	 * an empty array here.
	 */
	private static function journal_article_dragdrop_shape( array $fields, $design = null ) {
		$design      = $design ?: 'full_reference';
		$authors     = $fields['authors'];
		$record_seed = 'journal_article_dragdrop|' . implode( '|', array( (string) $fields['year'], (string) $fields['articleTitle'], (string) $fields['journalTitle'], (string) $fields['volume'], (string) $fields['issue'], (string) $fields['pages'] ) );

		if ( 'author_only' === $design ) {
			// Always exactly 1 real author by construction (see
			// journal_article_design_author_bounds()) — a single combined
			// part, same as ever.
			return array(
				'parts'          => array( sprintf( '%s, %s', $authors[0]['surname'], $authors[0]['initials'] ) ),
				'fixedText'      => '|',
				'confusingWords' => array(),
			);
		}
		if ( in_array( $design, array( 'author_year_volume_pages', 'author_year_issue', 'author_year_journal' ), true ) ) {
			list( $author_drawn, $author_joiners, $author_overflow ) = self::person_parts( $authors, 1 );
			$author_template   = self::name_template( $author_drawn, $author_joiners ) . $author_overflow;
			$other_author_full = isset( $authors[1] ) ? sprintf( '%s, %s', $authors[1]['surname'], $authors[1]['initials'] ) : null;
			$author_distractor = self::combined_person_distractor( $author_drawn[0], $other_author_full, $authors[0]['fullName'] ?? '', $authors[0]['surname'], $record_seed . '|authors' );

			if ( 'author_year_volume_pages' === $design ) {
				// "Author (Year) Volume, pp.Start-End." — real Harvard
				// punctuation throughout (parentheses for the year, "pp."
				// prefix), just skipping the title/journal/issue segment.
				// Pages is baked into fixedText as literal text (see this
				// design's own docblock entry in journal_article_designs())
				// rather than drawn, so the design stays within the
				// exactly-3-part rule.
				return array(
					'parts'          => array( $author_drawn[0], $fields['year'], $fields['volume'] ),
					'fixedText'      => sprintf( '%s (||) ||, pp.%s.', $author_template, $fields['pages'] ),
					'confusingWords' => array(
						$author_distractor,
						self::year_distractor( $fields['year'], $record_seed . '|year' ),
						self::small_integer_distractor( $fields['volume'], $record_seed . '|volume' ),
					),
				);
			}
			if ( 'author_year_issue' === $design ) {
				// A plain, unambiguous "fact list" — deliberately NOT styled
				// like a real Harvard fragment (issue alone is never shown in
				// its own parentheses immediately after the year in a real
				// reference; doing so here would misteach that placement).
				return array(
					'parts'          => array( $author_drawn[0], $fields['year'], $fields['issue'] ),
					'fixedText'      => sprintf( '%s, ||, ||.', $author_template ),
					'confusingWords' => array(
						$author_distractor,
						self::year_distractor( $fields['year'], $record_seed . '|year' ),
						self::small_integer_distractor( $fields['issue'], $record_seed . '|issue' ),
					),
				);
			}
			// 'author_year_journal'.
			return array(
				'parts'          => array( $author_drawn[0], $fields['year'], $fields['journalTitle'] ),
				'fixedText'      => sprintf( '%s, ||, ||.', $author_template ),
				'confusingWords' => array(
					$author_distractor,
					self::year_distractor( $fields['year'], $record_seed . '|year' ),
					self::pick_from_pool( self::journal_pool(), array( $fields['journalTitle'] ), $record_seed . '|journal' ) ?? 'n.j.',
				),
			);
		}
		if ( 'volume_issue_pages' === $design ) {
			return array(
				'parts'          => array( $fields['volume'], $fields['issue'], $fields['pages'] ),
				'fixedText'      => '|(||), pp.||.',
				'confusingWords' => array(
					self::small_integer_distractor( $fields['volume'], $record_seed . '|volume' ),
					self::small_integer_distractor( $fields['issue'], $record_seed . '|issue' ),
					self::page_range_distractor( $fields['pages'], $record_seed . '|pages' ),
				),
			);
		}
		if ( 'journal_volume_issue' === $design ) {
			return array(
				'parts'          => array( $fields['journalTitle'], $fields['volume'], $fields['issue'] ),
				'fixedText'      => '|, ||(||)',
				'confusingWords' => array(
					self::pick_from_pool( self::journal_pool(), array( $fields['journalTitle'] ), $record_seed . '|journal' ) ?? 'n.j.',
					self::small_integer_distractor( $fields['volume'], $record_seed . '|volume' ),
					self::small_integer_distractor( $fields['issue'], $record_seed . '|issue' ),
				),
			);
		}
		if ( 'year_volume_issue_pages' === $design ) {
			// A plain fact list, not styled as a Harvard fragment (same
			// style as author_year_issue/author_year_journal above) — pages
			// stays a bare number range with no "pp." prefix, exactly as it
			// always has been for this design. Pages is baked into
			// fixedText as literal text (see this design's own docblock
			// entry in journal_article_designs()), not drawn, so only
			// year/volume/issue are draggable — exactly 3 parts.
			return array(
				'parts'          => array( $fields['year'], $fields['volume'], $fields['issue'] ),
				'fixedText'      => sprintf( '|, ||, ||, %s.', $fields['pages'] ),
				'confusingWords' => array(
					self::year_distractor( $fields['year'], $record_seed . '|year' ),
					self::small_integer_distractor( $fields['volume'], $record_seed . '|volume' ),
					self::small_integer_distractor( $fields['issue'], $record_seed . '|issue' ),
				),
			);
		}
		// full_reference (MCQ-only — see journal_article_dragdrop_designs()):
		// no 3-part cap applies, since this is never shown as separate
		// DragDrop chips; kept as a single joined author chip purely to
		// compute the correct reconstructed STRING for MCQ option
		// comparison.
		return array(
			'parts'          => array(
				self::join_people( $authors ),
				$fields['year'],
				$fields['articleTitle'],
				$fields['journalTitle'],
				$fields['volume'],
				$fields['issue'],
				$fields['pages'],
			),
			'fixedText'      => '| (||) ||. ||, ||(||), pp.||.',
			'confusingWords' => array(),
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
	 * Category-agnostic — used by every category's `normalise_*_item()`,
	 * not just Journal Article's. Checks each component's own size (both
	 * character length AND word count — a part over ~20 words is rejected
	 * even if individual words are short) against a generous per-component
	 * threshold, AND the combined size of every component together
	 * (catching "individually fine, but too many large pieces at once").
	 * Every category now builds Question Parts as individual person-names
	 * (see person_parts()) rather than one large joined multi-person chip,
	 * so a single, ordinary per-component threshold applies uniformly —
	 * there is no special larger budget for any part shape. Feeds the
	 * existing regenerate-with-feedback retry loop exactly like every
	 * other validation failure (gated non-blocking via
	 * Citex_AI_V2::quality_reject() — see that class).
	 *
	 * $max_words is the word-count backstop for a SINGLE component — callers
	 * pass the admin-configured limit that actually applies (see
	 * Citex_AI_V2::max_author_words()/max_title_words()); defaults to 20 for
	 * any caller (or test) that doesn't have a more specific limit to hand.
	 * This is a backstop only: the real steering happens in the prompt (see
	 * Citex_AI_V2::content_realism_guidance()) — Gemini is asked for the
	 * exact configured length directly, this just catches it not listening.
	 *
	 * @return string|null A human-readable rejection reason, or null when suitable.
	 */
	public static function part_suitability( array $parts, $max_words = 20 ) {
		// Generous enough that an ordinary invented-or-real part is never
		// rejected — this is a backstop against genuinely excessive cases
		// (an unusually long title), not a filter on ordinary variation.
		$max_single_component_chars = 70;
		$max_single_component_words = max( 1, (int) $max_words );
		$max_combined_total_chars   = 200;
		$total                      = 0;
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
			if ( $length > $max_single_component_chars ) {
				return sprintf(
					'A single draggable component is %1$d characters long ("%2$s…"), too large for a comfortable mobile DragDrop layout — invent or choose a shorter value, or a smaller exercise design.',
					$length,
					mb_substr( $text, 0, 30 )
				);
			}
			$word_count = str_word_count( $text );
			if ( $word_count > $max_single_component_words ) {
				return sprintf(
					'A single draggable component is %1$d words long ("%2$s…"), too long — keep every field to about %3$d words or fewer.',
					$word_count,
					mb_substr( $text, 0, 30 ),
					$max_single_component_words
				);
			}
		}
		if ( $total > $max_combined_total_chars ) {
			return sprintf(
				'The combined length of all draggable components (%d characters) is too large for a comfortable mobile DragDrop layout — invent or choose shorter values, or a smaller exercise design.',
				$total
			);
		}
		return null;
	}

	/**
	 * @deprecated Use part_suitability() — kept only so any external
	 * reference to the old Journal-Article-specific name keeps working.
	 */
	public static function journal_article_mobile_suitability( array $parts ) {
		return self::part_suitability( $parts );
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
	/**
	 * Website's DragDrop shape, per exercise design — mirrors Journal
	 * Article's "named field-subset design" pattern (see
	 * journal_article_dragdrop_designs()), generalised here to a
	 * category with no multi-person joining concept at all. Every design
	 * still reconstructs the SAME complete, correct 6-field reference
	 * string (author, year, title, publisher, url, accessedDate, in the
	 * fixed Harvard order) — the choice of design only changes WHICH 3
	 * of those 6 fields are draggable Question Parts; the rest are baked
	 * into fixedText as ordinary (non-draggable) literal text, exactly
	 * like place/publisher have always been for Book. This means
	 * format_regex()'s existing full-reference shape check keeps working
	 * unchanged for every design — the reconstructed STRING never differs
	 * in shape, only in which pieces are graded.
	 */
	private static function website_dragdrop_shape( array $fields, $design = null ) {
		$design = $design ?: 'full_reference';
		$values = array(
			self::format_website_author( $fields['author'] ),
			$fields['year'],
			$fields['title'],
			$fields['publisher'],
			$fields['url'],
			$fields['accessedDate'],
		);
		// Index map: 0=author, 1=year, 2=title, 3=publisher, 4=url, 5=accessedDate.
		$draggable_map = array(
			'author_year_title'      => array( 0, 1, 2 ),
			'author_year_publisher'  => array( 0, 1, 3 ),
			'title_publisher_url'    => array( 2, 3, 4 ),
			'year_publisher_accessed' => array( 1, 3, 5 ),
			'full_reference'         => array( 0, 1, 2, 3, 4, 5 ),
		);
		$draggable = $draggable_map[ $design ] ?? $draggable_map['full_reference'];
		// The literal connective text that always follows each of the 6
		// positions, regardless of design — the same punctuation the
		// original fixed 6-part template used.
		$connectors = array( ' (', ') ', ' [online]. ', '. Available from: <', '> [accessed ', '].' );

		// `confusingWords` is now also computed HERE, deterministically from
		// the record's own fields — never Gemini-authored (see the "SHARED
		// DETERMINISTIC DISTRACTOR PRIMITIVES" section at the bottom of this
		// class). $exclude_values is every field ACTUALLY drawn this
		// question (not just each distractor's own field) so a pool-based
		// pick (organisation_pool()) can never accidentally collide with a
		// different correct part in the same question — e.g.
		// author_year_publisher draws both the author and the publisher, so
		// each one's distractor pool excludes the OTHER's correct value too.
		$record_seed          = 'website_dragdrop|' . implode( '|', array( (string) $fields['year'], (string) $fields['title'], (string) $fields['publisher'], (string) $fields['url'], (string) $fields['accessedDate'] ) );
		$drawn_correct_values = array_values( array_intersect_key( $values, array_flip( $draggable ) ) );

		$parts     = array();
		$confusing = array();
		$fixed     = '';
		foreach ( $values as $index => $value ) {
			if ( in_array( $index, $draggable, true ) ) {
				$parts[] = $value;
				// Matches Citex's established pipe grammar (see
				// name_template()'s docblock): a placeholder at the very
				// start of Fixed Text is a single "|"; only possible for
				// index 0 (author), the only draggable field that can ever
				// be the very first character emitted here.
				$fixed      .= ( '' === $fixed ) ? '|' : '||';
				$confusing[] = self::website_distractor_for_index( $index, $value, $fields, $drawn_correct_values, $record_seed );
			} else {
				$fixed .= $value;
			}
			$fixed .= $connectors[ $index ];
		}
		return array(
			'parts'          => $parts,
			'fixedText'      => $fixed,
			'confusingWords' => $confusing,
		);
	}

	/**
	 * One deterministic wrong chip for a drawn Website candidate, keyed by
	 * its position in website_dragdrop_shape()'s own 0-5 index map.
	 */
	private static function website_distractor_for_index( $index, $value, array $fields, array $exclude_values, $record_seed ) {
		switch ( $index ) {
			case 0:
				$author = $fields['author'];
				if ( 'organisation' === ( $author['type'] ?? '' ) ) {
					return self::pick_from_pool( self::organisation_pool(), $exclude_values, $record_seed . '|author' ) ?? 'Unknown Organisation';
				}
				return self::combined_person_distractor( $value, null, $author['fullName'] ?? '', $author['surname'] ?? '', $record_seed . '|author' );
			case 1:
				return self::year_or_undated_distractor( $value, $record_seed . '|year' );
			case 2:
				return self::title_like_distractor( $value, $fields['year'], $record_seed . '|title' );
			case 3:
				return self::pick_from_pool( self::organisation_pool(), $exclude_values, $record_seed . '|publisher' ) ?? 'Unknown Publisher';
			case 4:
				return self::url_distractor( $value, $record_seed . '|url' );
			case 5:
				return self::date_distractor( $value, $record_seed . '|accessed' );
			default:
				return $value . '?';
		}
	}

	/**
	 * Design ids permitted for a Website DragDrop question — every design
	 * except 'full_reference' (6 parts, MCQ-only — too large for the
	 * 3-part hard rule). 'year_publisher_accessed' keeps year (not url)
	 * among its 3 fields deliberately: it is the design assigned to the
	 * 'individual_author_undated' scenario bucket (see
	 * Citex_Question_Scenarios::website_buckets()), whose whole point is
	 * testing that the student drags "(n.d.)" correctly — dropping year
	 * here would leave that rule completely untested by DragDrop for
	 * undated sources. Mirrors
	 * Citex_Reference_Rules::journal_article_dragdrop_designs().
	 *
	 * @return string[]
	 */
	public static function website_dragdrop_designs() {
		return array( 'author_year_title', 'author_year_publisher', 'title_publisher_url', 'year_publisher_accessed' );
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
				'Using "(eds)" for a question with only one editor, or "(ed.)" for a question with two editors: the wrong designation for the stated editor count.',
				'Using the full word "(editor)" or "(author)" instead of the correct "(ed.)"/"(eds)" abbreviation.',
				'Placing the designation after the year instead of immediately after the editor name(s).',
				'Swapping the place of publication and publisher.',
				'Missing the full stop after the book title, or an extra comma before the year.',
				'For two editors, omitting "and" between them or joining them with the wrong punctuation.',
			);
		}
		if ( self::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return array(
				'Using the author\'s full first name instead of initials, for example "John Smith" instead of "Smith, J.".',
				'Placing the initials before the surname, for example "J. Smith" instead of "Smith, J.".',
				'Placing the year outside its parentheses, or in the wrong position relative to the author.',
				'Missing the full stop after the article title, or an extra comma before the year.',
				'Missing the comma after the journal title, before the volume.',
				'Swapping the volume and issue, or placing the issue outside its parentheses, for example "(2)12" instead of "12(2)".',
				'Missing the "pp." prefix before the page range, or using "p." instead of "pp.".',
				'Reversing the page range, for example "pp.35-27" instead of "pp.27-35".',
				'Missing the final full stop at the end of the reference.',
				'For two or more authors, joining them with "&" instead of "and".',
				'For two or more authors, omitting "and" before the final author and using a comma instead.',
				'For three or more authors, joining every pair with "and" instead of separating all but the last with commas.',
				'Using "et al." after the first author\'s name in the reference list for four or more authors, instead of listing every author in full: "et al." is only Liverpool Hope\'s in-text-citation convention, never used in a reference-list entry.',
				'Listing the authors in a different order than their real published order (e.g. alphabetically by surname) instead of the order they actually appear on the article.',
			);
		}
		if ( self::CATEGORY_WEBSITE === $category ) {
			return array(
				'Missing "[online]" from the reference entirely.',
				'Missing "Available from:" or omitting the colon after it, for example "Available from <URL>" instead of "Available from: <URL>".',
				'The URL not enclosed in angled brackets, for example "Available from: http://example.com" instead of "Available from: <http://example.com>".',
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
			'Using the author\'s full first name instead of the required initials.',
			'Placing the initials before the surname instead of after it.',
			'Placing the year outside its parentheses, or in the wrong position relative to the author.',
			'Swapping the place of publication and publisher.',
			'Missing the full stop after the book title, or an extra comma between surname and initials.',
			'Missing the parentheses around the publication year entirely.',
			// Multi-author-specific mistakes (only realistic when the
			// question has 2+ authors — Citex_AI_V2 only surfaces these to
			// Gemini for questions it has assigned more than one author):
			'For two or more authors, joining them with "&" instead of "and".',
			'For two or more authors, omitting "and" before the final author and using a comma instead.',
			'For three or more authors, joining every pair with "and" instead of separating all but the last with commas.',
			'Using "et al." after the first author\'s name in the reference list for four or more authors, instead of listing every author in full: "et al." is only Liverpool Hope\'s in-text-citation convention, never used in a reference-list entry.',
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
			'author_year_volume_pages' => 'Which of the following correctly identifies the author(s), year and volume for the Harvard reference list?',
			'author_year_issue'        => 'Which of the following correctly identifies the author(s), year and issue for the Harvard reference list?',
			'author_year_journal'      => 'Which of the following correctly identifies the author(s), year and journal title for the Harvard reference list?',
			'volume_issue_pages'       => 'Which of the following correctly formats the volume, issue and page range for the Harvard reference list?',
			'journal_volume_issue'     => 'Which of the following correctly formats the journal title, volume and issue for the Harvard reference list?',
			'year_volume_issue_pages'  => 'Which of the following correctly identifies the year, volume and issue for the Harvard reference list?',
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
	 * bucket exists to test — the exact confusion between Harvard's
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
					'stem'             => 'Which of the following statements is correct about referencing a book edited by two people in the Harvard reference list?',
					'correctStatement' => 'Both editors are included, joined by "and", followed by the designation "(eds)".',
				),
				'three_or_more_editors'  => array(
					'stem'             => 'Which of the following statements is correct about referencing a book edited by three or more people in the Harvard reference list?',
					'correctStatement' => 'All editors are included, separated by commas with "and" before the final editor, followed by the designation "(eds)".',
				),
			);
			return $catalogue[ $bucket_id ] ?? null;
		}
		$catalogue = array(
			'two_authors'            => array(
				'stem'             => 'Which of the following statements is correct about referencing a book written by two authors in the Harvard reference list?',
				'correctStatement' => 'Both authors are included, joined by "and".',
			),
			'three_authors'          => array(
				'stem'             => 'Which of the following statements is correct about referencing a book written by three authors in the Harvard reference list?',
				'correctStatement' => 'All three authors are included, separated by commas with "and" before the final author.',
			),
			'four_or_more_authors'   => array(
				'stem'             => 'Which statement is correct about a book with four or more authors in the Harvard reference list?',
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
			return 'Think about how the editor designation and the joining of multiple editor names change (or don\'t change) as the editor count grows, and remember this is the reference-list rule, not the separate in-text-citation convention.';
		}
		return 'Think about how the joining of multiple author names changes (or doesn\'t change) as the author count grows, and remember this is the reference-list rule, not the separate in-text-citation convention (which does use "et al.").';
	}

	// =====================================================================
	// SHARED DETERMINISTIC DISTRACTOR PRIMITIVES
	//
	// Edited Book, Journal Article and Website DragDrop questions used to
	// ask Gemini for `confusingWords` via prompt instructions — the exact
	// "prompt-only enforcement" pattern already proven unreliable elsewhere
	// in this codebase (see e.g. Citex_AI_V2's hard "no examples" and
	// place/publisher-diversity checks, both originally prompt-only too).
	// In practice this produced distractors that were too easy to spot
	// (wildly different values, generic wording) instead of genuine
	// Harvard-referencing mistakes a student might actually make. Book
	// already solved this for its own DragDrop mechanic by having Citex
	// author every distractor itself, deterministically, from the real
	// record (see Citex_Book_Dragdrop_Parts) — the methods below generalise
	// that same philosophy for the 3 remaining categories, whose
	// dragdrop_shape() methods now also return a `confusingWords` array
	// alongside `parts`/`fixedText`, computed by the SAME method called at
	// both generation time (Citex_AI_V2) and validation time
	// (Citex_Generated_Validator) — so a question's distractors can never
	// silently disagree with the record they came from, exactly like every
	// other part of this class.
	//
	// Every generator below is seeded from the record's OWN fields only
	// (never an external per-call random seed), so recomputing from the
	// same stored fields always reproduces the exact same distractors.
	// =====================================================================

	/**
	 * A fixed pool of real, globally recognised places of publication —
	 * duplicated from Citex_Book_Dragdrop_Parts's own identical pool (kept
	 * self-contained there, per this codebase's established convention for
	 * small per-file helpers), for use by Edited Book here.
	 *
	 * @return string[]
	 */
	private static function place_pool() {
		return array(
			'London', 'Oxford', 'Cambridge', 'Manchester', 'Edinburgh', 'Dublin',
			'New York', 'Boston', 'Chicago', 'San Francisco', 'Toronto', 'Vancouver',
			'Sydney', 'Melbourne', 'Singapore', 'Delhi', 'Mumbai', 'Tokyo',
			'Paris', 'Berlin', 'Amsterdam', 'Cape Town',
		);
	}

	/**
	 * A fixed pool of real, globally recognised academic publishers —
	 * duplicated from Citex_Book_Dragdrop_Parts's own identical pool, for
	 * use by Edited Book here.
	 *
	 * @return string[]
	 */
	private static function publisher_pool() {
		return array(
			'Routledge', 'Pearson', 'SAGE', 'Palgrave Macmillan', 'Oxford University Press',
			'Cambridge University Press', 'Wiley', 'Wiley-Blackwell', 'Springer', 'Elsevier',
			'Taylor & Francis', 'Bloomsbury', 'McGraw-Hill', 'Harvard University Press',
			'Yale University Press', 'University of Chicago Press',
		);
	}

	/**
	 * A fixed pool of real, well-known academic journals spanning multiple
	 * disciplines — Journal Article's journalTitle distractor pool, the
	 * same role place_pool()/publisher_pool() play for Book/Edited Book.
	 *
	 * @return string[]
	 */
	private static function journal_pool() {
		return array(
			'Nature', 'Science', 'The Lancet', 'BMJ', 'Cell', 'PNAS',
			'Journal of Applied Psychology', 'American Economic Review',
			'Journal of Marketing', 'Harvard Business Review',
			'British Journal of Sociology', 'Journal of Educational Psychology',
			'Cities', 'Urban Studies', 'Journal of Media Studies',
			'International Journal of Human-Computer Studies',
		);
	}

	/**
	 * A fixed pool of real, well-known organisations — Website's distractor
	 * pool for BOTH an organisation-author mix-up and a publisher mix-up (a
	 * webpage's "publisher" is itself an organisation), the same role
	 * place_pool()/publisher_pool() play elsewhere.
	 *
	 * @return string[]
	 */
	private static function organisation_pool() {
		return array(
			'World Health Organization', 'United Nations', 'UNESCO', 'World Bank',
			'NHS', 'British Council', 'European Commission', 'UNICEF',
			'Department for Education', 'Office for National Statistics',
			'University of Oxford', 'University of Cambridge', 'Harvard University',
			'Public Health England', 'Royal Society', 'World Economic Forum',
		);
	}

	/**
	 * Deterministically (crc32-seeded) picks one entry from $pool, having
	 * removed every value in $exclude (case-insensitively) first — same
	 * algorithm as Citex_Book_Dragdrop_Parts::pick_from_pool(), duplicated
	 * here so Edited Book/Journal Article/Website can share ONE copy
	 * amongst themselves without reaching into Book's own file. Returns
	 * null only if every pool entry was excluded.
	 *
	 * @param string[] $pool
	 * @param string[] $exclude
	 * @param string   $seed_key
	 * @return string|null
	 */
	private static function pick_from_pool( array $pool, array $exclude, $seed_key ) {
		$exclude_lower = array_map( 'strtolower', array_map( 'strval', $exclude ) );
		$eligible      = array_values(
			array_filter(
				$pool,
				function ( $candidate ) use ( $exclude_lower ) {
					return ! in_array( strtolower( $candidate ), $exclude_lower, true );
				}
			)
		);
		if ( empty( $eligible ) ) {
			return null;
		}
		usort(
			$eligible,
			function ( $a, $b ) use ( $seed_key ) {
				$hash_a = crc32( $seed_key . '|' . $a );
				$hash_b = crc32( $seed_key . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);
		return $eligible[0];
	}

	/**
	 * A near-miss year distractor — rotates deterministically (seeded by
	 * $seed_key) between a nearby wrong year (+/- 1 or +/- 2, never the
	 * real year) and a transposed-digit mistake (swapping the last two
	 * digits, e.g. "2021" -> "2012") — both genuine "close but wrong"
	 * mistakes, never a wildly different value.
	 */
	private static function year_distractor( $value, $seed_key ) {
		$numeric = ctype_digit( (string) $value ) ? (int) $value : null;
		if ( null === $numeric ) {
			return $value . '?';
		}
		$flavor = abs( crc32( $seed_key . '|flavor' ) ) % 2;
		if ( 0 === $flavor ) {
			$deltas    = array( -2, -1, 1, 2 );
			$delta     = $deltas[ abs( crc32( $seed_key . '|delta' ) ) % count( $deltas ) ];
			$candidate = (string) ( $numeric + $delta );
			if ( $candidate !== (string) $value ) {
				return $candidate;
			}
		}
		$digits = str_split( (string) $value );
		$count  = count( $digits );
		if ( $count >= 2 ) {
			$transposed                 = $digits;
			$transposed[ $count - 1 ]   = $digits[ $count - 2 ];
			$transposed[ $count - 2 ]   = $digits[ $count - 1 ];
			$candidate                  = implode( '', $transposed );
			if ( $candidate !== (string) $value ) {
				return $candidate;
			}
		}
		return (string) ( $numeric + 1 );
	}

	/**
	 * A genuine title-boundary/content mistake — never a random
	 * character-level misspelling — rotating deterministically (seeded by
	 * $seed_key) between four flavours, mirroring
	 * Citex_Book_Dragdrop_Parts::title_distractor()'s identical technique:
	 * - a full stop wrongly attached to the title itself,
	 * - a comma wrongly attached the same way,
	 * - $fold_in (typically the record's own year) wrongly folded into the
	 *   title chip in parentheses, and
	 * - a subtle wording alteration (a plural/singular flip on the title's
	 *   last word).
	 */
	private static function title_like_distractor( $value, $fold_in, $seed_key ) {
		$flavor = abs( crc32( $seed_key . '|flavor' ) ) % 4;
		if ( 0 === $flavor ) {
			$candidate = $value . '.';
		} elseif ( 1 === $flavor ) {
			$candidate = $value . ',';
		} elseif ( 2 === $flavor && '' !== trim( (string) $fold_in ) ) {
			$candidate = $value . ' (' . (string) $fold_in . ')';
		} else {
			$words = preg_split( '/\s+/', trim( (string) $value ) );
			$last  = array_pop( $words );
			if ( null === $last ) {
				$last = '';
			}
			if ( '' !== $last && 's' === strtolower( substr( $last, -1 ) ) ) {
				$last = substr( $last, 0, -1 );
			} else {
				$last .= 's';
			}
			$words[]   = $last;
			$candidate = trim( implode( ' ', $words ) );
		}
		return ( '' !== $candidate && $candidate !== $value ) ? $candidate : $value . '.';
	}

	/**
	 * A near-miss page-range distractor — shifts BOTH the start and end
	 * page of a "NN-NN" range by the same small deterministic delta, so
	 * the range's own length (a real, checkable fact) stays correct and
	 * only the actual numbers are wrong — a genuine "close but wrong"
	 * mistake, never an absurd range.
	 */
	private static function page_range_distractor( $value, $seed_key ) {
		if ( 1 !== preg_match( '/^(\d+)-(\d+)$/', (string) $value, $matches ) ) {
			return $value . '?';
		}
		$start     = (int) $matches[1];
		$end       = (int) $matches[2];
		$length    = max( 1, $end - $start );
		$deltas    = array( -3, -2, -1, 1, 2, 3 );
		$delta     = $deltas[ abs( crc32( $seed_key . '|delta' ) ) % count( $deltas ) ];
		$new_start = max( 1, $start + $delta );
		$candidate = $new_start . '-' . ( $new_start + $length );
		return $candidate !== $value ? $candidate : ( $new_start + 1 ) . '-' . ( $new_start + 1 + $length );
	}

	/**
	 * A small near-miss integer distractor — used for volume/issue numbers,
	 * clamped to stay at least 1 (never zero or negative, which would never
	 * be a real volume/issue number).
	 */
	private static function small_integer_distractor( $value, $seed_key, array $deltas = array( -2, -1, 1, 2 ) ) {
		if ( ! ctype_digit( (string) $value ) ) {
			return $value . '?';
		}
		$numeric   = (int) $value;
		$delta     = $deltas[ abs( crc32( $seed_key . '|delta' ) ) % count( $deltas ) ];
		$candidate = max( 1, $numeric + $delta );
		return (string) $candidate !== (string) $value ? (string) $candidate : (string) ( $numeric + 1 );
	}

	/**
	 * A single combined "Surname, I." chip's distractor (used when a
	 * person's whole name is drawn as ONE part, e.g. Edited Book's baseline
	 * designs or Website's individual author) — rotates deterministically
	 * between:
	 * - the OTHER person's own combined name (when one exists — e.g. a
	 *   second editor not drawn this question), testing "which person does
	 *   this reference actually belong to", and
	 * - a mistake on THIS SAME person's own name: the given name spelled
	 *   out in full instead of the initial (e.g. "Smith, John" instead of
	 *   "Smith, J."), or the initial's full stop dropped ("Smith, J").
	 *
	 * @param string      $value          The correct "Surname, I." chip.
	 * @param string|null $other_combined Another real person's own "Surname, I." from the same record, or null when there is none.
	 * @param string      $full_name      This person's own full name (for the given-name flavour).
	 * @param string      $surname        This person's own surname (to isolate the given-name portion of $full_name).
	 * @param string      $seed_key
	 */
	private static function combined_person_distractor( $value, $other_combined, $full_name, $surname, $seed_key ) {
		if ( null !== $other_combined && '' !== trim( (string) $other_combined )
			&& 0 !== strcasecmp( (string) $other_combined, $value )
			&& 0 === ( abs( crc32( $seed_key . '|other' ) ) % 2 ) ) {
			return (string) $other_combined;
		}
		if ( 0 === ( abs( crc32( $seed_key . '|flavor' ) ) % 2 ) ) {
			$given = self::given_name_portion( $full_name, $surname );
			if ( '' !== $given && false === strpos( $value, $given ) ) {
				return sprintf( '%s, %s', $surname, $given );
			}
		}
		$stripped = str_replace( '.', '', $value );
		return $stripped !== $value ? $stripped : $value . "'s";
	}

	/**
	 * A split surname/initials chip's distractor (used when a person's name
	 * is drawn as two SEPARATE parts, e.g. Edited Book's
	 * editor_split_designation design) — mirrors
	 * Citex_Book_Dragdrop_Parts::author_surname_distractor()/
	 * author_initials_distractor() exactly: rotates between the OTHER
	 * person's corresponding field (when one exists) and a same-person
	 * mistake (given-name confusion for a surname, missing full stop for
	 * initials).
	 *
	 * @param string      $kind        'surname' or 'initials'.
	 * @param string      $value       The correct value.
	 * @param string|null $other_value The other person's own value for this same kind, or null.
	 * @param string      $full_name   This person's own full name (surname kind only).
	 * @param string      $seed_key
	 */
	private static function split_person_distractor( $kind, $value, $other_value, $full_name, $seed_key ) {
		if ( null !== $other_value && '' !== trim( (string) $other_value )
			&& 0 !== strcasecmp( (string) $other_value, $value )
			&& 0 === ( abs( crc32( $seed_key . '|' . $kind . '|other' ) ) % 2 ) ) {
			return (string) $other_value;
		}
		if ( 'initials' === $kind ) {
			$stripped = str_replace( '.', '', $value );
			return ( '' !== $stripped && $stripped !== $value ) ? $stripped : $value . "'";
		}
		$given = self::given_name_portion( $full_name, $value );
		return ( '' !== $given && $given !== $value ) ? $given : $value . "'s";
	}

	/**
	 * Extracts the given-name portion of a full name once its surname is
	 * known — e.g. ("Andrew Brown", "Brown") -> "Andrew" — duplicated from
	 * Citex_Book_Dragdrop_Parts's identical helper to keep this shared
	 * section self-contained.
	 */
	private static function given_name_portion( $full_name, $surname ) {
		$full_name = trim( (string) $full_name );
		$surname   = trim( (string) $surname );
		if ( '' !== $surname && '' !== $full_name && strlen( $full_name ) > strlen( $surname )
			&& 0 === strcasecmp( substr( $full_name, -strlen( $surname ) ), $surname ) ) {
			return trim( substr( $full_name, 0, strlen( $full_name ) - strlen( $surname ) ) );
		}
		$words = preg_split( '/\s+/', $full_name );
		if ( count( $words ) > 1 ) {
			array_pop( $words );
			return implode( ' ', $words );
		}
		return '' !== $full_name ? $full_name : $surname;
	}

	/**
	 * A plausible URL mistake — rotates deterministically between:
	 * - dropping the protocol scheme entirely (a very common real mistake:
	 *   "www.example.com/page" instead of the full "https://..." address),
	 * - swapping "https" for the insecure "http", and
	 * - truncating the final path segment (linking to the site's home page
	 *   instead of the actual specific page).
	 */
	private static function url_distractor( $value, $seed_key ) {
		$flavor = abs( crc32( $seed_key . '|flavor' ) ) % 3;
		if ( 0 === $flavor ) {
			$candidate = preg_replace( '#^https?://(www\.)?#i', '', (string) $value );
			if ( null !== $candidate && '' !== $candidate && $candidate !== $value ) {
				return $candidate;
			}
		}
		if ( 1 === $flavor && 0 === stripos( (string) $value, 'https://' ) ) {
			return 'http://' . substr( $value, strlen( 'https://' ) );
		}
		$trimmed    = rtrim( (string) $value, '/' );
		$scheme_end = strpos( $trimmed, '//' );
		$last_slash = strrpos( $trimmed, '/' );
		if ( false !== $scheme_end && false !== $last_slash && $last_slash > $scheme_end + 2 ) {
			$candidate = substr( $trimmed, 0, $last_slash );
			if ( '' !== $candidate && $candidate !== $value ) {
				return $candidate;
			}
		}
		return $value . '/';
	}

	/**
	 * A plausible "accessed" date mistake — rotates deterministically
	 * between two real formatting mistakes (never a wildly different date):
	 * - the American month-first order with a comma ("March 5, 2024"
	 *   instead of Harvard's "5 March 2024"), and
	 * - a numeric slash-separated date ("5/3/2024").
	 * $value is always Citex's own computed 'j F Y' date (see
	 * Citex_AI_V2::current_accessed_date()), so the parse below is never
	 * expected to fail in practice.
	 */
	private static function date_distractor( $value, $seed_key ) {
		$date = DateTime::createFromFormat( 'j F Y', (string) $value );
		if ( false === $date ) {
			return $value . '?';
		}
		$flavor = abs( crc32( $seed_key . '|flavor' ) ) % 2;
		return 0 === $flavor ? $date->format( 'F j, Y' ) : $date->format( 'j/n/Y' );
	}

	/**
	 * Website's year-or-"(n.d.)" distractor — the two real mistakes this
	 * field actually invites are assuming a date exists when the source is
	 * genuinely undated, or the reverse (assuming "n.d." when the source
	 * does have a real date): when $value is a real year, rotates between a
	 * near-miss year (year_distractor()) and the literal "n.d."; when
	 * $value is "n.d." itself, returns a plausible fabricated year instead
	 * (a small deterministic pool of recent years, never today's actual
	 * year, which would be an unfairly easy tell).
	 */
	private static function year_or_undated_distractor( $value, $seed_key ) {
		if ( 'n.d.' === $value ) {
			$years = array( '2018', '2019', '2020', '2021', '2022' );
			return $years[ abs( crc32( $seed_key . '|nd_year' ) ) % count( $years ) ];
		}
		if ( 0 === ( abs( crc32( $seed_key . '|nd_flavor' ) ) % 2 ) ) {
			return 'n.d.';
		}
		return self::year_distractor( $value, $seed_key );
	}

	/**
	 * The editor designation's distractor — rotates deterministically
	 * between the 4 real mistakes explicitly called out for this category
	 * (see build_prompt_edited_book()'s own DISTRACTORS guidance, now made
	 * deterministic instead of Gemini-authored):
	 * - the WRONG designation for this question's actual editor count
	 *   ("eds" for one editor, "ed." for two or more),
	 * - the unabbreviated word "editor" (never actually correct — Harvard
	 *   always abbreviates),
	 * - the wrong role entirely, "author", and
	 * - a punctuation mistake on the correct designation itself ("ed"
	 *   missing its full stop, or "eds." with a stray one).
	 */
	private static function designation_mistake_distractor( $value, $editor_count, $seed_key ) {
		$wrong_count_designation = $editor_count > 1 ? 'ed.' : 'eds';
		$punctuation_mistake     = 'ed.' === $value ? 'ed' : 'eds.';
		$flavors                = array( $wrong_count_designation, 'editor', 'author', $punctuation_mistake );
		$eligible                = array_values(
			array_unique(
				array_filter(
					$flavors,
					function ( $candidate ) use ( $value ) {
						return $candidate !== $value;
					}
				)
			)
		);
		if ( empty( $eligible ) ) {
			return $value . '?';
		}
		return $eligible[ abs( crc32( $seed_key . '|flavor' ) ) % count( $eligible ) ];
	}
}
