<?php
/**
 * Regression tests for Citex_Populator's Question Parts / Confusing Words
 * ACF repeater writing, added after a real production failure: a generated
 * Book/DragDrop question's Question Parts repeater showed rows like
 * "Select Element: Punctuation" / "Punctuation: apostrophe" on the
 * WordPress edit screen instead of the four correct draggable values.
 *
 * Root cause: the old write_acf_list() wrote every row using only the
 * repeater's FIRST sub-field's key, assuming a single flat text sub-field.
 * The real repeater has more than one sub-field — a row-type selector
 * (the reported "Select Element" choice field distinguishing Text rows
 * from Punctuation rows) plus type-specific value sub-fields — so writing
 * only one sub-field per row never produced a valid, correctly-typed Text
 * row.
 *
 * resolve_repeater_text_row_shape() now discovers the real shape from the
 * field's own ACF definition (acf_get_field()) — sub-field types, labels,
 * and, for the selector, its actual `choices` — and write_repeater_rows()
 * writes every row with that full shape; verify_repeater_text_values()
 * then reads the saved rows back and confirms the text sub-field of every
 * row matches the expected values, in order.
 *
 * Repo-level only, run with plain
 * `php tests/populator-question-parts-repeater.test.php` — not shipped in
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
		if ( 'ID' !== $k ) {
			$GLOBALS['__posts'][ $id ][ $k ] = $v;
		}
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
		$out[] = (object) array( 'term_id' => $term_id, 'name' => $t['name'] ?? '', 'parent' => (int) ( $t['parent'] ?? 0 ), 'taxonomy' => $taxonomy );
	}
	return $out;
}

function acf_get_field( $key ) {
	return $GLOBALS['__acf_fields'][ $key ] ?? false;
}
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
	// A real ACF repeater update fully REPLACES the field's stored value —
	// this must be true for verification to mean anything (it must not be
	// possible for a stale row to merely "survive alongside" a new one).
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

/**
 * The real repeater shape reported on the live site: a row-type selector
 * ("Select Element": Text vs Punctuation) plus type-specific value
 * sub-fields — not a single flat text sub-field.
 */
function question_parts_repeater_definition() {
	return array(
		'key'        => Citex_Populator::FIELD_QUESTION_PARTS,
		'type'       => 'repeater',
		'sub_fields' => array(
			array(
				'key'     => 'field_select_element',
				'label'   => 'Select Element',
				'name'    => 'select_element',
				'type'    => 'select',
				'choices' => array( 'text' => 'Text', 'punctuation' => 'Punctuation' ),
			),
			array(
				'key'   => 'field_text_value',
				'label' => 'Text',
				'name'  => 'text_value',
				'type'  => 'text',
			),
			array(
				'key'     => 'field_punctuation_value',
				'label'   => 'Punctuation',
				'name'    => 'punctuation_value',
				'type'    => 'select',
				'choices' => array( 'apostrophe' => "Apostrophe (')", 'comma' => 'Comma (,)' ),
			),
		),
	);
}

function reset_environment() {
	$GLOBALS['__posts']                         = array();
	$GLOBALS['__post_meta']                     = array();
	$GLOBALS['__post_terms']                    = array();
	$GLOBALS['__terms_full']                    = array();
	$GLOBALS['__taxonomies_by_post_type']       = array();
	$GLOBALS['__acf_field_groups_by_post_type'] = array();
	$GLOBALS['__acf_field_groups_by_post_id']   = array();
	$GLOBALS['__post_field_objects']            = array();
	$GLOBALS['__acf_values']                    = array();
	$GLOBALS['__acf_write_blocked']             = array();
	$GLOBALS['__next_post_id']                  = 100;
	$GLOBALS['__deleted_posts']                 = array();
	$GLOBALS['__options']                       = array();

	$GLOBALS['__acf_fields'] = array(
		Citex_Populator::FIELD_FIXED_TEXT      => array( 'key' => Citex_Populator::FIELD_FIXED_TEXT, 'type' => 'text' ),
		Citex_Populator::FIELD_QUESTION_PARTS  => question_parts_repeater_definition(),
		Citex_Populator::FIELD_CONFUSING_WORDS => question_parts_repeater_definition(), // same reported shape
		Citex_Populator::FIELD_QUESTION_CLASS  => array( 'key' => Citex_Populator::FIELD_QUESTION_CLASS, 'type' => 'text' ),
	);

	$GLOBALS['__taxonomies_by_post_type']['question'] = array( 'reference_category' );
	$GLOBALS['__terms_full']['reference_category']    = array(
		1 => array( 'name' => 'Book', 'parent' => 0 ),
		2 => array( 'name' => 'Exercise 1', 'parent' => 1 ),
	);
	$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
		array( 'key' => 'group_1', 'fields' => array( array( 'key' => 'field_scenario', 'label' => 'Scenario', 'name' => 'scenario', 'sub_fields' => array() ) ) ),
	);
}

