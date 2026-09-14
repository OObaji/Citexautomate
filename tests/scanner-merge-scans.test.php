<?php
/**
 * Regression tests for Citex_Scanner::merge_scans() — added after a real
 * live-site report: the Dashboard's "Total Questions" stat card and its
 * Question Bank Overview breakdowns only ever reflected the Reference
 * List's own scan, silently excluding every Citations/In-Text Citation
 * question from every total. merge_scans() combines any number of
 * per-target scans (Reference List, Citations — see
 * Citex_Scanner::target_for_group()'s own docblock) into one scan-shaped
 * array, so Citex_Dashboard can build its stats/breakdowns from the
 * combined question list while still surfacing Citations' own count on
 * its own dedicated panel.
 *
 * Pure, no WordPress dependency at all — merge_scans() only calls native
 * PHP (gmdate()) and Citex_Scanner's own private count_by()/
 * count_combinations() helpers, neither of which touch WordPress.
 *
 * Repo-level only, run with plain
 * `php tests/scanner-merge-scans.test.php` — not shipped in
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

function scan_question( $source, $group, $category, $type, $question_id ) {
	return array(
		'source'     => $source,
		'group'      => $group,
		'category'   => $category,
		'type'       => $type,
		'questionId' => $question_id,
		'postStatus' => 'draft',
	);
}

$reference_scan = array(
	'scannedAt' => '2026-09-14T10:00:00+00:00',
	'total'     => 2,
	'harvardTotal' => 1,
	'questions' => array(
		scan_question( 'Harvard', 'ReferenceList', 'Book', 'DragDrop', 'BK01' ),
		scan_question( 'MLA', 'ReferenceList', 'Website', 'MCQ', 'MW01' ),
	),
);
$citations_scan = array(
	'scannedAt' => '2026-09-14T11:30:00+00:00',
	'total'     => 3,
	'harvardTotal' => 3,
	'questions' => array(
		scan_question( 'Harvard', 'InTextCitation', 'Book', 'DragDrop', 'IB01' ),
		scan_question( 'Harvard', 'InTextCitation', 'Book', 'DragDrop', 'IB02' ),
		scan_question( 'Harvard', 'InTextCitation', 'Website', 'MCQ', 'IW01' ),
	),
);

// ---------------------------------------------------------------------
// 1. Both scans present: total/harvardTotal/questions are the true
// combined counts — the exact bug this fix addresses (Citations
// questions were previously invisible to every dashboard total).
// ---------------------------------------------------------------------
$merged = Citex_Scanner::merge_scans( array( $reference_scan, $citations_scan ) );
check( '[1] merged total is the sum of both scans (2 + 3 = 5), never just the Reference List\'s own 2', $merged['total'], 5 );
check( '[1] merged harvardTotal is the sum of both scans (1 + 3 = 4)', $merged['harvardTotal'], 4 );
check( '[1] merged questions contains all 5, both Reference List and Citations records', count( $merged['questions'] ), 5 );
check( '[1] a Citations question (IB01) is present in the merged list', in_array( 'IB01', array_column( $merged['questions'], 'questionId' ), true ), true );
check( '[1] a Reference List question (BK01) is also still present', in_array( 'BK01', array_column( $merged['questions'], 'questionId' ), true ), true );

// ---------------------------------------------------------------------
// 2. scannedAt is the MORE RECENT of the two (the Citations scan here).
// ---------------------------------------------------------------------
check( '[2] merged scannedAt is the more recent of the two scans', $merged['scannedAt'], '2026-09-14T11:30:00+00:00' );

// ---------------------------------------------------------------------
// 3. Breakdowns reflect the combined question list — "groups" shows both
// ReferenceList and InTextCitation counted, never just one.
// ---------------------------------------------------------------------
$group_breakdown = array();
foreach ( $merged['breakdowns']['groups'] as $row ) {
	$group_breakdown[ $row['name'] ] = $row['count'];
}
check( '[3] breakdowns[groups] counts 2 ReferenceList questions', $group_breakdown['ReferenceList'] ?? null, 2 );
check( '[3] breakdowns[groups] counts 3 InTextCitation questions', $group_breakdown['InTextCitation'] ?? null, 3 );

$category_breakdown = array();
foreach ( $merged['breakdowns']['categories'] as $row ) {
	$category_breakdown[ $row['name'] ] = $row['count'];
}
check( '[3] breakdowns[categories] counts Book across BOTH scans (1 reference + 2 citations = 3)', $category_breakdown['Book'] ?? null, 3 );
check( '[3] breakdowns[categories] counts Website across BOTH scans (1 reference + 1 citations = 2)', $category_breakdown['Website'] ?? null, 2 );

// ---------------------------------------------------------------------
// 4. A missing/never-scanned target (null) is skipped gracefully — the
// merged result reflects only whichever scan(s) actually exist.
// ---------------------------------------------------------------------
$reference_only = Citex_Scanner::merge_scans( array( $reference_scan, null ) );
check( '[4] merging with a null (never-scanned) target reflects only the real scan\'s own total', $reference_only['total'], 2 );

$citations_only = Citex_Scanner::merge_scans( array( null, $citations_scan ) );
check( '[4] the reverse (only Citations configured) reflects only Citations\' own total', $citations_only['total'], 3 );

// ---------------------------------------------------------------------
// 5. Both null/missing returns null — mirrors get_last_scan()'s own
// "never scanned" null, never a misleading zeroed-out scan shape.
// ---------------------------------------------------------------------
check( '[5] merging two nulls returns null', Citex_Scanner::merge_scans( array( null, null ) ), null );
check( '[5] merging zero entries also returns null', Citex_Scanner::merge_scans( array() ), null );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
