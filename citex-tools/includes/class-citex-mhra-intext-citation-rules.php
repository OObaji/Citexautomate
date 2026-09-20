<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MHRA IN-TEXT citation rules — a genuinely separate sibling class (never a
 * retrofit of Citex_Intext_Citation_Rules), even though its actual output
 * shape happens to coincide with Harvard's own in-text convention exactly
 * (comma-separated author/year, "p." prefix on a page reference, "et al."
 * from four authors onward). This app has no footnote mechanic anywhere
 * (see Citex_MHRA_Reference_Rules's own docblock — real MHRA is primarily a
 * footnote/endnote system), so MHRA's In-Text Citation targets the same
 * Author-Date shape Harvard/APA/Chicago all use for the same reason — this
 * was a deliberate content decision (not assumed), matching how Chicago's
 * own Author-Date choice was made for the identical architectural reason.
 *
 * Category-agnostic, like every other in-text class: the only per-category
 * variation (which field holds the "who") lives in Citex_AI_V2's own
 * extract_intext_people() helper, never here.
 *
 * Three citation forms, matching the three ways a source is actually
 * worked into a sentence:
 * - narrative: "Who (Year) argues that ..." — the citation reads as part
 *   of the sentence's own grammar, used for a paraphrase.
 * - parenthetical: "... pillars (Who, Year)." — the whole citation sits
 *   in parentheses at the end of a paraphrased sentence.
 * - parenthetical_quote: '"..." (Who, Year, p. X).' — a direct
 *   quotation, which always needs a page reference.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly, same
 * as every other *_Intext_Citation_Rules class.
 */
class Citex_MHRA_Intext_Citation_Rules {

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
	 * four or more.
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
	 * list — mirrors Citex_Intext_Citation_Rules::wrong_join() exactly.
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
	 * The single individual-or-organisation "who" for a Website source.
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
