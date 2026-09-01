<?php
/**
 * Behavioural tests for Citex_Populator's template/no-template fallback
 * hierarchy (see the class docblock in class-citex-populator.php).
 *
 * Repo-level only, run with plain `php tests/populator-template-fallback.test.php`
 * — not shipped in citex-tools.zip.
 *
 * This defines a minimal in-memory WordPress/ACF stub (posts, post meta,
 * taxonomy terms and ACF field/field-group registries all live in plain
 * PHP arrays below) and drives Citex_Populator's private methods directly
 * via Reflection, since almost all of its logic lives in private methods
 * and the class's only public entry point (maybe_handle_submit) is an
 * HTTP controller (nonce/capability/$_POST/redirect) that is out of scope
 * here — the fallback hierarchy itself is what changed and what needs
 * covering.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

// ---------------------------------------------------------------------
// Minimal WordPress/ACF stub environment
// ---------------------------------------------------------------------

$GLOBALS['__posts']                        = array();
$GLOBALS['__post_meta']                    = array();
$GLOBALS['__post_terms']                   = array();
$GLOBALS['__terms']                        = array();
$GLOBALS['__terms_full']                   = array();
$GLOBALS['__taxonomies_by_post_type']      = array();
$GLOBALS['__acf_fields']                   = array();
$GLOBALS['__acf_field_groups_by_post_type']= array();
$GLOBALS['__post_field_objects']           = array();
$GLOBALS['__acf_values']                   = array();
$GLOBALS['__acf_write_blocked']            = array();
$GLOBALS['__registered_post_types']        = array();
$GLOBALS['__next_post_id']                 = 100;
$GLOBALS['__deleted_posts']                = array();
$GLOBALS['__wp_update_post_calls']         = 0;

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
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
	$post_type     = $args['post_type'] ?? '';
	$statuses      = (array) ( $args['post_status'] ?? array( 'publish' ) );
	$title_filter  = array_key_exists( 'title', $args ) ? $args['title'] : null;
	$fields        = $args['fields'] ?? 'all';
	$limit         = $args['posts_per_page'] ?? -1;

	$matches = array();
	foreach ( $GLOBALS['__posts'] as $id => $p ) {
		if ( $p['post_type'] !== $post_type ) {
			continue;
		}
		if ( ! in_array( $p['post_status'], $statuses, true ) ) {
			continue;
		}
		if ( null !== $title_filter && $p['post_title'] !== $title_filter ) {
			continue;
		}
		$matches[ $id ] = $p;
	}
	if ( $limit > 0 ) {
		$matches = array_slice( $matches, 0, $limit, true );
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

function get_the_title( $post ) {
	if ( is_object( $post ) ) {
		return $post->post_title;
	}
	return $GLOBALS['__posts'][ $post ]['post_title'] ?? '';
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
	if ( ! empty( $GLOBALS['__taxonomy_write_blocked'][ $post_id ][ $taxonomy ] ) ) {
		return $term_ids; // simulate a write WordPress accepts but never actually persists
	}
	$GLOBALS['__post_terms'][ $post_id ][ $taxonomy ] = array_values( (array) $term_ids );
	return $term_ids;
}

function get_term_by( $field, $value, $taxonomy ) {
	foreach ( ( $GLOBALS['__terms'][ $taxonomy ] ?? array() ) as $term_id => $name ) {
		if ( 'name' === $field && $name === $value ) {
			return (object) array( 'term_id' => $term_id, 'name' => $name, 'taxonomy' => $taxonomy );
		}
	}
	foreach ( ( $GLOBALS['__terms_full'][ $taxonomy ] ?? array() ) as $term_id => $t ) {
		if ( 'name' === $field && ( $t['name'] ?? '' ) === $value ) {
			return (object) array( 'term_id' => $term_id, 'name' => $t['name'], 'taxonomy' => $taxonomy );
		}
	}
	return false;
}

/**
 * Richer term store (name + parent) backing the Category/Exercise
 * discovery mechanism (find_taxonomy_term_by_name()), distinct from the
 * flat $GLOBALS['__terms'] used by the older get_term_by()-based
 * best-effort tagging.
 */
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
	$GLOBALS['__post_meta']                      = array();
	$GLOBALS['__post_terms']                     = array();
	$GLOBALS['__terms']                          = array();
	$GLOBALS['__terms_full']                     = array();
	$GLOBALS['__taxonomies_by_post_type']        = array();
	$GLOBALS['__acf_fields']                     = array();
	$GLOBALS['__acf_field_groups_by_post_type']  = array();
	$GLOBALS['__post_field_objects']             = array();
	$GLOBALS['__acf_values']                     = array();
	$GLOBALS['__acf_write_blocked']              = array();
	$GLOBALS['__taxonomy_write_blocked']         = array();
	$GLOBALS['__registered_post_types']          = array( 'question' );
	$GLOBALS['__next_post_id']                   = 100;
	$GLOBALS['__deleted_posts']                  = array();
	$GLOBALS['__wp_update_post_calls']           = 0;

	// The three known ACF field keys are always "registered" on the site,
	// mirroring a real ACF install where the field group already exists.
	$GLOBALS['__acf_fields'][ Citex_Populator::FIELD_FIXED_TEXT ]      = array( 'key' => Citex_Populator::FIELD_FIXED_TEXT, 'type' => 'text' );
	$GLOBALS['__acf_fields'][ Citex_Populator::FIELD_QUESTION_PARTS ]  = array( 'key' => Citex_Populator::FIELD_QUESTION_PARTS, 'type' => 'repeater' );
	$GLOBALS['__acf_fields'][ Citex_Populator::FIELD_CONFUSING_WORDS ] = array( 'key' => Citex_Populator::FIELD_CONFUSING_WORDS, 'type' => 'repeater' );

	// Default Reference Category taxonomy: Book (top-level) with Exercise
	// 1-5 as child terms, mirroring the real site's "Reference Categories"
	// hierarchical checkbox metabox. Every scenario below gets this by
	// default (populate_one() now requires it to succeed); scenarios that
	// override __taxonomies_by_post_type['question'] re-include it.
	$GLOBALS['__taxonomies_by_post_type']['question'] = array( 'reference_category' );
	$GLOBALS['__terms_full']['reference_category'] = array(
		1 => array( 'name' => 'Book', 'parent' => 0 ),
		2 => array( 'name' => 'Exercise 1', 'parent' => 1 ),
		3 => array( 'name' => 'Exercise 2', 'parent' => 1 ),
		4 => array( 'name' => 'Exercise 3', 'parent' => 1 ),
		5 => array( 'name' => 'Exercise 4', 'parent' => 1 ),
		6 => array( 'name' => 'Exercise 5', 'parent' => 1 ),
	);
}

