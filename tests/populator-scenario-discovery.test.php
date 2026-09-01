<?php
/**
 * Regression tests for Citex_Populator's Scenario/Question ACF field
 * discovery (discover_scenario_field() and its collect_fields_by_*
 * helpers), added after a real production failure: a live Book/DragDrop
 * Reference List record visibly has an ACF field labelled "Scenario" with
 * a value on its edit screen, yet the single-mechanism discovery used
 * before this fix (get_field_objects() against the template post, or
 * acf_get_field_groups(['post_type' => ...]) for the no-template path)
 * could not find it, and population failed with "Citex could not identify
 * the ACF Question/Scenario field."
 *
 * discover_scenario_field() now merges candidates from three independent
 * ACF-native mechanisms (field groups located by post type, field groups
 * located by the specific post's full location context, and
 * get_field_objects() against that post) before matching, and walks both
 * repeater/group sub_fields and flexible-content layouts. This file
 * exercises that merge directly, including the specific asymmetric case
 * that reproduces the reported failure: a field discoverable through only
 * ONE of those three mechanisms.
 *
 * Repo-level only, run with plain
 * `php tests/populator-scenario-discovery.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

// ---------------------------------------------------------------------
// Minimal WordPress/ACF stub environment
// ---------------------------------------------------------------------

$GLOBALS['__posts']                          = array();
$GLOBALS['__post_meta']                      = array();
$GLOBALS['__post_terms']                     = array();
$GLOBALS['__terms_full']                     = array();
$GLOBALS['__taxonomies_by_post_type']        = array();
$GLOBALS['__acf_fields']                     = array();
$GLOBALS['__acf_field_groups_by_post_type']  = array();
$GLOBALS['__acf_field_groups_by_post_id']    = array();
$GLOBALS['__post_field_objects']             = array();
$GLOBALS['__acf_values']                     = array();
$GLOBALS['__acf_write_blocked']              = array();
$GLOBALS['__next_post_id']                   = 100;
$GLOBALS['__deleted_posts']                  = array();

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
function absint( $v ) {
	return abs( intval( $v ) );
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

function wp_delete_post( $id, $force = false ) {
	if ( isset( $GLOBALS['__posts'][ $id ] ) ) {
		unset( $GLOBALS['__posts'][ $id ] );
		$GLOBALS['__deleted_posts'][] = $id;
	}
	return true;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	return '' === $key ? array() : array();
}
function add_post_meta( $post_id, $key, $value ) {
	return true;
}
function delete_post_meta( $post_id, $key ) {
	return true;
}
function maybe_unserialize( $v ) {
	return $v;
}
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
		$out[] = (object) array(
			'term_id'  => $term_id,
			'name'     => $t['name'] ?? '',
			'parent'   => (int) ( $t['parent'] ?? 0 ),
			'taxonomy' => $taxonomy,
		);
	}
	return $out;
}

function acf_get_field( $key ) {
	return $GLOBALS['__acf_fields'][ $key ] ?? false;
}

/**
 * Distinguishes 'post_type' vs 'post_id' location context, matching the
 * two independently-called strategies in discover_scenario_field().
 */
