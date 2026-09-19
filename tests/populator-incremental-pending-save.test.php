<?php
/**
 * Regression test for the "populate isn't working" bug: Citex_Populator::
 * populate_questions() used to remove successfully-populated questions
 * from the Pending queue only ONCE, after its entire batch finished.
 * Populating one question is a slow, synchronous WordPress/ACF round trip
 * (create the post, write every field, then read every one of them back
 * to verify it actually persisted), so a batch of any real size can run
 * long enough to hit a server-side execution-time limit. If that happens
 * partway through, every question already successfully created in
 * WordPress up to that point was NOT yet saved out of Pending — it stays
 * marked "pending" forever, and worse, the NEXT Populate attempt fails it
 * all over again with a duplicate-title error, since its real post
 * already exists. This is the exact same class of bug already fixed for
 * question generation (see generator-split-evenly.test.php's own
 * docblock and class-citex-generator.php's $on_partial_result) — fixed
 * here by removing each question from Pending the instant IT succeeds,
 * never batched to the end.
 *
 * This test proves the fix at the save-option level: update_option() for
 * the pending-questions option must be called incrementally — reflecting
 * the first question's removal — BEFORE the second (deliberately failing)
 * question is even attempted, not only once after the whole batch ends.
 *
 * Repo-level only, run with plain
 * `php tests/populator-incremental-pending-save.test.php` — not shipped
 * in citex-tools.zip.
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
$GLOBALS['__update_option_calls']           = array();

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
	// Recorded so the test can assert the pending-questions option was
	// written incrementally (once per successful question), not only
	// once after the whole batch finished.
	$GLOBALS['__update_option_calls'][] = array( 'key' => $key, 'value' => $value );
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

require __DIR__ . '/../citex-tools/includes/class-citex-generator.php';
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

// Book (1) -> Exercise 1 (2).
$GLOBALS['__terms_full']['reference_category'] = array(
	1 => array( 'name' => 'Book', 'parent' => 0 ),
	2 => array( 'name' => 'Exercise 1', 'parent' => 1 ),
);
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

// A real WordPress-level scan already on record for this post type, so
// populate_batch() never needs a configured scan URL / sync_from_wordpress()
// round trip to resolve the Reference List post type in this test.
$GLOBALS['__options'][ Citex_Scanner::OPTION_SCAN ] = array( 'postType' => 'question', 'questions' => array() );

function mcq_question( $overrides = array() ) {
	return array_merge(
		array(
			'key'                    => wp_generate_uuid4_stub(),
			'questionId'             => 'BK-MCQ-1',
			'title'                  => 'Harvard | ReferenceList | Book | MCQ | BK-MCQ-1',
			'group'                  => 'ReferenceList',
			'category'               => 'Book',
			'exercise'               => 'Exercise 1',
			'type'                   => 'MCQ',
			'validationStatus'       => 'passed',
			'scenario'               => 'You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.',
			'options'                => array(
				'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.',
				'A. Bryman (2012) Social Research Methods. Oxford: Oxford University Press.',
				'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.',
				'',
			),
			'reconstructedReference' => 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.',
			'hint'                   => 'Check the order of the author\'s surname and initials, the position of the year, and the punctuation between the title, place and publisher.',
		),
		$overrides
	);
}
$__uuid_counter = 0;
function wp_generate_uuid4_stub() {
	global $__uuid_counter;
	$__uuid_counter++;
	return 'uuid-' . $__uuid_counter;
}

// ---------------------------------------------------------------------
// Two eligible pending questions: A (a genuinely valid MCQ, will succeed)
// and B (deliberately given no title, so populate_one() fails it
// immediately with citex_missing_title — no post is ever created for B).
// ---------------------------------------------------------------------
$question_a = mcq_question( array( 'key' => 'key-a', 'questionId' => 'BK-MCQ-A' ) );
$question_b = mcq_question( array( 'key' => 'key-b', 'questionId' => 'BK-MCQ-B', 'title' => '' ) );

Citex_Generator::save_pending_questions( array( $question_a, $question_b ) );
$GLOBALS['__update_option_calls'] = array(); // Reset: only count calls made DURING populate_questions() below.

$populator = new Citex_Populator();
$result    = $populator->populate_questions( array( $question_a, $question_b ), 'draft' );

check( '[1] question A populated successfully', count( $result['created'] ), 1 );
check( '[1] question B failed (missing title)', count( $result['failed'] ), 1 );
check( '[1] question A is in successfulKeys', in_array( 'key-a', $result['successfulKeys'], true ), true );

// ---------------------------------------------------------------------
// CRITICAL — the actual regression fix: the pending-questions option must
// have been written incrementally. Question A's removal must show up in
// an update_option() call BEFORE question B was even attempted — i.e.
// there must be an intermediate save whose value contains ONLY question
// B (key-b), proving A was persisted out of Pending the instant it
// succeeded rather than only once at the very end of the whole batch.
// ---------------------------------------------------------------------
$pending_option_calls = array_values(
	array_filter(
		$GLOBALS['__update_option_calls'],
		function ( $call ) {
			return Citex_Generator::OPTION_PENDING === $call['key'];
		}
	)
);
check( '[2] the pending-questions option was saved more than once during population (incremental, not batched to the end)', count( $pending_option_calls ) >= 1, true );

$found_intermediate_a_removed_state = false;
foreach ( $pending_option_calls as $call ) {
	$keys_present = array_map(
		function ( $q ) {
			return $q['key'] ?? '';
		},
		$call['value']
	);
	if ( ! in_array( 'key-a', $keys_present, true ) && in_array( 'key-b', $keys_present, true ) ) {
		$found_intermediate_a_removed_state = true;
		break;
	}
}
check( '[2] an intermediate save exists with A already removed while B (not yet processed/failed) is still present', $found_intermediate_a_removed_state, true );

// ---------------------------------------------------------------------
// Final state: A (succeeded) is gone from Pending; B (failed) remains,
// so it stays visible for review rather than being silently dropped.
// ---------------------------------------------------------------------
$final_pending = Citex_Generator::get_pending_questions();
$final_keys    = array_map(
	function ( $q ) {
		return $q['key'] ?? '';
	},
	$final_pending
);
check( '[3] final Pending no longer contains the succeeded question A', in_array( 'key-a', $final_keys, true ), false );
check( '[3] final Pending still contains the failed question B for review', in_array( 'key-b', $final_keys, true ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
