<?php
/**
 * Regression tests for Citex_MHRA_Book_Dragdrop_Parts — MHRA Book
 * DragDrop's dynamic 3-part question builder, mirroring
 * tests/reference-rules-chicago-book-dragdrop-parts.test.php's own "drawn
 * author index" structure, but for MHRA's own rule: only the FIRST author
 * is inverted ("Surname, ||") — every author after the first is rendered
 * in NATURAL word order ("|| ||", given name first) instead, so the drawn
 * author's surname/given-name tokens swap their relative order depending
 * on whether the drawn author is first or later; every author always
 * listed in full ("and" before the last, comma before it even at exactly
 * 2 — never "&"); `place` IS a candidate; and place, publisher AND year
 * all sit together inside ONE trailing parenthesis. Pure, no WordPress/ACF
 * dependency.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-mhra-book-dragdrop-parts.test.php` — not
 * shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-book-dragdrop-parts.php';

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

function mhra_author( $surname, $given, $full ) {
	return array( 'surname' => $surname, 'givenName' => $given, 'fullName' => $full );
}

// Reconstructs the full reference from {parts, fixedText} — same mechanism
// Citex_Generated_Validator::reconstruct() uses in production.
function mhra_reconstruct( $built ) {
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

$fields = array( 'title' => 'Digital Media', 'place' => 'London', 'publisher' => 'Routledge', 'year' => '2019' );

// ---------------------------------------------------------------------
// 1. build_tokens(): the full token stream for 1/2/3-author records
// reconstructs (all-literal) to exactly build_reference()'s own output.
// ---------------------------------------------------------------------
function mhra_all_literal_reconstruction( array $tokens ) {
	$out = '';
	foreach ( $tokens as $token ) {
		$out .= $token['value'];
	}
	return $out;
}
$pool = array(
	mhra_author( 'Clark', 'Simon', 'Simon Clark' ),
	mhra_author( 'Davies', 'Helen', 'Helen Davies' ),
	mhra_author( 'Wilson', 'Mark', 'Mark Wilson' ),
);
foreach ( array( 1, 2, 3 ) as $count ) {
	$authors  = array_slice( $pool, 0, $count );
	$tokens   = Citex_MHRA_Book_Dragdrop_Parts::build_tokens( $authors, $fields, 0 );
	$expected = Citex_MHRA_Reference_Rules::build_reference( Citex_MHRA_Reference_Rules::CATEGORY_BOOK, array_merge( $fields, array( 'authors' => $authors ) ) );
	check( "[1] build_tokens() for $count author(s), all-literal, reconstructs to build_reference()'s output", mhra_all_literal_reconstruction( $tokens ), $expected );
}

// ---------------------------------------------------------------------
// 2. select_parts(): reproducible for the same seed, always exactly 3
// parts, and the drawn author index (if any) is always within range.
// ---------------------------------------------------------------------
$three_authors = array( mhra_author( 'Carter', 'John', 'John Carter' ), mhra_author( 'Green', 'Emma', 'Emma Green' ), mhra_author( 'Smith', 'David', 'David Smith' ) );
check(
	'[2] select_parts() is reproducible for the same seed',
	Citex_MHRA_Book_Dragdrop_Parts::select_parts( 'HB07', $three_authors ),
	Citex_MHRA_Book_Dragdrop_Parts::select_parts( 'HB07', $three_authors )
);
for ( $i = 1; $i <= 40; $i++ ) {
	$seed  = 'HB' . str_pad( $i, 2, '0', STR_PAD_LEFT );
	$keys  = Citex_MHRA_Book_Dragdrop_Parts::select_parts( $seed, $three_authors );
	$built = Citex_MHRA_Book_Dragdrop_Parts::build( $keys, $three_authors, $fields );
	check( "[2] $seed: exactly 3 parts drawn", count( $built['parts'] ), 3 );
	foreach ( $keys as $key ) {
		if ( 1 === preg_match( '/^author_(\d+)_(?:surname|givenname)$/', $key, $m ) ) {
			if ( (int) $m[1] >= count( $three_authors ) ) {
				check( "[2] $seed: drawn author index is within range", (int) $m[1] < count( $three_authors ), true );
			}
		}
	}
}

// ---------------------------------------------------------------------
// 3. "and" is never eligible/selected for a single author, but is
// selected at least once across a wide seed sweep for 2+ authors.
// ---------------------------------------------------------------------
$single_author = array( mhra_author( 'Brown', 'Andrew', 'Andrew Brown' ) );
$and_seen_single = false;
for ( $i = 1; $i <= 60; $i++ ) {
	$keys = Citex_MHRA_Book_Dragdrop_Parts::select_parts( 'S' . $i, $single_author );
	if ( in_array( 'and', $keys, true ) ) {
		$and_seen_single = true;
	}
}
check( '[3] single-author: "and" is never selected (not eligible with only 1 author)', $and_seen_single, false );

$and_seen_multi = false;
for ( $i = 1; $i <= 60; $i++ ) {
	if ( in_array( 'and', Citex_MHRA_Book_Dragdrop_Parts::select_parts( 'M' . $i, $three_authors ), true ) ) {
		$and_seen_multi = true;
	}
}
check( '[3] multi-author (3): "and" is selected at least once across 60 seeds', $and_seen_multi, true );

// ---------------------------------------------------------------------
// 4. build(): exact output for hand-picked key sets — confirmed via a
// real run against Clark, Simon / Davies, Helen / Wilson, Mark, "Digital
// Media", London: Routledge, 2019. Drawing a LATER author must render in
// NATURAL word order (given name first), the single most MHRA-distinctive
// DragDrop behaviour.
// ---------------------------------------------------------------------
$three_named = $pool;

$built_author0 = Citex_MHRA_Book_Dragdrop_Parts::build( array( 'author_0_surname', 'author_0_givenname', 'year', 'publisher' ), $three_named, $fields );
check( '[4] drawing author 0 (Clark, first, inverted): parts', $built_author0['parts'], array( 'Clark', 'Simon', 'Routledge', '2019' ) );
check( '[4] drawing author 0 (Clark): fixedText', $built_author0['fixedText'], '|, ||, Helen Davies, and Mark Wilson, Digital Media (London: ||, ||).' );
check( '[4] drawing author 0 (Clark): reconstructs to the full reference', mhra_reconstruct( $built_author0 ), 'Clark, Simon, Helen Davies, and Mark Wilson, Digital Media (London: Routledge, 2019).' );

$built_author1 = Citex_MHRA_Book_Dragdrop_Parts::build( array( 'author_1_surname', 'author_1_givenname' ), $three_named, $fields );
check( '[4] drawing author 1 (Davies, middle, NATURAL order): parts', $built_author1['parts'], array( 'Helen', 'Davies' ) );
check( '[4] drawing author 1 (Davies): fixedText', $built_author1['fixedText'], 'Clark, Simon, || ||, and Mark Wilson, Digital Media (London: Routledge, 2019).' );
check( '[4] drawing author 1 (Davies): reconstructs to the full reference', mhra_reconstruct( $built_author1 ), 'Clark, Simon, Helen Davies, and Mark Wilson, Digital Media (London: Routledge, 2019).' );

$built_author2 = Citex_MHRA_Book_Dragdrop_Parts::build( array( 'author_2_surname', 'author_2_givenname' ), $three_named, $fields );
check( '[4] drawing author 2 (Wilson, last, NATURAL order): parts', $built_author2['parts'], array( 'Mark', 'Wilson' ) );
check( '[4] drawing author 2 (Wilson): fixedText', $built_author2['fixedText'], 'Clark, Simon, Helen Davies, and || ||, Digital Media (London: Routledge, 2019).' );

$built_and = Citex_MHRA_Book_Dragdrop_Parts::build( array( 'and' ), $three_named, $fields );
check( '[4] drawing "and": parts', $built_and['parts'], array( 'and' ) );
check( '[4] drawing "and": confusingWords is "&"', $built_and['confusingWords'], array( '&' ) );
check( '[4] drawing "and": fixedText', $built_and['fixedText'], 'Clark, Simon, Helen Davies, || Mark Wilson, Digital Media (London: Routledge, 2019).' );
check( '[4] drawing "and": reconstructs to the full reference', mhra_reconstruct( $built_and ), 'Clark, Simon, Helen Davies, and Mark Wilson, Digital Media (London: Routledge, 2019).' );

// ---------------------------------------------------------------------
// 5. Distractor rules: never equal to the correct value, for every kind.
// ---------------------------------------------------------------------
$all_keys_single = array( 'author_0_surname', 'author_0_givenname', 'title', 'place', 'publisher', 'year' );
$built_all = Citex_MHRA_Book_Dragdrop_Parts::build( $all_keys_single, $single_author, $fields );
foreach ( $built_all['parts'] as $index => $part ) {
	check( "[5] distractor for part $index (\"$part\") is never equal to the correct value", $built_all['confusingWords'][ $index ] === $part, false );
}

// ---------------------------------------------------------------------
// 6. Author-mix-up distractors: with 2+ authors, the surname/given-name
// distractor sometimes attributes the OTHER author's real surname/given
// name to the drawn author's position — swept across many seeds.
// ---------------------------------------------------------------------
$other_author_surname_seen    = false;
$other_author_given_name_seen = false;
for ( $i = 1; $i <= 30; $i++ ) {
	$mix_fields = array( 'title' => 'Book title ' . $i, 'place' => 'London', 'publisher' => 'Routledge', 'year' => (string) ( 2000 + $i ) );
	$mix_built  = Citex_MHRA_Book_Dragdrop_Parts::build( array( 'author_0_surname', 'author_0_givenname' ), $three_named, $mix_fields );
	if ( 'Davies' === $mix_built['confusingWords'][0] ) {
		$other_author_surname_seen = true;
	}
	if ( 'Helen' === $mix_built['confusingWords'][1] ) {
		$other_author_given_name_seen = true;
	}
}
check( '[6] author surname distractor sometimes attributes the OTHER author\'s real surname', $other_author_surname_seen, true );
check( '[6] author given-name distractor sometimes attributes the OTHER author\'s real given name', $other_author_given_name_seen, true );

// ---------------------------------------------------------------------
// 7. build() returns null for an out-of-range author index, or an empty
// selection.
// ---------------------------------------------------------------------
check( '[7] an out-of-range author index returns null', Citex_MHRA_Book_Dragdrop_Parts::build( array( 'author_5_surname' ), $single_author, $fields ), null );
check( '[7] an empty selection returns null', Citex_MHRA_Book_Dragdrop_Parts::build( array(), $single_author, $fields ), null );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
