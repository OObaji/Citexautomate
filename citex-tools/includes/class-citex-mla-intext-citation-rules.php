<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MLA in-text citation rules — author-page, with NO year at all in-text
 * (the single biggest structural difference from Harvard's own
 * author-year in-text convention — see Citex_Intext_Citation_Rules).
 * Category-agnostic, mirroring that class's own shape exactly: the only
 * per-category variation (which field holds the "who") lives in
 * Citex_AI_V2's extract_intext_people() helper, never here.
 *
 * Three citation forms, kept structurally parallel to Harvard's own three
 * (even though real MLA style is slightly more flexible about including a
 * page for a paraphrase too) so both styles share one prompt/normaliser
 * shape:
 * - narrative: "Who {clause} (Page)." — the author is named in the
 *   sentence itself; the page (when known) sits in its own parentheses at
 *   the end. No page at all (an unpaginated source): "Who {clause}."
 * - parenthetical: "{clause} (Who)." — an unpaginated paraphrase; the
 *   whole citation is just the surname(s) in parentheses.
 * - parenthetical_quote: '"..." (Who Page).' — a direct quotation, which
 *   always needs a page. Crucially, NO comma between the surname(s) and
 *   the page — the single most-tested MLA-vs-Harvard distractor (a comma
 *   here is the Harvard mistake).
 *
 * Person-list joining uses the SAME "et al. at 3+" threshold as MLA's own
 * reference-list rule (Citex_MLA_Reference_Rules::join_people()) — unlike
 * Harvard, which splits its own reference-list (never) and in-text (4+)
 * thresholds, MLA is consistent: 3+ triggers "et al." everywhere.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_MLA_Intext_Citation_Rules {

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
	 * "Surname" for one person; "Surname1 and Surname2" for two (no comma
	 * before "and" — MLA's reference-list-only comma-before-"and" quirk
	 * does not apply in-text); "Surname1 et al." for three or more.
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
		if ( $count >= 3 ) {
			return (string) $people[0]['surname'] . ' et al.';
		}
		return (string) $people[0]['surname'] . ' and ' . (string) $people[1]['surname'];
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
	 * The full narrative sentence: "Who {clause} (Page)." — or, when no
	 * page exists at all (an unpaginated source), "Who {clause}." with no
	 * trailing parenthetical whatsoever.
	 */
	public static function narrative_sentence( $who, $clause, $page = null ) {
		$clean = self::clean_clause( $clause );
		if ( null !== $page && '' !== trim( (string) $page ) ) {
			return sprintf( '%s %s (%s).', $who, $clean, $page );
		}
		return sprintf( '%s %s.', $who, $clean );
	}

	/**
	 * "(Who)" — the graded fragment for an unpaginated parenthetical
	 * citation.
	 */
	public static function parenthetical_fragment( $who ) {
		return sprintf( '(%s)', $who );
	}

	/**
	 * The full unpaginated parenthetical sentence: "{clause} (Who)."
	 */
	public static function parenthetical_sentence( $who, $clause ) {
		return sprintf( '%s %s.', self::clean_clause( $clause ), self::parenthetical_fragment( $who ) );
	}

	/**
	 * "(Who Page)" — NO comma between the surname(s) and the page. This
	 * is the graded fragment for a direct quote.
	 */
	public static function parenthetical_quote_fragment( $who, $page ) {
		return sprintf( '(%s %s)', $who, $page );
	}

	/**
	 * The full direct-quote sentence: '"Quote" (Who Page).'
	 */
	public static function parenthetical_quote_sentence( $who, $page, $quote ) {
		return sprintf( '"%s" %s.', trim( (string) $quote ), self::parenthetical_quote_fragment( $who, $page ) );
	}

	private static function clean_clause( $clause ) {
		return rtrim( trim( (string) $clause ), '.' );
	}

	/**
	 * The single deterministic "wrong join" mistake for a given person
	 * list — shared by Citex_MLA_Intext_Dragdrop_Parts and
	 * Citex_MLA_Intext_Mcq_Variants, mirroring
	 * Citex_Intext_Citation_Rules::wrong_join()'s own role but with
	 * MLA-specific mistakes:
	 * - 1 person (or a Website individual/organisation): "et al." wrongly
	 *   applied to a single source.
	 * - 2 people: a comma wrongly inserted before "and" — MLA's own
	 *   reference-list rule (which DOES use that comma) bleeding
	 *   incorrectly into the in-text citation.
	 * - 3+ people: every person listed in full instead of "et al." — the
	 *   same Harvard-style "list everyone" mistake Harvard's own in-text
	 *   class tests.
	 */
	public static function wrong_join( array $surnames, $who ) {
		$count = count( $surnames );
		if ( $count <= 1 ) {
			return $who . ' et al.';
		}
		if ( 2 === $count ) {
			return $surnames[0] . ', and ' . $surnames[1];
		}
		$copy = $surnames;
		$last = array_pop( $copy );
		return implode( ', ', $copy ) . ' and ' . $last;
	}

	public static function mcq_question_stem( $form ) {
		if ( self::FORM_NARRATIVE === $form ) {
			return 'Which of the following uses the correct MLA format for a narrative in-text citation?';
		}
		if ( self::FORM_PARENTHETICAL_QUOTE === $form ) {
			return 'Which of the following uses the correct MLA format for an in-text citation of a direct quotation?';
		}
		return 'Which of the following uses the correct MLA format for an in-text citation?';
	}

	public static function mcq_hint( $form ) {
		if ( self::FORM_NARRATIVE === $form ) {
			return 'MLA in-text citations never include the year — only the author\'s surname, with the page number (if any) in its own parentheses at the end, and "et al." only once there are three or more authors.';
		}
		if ( self::FORM_PARENTHETICAL_QUOTE === $form ) {
			return 'MLA never includes the year in-text, and there is no comma between the surname(s) and the page number — unlike Harvard, which does use one.';
		}
		return 'MLA in-text citations never include the year — just the surname(s) in parentheses, with "et al." only once there are three or more authors.';
	}

	public static function identify_error_hint( $form ) {
		return 'Work through the citation piece by piece: whether a year has been wrongly included at all, whether "et al." is used at the right author count, and whether a comma has been wrongly inserted where MLA never uses one.';
	}
}
