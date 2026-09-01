<?php
/**
 * Regression test for the blank-white-screen bug.
 *
 * Root cause: several Citex admin pages ran their POST-handling (and the
 * wp_safe_redirect() that followed) from inside the page's own render()
 * callback. WordPress calls that callback only after admin-header.php has
 * already streamed the page chrome to the browser, so the redirect's
 * header() call silently fails (headers already sent) and the user is
 * left looking at a half-printed, effectively blank admin page.
 *
 * The fix moves each handler onto the admin_init hook (which fires before
 * any admin output) and hardens every AJAX endpoint to always return JSON.
 * This is a static/reflection check — repo-level only, run with plain
 * `php tests/admin-action-feedback.test.php` — not shipped in
 * citex-tools.zip. It does not boot WordPress; it only proves the shape
 * of the fix is present so it cannot silently regress.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-admin.php';
require __DIR__ . '/../citex-tools/includes/class-citex-generator.php';
require __DIR__ . '/../citex-tools/includes/class-citex-importer.php';
require __DIR__ . '/../citex-tools/includes/class-citex-populator.php';
require __DIR__ . '/../citex-tools/includes/class-citex-questions.php';
require __DIR__ . '/../citex-tools/includes/class-citex-ai-v2.php';
require __DIR__ . '/../citex-tools/includes/validators/class-citex-harvard-book-dragdrop-validator.php';
require __DIR__ . '/../citex-tools/includes/class-citex-validator.php';
require __DIR__ . '/../citex-tools/includes/class-citex-scanner.php';
require __DIR__ . '/../citex-tools/includes/class-citex-bulk-editor.php';

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

function is_public_method( $class, $method ) {
	if ( ! method_exists( $class, $method ) ) {
		return false;
	}
	$reflection = new ReflectionMethod( $class, $method );
	return $reflection->isPublic();
}

// Every handler that redirects after a POST must be reachable from
// outside the class (i.e. public), so Citex_Admin can call it on
// admin_init before any output has started.
check( 'Citex_Generator::maybe_handle_submit is public', is_public_method( 'Citex_Generator', 'maybe_handle_submit' ), true );
check( 'Citex_Importer::maybe_handle_submit is public', is_public_method( 'Citex_Importer', 'maybe_handle_submit' ), true );
check( 'Citex_Populator::maybe_handle_submit is public', is_public_method( 'Citex_Populator', 'maybe_handle_submit' ), true );
check( 'Citex_Questions::maybe_handle_sync_submit is public', is_public_method( 'Citex_Questions', 'maybe_handle_sync_submit' ), true );
check( 'Citex_AI_V2::maybe_handle_submit is public and static', is_public_method( 'Citex_AI_V2', 'maybe_handle_submit' ), true );
check(
	'Citex_AI_V2::maybe_handle_submit is declared static',
	( new ReflectionMethod( 'Citex_AI_V2', 'maybe_handle_submit' ) )->isStatic(),
	true
);

// Citex_Admin must actually wire every one of those handlers onto
// admin_init — the fix only works if this hook exists and calls them all
// before any output. handle_admin_actions() itself must exist and be
// public (it's what admin_init calls).
check( 'Citex_Admin::handle_admin_actions is public', is_public_method( 'Citex_Admin', 'handle_admin_actions' ), true );

$admin_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-admin.php' );
check( 'Citex_Admin registers handle_admin_actions on admin_init', false !== strpos( $admin_source, "add_action( 'admin_init', array( \$this, 'handle_admin_actions' ) )" ), true );
foreach ( array( 'maybe_handle_submit', 'maybe_handle_sync_submit' ) as $needle ) {
	check( "Citex_Admin::handle_admin_actions references {$needle}", false !== strpos( $admin_source, $needle ), true );
}
check( 'handle_admin_actions catches Throwable so one bad handler cannot fatal the request', false !== strpos( $admin_source, 'catch ( Throwable $e )' ), true );

// Every AJAX endpoint must always return JSON, including on a nonce
// failure. check_ajax_referer()'s default $die=true argument calls
// wp_die( -1 ), which is not JSON and is exactly the kind of response
// the JavaScript cannot handle — so every AJAX handler must pass
// $die=false and send its own wp_send_json_error() instead.
$ajax_handlers = array(
	'class-citex-scanner.php'     => array( 'ajax_save_settings', 'ajax_save_scan' ),
	'class-citex-validator.php'   => array( 'ajax_save_result' ),
	'class-citex-bulk-editor.php' => array( 'ajax_update_status' ),
);
foreach ( $ajax_handlers as $file => $methods ) {
	$source = file_get_contents( __DIR__ . '/../citex-tools/includes/' . $file );
	foreach ( $methods as $method ) {
		if ( ! preg_match( '/function\s+' . preg_quote( $method, '/' ) . '\s*\([^)]*\)\s*\{(.*?)\n\t\}\n/s', $source, $matches ) ) {
			check( "{$file}::{$method} body could be located", false, true );
			continue;
		}
		$body = $matches[1];
		check( "{$file}::{$method} uses check_ajax_referer(..., false) instead of the default die-on-failure", false !== strpos( $body, "'nonce', false )" ), true );
		check( "{$file}::{$method} wraps its logic in try/catch(Throwable) so it can never fatal without returning JSON", false !== strpos( $body, 'catch ( Throwable $e )' ), true );
	}
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
