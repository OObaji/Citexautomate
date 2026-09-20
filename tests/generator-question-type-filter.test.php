<?php
/**
 * Regression tests for Citex_Generator::resolve_mixed_quantities() — the
 * [DragDrop quantity, MCQ quantity] split generate_mixed_batch() uses for
 * one call, including the "Question Type" field's testing-only override
 * (see admin/views/generate.php and handle_generation()'s own docblock —
 * a reported request: being able to generate DragDrop-only or MCQ-only
 * questions for testing, without the other half spending API calls and
 * Pending slots it doesn't need).
 *
 * Repo-level only, run with plain
 * `php tests/generator-question-type-filter.test.php` — not shipped in
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

function invoke_resolve_mixed_quantities( $quantity, $type_filter ) {
	$reflection = new ReflectionMethod( 'Citex_Generator', 'resolve_mixed_quantities' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $quantity, $type_filter );
}

// ---------------------------------------------------------------------
// Default ('mixed'): the pre-existing even DragDrop/MCQ split, DragDrop
// getting the extra question on an odd total — unchanged by this feature.
// ---------------------------------------------------------------------
check( '[1] mixed: an even total of 20 splits 10/10', invoke_resolve_mixed_quantities( 20, 'mixed' ), array( 10, 10 ) );
check( '[1] mixed: an odd total of 11 gives DragDrop the extra one', invoke_resolve_mixed_quantities( 11, 'mixed' ), array( 6, 5 ) );
check( '[1] mixed is also the default when no type_filter is given at all', invoke_resolve_mixed_quantities_default( 10 ), array( 5, 5 ) );

function invoke_resolve_mixed_quantities_default( $quantity ) {
	$reflection = new ReflectionMethod( 'Citex_Generator', 'resolve_mixed_quantities' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $quantity );
}

// ---------------------------------------------------------------------
// 'dragdrop': the whole quantity goes to DragDrop, MCQ gets none at all —
// so generate_mixed_batch()'s own `if ( $mcq_quantity > 0 )` guard skips
// calling generate_for_type() for MCQ entirely.
// ---------------------------------------------------------------------
check( '[2] dragdrop: the whole quantity goes to DragDrop', invoke_resolve_mixed_quantities( 20, 'dragdrop' ), array( 20, 0 ) );
check( '[2] dragdrop: MCQ gets none', invoke_resolve_mixed_quantities( 20, 'dragdrop' )[1], 0 );

// ---------------------------------------------------------------------
// 'mcq': the mirror image — the whole quantity goes to MCQ, DragDrop gets
// none at all.
// ---------------------------------------------------------------------
check( '[3] mcq: the whole quantity goes to MCQ', invoke_resolve_mixed_quantities( 20, 'mcq' ), array( 0, 20 ) );
check( '[3] mcq: DragDrop gets none', invoke_resolve_mixed_quantities( 20, 'mcq' )[0], 0 );

// ---------------------------------------------------------------------
// Every bucket still sums back to the original quantity, for every
// type_filter — no question is ever silently gained or dropped by the
// split itself.
// ---------------------------------------------------------------------
foreach ( array( 'mixed', 'dragdrop', 'mcq' ) as $filter ) {
	check(
		"[4] {$filter}: the split sums back to the original quantity (37)",
		array_sum( invoke_resolve_mixed_quantities( 37, $filter ) ),
		37
	);
}

// ---------------------------------------------------------------------
// An unrecognised type_filter falls back to 'mixed' rather than silently
// generating nothing — matches handle_generation()'s own POST-value
// whitelist, defended here too since this method has its own default.
// ---------------------------------------------------------------------
check( '[5] an unrecognised type_filter falls back to the mixed split', invoke_resolve_mixed_quantities( 10, 'something_else' ), array( 5, 5 ) );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
