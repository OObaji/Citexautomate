<?php
/**
 * Regression tests for Citex_Website_Dragdrop_Parts — the dynamic
 * exactly-3-part Website DragDrop question builder that replaced the fixed
 * named-design catalogue (Citex_Reference_Rules::website_dragdrop_shape()
 * and friends, still used by MCQ, untouched). Pure, no WordPress/ACF
 * dependency.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-website-dragdrop-parts.test.php` — not shipped
 * in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-website-dragdrop-parts.php';

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

function web_reconstruct( $built ) {
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

$author_ind = array( 'type' => 'individual', 'surname' => 'Mitchell', 'initials' => 'S.', 'fullName' => 'Sarah Mitchell' );
$fields_ind = array( 'year' => '2022', 'title' => 'Study skills guide', 'publisher' => 'University of Leeds', 'url' => 'https://www.leeds.ac.uk/study-skills', 'accessedDate' => '12 September 2026' );

$author_org = array( 'type' => 'organisation', 'name' => 'World Health Organization' );
$fields_org = array( 'year' => 'n.d.', 'title' => 'Guidance on nutrition', 'publisher' => 'WHO', 'url' => 'https://www.who.int/nutrition', 'accessedDate' => '1 January 2026' );

// ---------------------------------------------------------------------
// 1. build_tokens(): all-literal reconstruction matches build_reference()'s
// own output, for individual and organisation authors.
// ---------------------------------------------------------------------
function web_all_literal( array $tokens ) {
	$out = '';
	foreach ( $tokens as $token ) {
		$out .= $token['value'];
	}
	return $out;
}
$tokens_ind = Citex_Website_Dragdrop_Parts::build_tokens( $author_ind, $fields_ind );
check( '[1] individual author: all-literal reconstruction matches build_reference()', web_all_literal( $tokens_ind ), Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_WEBSITE, array_merge( $fields_ind, array( 'author' => $author_ind ) ) ) );
$tokens_org = Citex_Website_Dragdrop_Parts::build_tokens( $author_org, $fields_org );
check( '[1] organisation author: all-literal reconstruction matches build_reference()', web_all_literal( $tokens_org ), Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_WEBSITE, array_merge( $fields_org, array( 'author' => $author_org ) ) ) );

// ---------------------------------------------------------------------
// 2. select_parts(): reproducible for the same seed; always exactly 3 of
// the 6 pure-content candidates (no "content floor" logic needed — every
// candidate is content).
// ---------------------------------------------------------------------
check(
	'[2] select_parts() is reproducible for the same seed',
	Citex_Website_Dragdrop_Parts::select_parts( 'WR07' ),
	Citex_Website_Dragdrop_Parts::select_parts( 'WR07' )
);
$always_three   = true;
$all_valid_keys = true;
$valid_keys     = array( 'author', 'year', 'title', 'publisher', 'url', 'accessed_date' );
for ( $i = 1; $i <= 60; $i++ ) {
	$keys = Citex_Website_Dragdrop_Parts::select_parts( 'WR' . $i );
	if ( 3 !== count( $keys ) ) {
		$always_three = false;
	}
	foreach ( $keys as $key ) {
		if ( ! in_array( $key, $valid_keys, true ) ) {
			$all_valid_keys = false;
		}
	}
}
check( '[2] select_parts() always returns exactly 3 keys across 60 seeds', $always_three, true );
check( '[2] select_parts() only ever returns the 6 known candidate keys', $all_valid_keys, true );

// ---------------------------------------------------------------------
// 3. Every one of the 6 fields is drawn at least once across a wide seed
// sweep (genuine variety, no field left permanently untestable).
// ---------------------------------------------------------------------
$seen = array_fill_keys( $valid_keys, false );
for ( $i = 1; $i <= 100; $i++ ) {
	foreach ( Citex_Website_Dragdrop_Parts::select_parts( 'V' . $i ) as $key ) {
		$seen[ $key ] = true;
	}
}
check( '[3] every one of the 6 fields is drawn at least once across 100 seeds', array_filter( $seen ), $seen );

// ---------------------------------------------------------------------
// 4. build(): exact output for hand-picked key sets.
// ---------------------------------------------------------------------
$built = Citex_Website_Dragdrop_Parts::build( array( 'author', 'year', 'url' ), $author_ind, $fields_ind );
check( '[4] drawing author/year/url: parts', $built['parts'], array( 'Mitchell, S.', '2022', 'https://www.leeds.ac.uk/study-skills' ) );
check( '[4] drawing author/year/url: fixedText', $built['fixedText'], '| (||) Study skills guide [online]. University of Leeds. Available from: <||> [accessed 12 September 2026].' );
check( '[4] reconstructs to the full reference', web_reconstruct( $built ), 'Mitchell, S. (2022) Study skills guide [online]. University of Leeds. Available from: <https://www.leeds.ac.uk/study-skills> [accessed 12 September 2026].' );

$built_org = Citex_Website_Dragdrop_Parts::build( array( 'author', 'title', 'accessed_date' ), $author_org, $fields_org );
check( '[4] organisation author: parts', $built_org['parts'], array( 'World Health Organization', 'Guidance on nutrition', '1 January 2026' ) );
check( '[4] organisation author: reconstructs to the full reference (n.d. stays literal when not drawn)', web_reconstruct( $built_org ), 'World Health Organization (n.d.) Guidance on nutrition [online]. WHO. Available from: <https://www.who.int/nutrition> [accessed 1 January 2026].' );

// ---------------------------------------------------------------------
// 5. Distractor rules: never equal to the correct value, across every kind,
// for both individual and organisation authors.
// ---------------------------------------------------------------------
$all_keys      = array( 'author', 'year', 'title', 'publisher', 'url', 'accessed_date' );
$built_all_ind = Citex_Website_Dragdrop_Parts::build( $all_keys, $author_ind, $fields_ind );
foreach ( $built_all_ind['parts'] as $index => $part ) {
	check( "[5] individual: distractor for part $index (\"$part\") is never equal to the correct value", $built_all_ind['confusingWords'][ $index ] === $part, false );
}
$built_all_org = Citex_Website_Dragdrop_Parts::build( $all_keys, $author_org, $fields_org );
foreach ( $built_all_org['parts'] as $index => $part ) {
	check( "[5] organisation: distractor for part $index (\"$part\") is never equal to the correct value", $built_all_org['confusingWords'][ $index ] === $part, false );
}

// ---------------------------------------------------------------------
// 6. build() returns null for an empty selection.
// ---------------------------------------------------------------------
check( '[6] an empty selection returns null', Citex_Website_Dragdrop_Parts::build( array(), $author_ind, $fields_ind ), null );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
