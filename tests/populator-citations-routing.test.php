<?php
/**
 * Regression tests for the Citations/Reference-List routing split — the
 * live WordPress site has "Citations" as a genuinely SEPARATE real post
 * type/admin list from "Reference List" (confirmed by the user's own
 * screenshot of the admin sidebar showing both as distinct top-level CPT
 * screens), so In-Text Citation questions must populate there, never into
 * the Reference List.
 *
 * Covers:
 *  - Citex_Scanner::target_for_group() — the pure classifier deciding
 *    which of the two real destinations a question's own `group` belongs to.
 *  - Citex_Scanner's per-target option separation: get_question_list_url()/
 *    get_last_scan()/sync_from_wordpress() for 'reference' and 'citations'
 *    read/write genuinely different options and never leak into each other.
 *  - Citex_Populator::populate_batch() threads its own $target parameter
 *    correctly into Citex_Scanner (proven via the target-specific error
 *    message each destination reports when its own URL isn't configured —
 *    reachable without any ACF/taxonomy stub since that failure returns
 *    before any ACF-dependent code runs).
 *  - Citex_Populator::maybe_handle_submit()'s own source literally routes
 *    each eligible question through Citex_Scanner::target_for_group()
 *    into one of exactly 2 batches — mirrors this codebase's own
 *    established pattern (see tests/populator-mcq-population.test.php's
 *    own literal-source-string check) for logic embedded in the one
 *    public entry point that is otherwise impractical to unit test
 *    directly (nonce/capability/redirect machinery).
 *
 * Repo-level only, run with plain
 * `php tests/populator-citations-routing.test.php` — not shipped in
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
function sanitize_text_field( $v ) {
	return trim( (string) $v );
}
function absint( $v ) {
	return abs( intval( $v ) );
}
function __( $s, $d = '' ) {
	return $s;
}
function esc_url_raw( $v ) {
	return (string) $v;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

$GLOBALS['__options']              = array();
$GLOBALS['__posts']                = array();
$GLOBALS['__registered_post_types'] = array( 'citex-reference', 'citex-citations' );
$GLOBALS['__next_post_id']         = 900;

function get_option( $key, $default = false ) {
	return $GLOBALS['__options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}
function post_type_exists( $post_type ) {
	return in_array( $post_type, $GLOBALS['__registered_post_types'], true );
}
function get_the_title( $post ) {
	if ( is_object( $post ) ) {
		return $post->post_title ?? '';
	}
	return $GLOBALS['__posts'][ $post ]['post_title'] ?? '';
}
function get_edit_post_link( $post_id, $context = 'display' ) {
	return 'https://example.com/wp-admin/post.php?post=' . $post_id . '&action=edit';
}
function get_posts( $args ) {
	$post_type = $args['post_type'] ?? '';
	$statuses  = (array) ( $args['post_status'] ?? array( 'publish' ) );
	$title     = $args['title'] ?? null;
	$matches   = array();
	foreach ( $GLOBALS['__posts'] as $id => $p ) {
		if ( $p['post_type'] !== $post_type || ! in_array( $p['post_status'], $statuses, true ) ) {
			continue;
		}
		if ( null !== $title && $p['post_title'] !== $title ) {
			continue;
		}
		$matches[ $id ] = $p;
	}
	if ( 'ids' === ( $args['fields'] ?? 'all' ) ) {
		return array_map( 'intval', array_keys( $matches ) );
	}
	$objects = array();
	foreach ( $matches as $id => $p ) {
		$objects[] = (object) array_merge( $p, array( 'ID' => $id ) );
	}
	return $objects;
}

require __DIR__ . '/../citex-tools/includes/class-citex-scanner.php';
require __DIR__ . '/../citex-tools/includes/class-citex-populator.php';

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
function invoke_private( $object, $method, array $args = array() ) {
	$reflection = new ReflectionMethod( get_class( $object ), $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

// ---------------------------------------------------------------------
// 1. Citex_Scanner::target_for_group() — the pure classifier.
// ---------------------------------------------------------------------
check( '[1] "InTextCitation" routes to citations', Citex_Scanner::target_for_group( 'InTextCitation' ), 'citations' );
check( '[1] "ReferenceList" routes to reference', Citex_Scanner::target_for_group( 'ReferenceList' ), 'reference' );
check( '[1] an empty group routes to reference (the pre-existing default group)', Citex_Scanner::target_for_group( '' ), 'reference' );
check( '[1] any other/unrecognised group routes to reference, never a silent 3rd bucket', Citex_Scanner::target_for_group( 'SomethingElse' ), 'reference' );

// ---------------------------------------------------------------------
// 2. get_question_list_url()/get_last_scan(): 'reference' and 'citations'
// read/write genuinely different options, never leaking into each other.
// ---------------------------------------------------------------------
update_option( Citex_Scanner::OPTION_URL, 'https://example.com/wp-admin/edit.php?post_type=citex-reference' );
update_option( Citex_Scanner::OPTION_CITATIONS_URL, 'https://example.com/wp-admin/edit.php?post_type=citex-citations' );
check( '[2] get_question_list_url() (default target) reads the Reference List option', Citex_Scanner::get_question_list_url(), 'https://example.com/wp-admin/edit.php?post_type=citex-reference' );
check( '[2] get_question_list_url("reference") reads the same Reference List option', Citex_Scanner::get_question_list_url( 'reference' ), 'https://example.com/wp-admin/edit.php?post_type=citex-reference' );
check( '[2] get_question_list_url("citations") reads the genuinely separate Citations option', Citex_Scanner::get_question_list_url( 'citations' ), 'https://example.com/wp-admin/edit.php?post_type=citex-citations' );
check( '[2] an unrecognised target string falls back to the Reference List option', Citex_Scanner::get_question_list_url( 'bogus' ), 'https://example.com/wp-admin/edit.php?post_type=citex-reference' );

// ---------------------------------------------------------------------
// 3. sync_from_wordpress(): each target resolves its OWN post type from
// its OWN URL, and stores into its OWN scan option — proven by seeding
// one post into each of the two DIFFERENT real post types and confirming
// each target's scan sees only its own.
// ---------------------------------------------------------------------
$GLOBALS['__posts'][901] = array( 'post_type' => 'citex-reference', 'post_status' => 'draft', 'post_title' => 'Harvard | ReferenceList | Book | DragDrop | BK01' );
$GLOBALS['__posts'][902] = array( 'post_type' => 'citex-citations', 'post_status' => 'draft', 'post_title' => 'Harvard | InTextCitation | Book | DragDrop | IB01' );

$reference_scan = Citex_Scanner::sync_from_wordpress( 'reference' );
check( '[3] reference sync succeeds', is_wp_error( $reference_scan ), false );
if ( ! is_wp_error( $reference_scan ) ) {
	check( '[3] reference scan resolves the Reference List post type', $reference_scan['postType'], 'citex-reference' );
	check( '[3] reference scan sees exactly 1 question (only its own post type)', $reference_scan['total'], 1 );
	check( '[3] reference scan\'s question is BK01, never the Citations post', $reference_scan['questions'][0]['questionId'] ?? null, 'BK01' );
}

$citations_scan = Citex_Scanner::sync_from_wordpress( 'citations' );
check( '[3] citations sync succeeds', is_wp_error( $citations_scan ), false );
if ( ! is_wp_error( $citations_scan ) ) {
	check( '[3] citations scan resolves the Citations post type, genuinely different from Reference List', $citations_scan['postType'], 'citex-citations' );
	check( '[3] citations scan sees exactly 1 question (only its own post type)', $citations_scan['total'], 1 );
	check( '[3] citations scan\'s question is IB01, never the Reference List post', $citations_scan['questions'][0]['questionId'] ?? null, 'IB01' );
}

check( '[3] the two scans were stored under genuinely different options', Citex_Scanner::get_last_scan( 'reference' ) === Citex_Scanner::get_last_scan( 'citations' ), false );

// ---------------------------------------------------------------------
// 4. An unconfigured Citations URL reports its OWN error, never silently
// reusing (or being confused with) the Reference List's own message.
// ---------------------------------------------------------------------
$GLOBALS['__options'] = array(); // Neither URL configured.
$no_ref_error = Citex_Scanner::sync_from_wordpress( 'reference' );
check( '[4] an unconfigured Reference List URL fails', is_wp_error( $no_ref_error ), true );
check( '[4] with its own Reference-List-specific error code', is_wp_error( $no_ref_error ) ? $no_ref_error->get_error_code() : null, 'citex_no_reference_url' );

$no_citations_error = Citex_Scanner::sync_from_wordpress( 'citations' );
check( '[4] an unconfigured Citations URL fails', is_wp_error( $no_citations_error ), true );
check( '[4] with its own Citations-specific error code, never the Reference List one', is_wp_error( $no_citations_error ) ? $no_citations_error->get_error_code() : null, 'citex_no_citations_url' );
check( '[4] the Citations error message names Citations, not the Reference List', is_wp_error( $no_citations_error ) ? false !== strpos( $no_citations_error->get_error_message(), 'Citations' ) : false, true );

// ---------------------------------------------------------------------
// 5. Citex_Populator::populate_batch() threads $target correctly into
// Citex_Scanner — proven via each destination's own target-specific
// "could not determine the post type" error, which only differs if
// $target genuinely reached Citex_Scanner rather than being dropped or
// swapped. Reachable with zero ACF/taxonomy stubbing since this failure
// returns before any such code runs.
// ---------------------------------------------------------------------
$populator = new Citex_Populator();
$question  = array( 'key' => 'k1', 'questionId' => 'BK01', 'type' => 'DragDrop', 'group' => 'ReferenceList' );

$GLOBALS['__options'] = array(); // Neither URL configured — sync_from_wordpress() fails for both.
$reference_batch = invoke_private( $populator, 'populate_batch', array( array( $question ), 'reference', 'draft' ) );
check( '[5] populate_batch("reference") produces no created posts when unconfigured', $reference_batch['created'], array() );
check( '[5] populate_batch("reference") fails with the Reference-List-specific message', false !== strpos( $reference_batch['failed'][0] ?? '', 'Reference List URL is not configured' ), true );

$citations_batch = invoke_private( $populator, 'populate_batch', array( array( $question ), 'citations', 'draft' ) );
check( '[5] populate_batch("citations") produces no created posts when unconfigured', $citations_batch['created'], array() );
check( '[5] populate_batch("citations") fails with the Citations-specific message, never the Reference List one', false !== strpos( $citations_batch['failed'][0] ?? '', 'Citations List URL is not configured' ), true );

// ---------------------------------------------------------------------
// 6. maybe_handle_submit()'s own source routes every eligible question
// through Citex_Scanner::target_for_group() into exactly one of the two
// batches — mirrors this codebase's own established "literal source
// string" pattern (see tests/populator-mcq-population.test.php) for
// logic embedded in the one public entry point whose nonce/capability/
// redirect machinery makes it impractical to invoke directly in a test.
// ---------------------------------------------------------------------
$populator_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-populator.php' );
check(
	"[6] maybe_handle_submit() routes every eligible question via Citex_Scanner::target_for_group() into one of exactly 2 batches",
	false !== strpos( $populator_source, "\$batches[ Citex_Scanner::target_for_group( \$question['group'] ?? '' ) ][] = \$question;" ),
	true
);
check(
	'[6] the batches are initialised as exactly {reference, citations} — no silent 3rd destination',
	false !== strpos( $populator_source, "\$batches = array( 'reference' => array(), 'citations' => array() );" ),
	true
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
