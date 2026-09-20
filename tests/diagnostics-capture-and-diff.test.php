<?php
/**
 * Regression tests for Citex_Diagnostics — the read-only investigative
 * tooling added for the "Published but invisible until a manual wp-admin
 * Update click" report.
 *
 * This is investigative infrastructure, not a claimed fix for the
 * underlying visibility bug: this repository has zero code-level presence
 * of the separate "Citex student app" (no REST route, shortcode, custom
 * table, or frontend rendering code exists anywhere under citex-tools/), so
 * the real mechanism it depends on cannot be identified from source code
 * that isn't in this repository. Citex_Diagnostics instead gives an admin
 * two things only the live site can actually produce: (1) exactly which
 * callbacks are registered on the save-lifecycle hooks Citex_Populator
 * already fires, read live from WordPress's own hook registry rather than
 * guessed, and (2) an exact before/after diff of one post's real state
 * across a manual Update click.
 *
 * Repo-level only, run with plain
 * `php tests/diagnostics-capture-and-diff.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

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
function sanitize_key( $v ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
}
function absint( $v ) {
	return abs( (int) $v );
}
function current_time( $type ) {
	return '2026-01-01 00:00:00';
}
function maybe_unserialize( $v ) {
	$u = @unserialize( $v );
	return false === $u && 'b:0;' !== $v ? $v : $u;
}
function __( $s, $d = '' ) {
	return $s;
}

$GLOBALS['__posts']       = array();
$GLOBALS['__postmeta']    = array();
$GLOBALS['__object_terms'] = array();
$GLOBALS['__acf_fields']  = array();
$GLOBALS['__options']     = array();

function reset_environment() {
	$GLOBALS['__posts']        = array();
	$GLOBALS['__postmeta']     = array();
	$GLOBALS['__object_terms'] = array();
	$GLOBALS['__acf_fields']   = array();
	$GLOBALS['__options']      = array();
}

function get_post( $id ) {
	return $GLOBALS['__posts'][ $id ] ?? null;
}
function get_post_meta( $id, $key = '', $single = false ) {
	$all = $GLOBALS['__postmeta'][ $id ] ?? array();
	if ( '' === $key ) {
		return $all;
	}
	return $all[ $key ] ?? array();
}
function get_object_taxonomies( $post_type, $output = 'names' ) {
	return array( 'reference_category', 'reference_exercise' );
}
function wp_get_object_terms( $post_id, $taxonomy, $args = array() ) {
	return $GLOBALS['__object_terms'][ $post_id ][ $taxonomy ] ?? array();
}
function get_fields( $post_id, $format = true ) {
	return $GLOBALS['__acf_fields'][ $post_id ] ?? array();
}
function get_option( $key, $default = false ) {
	return $GLOBALS['__options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}

require __DIR__ . '/../citex-tools/includes/class-citex-diagnostics.php';

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

function make_post( $id, $overrides = array() ) {
	return (object) array_merge(
		array(
			'ID'                => $id,
			'post_type'         => 'question',
			'post_status'       => 'draft',
			'post_title'        => 'Question Title: Harvard | ReferenceList | Book | DragDrop',
			'post_name'         => 'question-' . $id,
			'post_date'         => '2026-01-01 00:00:00',
			'post_modified'     => '2026-01-01 00:00:00',
			'post_modified_gmt' => '2026-01-01 00:00:00',
			'guid'              => 'https://example.test/?p=' . $id,
			'menu_order'        => 0,
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. capture_post_state() rejects a missing post ID / a post that doesn't
// exist, rather than silently returning an empty/misleading snapshot.
// ---------------------------------------------------------------------
reset_environment();
$missing_id = Citex_Diagnostics::capture_post_state( 0 );
check( '[1] a zero post ID is rejected', is_wp_error( $missing_id ), true );
check( '[1] error code identifies a missing post ID', is_wp_error( $missing_id ) ? $missing_id->get_error_code() : null, 'citex_diagnostics_missing_post_id' );

$not_found = Citex_Diagnostics::capture_post_state( 999 );
check( '[1] a nonexistent post ID is rejected', is_wp_error( $not_found ), true );
check( '[1] error code identifies the post as not found', is_wp_error( $not_found ) ? $not_found->get_error_code() : null, 'citex_diagnostics_post_not_found' );

// ---------------------------------------------------------------------
// 2. capture_post_state() captures core fields, meta, taxonomy terms and
// ACF values for a real post — the full state the underlying bug report's
// Step 2 asked for.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__posts'][42]    = make_post( 42, array( 'post_status' => 'publish' ) );
$GLOBALS['__postmeta'][42] = array( '_edit_lock' => array( '123:1' ), 'custom_key' => array( 'custom_value' ) );
$GLOBALS['__object_terms'][42] = array(
	'reference_category' => array( 'Book' ),
	'reference_exercise'  => array( 'Exercise 1' ),
);
$GLOBALS['__acf_fields'][42] = array( 'scenario' => 'You are referencing a book...', 'fixedText' => '|, || (||) ||. Oxford: OUP.' );

$state = Citex_Diagnostics::capture_post_state( 42 );
check( '[2] capture succeeds for a real post', is_wp_error( $state ), false );
check( '[2] core status is captured', $state['core']['post_status'], 'publish' );
check( '[2] core ID is captured', $state['core']['ID'], 42 );
check( '[2] postmeta is captured', $state['meta']['custom_key'], array( 'custom_value' ) );
check( '[2] taxonomy terms are captured', $state['terms']['reference_category'], array( 'Book' ) );
check( '[2] ACF field values are captured', $state['acf']['scenario'], 'You are referencing a book...' );

// ---------------------------------------------------------------------
// 3. diff_snapshots() reports only what actually changed, across meta,
// terms, and ACF — nothing invented, nothing missed. This is the exact
// before/after comparison the bug report's Step 2 requires.
// ---------------------------------------------------------------------
$before = array(
	'core' => array( 'post_status' => 'publish' ),
	'meta' => array( 'custom_key' => array( 'old_value' ) ),
	'terms' => array( 'reference_category' => array( 'Book' ) ),
	'acf'  => array( 'scenario' => 'Old scenario text.' ),
	'capturedAt' => '2026-01-01 00:00:00',
);
$after = array(
	'core' => array( 'post_status' => 'publish' ),
	'meta' => array( 'custom_key' => array( 'new_value' ), 'newly_added_by_update' => array( 'app_index_ready' ) ),
	'terms' => array( 'reference_category' => array( 'Book' ) ),
	'acf'  => array( 'scenario' => 'Old scenario text.' ),
	'capturedAt' => '2026-01-01 00:05:00',
);
$diff = Citex_Diagnostics::diff_snapshots( $before, $after );
check( '[3] unchanged core status is not reported as a diff', array_key_exists( 'core.post_status', $diff ), false );
check( '[3] a changed meta value is reported', $diff['meta.custom_key.0']['before'], 'old_value' );
check( '[3] a changed meta value reports the new value', $diff['meta.custom_key.0']['after'], 'new_value' );
check( '[3] a meta key that only exists after Update is reported', $diff['meta.newly_added_by_update.0']['after'], 'app_index_ready' );
check( '[3] a meta key that only exists after Update has no "before" value', $diff['meta.newly_added_by_update.0']['before'], null );
check( '[3] the capturedAt timestamp itself is never reported as a diff (it always differs and is not meaningful)', array_key_exists( 'capturedAt', $diff ), false );
check( '[3] unchanged ACF/terms values produce no diff entries for them', array_key_exists( 'acf.scenario', $diff ) || array_key_exists( 'terms.reference_category.0', $diff ), false );

// A real before/after pair with genuinely no differences signals that the
// manual Update click changed nothing WordPress/ACF-visible — which is
// itself diagnostic (points at an external index/cache Citex cannot see).
$identical_diff = Citex_Diagnostics::diff_snapshots( $before, $before );
check( '[3] two identical snapshots produce an empty diff', $identical_diff, array() );

// ---------------------------------------------------------------------
// 4. hooks_for_post_type() includes every static save-lifecycle hook
// Citex_Populator fires, plus the post-type-specific and status-transition
// hooks WordPress core also fires automatically as part of the same
// wp_update_post() call.
// ---------------------------------------------------------------------
$hooks = Citex_Diagnostics::hooks_for_post_type( 'question' );
foreach ( array( 'wp_insert_post', 'wp_after_insert_post', 'save_post', 'transition_post_status', 'acf/save_post', 'clean_post_cache' ) as $expected_hook ) {
	check( "[4] hooks_for_post_type includes the static hook \"$expected_hook\"", in_array( $expected_hook, $hooks, true ), true );
}
check( '[4] hooks_for_post_type includes the post-type-specific save hook', in_array( 'save_post_question', $hooks, true ), true );
check( '[4] hooks_for_post_type includes the post-type-specific publish hook', in_array( 'publish_question', $hooks, true ), true );
check( '[4] hooks_for_post_type includes the draft_to_publish transition (a fresh Populator post created directly as Draft-then-Published)', in_array( 'draft_to_publish', $hooks, true ), true );
check( '[4] hooks_for_post_type includes the new_to_publish transition (a Populator post created directly as Published)', in_array( 'new_to_publish', $hooks, true ), true );

// ---------------------------------------------------------------------
// 5. list_registered_callbacks() reports real, live callbacks from
// WordPress's own $wp_filter registry — a plain function, a static class
// method, and a closure — never a guess at what "might" be listening.
// ---------------------------------------------------------------------
function citex_test_diagnostic_listener() {}
class Citex_Test_Diagnostic_Listener {
	public static function on_save() {}
}

global $wp_filter;
$wp_filter = array(
	'save_post' => (object) array(
		'callbacks' => array(
			10 => array(
				array( 'function' => 'citex_test_diagnostic_listener', 'accepted_args' => 1 ),
			),
			20 => array(
				array( 'function' => array( 'Citex_Test_Diagnostic_Listener', 'on_save' ), 'accepted_args' => 1 ),
			),
		),
	),
	'acf/save_post' => array(
		10 => array(
			array( 'function' => function () {}, 'accepted_args' => 1 ),
		),
	),
	'transition_post_status' => array(),
);

$report = Citex_Diagnostics::list_registered_callbacks( array( 'save_post', 'acf/save_post', 'transition_post_status', 'clean_post_cache' ) );
check( '[5] save_post reports both registered callbacks', count( $report['save_post'] ), 2 );
check( '[5] the plain function callback is identified by name', false !== strpos( $report['save_post'][0]['callback'], 'citex_test_diagnostic_listener' ), true );
check( '[5] the static method callback is identified by Class::method', false !== strpos( $report['save_post'][1]['callback'], 'Citex_Test_Diagnostic_Listener::on_save' ), true );
check( '[5] priorities are reported in ascending order', array( $report['save_post'][0]['priority'], $report['save_post'][1]['priority'] ), array( 10, 20 ) );
check( '[5] the closure callback on acf/save_post is identified as a Closure', false !== strpos( $report['acf/save_post'][0]['callback'], 'Closure' ), true );
check( '[5] a hook with no registered callbacks reports an empty list, not an error', $report['transition_post_status'], array() );
check( '[5] a hook that is not registered in $wp_filter at all reports an empty list', $report['clean_post_cache'], array() );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
