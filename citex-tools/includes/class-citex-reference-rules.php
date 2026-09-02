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
	 * The single, correctly-formatted Harvard reference string for this
	 * category — the same string DragDrop reconstructs from its pieces and
	 * MCQ places as its correct option.
	 *
	 * @param string $category
	 * @param array  $fields Book: {surname, initials, year, title, place, publisher}.
	 *               Edited Book: {editors: array<{surname, initials}>, year, title, place, publisher}.
	 */
	public static function build_reference( $category, array $fields ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			return self::build_edited_book_reference( $fields );
		}
		return self::build_book_reference( $fields );
	}

	private static function build_book_reference( array $fields ) {
		return sprintf(
			'%s, %s (%s) %s. %s: %s.',
			$fields['surname'],
			$fields['initials'],
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
			self::join_editors( $editors ),
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
	 * "Smith, J." for one editor; "Smith, J. and Jones, A." for two;
	 * "Smith, J., Jones, A. and Lee, K." for three or more (Harvard's
	 * standard comma-separated-with-a-final-"and" list joining).
	 */
	public static function join_editors( array $editors ) {
		$parts = array();
		foreach ( $editors as $editor ) {
			$parts[] = sprintf( '%s, %s', $editor['surname'], $editor['initials'] );
		}
		if ( 1 === count( $parts ) ) {
			return $parts[0];
		}
		$last = array_pop( $parts );
		return implode( ', ', $parts ) . ' and ' . $last;
	}

	/**
	 * The DragDrop shape for this category: the ordered draggable Question
	 * Parts, and the Fixed Text template (Citex's established |/|| pipe
	 * grammar — see class-citex-populator.php's docblock) that the parts
	 * slot into. Editor(s), designation, year and title are draggable;
	 * place and publisher are baked into the fixed template directly, from
	 * the record, exactly like Book already does — this plugin has never
	 * made place/publisher draggable for any category.
	 *
	 * @return array{parts: string[], fixedText: string}
	 */
	public static function dragdrop_shape( $category, array $fields ) {
		if ( self::CATEGORY_EDITED_BOOK === $category ) {
			$editors     = $fields['editors'];
			$designation = self::designation_for_editor_count( count( $editors ) );
			return array(
				'parts'     => array( self::join_editors( $editors ), $designation, $fields['year'], $fields['title'] ),
				'fixedText' => sprintf( '| (||) (||) ||. %s: %s.', $fields['place'], $fields['publisher'] ),
			);
		}
		return array(
			'parts'     => array( $fields['surname'], $fields['initials'], $fields['year'], $fields['title'] ),
			'fixedText' => sprintf( '|, || (||) ||. %s: %s.', $fields['place'], $fields['publisher'] ),
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
		return '/^[^,]+,\s+(?:[A-Z]\.\s*)+\(\d{4}\)\s+.+\.\s+[^:]+:\s+.+\.\s*$/u';
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
		);
	}
}
