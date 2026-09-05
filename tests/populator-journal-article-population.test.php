<?php
/**
 * Regression test for Citex_Populator's finalisation/save lifecycle with
 * the new Journal Article category — proves populate_one() creates a real
 * WordPress post, assigns the real "Journal Article"/"Exercise N" taxonomy
 * terms (found purely by NAME, via the same find_taxonomy_term_by_name()
 * mechanism already proven category-agnostic for Book/Website in
 * tests/populator-category-exercise-assignment.test.php), writes the
 * generic fixedText/questionParts/confusingWords/scenario ACF fields Citex
 * always writes regardless of category, and verifies the saved post before
 * reporting success — with ZERO Populator code changes required, since
 * Citex_Populator never branches on category at all (see its own docblock).
 *
 * Repo-level only, run with plain
 * `php tests/populator-journal-article-population.test.php` — not shipped
 * in citex-tools.zip.
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
function absint( $v ) {
	return abs( intval( $v ) );
}

function get_posts( $args ) {
	$post_type = $args['post_type'] ?? '';
	$statuses  = (array) ( $args['post_status'] ?? array( 'publish' ) );
	$matches   = array();
	foreach ( $GLOBALS['__posts'] as $id => $p ) {
		if ( $p['post_type'] === $post_type && in_array( $p['post_status'], $statuses, true ) ) {
			$matches[ $id ] = $p;
		}
	}
	return ( 'ids' === ( $args['fields'] ?? 'all' ) ) ? array_map( 'intval', array_keys( $matches ) ) : array();
}
function get_post( $id ) {
	return isset( $GLOBALS['__posts'][ $id ] ) ? (object) array_merge( $GLOBALS['__posts'][ $id ], array( 'ID' => $id ) ) : null;
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
	$id = $args['ID'] ?? 0;
	if ( ! isset( $GLOBALS['__posts'][ $id ] ) ) {
		return new WP_Error( 'missing_post', 'Post not found' );
	}
	foreach ( $args as $k => $v ) {
		if ( 'ID' !== $k ) {
			$GLOBALS['__posts'][ $id ][ $k ] = $v;
		}
	}
	return $id;
}
function get_post_status( $post_id ) {
	return $GLOBALS['__posts'][ $post_id ]['post_status'] ?? false;
}
function clean_post_cache( $post_id ) {}
function do_action( $hook, ...$args ) {}
function get_option( $key, $default = false ) {
	return $GLOBALS['__options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}
function wp_delete_post( $id, $force = false ) {
	unset( $GLOBALS['__posts'][ $id ] );
	return true;
}
function get_post_meta( $post_id, $key = '', $single = false ) {
	return '' === $key ? array() : array();
}
function add_post_meta( $post_id, $key, $value ) { return true; }
function delete_post_meta( $post_id, $key ) { return true; }
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
function get_term_by( $field, $value, $taxonomy ) {
	foreach ( ( $GLOBALS['__terms_full'][ $taxonomy ] ?? array() ) as $term_id => $t ) {
		if ( 'name' === $field && ( $t['name'] ?? '' ) === $value ) {
			return (object) array( 'term_id' => $term_id, 'name' => $t['name'], 'taxonomy' => $taxonomy );
		}
	}
	return false;
}
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
function acf_get_field( $key ) { return $GLOBALS['__acf_fields'][ $key ] ?? false; }
function acf_get_field_groups( $args = array() ) { return array(); }
function acf_get_fields( $group ) { return $group['fields'] ?? array(); }
function get_field_objects( $post_id, $formatted = false, $load_value = false ) { return array(); }
function get_field( $selector, $post_id, $format = false ) {
	return $GLOBALS['__acf_values'][ $post_id ][ $selector ] ?? null;
}
function update_field( $selector, $value, $post_id ) {
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
function invoke_private( $object, $method, array $args = array() ) {
	$reflection = new ReflectionMethod( get_class( $object ), $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

$GLOBALS['__posts']                   = array();
$GLOBALS['__post_terms']              = array();
$GLOBALS['__next_post_id']            = 300;
$GLOBALS['__options']                 = array();
$GLOBALS['__acf_values']              = array();
$GLOBALS['__acf_fields']              = array(
	Citex_Populator::FIELD_FIXED_TEXT      => array( 'key' => Citex_Populator::FIELD_FIXED_TEXT, 'type' => 'text' ),
	Citex_Populator::FIELD_QUESTION_PARTS  => array( 'key' => Citex_Populator::FIELD_QUESTION_PARTS, 'type' => 'repeater' ),
	Citex_Populator::FIELD_CONFUSING_WORDS => array( 'key' => Citex_Populator::FIELD_CONFUSING_WORDS, 'type' => 'repeater' ),
	Citex_Populator::FIELD_QUESTION_CLASS  => array( 'key' => Citex_Populator::FIELD_QUESTION_CLASS, 'type' => 'text' ),
);
$GLOBALS['__taxonomies_by_post_type']['question'] = array( 'reference_category' );
// Journal Article (id 20) with its own Exercise 1-5 children (ids 21-25) —
// a real WordPress taxonomy shape, exactly like Book's in the sibling test.
$GLOBALS['__terms_full']['reference_category'] = array(
	20 => array( 'name' => 'Journal Article', 'parent' => 0 ),
	21 => array( 'name' => 'Exercise 1', 'parent' => 20 ),
	22 => array( 'name' => 'Exercise 2', 'parent' => 20 ),
	23 => array( 'name' => 'Exercise 3', 'parent' => 20 ),
	24 => array( 'name' => 'Exercise 4', 'parent' => 20 ),
	25 => array( 'name' => 'Exercise 5', 'parent' => 20 ),
);

$populator = new Citex_Populator();
$field_map = array(
	'fixedText'      => Citex_Populator::FIELD_FIXED_TEXT,
	'questionParts'  => Citex_Populator::FIELD_QUESTION_PARTS,
	'confusingWords' => Citex_Populator::FIELD_CONFUSING_WORDS,
	'scenario'       => 'field_scenario',
	'questionClass'  => Citex_Populator::FIELD_QUESTION_CLASS,
);

$question = array(
	'key'            => 'k-ja',
	'questionId'     => 'JA01',
	'title'          => 'Harvard | ReferenceList | Journal Article | DragDrop | JA01',
	'category'       => 'Journal Article',
	'exercise'       => 'Exercise 3',
	'type'           => 'DragDrop',
	'fixedText'      => '| (||) ||. ||, ||(||), pp.||.',
	'questionParts'  => array( 'Mitchell, S. and Evans, D.', '2010', 'A brief guide to Harvard referencing', 'The British Journal of Referencing', '12', '2', '27-35' ),
	'confusingWords' => array( '2015', 'A different journal', '99-100' ),
	'scenario'       => 'You are referencing a journal article titled A brief guide to Harvard referencing by Sarah Mitchell and Daniel Evans.',
	'validationStatus' => 'passed',
);

$result = invoke_private( $populator, 'populate_one', array( $question, 'question', 0, $field_map, 'draft' ) );

// ---------------------------------------------------------------------
// 21, 22 & 24. Journal Article + Exercise 3 + DragDrop: populate_one()
// creates the post, assigns BOTH real taxonomy terms (Category="Journal
// Article", Exercise="Exercise 3"), writes the generic ACF fields, and
// verifies everything actually persisted before reporting success — the
// same finalisation mechanism Book/Edited Book already use, with zero
// Populator code changes.
// ---------------------------------------------------------------------
check( '[21][22][24] populate_one() succeeds for Journal Article with no Populator code changes', is_wp_error( $result ), false );
if ( ! is_wp_error( $result ) ) {
	$post_id = $result['postId'];
	$saved_terms = $GLOBALS['__post_terms'][ $post_id ]['reference_category'] ?? array();
	check( '[21] the "Journal Article" category term (20) is assigned', in_array( 20, $saved_terms, true ), true );
	check( '[22] the "Exercise 3" term (23), Journal Article\'s own child, is assigned', in_array( 23, $saved_terms, true ), true );
	check( '[24] result reports the verified Category', $result['category'], 'Journal Article' );
	check( '[24] result reports the verified Exercise', $result['exercise'], 'Exercise 3' );
	check( '[24] result reports categoryVerified', $result['categoryVerified'], true );
	check( '[24] result reports exerciseVerified', $result['exerciseVerified'], true );
	check( '[24] the Fixed Text ACF field was written', $GLOBALS['__acf_values'][ $post_id ][ Citex_Populator::FIELD_FIXED_TEXT ] ?? null, '| (||) ||. ||, ||(||), pp.||.' );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
