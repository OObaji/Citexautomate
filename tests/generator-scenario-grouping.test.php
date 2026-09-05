<?php
/**
 * Regression tests for Citex_Generator::generate_via_scenarios() — the
 * grouped-request orchestration that issues one Citex_AI_V2::generate_questions()
 * call per scenario (Citex_Question_Diversity::assign_scenarios()) instead
 * of always one shared request for the whole batch, so a single "generate
 * N questions" submission can genuinely test several different Harvard
 * rules instead of the same one N times.
 *
 * Stubs wp_remote_post() with a queue of canned Gemini-shaped responses —
 * one per expected scenario group — consumed in call order.
 *
 * Repo-level only, run with plain
 * `php tests/generator-scenario-grouping.test.php` — not shipped in
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
function sanitize_key( $v ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
}
function sanitize_text_field( $v ) {
	return trim( (string) $v );
}
function sanitize_textarea_field( $v ) {
	return trim( (string) $v );
}
function wp_generate_uuid4() {
	static $n = 0;
	return 'uuid-' . ( $n++ );
}
function __( $s, $d = '' ) {
	return $s;
}
function _n( $single, $plural, $number, $d = '' ) {
	return 1 === (int) $number ? $single : $plural;
}
function absint( $v ) {
	return abs( intval( $v ) );
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}

$GLOBALS['__options']        = array();
$GLOBALS['__response_queue'] = array();
function get_option( $key, $default = false ) {
	return $GLOBALS['__options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}
function wp_remote_post( $url, $args ) {
	if ( empty( $GLOBALS['__response_queue'] ) ) {
		return array( 'response' => array( 'code' => 500 ), 'body' => '' );
	}
	return array_shift( $GLOBALS['__response_queue'] );
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? 200;
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}

// Minimal stand-in for Citex_Populator — generate_via_scenarios() pulls
// exercise coverage through build_exercise_assignments(), which needs this
// one static method; nothing else about the real populator is relevant here.
class Citex_Populator {
	public static function get_population_coverage() {
		return array();
	}
}

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-question-scenarios.php';
require __DIR__ . '/../citex-tools/includes/class-citex-question-diversity.php';
require __DIR__ . '/../citex-tools/includes/class-citex-generated-validator.php';
require __DIR__ . '/../citex-tools/includes/class-citex-ai-v2.php';
require __DIR__ . '/../citex-tools/includes/class-citex-generator.php';

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
	$GLOBALS['__options']        = array( Citex_AI_V2::OPTION_API_KEY => 'test-key' );
	$GLOBALS['__response_queue'] = array();
}

function queue_response( $questions ) {
	$body = wp_json_encode( array( 'questions' => $questions ) );
	$GLOBALS['__response_queue'][] = array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array( 'output_text' => $body ) ),
	);
}

function book_mcq_question( $id, $author_names, $book_title ) {
	return array(
		'questionId'      => $id,
		'authorFullNames' => $author_names,
		'year'            => '2020',
		'bookTitle'       => $book_title,
		'place'           => 'London',
		'publisher'       => 'SAGE Publications',
		'distractors'     => array(
			array( 'reference' => 'Wrong, X (2020) ' . $book_title . '. London: SAGE Publications.', 'errorReason' => 'Missing comma and initials formatting.' ),
			array( 'reference' => 'Also Wrong, Y. 2020 ' . $book_title . '. London: SAGE Publications.', 'errorReason' => 'Missing parentheses around the year.' ),
			array( 'reference' => 'Still Wrong, Z. (2020) ' . $book_title . '. SAGE Publications: London.', 'errorReason' => 'Place and publisher swapped.' ),
		),
	);
}

function invoke_generate_via_scenarios( $category_label, $category_key, $type_label, $type_key, $quantity, $starting_id, $difficulty, $web_verify, $used_ids, $pending ) {
	$generator  = new Citex_Generator();
	$reflection = new ReflectionMethod( 'Citex_Generator', 'generate_via_scenarios' );
	$reflection->setAccessible( true );
	return $reflection->invoke( $generator, $category_label, $category_key, $type_label, $type_key, $quantity, $starting_id, $difficulty, $web_verify, $used_ids, $pending );
}

// ---------------------------------------------------------------------
// 1. A 4-question batch (matching Book's 4 scenarios exactly) issues one
// request per scenario, each producing exactly 1 question, and IDs are
// assigned sequentially with no collisions across groups.
// ---------------------------------------------------------------------
reset_environment();
queue_response( array( book_mcq_question( 'BK01', array( 'John Smith' ), 'Book One' ) ) );
queue_response( array( book_mcq_question( 'BK02', array( 'John Smith', 'Amy Jones' ), 'Book Two' ) ) );
queue_response( array( book_mcq_question( 'BK03', array( 'John Smith', 'Amy Jones', 'Tom Brown' ), 'Book Three' ) ) );
queue_response( array( book_mcq_question( 'BK04', array( 'John Smith', 'Amy Jones', 'Tom Brown', 'Rita Williams' ), 'Book Four' ) ) );

$result = invoke_generate_via_scenarios( 'Book', 'book', 'MCQ', 'mcq', 4, 'BK01', 'medium', false, array(), array() );
check( '[1] generation succeeds across all 4 scenario groups', is_wp_error( $result ), false );
if ( ! is_wp_error( $result ) ) {
	check( '[1] exactly 4 questions were generated (one per scenario)', count( $result ), 4 );
	$scenarios = array_map( function ( $c ) { return $c['blueprint']['scenario']; }, $result );
	sort( $scenarios );
	check( '[1] all 4 scenarios are covered, each exactly once', $scenarios, array( 'four_or_more_authors', 'one_author', 'three_authors', 'two_authors' ) );
	$ids = array_column( $result, 'questionId' );
	check( '[1] no duplicate question IDs across groups', count( $ids ), count( array_unique( $ids ) ) );
}

// ---------------------------------------------------------------------
// 2. Atomicity: if a LATER group's request ultimately fails, the whole
// call returns that WP_Error — the caller (handle_generation()) never
// sees a partial result to accidentally save.
// ---------------------------------------------------------------------
reset_environment();
queue_response( array( book_mcq_question( 'BK01', array( 'John Smith' ), 'Book One' ) ) );
// Second group's response is deliberately malformed (wrong question count)
// so it exhausts every quality-retry attempt and ultimately fails.
$GLOBALS['__response_queue'][] = array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'output_text' => wp_json_encode( array( 'questions' => array() ) ) ) ) );
$GLOBALS['__response_queue'][] = array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'output_text' => wp_json_encode( array( 'questions' => array() ) ) ) ) );
$GLOBALS['__response_queue'][] = array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'output_text' => wp_json_encode( array( 'questions' => array() ) ) ) ) );

$atomic_result = invoke_generate_via_scenarios( 'Book', 'book', 'MCQ', 'mcq', 2, 'BK01', 'medium', false, array(), array() );
check( '[2] a later group\'s failure fails the whole call', is_wp_error( $atomic_result ), true );

// ---------------------------------------------------------------------
// 3. Cross-group duplicate guard: group 2 must not regenerate the exact
// same book group 1 just produced — existing_references grows as each
// group succeeds.
// ---------------------------------------------------------------------
reset_environment();
queue_response( array( book_mcq_question( 'BK01', array( 'John Smith' ), 'Repeated Book' ) ) );
// Group 2 (two_authors) tries to reuse the exact same title/author combo
// group 1 already produced (as a 1-author reference) — not literally
// possible to collide byte-for-byte here since author counts differ, so
// instead prove the mechanism directly: pre-seed $pending with a reference
// group 2's own attempt will exactly match.
$existing_pending = array( array( 'category' => 'Book', 'reconstructedReference' => 'Smith, J. and Jones, A. (2020) Repeated Book. London: SAGE Publications.' ) );
queue_response( array( book_mcq_question( 'BK02', array( 'John Smith', 'Amy Jones' ), 'Repeated Book' ) ) );
queue_response( array( book_mcq_question( 'BK02', array( 'John Smith', 'Amy Jones' ), 'Repeated Book' ) ) );
queue_response( array( book_mcq_question( 'BK02', array( 'John Smith', 'Amy Jones' ), 'Repeated Book' ) ) );

$duplicate_result = invoke_generate_via_scenarios( 'Book', 'book', 'MCQ', 'mcq', 2, 'BK01', 'medium', false, array(), $existing_pending );
check( '[3] a group that would duplicate an existing pending reference fails', is_wp_error( $duplicate_result ), true );

// ---------------------------------------------------------------------
// 4. Regression for a real reported bug: collect_existing_references()
// must never pull a 'choose_treatment'/'identify_error' MCQ question's
// reconstructedReference into the list of "real books already used" —
// that field holds a fixed, deliberately-repeated rule statement (or
// Gemini's free-form errorReason) for those patterns, never an actual
// book reference. Including it made every subsequent choose_treatment
// question for the same rule bucket look like a duplicate of a pending
// question that was never actually about the same book at all.
// ---------------------------------------------------------------------
function invoke_collect_existing_references( $pending, $category_label ) {
	$generator  = new Citex_Generator();
	$reflection = new ReflectionMethod( 'Citex_Generator', 'collect_existing_references' );
	$reflection->setAccessible( true );
	return $reflection->invoke( $generator, $pending, $category_label );
}

$mixed_pending = array(
	array( 'category' => 'Book', 'mcqPattern' => 'choose_treatment', 'reconstructedReference' => 'Both authors are included, joined by "and" — e.g. Smith, J. and Jones, A.' ),
	array( 'category' => 'Book', 'mcqPattern' => 'identify_error', 'reconstructedReference' => 'Missing the final full stop.' ),
	array( 'category' => 'Book', 'reconstructedReference' => 'Cottrell, S. (2019) Critical Thinking Skills. London: Red Globe Press.' ),
);
check(
	'[4] collect_existing_references() excludes choose_treatment/identify_error, keeps a real reference',
	invoke_collect_existing_references( $mixed_pending, 'Book' ),
	array( 'Cottrell, S. (2019) Critical Thinking Skills. London: Red Globe Press.' )
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