function acf_get_field_groups( $args = array() ) {
	if ( array_key_exists( 'post_id', $args ) ) {
		return $GLOBALS['__acf_field_groups_by_post_id'][ $args['post_id'] ] ?? array();
	}
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

function update_field( $selector, $value, $post_id ) {
	if ( ! empty( $GLOBALS['__acf_write_blocked'][ $post_id ][ $selector ] ) ) {
		return false;
	}
	$GLOBALS['__acf_values'][ $post_id ][ $selector ] = $value;
	return true;
}

require __DIR__ . '/../citex-tools/includes/class-citex-populator.php';

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
	$GLOBALS['__posts']                         = array();
	$GLOBALS['__post_meta']                     = array();
	$GLOBALS['__post_terms']                    = array();
	$GLOBALS['__terms_full']                    = array();
	$GLOBALS['__acf_field_groups_by_post_type'] = array();
	$GLOBALS['__acf_field_groups_by_post_id']   = array();
	$GLOBALS['__post_field_objects']            = array();
	$GLOBALS['__acf_values']                    = array();
	$GLOBALS['__acf_write_blocked']             = array();
	$GLOBALS['__next_post_id']                  = 100;
	$GLOBALS['__deleted_posts']                 = array();

	$GLOBALS['__acf_fields'] = array(
		Citex_Populator::FIELD_FIXED_TEXT      => array( 'key' => Citex_Populator::FIELD_FIXED_TEXT, 'type' => 'text' ),
		Citex_Populator::FIELD_QUESTION_PARTS  => array( 'key' => Citex_Populator::FIELD_QUESTION_PARTS, 'type' => 'repeater' ),
		Citex_Populator::FIELD_CONFUSING_WORDS => array( 'key' => Citex_Populator::FIELD_CONFUSING_WORDS, 'type' => 'repeater' ),
	);

	// Default Reference Category taxonomy (Book, with Exercise 1-5 as
	// child terms) so populate_one() tests here — whose focus is Scenario
	// field discovery, not classification — get a working Category/Exercise
	// default without needing to set this up individually.
	$GLOBALS['__taxonomies_by_post_type']['question'] = array( 'reference_category' );
	$GLOBALS['__terms_full']['reference_category']    = array(
		1 => array( 'name' => 'Book', 'parent' => 0 ),
		2 => array( 'name' => 'Exercise 1', 'parent' => 1 ),
	);
}

function invoke_private( $object, $method, array $args = array() ) {
	$reflection = new ReflectionMethod( get_class( $object ), $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

function field_group( $key, array $fields ) {
	return array( 'key' => $key, 'fields' => $fields );
}

$populator = new Citex_Populator();

// ---------------------------------------------------------------------
// 1. Scenario field labelled "Scenario" (with a plain matching name) is
// discovered via post-type field-group definitions.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	field_group( 'group_1', array( array( 'key' => 'field_scenario_1', 'label' => 'Scenario', 'name' => 'scenario' ) ) ),
);
$found = invoke_private( $populator, 'discover_scenario_field', array( 'question', 0 ) );
check( '[1] a field labelled "Scenario" is discovered', is_wp_error( $found ) ? $found->get_error_message() : $found, 'field_scenario_1' );

// ---------------------------------------------------------------------
// 2. Scenario field with a completely different NAME but label "Scenario"
// is discovered — proves matching works off the label alone.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	field_group( 'group_1', array( array( 'key' => 'field_scenario_2', 'label' => 'Scenario', 'name' => 'question_prompt_body_field' ) ) ),
);
$found2 = invoke_private( $populator, 'discover_scenario_field', array( 'question', 0 ) );
check( '[2] label "Scenario" is discovered even when the field name is unrelated', is_wp_error( $found2 ) ? $found2->get_error_message() : $found2, 'field_scenario_2' );

// ---------------------------------------------------------------------
// 3. Exact NAME "scenario" is discovered even when the label is different
// wording — proves matching also works off the name alone.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	field_group( 'group_1', array( array( 'key' => 'field_scenario_3', 'label' => 'Question Prompt', 'name' => 'scenario' ) ) ),
);
$found3 = invoke_private( $populator, 'discover_scenario_field', array( 'question', 0 ) );
check( '[3] exact field name "scenario" is discovered', is_wp_error( $found3 ) ? $found3->get_error_message() : $found3, 'field_scenario_3' );

// ---------------------------------------------------------------------
// 4. Existing Book/DragDrop template resolves the Scenario field
// end-to-end through resolve_population_fields().
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__posts'][500] = array( 'post_type' => 'question', 'post_status' => 'publish', 'post_title' => 'tmpl', 'post_content' => '', 'post_excerpt' => '', 'menu_order' => 0 );
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	field_group( 'group_1', array( array( 'key' => 'field_scenario_4', 'label' => 'Scenario', 'name' => 'scenario' ) ) ),
);
$field_map = invoke_private( $populator, 'resolve_population_fields', array( 500, 'question' ) );
check( '[4] resolve_population_fields() (template path) resolves Scenario', is_wp_error( $field_map ) ? $field_map->get_error_message() : $field_map['scenario'], 'field_scenario_4' );

