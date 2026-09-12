<?php
/**
 * Regression test for a real production complaint: after successfully
 * populating a batch (e.g. WR01-WR09) into the real Reference List, the
 * NEXT generate batch reused those exact same IDs, so population then
 * failed on every single one with "a record with this exact title already
 * exists" (0 created).
 *
 * Root cause: Citex_Generator::collect_used_question_ids() only consulted
 * Citex_Scanner::get_last_scan()'s CACHED snapshot, which goes stale the
 * moment a population run creates new posts after that snapshot was taken
 * — nothing re-synced it in between. The fix re-syncs fresh from
 * WordPress (Citex_Scanner::sync_from_wordpress()) every time used IDs
 * are collected, falling back to the cached scan only if a fresh sync
 * can't run at all.
 *
 * Repo-level only, run with plain
 * `php tests/generator-used-ids-fresh-scan.test.php` — not shipped in
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
function sanitize_text_field( $v ) {
	return trim( (string) $v );
}
function sanitize_key( $v ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
}
function esc_url_raw( $v ) {
	return (string) $v;
}
function get_edit_post_link( $id, $context = 'display' ) {
	return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
}
function get_the_title( $post ) {
	return is_object( $post ) ? (string) ( $post->post_title ?? '' ) : '';
}
function post_type_exists( $pt ) {
	return 'question' === $pt;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function __( $s, $d = '' ) {
	return $s;
}

$GLOBALS['__options'] = array();
function get_option( $key, $default = false ) {
	return $GLOBALS['__options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}

$GLOBALS['__posts'] = array();
function get_posts( $args ) {
	$post_type = $args['post_type'] ?? '';
	$statuses  = (array) ( $args['post_status'] ?? array( 'publish' ) );
	$matches   = array();
	foreach ( $GLOBALS['__posts'] as $id => $p ) {
		if ( $p['post_type'] === $post_type && in_array( $p['post_status'], $statuses, true ) ) {
			$matches[ $id ] = $p;
		}
	}
	ksort( $matches );
	$objects = array();
	foreach ( $matches as $id => $p ) {
		$objects[] = (object) array_merge( $p, array( 'ID' => $id ) );
	}
	return $objects;
}

require __DIR__ . '/../citex-tools/includes/class-citex-scanner.php';
require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-populator.php';
require __DIR__ . '/../citex-tools/includes/class-citex-generator.php';

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

function invoke_collect_used_question_ids( $pending ) {
	$generator  = new Citex_Generator();
	$reflection = new ReflectionMethod( 'Citex_Generator', 'collect_used_question_ids' );
	$reflection->setAccessible( true );
	return $reflection->invoke( $generator, $pending );
}

// ---------------------------------------------------------------------
// 1. Configure a Reference List URL (required for sync_from_wordpress()
// to resolve a post type) and seed a STALE cached scan that does NOT
// know about WR01 — simulating "the scan was taken before the last
// population run created it".
// ---------------------------------------------------------------------
update_option( Citex_Scanner::OPTION_URL, 'https://example.test/?post_type=question', false );
update_option(
	Citex_Scanner::OPTION_SCAN,
	array(
		'scannedAt' => '2020-01-01T00:00:00+00:00',
		'questions' => array(),
	),
	false
);

// ---------------------------------------------------------------------
// 2. A real WordPress post for WR01 now exists (e.g. from a population
// run that happened AFTER that stale scan was cached) — the cached scan
// still doesn't know about it, but the live database does.
// ---------------------------------------------------------------------
$GLOBALS['__posts'][501] = array(
	'post_type'   => 'question',
	'post_status' => 'publish',
	'post_title'  => 'Harvard | ReferenceList | Website | DragDrop | WR01',
);

$used = invoke_collect_used_question_ids( array() );
check( '[1] CRITICAL — a live WordPress post missing from the stale cached scan is still picked up via a fresh sync', isset( $used['WR01'] ), true );

// ---------------------------------------------------------------------
// 3. Pending (not-yet-populated) question IDs are still included
// alongside the freshly-synced live ones — neither source is dropped.
// ---------------------------------------------------------------------
$used_with_pending = invoke_collect_used_question_ids( array( array( 'questionId' => 'WR05' ) ) );
check( '[2] a pending question ID is still included', isset( $used_with_pending['WR05'] ), true );
check( '[2] the freshly-synced live post ID is still included alongside it', isset( $used_with_pending['WR01'] ), true );

// ---------------------------------------------------------------------
// 4. When a fresh sync cannot run at all (e.g. no Reference List URL
// configured), the cached scan is still used as a fallback rather than
// silently returning nothing.
// ---------------------------------------------------------------------
update_option( Citex_Scanner::OPTION_URL, '', false );
update_option(
	Citex_Scanner::OPTION_SCAN,
	array(
		'scannedAt' => '2020-01-01T00:00:00+00:00',
		'questions' => array( array( 'questionId' => 'WR09' ) ),
	),
	false
);
$used_fallback = invoke_collect_used_question_ids( array() );
check( '[3] falls back to the cached scan when a fresh sync cannot run', isset( $used_fallback['WR09'] ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
