<?php
/**
 * Regression tests for Citex_AI_V2::normalise() no longer trusting Gemini's
 * own questionParts/fixedText, and for the pre-queue quality gate rejecting
 * a scenario that does not match Gemini's own bibliographic fields — the
 * exact shape of the reported academic-integrity bug (a scenario describing
 * one real book while the structured data described a different one).
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-canonical-construction.test.php` — not shipped in
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
function get_option( $key, $default = null ) {
	return $default;
}

require __DIR__ . '/../citex-tools/includes/class-citex-generated-validator.php';
require __DIR__ . '/../citex-tools/includes/class-citex-ai-v2.php';

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

function invoke_normalise( $questions, $ids, $difficulty, $exercises = array() ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions, $ids, $difficulty, $exercises );
}

// ---------------------------------------------------------------------
// 1. Gemini supplies a conflicting, self-consistent-with-itself but wrong
// questionParts/fixedText alongside correct bibliographic fields and a
// correct scenario. Citex must ignore Gemini's questionParts/fixedText
// entirely and construct its own from the canonical fields.
// ---------------------------------------------------------------------
$item_with_bad_ai_parts = array(
	'scenario'       => 'You are referencing a book titled Critical Thinking Skills by Stella Cottrell, published in London by Red Globe Press in 2019.',
	'authorSurname'  => 'Cottrell',
	'authorInitials' => 'S.',
	'year'           => '2019',
	'bookTitle'      => 'Critical Thinking Skills',
	'place'          => 'London',
	'publisher'      => 'Red Globe Press',
	// Gemini's own (wrong, self-inconsistent-with-its-bib-fields) DragDrop data:
	'questionParts'  => array( 'Cottrell', 'M.', '2016', 'Skills for Success' ),
	'fixedText'      => '|, || (||) ||. Oxford: A Different Publisher.',
	'confusingWords' => array( '2016', 'Manchester', 'Brown' ),
);

$result = invoke_normalise( array( $item_with_bad_ai_parts ), array( 'BK01' ), 'medium' );
check( '[canonical construction] normalise() succeeds despite Gemini\'s own bad questionParts/fixedText', is_wp_error( $result ), false );
if ( ! is_wp_error( $result ) ) {
	$candidate = $result[0];
	check( '[canonical construction] Question Parts are Citex-constructed from the canonical record, not Gemini\'s', $candidate['questionParts'], array( 'Cottrell', 'S.', '2019', 'Critical Thinking Skills' ) );
	check( '[canonical construction] Fixed Text is Citex-constructed from the canonical place/publisher, not Gemini\'s', $candidate['fixedText'], '|, || (||) ||. London: Red Globe Press.' );
	check( '[canonical construction] the reconstructed reference is built from the canonical record', $candidate['reconstructedReference'], 'Cottrell, S. (2019) Critical Thinking Skills. London: Red Globe Press.' );
	check( '[canonical construction] the canonical fields are retained on the pending record', $candidate['authorSurname'] . '|' . $candidate['year'] . '|' . $candidate['bookTitle'], 'Cottrell|2019|Critical Thinking Skills' );
	check( '[canonical construction] the resulting question passes the quality gate (now fully self-consistent)', $candidate['validationStatus'], 'passed' );
}

// ---------------------------------------------------------------------
// 2. Gemini's bibliographic fields are internally self-consistent with each
// other, but the SCENARIO names a different book/year — the exact reported
// bug shape. This must be rejected by the pre-queue quality gate rather
// than silently entering the pending queue.
// ---------------------------------------------------------------------
$item_with_bad_scenario = array(
	'scenario'       => 'You are referencing a book titled Skills for Success by Stella Cottrell, published in London by Red Globe Press in 2016.',
	'authorSurname'  => 'Cottrell',
	'authorInitials' => 'S.',
	'year'           => '2019',
	'bookTitle'      => 'Critical Thinking Skills',
	'place'          => 'London',
	'publisher'      => 'Red Globe Press',
	'questionParts'  => array( 'Cottrell', 'S.', '2019', 'Critical Thinking Skills' ),
	'fixedText'      => '|, || (||) ||. London: Red Globe Press.',
	'confusingWords' => array( '2016', 'Manchester', 'Brown' ),
);
$rejected = invoke_normalise( array( $item_with_bad_scenario ), array( 'BK02' ), 'medium' );
check( '[scenario mismatch] normalise() rejects a scenario that does not match the bibliographic record', is_wp_error( $rejected ), true );
check( '[scenario mismatch] error code identifies the pre-queue quality gate rejection', is_wp_error( $rejected ) ? $rejected->get_error_code() : null, 'citex_ai_validator_rejected' );

// ---------------------------------------------------------------------
// 3. Exercise is stamped from the pre-built assignment matrix by slot
// index — never read from Gemini's own response (its schema has no
// exercise field at all, so there is nothing there to trust or distrust).
// ---------------------------------------------------------------------
function make_valid_item( $suffix ) {
	return array(
		'scenario'       => "You are referencing a book titled Book $suffix by A. Smith, published in London by Example Press in 2020.",
		'authorSurname'  => 'Smith',
		'authorInitials' => 'A.',
		'year'           => '2020',
		'bookTitle'      => "Book $suffix",
		'place'          => 'London',
		'publisher'      => 'Example Press',
		'confusingWords' => array( '2018', 'Manchester', 'Brown' ),
	);
}
$batch_items = array( make_valid_item( 'One' ), make_valid_item( 'Two' ), make_valid_item( 'Three' ) );
$batch_ids   = array( 'BK10', 'BK11', 'BK12' );
$assignments = array( 'Exercise 3', 'Exercise 1', 'Exercise 5' );
$batch_result = invoke_normalise( $batch_items, $batch_ids, 'medium', $assignments );
check( '[exercise assignment] normalise() succeeds', is_wp_error( $batch_result ), false );
if ( ! is_wp_error( $batch_result ) ) {
	check( '[exercise assignment] slot 0 is stamped with its pre-assigned exercise', $batch_result[0]['exercise'], 'Exercise 3' );
	check( '[exercise assignment] slot 1 is stamped with its pre-assigned exercise', $batch_result[1]['exercise'], 'Exercise 1' );
	check( '[exercise assignment] slot 2 is stamped with its pre-assigned exercise', $batch_result[2]['exercise'], 'Exercise 5' );
}

// No assignment supplied at all defaults sensibly (today's only generation
// target) rather than erroring or leaving the field unset.
$no_assignment_result = invoke_normalise( array( make_valid_item( 'Four' ) ), array( 'BK13' ), 'medium' );
check( '[exercise assignment] a missing assignment array defaults to Exercise 1', is_wp_error( $no_assignment_result ) ? null : $no_assignment_result[0]['exercise'], 'Exercise 1' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
