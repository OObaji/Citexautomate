<?php
/**
 * Unit tests for Citex_MLA_Intext_Dragdrop_Parts — MLA's in-text citation
 * DragDrop builder. Critical distinctions under test vs. the Harvard
 * class: no "year" token exists anywhere; the quote form's who/page gap
 * is a single space token, never a comma — the single most-tested
 * MLA-vs-Harvard distractor.
 *
 * Repo-level only, run with plain
 * `php tests/mla-intext-dragdrop-parts.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-intext-citation-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-intext-dragdrop-parts.php';

$failures = 0;
function check( $description, $actual, $expected ) {
	global $failures;
	$pass = $actual === $expected;
	echo ( $pass ? 'PASS' : 'FAIL' ) . ': ' . $description
		. ( $pass ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' )
		. "\n";
	if ( ! $pass ) {
		$failures++;
	}
}
function reconstruct( $built ) {
	$fixed = $built['fixedText'];
	$parts = $built['parts'];
	$out   = '';
	$index = 0;
	$len   = strlen( $fixed );
	for ( $i = 0; $i < $len; $i++ ) {
		if ( '|' !== $fixed[ $i ] ) {
			$out .= $fixed[ $i ];
			continue;
		}
		if ( $i + 1 < $len && '|' === $fixed[ $i + 1 ] ) {
			$out .= (string) $parts[ $index++ ];
			$i++;
			continue;
		}
		$out .= (string) $parts[ $index++ ];
	}
	return $out;
}

// ---------------------------------------------------------------------
// 1. Narrative WITH a page: who + page blanks, no year token anywhere.
// ---------------------------------------------------------------------
$narrative_tokens = Citex_MLA_Intext_Dragdrop_Parts::build_tokens( 'narrative', 'Ross', 'argues that point matters', '18' );
$kinds             = array_column( $narrative_tokens, 'kind' );
check( '[1] narrative-with-page draws exactly [who, page] as blanks', array_values( array_filter( $kinds, function ( $k ) { return 'literal' !== $k; } ) ), array( 'who', 'page' ) );
check( '[1] no token kind is "year" anywhere (MLA has no year at all)', in_array( 'year', $kinds, true ), false );

// ---------------------------------------------------------------------
// 2. Narrative WITHOUT a page: who is the only blank, thinner than every
// other DragDrop mechanic — an accepted, documented edge case.
// ---------------------------------------------------------------------
$narrative_no_page = Citex_MLA_Intext_Dragdrop_Parts::build_tokens( 'narrative', 'Ross', 'argues that point matters', null );
$kinds_no_page      = array_column( $narrative_no_page, 'kind' );
check( '[2] narrative-without-page draws exactly [who] as the only blank', array_values( array_filter( $kinds_no_page, function ( $k ) { return 'literal' !== $k; } ) ), array( 'who' ) );

// ---------------------------------------------------------------------
// 3. Quote form: who and page are separated by a SINGLE SPACE literal
// token, never a comma.
// ---------------------------------------------------------------------
$quote_tokens = Citex_MLA_Intext_Dragdrop_Parts::build_tokens( 'parenthetical_quote', 'Ross', '', '22', 'a short quote' );
$who_index    = null;
$page_index   = null;
foreach ( $quote_tokens as $i => $t ) {
	if ( 'who' === $t['kind'] ) { $who_index = $i; }
	if ( 'page' === $t['kind'] ) { $page_index = $i; }
}
check( '[3] the literal token between who and page is exactly a single space', $quote_tokens[ $who_index + 1 ]['value'], ' ' );
check( '[3] no literal token anywhere in the quote form contains a comma', false !== strpos( implode( '', array_column( array_filter( $quote_tokens, function ( $t ) { return $t['literal']; } ), 'value' ) ), ',' ), false );

// ---------------------------------------------------------------------
// 4. build() + reconstruction matches Citex_MLA_Intext_Citation_Rules's
// own sentence builders exactly.
// ---------------------------------------------------------------------
$built = Citex_MLA_Intext_Dragdrop_Parts::build( 'narrative', 'Ross', array( 'Ross' ), 'argues that point matters', '18' );
check( '[4] narrative-with-page build() reconstructs to narrative_sentence() exactly', reconstruct( $built ), Citex_MLA_Intext_Citation_Rules::narrative_sentence( 'Ross', 'argues that point matters', '18' ) );

$built_quote = Citex_MLA_Intext_Dragdrop_Parts::build( 'parenthetical_quote', 'Ross et al.', array( 'Ross', 'Carter', 'Lee' ), '', '22', 'a short quote' );
check( '[4] quote-form build() reconstructs to parenthetical_quote_sentence() exactly', reconstruct( $built_quote ), Citex_MLA_Intext_Citation_Rules::parenthetical_quote_sentence( 'Ross et al.', '22', 'a short quote' ) );
check( '[4] reconstructed quote-form output contains no comma before the page', strpos( reconstruct( $built_quote ), ', 22' ), false );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