function invoke_private( $object, $method, array $args = array() ) {
	$reflection = new ReflectionMethod( get_class( $object ), $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

function sample_question( $overrides = array() ) {
	return array_merge(
		array(
			'key'             => 'k1',
			'questionId'      => 'BK01',
			'title'           => 'Harvard | ReferenceList | Book | DragDrop | BK01',
			'fixedText'       => '|, || (||) ||. Place: Publisher.',
			'questionParts'   => array( 'Smith', 'J.', '2020', 'Example Book' ),
			'confusingWords'  => array( '2018', 'Manchester', 'Brown' ),
			'scenario'        => 'You are creating a reference for a book by Smith.',
			'validationStatus'=> 'passed',
		),
		$overrides
	);
}

$populator = new Citex_Populator();

// ---------------------------------------------------------------------
// 1. Existing template path
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__posts'][500] = array(
	'post_type'    => 'question',
	'post_status'  => 'publish',
	'post_title'   => 'Harvard | ReferenceList | Book | DragDrop | BK00',
	'post_content' => 'TemplateContent',
	'post_excerpt' => 'TemplateExcerpt',
	'menu_order'   => 7,
);
$GLOBALS['__post_meta'][500] = array(
	'_citex_custom_meta' => array( 'keep-me' ),
	'_edit_lock'         => array( '111:1' ),
);
$GLOBALS['__taxonomies_by_post_type']['question'] = array( 'quiz_topic', 'reference_category' );
$GLOBALS['__post_terms'][500]['quiz_topic'] = array( 11, 22 );
// The template itself belongs to Book / Exercise 3 (ids 1, 4) — the
// generated question below defaults to Exercise 1, and the resulting post
// must end up with Exercise 1, never the template's Exercise 3.
$GLOBALS['__post_terms'][500]['reference_category'] = array( 1, 4 );
$GLOBALS['__post_field_objects'][500] = array(
	array( 'key' => 'field_scenario_tmpl', 'label' => 'Question', 'name' => 'question', 'sub_fields' => array() ),
);

$scan_with_template = array(
	'postType'  => 'question',
	'questions' => array(
		array( 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Book', 'type' => 'DragDrop', 'wpPostId' => 500 ),
	),
);

$template_id = invoke_private( $populator, 'find_template_post_id', array( 'question', $scan_with_template ) );
check( '[template] find_template_post_id finds the real Book/DragDrop record', $template_id, 500 );

$field_map = invoke_private( $populator, 'resolve_population_fields', array( $template_id, 'question' ) );
check( '[template] resolve_population_fields discovers the scenario field from the template post', $field_map['scenario'], 'field_scenario_tmpl' );

$question = sample_question();
$result   = invoke_private( $populator, 'populate_one', array( $question, 'question', $template_id, $field_map, 'draft' ) );
check( '[template] populate_one succeeds', is_wp_error( $result ), false );
$new_id = is_array( $result ) ? $result['postId'] : null;
check( '[template] new post created as draft', $GLOBALS['__posts'][ $new_id ]['post_status'] ?? null, 'draft' );
check( '[template] post content cloned from template', $GLOBALS['__posts'][ $new_id ]['post_content'] ?? null, 'TemplateContent' );
check( '[template] non-internal meta cloned from template', $GLOBALS['__post_meta'][ $new_id ]['_citex_custom_meta'][0] ?? null, 'keep-me' );
check( '[template] internal _edit_lock meta NOT cloned', isset( $GLOBALS['__post_meta'][ $new_id ]['_edit_lock'] ), false );
check( '[template] unrelated taxonomy terms cloned from template', $GLOBALS['__post_terms'][ $new_id ]['quiz_topic'] ?? null, array( 11, 22 ) );
check( '[template] Fixed Text written and verified', $GLOBALS['__acf_values'][ $new_id ][ Citex_Populator::FIELD_FIXED_TEXT ] ?? null, $question['fixedText'] );
check( '[template] Scenario field written to the discovered key', $GLOBALS['__acf_values'][ $new_id ]['field_scenario_tmpl'] ?? null, $question['scenario'] );
check( "[template] generated question's own Book/Exercise 1 (ids 1,2) replace the template's Book/Exercise 3 (ids 1,4) — no leak", $GLOBALS['__post_terms'][ $new_id ]['reference_category'] ?? null, array( 1, 2 ) );
check( '[template] result reports the verified classification', $result['category'] . '|' . $result['exercise'] . '|' . $result['type'], 'Book|Exercise 1|DragDrop' );

// ---------------------------------------------------------------------
// 2 & 6. No-template fallback path creates the correct Draft record
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	array(
		'key'    => 'group_1',
		'fields' => array(
			array( 'key' => 'field_scenario_fallback', 'label' => 'Scenario', 'name' => 'scenario', 'sub_fields' => array() ),
		),
	),
);
$GLOBALS['__taxonomies_by_post_type']['question'] = array( 'quiz_topic', 'reference_category' );
$GLOBALS['__terms']['quiz_topic'] = array( 99 => 'Book' ); // only "Book" happens to exist as a term name

$scan_without_template = array( 'postType' => 'question', 'questions' => array() );
$template_id_2 = invoke_private( $populator, 'find_template_post_id', array( 'question', $scan_without_template ) );
check( '[fallback] find_template_post_id correctly reports no template exists', $template_id_2, 0 );

$field_map_2 = invoke_private( $populator, 'resolve_population_fields_without_template', array( 'question' ) );
check( '[fallback] resolve_population_fields_without_template discovers scenario field from the field group', is_wp_error( $field_map_2 ), false );
check( '[fallback] scenario field key resolved without any template post', $field_map_2['scenario'] ?? null, 'field_scenario_fallback' );

$question_2 = sample_question( array( 'key' => 'k2', 'title' => 'Harvard | ReferenceList | Book | DragDrop | BK02' ) );
$result_2   = invoke_private( $populator, 'populate_one', array( $question_2, 'question', $template_id_2, $field_map_2, 'draft' ) );
check( '[fallback] populate_one succeeds with no template', is_wp_error( $result_2 ), false );
$new_id_2 = is_array( $result_2 ) ? $result_2['postId'] : null;
check( '[fallback] new post created as Draft', $GLOBALS['__posts'][ $new_id_2 ]['post_status'] ?? null, 'draft' );
check( '[fallback] no content is fabricated (no template to clone from)', $GLOBALS['__posts'][ $new_id_2 ]['post_content'] ?? null, '' );
check( '[fallback] no arbitrary meta is cloned', $GLOBALS['__post_meta'][ $new_id_2 ] ?? array(), array() );
check( '[fallback] only the classification term that actually exists ("Book") is applied by the best-effort tagger', $GLOBALS['__post_terms'][ $new_id_2 ]['quiz_topic'] ?? null, array( 99 ) );
check( '[fallback] Fixed Text written and verified', $GLOBALS['__acf_values'][ $new_id_2 ][ Citex_Populator::FIELD_FIXED_TEXT ] ?? null, $question_2['fixedText'] );
check( '[fallback] generated Category/Exercise (Book/Exercise 1) authoritatively assigned', $GLOBALS['__post_terms'][ $new_id_2 ]['reference_category'] ?? null, array( 1, 2 ) );

// ---------------------------------------------------------------------
// 3. Missing required ACF field
// ---------------------------------------------------------------------
reset_environment();
unset( $GLOBALS['__acf_fields'][ Citex_Populator::FIELD_CONFUSING_WORDS ] ); // simulate the field group not being installed
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	array( 'key' => 'group_1', 'fields' => array( array( 'key' => 'field_scenario_fallback', 'label' => 'Scenario', 'name' => 'scenario', 'sub_fields' => array() ) ) ),
);
$field_map_missing = invoke_private( $populator, 'resolve_population_fields_without_template', array( 'question' ) );
check( '[missing field] resolve_population_fields_without_template stops with an error', is_wp_error( $field_map_missing ), true );
check( '[missing field] error code identifies the missing ACF field', is_wp_error( $field_map_missing ) ? $field_map_missing->get_error_code() : null, 'citex_missing_acf_field' );

