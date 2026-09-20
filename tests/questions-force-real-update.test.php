<?php
/**
 * Regression tests for "Bulk Force Real Update" on the Question Bank page
 * — a real reported problem: a freshly populated question does not show
 * up in the site's separate student app until an admin manually opens it
 * in wp-admin and clicks the real "Update" button. Citex_Populator's own
 * finalize_question() (re-firing wp_update_post()/acf/save_post — see
 * populator-finalize-question.test.php) was CONFIRMED by the user not to
 * fix this for at least some questions — the true application-side
 * trigger is still unidentified (see class-citex-populator.php's own
 * class docblock).
 *
 * Since re-running WordPress's own save FUNCTIONS in code was confirmed
 * insufficient, this instead automates the ACTUAL manual fix: for each
 * selected question, load its real wp-admin edit screen in a hidden
 * iframe and click its real "Update" submit button — the exact same
 * request a human clicking Update produces — for many questions in a
 * row, without anyone opening each one by hand. This is pure client-side
 * browser automation (no new PHP/AJAX endpoint), so — mirroring this
 * codebase's established pattern for that position (see
 * questions-clear-question-bank.test.php) — this file is a source-wiring
 * check proving the shape of the feature is present, not a runtime test
 * of the iframe/DOM behaviour itself.
 *
 * Repo-level only, run with plain
 * `php tests/questions-force-real-update.test.php` — not shipped in
 * citex-tools.zip.
 */

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
// The view renders a dedicated "Bulk Force Real Update" panel, driven
// from the SAME $filtered_post_ids the existing "Bulk Edit Real
// WordPress Status" panel already uses — never a separate/duplicated
// post-ID computation.
// ---------------------------------------------------------------------
$questions_view_source = file_get_contents( __DIR__ . '/../citex-tools/admin/views/questions.php' );
check( '[wiring] the view renders a dedicated Bulk Force Real Update panel', false !== strpos( $questions_view_source, 'id="citex-bulk-real-update"' ), true );
check( '[wiring] the panel is driven from the same $filtered_post_ids as Bulk Edit Status, not a separate computation', 2 === substr_count( $questions_view_source, 'data-filtered-post-ids="<?php echo esc_attr( wp_json_encode( $filtered_post_ids ) ); ?>"' ), true );
check( '[wiring] a "selected on this page" scope option exists, matching Bulk Edit Status\'s own scope convention', 2 <= substr_count( $questions_view_source, 'Selected on this page' ), true );
check( '[wiring] the Force Real Update button exists', false !== strpos( $questions_view_source, 'id="citex-apply-real-update"' ), true );
check( '[wiring] the panel explains this is different from (and a fallback beyond) the per-row Finalise button', false !== strpos( $questions_view_source, 'different from "Finalise"' ), true );

// ---------------------------------------------------------------------
// citexTools carries the real wp-admin base URL the iframe automation
// needs to build each post's own real edit-screen URL.
// ---------------------------------------------------------------------
$admin_class_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-admin.php' );
check( '[wiring] citexTools is localized with the real admin URL (adminUrl)', false !== strpos( $admin_class_source, "'adminUrl'           => admin_url()," ), true );

// ---------------------------------------------------------------------
// citex-bulk-edit.js wires the panel, reuses the EXISTING
// selectedPostIds()/uniquePositiveIntegers() helpers (never duplicates
// them), asks for confirmation first, runs strictly ONE post at a time
// (never in parallel — each is a real page load, not a lightweight API
// call), and targets WordPress core's own stable `#publish` button —
// never a synthetic hand-built form post.
// ---------------------------------------------------------------------
$bulk_edit_js_source = file_get_contents( __DIR__ . '/../citex-tools/admin/js/citex-bulk-edit.js' );
check( '[wiring] citex-bulk-edit.js wires the Bulk Force Real Update panel', false !== strpos( $bulk_edit_js_source, 'function wireForceRealUpdate()' ), true );
check( '[wiring] wireForceRealUpdate() is actually called on DOMContentLoaded', false !== strpos( $bulk_edit_js_source, 'wireForceRealUpdate();' ), true );
check(
	'[wiring] wireForceRealUpdate() reuses the existing selectedPostIds()/uniquePositiveIntegers() helpers rather than duplicating them',
	1 === preg_match( '/function wireForceRealUpdate[\s\S]*?selectedPostIds\(\)/', $bulk_edit_js_source ) && 1 === preg_match( '/function wireForceRealUpdate[\s\S]*?uniquePositiveIntegers\(/', $bulk_edit_js_source ),
	true
);
check( '[wiring] Bulk Force Real Update asks for confirmation before acting on many real posts', preg_match( '/function wireForceRealUpdate[\s\S]*?window\.confirm\(/', $bulk_edit_js_source ), 1 );
check(
	'[wiring] posts are updated strictly ONE AT A TIME (await inside a for loop), never in parallel',
	preg_match( '/for \( var i = 0; i < ids\.length; i\+\+ \) \{\s*setRealUpdateProgress[\s\S]*?await forceRealUpdate\( ids\[ i \] \)/', $bulk_edit_js_source ),
	1
);
check( '[wiring] forceRealUpdate() targets WordPress core\'s own stable #publish button, never a hand-built form post', false !== strpos( $bulk_edit_js_source, "doc.getElementById( 'publish' )" ), true );
check( '[wiring] forceRealUpdate() loads the post\'s REAL edit screen (post.php?action=edit), not a custom endpoint', false !== strpos( $bulk_edit_js_source, "'post.php?post=' + postId + '&action=edit'" ), true );
check( '[wiring] forceRealUpdate() waits for the SECOND iframe load (the save completing) before resolving, not the first (the raw edit screen)', false !== strpos( $bulk_edit_js_source, 'if ( 1 === loadCount )' ), true );
check( '[wiring] forceRealUpdate() has a timeout so one stuck post cannot hang the whole batch forever', false !== strpos( $bulk_edit_js_source, 'TIMEOUT_MS' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
