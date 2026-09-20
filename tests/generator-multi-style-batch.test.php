<?php
/**
 * Regression tests for the TEMPORARY "Multi-Style Batch Populate" tool
 * (admin/js/citex-multi-style-batch.js) — a real requested feature: the
 * admin has newer Referencing Styles (Chicago, MHRA) still needing
 * Reference List coverage across all 4 categories and wants to queue that
 * up overnight, ticking several styles and setting ONE target-per-category
 * number, rather than running the existing single Style+Category
 * Auto-Generate by hand once per combination.
 *
 * The admin has said this is explicitly temporary and will be removed
 * once the backfill is done, so it was built as a fully self-contained
 * file rather than a refactor of the existing (already shipped, already
 * tested) Auto-Generate loop in citex-admin.js — removing it later is
 * just deleting citex-multi-style-batch.js, its enqueue line, and its
 * section in admin/views/generate.php, none of which touches the tested
 * Auto-Generate feature. This file is source-wiring only, matching this
 * codebase's established pattern for JS-only features (see
 * questions-force-real-update.test.php) — a full runtime/DOM harness
 * isn't practical here.
 *
 * Repo-level only, run with plain
 * `php tests/generator-multi-style-batch.test.php` — not shipped in
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

$batch_js_source = file_get_contents( __DIR__ . '/../citex-tools/admin/js/citex-multi-style-batch.js' );

// ---------------------------------------------------------------------
// Every batch it sends is still exactly what the existing Auto-Generate
// feature sends — the same AJAX action/nonce, the same 20-question cap,
// and it always publishes (citex_question_group is always
// 'referencelist', since Chicago/MHRA — the whole reason for this tool —
// don't support In-Text Citation yet).
// ---------------------------------------------------------------------
check(
	'[1] batches are capped at 20 questions, matching the existing Auto-Generate/Generate & Publish cap',
	false !== strpos( $batch_js_source, 'var BATCH_CAP             = 20;' ),
	true
);
check(
	'[1] every batch calls the SAME citexTools.generator.autoGenerateAction AJAX action Auto-Generate uses',
	false !== strpos( $batch_js_source, 'action:                   citexTools.generator.autoGenerateAction,' ),
	true
);
check(
	'[1] every batch is always Reference List, never In-Text Citation',
	false !== strpos( $batch_js_source, "var GROUP_KEY             = 'referencelist';" ) && false !== strpos( $batch_js_source, 'citex_question_group:     GROUP_KEY,' ),
	true
);

// ---------------------------------------------------------------------
// One combination (style + category) is run to completion, in 20-at-a-
// time batches, strictly sequentially — never firing the next batch
// before the previous one resolves, and never running two combinations
// in parallel.
// ---------------------------------------------------------------------
check(
	'[2] a batch is only ever queued via window.setTimeout AFTER the previous one resolved (sequential, not fire-and-forget)',
	false !== strpos( $batch_js_source, 'window.setTimeout( nextBatch, 500 );' ),
	true
);
check(
	'[2] runAll() awaits each combination in a for loop before starting the next — never Promise.all/parallel',
	1 === preg_match( '/for \( var i = 0; i < combos\.length; i\+\+ \) \{[\s\S]*?await runCombo\( combo, target \)/', $batch_js_source ),
	true
);

// ---------------------------------------------------------------------
// A stalled/failed/safety-limited combination is logged and skipped —
// the whole point of running this overnight is that one bad combination
// must never block the rest.
// ---------------------------------------------------------------------
check(
	'[3] a stalled combination logs a warning and continues to the next one (no early return/break)',
	false !== strpos( $batch_js_source, "'stalled' === result.outcome" ) && 0 === preg_match( "/'stalled' === result\\.outcome \\) \\{[^}]*break;/", $batch_js_source ),
	true
);
check(
	'[3] only the user explicitly clicking Stop breaks out of the combination loop early',
	1 === preg_match( "/'stopped' === result\\.outcome \\) \\{[^}]*break;/", $batch_js_source ),
	true
);

// ---------------------------------------------------------------------
// DragDrop questions published by each batch are automatically force-
// updated via the SAME shared module every other publish path in this
// plugin uses — never a duplicated implementation, never MCQ.
// ---------------------------------------------------------------------
check(
	'[4] DragDrop IDs from each batch are force-updated via the shared CitexForceUpdate module',
	false !== strpos( $batch_js_source, 'CitexForceUpdate.forceRealUpdateBatch( dragdropIds, function () {} )' ),
	true
);

// ---------------------------------------------------------------------
// The style checkboxes and the 4 categories are built from the SAME
// <select> elements the rest of the Generate page already renders —
// never a second, separately maintained list that could drift out of
// sync (e.g. if a 6th Referencing Style is ever added).
// ---------------------------------------------------------------------
check(
	'[5] style checkboxes are built from the existing #citex_referencing_style <select>, not a hardcoded list',
	false !== strpos( $batch_js_source, "Array.prototype.forEach.call( styleSelect.options, function ( opt ) {" ),
	true
);
check(
	'[5] categories are read from the existing #citex_category <select>, not a hardcoded list',
	false !== strpos( $batch_js_source, "var categories = Array.prototype.map.call( categorySelect.options, function ( opt ) {" ),
	true
);

// ---------------------------------------------------------------------
// Source wiring: enqueued as a temporary script depending on citex-admin
// (for the localized nonce/action) and citex-force-update (for
// window.CitexForceUpdate), and the view renders its own dedicated
// section — clearly marked as removable in both places.
// ---------------------------------------------------------------------
$admin_class_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-admin.php' );
check(
	"[6] citex-multi-style-batch.js is enqueued depending on citex-admin and citex-force-update",
	false !== strpos( $admin_class_source, "wp_enqueue_script( 'citex-multi-style-batch', CITEX_TOOLS_URL . 'admin/js/citex-multi-style-batch.js', array( 'citex-admin', 'citex-force-update' )" ),
	true
);
check(
	'[6] the enqueue line is marked TEMPORARY so it is easy to find and remove later',
	false !== strpos( $admin_class_source, '// TEMPORARY — see admin/js/citex-multi-style-batch.js' ),
	true
);

$generate_view_source = file_get_contents( __DIR__ . '/../citex-tools/admin/views/generate.php' );
check(
	'[6] the view renders the Multi-Style Batch Populate section',
	false !== strpos( $generate_view_source, 'class="citex-multi-style-batch"' ) && false !== strpos( $generate_view_source, 'Multi-Style Batch Populate (Temporary)' ),
	true
);
check(
	'[6] the view section is marked TEMPORARY so it is easy to find and remove later',
	false !== strpos( $generate_view_source, 'TEMPORARY — see admin/js/citex-multi-style-batch.js' ),
	true
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
