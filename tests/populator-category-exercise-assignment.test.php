<?php
/**
 * Regression tests for Citex_Populator's Category/Exercise taxonomy
 * assignment, added after a real production failure: a newly-populated
 * Book/DragDrop question was successfully created as a WordPress post, but
 * on its edit screen neither "Book" nor any Exercise 1-5 term was
 * selected under Reference Categories — so the question never appeared in
 * the Citex student app.
 *
 * Root cause: the old code either cloned WHATEVER Category/Exercise terms
 * a template post happened to have (which could be none, or the wrong
 * ones), or — in the no-template path — only ever searched for terms
 * literally named Harvard/ReferenceList/Book/DragDrop, never "Exercise 1"
 * through "Exercise 5" at all, and never verified anything was actually
 * saved.
 *
 * assign_generated_classification() now makes the GENERATED question's own
 * category/exercise/type fields authoritative: it resolves them via
 * find_taxonomy_term_by_name() (dynamic, name-based — no taxonomy slug or
 * term ID is ever hard-coded), preferring Exercise as a child term of the
 * resolved Category (matching the real "Book -> Exercise 1" hierarchical
 * checkbox metabox), with a flat taxonomy-wide fallback in case that
 * nesting assumption is wrong on the real site. wp_set_object_terms() is
 * always called with $append = false, so a template's own terms for that
 * taxonomy are replaced, never merely added to. populate_one() then reads
 * the actual saved terms back from WordPress and rolls back the post if
 * either the Category or the Exercise did not really persist.
 *
 * Repo-level only, run with plain
 * `php tests/populator-category-exercise-assignment.test.php` — not
 * shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

// ---------------------------------------------------------------------
// Minimal WordPress/ACF stub environment
// ---------------------------------------------------------------------

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
	if ( ! empty( $GLOBALS['__taxonomy_write_blocked'][ $post_id ][ $taxonomy ] ) ) {
		return $term_ids;
	}
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

/**
 * Book (id 1) at top level, Exercise 1-5 (ids 2-6) as its child terms, and
 * a second Website category (id 7) with its own Exercise 1 (id 8) — proving
 * "Exercise 1" is not assumed to be a single, globally unique term name.
 */
