<?php
/**
 * Regression tests for a real requested feature: both "Generate & Publish"
 * (and plain "Generate") and Auto-Generate should give the admin an
 * unambiguous "this finished" signal, since either can run long enough
 * (Auto-Generate especially — it can chain many batches, each one now also
 * force-updating its own DragDrop questions — see
 * tests/questions-force-real-update.test.php) that the admin has switched
 * tabs or moved on to something else.
 *
 * Two independent completion signals, mirroring the existing "audible
 * chime on page reload" feature this session added earlier (v0.39.0):
 *
 * 1. A small visual tick (✓) on a genuinely successful action:
 *    - Citex_Admin::render_notice() now prefixes "✓ " onto the message of
 *      any notice whose type is 'success' — never on 'warning'/'error'/
 *      'info', which already read as "needs your attention" without one.
 *      This covers classic Generate/Generate & Publish (a full page
 *      reload showing Citex_Admin::render_notice()'s own output).
 *    - The Auto-Generate loop's own 'targetReached' string (localized in
 *      Citex_Admin::enqueue_assets()) is prefixed "✓ Done — " directly,
 *      since Auto-Generate never reloads the page and so never goes
 *      through render_notice() at all — its own status line is set
 *      client-side from this string.
 *
 * 2. The SAME audible two-tone chime (factored out into a new shared
 *    playCitexChime(), used by both playActionToneIfNeeded() — the
 *    existing page-reload trigger — and Auto-Generate's own finish(),
 *    which is the loop's only exit point, so the chime plays whichever
 *    way a run ends: target reached, safety limit, no-progress stall,
 *    user-clicked Stop, or a batch failure).
 *
 * render_notice() is tightly coupled to the WordPress request cycle
 * (get_transient()/echo), so — mirroring this codebase's established
 * pattern for that position — this file drives it directly with a stub
 * get_transient()/delete_transient() rather than a full WP bootstrap, and
 * verifies the JS's own source wiring for the parts that can't run
 * standalone.
 *
 * Repo-level only, run with plain
 * `php tests/generator-completion-feedback.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

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
// 1. Citex_Admin::render_notice() — drive it directly against a stub
// transient store, one call per notice type, checking the ECHOED HTML for
// the "✓ " prefix.
// ---------------------------------------------------------------------
$GLOBALS['__citex_test_transients'] = array();
function set_transient( $key, $value, $ttl ) {
	$GLOBALS['__citex_test_transients'][ $key ] = $value;
}
function get_transient( $key ) {
	return $GLOBALS['__citex_test_transients'][ $key ] ?? false;
}
function delete_transient( $key ) {
	unset( $GLOBALS['__citex_test_transients'][ $key ] );
}
function get_current_user_id() {
	return 1;
}
function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES );
}
function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES );
}
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}

require __DIR__ . '/../citex-tools/includes/class-citex-admin.php';

// Citex_Admin's own constructor instantiates every other Citex_* class and
// registers WordPress hooks — none of which this test needs or stubs, so
// render_notice() (a plain method reading a transient and echoing HTML) is
// exercised on a constructor-free instance instead, mirroring how other
// tests in this suite reach a single method without a full WP bootstrap.
function render_notice_html() {
	$admin = ( new ReflectionClass( 'Citex_Admin' ) )->newInstanceWithoutConstructor();
	ob_start();
	$admin->render_notice();
	return ob_get_clean();
}

Citex_Admin::set_notice( 'All questions published.', 'success' );
$success_html = render_notice_html();
check( '[1] a success notice is prefixed with a checkmark', false !== strpos( $success_html, '✓ All questions published.' ), true );

Citex_Admin::set_notice( 'Some questions failed to publish.', 'warning' );
$warning_html = render_notice_html();
check( '[1] a warning notice is NEVER prefixed with a checkmark', false !== strpos( $warning_html, '✓' ), false );
check( '[1] a warning notice still shows its own message untouched', false !== strpos( $warning_html, 'Some questions failed to publish.' ), true );

Citex_Admin::set_notice( 'Something went wrong.', 'error' );
$error_html = render_notice_html();
check( '[1] an error notice is NEVER prefixed with a checkmark', false !== strpos( $error_html, '✓' ), false );

Citex_Admin::set_notice( 'Just so you know.', 'info' );
$info_html = render_notice_html();
check( '[1] an info notice is NEVER prefixed with a checkmark', false !== strpos( $info_html, '✓' ), false );

// A second call with no notice pending must render nothing (never fatal
// on a missing/false transient).
check( '[1] render_notice() renders nothing once the transient has been consumed', render_notice_html(), '' );

// ---------------------------------------------------------------------
// 2. Source wiring: the Auto-Generate 'targetReached' string carries its
// own "✓ Done" prefix directly (it never goes through render_notice()).
// ---------------------------------------------------------------------
$admin_class_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-admin.php' );
check(
	"[2] the Auto-Generate 'targetReached' string is prefixed with a checkmark and \"Done\"",
	false !== strpos( $admin_class_source, "'targetReached'   => __( '✓ Done — target reached, {total}/{target} published.', 'citex-tools' )" ),
	true
);

// ---------------------------------------------------------------------
// 3. Source wiring: the audible chime is factored into ONE shared
// playCitexChime(), called from BOTH the existing page-reload trigger
// (playActionToneIfNeeded()) and Auto-Generate's own finish() — its only
// exit point, so every way a run can end plays the same chime.
// ---------------------------------------------------------------------
$admin_js_source = file_get_contents( __DIR__ . '/../citex-tools/admin/js/citex-admin.js' );
check(
	'[3] playCitexChime() exists as its own shared function',
	false !== strpos( $admin_js_source, 'function playCitexChime()' ),
	true
);
check(
	'[3] playActionToneIfNeeded() delegates to the shared playCitexChime()',
	1 === preg_match( '/function playActionToneIfNeeded\(\) \{.*?playCitexChime\(\);\s*\}/s', $admin_js_source ),
	true
);
check(
	"[3] Auto-Generate's own finish() also calls playCitexChime() — its ONLY exit point, so every stop reason chimes",
	1 === preg_match( '/function finish\( message \) \{.*?playCitexChime\(\);\s*\}/s', $admin_js_source ),
	true
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
