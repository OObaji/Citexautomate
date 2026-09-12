<?php
/**
 * Regression tests for Citex_Populator::finalize_question() and its admin
 * action maybe_handle_finalize_submit(), added to work around a real,
 * live-investigated production issue: a question populated via Citex Tools
 * as WordPress-Published does not appear in the Citex student app until a
 * human manually opens it in wp-admin and clicks Update, with no content
 * change — confirmed via Citex Diagnostics (see class-citex-diagnostics.php)
 * to leave every WordPress-native field, meta value, taxonomy term, and ACF
 * value byte-identical before and after that manual click. The real
 * application-side mechanism this depends on is still not identified (it is
 * not any currently-registered save_post/acf hook on the live site), so
 * rather than guess a new one, finalize_question() reproduces every
 * standard, already-confirmed part of a manual Update click — the
 * WordPress save lifecycle (wp_update_post(), which unconditionally fires
 * save_post/save_post_{post_type}/wp_insert_post/wp_after_insert_post/
 * transition_post_status/{old}_to_{new}) plus the explicit
 * do_action('acf/save_post', ...) — as one reusable, self-verifying
 * operation, callable both right after a new question is populated and,
 * via the Questions page "Finalise" action, against an existing question by
 * post ID to repair one that was populated before this step existed.
 *
 * Repo-level only, run with plain
 * `php tests/populator-finalize-question.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

// ---------------------------------------------------------------------
// Minimal WordPress/ACF stub environment
// ---------------------------------------------------------------------

$GLOBALS['__posts']                  = array();
$GLOBALS['__post_terms']             = array();
$GLOBALS['__taxonomies_by_post_type'] = array();
$GLOBALS['__acf_values']             = array();
$GLOBALS['__wp_update_post_calls']   = 0;
$GLOBALS['__clean_post_cache_calls'] = array();
$GLOBALS['__acf_save_post_calls']    = array();
$GLOBALS['__notices']                = array();
$GLOBALS['__can_manage_options']     = true;
$GLOBALS['__editable_post_ids']      = array();
// When set to a post ID, do_action('acf/save_post', $id) for that ID also
// mutates ACF/taxonomy state — simulating an external hook listener with a
// side effect, so finalize_question()'s own before/after integrity check
// can be proven to actually catch it rather than silently succeeding.
$GLOBALS['__simulate_acf_mutation_for_post']  = null;
$GLOBALS['__simulate_terms_mutation_for_post'] = null;

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_code() {
		return $this->code;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function absint( $v ) {
	return abs( intval( $v ) );
}
function sanitize_key( $v ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
}
function wp_unslash( $v ) {
	return $v;
}
function __( $s, $d = '' ) {
	return $s;
}
function esc_html__( $s, $d = '' ) {
	return $s;
}
function wp_strip_all_tags( $s ) {
	return $s;
}
function get_current_user_id() {
	return 1;
}
function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['__notices'][] = $value;
	return true;
}

/**
 * wp_safe_redirect()/wp_die() normally end the request. Throwing instead of
 * calling the real exit() lets maybe_handle_finalize_submit() be exercised
 * live, in-process, while still observing exactly what it decided to do.
 */
