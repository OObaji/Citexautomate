<?php
/**
 * Regression tests for Citex_Generator::split_evenly() — the shared
 * near-equal integer split used by every even-split feature in the
 * generator: DragDrop/MCQ (generate_mixed_batch()), Citation Form
 * (generate_for_type()), and Question Focus x Category (
 * handle_bulk_generation(), the "Bulk Generate" feature — a reported
 * request: "Harvard, 500" should generate roughly equal shares of
 * Reference List/In-Text Citation x Book/Edited Book/Journal Article/
 * Website, not 500 of any single combination).
 *
 * Repo-level only, run with plain
 * `php tests/generator-split-evenly.test.php` — not shipped in
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

// ---------------------------------------------------------------------
// A quantity that divides evenly.
// ---------------------------------------------------------------------
check( 'a total that divides evenly by 2 splits exactly', Citex_Generator::split_evenly( 10, 2 ), array( 5, 5 ) );
check( 'a total that divides evenly by 4 splits exactly', Citex_Generator::split_evenly( 8, 4 ), array( 2, 2, 2, 2 ) );

// ---------------------------------------------------------------------
// Odd remainders go to the FIRST buckets, in order — matches
// handle_mixed_generation()'s own documented "DragDrop gets the extra
// question on an odd total" behaviour (DragDrop is always bucket 0
// there).
// ---------------------------------------------------------------------
check( 'an odd total of 11 across 2 buckets gives the first bucket the extra one', Citex_Generator::split_evenly( 11, 2 ), array( 6, 5 ) );
check( 'a total of 10 across 3 buckets (Citation Form) gives the first bucket the extra one', Citex_Generator::split_evenly( 10, 3 ), array( 4, 3, 3 ) );
check( 'a total of 500 across 8 buckets (Bulk Generate: 2 Question Focus x 4 Category)', Citex_Generator::split_evenly( 500, 8 ), array( 63, 63, 63, 63, 62, 62, 62, 62 ) );
check( 'a total of 500 across 4 buckets (Bulk Generate, Chicago/MHRA: Reference List only)', Citex_Generator::split_evenly( 500, 4 ), array( 125, 125, 125, 125 ) );

// ---------------------------------------------------------------------
// Edge cases.
// ---------------------------------------------------------------------
check( 'a total smaller than the bucket count still assigns 1 to the first N buckets, 0 to the rest', Citex_Generator::split_evenly( 3, 8 ), array( 1, 1, 1, 0, 0, 0, 0, 0 ) );
check( 'a total of 0 gives every bucket 0', Citex_Generator::split_evenly( 0, 4 ), array( 0, 0, 0, 0 ) );
check( 'a bucket count of 0 returns an empty array', Citex_Generator::split_evenly( 100, 0 ), array() );
check( 'every bucket sums back to the original total (500 across 8)', array_sum( Citex_Generator::split_evenly( 500, 8 ) ), 500 );
check( 'every bucket sums back to the original total (127 across 5)', array_sum( Citex_Generator::split_evenly( 127, 5 ) ), 127 );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