// ---------------------------------------------------------------------
// 4a. Best-effort tagging (Harvard/ReferenceList/DragDrop-word terms) is
// still not required and must not block population on its own — but the
// mandatory Category term (Book) below is what actually decides success.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	array( 'key' => 'group_1', 'fields' => array( array( 'key' => 'field_scenario_fallback', 'label' => 'Scenario', 'name' => 'scenario', 'sub_fields' => array() ) ) ),
);
$GLOBALS['__taxonomies_by_post_type']['question'] = array( 'quiz_topic', 'reference_category' );
$GLOBALS['__terms']['quiz_topic'] = array( 5 => 'Unrelated Term' ); // none of Harvard/ReferenceList/DragDrop exist here

$field_map_3 = invoke_private( $populator, 'resolve_population_fields_without_template', array( 'question' ) );
$question_3  = sample_question( array( 'key' => 'k3', 'title' => 'Harvard | ReferenceList | Book | DragDrop | BK03' ) );
$result_3    = invoke_private( $populator, 'populate_one', array( $question_3, 'question', 0, $field_map_3, 'draft' ) );
check( '[best-effort tagging] population succeeds since the mandatory Category (Book) is still resolvable elsewhere', is_wp_error( $result_3 ), false );
$new_id_3 = is_array( $result_3 ) ? $result_3['postId'] : null;
check( '[best-effort tagging] no terms are fabricated in the unrelated taxonomy when none match', $GLOBALS['__post_terms'][ $new_id_3 ]['quiz_topic'] ?? array(), array() );

