<?php
/**
 * Regression tests for Citex_Populator::build_population_message() and
 * populate_questions() — extracted from the Populate screen's own submit
 * handler (maybe_handle_submit()) so the exact same population logic and
 * message shape can be reused by Citex Generator's "Generate & Publish"
 * action, which populates a freshly generated batch immediately instead of
 * a separate trip through the Populate screen. This file only exercises
 * build_population_message() directly (pure string formatting, no WP
 * dependency beyond __()) — populate_questions()'s own full population
 * pipeline is already covered end-to-end by the other
 * tests/populator-*.test.php files.
 *
 * Repo-level only, run with plain
 * `php tests/populator-population-message.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

function __( $s, $d = '' ) {
	return $s;
}

require __DIR__ . '/../citex-tools/includes/class-citex-populator.php';

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
// 1. Nothing created, nothing failed.
// ---------------------------------------------------------------------
check(
	'[1] all-zero counts produce the base "Population complete" line with no created/failed detail',
	Citex_Populator::build_population_message( array(), array(), array( 'reference' => 0, 'citations' => 0 ) ),
	'Population complete. Created in Reference List: 0. Created in Citations: 0. Failed: 0.'
);

// ---------------------------------------------------------------------
// 2. Created questions append per-question verification detail, capped
// at the first 3.
// ---------------------------------------------------------------------
$created = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$created[] = array(
		'postId'                 => 1000 + $i,
		'questionId'              => 'BK0' . $i,
		'status'                  => 'publish',
		'category'                => 'Book',
		'exercise'                => 'Exercise 1',
		'type'                    => 'DragDrop',
		'scenarioVerified'        => true,
		'questionPartsVerified'   => '3/3',
		'fixedTextVerified'       => true,
		'categoryVerified'        => true,
		'exerciseVerified'        => true,
		'statusVerified'          => true,
		'saveLifecycleCompleted'  => true,
	);
}
$message = Citex_Populator::build_population_message( $created, array(), array( 'reference' => 5, 'citations' => 0 ) );
check( '[2] counts reflect all 5 created, 0 failed', 0 === strpos( $message, 'Population complete. Created in Reference List: 5. Created in Citations: 0. Failed: 0.' ), true );
check( '[2] per-question detail is capped at the first 3, not all 5', substr_count( $message, 'BK0' ), 3 );
check( '[2] the first created question\'s own id appears in the detail', false !== strpos( $message, 'BK01' ), true );
check( '[2] a 4th/5th question\'s id is NOT in the detail (cap enforced)', false !== strpos( $message, 'BK04' ), false );

// ---------------------------------------------------------------------
// 3. An MCQ-created question's detail line uses Options/Answer wording,
// not the DragDrop Question Parts/Fixed Text wording.
// ---------------------------------------------------------------------
$mcq_created = array(
	array(
		'postId'           => 2001,
		'questionId'        => 'BKQ01',
		'status'            => 'publish',
		'category'          => 'Book',
		'exercise'          => 'Exercise 1',
		'type'              => 'MCQ',
		'scenarioVerified'  => true,
		'optionsVerified'   => '4/4',
		'answerVerified'    => true,
		'categoryVerified'  => true,
		'exerciseVerified'  => true,
		'statusVerified'    => true,
		'saveLifecycleCompleted' => true,
	),
);
$mcq_message = Citex_Populator::build_population_message( $mcq_created, array(), array( 'reference' => 1, 'citations' => 0 ) );
check( '[3] MCQ detail mentions Options', false !== strpos( $mcq_message, 'Options: 4/4' ), true );
check( '[3] MCQ detail does not mention Question Parts', false !== strpos( $mcq_message, 'Question Parts' ), false );

// ---------------------------------------------------------------------
// 4. Failures append their own messages, also capped at the first 3.
// ---------------------------------------------------------------------
$failed = array( 'BK06: some error', 'BK07: another error', 'BK08: a third error', 'BK09: a fourth error' );
$fail_message = Citex_Populator::build_population_message( array(), $failed, array( 'reference' => 0, 'citations' => 0 ) );
check( '[4] failed count is reflected', 0 === strpos( $fail_message, 'Population complete. Created in Reference List: 0. Created in Citations: 0. Failed: 4.' ), true );
check( '[4] only the first 3 failure messages are appended', substr_count( $fail_message, ': some error' ) + substr_count( $fail_message, ': another error' ) + substr_count( $fail_message, ': a third error' ), 3 );
check( '[4] the 4th failure message is not appended', false !== strpos( $fail_message, 'a fourth error' ), false );

// ---------------------------------------------------------------------
// 5. A missing key in $created_by_target (e.g. a target that had no
// questions at all) defaults to 0 rather than a PHP warning/notice.
// ---------------------------------------------------------------------
check(
	'[5] a missing createdByTarget key defaults to 0',
	Citex_Populator::build_population_message( array(), array(), array() ),
	'Population complete. Created in Reference List: 0. Created in Citations: 0. Failed: 0.'
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
