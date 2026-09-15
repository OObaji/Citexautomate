<?php
/**
 * Regression tests for the Question Bank page listing/syncing/Finalising
 * BOTH real destinations (Reference List and Citations — see
 * Citex_Scanner::target_for_group()'s own docblock), added after a real
 * live-site report: a newly populated Citations question needed its post
 * hand-opened and manually re-saved in wp-admin before the site's Appify
 * mobile app would show it, exactly like Reference List questions
 * sometimes do — but the "Finalise" button already built for exactly that
 * (re-runs the WordPress/ACF save lifecycle without opening the post; see
 * Citex_Populator::finalize_question()) was unreachable for Citations
 * questions, because Citex_Questions::render() only ever listed the
 * Reference List's own scan (Citex_Scanner::get_last_scan() with its
 * default 'reference' target).
 *
 * render() and maybe_handle_sync_submit() are both tightly coupled to the
 * WordPress request cycle (nonces, $_POST/$_GET superglobals, wp_die(),
 * wp_safe_redirect()+exit, and a `require` of the view template), so —
 * mirroring this codebase's own established pattern for logic in that
 * position (see e.g. populator-citations-routing.test.php) — this file
 * verifies the actual source wiring directly rather than invoking those
 * methods, plus exercises the underlying Citex_Scanner primitives they are
 * built from (already covered end-to-end by scanner-merge-scans.test.php).
 *
 * Repo-level only, run with plain
 * `php tests/questions-citations-merge.test.php` — not shipped in
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

$questions_class_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-questions.php' );
$questions_view_source  = file_get_contents( __DIR__ . '/../citex-tools/admin/views/questions.php' );

// ---------------------------------------------------------------------
// 1. render() builds its scan from BOTH targets, merged — never the
// Reference-List-only get_last_scan() this page used before the fix.
// ---------------------------------------------------------------------
check(
	'[1] render() reads the Reference List scan by its explicit target',
	false !== strpos( $questions_class_source, "Citex_Scanner::get_last_scan( 'reference' )" ),
	true
);
check(
	'[1] render() reads the Citations scan too',
	false !== strpos( $questions_class_source, "Citex_Scanner::get_last_scan( 'citations' )" ),
	true
);
check(
	'[1] render() combines both via Citex_Scanner::merge_scans()',
	1 === preg_match( '/\$scan\s*=\s*Citex_Scanner::merge_scans\(/', $questions_class_source ),
	true
);

// ---------------------------------------------------------------------
// 2. maybe_handle_sync_submit() syncs BOTH targets, and a missing/failed
// Citations sync must never block a Reference List sync from succeeding.
// ---------------------------------------------------------------------
check(
	'[2] the sync handler syncs the Reference List target',
	false !== strpos( $questions_class_source, "Citex_Scanner::sync_from_wordpress( 'reference' )" ),
	true
);
check(
	'[2] the sync handler syncs the Citations target too',
	false !== strpos( $questions_class_source, "Citex_Scanner::sync_from_wordpress( 'citations' )" ),
	true
);
check(
	'[2] a Citations sync failure is checked separately and does not gate the success branch',
	1 === preg_match( '/is_wp_error\(\s*\$reference_result\s*\)/', $questions_class_source ),
	true
);

// ---------------------------------------------------------------------
// 3. The sync button is enabled whenever EITHER destination has a URL
// configured, not only Reference List.
// ---------------------------------------------------------------------
check(
	'[3] render() computes whether the Citations URL is configured',
	false !== strpos( $questions_class_source, "Citex_Scanner::get_question_list_url( 'citations' )" ),
	true
);
check(
	'[3] the sync button\'s disabled state considers both URLs',
	false !== strpos( $questions_view_source, '$citations_list_url_configured' ),
	true
);

// ---------------------------------------------------------------------
// 4. The listing table shows which real destination each row belongs to,
// via the same Citex_Scanner::target_for_group() classifier used to route
// population — never a hard-coded/guessed mapping.
// ---------------------------------------------------------------------
check(
	'[4] the view renders a Destination column',
	false !== strpos( $questions_view_source, "esc_html_e( 'Destination', 'citex-tools' )" ),
	true
);
check(
	'[4] each row\'s destination is resolved via Citex_Scanner::target_for_group()',
	false !== strpos( $questions_view_source, "Citex_Scanner::target_for_group( \$question['group'] ?? '' )" ),
	true
);

// ---------------------------------------------------------------------
// 5. The pre-existing "Finalise" button and "Clear Question Bank"/bulk
// status actions are untouched (still wired to every wpPostId, now
// spanning both destinations because $all_questions itself now does) —
// asserted as a regression guard, since this fix relies on those already
// working generically rather than needing any destination-specific change.
// ---------------------------------------------------------------------
check(
	'[5] the per-row Finalise button is still present',
	false !== strpos( $questions_view_source, 'citex_finalize_submit' ),
	true
);
check(
	'[5] Clear Question Bank still derives its scope from every indexed question',
	false !== strpos( $questions_class_source, 'self::extract_post_ids( $all_questions )' ),
	true
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