// ---------------------------------------------------------------------
// 9. Missing required Category term causes rollback (THE reported
// production bug's underlying mechanism: population must not succeed —
// let alone leave a post behind — when the Category cannot be resolved).
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	array( 'key' => 'group_1', 'fields' => array( array( 'key' => 'field_scenario_fallback', 'label' => 'Scenario', 'name' => 'scenario', 'sub_fields' => array() ) ) ),
);
$GLOBALS['__taxonomies_by_post_type']['question'] = array( 'reference_category' );
$GLOBALS['__terms_full']['reference_category']    = array( 2 => array( 'name' => 'Exercise 1', 'parent' => 0 ) ); // no "Book" term at all

$field_map_cat = invoke_private( $populator, 'resolve_population_fields_without_template', array( 'question' ) );
$question_cat  = sample_question( array( 'key' => 'k-cat', 'title' => 'Harvard | ReferenceList | Book | DragDrop | BK-CAT' ) );
$result_cat    = invoke_private( $populator, 'populate_one', array( $question_cat, 'question', 0, $field_map_cat, 'draft' ) );
check( '[missing category] populate_one fails when the Category term cannot be found', is_wp_error( $result_cat ), true );
check( '[missing category] error names the missing Category', is_wp_error( $result_cat ) && false !== strpos( $result_cat->get_error_message(), 'Book' ), true );
check( '[missing category] no post was left behind (id 100 never persists)', isset( $GLOBALS['__posts'][100] ), false );

