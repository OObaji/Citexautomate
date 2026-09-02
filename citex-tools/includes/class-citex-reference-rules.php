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

	const CATEGORY_BOOK        = 'Book';
	const CATEGORY_EDITED_BOOK = 'Edited Book';

	public static function categories() {
		return array( self::CATEGORY_BOOK, self::CATEGORY_EDITED_BOOK );
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
	 */
	public static function build_reference( $category, array $fields ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return self::build_edited_book_reference( $fields );
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
	public static function dragdrop_shape( $category, array $fields ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			$editors     = $fields['editors'];
			$designation = self::designation_for_editor_count( count( $editors ) );
			return array(
				'parts'     => array( self::join_people( $editors ), $designation, $fields['year'], $fields['title'] ),
				'fixedText' => sprintf( '| (||) (||) ||. %s: %s.', $fields['place'], $fields['publisher'] ),
			);
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
	 * The overall-shape regex used to confirm a completed reference string
	 * (DragDrop's reconstruction, or MCQ's correct option) actually looks
	 * like this category's Harvard format — the category-specific
	 * counterpart to the shared punctuation/spacing checks in
	 * Citex_Generated_Validator::validate_reference_format(), which apply
	 * to every category identically.
	 */
	public static function format_regex( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			// Surname(s), Initials [and Surname, Initials ...] (ed.|eds) (Year) Title. Place: Publisher.
			return '/^.+\s+\((?:ed\.|eds)\)\s+\(\d{4}\)\s+.+\.\s+[^:]+:\s+.+\.\s*$/u';
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
	public static function mcq_question_stem( $category ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return 'Which of the following is the correct Harvard reference for an edited book?';
		}
		return 'Which of the following is the correct Harvard reference for a book?';
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
}
