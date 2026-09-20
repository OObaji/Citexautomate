<?php
/**
 * Regression tests for "click the real Update button in the background",
 * in two forms: the manual "Bulk Force Real Update" panel on the Question
 * Bank page, and the automatic, no-click-needed run of the same
 * automation right after "Generate & Publish"/Auto-Generate publishes a
 * DragDrop question (a real requested feature).
 *
 * Background: a freshly populated question does not show up in the
 * site's separate student app until an admin manually opens it in
 * wp-admin and clicks the real "Update" button. Citex_Populator's own
 * finalize_question() (re-firing wp_update_post()/acf/save_post — see
 * populator-finalize-question.test.php) was CONFIRMED by the user not to
 * fix this — the true application-side trigger is still unidentified
 * (see class-citex-populator.php's own class docblock). Since re-running
 * WordPress's own save FUNCTIONS in code was confirmed insufficient,
 * this instead automates the ACTUAL manual fix: load the post's real
 * edit screen in a hidden iframe and click its real "Update" submit
 * button — the exact same request a human clicking Update produces.
 *
 * The low-level iframe/timeout logic lives in ITS OWN shared file
 * (admin/js/citex-force-update.js, window.CitexForceUpdate) specifically
 * so both the Questions page's own bulk panel (citex-bulk-edit.js) AND
 * the Generate page's own automatic trigger (citex-admin.js) can drive
 * it without duplicating it — this file checks both callers wire into
 * that ONE shared implementation, plus the implementation itself.
 *
 * This is pure client-side browser automation (mostly no new PHP/AJAX
 * endpoint — the automatic-after-publish path does add a small
 * transient to carry post IDs across a redirect), so — mirroring this
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
// The shared implementation (admin/js/citex-force-update.js): targets
// WordPress core's own stable `#publish` button — never a synthetic
// hand-built form post — waits for the SECOND iframe load (the save
// completing, after WordPress's own POST-redirect-GET) before
// resolving, has a timeout so one stuck post cannot hang forever, and
// exposes both a single-post function and a batch runner that processes
// posts strictly ONE AT A TIME (await inside a for loop) — never in
// parallel, since each is a real page load, not a lightweight API call.
// ---------------------------------------------------------------------
$force_update_js_source = file_get_contents( __DIR__ . '/../citex-tools/admin/js/citex-force-update.js' );
check( '[shared] forceRealUpdate() targets WordPress core\'s own stable #publish button, never a hand-built form post', false !== strpos( $force_update_js_source, "doc.getElementById( 'publish' )" ), true );
check( '[shared] forceRealUpdate() loads the post\'s REAL edit screen (post.php?action=edit), not a custom endpoint', false !== strpos( $force_update_js_source, "'post.php?post=' + postId + '&action=edit'" ), true );
check( '[shared] forceRealUpdate() waits for the SECOND iframe load (the save completing) before resolving, not the first (the raw edit screen)', false !== strpos( $force_update_js_source, 'if ( 1 === loadCount )' ), true );
check( '[shared] forceRealUpdate() has a timeout so one stuck post cannot hang the whole batch forever', false !== strpos( $force_update_js_source, 'TIMEOUT_MS' ), true );
check(
	'[shared] forceRealUpdateBatch() processes posts strictly ONE AT A TIME (await inside a for loop), never in parallel',
	1 === preg_match( '/for \( var i = 0; i < postIds\.length; i\+\+ \) \{[\s\S]*?await forceRealUpdate\( postIds\[ i \] \)/', $force_update_js_source ),
	true
);
check( '[shared] the module is exposed as window.CitexForceUpdate for other scripts to use', false !== strpos( $force_update_js_source, 'window.CitexForceUpdate = {' ), true );

// ---------------------------------------------------------------------
// citexTools carries the real wp-admin base URL the iframe automation
// needs to build each post's own real edit-screen URL.
// ---------------------------------------------------------------------
$admin_class_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-admin.php' );
check( '[wiring] citexTools is localized with the real admin URL (adminUrl)', false !== strpos( $admin_class_source, "'adminUrl'           => admin_url()," ), true );
check(
	'[wiring] citex-force-update.js is enqueued as a dependency of both citex-admin.js and citex-bulk-edit.js',
	false !== strpos( $admin_class_source, "wp_enqueue_script( 'citex-admin', CITEX_TOOLS_URL . 'admin/js/citex-admin.js', array( 'citex-scanner', 'citex-validator-site-adapter', 'citex-force-update' )" )
		&& false !== strpos( $admin_class_source, "wp_enqueue_script( 'citex-bulk-edit', CITEX_TOOLS_URL . 'admin/js/citex-bulk-edit.js', array( 'citex-admin', 'citex-force-update' )" ),
	true
);

// ---------------------------------------------------------------------
// Manual path: the Question Bank page renders a dedicated "Bulk Force
// Real Update" panel, driven from the SAME $filtered_post_ids the
// existing "Bulk Edit Real WordPress Status" panel already uses — never
// a separate/duplicated post-ID computation — and citex-bulk-edit.js
// wires it using the SHARED module, reusing the existing
// selectedPostIds()/uniquePositiveIntegers() helpers, with a
// confirmation first.
// ---------------------------------------------------------------------
$questions_view_source = file_get_contents( __DIR__ . '/../citex-tools/admin/views/questions.php' );
check( '[manual] the view renders a dedicated Bulk Force Real Update panel', false !== strpos( $questions_view_source, 'id="citex-bulk-real-update"' ), true );
check( '[manual] the panel is driven from the same $filtered_post_ids as Bulk Edit Status, not a separate computation', 2 === substr_count( $questions_view_source, 'data-filtered-post-ids="<?php echo esc_attr( wp_json_encode( $filtered_post_ids ) ); ?>"' ), true );
check( '[manual] a "selected on this page" scope option exists, matching Bulk Edit Status\'s own scope convention', 2 <= substr_count( $questions_view_source, 'Selected on this page' ), true );
check( '[manual] the Force Real Update button exists', false !== strpos( $questions_view_source, 'id="citex-apply-real-update"' ), true );
check( '[manual] the panel explains this is different from (and a fallback beyond) the per-row Finalise button', false !== strpos( $questions_view_source, 'different from "Finalise"' ), true );

$bulk_edit_js_source = file_get_contents( __DIR__ . '/../citex-tools/admin/js/citex-bulk-edit.js' );
check( '[manual] citex-bulk-edit.js wires the Bulk Force Real Update panel', false !== strpos( $bulk_edit_js_source, 'function wireForceRealUpdate()' ), true );
check( '[manual] wireForceRealUpdate() is actually called on DOMContentLoaded', false !== strpos( $bulk_edit_js_source, 'wireForceRealUpdate();' ), true );
check(
	'[manual] wireForceRealUpdate() reuses the existing selectedPostIds()/uniquePositiveIntegers() helpers rather than duplicating them',
	1 === preg_match( '/function wireForceRealUpdate[\s\S]*?selectedPostIds\(\)/', $bulk_edit_js_source ) && 1 === preg_match( '/function wireForceRealUpdate[\s\S]*?uniquePositiveIntegers\(/', $bulk_edit_js_source ),
	true
);
check( '[manual] Bulk Force Real Update asks for confirmation before acting on many real posts', preg_match( '/function wireForceRealUpdate[\s\S]*?window\.confirm\(/', $bulk_edit_js_source ), 1 );
check( '[manual] wireForceRealUpdate() drives the SHARED module (CitexForceUpdate.forceRealUpdateBatch), not its own duplicated loop', false !== strpos( $bulk_edit_js_source, 'CitexForceUpdate.forceRealUpdateBatch( ids,' ), true );
check( '[manual] no duplicate forceRealUpdate() implementation was left behind in citex-bulk-edit.js', false === strpos( $bulk_edit_js_source, "iframe.src = citexTools.adminUrl" ), true );

// ---------------------------------------------------------------------
// Automatic path (a real requested feature): DragDrop questions
// published via "Generate & Publish" or Auto-Generate are force-updated
// automatically, with no click needed — never MCQ.
//
// Classic "Generate & Publish": Citex_Generator::handle_mixed_generation()
// extracts DragDrop-only post IDs from the populate result and queues
// them via Citex_Admin::set_pending_force_update_ids() (a short-lived
// transient — the same "carry small state across a redirect" mechanism
// set_notice()/render_notice() already use, since a plain PHP variable
// cannot survive the redirect to the next page load). render() reads
// and clears them exactly once via get_and_clear_pending_force_update_ids()
// and the view prints them for citex-admin.js's own wireAutoForceUpdate()
// to pick up automatically on page load.
// ---------------------------------------------------------------------
$generator_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-generator.php' );
check( '[auto-classic] a shared extract_dragdrop_post_ids() helper exists so both entry points filter identically', false !== strpos( $generator_source, 'private static function extract_dragdrop_post_ids( array $created )' ), true );
check( '[auto-classic] extract_dragdrop_post_ids() filters strictly to DragDrop, never MCQ', false !== strpos( $generator_source, "'DragDrop' === ( \$item['type'] ?? '' )" ), true );
check( '[auto-classic] handle_mixed_generation() queues the DragDrop IDs for automatic force-update after publishing', false !== strpos( $generator_source, 'Citex_Admin::set_pending_force_update_ids( self::extract_dragdrop_post_ids( $populate_result[\'created\'] ) )' ), true );
check( '[auto-classic] render() reads and clears the queued IDs for the view', false !== strpos( $generator_source, '$auto_force_update_post_ids = Citex_Admin::get_and_clear_pending_force_update_ids();' ), true );

$admin_class_transient_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-admin.php' );
check( '[auto-classic] Citex_Admin exposes set_pending_force_update_ids()', false !== strpos( $admin_class_transient_source, 'public static function set_pending_force_update_ids( array $post_ids )' ), true );
check( '[auto-classic] Citex_Admin exposes get_and_clear_pending_force_update_ids()', false !== strpos( $admin_class_transient_source, 'public static function get_and_clear_pending_force_update_ids()' ), true );
check( '[auto-classic] the transient is deleted once read, so the same batch is never force-updated twice', false !== strpos( $admin_class_transient_source, 'delete_transient( $key );' ), true );

$generate_view_source = file_get_contents( __DIR__ . '/../citex-tools/admin/views/generate.php' );
check( '[auto-classic] the view prints the queued post IDs onto a data attribute for JS to pick up', false !== strpos( $generate_view_source, 'id="citex-auto-force-update" data-post-ids="<?php echo esc_attr( wp_json_encode( $auto_force_update_post_ids ) ); ?>"' ), true );

$admin_js_source = file_get_contents( __DIR__ . '/../citex-tools/admin/js/citex-admin.js' );
check( '[auto-classic] citex-admin.js wires the automatic force-update trigger', false !== strpos( $admin_js_source, 'function wireAutoForceUpdate()' ), true );
check( '[auto-classic] wireAutoForceUpdate() is actually called on DOMContentLoaded', false !== strpos( $admin_js_source, 'wireAutoForceUpdate();' ), true );
check( '[auto-classic] wireAutoForceUpdate() drives the SHARED module, not a duplicated loop', false !== strpos( $admin_js_source, 'CitexForceUpdate.forceRealUpdateBatch( postIds,' ), true );

// ---------------------------------------------------------------------
// Auto-Generate: each AJAX batch response carries the DragDrop-only post
// IDs created in THAT batch, and the JS loop force-updates them before
// starting the next batch (sequential, matching everything else in that
// loop).
// ---------------------------------------------------------------------
check( '[auto-generate] auto_generate_batch_body() returns DragDrop-only post IDs from this batch\'s own populate result', false !== strpos( $generator_source, "'dragdropCreatedPostIds' => self::extract_dragdrop_post_ids( \$created )" ), true );
check( '[auto-generate] the Auto-Generate JS loop force-updates DragDrop IDs from each batch via the SHARED module', false !== strpos( $admin_js_source, 'CitexForceUpdate.forceRealUpdateBatch( dragdropIds,' ), true );
check(
	'[auto-generate] the force-update run happens BEFORE the next batch is scheduled (sequential, not fire-and-forget)',
	1 === preg_match( '/await CitexForceUpdate\.forceRealUpdateBatch\( dragdropIds[\s\S]*?window\.setTimeout\( runNextBatch, 500 \)/', $admin_js_source ),
	true
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