class Citex_Test_Redirect_Signal extends Exception {
	public $location;
	public function __construct( $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}
class Citex_Test_Die_Signal extends Exception {}
function wp_safe_redirect( $location ) {
	throw new Citex_Test_Redirect_Signal( $location );
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}
function wp_die( $message = '' ) {
	throw new Citex_Test_Die_Signal( (string) $message );
}

function check_admin_referer( $action, $field ) {
	return true;
}
function current_user_can( $cap, $post_id = null ) {
	if ( 'manage_options' === $cap ) {
		return $GLOBALS['__can_manage_options'];
	}
	if ( 'edit_post' === $cap ) {
		return in_array( $post_id, $GLOBALS['__editable_post_ids'], true );
	}
	return true;
}

function get_post( $id ) {
	if ( ! isset( $GLOBALS['__posts'][ $id ] ) ) {
		return null;
	}
	return (object) array_merge( $GLOBALS['__posts'][ $id ], array( 'ID' => $id ) );
}

function wp_update_post( $args, $wp_error = false ) {
	$GLOBALS['__wp_update_post_calls']++;
	$id = $args['ID'] ?? 0;
	if ( ! isset( $GLOBALS['__posts'][ $id ] ) ) {
		return new WP_Error( 'missing_post', 'Post not found' );
	}
	foreach ( $args as $k => $v ) {
		if ( 'ID' === $k ) {
			continue;
		}
		$GLOBALS['__posts'][ $id ][ $k ] = $v;
	}
	return $id;
}

function get_post_status( $post_id ) {
	return $GLOBALS['__posts'][ $post_id ]['post_status'] ?? false;
}

function clean_post_cache( $post_id ) {
	$GLOBALS['__clean_post_cache_calls'][] = $post_id;
}

function do_action( $hook, ...$args ) {
	if ( 'acf/save_post' !== $hook ) {
		return;
	}
	$post_id                          = $args[0] ?? null;
	$GLOBALS['__acf_save_post_calls'][] = $post_id;

	if ( $post_id === $GLOBALS['__simulate_acf_mutation_for_post'] ) {
		$GLOBALS['__acf_values'][ $post_id ]['scenario'] = 'mutated by a simulated acf/save_post listener';
	}
	if ( $post_id === $GLOBALS['__simulate_terms_mutation_for_post'] ) {
		$GLOBALS['__post_terms'][ $post_id ]['reference-categories'] = array( 999 );
	}
}

function get_object_taxonomies( $post_type, $output = 'names' ) {
	return $GLOBALS['__taxonomies_by_post_type'][ $post_type ] ?? array();
}
function wp_get_object_terms( $post_id, $taxonomy, $args = array() ) {
	return $GLOBALS['__post_terms'][ $post_id ][ $taxonomy ] ?? array();
}
function get_fields( $post_id, $format = true ) {
	return $GLOBALS['__acf_values'][ $post_id ] ?? array();
}

require __DIR__ . '/../citex-tools/includes/class-citex-populator.php';
require __DIR__ . '/../citex-tools/includes/class-citex-admin.php';

// ---------------------------------------------------------------------
// Test harness
// ---------------------------------------------------------------------

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

function reset_environment() {
	$GLOBALS['__posts']                            = array();
	$GLOBALS['__post_terms']                       = array();
	$GLOBALS['__taxonomies_by_post_type']          = array( 'question' => array( 'reference-categories' ) );
	$GLOBALS['__acf_values']                       = array();
	$GLOBALS['__wp_update_post_calls']             = 0;
	$GLOBALS['__clean_post_cache_calls']           = array();
	$GLOBALS['__acf_save_post_calls']              = array();
	$GLOBALS['__notices']                          = array();
	$GLOBALS['__can_manage_options']               = true;
	$GLOBALS['__editable_post_ids']                = array();
	$GLOBALS['__simulate_acf_mutation_for_post']   = null;
	$GLOBALS['__simulate_terms_mutation_for_post'] = null;
}

function seed_post( $id, $status, $post_type = 'question' ) {
	$GLOBALS['__posts'][ $id ] = array(
		'post_type'   => $post_type,
		'post_status' => $status,
		'post_title'  => 'Test question ' . $id,
	);
}

// ---------------------------------------------------------------------
// finalize_question(): input validation
// ---------------------------------------------------------------------
reset_environment();
$missing = Citex_Populator::finalize_question( 0 );
check( '[input] a zero post ID is rejected', is_wp_error( $missing ), true );
check( '[input] error code identifies a missing post ID', is_wp_error( $missing ) ? $missing->get_error_code() : null, 'citex_finalize_missing_post_id' );

$not_found = Citex_Populator::finalize_question( 999 );
check( '[input] a nonexistent post ID is rejected', is_wp_error( $not_found ), true );
check( '[input] error code identifies the post as not found', is_wp_error( $not_found ) ? $not_found->get_error_code() : null, 'citex_finalize_post_not_found' );

// ---------------------------------------------------------------------
// finalize_question(): status is preserved unless explicitly overridden —
// "Do not publish a draft unless the configured workflow says to publish
// it" / "Do not change its status unexpectedly."
// ---------------------------------------------------------------------
reset_environment();
seed_post( 501, 'draft' );
$result = Citex_Populator::finalize_question( 501 );
check( '[status] finalising an existing Draft with no explicit status succeeds', is_wp_error( $result ), false );
check( '[status] a Draft is NOT published by finalisation unless explicitly requested', get_post_status( 501 ), 'draft' );

reset_environment();
seed_post( 502, 'publish' );
$result = Citex_Populator::finalize_question( 502 );
check( '[status] finalising an existing Published post with no explicit status succeeds', is_wp_error( $result ), false );
check( '[status] a Published post stays Published (no unexpected status change)', get_post_status( 502 ), 'publish' );

reset_environment();
seed_post( 503, 'draft' );
$result = Citex_Populator::finalize_question( 503, array( 'post_status' => 'publish' ) );
check( '[status] an explicit target status (used by populate_one()) succeeds', is_wp_error( $result ), false );
check( '[status] the explicit target status is what gets applied — this is how populate_one() actually publishes', get_post_status( 503 ), 'publish' );

// ---------------------------------------------------------------------
// finalize_question(): the actual save-lifecycle mechanism fired
// ---------------------------------------------------------------------
reset_environment();
seed_post( 504, 'publish' );
Citex_Populator::finalize_question( 504 );
check( '[mechanism] finalize_question() calls wp_update_post() exactly once (the WordPress-native save that fires save_post/wp_insert_post/wp_after_insert_post/transition_post_status)', $GLOBALS['__wp_update_post_calls'], 1 );
check( '[mechanism] finalize_question() calls clean_post_cache() for this post', $GLOBALS['__clean_post_cache_calls'], array( 504 ) );
check( '[mechanism] finalize_question() explicitly fires acf/save_post for this post (ACF\'s own admin pipeline is the only other thing that does)', $GLOBALS['__acf_save_post_calls'], array( 504 ) );

// ---------------------------------------------------------------------
// finalize_question(): content is never touched — taxonomy terms and ACF
// values are read back and must be identical before/after.
// ---------------------------------------------------------------------
reset_environment();
seed_post( 505, 'publish' );
$GLOBALS['__post_terms'][505]['reference-categories'] = array( 10, 20 );
$GLOBALS['__acf_values'][505] = array( 'scenario' => 'Unchanged scenario text.', 'fixed_text' => '|, ||.' );
$result = Citex_Populator::finalize_question( 505 );
check( '[content preserved] finalisation succeeds when nothing actually changes', is_wp_error( $result ), false );
check( '[content preserved] taxonomy terms are unchanged after finalisation', $GLOBALS['__post_terms'][505]['reference-categories'], array( 10, 20 ) );
check( '[content preserved] ACF values are unchanged after finalisation', $GLOBALS['__acf_values'][505], array( 'scenario' => 'Unchanged scenario text.', 'fixed_text' => '|, ||.' ) );

// ---------------------------------------------------------------------
// finalize_question(): if firing the save lifecycle unexpectedly mutates
// ACF or taxonomy data (e.g. another plugin's acf/save_post listener has a
// side effect), that is reported as a failure — never silently accepted as
// success. This is the safety property that makes it safe to run this
// against 200 already-correct existing questions without risking silent
// content drift.
// ---------------------------------------------------------------------
reset_environment();
seed_post( 506, 'publish' );
$GLOBALS['__acf_values'][506] = array( 'scenario' => 'Original scenario.' );
$GLOBALS['__simulate_acf_mutation_for_post'] = 506;
$mutated = Citex_Populator::finalize_question( 506 );
check( '[safety] an ACF value unexpectedly changed by the save lifecycle FAILS, rather than succeeding silently', is_wp_error( $mutated ), true );
check( '[safety] error code identifies the ACF content change', is_wp_error( $mutated ) ? $mutated->get_error_code() : null, 'citex_finalize_acf_changed' );

reset_environment();
seed_post( 507, 'publish' );
$GLOBALS['__post_terms'][507]['reference-categories'] = array( 10, 20 );
$GLOBALS['__simulate_terms_mutation_for_post'] = 507;
$mutated_terms = Citex_Populator::finalize_question( 507 );
check( '[safety] a taxonomy term unexpectedly changed by the save lifecycle FAILS, rather than succeeding silently', is_wp_error( $mutated_terms ), true );
check( '[safety] error code identifies the taxonomy content change', is_wp_error( $mutated_terms ) ? $mutated_terms->get_error_code() : null, 'citex_finalize_terms_changed' );

// ---------------------------------------------------------------------
// maybe_handle_finalize_submit(): the admin action for repairing existing
// questions (Questions page "Finalise" button / batch).
// ---------------------------------------------------------------------
function invoke_finalize_submit( $populator ) {
	try {
		$populator->maybe_handle_finalize_submit();
		return array( 'type' => 'no_action' );
	} catch ( Citex_Test_Redirect_Signal $e ) {
		return array( 'type' => 'redirect', 'location' => $e->location );
	} catch ( Citex_Test_Die_Signal $e ) {
		return array( 'type' => 'die', 'message' => $e->getMessage() );
	}
}

$populator = new Citex_Populator();

// Not our submit marker at all: returns without redirecting, so other
// handlers in Citex_Admin::handle_admin_actions() still get a chance to run.
reset_environment();
$_POST = array();
check( '[admin action] with no submit marker, the handler does nothing (no redirect)', invoke_finalize_submit( $populator )['type'], 'no_action' );

// Capability check: a user without manage_options is refused via wp_die(),
// matching every other Citex admin handler's convention.
reset_environment();
$GLOBALS['__can_manage_options'] = false;
$_POST = array( 'citex_finalize_submit' => '1', 'citex_finalize_post_ids' => array( '501' ) );
$outcome = invoke_finalize_submit( $populator );
check( '[admin action] a user without manage_options is refused', $outcome['type'], 'die' );

// No post IDs selected: a warning notice, not an error, and it still redirects.
reset_environment();
$_POST = array( 'citex_finalize_submit' => '1', 'citex_finalize_post_ids' => array() );
$outcome = invoke_finalize_submit( $populator );
check( '[admin action] no selection redirects back to the Questions page', $outcome['type'], 'redirect' );
check( '[admin action] no selection redirects to citex-questions', false !== strpos( $outcome['location'] ?? '', 'page=citex-questions' ), true );
check( '[admin action] no selection sets a warning notice', end( $GLOBALS['__notices'] )['type'] ?? null, 'warning' );

// Too many post IDs in one submission: rejected outright (never partially
// processed), per MAX_FINALIZE_BATCH — this is what keeps "batch support"
// from accidentally becoming "run against all 200 questions at once".
reset_environment();
$too_many = array_map( 'strval', range( 1, Citex_Populator::MAX_FINALIZE_BATCH + 1 ) );
$_POST = array( 'citex_finalize_submit' => '1', 'citex_finalize_post_ids' => $too_many );
$outcome = invoke_finalize_submit( $populator );
check( '[admin action] more than MAX_FINALIZE_BATCH post IDs is rejected without processing any of them', end( $GLOBALS['__notices'] )['type'] ?? null, 'error' );
check( '[admin action] rejecting an oversized batch does not call wp_update_post at all', $GLOBALS['__wp_update_post_calls'], 0 );

// A real, single-question finalisation (the "first implementation" path:
// one selected question at a time) succeeds and reports success.
reset_environment();
seed_post( 601, 'publish' );
$GLOBALS['__editable_post_ids'] = array( 601 );
$_POST = array( 'citex_finalize_submit' => '1', 'citex_finalize_post_ids' => array( '601' ) );
$outcome = invoke_finalize_submit( $populator );
check( '[admin action] a single selected, editable question is finalised successfully', $outcome['type'], 'redirect' );
$notice = end( $GLOBALS['__notices'] );
check( '[admin action] success notice type', $notice['type'] ?? null, 'success' );
check( '[admin action] success notice names the finalised post ID', false !== strpos( $notice['message'] ?? '', '601' ), true );
check( '[admin action] the question\'s status is untouched by finalisation', get_post_status( 601 ), 'publish' );

// A batch of several valid post IDs — proving the handler itself is
// batch-capable even though the current UI only ever submits one at a time.
reset_environment();
seed_post( 701, 'draft' );
seed_post( 702, 'publish' );
$GLOBALS['__editable_post_ids'] = array( 701, 702 );
$_POST = array( 'citex_finalize_submit' => '1', 'citex_finalize_post_ids' => array( '701', '702' ) );
$outcome = invoke_finalize_submit( $populator );
$notice  = end( $GLOBALS['__notices'] );
check( '[batch] a batch of two valid questions both succeed', false !== strpos( $notice['message'] ?? '', 'Succeeded: 2' ), true );
check( '[batch] Draft in the batch is not published', get_post_status( 701 ), 'draft' );
check( '[batch] Published in the batch is not drafted', get_post_status( 702 ), 'publish' );

// A post the current user cannot edit is reported as a per-item failure,
// without aborting the rest of the batch — clear, granular success/error
// reporting rather than an all-or-nothing failure.
reset_environment();
seed_post( 801, 'publish' );
seed_post( 802, 'publish' );
$GLOBALS['__editable_post_ids'] = array( 801 ); // 802 is deliberately not editable
$_POST = array( 'citex_finalize_submit' => '1', 'citex_finalize_post_ids' => array( '801', '802' ) );
$outcome = invoke_finalize_submit( $populator );
$notice  = end( $GLOBALS['__notices'] );
check( '[permissions] one editable + one non-editable question: exactly one succeeds', false !== strpos( $notice['message'] ?? '', 'Succeeded: 1' ), true );
check( '[permissions] the notice reports exactly one failure', false !== strpos( $notice['message'] ?? '', 'Failed: 1' ), true );
check( '[permissions] the notice names the specific post that failed and why', false !== strpos( $notice['message'] ?? '', '#802' ) && false !== strpos( $notice['message'] ?? '', 'no permission' ), true );

// A nonexistent post ID (e.g. deleted since the page loaded) is reported,
// never causes a fatal.
reset_environment();
$GLOBALS['__editable_post_ids'] = array( 901 );
$_POST = array( 'citex_finalize_submit' => '1', 'citex_finalize_post_ids' => array( '901' ) );
$outcome = invoke_finalize_submit( $populator );
$notice  = end( $GLOBALS['__notices'] );
check( '[missing post] a nonexistent post ID does not fatal and is reported as a failure', false !== strpos( $notice['message'] ?? '', 'post not found' ), true );

// ---------------------------------------------------------------------
// Wiring: the finalise handler is actually registered so it runs on
// admin_init, matching every other Citex admin handler's pattern (see
// tests/admin-action-feedback.test.php for the same convention applied to
// the other handlers).
// ---------------------------------------------------------------------
$reflection = new ReflectionMethod( 'Citex_Populator', 'maybe_handle_finalize_submit' );
check( '[wiring] maybe_handle_finalize_submit is public (reachable from Citex_Admin::handle_admin_actions on admin_init)', $reflection->isPublic(), true );

$admin_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-admin.php' );
check( '[wiring] Citex_Admin::handle_admin_actions references maybe_handle_finalize_submit', false !== strpos( $admin_source, 'maybe_handle_finalize_submit' ), true );

$questions_view_source = file_get_contents( __DIR__ . '/../citex-tools/admin/views/questions.php' );
check( '[wiring] the Questions page view submits citex_finalize_post_ids', false !== strpos( $questions_view_source, 'citex_finalize_post_ids' ), true );
check( '[wiring] the Questions page view is nonce-protected with the finalize action', false !== strpos( $questions_view_source, 'Citex_Populator::NONCE_FINALIZE_ACTION' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