// ---------------------------------------------------------------------
// 10. Missing required Exercise term (Category resolves fine, but no
// child term for the requested Exercise exists anywhere) causes rollback.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	array( 'key' => 'group_1', 'fields' => array( array( 'key' => 'field_scenario_fallback', 'label' => 'Scenario', 'name' => 'scenario', 'sub_fields' => array() ) ) ),
);
$GLOBALS['__taxonomies_by_post_type']['question'] = array( 'reference_category' );
$GLOBALS['__terms_full']['reference_category']    = array( 1 => array( 'name' => 'Book', 'parent' => 0 ) ); // Book exists, no Exercise terms at all

$field_map_ex = invoke_private( $populator, 'resolve_population_fields_without_template', array( 'question' ) );
$question_ex  = sample_question( array( 'key' => 'k-ex', 'title' => 'Harvard | ReferenceList | Book | DragDrop | BK-EX' ) );
$result_ex    = invoke_private( $populator, 'populate_one', array( $question_ex, 'question', 0, $field_map_ex, 'draft' ) );
check( '[missing exercise] populate_one fails when the Exercise term cannot be found', is_wp_error( $result_ex ), true );
check( '[missing exercise] error names the missing Exercise', is_wp_error( $result_ex ) && false !== strpos( $result_ex->get_error_message(), 'Exercise 1' ), true );
check( '[missing exercise] no post was left behind (id 100 never persists)', isset( $GLOBALS['__posts'][100] ), false );

// ---------------------------------------------------------------------
// 11. Post-creation verification detects a taxonomy assignment that was
// accepted by wp_set_object_terms() but did not actually persist (e.g. a
// plugin silently rejecting the write) — this must roll back exactly like
// any other verification failure, never be reported as populated.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	array( 'key' => 'group_1', 'fields' => array( array( 'key' => 'field_scenario_fallback', 'label' => 'Scenario', 'name' => 'scenario', 'sub_fields' => array() ) ) ),
);
// wp_set_object_terms() "succeeds" (returns normally) but the write never
// actually persists — simulating a site where something silently rejects
// the taxonomy change.
$GLOBALS['__taxonomy_write_blocked'][100]['reference_category'] = true;
$field_map_verify = invoke_private( $populator, 'resolve_population_fields_without_template', array( 'question' ) );
$question_verify  = sample_question( array( 'key' => 'k-verify', 'title' => 'Harvard | ReferenceList | Book | DragDrop | BK-VERIFY' ) );
$result_verify    = invoke_private( $populator, 'populate_one', array( $question_verify, 'question', 0, $field_map_verify, 'draft' ) );
check( '[verification] a taxonomy write that does not actually persist is detected and fails', is_wp_error( $result_verify ), true );
check( '[verification] error identifies the missing terms as "MISSING" not silently accepted', is_wp_error( $result_verify ) && false !== strpos( $result_verify->get_error_message(), 'MISSING' ), true );
check( '[verification] no post was left behind', isset( $GLOBALS['__posts'][100] ), false );

