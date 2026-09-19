<?php
/**
 * Unit tests for Citex_Intext_Dragdrop_Parts — Harvard's in-text citation
 * DragDrop builder. The critical thing under test: WHERE the "who" token
 * sits relative to the parentheses per form, by construction — the exact
 * screenshot-1 regression (author trapped inside the parentheses in the
 * narrative form) this class exists to make structurally impossible.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-intext-dragdrop-parts.test.php` — not
 * shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-intext-citation-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-intext-dragdrop-parts.php';

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

// ---------------------------------------------------------------------
// 1. Narrative form: "who" sits OUTSIDE the parentheses, as its own
// leading token, with only "year" inside "(||)" — the screenshot-1 fix.
// ---------------------------------------------------------------------
$narrative_tokens = Citex_Intext_Dragdrop_Parts::build_tokens( 'narrative', 'Ross', '2015', 'argues that point matters' );
check( '[1] narrative: first token is the "who" blank, not a literal', $narrative_tokens[0]['kind'], 'who' );
check( '[1] narrative: first token is non-literal (a draggable blank)', $narrative_tokens[0]['literal'], false );
check( '[1] narrative: second token is the literal " (" — opening the parenthesis AFTER who', $narrative_tokens[1]['value'], ' (' );
check( '[1] narrative: third token is the "year" blank, sitting INSIDE the parenthesis', $narrative_tokens[2]['kind'], 'year' );
check( '[1] narrative: no token anywhere combines who+year inside one parenthesis pair', in_array( 'Ross (2015', array_column( $narrative_tokens, 'value' ), true ), false );

// ---------------------------------------------------------------------
// 2. Parenthetical form: the WHOLE citation (who + year) sits inside one
// parenthesis pair, at the end of the clause.
// ---------------------------------------------------------------------
$parenthetical_tokens = Citex_Intext_Dragdrop_Parts::build_tokens( 'parenthetical', 'Ross', '2015', 'point matters' );
check( '[2] parenthetical: first token is the literal clause + opening paren', $parenthetical_tokens[0]['value'], 'point matters (' );
check( '[2] parenthetical: second token is the "who" blank', $parenthetical_tokens[1]['kind'], 'who' );
check( '[2] parenthetical: third token is a literal comma+space', $parenthetical_tokens[2]['value'], ', ' );
check( '[2] parenthetical: fourth token is the "year" blank', $parenthetical_tokens[3]['kind'], 'year' );

// ---------------------------------------------------------------------
// 3. Parenthetical-quote form: quote, then (who, year, p. page).
// ---------------------------------------------------------------------
$quote_tokens = Citex_Intext_Dragdrop_Parts::build_tokens( 'parenthetical_quote', 'Ross', '2019', '', '69', 'urban planning must treat' );
$quote_kinds  = array_column( $quote_tokens, 'kind' );
check( '[3] quote form draws exactly who/year/page as blanks (in that order)', array_values( array_filter( $quote_kinds, function ( $k ) { return 'literal' !== $k; } ) ), array( 'who', 'year', 'page' ) );
check( '[3] quote form\'s opening literal carries the quote text', strpos( $quote_tokens[0]['value'], 'urban planning must treat' ) !== false, true );

// ---------------------------------------------------------------------
// 4. build() — every non-literal token is always drawn (no subset
// selection, unlike reference-list DragDrop classes).
// ---------------------------------------------------------------------
$built = Citex_Intext_Dragdrop_Parts::build( 'narrative', 'Ross', array( 'Ross' ), '2015', 'argues that point matters' );
check( '[4] narrative build() draws exactly 2 parts (who, year)', count( $built['parts'] ), 2 );
check( '[4] narrative build() parts are [who, year] in order', $built['parts'], array( 'Ross', '2015' ) );
check( '[4] narrative build() produces exactly 2 confusingWords, one per blank', count( $built['confusingWords'] ), 2 );
check( '[4] narrative build()\'s confusingWords never equal the correct values', in_array( 'Ross', $built['confusingWords'], true ) || in_array( '2015', $built['confusingWords'], true ), false );

$built_quote = Citex_Intext_Dragdrop_Parts::build( 'parenthetical_quote', 'Ross et al.', array( 'Ross', 'Carter', 'Lee', 'Kaur' ), '2019', '', '69', 'a short quote' );
check( '[4] quote-form build() draws exactly 3 parts (who, year, page)', count( $built_quote['parts'] ), 3 );

// ---------------------------------------------------------------------
// 5. Reconstruction via fixedText/parts exactly reproduces the rules
// class's own full sentence — the exact-match contract the validator
// relies on.
// ---------------------------------------------------------------------
// Reconstructs the full sentence from {parts, fixedText} by substituting
// each "|"/"||" placeholder in order — the same mechanism
// Citex_Generated_Validator::reconstruct() uses in production.
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
$expected_narrative = Citex_Intext_Citation_Rules::narrative_sentence( 'Ross', '2015', 'argues that point matters' );
check( '[5] reconstructing narrative build() output matches Citex_Intext_Citation_Rules::narrative_sentence() exactly', reconstruct( $built ), $expected_narrative );

$expected_quote = Citex_Intext_Citation_Rules::parenthetical_quote_sentence( 'Ross et al.', '2019', '69', 'a short quote' );
check( '[5] reconstructing quote-form build() output matches parenthetical_quote_sentence() exactly', reconstruct( $built_quote ), $expected_quote );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