// ---------------------------------------------------------------------
// 5. No-template ACF field-group discovery resolves Scenario.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	field_group( 'group_1', array( array( 'key' => 'field_scenario_5', 'label' => 'Scenario', 'name' => 'scenario' ) ) ),
);
$field_map_no_tmpl = invoke_private( $populator, 'resolve_population_fields_without_template', array( 'question' ) );
check( '[5] resolve_population_fields_without_template() resolves Scenario', is_wp_error( $field_map_no_tmpl ) ? $field_map_no_tmpl->get_error_message() : $field_map_no_tmpl['scenario'], 'field_scenario_5' );

// ---------------------------------------------------------------------
// 6. Ambiguous candidates (two fields both labelled "Scenario") fail
// safely with both candidates named in the diagnostic, rather than
// silently picking one.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	field_group(
		'group_1',
		array(
			array( 'key' => 'field_scenario_a', 'label' => 'Scenario', 'name' => 'scenario' ),
			array( 'key' => 'field_scenario_b', 'label' => 'Scenario', 'name' => 'scenario_alt' ),
		)
	),
);
$ambiguous = invoke_private( $populator, 'discover_scenario_field', array( 'question', 0 ) );
check( '[6] ambiguous candidates fail safely (not a field key)', is_wp_error( $ambiguous ), true );
check( '[6] error code identifies the ambiguity', is_wp_error( $ambiguous ) ? $ambiguous->get_error_code() : null, 'citex_question_field_ambiguous' );
check( '[6] diagnostic message names both candidate fields', is_wp_error( $ambiguous ) && false !== strpos( $ambiguous->get_error_message(), 'Scenario' ), true );

// ---------------------------------------------------------------------
// 7a. THE EXACT REPORTED FAILURE: a field labelled "Scenario" that only
// get_field_objects() (post-based) can see — post-type field-group
// discovery finds nothing at all. The old single-mechanism
// find_acf_field_key_by_post_type() would have failed here; the merged
// discover_scenario_field() must still succeed via the template post.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__posts'][501] = array( 'post_type' => 'question', 'post_status' => 'publish', 'post_title' => 'tmpl', 'post_content' => '', 'post_excerpt' => '', 'menu_order' => 0 );
// No post-type field groups registered at all — simulates a location rule
// that does not resolve under a post-type-only context.
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array();
$GLOBALS['__post_field_objects'][501] = array(
	array( 'key' => 'field_scenario_7a', 'label' => 'Scenario', 'name' => 'scenario' ),
);
$repro_a = invoke_private( $populator, 'discover_scenario_field', array( 'question', 501 ) );
check( '[7a] reported failure reproduction: field only visible via get_field_objects() is still found', is_wp_error( $repro_a ) ? $repro_a->get_error_message() : $repro_a, 'field_scenario_7a' );

// 7b. The inverse: a field only visible through post-type field-group
// discovery, invisible to get_field_objects() on that specific post
// (e.g. the value has never been saved on that post so ACF has no
// per-post reference for it yet). Must still be found.
reset_environment();
$GLOBALS['__posts'][502] = array( 'post_type' => 'question', 'post_status' => 'publish', 'post_title' => 'tmpl', 'post_content' => '', 'post_excerpt' => '', 'menu_order' => 0 );
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	field_group( 'group_1', array( array( 'key' => 'field_scenario_7b', 'label' => 'Scenario', 'name' => 'scenario' ) ) ),
);
$GLOBALS['__post_field_objects'][502] = array(); // get_field_objects() sees nothing for this post
$repro_b = invoke_private( $populator, 'discover_scenario_field', array( 'question', 502 ) );
check( '[7b] inverse case: field only visible via post-type field groups is still found', is_wp_error( $repro_b ) ? $repro_b->get_error_message() : $repro_b, 'field_scenario_7b' );

