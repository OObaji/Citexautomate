<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Harvard IN-TEXT citation rules — genuinely distinct from
 * Citex_Reference_Rules' own reference-LIST rules (see that class's own
 * docblock: "et al." is Liverpool Hope's IN-TEXT CITATION convention,
 * never used in a reference-list entry). Category-agnostic: the only
 * per-category variation (which field holds the "who", what the scenario
 * calls the source) lives in Citex_AI_V2's own extract_intext_people()
 * helper, never here — Book authors, Edited Book editors and Journal
 * Article authors are all just "a list of people" to this class, and
 * Website's single individual-or-organisation author is handled via
 * display_person_or_org() instead of join_people_intext().
 *
 * Three citation forms, matching the three ways a source is actually
 * worked into a sentence:
 * - narrative: "Who (Year) argues that ..." — the citation reads as part
 *   of the sentence's own grammar, used for a paraphrase.
 * - parenthetical: "... pillars (Who, Year)." — the whole citation sits
 *   in parentheses at the end of a paraphrased sentence.
 * - parenthetical_quote: '"..." (Who, Year, p. X).' — a direct
 *   quotation, which always needs a page reference. A page RANGE is
 *   deliberately out of scope for this mechanic (kept to a single page)
 *   — the "p." vs "pp." distinction is tested as an MCQ-only distractor
 *   instead of adding range support end-to-end for comparatively little
 *   pedagogical value.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly, same
 * as Citex_Reference_Rules/Citex_MLA_Reference_Rules.
 */
class Citex_Intext_Citation_Rules {

	const FORM_NARRATIVE           = 'narrative';
	const FORM_PARENTHETICAL       = 'parenthetical';
	const FORM_PARENTHETICAL_QUOTE = 'parenthetical_quote';

	/**
	 * @return string[]
	 */
	public static function forms() {
		return array( self::FORM_NARRATIVE, self::FORM_PARENTHETICAL, self::FORM_PARENTHETICAL_QUOTE );
	}

	/**
	 * "Surname" for one person; "Surname1 and Surname2" for two;
	 * "Surname1, Surname2 and Surname3" for three; "Surname1 et al." for
	 * four or more — the in-text-only "et al." threshold, distinct from
	 * Citex_Reference_Rules::join_people()'s own reference-list rule
	 * (which never abbreviates, at any count).
	 *
	 * @param array $people array<{surname}>, 1 or more.
	 */
	public static function join_people_intext( array $people ) {
		$count = count( $people );
		if ( 0 === $count ) {
			return '';
		}
		if ( 1 === $count ) {
			return (string) $people[0]['surname'];
		}
		if ( $count >= 4 ) {
			return (string) $people[0]['surname'] . ' et al.';
		}
		$surnames = array_map(
			function ( $person ) {
				return (string) $person['surname'];
			},
			$people
		);
		$last = array_pop( $surnames );
		return implode( ', ', $surnames ) . ' and ' . $last;
	}

	/**
	 * The single deterministic "wrong join" mistake for a given person
	 * list — shared by Citex_Intext_Dragdrop_Parts and
	 * Citex_Intext_Mcq_Variants so both mechanics test the exact same
	 * mistake shape. Which mistake is meaningful depends on the real
	 * person COUNT, not the already-joined string:
	 * - 1 person (or a Website individual/organisation): "et al." wrongly
	 *   applied to a single source.
	 * - 2-3 people: "&" instead of "and" (the same "and never &" rule
	 *   every other category's join tests).
	 * - 4+ people: every person listed in full instead of "et al." — the
	 *   classic reference-list-style mistake wrongly applied in-text.
	 *
	 * @param string[] $surnames The raw surname (or Website name) list.
	 * @param string   $who      The correctly-joined "who" text.
	 */
	public static function wrong_join( array $surnames, $who ) {
		$count = count( $surnames );
		if ( $count <= 1 ) {
			return $who . ' et al.';
		}
		if ( $count <= 3 ) {
			return str_replace( ' and ', ' & ', $who );
		}
		$copy = $surnames;
		$last = array_pop( $copy );
		return implode( ', ', $copy ) . ' and ' . $last;
	}

