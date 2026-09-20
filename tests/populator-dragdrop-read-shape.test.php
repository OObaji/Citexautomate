<?php
/**
 * Regression tests for Citex_Populator::resolve_dragdrop_read_shape() and
 * read_dragdrop_content() — the read-only counterparts to the existing
 * write-side field/repeater-shape discovery (resolve_population_fields_without_template()
 * + write_repeater_rows()'s own resolve_repeater_text_row_shape()), added
 * so already-published posts' real content can be inspected (for
 * Citex_Questions::find_broken_intext_page_questions()) without ever
 * writing anything.
 *
 * resolve_dragdrop_read_shape() resolves the field map + the questionParts
 * repeater's own text-subfield shape ONCE per post type — reusable across
 * every post of that type in one scan, rather than re-discovering ACF
 * structure per post. read_dragdrop_content() then reads one post's real
 * fixedText/scenario/questionParts using that pre-resolved shape.
 *
 * Repo-level only, run with plain
 * `php tests/populator-dragdrop-read-shape.test.php` — not shipped in
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

/**
 * The real repeater shape reported on the live site (same fixture as
 * populator-question-parts-repeater.test.php): a row-type selector
 * ("Select Element": Text vs Punctuation) plus type-specific value
 * sub-fields.
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
	$GLOBALS['__acf_field_groups_by_post_type'] = array(
		'citex_citations' => array(
			array( 'key' => 'group_1', 'fields' => array( array( 'key' => 'field_scenario', 'label' => 'Scenario', 'name' => 'scenario', 'sub_fields' => array() ) ) ),
		),
	);
	$GLOBALS['__acf_field_groups_by_post_id'] = array();
	$GLOBALS['__post_field_objects']          = array();
	$GLOBALS['__acf_values']                  = array();

	$GLOBALS['__acf_fields'] = array(
		Citex_Populator::FIELD_FIXED_TEXT      => array( 'key' => Citex_Populator::FIELD_FIXED_TEXT, 'type' => 'text' ),
		Citex_Populator::FIELD_QUESTION_PARTS  => question_parts_repeater_definition(),
		Citex_Populator::FIELD_CONFUSING_WORDS => question_parts_repeater_definition(),
		Citex_Populator::FIELD_QUESTION_CLASS  => array( 'key' => Citex_Populator::FIELD_QUESTION_CLASS, 'type' => 'text' ),
	);
}

// ---------------------------------------------------------------------
// 1. resolve_dragdrop_read_shape() resolves both the field map (including
// the discovered scenario field) and the repeater's own text-subfield
// shape, without any template post.
// ---------------------------------------------------------------------
reset_environment();
$populator = new Citex_Populator();
$shape     = $populator->resolve_dragdrop_read_shape( 'citex_citations' );
check( '[1] resolve_dragdrop_read_shape() succeeds with no template post', is_wp_error( $shape ), false );
check( '[1] resolves the scenario field key via the existing discovery machinery', is_wp_error( $shape ) ? null : $shape['fieldMap']['scenario'], 'field_scenario' );
check( '[1] resolves fixedText/questionParts field keys from the known constants', is_wp_error( $shape ) ? null : array( $shape['fieldMap']['fixedText'], $shape['fieldMap']['questionParts'] ), array( Citex_Populator::FIELD_FIXED_TEXT, Citex_Populator::FIELD_QUESTION_PARTS ) );
check( '[1] resolves the repeater\'s own text sub-field, not the selector or "Punctuation"', is_wp_error( $shape ) ? null : $shape['partsShape']['textSubfieldKey'], 'field_text_value' );

// ---------------------------------------------------------------------
// 2. read_dragdrop_content() reads back a post's real, already-stored
// content using that shape — a repeater row's TEXT sub-field value, in
// order, never the selector/punctuation sub-fields.
// ---------------------------------------------------------------------
$GLOBALS['__acf_values'][500] = array(
	Citex_Populator::FIELD_FIXED_TEXT     => '"a striking claim" (||, ||, p. ||).',
	'field_scenario'                      => 'Complete the parenthetical in-text citation for a direct quotation from the Book Example Title by Smith, published in 2020.',
	Citex_Populator::FIELD_QUESTION_PARTS => array(
		array( 'field_select_element' => 'text', 'field_text_value' => 'Smith', 'field_punctuation_value' => '' ),
		array( 'field_select_element' => 'text', 'field_text_value' => '2020', 'field_punctuation_value' => '' ),
		array( 'field_select_element' => 'text', 'field_text_value' => '34', 'field_punctuation_value' => '' ),
	),
);
$content = $populator->read_dragdrop_content( 500, $shape );
check( '[2] reads fixedText as stored', $content['fixedText'], '"a striking claim" (||, ||, p. ||).' );
check( '[2] reads scenario as stored', $content['scenario'], 'Complete the parenthetical in-text citation for a direct quotation from the Book Example Title by Smith, published in 2020.' );
check( '[2] reads questionParts as the TEXT sub-field values, in order — never the selector/punctuation values', $content['questionParts'], array( 'Smith', '2020', '34' ) );

// ---------------------------------------------------------------------
// 3. A post with no stored value for a field reads back cleanly as an
// empty string/array, never a PHP notice or a null propagating through.
// ---------------------------------------------------------------------
$empty_content = $populator->read_dragdrop_content( 999, $shape );
check( '[3] a post with nothing stored reads back an empty fixedText', $empty_content['fixedText'], '' );
check( '[3] a post with nothing stored reads back an empty scenario', $empty_content['scenario'], '' );
check( '[3] a post with nothing stored reads back an empty questionParts list', $empty_content['questionParts'], array() );

// ---------------------------------------------------------------------
// 4. A simple (non-repeater) questionParts field is read directly as a
// flat scalar list — read_dragdrop_content() must not assume every field
// is a repeater with the discovered sub-field shape.
// ---------------------------------------------------------------------
$GLOBALS['__acf_field_groups_by_post_type']['citex_citations_flat'] = $GLOBALS['__acf_field_groups_by_post_type']['citex_citations'];
$GLOBALS['__acf_fields'][ Citex_Populator::FIELD_QUESTION_PARTS ]   = array( 'key' => Citex_Populator::FIELD_QUESTION_PARTS, 'type' => 'text' ); // not a repeater
$flat_shape = $populator->resolve_dragdrop_read_shape( 'citex_citations_flat' );
check( '[4] a non-repeater questionParts field resolves with an empty text-subfield key', is_wp_error( $flat_shape ) ? null : $flat_shape['partsShape']['textSubfieldKey'], '' );
$GLOBALS['__acf_values'][501][ Citex_Populator::FIELD_QUESTION_PARTS ] = array( 'Smith', '2020', '34' );
$flat_content = $populator->read_dragdrop_content( 501, $flat_shape );
check( '[4] a flat scalar list is read back as-is', $flat_content['questionParts'], array( 'Smith', '2020', '34' ) );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
