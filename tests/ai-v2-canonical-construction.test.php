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

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-generated-validator.php';
require __DIR__ . '/../citex-tools/includes/class-citex-question-scenarios.php';
require __DIR__ . '/../citex-tools/includes/class-citex-question-diversity.php';
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
	'authorFullNames' => array( 'Stella Cottrell' ),
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
	'authorFullNames' => array( 'Stella Cottrell' ),
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
		'scenario'       => "You are referencing a book titled Book $suffix by Andrew Smith, published in London by Example Press in 2020.",
		'authorFullNames' => array( 'Andrew Smith' ),
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

// ---------------------------------------------------------------------
// 4. Citex — not Gemini — derives surname/initials from authorFullName.
// Multiple given names produce concatenated initials with no spaces.
// ---------------------------------------------------------------------
$multi_given_name_item = array(
	'scenario'       => 'You are creating a reference for a book titled Systems Theory by John Michael Smith, published in Boston by Academic Press in 2015.',
	'authorFullNames' => array( 'John Michael Smith' ),
	'year'           => '2015',
	'bookTitle'      => 'Systems Theory',
	'place'          => 'Boston',
	'publisher'      => 'Academic Press',
	'confusingWords' => array( '2013', 'London', 'Green' ),
);
$multi_result = invoke_normalise( array( $multi_given_name_item ), array( 'BK20' ), 'medium' );
check( '[author derivation] normalise() succeeds for a multi-given-name author', is_wp_error( $multi_result ), false );
if ( ! is_wp_error( $multi_result ) ) {
	check( '[author derivation] surname is the last word of authorFullName', $multi_result[0]['authorSurname'], 'Smith' );
	check( '[author derivation] initials are every other word\'s first letter, concatenated, no spaces', $multi_result[0]['authorInitials'], 'J.M.' );
	check( '[author derivation] Question Parts use the derived surname/initials, not authorFullName itself', $multi_result[0]['questionParts'], array( 'Smith', 'J.M.', '2015', 'Systems Theory' ) );
}

// A single-word author name (no given name) cannot be derived into
// initials at all and must be rejected, not silently guessed.
$incomplete_name_item = array(
	'scenario'       => 'You are referencing a book titled Systems Theory by Smith, published in Boston by Academic Press in 2015.',
	'authorFullNames' => array( 'Smith' ),
	'year'           => '2015',
	'bookTitle'      => 'Systems Theory',
	'place'          => 'Boston',
	'publisher'      => 'Academic Press',
	'confusingWords' => array( '2013', 'London', 'Green' ),
);
$incomplete_result = invoke_normalise( array( $incomplete_name_item ), array( 'BK21' ), 'medium' );
check( '[author derivation] a surname-only authorFullName (no given name) is rejected', is_wp_error( $incomplete_result ), true );
check( '[author derivation] error code identifies the incomplete name', is_wp_error( $incomplete_result ) ? $incomplete_result->get_error_code() : null, 'citex_ai_missing_field' );

// ---------------------------------------------------------------------
// 5. Multi-author Book (Liverpool Hope's reference-list rule): all authors
// listed in full, joined with "and"/commas, "et al." never used. The
// DragDrop shape switches to 3 parts (joined author list, year, title) —
// the whole author list is ONE draggable part, not one part per author.
// ---------------------------------------------------------------------
$three_author_item = array(
	'scenario'        => 'You are referencing a book titled Understanding digital culture by John Smith, Amy Jones and Tom Brown, published in London by SAGE Publications in 2020.',
	'authorFullNames' => array( 'John Smith', 'Amy Jones', 'Tom Brown' ),
	'year'            => '2020',
	'bookTitle'       => 'Understanding digital culture',
	'place'           => 'London',
	'publisher'       => 'SAGE Publications',
	'confusingWords'  => array( '2018', 'Paris', 'Routledge' ),
);
$three_author_result = invoke_normalise( array( $three_author_item ), array( 'BK30' ), 'medium' );
check( '[multi-author] normalise() succeeds for three authors', is_wp_error( $three_author_result ), false );
if ( ! is_wp_error( $three_author_result ) ) {
	$candidate = $three_author_result[0];
	check( '[multi-author] Question Parts are the 3-part joined shape', $candidate['questionParts'], array( 'Smith, J., Jones, A. and Brown, T.', '2020', 'Understanding digital culture' ) );
	check( '[multi-author] Fixed Text has 3 placeholder tokens', $candidate['fixedText'], '| (||) ||. London: SAGE Publications.' );
	check( '[multi-author] the reconstructed reference joins all three authors, never "et al."', $candidate['reconstructedReference'], 'Smith, J., Jones, A. and Brown, T. (2020) Understanding digital culture. London: SAGE Publications.' );
	check( '[multi-author] "et al." never appears in the reference', false !== strpos( $candidate['reconstructedReference'], 'et al' ), false );
	check( '[multi-author] authorFullNames is carried through in order', $candidate['authorFullNames'], array( 'John Smith', 'Amy Jones', 'Tom Brown' ) );
	check( '[multi-author] the first author is kept as the back-compat authorFullName/authorSurname/authorInitials fields', $candidate['authorSurname'], 'Smith' );
}

// A 4-author question must still list every author in full — no et-al
// cutoff at 4+, per Liverpool Hope's confirmed reference-list rule.
$four_author_item = array(
	'scenario'        => 'You are creating a reference for a book titled Understanding digital culture by John Smith, Amy Jones, Tom Brown and Rita Williams, published in London by SAGE Publications in 2020.',
	'authorFullNames' => array( 'John Smith', 'Amy Jones', 'Tom Brown', 'Rita Williams' ),
	'year'            => '2020',
	'bookTitle'       => 'Understanding digital culture',
	'place'           => 'London',
	'publisher'       => 'SAGE Publications',
	'confusingWords'  => array( '2018', 'Paris', 'Routledge' ),
);
$four_author_result = invoke_normalise( array( $four_author_item ), array( 'BK31' ), 'medium' );
check( '[multi-author] normalise() succeeds for four authors', is_wp_error( $four_author_result ), false );
if ( ! is_wp_error( $four_author_result ) ) {
	check(
		'[multi-author] all four authors are listed in full — no "et al." cutoff at 4+',
		$four_author_result[0]['reconstructedReference'],
		'Smith, J., Jones, A., Brown, T. and Williams, R. (2020) Understanding digital culture. London: SAGE Publications.'
	);
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