function invoke_private( $object, $method, array $args = array() ) {
	$reflection = new ReflectionMethod( get_class( $object ), $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

$populator = new Citex_Populator();
$field_map = array(
	'fixedText'      => Citex_Populator::FIELD_FIXED_TEXT,
	'questionParts'  => Citex_Populator::FIELD_QUESTION_PARTS,
	'confusingWords' => Citex_Populator::FIELD_CONFUSING_WORDS,
	'scenario'       => 'field_scenario',
	'questionClass'  => Citex_Populator::FIELD_QUESTION_CLASS,
);

// The Geertz example from the bug report.
$canonical_parts = array( 'Geertz', 'C.', '1973', 'The Interpretation of Cultures' );

// ---------------------------------------------------------------------
// 3. resolve_repeater_text_row_shape() correctly identifies the real
// shape: the "Text" choice on the selector, and the genuinely-text
// sub-field — never the selector itself and never "apostrophe".
// ---------------------------------------------------------------------
reset_environment();
$shape = invoke_private( $populator, 'resolve_repeater_text_row_shape', array( question_parts_repeater_definition()['sub_fields'] ) );
check( '[3] shape resolution succeeds', is_wp_error( $shape ), false );
check( '[3] the discovered text sub-field is "Text", not the selector or "Punctuation"', is_wp_error( $shape ) ? null : $shape['textSubfieldKey'], 'field_text_value' );
check( '[3] the discovered selector sub-field is "Select Element"', is_wp_error( $shape ) ? null : $shape['typeSubfieldKey'], 'field_select_element' );
check( '[3] the discovered choice value marks the row as Text, never "apostrophe" or "punctuation"', is_wp_error( $shape ) ? null : $shape['textChoiceValue'], 'text' );

// ---------------------------------------------------------------------
// 1, 4, 5. write_repeater_rows() writes the four correct draggable values,
// in order, with each row correctly typed as Text — and they survive the
// round trip through update_field()/get_field().
// ---------------------------------------------------------------------
reset_environment();
$write_shape = invoke_private( $populator, 'write_repeater_rows', array( 100, Citex_Populator::FIELD_QUESTION_PARTS, $canonical_parts ) );
check( '[1,5] write_repeater_rows() succeeds', is_wp_error( $write_shape ), false );
$stored_rows = $GLOBALS['__acf_values'][100][ Citex_Populator::FIELD_QUESTION_PARTS ] ?? array();
check( '[1] exactly four rows were written', count( $stored_rows ), 4 );
check( '[1,4] the four correct draggable values are stored, in order, under the TEXT sub-field', array_column( $stored_rows, 'field_text_value' ), $canonical_parts );
check( '[3,4] every row is correctly typed as Text (never left as Punctuation/unset)', array_column( $stored_rows, 'field_select_element' ), array_fill( 0, 4, 'text' ) );
check( '[2] no row carries a "Punctuation" sub-field value at all (rows are Text rows)', array_filter( array_column( $stored_rows, 'field_punctuation_value' ) ), array() );

// ---------------------------------------------------------------------
// 2. A stale value already present (simulating whatever was there before
// — e.g. inherited from a template) is NOT merely appended to; it is
// completely replaced by the four correct values, with no leftover
// "apostrophe"-style punctuation row surviving anywhere in the result.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_values'][101][ Citex_Populator::FIELD_QUESTION_PARTS ] = array(
	array( 'field_select_element' => 'punctuation', 'field_text_value' => '', 'field_punctuation_value' => 'apostrophe' ),
	array( 'field_select_element' => 'punctuation', 'field_text_value' => '', 'field_punctuation_value' => 'comma' ),
);
invoke_private( $populator, 'write_repeater_rows', array( 101, Citex_Populator::FIELD_QUESTION_PARTS, $canonical_parts ) );
$after_rows = $GLOBALS['__acf_values'][101][ Citex_Populator::FIELD_QUESTION_PARTS ] ?? array();
check( '[2] the stale template rows are gone — exactly four rows remain', count( $after_rows ), 4 );
check( '[2] no "apostrophe" (or any punctuation) value survives anywhere in the result', in_array( 'apostrophe', array_column( $after_rows, 'field_punctuation_value' ), true ), false );
check( '[2] the four correct draggable values replaced the stale rows entirely', array_column( $after_rows, 'field_text_value' ), $canonical_parts );

// ---------------------------------------------------------------------
// 6. Read-back verification detects incorrect/stale values — proving the
// safety net catches exactly the reported bug shape (rows present, but
// holding punctuation instead of the correct text) even if a write somehow
// produced it.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_values'][102][ Citex_Populator::FIELD_QUESTION_PARTS ] = array(
	array( 'field_select_element' => 'punctuation', 'field_text_value' => '', 'field_punctuation_value' => 'apostrophe' ),
	array( 'field_select_element' => 'text', 'field_text_value' => 'C.', 'field_punctuation_value' => '' ),
	array( 'field_select_element' => 'text', 'field_text_value' => '1973', 'field_punctuation_value' => '' ),
	array( 'field_select_element' => 'text', 'field_text_value' => 'The Interpretation of Cultures', 'field_punctuation_value' => '' ),
);
$bad_shape = array( 'textSubfieldKey' => 'field_text_value', 'typeSubfieldKey' => 'field_select_element', 'textChoiceValue' => 'text' );
$verify_result = invoke_private( $populator, 'verify_repeater_text_values', array( 102, Citex_Populator::FIELD_QUESTION_PARTS, $bad_shape, $canonical_parts ) );
check( '[6] verification detects the first row is wrong (empty text, not "Geertz")', is_wp_error( $verify_result ), true );
check( '[6] error code identifies the verification failure', is_wp_error( $verify_result ) ? $verify_result->get_error_code() : null, 'citex_repeater_verification_failed' );

