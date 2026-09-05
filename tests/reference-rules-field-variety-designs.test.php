<?php
/**
 * Regression tests for Book/Edited Book's new DragDrop "exercise design"
 * variety — swapping year for place or publisher so a generated batch does
 * not test the exact same 3-4 fields every question (per the user's own
 * request: "test either the city or the publisher name... don't do that in
 * every question... don't test the year in every question"). Pure, no
 * WordPress/ACF dependency — mirrors reference-rules-book-authors.test.php's
 * own no-stub-needed setup.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-field-variety-designs.test.php` — not shipped
 * in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';

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

function author( $surname, $initials ) {
	return array( 'surname' => $surname, 'initials' => $initials );
}

// The reconstructed reference from a shape must exactly match
// build_reference()'s own output — DragDrop and the correct MCQ answer can
// never silently disagree, for ANY design.
function reconstruct_from_shape( $shape ) {
	$fixed = $shape['fixedText'];
	$parts = $shape['parts'];
	$reference = '';
	$part_index = 0;
	$length = strlen( $fixed );
	for ( $i = 0; $i < $length; $i++ ) {
		if ( '|' !== $fixed[ $i ] ) {
			$reference .= $fixed[ $i ];
			continue;
		}
		if ( $i + 1 < $length && '|' === $fixed[ $i + 1 ] ) {
			$reference .= (string) $parts[ $part_index++ ];
			$i++;
			continue;
		}
		$reference .= (string) $parts[ $part_index++ ];
	}
	return trim( $reference );
}

$one_author  = array( author( 'Vance', 'C.' ) );
$two_authors = array( author( 'Vance', 'C.' ), author( 'Shaw', 'D.' ) );
$book_fields_one  = array( 'authors' => $one_author, 'year' => '2019', 'title' => 'Urban Ecology', 'place' => 'Cambridge', 'publisher' => 'Polity' );
$book_fields_two  = array( 'authors' => $two_authors, 'year' => '2019', 'title' => 'Urban Ecology', 'place' => 'Cambridge', 'publisher' => 'Polity' );

// ---------------------------------------------------------------------
// 1. Every declared Book design produces exactly 3 or 4 parts, matching
// placeholder count, and reconstructs to the exact same reference
// regardless of which fields are draggable vs. baked.
// ---------------------------------------------------------------------
$expected_book_reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_BOOK, $book_fields_two );
foreach ( Citex_Reference_Rules::book_dragdrop_designs() as $design ) {
	$shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_BOOK, $book_fields_two, $design );
	$slot_count = substr_count( $shape['fixedText'], '||' ) + ( 1 === preg_match( '/(?<!\|)\|(?!\|)/', $shape['fixedText'] ) ? 1 : 0 );
	check( "[1] Book design \"$design\" (2 authors) produces exactly count(parts) placeholders", $slot_count, count( $shape['parts'] ) );
	check( "[1] Book design \"$design\" (2 authors) produces 3 or 4 parts", count( $shape['parts'] ) >= 3 && count( $shape['parts'] ) <= 4, true );
	check( "[1] Book design \"$design\" (2 authors) reconstructs to the exact same reference as the baseline", reconstruct_from_shape( $shape ), $expected_book_reference );
}

// ---------------------------------------------------------------------
// 2. Exact shapes for each Book variety design (1 author, so the person
// chip is a single combined "Surname, I." part, unlike the baseline's own
// 1-author split-surname/initials shape).
// ---------------------------------------------------------------------
check(
	'[2] author_title_place: year baked, place drawn, publisher baked',
	Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_BOOK, $book_fields_one, 'author_title_place' ),
	array( 'parts' => array( 'Vance, C.', 'Urban Ecology', 'Cambridge' ), 'fixedText' => '| (2019) ||. ||: Polity.' )
);
check(
	'[2] author_title_publisher: year baked, publisher drawn, place baked',
	Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_BOOK, $book_fields_one, 'author_title_publisher' ),
	array( 'parts' => array( 'Vance, C.', 'Urban Ecology', 'Polity' ), 'fixedText' => '| (2019) ||. Cambridge: ||.' )
);
check(
	'[2] author_year_title_place: year AND place drawn, publisher baked',
	Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_BOOK, $book_fields_one, 'author_year_title_place' ),
	array( 'parts' => array( 'Vance, C.', '2019', 'Urban Ecology', 'Cambridge' ), 'fixedText' => '| (||) ||. ||: Polity.' )
);
check(
	'[2] author_year_title_publisher: year AND publisher drawn, place baked',
	Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_BOOK, $book_fields_one, 'author_year_title_publisher' ),
	array( 'parts' => array( 'Vance, C.', '2019', 'Urban Ecology', 'Polity' ), 'fixedText' => '| (||) ||. Cambridge: ||.' )
);

// A 2nd author still folds into fixedText as a correct literal
// continuation for every variety design, exactly like the baseline.
check(
	'[2] author_title_place with 2 authors folds the 2nd author in as a literal continuation',
	Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_BOOK, $book_fields_two, 'author_title_place' ),
	array( 'parts' => array( 'Vance, C.', 'Urban Ecology', 'Cambridge' ), 'fixedText' => '| and Shaw, D. (2019) ||. ||: Polity.' )
);

// ---------------------------------------------------------------------
// 3. book_dragdrop_design_for() always returns a real, known design id,
// is reproducible for the same seed, and (property test) produces more
// than one distinct design across many different seeds — proving it is
// not silently hardcoded to the baseline.
// ---------------------------------------------------------------------
$known_book_designs = Citex_Reference_Rules::book_dragdrop_designs();
$seen_book_designs   = array();
for ( $i = 1; $i <= 50; $i++ ) {
	$design = Citex_Reference_Rules::book_dragdrop_design_for( 'BK' . str_pad( $i, 2, '0', STR_PAD_LEFT ) );
	if ( ! in_array( $design, $known_book_designs, true ) ) {
		check( "[3] book_dragdrop_design_for() seed BK$i returns a known design id", $design, '(unknown)' );
	}
	$seen_book_designs[ $design ] = true;
}
check( '[3] book_dragdrop_design_for() picks more than one distinct design across 50 different seeds', count( $seen_book_designs ) > 1, true );
check(
	'[3] book_dragdrop_design_for() is reproducible for the same seed',
	Citex_Reference_Rules::book_dragdrop_design_for( 'BK07' ),
	Citex_Reference_Rules::book_dragdrop_design_for( 'BK07' )
);

// ---------------------------------------------------------------------
// 4. Edited Book: same coverage — designation is always drawn in every
// design (this category's own defining rule never gets traded away).
// ---------------------------------------------------------------------
$one_editor = array( author( 'Vance', 'C.' ) );
$eb_fields_one = array( 'editors' => $one_editor, 'year' => '2019', 'title' => 'Urban Ecology', 'place' => 'Cambridge', 'publisher' => 'Polity' );
$eb_fields_two = array( 'editors' => array( author( 'Vance', 'C.' ), author( 'Shaw', 'D.' ) ), 'year' => '2019', 'title' => 'Urban Ecology', 'place' => 'Cambridge', 'publisher' => 'Polity' );

$expected_eb_reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $eb_fields_two );
foreach ( Citex_Reference_Rules::edited_book_dragdrop_designs() as $design ) {
	$shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $eb_fields_two, $design );
	check( "[4] Edited Book design \"$design\" (2 editors) produces exactly 4 parts", count( $shape['parts'] ), 4 );
	check( "[4] Edited Book design \"$design\" (2 editors) reconstructs to the exact same reference as the baseline", reconstruct_from_shape( $shape ), $expected_eb_reference );
	check( "[4] Edited Book design \"$design\" always draws the designation as its own part", in_array( 'eds', $shape['parts'], true ), true );
}

check(
	'[4] editor_designation_title_place (1 editor): year baked, place drawn, publisher baked',
	Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $eb_fields_one, 'editor_designation_title_place' ),
	array( 'parts' => array( 'Vance, C.', 'ed.', 'Urban Ecology', 'Cambridge' ), 'fixedText' => '| (||) (2019) ||. ||: Polity.' )
);
check(
	'[4] editor_designation_title_publisher (1 editor): year baked, publisher drawn, place baked',
	Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $eb_fields_one, 'editor_designation_title_publisher' ),
	array( 'parts' => array( 'Vance, C.', 'ed.', 'Urban Ecology', 'Polity' ), 'fixedText' => '| (||) (2019) ||. Cambridge: ||.' )
);

$known_eb_designs = Citex_Reference_Rules::edited_book_dragdrop_designs();
$seen_eb_designs  = array();
for ( $i = 1; $i <= 50; $i++ ) {
	$design = Citex_Reference_Rules::edited_book_dragdrop_design_for( 'EB' . str_pad( $i, 2, '0', STR_PAD_LEFT ) );
	if ( ! in_array( $design, $known_eb_designs, true ) ) {
		check( "[4] edited_book_dragdrop_design_for() seed EB$i returns a known design id", $design, '(unknown)' );
	}
	$seen_eb_designs[ $design ] = true;
}
check( '[4] edited_book_dragdrop_design_for() picks more than one distinct design across 50 different seeds', count( $seen_eb_designs ) > 1, true );

// ---------------------------------------------------------------------
// 5. An unrecognised/omitted design always falls back to the exact
// original baseline shape — the whole point of keeping every existing
// caller (including every pre-existing test) fully unaffected.
// ---------------------------------------------------------------------
check(
	'[5] Book: no design argument at all matches the pre-existing baseline exactly',
	Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_BOOK, $book_fields_one ),
	array( 'parts' => array( 'Vance', 'C.', '2019', 'Urban Ecology' ), 'fixedText' => '|, || (||) ||. Cambridge: Polity.' )
);
check(
	'[5] Book: "full_reference" (Journal Article/Website\'s own sentinel) is treated as baseline too, not a crash',
	Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_BOOK, $book_fields_one, 'full_reference' ),
	array( 'parts' => array( 'Vance', 'C.', '2019', 'Urban Ecology' ), 'fixedText' => '|, || (||) ||. Cambridge: Polity.' )
);
check(
	'[5] Edited Book: no design argument at all matches the pre-existing baseline exactly',
	Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $eb_fields_one ),
	array( 'parts' => array( 'Vance, C.', 'ed.', '2019', 'Urban Ecology' ), 'fixedText' => '| (||) (||) ||. Cambridge: Polity.' )
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
