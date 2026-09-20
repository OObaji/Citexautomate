<?php
/**
 * Regression tests for Citex_Questions::find_broken_intext_page_questions()
 * and its wiring into the Question Bank page — the user-facing half of the
 * "detect and trash broken In-Text Citation page-reference questions"
 * feature. See Citex_Scanner::is_intext_dragdrop_missing_page()'s own
 * docblock for the underlying bug this surfaces.
 *
 * Repo-level only, run with plain
 * `php tests/questions-broken-intext-page.test.php` — not shipped in
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
function get_edit_post_link( $post_id, $context = 'display' ) {
	return 'https://example.test/wp-admin/post.php?post=' . $post_id . '&action=edit';
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

require __DIR__ . '/../citex-tools/includes/class-citex-scanner.php';
require __DIR__ . '/../citex-tools/includes/class-citex-populator.php';
require __DIR__ . '/../citex-tools/includes/class-citex-questions.php';

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

function invoke_find_broken( array $questions, $citations_post_type ) {
	$reflection = new ReflectionMethod( 'Citex_Questions', 'find_broken_intext_page_questions' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions, $citations_post_type );
}

$GLOBALS['__acf_field_groups_by_post_type'] = array(
	'citex_citations' => array(
		array( 'key' => 'group_1', 'fields' => array( array( 'key' => 'field_scenario', 'label' => 'Scenario', 'name' => 'scenario', 'sub_fields' => array() ) ) ),
	),
);
$GLOBALS['__acf_field_groups_by_post_id'] = array();
$GLOBALS['__post_field_objects']          = array();
$GLOBALS['__acf_fields']                  = array(
	Citex_Populator::FIELD_FIXED_TEXT      => array( 'key' => Citex_Populator::FIELD_FIXED_TEXT, 'type' => 'text' ),
	Citex_Populator::FIELD_QUESTION_PARTS  => array( 'key' => Citex_Populator::FIELD_QUESTION_PARTS, 'type' => 'text' ),
	Citex_Populator::FIELD_CONFUSING_WORDS => array( 'key' => Citex_Populator::FIELD_CONFUSING_WORDS, 'type' => 'text' ),
	Citex_Populator::FIELD_QUESTION_CLASS  => array( 'key' => Citex_Populator::FIELD_QUESTION_CLASS, 'type' => 'text' ),
);
$GLOBALS['__acf_values'] = array(
	// Broken: parenthetical_quote form, scenario never mentions the page (34).
	601 => array(
		Citex_Populator::FIELD_FIXED_TEXT     => '"a striking claim" (||, ||, p. ||).',
		'field_scenario'                      => 'Complete the parenthetical in-text citation for a direct quotation from the Book Example Title by Smith, published in 2020.',
		Citex_Populator::FIELD_QUESTION_PARTS => array( 'Smith', '2020', '34' ),
	),
	// Fixed: parenthetical_quote form, scenario DOES mention the page (56).
	602 => array(
		Citex_Populator::FIELD_FIXED_TEXT     => '"another claim" (||, ||, p. ||).',
		'field_scenario'                      => 'Complete the parenthetical in-text citation for a direct quotation from the Journal Article Example Title by Jones, published in 2019, found on page 56.',
		Citex_Populator::FIELD_QUESTION_PARTS => array( 'Jones', '2019', '56' ),
	),
	// Reference List DragDrop (not In-Text Citation) — never a candidate at all.
	603 => array(
		Citex_Populator::FIELD_FIXED_TEXT     => '|, || (||). London: Example Press.',
		'field_scenario'                      => 'Complete the reference for this book.',
		Citex_Populator::FIELD_QUESTION_PARTS => array( 'Smith', 'J.', '2020' ),
	),
);

$questions = array(
	array( 'wpPostId' => 601, 'group' => 'InTextCitation', 'type' => 'DragDrop', 'source' => 'Harvard', 'category' => 'Book', 'questionId' => 'IB01', 'editUrl' => 'https://example.test/edit/601' ),
	array( 'wpPostId' => 602, 'group' => 'InTextCitation', 'type' => 'DragDrop', 'source' => 'Harvard', 'category' => 'Journal Article', 'questionId' => 'IJ01', 'editUrl' => 'https://example.test/edit/602' ),
	array( 'wpPostId' => 603, 'group' => 'ReferenceList', 'type' => 'DragDrop', 'source' => 'Harvard', 'category' => 'Book', 'questionId' => 'BK01', 'editUrl' => 'https://example.test/edit/603' ),
	array( 'wpPostId' => 604, 'group' => 'InTextCitation', 'type' => 'MCQ', 'source' => 'Harvard', 'category' => 'Website', 'questionId' => 'IW01', 'editUrl' => 'https://example.test/edit/604' ),
);

// ---------------------------------------------------------------------
// 1. Only the genuinely broken In-Text Citation DragDrop post is returned
// — never the fixed one, never the Reference List post, never the MCQ.
// ---------------------------------------------------------------------
$broken = invoke_find_broken( $questions, 'citex_citations' );
check( '[1] exactly one broken question is found', count( $broken ), 1 );
check( '[1] it is the correct post', $broken[0]['postId'] ?? null, 601 );
check( '[1] its own questionId is reported', $broken[0]['questionId'] ?? null, 'IB01' );
check( '[1] its own scenario is reported', $broken[0]['scenario'] ?? null, 'Complete the parenthetical in-text citation for a direct quotation from the Book Example Title by Smith, published in 2020.' );
check( '[1] the missing page value (34) is reported', $broken[0]['page'] ?? null, '34' );
check( '[1] its own edit URL is reported', $broken[0]['editUrl'] ?? null, 'https://example.test/edit/601' );

// ---------------------------------------------------------------------
// 2. No Citations post type configured — returns empty, never errors.
// ---------------------------------------------------------------------
check( '[2] an unconfigured Citations post type returns no candidates', invoke_find_broken( $questions, '' ), array() );

// ---------------------------------------------------------------------
// 3. No In-Text Citation DragDrop candidates at all — returns empty.
// ---------------------------------------------------------------------
check( '[3] a question list with no In-Text Citation DragDrop candidates returns empty', invoke_find_broken( array( $questions[2], $questions[3] ), 'citex_citations' ), array() );

// ---------------------------------------------------------------------
// Wiring: the view renders the new panel, gated on non-empty results, with
// a "Move All to Bin" button, and JS wires it via the existing batching
// infrastructure — mirroring "Clear Question Bank"'s own established
// pattern rather than duplicating it.
// ---------------------------------------------------------------------
$questions_view_source = file_get_contents( __DIR__ . '/../citex-tools/admin/views/questions.php' );
check( '[wiring] the view renders the broken-page panel', false !== strpos( $questions_view_source, 'citex-broken-intext-page-panel' ), true );
check( '[wiring] the panel is gated on non-empty $broken_page_questions', false !== strpos( $questions_view_source, 'if ( ! empty( $broken_page_questions ) )' ), true );
check( '[wiring] the panel exposes the broken post IDs for the trash action', false !== strpos( $questions_view_source, 'data-all-post-ids="<?php echo esc_attr( wp_json_encode( $broken_page_post_ids ) ); ?>"' ), true );
check( '[wiring] the "Move All to Bin" trash button exists', false !== strpos( $questions_view_source, 'id="citex-trash-broken-intext-page"' ), true );

$questions_class_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-questions.php' );
check( '[wiring] render() computes broken_page_questions from the merged scan', false !== strpos( $questions_class_source, 'self::find_broken_intext_page_questions( $scan[\'questions\'] ?? array(), $citations_post_type )' ), true );
check( '[wiring] render() derives broken_page_post_ids from broken_page_questions', false !== strpos( $questions_class_source, "wp_list_pluck( \$broken_page_questions, 'postId' )" ), true );

$bulk_edit_js_source = file_get_contents( __DIR__ . '/../citex-tools/admin/js/citex-bulk-edit.js' );
check( '[wiring] citex-bulk-edit.js wires the trash-broken-page button', false !== strpos( $bulk_edit_js_source, 'wireTrashBrokenIntextPage' ), true );
check( '[wiring] it is called on DOMContentLoaded alongside the other panel wirers', false !== strpos( $bulk_edit_js_source, 'wireTrashBrokenIntextPage();' ), true );
check( '[wiring] it reuses the existing authenticated trash batching (runServerBatches), not a new/duplicated code path', preg_match( '/function wireTrashBrokenIntextPage[\s\S]*?runServerBatches\( ids, .trash. \)/', $bulk_edit_js_source ), 1 );
check( '[wiring] it asks for confirmation before acting (a destructive action)', preg_match( '/function wireTrashBrokenIntextPage[\s\S]*?window\.confirm\(/', $bulk_edit_js_source ), 1 );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