	/**
	 * The single individual-or-organisation "who" for a Website source —
	 * mirrors Citex_Reference_Rules::format_website_author()'s own
	 * type-dispatch, but returns just the surname (never "Surname, I.")
	 * since an in-text citation never shows initials at all.
	 *
	 * @param array $author {type: 'individual'|'organisation', surname, name}.
	 */
	public static function display_person_or_org( array $author ) {
		if ( 'organisation' === ( $author['type'] ?? '' ) ) {
			return (string) ( $author['name'] ?? '' );
		}
		return (string) ( $author['surname'] ?? '' );
	}

	/**
	 * "Who (Year)" — the graded fragment for the narrative form.
	 */
	public static function narrative_fragment( $who, $year ) {
		return sprintf( '%s (%s)', $who, $year );
	}

	/**
	 * "(Who, Year)" — the graded fragment for the parenthetical form.
	 */
	public static function parenthetical_fragment( $who, $year ) {
		return sprintf( '(%s, %s)', $who, $year );
	}

	/**
	 * "(Who, Year, p. Page)" — the graded fragment for a direct quote.
	 */
	public static function parenthetical_quote_fragment( $who, $year, $page ) {
		return sprintf( '(%s, %s, p. %s)', $who, $year, $page );
	}

	/**
	 * The full narrative sentence: "Who (Year) {clause}." — $clause is the
	 * Citex/Gemini-authored paraphrase clause (e.g. "argues that a
	 * comprehensive model must integrate the three pillars"), supplied
	 * with no leading/trailing punctuation of its own.
	 */
	public static function narrative_sentence( $who, $year, $clause ) {
		return sprintf( '%s %s.', self::narrative_fragment( $who, $year ), self::clean_clause( $clause ) );
	}

	/**
	 * The full parenthetical sentence: "{clause} (Who, Year)."
	 */
	public static function parenthetical_sentence( $who, $year, $clause ) {
		return sprintf( '%s %s.', self::clean_clause( $clause ), self::parenthetical_fragment( $who, $year ) );
	}

	/**
	 * The full direct-quote sentence: '"Quote" (Who, Year, p. Page).'
	 */
	public static function parenthetical_quote_sentence( $who, $year, $page, $quote ) {
		return sprintf( '"%s" %s.', trim( (string) $quote ), self::parenthetical_quote_fragment( $who, $year, $page ) );
	}

	private static function clean_clause( $clause ) {
		return rtrim( trim( (string) $clause ), '.' );
	}

	public static function mcq_question_stem( $form ) {
		if ( self::FORM_NARRATIVE === $form ) {
			return 'Which of the following uses the correct format for a narrative in-text citation?';
		}
		if ( self::FORM_PARENTHETICAL_QUOTE === $form ) {
			return 'Which of the following uses the correct format for an in-text citation of a direct quotation?';
		}
		return 'Which of the following uses the correct format for an in-text citation?';
	}

	public static function mcq_hint( $form ) {
		if ( self::FORM_NARRATIVE === $form ) {
			return 'The author\'s surname sits outside the parentheses, with only the year inside them, and "et al." is only used once there are four or more authors.';
		}
		if ( self::FORM_PARENTHETICAL_QUOTE === $form ) {
			return 'A direct quotation always needs a page reference ("p." before the page number), with a comma separating the surname, the year and the page.';
		}
		return 'The whole citation sits in parentheses at the end of the sentence, with a comma between the surname and the year, and "et al." only once there are four or more authors.';
	}

	public static function identify_error_hint( $form ) {
		return 'Work through the citation piece by piece: whether the surname sits in the right place relative to the parentheses, whether "et al." is used at the right author count, and whether the punctuation (commas, "p.") matches the form shown.';
	}
}