// A fully correct set of rows must verify successfully.
reset_environment();
$good_rows = array();
foreach ( $canonical_parts as $value ) {
	$good_rows[] = array( 'field_select_element' => 'text', 'field_text_value' => $value, 'field_punctuation_value' => '' );
}
$GLOBALS['__acf_values'][103][ Citex_Populator::FIELD_QUESTION_PARTS ] = $good_rows;
$verify_ok = invoke_private( $populator, 'verify_repeater_text_values', array( 103, Citex_Populator::FIELD_QUESTION_PARTS, $bad_shape, $canonical_parts ) );
check( '[6] verification passes for genuinely correct rows', is_wp_error( $verify_ok ), false );

// ---------------------------------------------------------------------
// End-to-end: populate_one() with the real reported repeater shape
// produces the four correct draggable values and passes verification —
// the exact reported bug, now fixed.
// ---------------------------------------------------------------------
reset_environment();
$question = array(
	'key'             => 'k-geertz',
	'questionId'      => 'BK-GEERTZ',
	'title'           => 'Harvard | ReferenceList | Book | DragDrop | BK-GEERTZ',
	'category'        => 'Book',
	'exercise'        => 'Exercise 1',
	'type'            => 'DragDrop',
	'fixedText'       => '|, || (||) ||. New York: Basic Books.',
	'questionParts'   => $canonical_parts,
	'confusingWords'  => array( '1966', 'London', 'Brown' ),
	'scenario'        => 'You are referencing a book titled The Interpretation of Cultures by Clifford Geertz, published in New York by Basic Books in 1973.',
	'validationStatus'=> 'passed',
);
$result = invoke_private( $populator, 'populate_one', array( $question, 'question', 0, $field_map, 'draft' ) );
check( '[end-to-end] populate_one() succeeds with the real reported repeater shape', is_wp_error( $result ), false );
$new_id = is_array( $result ) ? $result['postId'] : null;
$final_rows = $GLOBALS['__acf_values'][ $new_id ][ Citex_Populator::FIELD_QUESTION_PARTS ] ?? array();
check( '[end-to-end] the four correct draggable values are stored, in order', array_column( $final_rows, 'field_text_value' ), $canonical_parts );
check( '[end-to-end] every row is correctly typed as Text', array_column( $final_rows, 'field_select_element' ), array_fill( 0, 4, 'text' ) );
check( '[end-to-end] result reports Question Parts verified 4/4', $result['questionPartsVerified'] ?? null, '4/4' );

// ---------------------------------------------------------------------
// Reported bug: a programmatically-created DragDrop question's Question
// Class field never persisted (it relied on an ACF form default that is
// only ever applied when the edit screen is rendered and saved by hand)
// — the student app never showed it as "Harvard" until an admin opened
// and re-saved the post in wp-admin. write_dragdrop_acf_values() now
// writes it explicitly, exactly like write_mcq_acf_values() already did.
// ---------------------------------------------------------------------
check( '[question class] "harvard" is written explicitly for a DragDrop question, not left to an ACF default', $GLOBALS['__acf_values'][ $new_id ][ Citex_Populator::FIELD_QUESTION_CLASS ] ?? null, 'harvard' );
check( '[question class] result reports it verified', $result['questionClassVerified'] ?? null, true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
