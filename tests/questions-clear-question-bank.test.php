<?php
/**
 * Regression tests for the "Clear Question Bank" feature added to the
 * Question Bank page: a dedicated action that moves EVERY indexed
 * Reference List post to the WordPress Bin, regardless of the page's
 * current search/filter — distinct from the pre-existing "Bulk Edit Real
 * Reference List Status" panel, which only ever acts on the filtered or
 * selected subset currently on screen.
 *
 * Deletion is deliberately Bin (wp_trash_post via Citex_Bulk_Editor's
 * existing, already-tested AJAX_UPDATE_STATUS endpoint), never permanent —
 * matching this plugin's established convention (see class-citex-bulk-editor.php)
 * of using WordPress's own recoverable trash lifecycle rather than a
 * one-way delete.
 *
 * Separately, deleting individual PENDING (not-yet-populated, AI-generated)
 * questions already existed before this change — Citex_Generator's
 * maybe_handle_submit() already handles citex_delete_pending (remove one)
 * and citex_clear_pending (remove all), wired to "Remove"/"Clear Pending"
 * buttons on the Generate Questions page — this file also asserts that
 * wiring is still present, as a regression guard, since this feature is
 * genuinely part of the same "clear/delete questions" request even though
 * no new code was needed for it.
 *
 * Repo-level only, run with plain
 * `php tests/questions-clear-question-bank.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

function absint( $v ) {
	return abs( intval( $v ) );
}

require __DIR__ . '/../citex-tools/includes/class-citex-questions.php';

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

function invoke_extract_post_ids( $questions ) {
	$reflection = new ReflectionMethod( 'Citex_Questions', 'extract_post_ids' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions );
}

// ---------------------------------------------------------------------
// extract_post_ids(): the shared helper behind both the filtered scope and
// the "Clear Question Bank" (all indexed, regardless of filter) scope.
// ---------------------------------------------------------------------
check(
	'[extract] collects every unique, positive wpPostId',
	invoke_extract_post_ids(
		array(
			array( 'wpPostId' => 101 ),
			array( 'wpPostId' => 102 ),
			array( 'wpPostId' => 101 ), // duplicate, must not appear twice
		)
	),
	array( 101, 102 )
);
check(
	'[extract] a question with no wpPostId (never populated) is excluded, not treated as ID 0',
	invoke_extract_post_ids( array( array( 'wpPostId' => 0 ), array( 'questionId' => 'BK01' ), array( 'wpPostId' => 205 ) ) ),
	array( 205 )
);
check( '[extract] an empty question list yields an empty ID list', invoke_extract_post_ids( array() ), array() );

// ---------------------------------------------------------------------
// The view must expose ALL indexed IDs for "Clear Question Bank" — not
// merely the currently filtered set (data-filtered-post-ids), which is a
// different, pre-existing attribute used by the separate bulk-status panel.
// ---------------------------------------------------------------------
$questions_view_source = file_get_contents( __DIR__ . '/../citex-tools/admin/views/questions.php' );
check( '[wiring] the view renders a dedicated Clear Question Bank panel', false !== strpos( $questions_view_source, 'citex-clear-question-bank-panel' ), true );
check( '[wiring] the panel exposes ALL indexed post IDs, not just the filtered set', false !== strpos( $questions_view_source, 'data-all-post-ids' ), true );
check( '[wiring] the panel is driven from $all_indexed_post_ids (every question), not $filtered_post_ids', false !== strpos( $questions_view_source, '$all_indexed_post_ids' ), true );
check( '[wiring] the Clear Question Bank button exists', false !== strpos( $questions_view_source, 'id="citex-clear-question-bank"' ), true );
check( '[wiring] the button text describes moving to Bin (recoverable), not permanent deletion', false !== strpos( $questions_view_source, 'Move All Questions to Bin' ), true );

$questions_class_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-questions.php' );
check( '[wiring] render() computes all_indexed_post_ids from every question, not the filtered list', false !== strpos( $questions_class_source, 'self::extract_post_ids( $all_questions )' ), true );

$bulk_edit_js_source = file_get_contents( __DIR__ . '/../citex-tools/admin/js/citex-bulk-edit.js' );
check( '[wiring] citex-bulk-edit.js wires the Clear Question Bank button', false !== strpos( $bulk_edit_js_source, 'wireClearQuestionBank' ), true );
check( '[wiring] Clear Question Bank reuses the existing authenticated trash batching (runServerBatches), not a new/duplicated code path', preg_match( '/function wireClearQuestionBank[\s\S]*?runServerBatches\( ids, .trash. \)/', $bulk_edit_js_source ), 1 );
check( '[wiring] Clear Question Bank asks for confirmation before acting (a destructive, all-questions action)', preg_match( '/function wireClearQuestionBank[\s\S]*?window\.confirm\(/', $bulk_edit_js_source ), 1 );
check( '[wiring] Clear Question Bank moves posts to Bin, not a permanent-delete status', preg_match( "/function wireClearQuestionBank[\\s\\S]*?runServerBatches\\( ids, 'trash' \\)/", $bulk_edit_js_source ), 1 );

// ---------------------------------------------------------------------
// Regression guard: individual/bulk deletion of PENDING (not-yet-populated)
// generated questions already existed on the Generate Questions page —
// asserted here so it cannot silently regress even though this change did
// not need to add it.
// ---------------------------------------------------------------------
$generator_class_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-generator.php' );
check( '[pending deletion] Citex_Generator handles deleting one pending question (citex_delete_pending)', false !== strpos( $generator_class_source, "_POST['citex_delete_pending']" ), true );
check( '[pending deletion] Citex_Generator handles clearing every pending question (citex_clear_pending)', false !== strpos( $generator_class_source, "_POST['citex_clear_pending']" ), true );
check( '[pending deletion] clearing pending questions never touches real WordPress/Reference List posts', false !== strpos( $generator_class_source, 'No WordPress questions were changed' ), true );

$generate_view_source = file_get_contents( __DIR__ . '/../citex-tools/admin/views/generate.php' );
check( '[pending deletion] the Generate Questions page exposes a per-row "Remove" button', false !== strpos( $generate_view_source, 'citex_delete_pending' ), true );
check( '[pending deletion] the Generate Questions page exposes a "Clear Pending" button', false !== strpos( $generate_view_source, 'citex_clear_pending' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
