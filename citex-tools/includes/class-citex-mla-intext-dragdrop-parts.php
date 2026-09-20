<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MLA in-text citation DragDrop builder — mirrors
 * Citex_Intext_Dragdrop_Parts's own "always draw every blank, never a
 * subset" model exactly, but for MLA's genuinely different rule (see
 * Citex_MLA_Intext_Citation_Rules's own docblock): no year at all, and no
 * comma between the surname(s) and the page in the quote form.
 *
 * Category-agnostic, like every other in-text class — the only
 * per-category variation is which field holds the "who", resolved before
 * this class is ever called.
 *
 * A narrative/parenthetical MLA citation with no page at all has only ONE
 * draggable blank ("who") — thinner than every other DragDrop mechanic in
 * this codebase, which always has 2-3. Citex_AI_V2 always supplies a page
 * for Book/Edited Book/Journal Article DragDrop questions (MLA style
 * genuinely does prefer a page reference whenever one exists, unlike
 * Harvard, which reserves it for direct quotes only), so this thin shape
 * is only ever actually reached for Website, which has no page concept
 * at all — an accepted, documented edge case.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_MLA_Intext_Dragdrop_Parts {

	/**
	 * The full ordered token list for one MLA in-text citation sentence.
	 *
	 * @param string      $form   One of Citex_MLA_Intext_Citation_Rules::forms().
	 * @param string      $who    The already-joined "who" text.
	 * @param string      $clause The paraphrase clause (narrative/parenthetical only).
	 * @param string|null $page   Page reference — optional for narrative/
	 *                            parenthetical, required for parenthetical_quote.
	 * @param string|null $quote  Required for parenthetical_quote only.
	 * @return array<{key: string|null, kind: string, value: string, literal: bool}>
	 */
	public static function build_tokens( $form, $who, $clause, $page = null, $quote = null ) {
		$tokens = array();
		if ( Citex_MLA_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
			$tokens[] = array( 'key' => 'who', 'kind' => 'who', 'value' => (string) $who, 'literal' => false );
			$has_page = null !== $page && '' !== trim( (string) $page );
			if ( $has_page ) {
				$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ' . self::clean_clause( $clause ) . ' (', 'literal' => true );
				$tokens[] = array( 'key' => 'page', 'kind' => 'page', 'value' => (string) $page, 'literal' => false );
				$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ').', 'literal' => true );
			} else {
				$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ' . self::clean_clause( $clause ) . '.', 'literal' => true );
			}
		} elseif ( Citex_MLA_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => self::clean_clause( $clause ) . ' (', 'literal' => true );
			$tokens[] = array( 'key' => 'who', 'kind' => 'who', 'value' => (string) $who, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ').', 'literal' => true );
		} else {
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '"' . trim( (string) $quote ) . '" (', 'literal' => true );
			$tokens[] = array( 'key' => 'who', 'kind' => 'who', 'value' => (string) $who, 'literal' => false );
			// A single space, never a comma — the single most-tested
			// MLA-vs-Harvard distractor (a comma here is the Harvard
			// mistake).
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ', 'literal' => true );
			$tokens[] = array( 'key' => 'page', 'kind' => 'page', 'value' => (string) $page, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ').', 'literal' => true );
		}
		return $tokens;
	}

	private static function clean_clause( $clause ) {
		return rtrim( trim( (string) $clause ), '.' );
	}

	/**
	 * Builds {parts, fixedText, confusingWords} for one MLA in-text
	 * citation question — every candidate is always drawn.
	 *
	 * @param string   $who      Already-joined "who" text.
	 * @param string[] $surnames The raw surname (or Website name) list the
	 *                           "who" was joined from — used only to build
	 *                           the "who" distractor.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
	 */
	public static function build( $form, $who, array $surnames, $clause, $page = null, $quote = null ) {
		$tokens      = self::build_tokens( $form, $who, $clause, $page, $quote );
		$token_count = count( $tokens );
		$record_seed = implode( '|', array( $form, $who, (string) $page ) );

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
				return Citex_MLA_Intext_Citation_Rules::wrong_join( $surnames, $who );
			case 'page':
				// Reuses Citex_Reference_Rules::year_distractor()'s
				// generic "mutate a digit string" logic — a page number
				// is structurally the same shape as a year.
				return Citex_Reference_Rules::year_distractor( (string) $value, $record_seed . '|page' );
		}
		return $value . '?';
	}
}
