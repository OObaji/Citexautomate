<?php
/**
 * Regression tests for Edited Book's DragDrop "exercise design" variety —
 * every design produces EXACTLY 3 draggable parts (the plugin-wide "every
 * DragDrop question has exactly 3 parts" rule): the editor(s) (as one
 * joined chip, or split into surname/initials for editor_split_designation),
 * the designation (always drawn, never traded away), and exactly ONE
 * further fact rotating across the 5 designs (year, title, place,
 * publisher — or nothing further for editor_split_designation, which
 * spends its 3rd slot on the split initials instead) — per the user's own
 * request: "test either the city or the publisher name... don't do that in
 * every question... don't test the year in every question".
 *
 * Book's own equivalent coverage was removed along with its fixed 8-design
 * catalogue — see tests/reference-rules-book-dragdrop-parts.test.php for
 * Citex_Book_Dragdrop_Parts's dedicated coverage of the dynamic, per-question
 * mechanism that replaced it.
 *
 * Pure, no WordPress/ACF dependency — mirrors
 * reference-rules-book-authors.test.php's own no-stub-needed setup.
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

// dragdrop_shape() now also returns 'confusingWords' (Citex-authored
// distractors — see tests/reference-rules-distractors.test.php for
// dedicated coverage of those); this file only ever asserted 'parts'/
// 'fixedText', so every exact-shape check below compares just that subset.
function shape_subset( $shape ) {
	return array( 'parts' => $shape['parts'], 'fixedText' => $shape['fixedText'] );
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

// ---------------------------------------------------------------------
// 1. Edited Book: every declared design produces exactly 3 parts, matching
// placeholder count, reconstructs to the exact same reference regardless
// of which fields are draggable vs. baked, and always draws the
// designation as its own part (this category's own defining rule never
// gets traded away).
// ---------------------------------------------------------------------
$one_editor = array( author( 'Vance', 'C.' ) );
$eb_fields_one = array( 'editors' => $one_editor, 'year' => '2019', 'title' => 'Urban Ecology', 'place' => 'Cambridge', 'publisher' => 'Polity' );
$eb_fields_two = array( 'editors' => array( author( 'Vance', 'C.' ), author( 'Shaw', 'D.' ) ), 'year' => '2019', 'title' => 'Urban Ecology', 'place' => 'Cambridge', 'publisher' => 'Polity' );

$expected_eb_reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $eb_fields_two );
foreach ( Citex_Reference_Rules::edited_book_dragdrop_designs() as $design ) {
	$shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $eb_fields_two, $design );
	check( "[1] Edited Book design \"$design\" (2 editors) produces exactly 3 parts", count( $shape['parts'] ), 3 );
	check( "[1] Edited Book design \"$design\" (2 editors) reconstructs to the exact same reference as the baseline", reconstruct_from_shape( $shape ), $expected_eb_reference );
	check( "[1] Edited Book design \"$design\" always draws the designation as its own part", in_array( 'eds', $shape['parts'], true ), true );
}

check(
	'[1] editor_designation_year (1 editor, baseline): title/place/publisher baked, year drawn',
	shape_subset( Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $eb_fields_one, 'editor_designation_year' ) ),
	array( 'parts' => array( 'Vance, C.', 'ed.', '2019' ), 'fixedText' => '| (||) (||) Urban Ecology. Cambridge: Polity.' )
);
check(
	'[1] editor_designation_title (1 editor): year baked, title drawn, place/publisher baked',
	shape_subset( Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $eb_fields_one, 'editor_designation_title' ) ),
	array( 'parts' => array( 'Vance, C.', 'ed.', 'Urban Ecology' ), 'fixedText' => '| (||) (2019) ||. Cambridge: Polity.' )
);
check(
	'[1] editor_designation_place (1 editor): year/title baked, place drawn, publisher baked',
	shape_subset( Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $eb_fields_one, 'editor_designation_place' ) ),
	array( 'parts' => array( 'Vance, C.', 'ed.', 'Cambridge' ), 'fixedText' => '| (||) (2019) Urban Ecology. ||: Polity.' )
);
check(
	'[1] editor_designation_publisher (1 editor): year/title/place baked, publisher drawn',
	shape_subset( Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $eb_fields_one, 'editor_designation_publisher' ) ),
	array( 'parts' => array( 'Vance, C.', 'ed.', 'Polity' ), 'fixedText' => '| (||) (2019) Urban Ecology. Cambridge: ||.' )
);
check(
	'[1] editor_split_designation (1 editor): surname/initials split, designation always drawn, year/title/place/publisher baked',
	shape_subset( Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $eb_fields_one, 'editor_split_designation' ) ),
	array( 'parts' => array( 'Vance', 'C.', 'ed.' ), 'fixedText' => '|, || (||) (2019) Urban Ecology. Cambridge: Polity.' )
);
check(
	'[1] editor_split_designation (2 editors): first editor split, 2nd folds in as a literal continuation, designation still for the whole pair',
	shape_subset( Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $eb_fields_two, 'editor_split_designation' ) ),
	array( 'parts' => array( 'Vance', 'C.', 'eds' ), 'fixedText' => '|, || and Shaw, D. (||) (2019) Urban Ecology. Cambridge: Polity.' )
);

// ---------------------------------------------------------------------
// 2. edited_book_dragdrop_design_for() always returns a real, known design
// id, and (property test) produces more than one distinct design across
// many different seeds — proving it is not silently hardcoded to the
// baseline.
// ---------------------------------------------------------------------
$known_eb_designs = Citex_Reference_Rules::edited_book_dragdrop_designs();
$seen_eb_designs  = array();
for ( $i = 1; $i <= 50; $i++ ) {
	$design = Citex_Reference_Rules::edited_book_dragdrop_design_for( 'EB' . str_pad( $i, 2, '0', STR_PAD_LEFT ) );
	if ( ! in_array( $design, $known_eb_designs, true ) ) {
		check( "[2] edited_book_dragdrop_design_for() seed EB$i returns a known design id", $design, '(unknown)' );
	}
	$seen_eb_designs[ $design ] = true;
}
check( '[2] edited_book_dragdrop_design_for() picks more than one distinct design across 50 different seeds', count( $seen_eb_designs ) > 1, true );

// ---------------------------------------------------------------------
// 3. An unrecognised/omitted design always falls back to the exact
// original baseline shape — the whole point of keeping every existing
// caller (including every pre-existing test) fully unaffected.
// ---------------------------------------------------------------------
check(
	'[3] Edited Book: no design argument at all matches the pre-existing baseline exactly',
	shape_subset( Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $eb_fields_one ) ),
	array( 'parts' => array( 'Vance, C.', 'ed.', '2019' ), 'fixedText' => '| (||) (||) Urban Ecology. Cambridge: Polity.' )
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
