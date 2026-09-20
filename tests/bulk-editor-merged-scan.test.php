<?php
/**
 * Regression test for a real, previously-unnoticed bug in
 * Citex_Bulk_Editor::update_status_body(): it validated requested post IDs
 * against Citex_Scanner::get_last_scan() called with NO argument, which
 * defaults to the 'reference' target only. Since every In-Text Citation
 * question lives exclusively in the separate Citations post type (see
 * Citex_Scanner::target_for_group()'s own docblock), this meant ANY bulk
 * status change — including "Move to Bin" — on a Citations record always
 * failed with 'not_indexed', even though the record genuinely was indexed
 * (just under the other target). This directly blocked the "detect broken
 * In-Text Citation questions and move them to Bin" feature, since every one
 * of those posts lives in Citations.
 *
 * The fix merges BOTH targets' scans, exactly mirroring the existing
 * pattern already used and already proven in Citex_Questions::render()
 * (see scanner-merge-scans.test.php for merge_scans() itself).
 *
 * update_status_body() is a private AJAX-handler method wired to
 * $_POST, wp_send_json_success/error, get_post() and wp_trash_post() —
 * heavy, genuinely request-scoped WordPress plumbing not worth
 * re-stubbing end-to-end here.
 * Consistent with this codebase's own established convention for such
 * AJAX-handler code (e.g. questions-clear-question-bank.test.php), this is
 * a source-wiring regression guard: it asserts the real fix is present in
 * the shipped source, and stays red if a future edit reverts to the
 * single-target call.
 *
 * Repo-level only, run with plain
 * `php tests/bulk-editor-merged-scan.test.php` — not shipped in
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

$source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-bulk-editor.php' );

check(
	'[1] update_status_body() no longer calls the bare single-target get_last_scan() (the bug)',
	false !== strpos( $source, 'Citex_Scanner::get_last_scan();' ),
	false
);
check(
	'[2] it now merges both the reference AND citations targets\' scans',
	preg_match( "/Citex_Scanner::merge_scans\\(\\s*array\\(\\s*Citex_Scanner::get_last_scan\\(\\s*'reference'\\s*\\),\\s*Citex_Scanner::get_last_scan\\(\\s*'citations'\\s*\\)\\s*\\)\\s*\\);/", $source ),
	1
);
check(
	'[3] the fix is applied at the $scan assignment feeding $indexed_ids (the same variable the "not_indexed" check reads)',
	preg_match( '/\$scan\s*=\s*Citex_Scanner::merge_scans\([\s\S]*?\);\s*\$indexed_ids\s*=\s*array\(\);/', $source ),
	1
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
