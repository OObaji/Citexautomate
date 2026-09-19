<?php
/**
 * Regression test for a real reported bug: "Generate & Publish" (and plain
 * Populate) silently did NOTHING for DragDrop questions — no admin notice,
 * no redirect, just the request going quiet — while the identical flow
 * worked fine for MCQ. Root cause: populate_one()'s try/catch only caught
 * `Exception`, never `Throwable`. In PHP 7+ a PHP Error (TypeError,
 * ArgumentCountError, etc.) is NOT an Exception, so it was never caught at
 * all — it crashed the whole request outright, with no WP_Error, no
 * notice, and no redirect. DragDrop's own write/verify path (ACF repeater
 * rows whose real shape is introspected dynamically — see
 * write_repeater_rows()'s own docblock) has far more surface area for this
 * than MCQ's plain scalar field writes, which is why only DragDrop was
 * ever reported affected.
 *
 * This proves populate_one() now catches a thrown Error the same way it
 * already caught a thrown Exception: rolling the partially-created post
 * back (wp_delete_post) and returning a clean, diagnosable WP_Error
 * instead of crashing the request.
 *
 * Repo-level only, run with plain
 * `php tests/populator-throwable-catch.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

$GLOBALS['__posts']                         = array();
$GLOBALS['__post_meta']                     = array();
$GLOBALS['__post_terms']                    = array();
$GLOBALS['__terms_full']                    = array();
$GLOBALS['__taxonomies_by_post_type']       = array( 'question' => array( 'reference_category' ) );
$GLOBALS['__acf_fields']                    = array();
$GLOBALS['__acf_field_groups_by_post_type'] = array();
$GLOBALS['__post_field_objects']            = array();
$GLOBALS['__acf_values']                    = array();
$GLOBALS['__registered_post_types']         = array( 'question' );
$GLOBALS['__next_post_id']                  = 100;
$GLOBALS['__deleted_posts']                 = array();
$GLOBALS['__wp_update_post_calls']          = 0;
$GLOBALS['__clean_post_cache_calls']        = array();
$GLOBALS['__acf_save_post_calls']           = array();
$GLOBALS['__options']                       = array();
$GLOBALS['__throw_on_update_field_key']     = '';

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
function wp_unslash( $v ) {
	return $v;
}
function absint( $v ) {
	return abs( intval( $v ) );
}
function __( $s, $d = '' ) {
	return $s;
}
function esc_html__( $s, $d = '' ) {
	return $s;
}
function post_type_exists( $pt ) {
	return in_array( $pt, $GLOBALS['__registered_post_types'], true );
}

function get_posts( $args ) {
	$post_type    = $args['post_type'] ?? '';
	$statuses     = (array) ( $args['post_status'] ?? array( 'publish' ) );
	$title_filter = array_key_exists( 'title', $args ) ? $args['title'] : null;
	$fields       = $args['fields'] ?? 'all';
	$matches      = array();
	foreach ( $GLOBALS['__posts'] as $id => $p ) {
		if ( $p['post_type'] !== $post_type || ! in_array( $p['post_status'], $statuses, true ) ) {
			continue;
		}
		if ( null !== $title_filter && $p['post_title'] !== $title_filter ) {
			continue;
		}
		$matches[ $id ] = $p;
	}
	if ( 'ids' === $fields ) {
		return array_map( 'intval', array_keys( $matches ) );
	}
	$objects = array();
	foreach ( $matches as $id => $p ) {
		$objects[] = (object) array_merge( $p, array( 'ID' => $id ) );
	}
	return $objects;
}

function get_post( $id ) {
	if ( ! isset( $GLOBALS['__posts'][ $id ] ) ) {
		return null;
	}
	return (object) array_merge( $GLOBALS['__posts'][ $id ], array( 'ID' => $id ) );
}

function wp_insert_post( $args, $wp_error = false ) {
	$id = $GLOBALS['__next_post_id']++;
	$GLOBALS['__posts'][ $id ] = array(
		'post_type'    => $args['post_type'] ?? '',
		'post_status'  => $args['post_status'] ?? 'draft',
		'post_title'   => $args['post_title'] ?? '',
		'post_content' => $args['post_content'] ?? '',
		'post_excerpt' => $args['post_excerpt'] ?? '',
		'menu_order'   => $args['menu_order'] ?? 0,
	);
	return $id;
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
	if ( 'acf/save_post' === $hook ) {
		$GLOBALS['__acf_save_post_calls'][] = $args[0] ?? null;
	}
}

function get_option( $key, $default = false ) {
	return $GLOBALS['__options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}

function wp_delete_post( $id, $force = false ) {
	if ( isset( $GLOBALS['__posts'][ $id ] ) ) {
		unset( $GLOBALS['__posts'][ $id ] );
		$GLOBALS['__deleted_posts'][] = $id;
	}
	return true;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	if ( '' === $key ) {
		return $GLOBALS['__post_meta'][ $post_id ] ?? array();
	}
	$values = $GLOBALS['__post_meta'][ $post_id ][ $key ] ?? array();
	return $single ? ( $values[0] ?? '' ) : $values;
}
function add_post_meta( $post_id, $key, $value ) {
	$GLOBALS['__post_meta'][ $post_id ][ $key ][] = $value;
	return true;
}
function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['__post_meta'][ $post_id ][ $key ] );
	return true;
}
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['__post_meta'][ $post_id ][ $key ] = array( $value );
	return true;
}
function maybe_unserialize( $v ) { return $v; }

function get_object_taxonomies( $post_type, $output = 'names' ) {
	return $GLOBALS['__taxonomies_by_post_type'][ $post_type ] ?? array();
}
function wp_get_object_terms( $post_id, $taxonomy, $args = array() ) {
	return $GLOBALS['__post_terms'][ $post_id ][ $taxonomy ] ?? array();
}
function wp_set_object_terms( $post_id, $term_ids, $taxonomy, $append = false ) {
	$GLOBALS['__post_terms'][ $post_id ][ $taxonomy ] = array_values( (array) $term_ids );
	return $term_ids;
}
function get_term_by( $field, $value, $taxonomy ) { return false; }
function get_terms( $args = array() ) {
	$taxonomy      = $args['taxonomy'] ?? '';
	$parent_filter = array_key_exists( 'parent', $args ) ? $args['parent'] : null;
	$out = array();
	foreach ( ( $GLOBALS['__terms_full'][ $taxonomy ] ?? array() ) as $term_id => $t ) {
		if ( null !== $parent_filter && (int) ( $t['parent'] ?? 0 ) !== (int) $parent_filter ) {
			continue;
		}
		$out[] = (object) array( 'term_id' => $term_id, 'name' => $t['name'] ?? '', 'parent' => (int) ( $t['parent'] ?? 0 ), 'taxonomy' => $taxonomy );
	}
	return $out;
}

function acf_get_field( $key ) {
	return $GLOBALS['__acf_fields'][ $key ] ?? false;
}
function acf_get_field_groups( $args = array() ) {
	$post_type = $args['post_type'] ?? '';
	return $GLOBALS['__acf_field_groups_by_post_type'][ $post_type ] ?? array();
}
function acf_get_fields( $group ) {
	return $group['fields'] ?? array();
}
function get_field_objects( $post_id, $formatted = false, $load_value = false ) {
	return $GLOBALS['__post_field_objects'][ $post_id ] ?? array();
}
function get_field( $selector, $post_id, $format = false ) {
	return $GLOBALS['__acf_values'][ $post_id ][ $selector ] ?? null;
}
// The fault injection point: simulates a real PHP Error (not Exception)
// occurring deep inside a single ACF write call — e.g. the kind of
// TypeError a live site's own unusual DragDrop repeater field shape could
// realistically trigger. Only fires for the one field key a test arms via
// $GLOBALS['__throw_on_update_field_key'], so every other write (and every
// other test file requiring this same populator class elsewhere) behaves
// exactly as before.
function update_field( $selector, $value, $post_id ) {
	if ( '' !== $GLOBALS['__throw_on_update_field_key'] && $selector === $GLOBALS['__throw_on_update_field_key'] ) {
		throw new TypeError( 'Simulated PHP Error: array_key_exists(): Argument #2 ($array) must be of type array, null given' );
	}
	$GLOBALS['__acf_values'][ $post_id ][ $selector ] = $value;
	return true;
}

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

function invoke_private( $object, $method, $args ) {
	$reflection = new ReflectionMethod( 'Citex_Populator', $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

// Book (1) -> Exercise 1 (2).
$GLOBALS['__terms_full']['reference_category'] = array(
	1 => array( 'name' => 'Book', 'parent' => 0 ),
	2 => array( 'name' => 'Exercise 1', 'parent' => 1 ),
);
foreach (
	array(
		Citex_Populator::FIELD_FIXED_TEXT      => 'text',
		Citex_Populator::FIELD_QUESTION_PARTS  => 'repeater',
		Citex_Populator::FIELD_CONFUSING_WORDS => 'repeater',
		Citex_Populator::FIELD_QUESTION_CLASS  => 'text',
	) as $key => $type
) {
	$GLOBALS['__acf_fields'][ $key ] = array( 'key' => $key, 'type' => $type );
}
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	array( 'fields' => array(
		array( 'key' => 'field_scenario', 'label' => 'Scenario', 'name' => 'scenario', 'type' => 'textarea' ),
	) ),
);

function dragdrop_question( $overrides = array() ) {
	return array_merge(
		array(
			'questionId'      => 'BK01',
			'title'           => 'Harvard | ReferenceList | Book | DragDrop | BK01',
			'category'        => 'Book',
			'exercise'        => 'Exercise 1',
			'type'            => 'DragDrop',
			'scenario'        => 'You are referencing the book Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.',
			'fixedText'       => 'Bryman, A. (2012) [DROP1]. Oxford: [DROP2].',
			'questionParts'   => array( 'Social Research Methods', 'Oxford University Press' ),
			'confusingWords'  => array( 'Social Research Method', 'Oxford University' ),
			'reconstructedReference' => 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.',
		),
		$overrides
	);
}

$field_map = array(
	'fixedText'      => Citex_Populator::FIELD_FIXED_TEXT,
	'questionParts'  => Citex_Populator::FIELD_QUESTION_PARTS,
	'confusingWords' => Citex_Populator::FIELD_CONFUSING_WORDS,
	'scenario'       => 'field_scenario',
	'questionClass'  => Citex_Populator::FIELD_QUESTION_CLASS,
);

// ---------------------------------------------------------------------
// 1. Baseline: with no fault armed, DragDrop population succeeds exactly
// as the existing populator tests already prove — establishes the
// fixture is sound before proving the fault-injection/catch behaviour.
// ---------------------------------------------------------------------
$GLOBALS['__throw_on_update_field_key'] = '';
$populator  = new Citex_Populator();
$baseline_q = dragdrop_question();
$baseline_r = invoke_private( $populator, 'populate_one', array( $baseline_q, 'question', 0, $field_map, 'draft' ) );
check( '[1] baseline: DragDrop population succeeds with no fault armed', is_wp_error( $baseline_r ), false );

// ---------------------------------------------------------------------
// 2. The actual regression fix: a PHP Error (TypeError — NOT an Exception)
// thrown mid-write must be caught, the partially-created post rolled back,
// and a normal, diagnosable WP_Error returned — never an uncaught fatal
// that crashes the whole request with no notice at all (the real reported
// bug: "Generate & Publish"/Populate silently did nothing for DragDrop,
// with zero error message to point at what went wrong).
// ---------------------------------------------------------------------
$GLOBALS['__throw_on_update_field_key'] = Citex_Populator::FIELD_FIXED_TEXT;
$posts_before = count( $GLOBALS['__posts'] );
$fault_q      = dragdrop_question( array( 'questionId' => 'BK02', 'title' => 'Harvard | ReferenceList | Book | DragDrop | BK02' ) );
$fault_r      = invoke_private( $populator, 'populate_one', array( $fault_q, 'question', 0, $field_map, 'draft' ) );
check( '[2] a thrown PHP Error (TypeError) during population is caught, not left to crash the request', is_wp_error( $fault_r ), true );
if ( is_wp_error( $fault_r ) ) {
	check( '[2] the resulting WP_Error carries the real error message, not a generic one', false !== strpos( $fault_r->get_error_message(), 'Simulated PHP Error' ), true );
}
check( '[2] the partially-created post is rolled back (deleted), not left behind half-configured', count( $GLOBALS['__posts'] ), $posts_before );

$GLOBALS['__throw_on_update_field_key'] = '';

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
