<?php
/**
 * Regression tests for Citex_Journal_Article_Dragdrop_Parts — the dynamic
 * exactly-3-part Journal Article DragDrop question builder that replaced the
 * fixed named-design catalogue (Citex_Reference_Rules::journal_article_dragdrop_shape()
 * and friends, still used by MCQ, untouched). Pure, no WordPress/ACF
 * dependency — mirrors tests/reference-rules-book-dragdrop-parts.test.php's
 * own no-stub-needed setup.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-journal-article-dragdrop-parts.test.php` — not
 * shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-journal-article-dragdrop-parts.php';

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

function ja_author( $surname, $initials, $full ) {
	return array( 'surname' => $surname, 'initials' => $initials, 'fullName' => $full );
}

function ja_reconstruct( $built ) {
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

$fields = array( 'year' => '2021', 'articleTitle' => 'Urban Life', 'journalTitle' => 'Sociology', 'volume' => '55', 'issue' => '3', 'pages' => '410-425' );

// ---------------------------------------------------------------------
// 1. build_tokens(): all-literal reconstruction matches build_reference()'s
// own output, for 1/2/3/6-author records.
// ---------------------------------------------------------------------
function ja_all_literal( array $tokens ) {
	$out = '';
	foreach ( $tokens as $token ) {
		$out .= $token['value'];
	}
	return $out;
}
$pool = array(
	ja_author( 'Ross', 'A.', 'Adam Ross' ),
	ja_author( 'King', 'B.', 'Beth King' ),
	ja_author( 'Cole', 'C.', 'Carl Cole' ),
	ja_author( 'Reed', 'D.', 'Dana Reed' ),
	ja_author( 'Shaw', 'E.', 'Eric Shaw' ),
	ja_author( 'Bell', 'F.', 'Faye Bell' ),
);
foreach ( array( 1, 2, 3, 6 ) as $count ) {
	$authors  = array_slice( $pool, 0, $count );
	$tokens   = Citex_Journal_Article_Dragdrop_Parts::build_tokens( $authors, $fields, 0 );
	$expected = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, array_merge( $fields, array( 'authors' => $authors ) ) );
	check( "[1] build_tokens() for $count author(s), all-literal, reconstructs to build_reference()'s output", ja_all_literal( $tokens ), $expected );
}

// ---------------------------------------------------------------------
// 2. select_parts(): reproducible for the same seed; always exactly 3 keys.
// ---------------------------------------------------------------------
$two_authors = array( ja_author( 'Vance', 'L.', 'Louise Vance' ), ja_author( 'Dale', 'E.', 'Edward Dale' ) );
check(
	'[2] select_parts() is reproducible for the same seed',
	Citex_Journal_Article_Dragdrop_Parts::select_parts( 'JA07', $two_authors ),
	Citex_Journal_Article_Dragdrop_Parts::select_parts( 'JA07', $two_authors )
);
$always_three = true;
for ( $i = 1; $i <= 40; $i++ ) {
	$keys = Citex_Journal_Article_Dragdrop_Parts::select_parts( 'JA' . $i, $two_authors );
	if ( 3 !== count( $keys ) ) {
		$always_three = false;
	}
}
check( '[2] select_parts() always returns exactly 3 keys across 40 seeds', $always_three, true );

// ---------------------------------------------------------------------
// 3. Content floor + "and" eligibility across a wide seed sweep.
// ---------------------------------------------------------------------
function ja_has_content( array $keys ) {
	foreach ( $keys as $key ) {
		if ( 'and' !== $key ) {
			return true;
		}
	}
	return false;
}
$single_author        = array( ja_author( 'Adams', 'R.', 'Robert Adams' ) );
$and_ever_single       = false;
$content_floor_single  = true;
for ( $i = 1; $i <= 60; $i++ ) {
	$keys = Citex_Journal_Article_Dragdrop_Parts::select_parts( 'S' . $i, $single_author );
	if ( ! ja_has_content( $keys ) ) {
		$content_floor_single = false;
	}
	if ( in_array( 'and', $keys, true ) ) {
		$and_ever_single = true;
	}
}
check( '[3] single-author: content floor always satisfied across 60 seeds', $content_floor_single, true );
check( '[3] single-author: "and" is never selected (not eligible with only 1 author)', $and_ever_single, false );

$and_ever_multi = false;
for ( $i = 1; $i <= 60; $i++ ) {
	$keys = Citex_Journal_Article_Dragdrop_Parts::select_parts( 'M' . $i, $two_authors );
	if ( in_array( 'and', $keys, true ) ) {
		$and_ever_multi = true;
	}
}
check( '[3] two-author: "and" is selected at least once across 60 seeds', $and_ever_multi, true );

// ---------------------------------------------------------------------
// 4. build(): exact output for hand-verified key sets — the user's own
// literal example (Vance, L. and Dale, E. (2021) 'Urban Life', Sociology,
// 55(3), pp. 410–425.).
// ---------------------------------------------------------------------
$built_full = Citex_Journal_Article_Dragdrop_Parts::build( array( 'author_0', 'year', 'pages' ), $two_authors, $fields );
check( '[4] drawing author/year/pages: parts', $built_full['parts'], array( 'Vance, L.', '2021', '410–425' ) );
check( '[4] drawing author/year/pages: fixedText', $built_full['fixedText'], '| and Dale, E. (||) ‘Urban Life’, Sociology, 55(3), pp. ||.' );
check( '[4] reconstructs to the full Cite Them Right reference', ja_reconstruct( $built_full ), 'Vance, L. and Dale, E. (2021) ‘Urban Life’, Sociology, 55(3), pp. 410–425.' );

$built_and = Citex_Journal_Article_Dragdrop_Parts::build( array( 'and' ), $two_authors, $fields );
check( '[4] drawing "and": confusingWords is "&"', $built_and['confusingWords'], array( '&' ) );
check( '[4] drawing "and": reconstructs to the full reference', ja_reconstruct( $built_and ), 'Vance, L. and Dale, E. (2021) ‘Urban Life’, Sociology, 55(3), pp. 410–425.' );

$built_author1 = Citex_Journal_Article_Dragdrop_Parts::build( array( 'author_1' ), $two_authors, $fields );
check( '[4] drawing the SECOND author individually: parts', $built_author1['parts'], array( 'Dale, E.' ) );
check( '[4] drawing the second author: reconstructs to the full reference', ja_reconstruct( $built_author1 ), 'Vance, L. and Dale, E. (2021) ‘Urban Life’, Sociology, 55(3), pp. 410–425.' );

// ---------------------------------------------------------------------
// 5. Distractor rules: never equal to the correct value, across every kind.
// ---------------------------------------------------------------------
$all_keys  = array( 'author_0', 'year', 'title', 'journal', 'volume', 'issue', 'pages' );
$built_all = Citex_Journal_Article_Dragdrop_Parts::build( $all_keys, $single_author, array( 'year' => '2022', 'articleTitle' => 'Heart Health', 'journalTitle' => 'The Lancet', 'volume' => '399', 'issue' => '1', 'pages' => '40-48' ) );
foreach ( $built_all['parts'] as $index => $part ) {
	check( "[5] distractor for part $index (\"$part\") is never equal to the correct value", $built_all['confusingWords'][ $index ] === $part, false );
}

// ---------------------------------------------------------------------
// 6. build() returns null for an out-of-range author index, or an empty
// selection — the exact signal Citex_Generated_Validator's Journal Article
// block treats as JOURNAL_ARTICLE_DRAGDROP_PARTS_UNKNOWN.
// ---------------------------------------------------------------------
check( '[6] an out-of-range author index returns null', Citex_Journal_Article_Dragdrop_Parts::build( array( 'author_5' ), $single_author, $fields ), null );
check( '[6] an empty selection returns null', Citex_Journal_Article_Dragdrop_Parts::build( array(), $single_author, $fields ), null );

// ---------------------------------------------------------------------
// 7. Reproducibility: build() from the same selected_keys always yields
// identical output (the validator's recomputation must always agree).
// ---------------------------------------------------------------------
$rebuilt = Citex_Journal_Article_Dragdrop_Parts::build( array( 'author_0', 'year', 'pages' ), $two_authors, $fields );
check( '[7] build() is perfectly reproducible from the same selection', $rebuilt, $built_full );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
