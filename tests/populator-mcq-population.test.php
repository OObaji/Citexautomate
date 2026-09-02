<?php
/**
 * Regression tests for Citex_Populator's MCQ population path — write_mcq_acf_values(),
 * verify_mcq_acf_values(), and resolve_mcq_answer_choice().
 *
 * The MCQ ACF field keys (option_1-4, answer) are the real, confirmed field
 * keys captured via Citex Diagnostics against an actual post on this site's
 * citex-reference post type (see FIELD_OPTION_1..4/FIELD_ANSWER in
 * class-citex-populator.php) — not guessed. What the Answer field's
 * accepted VALUE looks like (a choice key referencing option_1-4, or a
 * plain option-name string) is still unknown ahead of time, so
 * resolve_mcq_answer_choice() discovers it from the field's own ACF
 * definition, exactly like resolve_repeater_text_row_shape() already does
 * for DragDrop's repeater rows — never assumed, and it fails loudly
 * (WP_Error, full rollback) rather than guessing when ambiguous.
 *
 * Repo-level only, run with plain
 * `php tests/populator-mcq-population.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

$GLOBALS['__posts']                         = array();
$GLOBALS['__post_terms']                    = array();
$GLOBALS['__terms_full']                    = array();
$GLOBALS['__taxonomies_by_post_type']       = array();
$GLOBALS['__acf_fields']                    = array();
$GLOBALS['__acf_field_groups_by_post_type'] = array();
$GLOBALS['__post_field_objects']            = array();
$GLOBALS['__acf_values']                    = array();
$GLOBALS['__registered_post_types']         = array();
$GLOBALS['__next_post_id']                  = 100;
$GLOBALS['__deleted_posts']                 = array();
$GLOBALS['__wp_update_post_calls']          = 0;
$GLOBALS['__clean_post_cache_calls']        = array();
$GLOBALS['__acf_save_post_calls']           = array();
$GLOBALS['__options']                       = array();

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

function invoke_private( $object, $method, $args ) {
	$reflection = new ReflectionMethod( 'Citex_Populator', $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

function reset_environment() {
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

	// Book (1) -> Exercise 1 (2)
	$GLOBALS['__terms_full']['reference_category'] = array(
		1 => array( 'name' => 'Book', 'parent' => 0 ),
		2 => array( 'name' => 'Exercise 1', 'parent' => 1 ),
	);

	// The confirmed real MCQ field keys registered, with a text-type Answer
	// field by default (the "not a choice field" branch) — individual tests
	// override $GLOBALS['__acf_fields'][Citex_Populator::FIELD_ANSWER] for
	// the choice-type discovery scenarios.
	foreach (
		array(
			Citex_Populator::FIELD_OPTION_1       => 'text',
			Citex_Populator::FIELD_OPTION_2       => 'text',
			Citex_Populator::FIELD_OPTION_3       => 'text',
			Citex_Populator::FIELD_OPTION_4       => 'text',
			Citex_Populator::FIELD_ANSWER         => 'text',
			Citex_Populator::FIELD_HINT           => 'textarea',
			Citex_Populator::FIELD_QUESTION_CLASS => 'text',
		) as $key => $type
	) {
		$GLOBALS['__acf_fields'][ $key ] = array( 'key' => $key, 'type' => $type );
	}

	$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
		array( 'fields' => array(
			array( 'key' => 'field_scenario', 'label' => 'Scenario', 'name' => 'scenario', 'type' => 'textarea' ),
		) ),
	);
}

function mcq_question( $overrides = array() ) {
	return array_merge(
		array(
			'questionId'         => 'BK-MCQ-1',
			'title'              => 'Harvard | ReferenceList | Book | MCQ | BK-MCQ-1',
			'category'           => 'Book',
			'exercise'           => 'Exercise 1',
			'type'               => 'MCQ',
			'scenario'           => 'You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.',
			'options'            => array(
				'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.',
				'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.',
				'A. Bryman (2012) Social Research Methods. Oxford: Oxford University Press.',
				'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.',
			),
			'correctOptionIndex' => 1,
			'explanation'        => 'B is correct because it follows the required Harvard reference structure: Surname, Initials. (Year) Title. Place: Publisher.',
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. End-to-end: a valid MCQ question populates successfully — post
// created, options/scenario/answer written and read back verified,
// Category/Exercise assigned and verified, status verified, save
// lifecycle (wp_update_post/clean_post_cache/acf_save_post) fired.
// ---------------------------------------------------------------------
reset_environment();
$populator = new Citex_Populator();
$field_map = array(
	'scenario'      => 'field_scenario',
	'option1'       => Citex_Populator::FIELD_OPTION_1,
	'option2'       => Citex_Populator::FIELD_OPTION_2,
	'option3'       => Citex_Populator::FIELD_OPTION_3,
	'option4'       => Citex_Populator::FIELD_OPTION_4,
	'answer'        => Citex_Populator::FIELD_ANSWER,
	'hint'          => Citex_Populator::FIELD_HINT,
	'questionClass' => Citex_Populator::FIELD_QUESTION_CLASS,
);
$question = mcq_question();
$result   = invoke_private( $populator, 'populate_one', array( $question, 'question', 0, $field_map, 'draft' ) );
check( '[1] MCQ population succeeds', is_wp_error( $result ), false );
if ( ! is_wp_error( $result ) ) {
	$post_id = $result['postId'];
	check( '[1] result reports type MCQ', $result['type'], 'MCQ' );
	check( '[1] option 1 persisted', $GLOBALS['__acf_values'][ $post_id ][ Citex_Populator::FIELD_OPTION_1 ], $question['options'][0] );
	check( '[1] option 2 (the correct one) persisted', $GLOBALS['__acf_values'][ $post_id ][ Citex_Populator::FIELD_OPTION_2 ], $question['options'][1] );
	check( '[1] option 3 persisted', $GLOBALS['__acf_values'][ $post_id ][ Citex_Populator::FIELD_OPTION_3 ], $question['options'][2] );
	check( '[1] option 4 persisted', $GLOBALS['__acf_values'][ $post_id ][ Citex_Populator::FIELD_OPTION_4 ], $question['options'][3] );
	check( '[1] scenario persisted', $GLOBALS['__acf_values'][ $post_id ]['field_scenario'], $question['scenario'] );
	check( '[1] Hint persisted with the generated explanation (there is no separate "explanation" field on this site)', $GLOBALS['__acf_values'][ $post_id ][ Citex_Populator::FIELD_HINT ], $question['explanation'] );
	check( '[1] Question Class is set to Harvard', $GLOBALS['__acf_values'][ $post_id ][ Citex_Populator::FIELD_QUESTION_CLASS ], 'Harvard' );
	// Answer field is plain text in this scenario (no choices) — the stable
	// option LETTER is written (see resolve_mcq_answer_choice()), matching
	// how grading is meant to work: by letter, never by comparing text.
	check( '[1] Answer field written as the stable option letter (plain-text Answer field shape)', $GLOBALS['__acf_values'][ $post_id ][ Citex_Populator::FIELD_ANSWER ], 'B' );
	check( '[1] result reports optionsVerified 4/4', $result['optionsVerified'], '4/4' );
	check( '[1] result reports answerVerified', $result['answerVerified'], true );
	check( '[1] result reports scenarioVerified', $result['scenarioVerified'], true );
	check( '[1] result reports hintVerified', $result['hintVerified'], true );
	check( '[1] Category/Exercise assigned and verified', $GLOBALS['__post_terms'][ $post_id ]['reference_category'], array( 1, 2 ) );
	check( '[1] status is draft as requested', get_post_status( $post_id ), 'draft' );
	check( '[1] save lifecycle fired wp_update_post', $GLOBALS['__wp_update_post_calls'] > 0, true );
	check( '[1] save lifecycle fired clean_post_cache for this post', in_array( $post_id, $GLOBALS['__clean_post_cache_calls'], true ), true );
	check( '[1] save lifecycle explicitly fired acf/save_post for this post', in_array( $post_id, $GLOBALS['__acf_save_post_calls'], true ), true );
	check( '[1] a plain _citex_question_type post meta key is written (no ACF field for this exists on the site)', $GLOBALS['__post_meta'][ $post_id ]['_citex_question_type'][0] ?? null, 'MCQ' );
}

// ---------------------------------------------------------------------
// 2. resolve_mcq_answer_choice(): a choice-type Answer field whose choices
// are keyed like "option_1".."option_4" is matched to the exact choice —
// discovered from the field's own definition, not assumed.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_fields'][ Citex_Populator::FIELD_ANSWER ] = array(
	'key'     => Citex_Populator::FIELD_ANSWER,
	'type'    => 'select',
	'choices' => array( 'option_1' => 'Option 1', 'option_2' => 'Option 2', 'option_3' => 'Option 3', 'option_4' => 'Option 4' ),
);
$populator2 = new Citex_Populator();
$result2 = invoke_private( $populator2, 'populate_one', array( mcq_question(), 'question', 0, $field_map, 'draft' ) );
check( '[2] population succeeds with a choice-type Answer field keyed by option name', is_wp_error( $result2 ), false );
if ( ! is_wp_error( $result2 ) ) {
	check( '[2] the matching choice KEY is written, discovered from the field\'s own choices', $GLOBALS['__acf_values'][ $result2['postId'] ][ Citex_Populator::FIELD_ANSWER ], 'option_2' );
}

// ---------------------------------------------------------------------
// 3. resolve_mcq_answer_choice(): a choice-type Answer field whose choices
// are keyed numerically ("1".."4") is also matched correctly.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_fields'][ Citex_Populator::FIELD_ANSWER ] = array(
	'key'     => Citex_Populator::FIELD_ANSWER,
	'type'    => 'radio',
	'choices' => array( '1' => 'First option', '2' => 'Second option', '3' => 'Third option', '4' => 'Fourth option' ),
);
$populator3 = new Citex_Populator();
$result3 = invoke_private( $populator3, 'populate_one', array( mcq_question(), 'question', 0, $field_map, 'draft' ) );
check( '[3] population succeeds with a numerically-keyed choice Answer field', is_wp_error( $result3 ), false );
if ( ! is_wp_error( $result3 ) ) {
	check( '[3] the numeric choice matching option 2 is written', $GLOBALS['__acf_values'][ $result3['postId'] ][ Citex_Populator::FIELD_ANSWER ], '2' );
}

// ---------------------------------------------------------------------
// 3b. resolve_mcq_answer_choice(): a choice-type Answer field keyed by
// LETTER ("A".."D") is also matched correctly — the newly-added matching
// path, exercised separately from the pre-existing number/option-name paths.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_fields'][ Citex_Populator::FIELD_ANSWER ] = array(
	'key'     => Citex_Populator::FIELD_ANSWER,
	'type'    => 'select',
	'choices' => array( 'A' => 'Option A', 'B' => 'Option B', 'C' => 'Option C', 'D' => 'Option D' ),
);
$populator3b = new Citex_Populator();
$result3b = invoke_private( $populator3b, 'populate_one', array( mcq_question(), 'question', 0, $field_map, 'draft' ) );
check( '[3b] population succeeds with a letter-keyed choice Answer field', is_wp_error( $result3b ), false );
if ( ! is_wp_error( $result3b ) ) {
	check( '[3b] the letter choice matching option 2 (B) is written', $GLOBALS['__acf_values'][ $result3b['postId'] ][ Citex_Populator::FIELD_ANSWER ], 'B' );
}

// ---------------------------------------------------------------------
// 4. resolve_mcq_answer_choice(): an AMBIGUOUS choice-type Answer field
// (no choice unambiguously names the correct option) fails safely — no
// half-created post is left behind, and Citex does not guess.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__acf_fields'][ Citex_Populator::FIELD_ANSWER ] = array(
	'key'     => Citex_Populator::FIELD_ANSWER,
	'type'    => 'select',
	'choices' => array( 'yes' => 'Yes', 'no' => 'No' ), // neither choice names any option number
);
$populator4 = new Citex_Populator();
$result4 = invoke_private( $populator4, 'populate_one', array( mcq_question(), 'question', 0, $field_map, 'draft' ) );
check( '[4] an ambiguous Answer field shape fails safely rather than guessing', is_wp_error( $result4 ), true );
check( '[4] error message names the Answer field problem', is_wp_error( $result4 ) ? false !== strpos( $result4->get_error_message(), 'Answer' ) : false, true );
check( '[4] no post is left behind after rollback', $GLOBALS['__posts'], array() );

// ---------------------------------------------------------------------
// 5. A malformed MCQ question record (not exactly 4 options) fails safely
// with a clear message and no post left behind — the same "verify, then
// rollback on any failure" contract already used for DragDrop.
// ---------------------------------------------------------------------
reset_environment();
$populator5 = new Citex_Populator();
$result5 = invoke_private( $populator5, 'populate_one', array( mcq_question( array( 'options' => array( 'a', 'b', 'c' ) ) ), 'question', 0, $field_map, 'draft' ) );
check( '[5] exactly 4 options is enforced at population time too', is_wp_error( $result5 ), true );
check( '[5] no post is left behind', $GLOBALS['__posts'], array() );

// ---------------------------------------------------------------------
// 6b. Exercise assignment must never cross-leak — a real production report
// was "questions for Exercise 1 showing up in Exercise 3 too". This proves
// the mechanism MCQ shares with DragDrop (assign_generated_classification())
// attaches ONLY the requested Exercise's term, with Exercise 2 and 3's own
// term IDs present as real siblings in the taxonomy but never attached.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__terms_full']['reference_category'] = array(
	1 => array( 'name' => 'Book', 'parent' => 0 ),
	2 => array( 'name' => 'Exercise 1', 'parent' => 1 ),
	3 => array( 'name' => 'Exercise 2', 'parent' => 1 ),
	4 => array( 'name' => 'Exercise 3', 'parent' => 1 ),
);
$populator6b = new Citex_Populator();
$result6b = invoke_private( $populator6b, 'populate_one', array( mcq_question( array( 'exercise' => 'Exercise 1' ) ), 'question', 0, $field_map, 'draft' ) );
check( '[6b] an Exercise-1 MCQ populates successfully alongside sibling Exercise 2/3 terms', is_wp_error( $result6b ), false );
if ( ! is_wp_error( $result6b ) ) {
	$attached = $GLOBALS['__post_terms'][ $result6b['postId'] ]['reference_category'] ?? array();
	check( '[6b] only Book + Exercise 1 are attached — Exercise 3\'s term ID (4) is never present', $attached, array( 1, 2 ) );
	check( '[6b] Exercise 3\'s term is not attached', in_array( 4, $attached, true ), false );
	check( '[6b] Exercise 2\'s term is not attached', in_array( 3, $attached, true ), false );
}

// ---------------------------------------------------------------------
// 6. Wiring guard: an MCQ question is never populated against a
// DragDrop-only template (which would leave stray DragDrop meta on an MCQ
// post) — asserted directly against the source, since find_template_post_id()
// only ever finds a Book/DragDrop record and maybe_handle_submit() must
// force template_id to 0 for MCQ specifically rather than reusing it.
// ---------------------------------------------------------------------
$populator_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-populator.php' );
check( "[6] maybe_handle_submit() forces template_id to 0 for MCQ questions ('MCQ' === \$type ? 0 : ...)", false !== strpos( $populator_source, "'MCQ' === \$type ? 0 : \$dragdrop_template_id" ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