function reset_environment() {
	$GLOBALS['__posts']                         = array();
	$GLOBALS['__post_meta']                     = array();
	$GLOBALS['__post_terms']                    = array();
	$GLOBALS['__taxonomy_write_blocked']        = array();
	$GLOBALS['__acf_field_groups_by_post_type'] = array();
	$GLOBALS['__acf_field_groups_by_post_id']   = array();
	$GLOBALS['__post_field_objects']            = array();
	$GLOBALS['__acf_values']                    = array();
	$GLOBALS['__acf_write_blocked']             = array();
	$GLOBALS['__next_post_id']                  = 100;
	$GLOBALS['__deleted_posts']                 = array();
	$GLOBALS['__clean_post_cache_calls']        = array();
	$GLOBALS['__acf_save_post_calls']           = array();
	$GLOBALS['__options']                       = array();

	$GLOBALS['__acf_fields'] = array(
		Citex_Populator::FIELD_FIXED_TEXT      => array( 'key' => Citex_Populator::FIELD_FIXED_TEXT, 'type' => 'text' ),
		Citex_Populator::FIELD_QUESTION_PARTS  => array( 'key' => Citex_Populator::FIELD_QUESTION_PARTS, 'type' => 'repeater' ),
		Citex_Populator::FIELD_CONFUSING_WORDS => array( 'key' => Citex_Populator::FIELD_CONFUSING_WORDS, 'type' => 'repeater' ),
	);

	$GLOBALS['__taxonomies_by_post_type']['question'] = array( 'reference_category' );
	$GLOBALS['__terms_full']['reference_category']    = array(
		1 => array( 'name' => 'Book', 'parent' => 0 ),
		2 => array( 'name' => 'Exercise 1', 'parent' => 1 ),
		3 => array( 'name' => 'Exercise 2', 'parent' => 1 ),
		4 => array( 'name' => 'Exercise 3', 'parent' => 1 ),
		5 => array( 'name' => 'Exercise 4', 'parent' => 1 ),
		6 => array( 'name' => 'Exercise 5', 'parent' => 1 ),
		7 => array( 'name' => 'Website', 'parent' => 0 ),
		8 => array( 'name' => 'Exercise 1', 'parent' => 7 ), // same name, different parent
	);

	$GLOBALS['__acf_field_groups_by_post_type']['question'] = array(
		array(
			'key'    => 'group_1',
			'fields' => array( array( 'key' => 'field_scenario', 'label' => 'Scenario', 'name' => 'scenario', 'sub_fields' => array() ) ),
		),
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
			'questionId'      => 'BK21',
			'title'           => 'Harvard | ReferenceList | Book | DragDrop | BK21',
			'category'        => 'Book',
			'exercise'        => 'Exercise 1',
			'type'            => 'DragDrop',
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
$field_map = array(
	'fixedText'      => Citex_Populator::FIELD_FIXED_TEXT,
	'questionParts'  => Citex_Populator::FIELD_QUESTION_PARTS,
	'confusingWords' => Citex_Populator::FIELD_CONFUSING_WORDS,
	'scenario'       => 'field_scenario',
);

// ---------------------------------------------------------------------
// THE EXACT REPORTED PRODUCTION BUG: Book + Exercise 1 + DragDrop must
// save both "Book" and "Exercise 1" as real WordPress terms. Before this
// fix, neither was assigned.
// ---------------------------------------------------------------------
reset_environment();
$question_repro = sample_question( array( 'key' => 'k-repro', 'questionId' => 'BK21' ) );
$result_repro   = invoke_private( $populator, 'populate_one', array( $question_repro, 'question', 0, $field_map, 'draft' ) );
check( '[reported bug] populate_one() succeeds', is_wp_error( $result_repro ), false );
$repro_id = is_array( $result_repro ) ? $result_repro['postId'] : null;
$saved_terms = $GLOBALS['__post_terms'][ $repro_id ]['reference_category'] ?? array();
check( '[reported bug] Book (term 1) IS saved — was previously missing', in_array( 1, $saved_terms, true ), true );
check( '[reported bug] Exercise 1 (term 2) IS saved — was previously missing', in_array( 2, $saved_terms, true ), true );

// ---------------------------------------------------------------------
// 1-5. Book + Exercise N + DragDrop assigns Book and Exercise N, for every
// exercise 1 through 5.
// ---------------------------------------------------------------------
$exercise_term_ids = array( 'Exercise 1' => 2, 'Exercise 2' => 3, 'Exercise 3' => 4, 'Exercise 4' => 5, 'Exercise 5' => 6 );
$n = 1;
foreach ( $exercise_term_ids as $exercise_name => $expected_term_id ) {
	reset_environment();
	$q = sample_question( array( 'key' => 'ex-' . $n, 'questionId' => 'BK' . $n, 'exercise' => $exercise_name ) );
	$r = invoke_private( $populator, 'populate_one', array( $q, 'question', 0, $field_map, 'draft' ) );
	check( "[$n] Book + $exercise_name + DragDrop: populate_one() succeeds", is_wp_error( $r ), false );
	$id = is_array( $r ) ? $r['postId'] : null;
	$terms = $GLOBALS['__post_terms'][ $id ]['reference_category'] ?? array();
	check( "[$n] Book + $exercise_name + DragDrop: Book and $exercise_name are both assigned", $terms, array( 1, $expected_term_id ) );
	$n++;
}

// ---------------------------------------------------------------------
// 6. Book + Exercise 1 + MCQ assigns Book and Exercise 1. Category/Exercise
// assignment is type-independent, so this is tested directly against
// assign_generated_classification() rather than the full populate_one()
// orchestration. Full end-to-end MCQ population (real option_1-4/answer
// ACF field writing) has its own dedicated field-map/ACF stubs and is
// covered by tests/populator-mcq-population.test.php, not here — this file
// stays focused on the taxonomy layer, which does not care about question
// type at all.
// ---------------------------------------------------------------------
reset_environment();
$mcq_post_id = 100;
$GLOBALS['__posts'][ $mcq_post_id ] = array( 'post_type' => 'question', 'post_status' => 'draft', 'post_title' => 'mcq', 'post_content' => '', 'post_excerpt' => '', 'menu_order' => 0 );
$classification_mcq = invoke_private( $populator, 'resolve_classification', array( array( 'category' => 'Book', 'exercise' => 'Exercise 1', 'type' => 'MCQ' ) ) );
$assign_result_mcq  = invoke_private( $populator, 'assign_generated_classification', array( $mcq_post_id, 'question', $classification_mcq ) );
check( '[6] Book + Exercise 1 + MCQ: classification assignment succeeds', is_wp_error( $assign_result_mcq ), false );
check( '[6] Book + Exercise 1 + MCQ: Book and Exercise 1 are both assigned', $GLOBALS['__post_terms'][ $mcq_post_id ]['reference_category'] ?? array(), array( 1, 2 ) );

// An MCQ question populated with a DragDrop-shaped field map (this file's
// $field_map has no option1-4/answer keys) must still fail safely — no
// half-created post left behind — rather than writing to undefined fields.
reset_environment();
$mcq_question       = sample_question( array( 'key' => 'k-mcq', 'questionId' => 'BK-MCQ', 'type' => 'MCQ' ) );
$mcq_result         = invoke_private( $populator, 'populate_one', array( $mcq_question, 'question', 0, $field_map, 'draft' ) );
check( '[mismatched field map] an MCQ question populated against a DragDrop field map fails safely, not silently', is_wp_error( $mcq_result ), true );
check( '[mismatched field map] no post is left behind', $GLOBALS['__posts'], array() );

// ---------------------------------------------------------------------
// 7. Template Exercise does not leak into a generated Exercise 4 — the
// template belongs to Exercise 1, the generated question is Exercise 4.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__posts'][500] = array( 'post_type' => 'question', 'post_status' => 'publish', 'post_title' => 'tmpl', 'post_content' => '', 'post_excerpt' => '', 'menu_order' => 0 );
$GLOBALS['__post_terms'][500]['reference_category'] = array( 1, 2 ); // Book + Exercise 1
$question_leak = sample_question( array( 'key' => 'k-leak', 'questionId' => 'BK-LEAK', 'exercise' => 'Exercise 4' ) );
$result_leak   = invoke_private( $populator, 'populate_one', array( $question_leak, 'question', 500, $field_map, 'draft' ) );
check( '[7] template-vs-generated Exercise: populate_one() succeeds', is_wp_error( $result_leak ), false );
$leak_id = is_array( $result_leak ) ? $result_leak['postId'] : null;
check( "[7] the template's Exercise 1 does NOT leak; the generated question's Exercise 4 is what's saved", $GLOBALS['__post_terms'][ $leak_id ]['reference_category'] ?? null, array( 1, 5 ) );

// ---------------------------------------------------------------------
// 8. Template category does not override the generated category — the
// template belongs to "Website", the generated question is "Book".
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__posts'][501] = array( 'post_type' => 'question', 'post_status' => 'publish', 'post_title' => 'tmpl2', 'post_content' => '', 'post_excerpt' => '', 'menu_order' => 0 );
$GLOBALS['__post_terms'][501]['reference_category'] = array( 7, 8 ); // Website + its own Exercise 1
$question_cat_leak = sample_question( array( 'key' => 'k-cat-leak', 'questionId' => 'BK-CAT-LEAK', 'category' => 'Book', 'exercise' => 'Exercise 1' ) );
$result_cat_leak   = invoke_private( $populator, 'populate_one', array( $question_cat_leak, 'question', 501, $field_map, 'draft' ) );
check( '[8] template-vs-generated Category: populate_one() succeeds', is_wp_error( $result_cat_leak ), false );
$cat_leak_id = is_array( $result_cat_leak ) ? $result_cat_leak['postId'] : null;
check( "[8] the template's Website category does NOT leak; the generated question's Book/Exercise 1 (not Website's own Exercise 1, term 8) is what's saved", $GLOBALS['__post_terms'][ $cat_leak_id ]['reference_category'] ?? null, array( 1, 2 ) );

// ---------------------------------------------------------------------
// 9 & 10 (unambiguous term lookup with siblings present): confirms
// "Exercise 1" resolves to Book's own child (term 2), never Website's
// same-named child (term 8), proving Exercise lookup is parent-aware and
// not a flat, globally-unique-name assumption.
// ---------------------------------------------------------------------
reset_environment();
$classification_book_ex1 = invoke_private( $populator, 'resolve_classification', array( array( 'category' => 'Book', 'exercise' => 'Exercise 1' ) ) );
$match_book = invoke_private( $populator, 'assign_generated_classification', array( 102, 'question', $classification_book_ex1 ) );
$GLOBALS['__posts'][102] = array( 'post_type' => 'question', 'post_status' => 'draft', 'post_title' => 'x', 'post_content' => '', 'post_excerpt' => '', 'menu_order' => 0 );
check( '[parent-aware] Book/Exercise 1 resolves to Book\'s own child term (2), not a sibling category\'s same-named term', is_wp_error( $match_book ) ? null : $match_book['exerciseTermId'], 2 );

$classification_web_ex1 = invoke_private( $populator, 'resolve_classification', array( array( 'category' => 'Website', 'exercise' => 'Exercise 1' ) ) );
$match_web = invoke_private( $populator, 'assign_generated_classification', array( 103, 'question', $classification_web_ex1 ) );
check( '[parent-aware] Website/Exercise 1 resolves to Website\'s own child term (8), not Book\'s', is_wp_error( $match_web ) ? null : $match_web['exerciseTermId'], 8 );

// ---------------------------------------------------------------------
// 11. Post-creation verification detects a Category/Exercise assignment
// that "succeeded" (wp_set_object_terms returned normally) but did not
// actually persist, and rolls back rather than reporting success.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__taxonomy_write_blocked'][100]['reference_category'] = true;
$question_verify = sample_question( array( 'key' => 'k-verify', 'questionId' => 'BK-VERIFY' ) );
$result_verify    = invoke_private( $populator, 'populate_one', array( $question_verify, 'question', 0, $field_map, 'draft' ) );
check( '[11] a Category/Exercise write that does not actually persist is detected', is_wp_error( $result_verify ), true );
check( '[11] no post is left behind', isset( $GLOBALS['__posts'][100] ), false );

// ---------------------------------------------------------------------
// 12. Successful population verifies the actual saved taxonomy terms and
// reports them in the result (the "BK21 Category: Book ✓ Exercise:
// Exercise 1 ✓" diagnostic).
// ---------------------------------------------------------------------
reset_environment();
$question_diag = sample_question( array( 'key' => 'k-diag', 'questionId' => 'BK-DIAG' ) );
$result_diag   = invoke_private( $populator, 'populate_one', array( $question_diag, 'question', 0, $field_map, 'draft' ) );
check( '[12] populate_one() succeeds', is_wp_error( $result_diag ), false );
check( '[12] result reports the verified Category', is_array( $result_diag ) ? $result_diag['category'] : null, 'Book' );
check( '[12] result reports the verified Exercise', is_array( $result_diag ) ? $result_diag['exercise'] : null, 'Exercise 1' );
check( '[12] result reports the verified Type', is_array( $result_diag ) ? $result_diag['type'] : null, 'DragDrop' );
check( '[12] result reports categoryVerified', is_array( $result_diag ) ? $result_diag['categoryVerified'] : null, true );
check( '[12] result reports exerciseVerified', is_array( $result_diag ) ? $result_diag['exerciseVerified'] : null, true );

// ---------------------------------------------------------------------
// 13. A representative 10-question matrix (5 exercises x 2 types) is
// correctly classified by the taxonomy-assignment mechanism. This proves
// assign_generated_classification() handles the full Book coverage matrix
// correctly; it is NOT a claim that Citex's generator currently produces
// MCQ questions (it does not — see the "MCQ not faked" test above).
// ---------------------------------------------------------------------
reset_environment();
$batch_ok = true;
$post_id_seq = 200;
foreach ( array( 'Exercise 1', 'Exercise 2', 'Exercise 3', 'Exercise 4', 'Exercise 5' ) as $exercise_name ) {
	foreach ( array( 'DragDrop', 'MCQ' ) as $type ) {
		$classification = invoke_private( $populator, 'resolve_classification', array( array( 'category' => 'Book', 'exercise' => $exercise_name, 'type' => $type ) ) );
		$assign = invoke_private( $populator, 'assign_generated_classification', array( $post_id_seq, 'question', $classification ) );
		if ( is_wp_error( $assign ) ) {
			$batch_ok = false;
			break 2;
		}
		$expected_term_id = $exercise_term_ids[ $exercise_name ];
		$saved = $GLOBALS['__post_terms'][ $post_id_seq ]['reference_category'] ?? array();
		if ( array( 1, $expected_term_id ) !== $saved ) {
			$batch_ok = false;
			break 2;
		}
		$post_id_seq++;
	}
}
check( '[13] a full 10-slot Book matrix (5 exercises x {DragDrop, MCQ}) is all correctly classified', $batch_ok, true );
check( '[13] exactly 10 slots were exercised', $post_id_seq - 200, 10 );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
