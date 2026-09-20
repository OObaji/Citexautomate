<?php
/**
 * Regression tests for Citex_Chicago_Book_Dragdrop_Parts — Chicago
 * (Author-Date) Book DragDrop's dynamic 3-part question builder, mirroring
 * tests/reference-rules-book-dragdrop-parts.test.php's own "drawn author
 * index" structure (unlike MLA Book, which only ever draws the first
 * author), but for Chicago's own rule: the FULL given name (never an
 * initial), every author always listed in full ("and" before the last,
 * comma before it even at exactly 2 — never "&"), `place` IS a candidate
 * (unlike MLA/APA), and no parentheses around the year at all.
 * Pure, no WordPress/ACF dependency.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-chicago-book-dragdrop-parts.test.php` — not
 * shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-chicago-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-chicago-book-dragdrop-parts.php';

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

function chicago_author( $surname, $given, $full ) {
	return array( 'surname' => $surname, 'givenName' => $given, 'fullName' => $full );
}

// Reconstructs the full reference from {parts, fixedText} — same mechanism
// Citex_Generated_Validator::reconstruct() uses in production.
function chicago_reconstruct( $built ) {
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

$fields = array( 'year' => '2019', 'title' => 'Digital Media', 'place' => 'London', 'publisher' => 'Routledge' );

// ---------------------------------------------------------------------
// 1. build_tokens(): the full token stream for 1/2/3-author records
// reconstructs (all-literal) to exactly build_reference()'s own output.
// ---------------------------------------------------------------------
function chicago_all_literal_reconstruction( array $tokens ) {
	$out = '';
	foreach ( $tokens as $token ) {
		$out .= $token['value'];
	}
	return $out;
}
$pool = array(
	chicago_author( 'Clark', 'Simon', 'Simon Clark' ),
	chicago_author( 'Davies', 'Helen', 'Helen Davies' ),
	chicago_author( 'Wilson', 'Mark', 'Mark Wilson' ),
);
foreach ( array( 1, 2, 3 ) as $count ) {
	$authors  = array_slice( $pool, 0, $count );
	$tokens   = Citex_Chicago_Book_Dragdrop_Parts::build_tokens( $authors, $fields, 0 );
	$expected = Citex_Chicago_Reference_Rules::build_reference( Citex_Chicago_Reference_Rules::CATEGORY_BOOK, array_merge( $fields, array( 'authors' => $authors ) ) );
	check( "[1] build_tokens() for $count author(s), all-literal, reconstructs to build_reference()'s output", chicago_all_literal_reconstruction( $tokens ), $expected );
}

// ---------------------------------------------------------------------
// 2. select_parts(): reproducible for the same seed, always exactly 3
// parts, and the drawn author index (if any) is always within range.
// ---------------------------------------------------------------------
$three_authors = array( chicago_author( 'Carter', 'John', 'John Carter' ), chicago_author( 'Green', 'Emma', 'Emma Green' ), chicago_author( 'Smith', 'David', 'David Smith' ) );
check(
	'[2] select_parts() is reproducible for the same seed',
	Citex_Chicago_Book_Dragdrop_Parts::select_parts( 'CB07', $three_authors ),
	Citex_Chicago_Book_Dragdrop_Parts::select_parts( 'CB07', $three_authors )
);
for ( $i = 1; $i <= 40; $i++ ) {
	$seed  = 'CB' . str_pad( $i, 2, '0', STR_PAD_LEFT );
	$keys  = Citex_Chicago_Book_Dragdrop_Parts::select_parts( $seed, $three_authors );
	$built = Citex_Chicago_Book_Dragdrop_Parts::build( $keys, $three_authors, $fields );
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
$single_author = array( chicago_author( 'Brown', 'Andrew', 'Andrew Brown' ) );
$and_seen_single = false;
for ( $i = 1; $i <= 60; $i++ ) {
	$keys = Citex_Chicago_Book_Dragdrop_Parts::select_parts( 'S' . $i, $single_author );
	if ( in_array( 'and', $keys, true ) ) {
		$and_seen_single = true;
	}
}
check( '[3] single-author: "and" is never selected (not eligible with only 1 author)', $and_seen_single, false );

$and_seen_multi = false;
for ( $i = 1; $i <= 60; $i++ ) {
	if ( in_array( 'and', Citex_Chicago_Book_Dragdrop_Parts::select_parts( 'M' . $i, $three_authors ), true ) ) {
		$and_seen_multi = true;
	}
}
check( '[3] multi-author (3): "and" is selected at least once across 60 seeds', $and_seen_multi, true );

// ---------------------------------------------------------------------
// 4. build(): exact output for hand-picked key sets — confirmed via a
// real run against Clark, Simon / Davies, Helen / Wilson, Mark, 2019,
// "Digital Media", London: Routledge.
// ---------------------------------------------------------------------
$three_named = $pool;

$built_author0 = Citex_Chicago_Book_Dragdrop_Parts::build( array( 'author_0_surname', 'author_0_givenname', 'year', 'publisher' ), $three_named, $fields );
check( '[4] drawing author 0 (Clark): parts', $built_author0['parts'], array( 'Clark', 'Simon', '2019', 'Routledge' ) );
check( '[4] drawing author 0 (Clark): fixedText', $built_author0['fixedText'], '|, ||, Davies, Helen, and Wilson, Mark. ||. Digital Media. London: ||.' );
check( '[4] drawing author 0 (Clark): reconstructs to the full reference', chicago_reconstruct( $built_author0 ), 'Clark, Simon, Davies, Helen, and Wilson, Mark. 2019. Digital Media. London: Routledge.' );

$built_author1 = Citex_Chicago_Book_Dragdrop_Parts::build( array( 'author_1_surname', 'author_1_givenname' ), $three_named, $fields );
check( '[4] drawing author 1 (Davies): parts', $built_author1['parts'], array( 'Davies', 'Helen' ) );
check( '[4] drawing author 1 (Davies): fixedText', $built_author1['fixedText'], 'Clark, Simon, ||, ||, and Wilson, Mark. 2019. Digital Media. London: Routledge.' );
check( '[4] drawing author 1 (Davies): reconstructs to the full reference', chicago_reconstruct( $built_author1 ), 'Clark, Simon, Davies, Helen, and Wilson, Mark. 2019. Digital Media. London: Routledge.' );

$built_author2 = Citex_Chicago_Book_Dragdrop_Parts::build( array( 'author_2_surname', 'author_2_givenname' ), $three_named, $fields );
check( '[4] drawing author 2 (Wilson): parts', $built_author2['parts'], array( 'Wilson', 'Mark' ) );
check( '[4] drawing author 2 (Wilson): fixedText', $built_author2['fixedText'], 'Clark, Simon, Davies, Helen, and ||, ||. 2019. Digital Media. London: Routledge.' );

$built_and = Citex_Chicago_Book_Dragdrop_Parts::build( array( 'and' ), $three_named, $fields );
check( '[4] drawing "and": parts', $built_and['parts'], array( 'and' ) );
check( '[4] drawing "and": confusingWords is "&"', $built_and['confusingWords'], array( '&' ) );
check( '[4] drawing "and": fixedText', $built_and['fixedText'], 'Clark, Simon, Davies, Helen, || Wilson, Mark. 2019. Digital Media. London: Routledge.' );
check( '[4] drawing "and": reconstructs to the full reference', chicago_reconstruct( $built_and ), 'Clark, Simon, Davies, Helen, and Wilson, Mark. 2019. Digital Media. London: Routledge.' );

// ---------------------------------------------------------------------
// 5. Distractor rules: never equal to the correct value, for every kind.
// ---------------------------------------------------------------------
$all_keys_single = array( 'author_0_surname', 'author_0_givenname', 'year', 'title', 'place', 'publisher' );
$built_all = Citex_Chicago_Book_Dragdrop_Parts::build( $all_keys_single, $single_author, $fields );
foreach ( $built_all['parts'] as $index => $part ) {
	check( "[5] distractor for part $index (\"$part\") is never equal to the correct value", $built_all['confusingWords'][ $index ] === $part, false );
}

// ---------------------------------------------------------------------
// 6. Author-mix-up distractors: with 2+ authors, the surname/given-name
// distractor sometimes attributes the OTHER author's real surname/given
// name to the drawn author's position — a genuine "which author does this
// belong to" test, swept across many seeds.
// ---------------------------------------------------------------------
$other_author_surname_seen    = false;
$other_author_given_name_seen = false;
for ( $i = 1; $i <= 30; $i++ ) {
	$mix_fields = array( 'year' => (string) ( 2000 + $i ), 'title' => 'Book title ' . $i, 'place' => 'London', 'publisher' => 'Routledge' );
	$mix_built  = Citex_Chicago_Book_Dragdrop_Parts::build( array( 'author_0_surname', 'author_0_givenname' ), $three_named, $mix_fields );
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
check( '[7] an out-of-range author index returns null', Citex_Chicago_Book_Dragdrop_Parts::build( array( 'author_5_surname' ), $single_author, $fields ), null );
check( '[7] an empty selection returns null', Citex_Chicago_Book_Dragdrop_Parts::build( array(), $single_author, $fields ), null );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