// ---------------------------------------------------------------------
// 5. ACF write failure rolls back the new post
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	array( 'key' => 'group_1', 'fields' => array( array( 'key' => 'field_scenario_fallback', 'label' => 'Scenario', 'name' => 'scenario', 'sub_fields' => array() ) ) ),
);
$field_map_4 = invoke_private( $populator, 'resolve_population_fields_without_template', array( 'question' ) );
$question_4  = sample_question( array( 'key' => 'k4', 'title' => 'Harvard | ReferenceList | Book | DragDrop | BK04' ) );

// The next post created will be id 100 (fresh reset_environment() above).
$GLOBALS['__acf_write_blocked'][100][ Citex_Populator::FIELD_FIXED_TEXT ] = true;

$result_4 = invoke_private( $populator, 'populate_one', array( $question_4, 'question', 0, $field_map_4, 'draft' ) );
check( '[write failure] populate_one reports failure when Fixed Text does not persist', is_wp_error( $result_4 ), true );
check( '[write failure] error code identifies the population failure', is_wp_error( $result_4 ) ? $result_4->get_error_code() : null, 'citex_population_failed' );
check( '[write failure] the newly-created post was rolled back (deleted)', isset( $GLOBALS['__posts'][100] ), false );
check( '[write failure] the rollback used force delete', in_array( 100, $GLOBALS['__deleted_posts'], true ), true );
check( '[write failure] publish was never attempted before the failed verification', $GLOBALS['__wp_update_post_calls'], 0 );

// ---------------------------------------------------------------------
// 7. Successful requested publish only happens after field verification
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	array( 'key' => 'group_1', 'fields' => array( array( 'key' => 'field_scenario_fallback', 'label' => 'Scenario', 'name' => 'scenario', 'sub_fields' => array() ) ) ),
);
$field_map_5 = invoke_private( $populator, 'resolve_population_fields_without_template', array( 'question' ) );
$question_5  = sample_question( array( 'key' => 'k5', 'title' => 'Harvard | ReferenceList | Book | DragDrop | BK05' ) );
$result_5    = invoke_private( $populator, 'populate_one', array( $question_5, 'question', 0, $field_map_5, 'publish' ) );
check( '[publish] populate_one succeeds', is_wp_error( $result_5 ), false );
$new_id_5 = is_array( $result_5 ) ? $result_5['postId'] : null;
check( '[publish] the post was actually published only after verification passed', $GLOBALS['__posts'][ $new_id_5 ]['post_status'] ?? null, 'publish' );
check( '[publish] wp_update_post (the publish step) ran exactly once', $GLOBALS['__wp_update_post_calls'], 1 );

// Now repeat with a blocked write and final_status=publish: publish must
// never be reached because verification fails first.
reset_environment();
$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
	array( 'key' => 'group_1', 'fields' => array( array( 'key' => 'field_scenario_fallback', 'label' => 'Scenario', 'name' => 'scenario', 'sub_fields' => array() ) ) ),
);
$field_map_6 = invoke_private( $populator, 'resolve_population_fields_without_template', array( 'question' ) );
$question_6  = sample_question( array( 'key' => 'k6', 'title' => 'Harvard | ReferenceList | Book | DragDrop | BK06' ) );
$GLOBALS['__acf_write_blocked'][100][ Citex_Populator::FIELD_FIXED_TEXT ] = true;
$result_6 = invoke_private( $populator, 'populate_one', array( $question_6, 'question', 0, $field_map_6, 'publish' ) );
check( '[publish guard] a failed verification blocks publish entirely', is_wp_error( $result_6 ), true );
check( '[publish guard] wp_update_post (publish) was never called', $GLOBALS['__wp_update_post_calls'], 0 );
check( '[publish guard] the unpublished, unverified post was rolled back', isset( $GLOBALS['__posts'][100] ), false );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
