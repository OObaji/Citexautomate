<?php
/**
 * Regression tests for the Dashboard's per-referencing-style breakdown,
 * added after a real request: the existing "Question Bank Overview"
 * breakdown tables combine every question together, with no way to see
 * Harvard's own Group/Category/Type coverage separately from MLA's.
 *
 * Citex_Dashboard::render() builds $style_breakdowns — one entry per style
 * (harvard, mla), each {label, total, breakdowns}, computed by filtering
 * the already-merged (both destinations) question list down to that
 * style's own questions and running them back through the same
 * Citex_Scanner::compute_breakdowns() used for the combined tables, so the
 * numbers stay directly comparable.
 *
 * render() itself is tightly coupled to the WordPress request cycle (a
 * `require` of the view template, WordPress option/formatting functions),
 * so — mirroring this codebase's established pattern for that position —
 * this file verifies the source wiring directly and exercises the
 * underlying, pure Citex_Scanner::compute_breakdowns() filtering logic
 * standalone (no WordPress dependency at all).
 *
 * Repo-level only, run with plain
 * `php tests/dashboard-style-breakdown.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-scanner.php';

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

function style_question( $source, $group, $category, $type, $question_id ) {
	return array(
		'source'     => $source,
		'group'      => $group,
		'category'   => $category,
		'type'       => $type,
		'questionId' => $question_id,
	);
}

// ---------------------------------------------------------------------
// 1. The same style-filtering Citex_Dashboard::render() applies — a
// case-insensitive substring match on `source`, matching the existing
// harvardTotal convention elsewhere in Citex_Scanner — correctly separates
// a mixed question list into per-style buckets before each is run through
// compute_breakdowns().
// ---------------------------------------------------------------------
$questions = array(
	style_question( 'Harvard', 'ReferenceList', 'Book', 'DragDrop', 'BK01' ),
	style_question( 'Harvard', 'InTextCitation', 'Website', 'MCQ', 'IW01' ),
	style_question( 'MLA', 'ReferenceList', 'Journal Article', 'DragDrop', 'MJ01' ),
);

$harvard_questions = array_values(
	array_filter(
		$questions,
		function ( $question ) {
			return false !== stripos( (string) ( $question['source'] ?? '' ), 'Harvard' );
		}
	)
);
$mla_questions = array_values(
	array_filter(
		$questions,
		function ( $question ) {
			return false !== stripos( (string) ( $question['source'] ?? '' ), 'MLA' );
		}
	)
);

check( '[1] Harvard bucket contains exactly its 2 own questions', count( $harvard_questions ), 2 );
check( '[1] MLA bucket contains exactly its 1 own question', count( $mla_questions ), 1 );
check( '[1] a Harvard question never leaks into the MLA bucket', in_array( 'MJ01', array_column( $harvard_questions, 'questionId' ), true ), false );

$harvard_breakdowns = Citex_Scanner::compute_breakdowns( $harvard_questions );
$mla_breakdowns      = Citex_Scanner::compute_breakdowns( $mla_questions );

$harvard_groups = array();
foreach ( $harvard_breakdowns['groups'] as $row ) {
	$harvard_groups[ $row['name'] ] = $row['count'];
}
check( '[1] Harvard\'s own breakdown counts 1 ReferenceList question', $harvard_groups['ReferenceList'] ?? null, 1 );
check( '[1] Harvard\'s own breakdown counts 1 InTextCitation question (both destinations combined before the style split)', $harvard_groups['InTextCitation'] ?? null, 1 );

$mla_categories = array();
foreach ( $mla_breakdowns['categories'] as $row ) {
	$mla_categories[ $row['name'] ] = $row['count'];
}
check( '[1] MLA\'s own breakdown counts its 1 Journal Article question', $mla_categories['Journal Article'] ?? null, 1 );
check( '[1] MLA\'s own breakdown never counts a Harvard-only category (Book)', isset( $mla_categories['Book'] ), false );

// ---------------------------------------------------------------------
// 2. A style with zero questions produces an empty (not missing/errored)
// breakdown shape — the view must be able to render "no questions for
// this style yet" rather than fail.
// ---------------------------------------------------------------------
$empty_breakdowns = Citex_Scanner::compute_breakdowns( array() );
check( '[2] an empty question list still returns the full breakdown shape', array_keys( $empty_breakdowns ), array( 'sources', 'groups', 'categories', 'types', 'postStatuses', 'combinations' ) );
check( '[2] each section is an empty array, not null/false', $empty_breakdowns['groups'], array() );

// ---------------------------------------------------------------------
// 3. Source wiring: Citex_Dashboard::render() actually builds and passes
// $style_breakdowns, and the view renders a section per style using
// compute_breakdowns() output.
// ---------------------------------------------------------------------
$dashboard_class_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-dashboard.php' );
check(
	'[3] render() builds one breakdown per style via Citex_Scanner::compute_breakdowns()',
	false !== strpos( $dashboard_class_source, 'Citex_Scanner::compute_breakdowns( $style_questions )' ),
	true
);
check(
	'[3] the style filter matches Harvard, MLA, APA, Chicago and MHRA specifically',
	false !== strpos( $dashboard_class_source, "array( 'harvard' => 'Harvard', 'mla' => 'MLA', 'apa' => 'APA', 'chicago' => 'Chicago', 'mhra' => 'MHRA' )" ),
	true
);

$dashboard_view_source = file_get_contents( __DIR__ . '/../citex-tools/admin/views/dashboard.php' );
check(
	'[3] the view renders a "Breakdown by Referencing Style" section',
	false !== strpos( $dashboard_view_source, 'Breakdown by Referencing Style' ),
	true
);
check(
	'[3] the view iterates $style_breakdowns',
	false !== strpos( $dashboard_view_source, 'foreach ( $style_breakdowns as $style )' ),
	true
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
