<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Harvard in-text citation DragDrop builder — category-agnostic (see
 * Citex_Intext_Citation_Rules's own docblock: the only per-category
 * variation is which field holds the "who", handled entirely by
 * Citex_AI_V2's extract_intext_people() before this class is ever
 * called). Unlike every reference-list DragDrop class (which draws N of a
 * larger pool of candidates), an in-text citation always has the SAME
 * fixed set of blanks for its form — "who" and "year" for narrative/
 * parenthetical, plus "page" for a direct quote — so this class always
 * returns every one of them as a draggable part, never a subset.
 *
 * The single most important thing this class gets right, by construction
 * rather than by a distractor: WHERE the "who" sits relative to the
 * parentheses. Narrative puts it OUTSIDE ("Who (Year) ..."); parenthetical
 * and parenthetical_quote put it INSIDE, alongside the year/page
 * ("... (Who, Year)."). A prior, hand-built version of this mechanic put
 * the author inside the parentheses for the narrative form too — the
 * fixedText TEMPLATE for each form (build_tokens()) is what prevents that
 * regression, not a runtime check.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly, same
 * as every other *_Dragdrop_Parts class.
 */
class Citex_Intext_Dragdrop_Parts {

	/**
	 * The full ordered token list for one in-text citation sentence.
	 *
	 * @param string $form  One of Citex_Intext_Citation_Rules::forms().
	 * @param string $who   The already-joined "who" text (see
	 *                      Citex_Intext_Citation_Rules::join_people_intext()/
	 *                      display_person_or_org()).
	 * @param string $year  4-digit year or literal "n.d.".
	 * @param string $clause The paraphrase clause (narrative/parenthetical only).
	 * @param string|null $page  Required for parenthetical_quote only.
	 * @param string|null $quote Required for parenthetical_quote only.
	 * @return array<{key: string|null, kind: string, value: string, literal: bool}>
	 */
	public static function build_tokens( $form, $who, $year, $clause, $page = null, $quote = null ) {
		$tokens = array();
		if ( Citex_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
			$tokens[] = array( 'key' => 'who', 'kind' => 'who', 'value' => (string) $who, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' (', 'literal' => true );
			$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => (string) $year, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ') ' . self::clean_clause( $clause ) . '.', 'literal' => true );
		} elseif ( Citex_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => self::clean_clause( $clause ) . ' (', 'literal' => true );
			$tokens[] = array( 'key' => 'who', 'kind' => 'who', 'value' => (string) $who, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
			$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => (string) $year, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ').', 'literal' => true );
		} else {
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '"' . trim( (string) $quote ) . '" (', 'literal' => true );
			$tokens[] = array( 'key' => 'who', 'kind' => 'who', 'value' => (string) $who, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
			$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => (string) $year, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', p. ', 'literal' => true );
			$tokens[] = array( 'key' => 'page', 'kind' => 'page', 'value' => (string) $page, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ').', 'literal' => true );
		}
		return $tokens;
	}

	private static function clean_clause( $clause ) {
		return rtrim( trim( (string) $clause ), '.' );
	}

	/**
	 * Builds {parts, fixedText, confusingWords} for one in-text citation
	 * question — every candidate is always drawn (there is no subset
	 * selection to make, unlike reference-list mechanics).
	 *
	 * @param string   $who      Already-joined "who" text.
	 * @param string[] $surnames The raw surname list the "who" was joined
	 *                           from (a 1-element array holding a Website
	 *                           organisation/individual name counts too) —
	 *                           used only to build the "who" distractor,
	 *                           since a wrong join needs to know the real
	 *                           person count, not just the final string.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
	 */
	public static function build( $form, $who, array $surnames, $year, $clause, $page = null, $quote = null ) {
		$tokens      = self::build_tokens( $form, $who, $year, $clause, $page, $quote );
		$token_count = count( $tokens );
		$record_seed = implode( '|', array( $form, $who, (string) $year, (string) $page ) );

		$parts     = array();
		$confusing = array();
		$fixed     = '';
		foreach ( $tokens as $index => $token ) {
			if ( $token['literal'] ) {
				$fixed .= $token['value'];
				continue;
			}
			$is_first = '' === trim( $fixed );
			$is_last  = ( $index === $token_count - 1 );
			$fixed   .= ( $is_first || $is_last ) ? '|' : '||';
			$parts[]  = (string) $token['value'];
			$confusing[] = self::distractor_for( $token['kind'], $token['value'], $surnames, $who, $record_seed );
		}
		if ( empty( $parts ) ) {
			return null;
		}
		return array( 'parts' => $parts, 'fixedText' => $fixed, 'confusingWords' => $confusing );
	}

	private static function distractor_for( $kind, $value, array $surnames, $who, $record_seed ) {
		switch ( $kind ) {
			case 'who':
				return Citex_Intext_Citation_Rules::wrong_join( $surnames, $who );
			case 'year':
				return Citex_Reference_Rules::year_distractor( (string) $value, $record_seed . '|year' );
			case 'page':
				// Reuses year_distractor()'s generic "mutate a digit
				// string" logic — a page number is structurally the same
				// shape (a short numeral) as a year, so the same
				// deterministic delta/transposition mistakes apply.
				return Citex_Reference_Rules::year_distractor( (string) $value, $record_seed . '|page' );
		}
		return $value . '?';
	}
}
