<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MHRA in-text citation DragDrop builder — structurally identical to
 * Citex_Intext_Dragdrop_Parts (see Citex_MHRA_Intext_Citation_Rules's own
 * docblock for why MHRA's In-Text Citation deliberately targets the same
 * Author-Date shape Harvard already uses), but its own genuinely separate
 * sibling class throughout, via Citex_MHRA_Intext_Citation_Rules.
 *
 * Category-agnostic, like every other in-text class — the only
 * per-category variation is which field holds the "who", resolved before
 * this class is ever called.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_MHRA_Intext_Dragdrop_Parts {

	/**
	 * The full ordered token list for one MHRA in-text citation sentence.
	 *
	 * @param string      $form   One of Citex_MHRA_Intext_Citation_Rules::forms().
	 * @param string      $who    The already-joined "who" text.
	 * @param string      $year   4-digit year or literal "n.d.".
	 * @param string      $clause The paraphrase clause (narrative/parenthetical only).
	 * @param string|null $page   Required for parenthetical_quote only.
	 * @param string|null $quote  Required for parenthetical_quote only.
	 * @return array<{key: string|null, kind: string, value: string, literal: bool}>
	 */
	public static function build_tokens( $form, $who, $year, $clause, $page = null, $quote = null ) {
		$tokens = array();
		if ( Citex_MHRA_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
			$tokens[] = array( 'key' => 'who', 'kind' => 'who', 'value' => (string) $who, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' (', 'literal' => true );
			$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => (string) $year, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ') ' . self::clean_clause( $clause ) . '.', 'literal' => true );
		} elseif ( Citex_MHRA_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
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
	 * Builds {parts, fixedText, confusingWords} for one MHRA in-text
	 * citation question — every candidate is always drawn.
	 *
	 * @param string   $who      Already-joined "who" text.
	 * @param string[] $surnames The raw surname (or Website name) list the
	 *                           "who" was joined from — used only to build
	 *                           the "who" distractor.
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
				return Citex_MHRA_Intext_Citation_Rules::wrong_join( $surnames, $who );
			case 'year':
				return Citex_Reference_Rules::year_distractor( (string) $value, $record_seed . '|year' );
			case 'page':
				return Citex_Reference_Rules::year_distractor( (string) $value, $record_seed . '|page' );
		}
		return $value . '?';
	}
}
