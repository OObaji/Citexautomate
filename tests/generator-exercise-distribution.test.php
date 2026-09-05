<?php
/**
 * Regression tests for Citex_Generator's Exercise-assignment matrix, added
 * after a real production complaint: generated questions kept concentrating
 * in one exercise instead of spreading across the required Book coverage
 * matrix (Exercise 1-5 x {DragDrop, MCQ}).
 *
 * Citex_Generator::build_exercise_assignments() now assigns every
 * generation slot's Exercise deterministically, BEFORE any Gemini request
 * is made, by greedily filling whichever exercise currently has the fewest
 * questions of the requested type — combining already-populated coverage
 * (Citex_Populator's own persistent count) with not-yet-populated pending
 * questions, so a new batch does not pile more questions onto an
 * already-covered slot while leaving another one empty. Gemini's response
 * schema carries no exercise field at all, so nothing from Gemini is ever
 * consulted for this — the matrix is built first and handed to Gemini only
 * as a fully Citex-owned assignment.
 *
 * MCQ generation itself is not implemented (Citex does not have the real
 * MCQ ACF structure — see class-citex-populator.php), so these tests cover
 * the assignment MECHANISM for both types (it is written to be
 * type-generic and already produces a correct MCQ-side matrix), without
 * claiming Citex currently generates MCQ questions end-to-end.
 *
 * Repo-level only, run with plain
 * `php tests/generator-exercise-distribution.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

$GLOBALS['__options'] = array();
function get_option( $key, $default = false ) {
	return $GLOBALS['__options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}

require __DIR__ . '/../citex-tools/includes/class-citex-populator.php';
require __DIR__ . '/../citex-tools/includes/class-citex-generator.php';

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

function reset_environment() {
	$GLOBALS['__options'] = array();
}

function count_by_exercise( array $assignments ) {
	$counts = array_fill_keys( Citex_Generator::EXERCISES, 0 );
	foreach ( $assignments as $exercise ) {
		$counts[ $exercise ] = ( $counts[ $exercise ] ?? 0 ) + 1;
	}
	return $counts;
}

// ---------------------------------------------------------------------
// 9. No exercise is left empty in a complete (one-per-exercise) batch:
// quantity 5 for one type gives every exercise exactly one.
// ---------------------------------------------------------------------
reset_environment();
$assignments_5 = Citex_Generator::build_exercise_assignments( 'Book', 'DragDrop', 5 );
check( '[9] 5 slots are assigned', count( $assignments_5 ), 5 );
check( '[9] every exercise receives exactly one DragDrop slot — none left empty', count_by_exercise( $assignments_5 ), array_fill_keys( Citex_Generator::EXERCISES, 1 ) );

// ---------------------------------------------------------------------
// 7. A 10-slot batch distributes evenly: 2 per exercise for one type
// (the DragDrop half of the full 5 x {MCQ, DragDrop} matrix — MCQ
// generation itself is not implemented, but the same mechanism, run for
// type 'MCQ', already produces the matching MCQ-side matrix below).
// ---------------------------------------------------------------------
reset_environment();
$assignments_10 = Citex_Generator::build_exercise_assignments( 'Book', 'DragDrop', 10 );
check( '[7] 10 slots are assigned', count( $assignments_10 ), 10 );
check( '[7] every exercise receives exactly 2 DragDrop slots', count_by_exercise( $assignments_10 ), array_fill_keys( Citex_Generator::EXERCISES, 2 ) );

// ---------------------------------------------------------------------
// 10. The same mechanism, requested for type MCQ instead, produces the
// matching balanced matrix — proving no type is left out of the
// assignment mechanism itself (full end-to-end MCQ generation is a
// separate, currently-unimplemented concern).
// ---------------------------------------------------------------------
reset_environment();
$assignments_mcq = Citex_Generator::build_exercise_assignments( 'Book', 'MCQ', 5 );
check( '[10] every exercise receives exactly one MCQ slot in the MCQ-side matrix', count_by_exercise( $assignments_mcq ), array_fill_keys( Citex_Generator::EXERCISES, 1 ) );

// A full 5 exercises x {DragDrop, MCQ} = 10 question matrix, assembled
// from both per-type assignment lists, covers every required combination
// with none missing and none duplicated.
$combined = array();
foreach ( $assignments_5 as $exercise ) {
	$combined[] = $exercise . '|DragDrop';
}
foreach ( $assignments_mcq as $exercise ) {
	$combined[] = $exercise . '|MCQ';
}
sort( $combined );
$expected_matrix = array();
foreach ( Citex_Generator::EXERCISES as $exercise ) {
	$expected_matrix[] = $exercise . '|DragDrop';
	$expected_matrix[] = $exercise . '|MCQ';
}
sort( $expected_matrix );
check( '[7,9,10] the combined 10-slot matrix covers every Exercise x Type combination exactly once', $combined, $expected_matrix );

// ---------------------------------------------------------------------
// 8. Existing covered slots are not unnecessarily regenerated: Exercise 1
// already has 2 DragDrop questions populated; a further batch of 3 must
// prioritise the still-empty exercises (2, 3, 4) over piling more onto
// Exercise 1.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__options'][ Citex_Populator::OPTION_COVERAGE ] = array(
	'Book' => array( 'Exercise 1' => array( 'DragDrop' => 2 ) ),
);
$assignments_topup = Citex_Generator::build_exercise_assignments( 'Book', 'DragDrop', 3 );
check( '[8] Exercise 1 (already covered) receives none of the next 3 slots', in_array( 'Exercise 1', $assignments_topup, true ), false );
check( '[8] the 3 slots go to the least-covered exercises (2, 3 and 4, in order)', $assignments_topup, array( 'Exercise 2', 'Exercise 3', 'Exercise 4' ) );

// ---------------------------------------------------------------------
// compute_category_coverage() correctly combines persisted (populated)
// coverage with not-yet-populated pending questions.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__options'][ Citex_Populator::OPTION_COVERAGE ] = array(
	'Book' => array( 'Exercise 1' => array( 'DragDrop' => 1 ) ),
);
$GLOBALS['__options'][ Citex_Generator::OPTION_PENDING ] = array(
	array( 'category' => 'Book', 'exercise' => 'Exercise 2', 'type' => 'DragDrop' ),
	array( 'category' => 'Book', 'exercise' => 'Exercise 2', 'type' => 'DragDrop' ),
	array( 'category' => 'Website', 'exercise' => 'Exercise 1', 'type' => 'DragDrop' ), // different category, must not count
);
$coverage = Citex_Generator::compute_category_coverage( 'Book' );
check( '[coverage] populated Exercise 1 DragDrop count is included', $coverage['Exercise 1']['DragDrop'], 1 );
check( '[coverage] pending Exercise 2 DragDrop questions are counted (not yet populated, but already accounted for)', $coverage['Exercise 2']['DragDrop'], 2 );
check( '[coverage] a different category\'s pending question is not counted', $coverage['Exercise 1']['MCQ'], 0 );
check( '[coverage] every exercise is present even with zero coverage', array_keys( $coverage ), Citex_Generator::EXERCISES );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