// 7c. A Scenario field nested inside a flexible-content layout (a
// structural shape the old traversal never walked at all).
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	field_group(
		'group_1',
		array(
			array(
				'key'     => 'field_flex',
				'label'   => 'Content Blocks',
				'name'    => 'content_blocks',
				'type'    => 'flexible_content',
				'layouts' => array(
					array(
						'key'        => 'layout_1',
						'sub_fields' => array(
							array( 'key' => 'field_scenario_7c', 'label' => 'Scenario', 'name' => 'scenario' ),
						),
					),
				),
			),
		)
	),
);
$repro_c = invoke_private( $populator, 'discover_scenario_field', array( 'question', 0 ) );
check( '[7c] a Scenario field nested inside a flexible-content layout is found', is_wp_error( $repro_c ) ? $repro_c->get_error_message() : $repro_c, 'field_scenario_7c' );

// ---------------------------------------------------------------------
// 8. Scenario value is written successfully via populate_one().
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	field_group( 'group_1', array( array( 'key' => 'field_scenario_8', 'label' => 'Scenario', 'name' => 'scenario' ) ) ),
);
$field_map_8 = invoke_private( $populator, 'resolve_population_fields_without_template', array( 'question' ) );
$question_8  = array(
	'key'            => 'k8',
	'questionId'     => 'BK08',
	'title'          => 'Harvard | ReferenceList | Book | DragDrop | BK08',
	'fixedText'      => '|, || (||) ||. Place: Publisher.',
	'questionParts'  => array( 'Smith', 'J.', '2020', 'Example Book' ),
	'confusingWords' => array( '2018', 'Manchester', 'Brown' ),
	'scenario'       => 'You are referencing a book titled Example Book by J. Smith.',
);
$result_8 = invoke_private( $populator, 'populate_one', array( $question_8, 'question', 0, $field_map_8, 'draft' ) );
check( '[8] populate_one() succeeds', is_wp_error( $result_8 ), false );
$new_id_8 = is_array( $result_8 ) ? $result_8['postId'] : null;
check( '[8] Scenario value is written to the discovered field key', $GLOBALS['__acf_values'][ $new_id_8 ]['field_scenario_8'] ?? null, $question_8['scenario'] );

// ---------------------------------------------------------------------
// 9. Scenario write verification failure rolls back the newly-created
// post and never publishes it.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	field_group( 'group_1', array( array( 'key' => 'field_scenario_9', 'label' => 'Scenario', 'name' => 'scenario' ) ) ),
);
$field_map_9 = invoke_private( $populator, 'resolve_population_fields_without_template', array( 'question' ) );
$question_9  = array(
	'key'            => 'k9',
	'questionId'     => 'BK09',
	'title'          => 'Harvard | ReferenceList | Book | DragDrop | BK09',
	'fixedText'      => '|, || (||) ||. Place: Publisher.',
	'questionParts'  => array( 'Smith', 'J.', '2020', 'Example Book' ),
	'confusingWords' => array( '2018', 'Manchester', 'Brown' ),
	'scenario'       => 'You are referencing a book titled Example Book by J. Smith.',
);
// The next post created is id 100 (fresh reset_environment() above);
// block the Scenario write from actually persisting.
$GLOBALS['__acf_write_blocked'][100]['field_scenario_9'] = true;
$result_9 = invoke_private( $populator, 'populate_one', array( $question_9, 'question', 0, $field_map_9, 'publish' ) );
check( '[9] populate_one() reports failure when Scenario does not persist', is_wp_error( $result_9 ), true );
check( '[9] the newly-created post was rolled back (deleted)', isset( $GLOBALS['__posts'][100] ), false );
check( '[9] the rollback used force delete', in_array( 100, $GLOBALS['__deleted_posts'], true ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
