<?php
/**
 * Regression tests for Citex_Book_Dragdrop_Parts — the dynamic 2-4-part
 * Book DragDrop question builder that replaced the fixed 8-design catalogue
 * (Citex_Reference_Rules::book_dragdrop_designs() and friends, now removed).
 * Pure, no WordPress/ACF dependency — mirrors
 * tests/reference-rules-book-authors.test.php's own no-stub-needed setup.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-book-dragdrop-parts.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-dragdrop-parts.php';

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

function author( $surname, $initials, $full ) {
	return array( 'surname' => $surname, 'initials' => $initials, 'fullName' => $full );
}

// Reconstructs the full reference from {parts, fixedText} by substituting
// each "||" placeholder in order — the same mechanism
// Citex_Generated_Validator::reconstruct() uses in production.
function reconstruct( $built ) {
	$fixed = $built['fixedText'];
	$parts = $built['parts'];
	$out   = '';
	$index = 0;
	$len   = strlen( $fixed );
	for ( $i = 0; $i < $len; $i++ ) {
		if ( '|' === $fixed[ $i ] && $i + 1 < $len && '|' === $fixed[ $i + 1 ] ) {
			$out .= (string) $parts[ $index++ ];
			$i++;
			continue;
		}
		$out .= $fixed[ $i ];
	}
	return $out;
}

$fields = array( 'year' => '2019', 'title' => 'Digital Media', 'place' => 'London', 'publisher' => 'Routledge' );

// ---------------------------------------------------------------------
// 1. build_tokens(): the full token stream for 1/2/3-author records
// reconstructs (when every candidate is treated as literal) to exactly
// build_reference()'s own output.
// ---------------------------------------------------------------------
function all_literal_reconstruction( array $tokens ) {
	$out = '';
	foreach ( $tokens as $token ) {
		$out .= $token['value'];
	}
	return $out;
}
foreach ( array( 1, 2, 3, 4, 6 ) as $count ) {
	$pool = array(
		author( 'Clark', 'S.', 'Simon Clark' ),
		author( 'Davies', 'H.', 'Helen Davies' ),
		author( 'Wilson', 'M.', 'Mark Wilson' ),
		author( 'Brown', 'A.', 'Andrew Brown' ),
		author( 'Smith', 'J.', 'James Smith' ),
		author( 'Jones', 'A.', 'Amy Jones' ),
	);
	$authors = array_slice( $pool, 0, $count );
	$tokens  = Citex_Book_Dragdrop_Parts::build_tokens( $authors, $fields, 0 );
	$expected = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_BOOK, array_merge( $fields, array( 'authors' => $authors ) ) );
	check( "[1] build_tokens() for $count author(s), all-literal, reconstructs to build_reference()'s output", all_literal_reconstruction( $tokens ), $expected );
}

// ---------------------------------------------------------------------
// 2. select_parts(): reproducible for the same seed, and the drawn author
// index (if any) is always within range.
// ---------------------------------------------------------------------
$three_authors = array( author( 'Carter', 'J.', 'John Carter' ), author( 'Green', 'E.', 'Emma Green' ), author( 'Smith', 'D.', 'David Smith' ) );
check(
	'[2] select_parts() is reproducible for the same seed',
	Citex_Book_Dragdrop_Parts::select_parts( 'BK07', $three_authors ),
	Citex_Book_Dragdrop_Parts::select_parts( 'BK07', $three_authors )
);
for ( $i = 1; $i <= 40; $i++ ) {
	$seed = 'BK' . str_pad( $i, 2, '0', STR_PAD_LEFT );
	$keys = Citex_Book_Dragdrop_Parts::select_parts( $seed, $three_authors );
	foreach ( $keys as $key ) {
		if ( 1 === preg_match( '/^author_(\d+)_(?:surname|initials)$/', $key, $m ) ) {
			if ( (int) $m[1] >= count( $three_authors ) ) {
				check( "[2] $seed: drawn author index is within range", (int) $m[1] < count( $three_authors ), true );
			}
		}
	}
}

// ---------------------------------------------------------------------
// 3. select_parts(): across a wide seed sweep — part count always 2-4,
// content floor always satisfied (at least one of author/year/title/
// place/publisher is drawn), and "and" is never eligible/selected for a
// single author.
// ---------------------------------------------------------------------
function has_content( array $keys ) {
	foreach ( $keys as $key ) {
		if ( in_array( $key, array( 'year', 'title', 'place', 'publisher' ), true ) ) {
			return true;
		}
		if ( 1 === preg_match( '/^author_\d+_(?:surname|initials)$/', $key ) ) {
			return true;
		}
	}
	return false;
}
$single_author = array( author( 'Brown', 'A.', 'Andrew Brown' ) );
$and_ever_seen_single = false;
$count_within_bounds  = true;
$content_floor_ok     = true;
for ( $i = 1; $i <= 60; $i++ ) {
	$seed  = 'S' . $i;
	$keys  = Citex_Book_Dragdrop_Parts::select_parts( $seed, $single_author );
	$built = Citex_Book_Dragdrop_Parts::build( $keys, $single_author, $fields );
	$part_count = count( $built['parts'] );
	if ( $part_count < 2 || $part_count > 4 ) {
		$count_within_bounds = false;
	}
	if ( ! has_content( $keys ) ) {
		$content_floor_ok = false;
	}
	if ( in_array( 'and', $keys, true ) ) {
		$and_ever_seen_single = true;
	}
}
check( '[3] single-author: part count always within 2-4 across 60 seeds', $count_within_bounds, true );
check( '[3] single-author: content floor always satisfied across 60 seeds', $content_floor_ok, true );
check( '[3] single-author: "and" is never selected (not eligible with only 1 author)', $and_ever_seen_single, false );

$and_ever_seen_multi = false;
for ( $i = 1; $i <= 60; $i++ ) {
	$seed = 'M' . $i;
	$keys = Citex_Book_Dragdrop_Parts::select_parts( $seed, $three_authors );
	if ( in_array( 'and', $keys, true ) ) {
		$and_ever_seen_multi = true;
	}
}
check( '[3] multi-author (3): "and" is selected at least once across 60 seeds', $and_ever_seen_multi, true );

// ---------------------------------------------------------------------
// 4. build(): exact output for hand-picked key sets, verified against the
// user's own literal example (Clark, S., Davies, H. and Wilson, M. (2019)
// Digital Media. London: Routledge.) — testing every author drawable in
// turn, and every structural/punctuation candidate.
// ---------------------------------------------------------------------
$three_named = array( author( 'Clark', 'S.', 'Simon Clark' ), author( 'Davies', 'H.', 'Helen Davies' ), author( 'Wilson', 'M.', 'Mark Wilson' ) );
$digital_media_fields = array( 'year' => '2019', 'title' => 'Digital Media', 'place' => 'London', 'publisher' => 'Routledge' );

$built_author0 = Citex_Book_Dragdrop_Parts::build( array( 'author_0_surname', 'author_0_initials', 'year', 'title' ), $three_named, $digital_media_fields );
check( '[4] drawing author 0 (Clark): parts', $built_author0['parts'], array( 'Clark', 'S.', '2019', 'Digital Media' ) );
check( '[4] drawing author 0 (Clark): fixedText', $built_author0['fixedText'], '||, ||, Davies, H. and Wilson, M. (||) ||. London: Routledge.' );
check( '[4] drawing author 0 (Clark): reconstructs to the full reference', reconstruct( $built_author0 ), 'Clark, S., Davies, H. and Wilson, M. (2019) Digital Media. London: Routledge.' );

$built_author1 = Citex_Book_Dragdrop_Parts::build( array( 'author_1_surname', 'author_1_initials' ), $three_named, $digital_media_fields );
check( '[4] drawing author 1 (Davies): parts', $built_author1['parts'], array( 'Davies', 'H.' ) );
check( '[4] drawing author 1 (Davies): fixedText', $built_author1['fixedText'], 'Clark, S., ||, || and Wilson, M. (2019) Digital Media. London: Routledge.' );
check( '[4] drawing author 1 (Davies): reconstructs to the full reference', reconstruct( $built_author1 ), 'Clark, S., Davies, H. and Wilson, M. (2019) Digital Media. London: Routledge.' );

$built_author2 = Citex_Book_Dragdrop_Parts::build( array( 'author_2_surname', 'author_2_initials' ), $three_named, $digital_media_fields );
check( '[4] drawing author 2 (Wilson): parts', $built_author2['parts'], array( 'Wilson', 'M.' ) );
check( '[4] drawing author 2 (Wilson): fixedText', $built_author2['fixedText'], 'Clark, S., Davies, H. and ||, || (2019) Digital Media. London: Routledge.' );

$built_and = Citex_Book_Dragdrop_Parts::build( array( 'and' ), $three_named, $digital_media_fields );
check( '[4] drawing "and": parts', $built_and['parts'], array( 'and' ) );
check( '[4] drawing "and": confusingWords is "&"', $built_and['confusingWords'], array( '&' ) );
check( '[4] drawing "and": fixedText', $built_and['fixedText'], 'Clark, S., Davies, H. || Wilson, M. (2019) Digital Media. London: Routledge.' );
check( '[4] drawing "and": reconstructs to the full reference', reconstruct( $built_and ), 'Clark, S., Davies, H. and Wilson, M. (2019) Digital Media. London: Routledge.' );

$built_punct = Citex_Book_Dragdrop_Parts::build( array( 'paren_open', 'paren_close', 'colon' ), $three_named, $digital_media_fields );
check( '[4] drawing punctuation: parts', $built_punct['parts'], array( '(', ')', ':' ) );
check( '[4] drawing punctuation: confusingWords', $built_punct['confusingWords'], array( '[', ']', ';' ) );
check( '[4] drawing punctuation: reconstructs to the full reference', reconstruct( $built_punct ), 'Clark, S., Davies, H. and Wilson, M. (2019) Digital Media. London: Routledge.' );

// ---------------------------------------------------------------------
// 5. Distractor rules: never equal to the correct value, for every kind.
// ---------------------------------------------------------------------
$all_keys_single = array( 'author_0_surname', 'author_0_initials', 'year', 'title', 'place', 'publisher', 'paren_open', 'paren_close', 'colon' );
$built_all = Citex_Book_Dragdrop_Parts::build( $all_keys_single, $single_author, $fields );
foreach ( $built_all['parts'] as $index => $part ) {
	check( "[5] distractor for part $index (\"$part\") is never equal to the correct value", $built_all['confusingWords'][ $index ] === $part, false );
}

// ---------------------------------------------------------------------
// 6. build() returns null for an out-of-range author index, or an empty
// selection — the exact signal Citex_Generated_Validator's Book-only
// block treats as BOOK_DRAGDROP_PARTS_UNKNOWN.
// ---------------------------------------------------------------------
check( '[6] an out-of-range author index returns null', Citex_Book_Dragdrop_Parts::build( array( 'author_5_surname' ), $single_author, $fields ), null );
check( '[6] an empty selection returns null', Citex_Book_Dragdrop_Parts::build( array(), $single_author, $fields ), null );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
