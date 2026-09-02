<?php
/**
 * Regression tests for Citex_Question_Diversity — scenario-history
 * tracking/selection and the duplicate-real-book similarity guard.
 *
 * Repo-level only, run with plain
 * `php tests/question-diversity.test.php` — not shipped in citex-tools.zip.
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

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-question-scenarios.php';
require __DIR__ . '/../citex-tools/includes/class-citex-question-diversity.php';

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

function reset_options() {
	$GLOBALS['__options'] = array();
}

$book = Citex_Reference_Rules::CATEGORY_BOOK;

// ---------------------------------------------------------------------
// 1. With no history at all, assign_scenarios() spreads a batch evenly
// across every scenario (all tied at 0, so it fills catalog order, then
// wraps) — a fresh install must not just always pick the first scenario.
// ---------------------------------------------------------------------
reset_options();
check( '[1] no history recorded yet', Citex_Question_Diversity::get_history( $book ), array() );
$assignment_4 = Citex_Question_Diversity::assign_scenarios( $book, 'MCQ', 4 );
check( '[1] a 4-slot batch covers all 4 Book scenarios, each exactly once', $assignment_4, array( 'one_author', 'two_authors', 'three_authors', 'four_or_more_authors' ) );

$assignment_8 = Citex_Question_Diversity::assign_scenarios( $book, 'MCQ', 8 );
check( '[1] an 8-slot batch cycles through all 4 scenarios exactly twice each', array_count_values( $assignment_8 ), array( 'one_author' => 2, 'two_authors' => 2, 'three_authors' => 2, 'four_or_more_authors' => 2 ) );

// ---------------------------------------------------------------------
// 2. record_batch() persists blueprints, and a scenario that was already
// heavily generated is deprioritised on the NEXT assignment call — the
// core "steer toward what's under-tested" behaviour.
// ---------------------------------------------------------------------
reset_options();
Citex_Question_Diversity::record_batch( $book, array(
	array( 'scenario' => 'one_author', 'ruleTested' => 'author_formatting', 'questionType' => 'MCQ' ),
	array( 'scenario' => 'one_author', 'ruleTested' => 'author_formatting', 'questionType' => 'MCQ' ),
	array( 'scenario' => 'one_author', 'ruleTested' => 'author_formatting', 'questionType' => 'MCQ' ),
) );
check( '[2] history now has 3 recorded entries', count( Citex_Question_Diversity::get_history( $book ) ), 3 );

$next_assignment = Citex_Question_Diversity::assign_scenarios( $book, 'MCQ', 3 );
check( '[2] the next 3 slots avoid the already-overused "one_author" scenario entirely', in_array( 'one_author', $next_assignment, true ), false );
check( '[2] the next 3 slots are exactly the 3 remaining, under-tested scenarios', $next_assignment, array( 'two_authors', 'three_authors', 'four_or_more_authors' ) );

// ---------------------------------------------------------------------
// 3. History is scoped per category — Edited Book generation never
// affects Book's own scenario counts, and vice versa.
// ---------------------------------------------------------------------
reset_options();
Citex_Question_Diversity::record_batch( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array(
	array( 'scenario' => 'one_editor', 'ruleTested' => 'editor_designation', 'questionType' => 'MCQ' ),
) );
check( '[3] Book history is untouched by Edited Book generation', Citex_Question_Diversity::get_history( $book ), array() );
$book_after_edited_book_history = Citex_Question_Diversity::assign_scenarios( $book, 'MCQ', 1 );
check( '[3] Book scenario selection is unaffected — still starts at the first scenario', $book_after_edited_book_history, array( 'one_author' ) );

// ---------------------------------------------------------------------
// 4. History is also scoped per question TYPE — heavy MCQ generation for
// a scenario does not deprioritise that SAME scenario for DragDrop, since
// they are tracked (and requested) completely independently.
// ---------------------------------------------------------------------
reset_options();
Citex_Question_Diversity::record_batch( $book, array(
	array( 'scenario' => 'one_author', 'ruleTested' => 'author_formatting', 'questionType' => 'MCQ' ),
	array( 'scenario' => 'one_author', 'ruleTested' => 'author_formatting', 'questionType' => 'MCQ' ),
) );
$dragdrop_assignment = Citex_Question_Diversity::assign_scenarios( $book, 'DragDrop', 1 );
check( '[4] heavy MCQ history for one_author does not deprioritise it for DragDrop', $dragdrop_assignment, array( 'one_author' ) );

// ---------------------------------------------------------------------
// 5. The rolling history cap: recording well beyond
// HISTORY_LIMIT_PER_CATEGORY keeps only the most recent entries.
// ---------------------------------------------------------------------
reset_options();
$big_batch = array();
for ( $i = 0; $i < Citex_Question_Diversity::HISTORY_LIMIT_PER_CATEGORY + 20; $i++ ) {
	$big_batch[] = array( 'scenario' => 'one_author', 'ruleTested' => 'author_formatting', 'questionType' => 'MCQ' );
}
Citex_Question_Diversity::record_batch( $book, $big_batch );
check( '[5] history never exceeds the per-category cap', count( Citex_Question_Diversity::get_history( $book ) ), Citex_Question_Diversity::HISTORY_LIMIT_PER_CATEGORY );

// ---------------------------------------------------------------------
// 6. is_duplicate_reference(): case-insensitive, whitespace-normalised
// exact match against a list of existing reference strings.
// ---------------------------------------------------------------------
$existing = array(
	'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.',
);
check(
	'[6] an exact duplicate is detected',
	Citex_Question_Diversity::is_duplicate_reference( 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.', $existing ),
	true
);
check(
	'[6] a case-insensitive, whitespace-normalised duplicate is still detected',
	Citex_Question_Diversity::is_duplicate_reference( "  bryman, a.  (2012) social research methods. oxford: oxford university press.  ", $existing ),
	true
);
check(
	'[6] a genuinely different reference is not flagged',
	Citex_Question_Diversity::is_duplicate_reference( 'Cottrell, S. (2019) Critical Thinking Skills. London: Red Globe Press.', $existing ),
	false
);
check( '[6] an empty reference is never flagged as a duplicate', Citex_Question_Diversity::is_duplicate_reference( '', $existing ), false );
check( '[6] an empty existing list never flags anything', Citex_Question_Diversity::is_duplicate_reference( 'Anything at all.', array() ), false );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
