<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * APA (7th edition) IN-TEXT citation rules — author-date, WITH the year
 * (like Harvard's own in-text convention — see Citex_Intext_Citation_Rules —
 * unlike MLA's author-page, no-year-at-all convention). Category-agnostic,
 * mirroring Citex_Intext_Citation_Rules/Citex_MLA_Intext_Citation_Rules's
 * own shape exactly: the only per-category variation (which field holds the
 * "who") lives in Citex_AI_V2's own extract_intext_people() helper, never
 * here.
 *
 * Two genuinely APA-distinctive rules, both real APA 7 conventions:
 * - The joining word between two authors DIFFERS by citation form — "&" in
 *   a PARENTHETICAL citation, but the spelled-out "and" in a NARRATIVE
 *   citation (e.g. "(Smith & Jones, 2020)" but "Smith and Jones (2020)
 *   argue..."). Neither Harvard nor MLA has this form-dependent split —
 *   both use the same joiner everywhere.
 * - "et al." applies from THREE OR MORE authors onward (APA 7 simplified
 *   this from APA 6's more complex first-citation-vs-subsequent rule) —
 *   the SAME 3+ threshold as MLA's own in-text rule, but genuinely
 *   different from Harvard's own 4+ threshold.
 *
 * Three citation forms, matching Harvard's/MLA's own three:
 * - narrative: "Who (Year) argues that ..." — the author is named as part
 *   of the sentence itself, only the year sits in parentheses; the
 *   multi-author joiner is "and".
 * - parenthetical: "... pillars (Who, Year)." — the whole citation sits in
 *   parentheses at the end of a paraphrased sentence; the multi-author
 *   joiner is "&".
 * - parenthetical_quote: '"..." (Who, Year, p. X).' — a direct quotation,
 *   which always needs a page reference; the joiner is "&", same as the
 *   plain parenthetical form (both are parenthetical citations).
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly, same as
 * Citex_Intext_Citation_Rules/Citex_MLA_Intext_Citation_Rules.
 */
class Citex_APA_Intext_Citation_Rules {

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
	 * The correct joining word for a given citation form — "and" for
	 * narrative, "&" for both parenthetical forms. This is the single most
	 * APA-distinctive in-text rule: real APA 7 genuinely uses a different
	 * word depending on whether the citation sits inside or outside the
	 * sentence's own grammar.
	 */
	public static function joiner_for_form( $form ) {
		return self::FORM_NARRATIVE === $form ? 'and' : '&';
	}

	/**
	 * "Surname" for one person; "Surname1 {joiner} Surname2" for two;
	 * "Surname1 et al." for three or more — the joiner itself is passed in
	 * (see joiner_for_form()), since it depends on which citation FORM is
	 * being built, not on the person count.
	 *
	 * @param array  $people array<{surname}>, 1 or more.
	 * @param string $joiner "and" or "&" — see joiner_for_form().
	 */
	public static function join_people_intext( array $people, $joiner = 'and' ) {
		$count = count( $people );
		if ( 0 === $count ) {
			return '';
		}
		if ( 1 === $count ) {
			return (string) $people[0]['surname'];
		}
		if ( $count >= 3 ) {
			return (string) $people[0]['surname'] . ' et al.';
		}
		return sprintf( '%s %s %s', $people[0]['surname'], $joiner, $people[1]['surname'] );
	}

	/**
	 * The single individual-or-organisation "who" for a Website source —
	 * mirrors Citex_Intext_Citation_Rules::display_person_or_org() exactly.
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
	 * The single deterministic "wrong join" mistake for a given person
	 * list and its correctly-joined "who" — shared by
	 * Citex_APA_Intext_Dragdrop_Parts and Citex_APA_Intext_Mcq_Variants,
	 * mirroring wrong_join()'s role in the sibling classes:
	 * - 1 person (or a Website individual/organisation): "et al." wrongly
	 *   applied to a single source.
	 * - 2 people: the joiner WRONG FOR THIS FORM — "&" swapped in for a
	 *   narrative citation's "and", or "and" swapped in for a parenthetical
	 *   citation's "&" — the single most APA-distinctive mistake.
	 * - 3+ people: every person listed in full instead of "et al." — the
	 *   same Harvard-style "list everyone" mistake the sibling classes test.
	 *
	 * @param string[] $surnames The raw surname (or Website name) list.
	 * @param string   $who      The correctly-joined "who" text.
	 */
	public static function wrong_join( array $surnames, $who ) {
		$count = count( $surnames );
		if ( $count <= 1 ) {
			return $who . ' et al.';
		}
		if ( 2 === $count ) {
			if ( false !== strpos( $who, ' & ' ) ) {
				return str_replace( ' & ', ' and ', $who );
			}
			return str_replace( ' and ', ' & ', $who );
		}
		$copy = $surnames;
		$last = array_pop( $copy );
		return implode( ', ', $copy ) . ' and ' . $last;
	}

	/**
	 * "Who (Year)" — the graded fragment for the narrative form. The "who"
	 * passed in must already be joined with joiner_for_form(FORM_NARRATIVE)
	 * ("and") — this method does not itself apply a joiner.
	 */
	public static function narrative_fragment( $who, $year ) {
		return sprintf( '%s (%s)', $who, $year );
	}

	/**
	 * "(Who, Year)" — the graded fragment for the parenthetical form. The
	 * "who" passed in must already be joined with
	 * joiner_for_form(FORM_PARENTHETICAL) ("&").
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
	 * The full narrative sentence: "Who (Year) {clause}."
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
			return 'Which of the following uses the correct APA format for a narrative in-text citation?';
		}
		if ( self::FORM_PARENTHETICAL_QUOTE === $form ) {
			return 'Which of the following uses the correct APA format for an in-text citation of a direct quotation?';
		}
		return 'Which of the following uses the correct APA format for an in-text citation?';
	}

	public static function mcq_hint( $form ) {
		if ( self::FORM_NARRATIVE === $form ) {
			return 'The author\'s surname sits outside the parentheses, with only the year inside them, two authors are joined with "and" (never "&") in a narrative citation, and "et al." is used once there are three or more authors.';
		}
		if ( self::FORM_PARENTHETICAL_QUOTE === $form ) {
			return 'A direct quotation always needs a page reference ("p." before the page number), two authors are joined with "&" (never "and") inside the parentheses, and "et al." is used once there are three or more authors.';
		}
		return 'The whole citation sits in parentheses at the end of the sentence, two authors are joined with "&" (never "and") inside the parentheses, and "et al." is used once there are three or more authors.';
	}

	public static function identify_error_hint( $form ) {
		return 'Work through the citation piece by piece: whether the surname sits in the right place relative to the parentheses, whether "&" or "and" is used correctly for this citation form, whether "et al." is used at the right author count (three or more), and whether the punctuation (commas, "p.") matches the form shown.';
	}
}
