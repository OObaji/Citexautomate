<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chicago (Author-Date) IN-TEXT citation rules — genuinely distinct from
 * Citex_Intext_Citation_Rules/Citex_APA_Intext_Citation_Rules's own comma-
 * before-year shape. Category-agnostic, mirroring every sibling in-text
 * class's own shape exactly: the only per-category variation (which field
 * holds the "who") lives in Citex_AI_V2's own extract_intext_people()
 * helper, never here.
 *
 * The one genuinely Chicago-distinctive rule: real Chicago Author-Date
 * in-text citations use NO comma between the author and the year —
 * "(Smith 2020)", never "(Smith, 2020)" — a comma only ever appears
 * immediately before a page number, "(Smith 2020, 45)", and even then
 * Chicago never prefixes that page number with "p." (unlike Harvard/APA/
 * MHRA, which all use "p."). Author joining ("and" for 2, comma-separated
 * with a final "and" for 3, "et al." from 4+) and the narrative form's own
 * shape ("Who (Year) ...") are otherwise identical to Harvard's own
 * in-text convention.
 *
 * Three citation forms, matching the three ways a source is actually
 * worked into a sentence:
 * - narrative: "Who (Year) argues that ..." — the author reads as part of
 *   the sentence's own grammar, only the year sits in parentheses.
 * - parenthetical: "... pillars (Who Year)." — the whole citation sits in
 *   parentheses at the end of a paraphrased sentence, NO comma before the
 *   year.
 * - parenthetical_quote: '"..." (Who Year, Page).' — a direct quotation,
 *   which always needs a page reference; the comma sits before the page
 *   only, and there is no "p." prefix at all.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly, same
 * as every other *_Intext_Citation_Rules class.
 */
class Citex_Chicago_Intext_Citation_Rules {

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
	 * four or more — the SAME threshold as Harvard's own in-text rule.
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
	 * list — mirrors Citex_Intext_Citation_Rules::wrong_join() exactly
	 * (same thresholds, since the joining rule itself is identical to
	 * Harvard's own — only the year/page punctuation differs for Chicago).
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
	 * "Who (Year)" — the graded fragment for the narrative form. Identical
	 * to Harvard's own shape — the narrative form never had a comma at all.
	 */
	public static function narrative_fragment( $who, $year ) {
		return sprintf( '%s (%s)', $who, $year );
	}

	/**
	 * "(Who Year)" — the graded fragment for the parenthetical form. NO
	 * comma between $who and $year — the single most Chicago-distinctive
	 * in-text rule (Harvard/APA/MHRA all use a comma here).
	 */
	public static function parenthetical_fragment( $who, $year ) {
		return sprintf( '(%s %s)', $who, $year );
	}

	/**
	 * "(Who Year, Page)" — the graded fragment for a direct quote. The
	 * comma sits ONLY before the page, and there is no "p." prefix at all
	 * (unlike Harvard/APA/MHRA, which all write "p. Page").
	 */
	public static function parenthetical_quote_fragment( $who, $year, $page ) {
		return sprintf( '(%s %s, %s)', $who, $year, $page );
	}

	/**
	 * The full narrative sentence: "Who (Year) {clause}."
	 */
	public static function narrative_sentence( $who, $year, $clause ) {
		return sprintf( '%s %s.', self::narrative_fragment( $who, $year ), self::clean_clause( $clause ) );
	}

	/**
	 * The full parenthetical sentence: "{clause} (Who Year)."
	 */
	public static function parenthetical_sentence( $who, $year, $clause ) {
		return sprintf( '%s %s.', self::clean_clause( $clause ), self::parenthetical_fragment( $who, $year ) );
	}

	/**
	 * The full direct-quote sentence: '"Quote" (Who Year, Page).'
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
			return 'A direct quotation always needs a page reference, with NO "p." before the page number — Chicago never uses "p." in-text — and a comma sits only between the year and the page, never between the surname and the year.';
		}
		return 'The whole citation sits in parentheses at the end of the sentence, with NO comma between the surname and the year — Chicago author-date never puts a comma there — and "et al." is used only once there are four or more authors.';
	}

	public static function identify_error_hint( $form ) {
		return 'Work through the citation piece by piece: whether the surname sits in the right place relative to the parentheses, whether a comma has been wrongly added between the surname and the year (Chicago never has one there), whether "et al." is used at the right author count, and whether a page reference wrongly carries a "p." prefix (Chicago never uses one).';
	}
}
