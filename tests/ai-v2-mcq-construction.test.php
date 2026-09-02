<?php
/**
 * Regression tests for Citex_AI_V2's MCQ generation path — added alongside
 * DragDrop as Citex's second supported question type.
 *
 * Mirrors the "Citex, not Gemini, is the authority" principle already used
 * for DragDrop's Question Parts/Fixed Text: Gemini supplies the same
 * canonical bibliographic fields (authorFullName/year/bookTitle/place/publisher)
 * plus exactly 3 incorrectReferences, but NEVER the correct answer itself —
 * Citex constructs the single correctly-formatted Harvard reference from the
 * canonical fields (normalise_mcq_item()) and places it at a
 * question-ID-derived (not random, so tests stay deterministic) position
 * among the 4 options.
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-mcq-construction.test.php` — not shipped in
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

function invoke_normalise( $questions, $ids, $difficulty, $exercises = array(), $type = 'DragDrop' ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions, $ids, $difficulty, $exercises, $type );
}

function mcq_item( $overrides = array() ) {
	return array_merge(
		array(
			'scenario'            => 'You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.',
			'authorFullName'      => 'Alan Bryman',
			'year'                => '2012',
			'bookTitle'           => 'Social Research Methods',
			'place'               => 'Oxford',
			'publisher'           => 'Oxford University Press',
			'incorrectReferences' => array(
				'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.',
				'A. Bryman (2012) Social Research Methods. Oxford: Oxford University Press.',
				'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.',
			),
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A valid MCQ item produces a correctly-shaped MCQ candidate: type=MCQ,
// exactly 4 options, and the correct option is CITEX'S OWN construction —
// never one of Gemini's incorrectReferences, even coincidentally.
// ---------------------------------------------------------------------
$result = invoke_normalise( array( mcq_item() ), array( 'BK02' ), 'medium', array(), 'MCQ' );
check( '[1] normalise() succeeds for a valid MCQ item', is_wp_error( $result ), false );
if ( ! is_wp_error( $result ) ) {
	$candidate = $result[0];
	check( '[1] candidate type is MCQ', $candidate['type'], 'MCQ' );
	check( '[1] candidate title names MCQ', $candidate['title'], 'Harvard | ReferenceList | Book | MCQ | BK02' );
	check( '[1] exactly 4 options', count( $candidate['options'] ), 4 );
	// crc32('BK02') % 4 === 0 — deterministic, computed independently of the
	// implementation to avoid a tautological test.
	check( '[1] the correct option position is derived deterministically from the question ID', $candidate['correctOptionIndex'], 0 );
	check( '[1] the correct option is Citex\'s own construction, not any Gemini incorrectReference', $candidate['options'][0], 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.' );
	check( '[1] reconstructedReference matches the correct option', $candidate['reconstructedReference'], $candidate['options'][0] );
	check( '[1] the other 3 options are exactly Gemini\'s incorrectReferences', array_slice( $candidate['options'], 1 ), mcq_item()['incorrectReferences'] );
	check( '[1] validation passed (pre-queue quality gate)', $candidate['validationStatus'], 'passed' );
	check( '[1] correctOptionLetter matches correctOptionIndex (0 -> A)', $candidate['correctOptionLetter'], 'A' );
	check( '[1] an explanation is generated (written to the real Hint field on population)', '' !== trim( $candidate['explanation'] ), true );
	check( '[1] the explanation names the correct letter', false !== strpos( $candidate['explanation'], 'A is correct' ), true );
}

// A second ID with a different crc32-derived slot proves the position
// genuinely varies per question rather than being a fixed constant.
$result2 = invoke_normalise( array( mcq_item() ), array( 'BK04' ), 'medium', array(), 'MCQ' );
if ( ! is_wp_error( $result2 ) ) {
	check( '[1] a different question ID lands the correct option in a different slot', $result2[0]['correctOptionIndex'], 1 );
	check( '[1] the correct option is still Citex\'s own construction at the new slot', $result2[0]['options'][1], 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.' );
	check( '[1] correctOptionLetter tracks the new slot (1 -> B)', $result2[0]['correctOptionLetter'], 'B' );
	check( '[1] the explanation names the new correct letter', false !== strpos( $result2[0]['explanation'], 'B is correct' ), true );
}

// ---------------------------------------------------------------------
// 2. Exactly 3 incorrectReferences are required.
// ---------------------------------------------------------------------
$two = invoke_normalise( array( mcq_item( array( 'incorrectReferences' => array( 'a', 'b' ) ) ) ), array( 'BK02' ), 'medium', array(), 'MCQ' );
check( '[2] 2 incorrectReferences (not 3) is rejected', is_wp_error( $two ), true );
check( '[2] error code identifies the option-count problem', is_wp_error( $two ) ? $two->get_error_code() : null, 'citex_ai_bad_mcq_options' );

$four = invoke_normalise( array( mcq_item( array( 'incorrectReferences' => array( 'a', 'b', 'c', 'd' ) ) ) ), array( 'BK02' ), 'medium', array(), 'MCQ' );
check( '[2] 4 incorrectReferences (not 3) is rejected', is_wp_error( $four ), true );

// ---------------------------------------------------------------------
// 3. An "incorrect" reference identical to the correct one is rejected —
// Gemini must never be trusted to supply the correct answer, even by
// coincidence.
// ---------------------------------------------------------------------
$matches_correct = invoke_normalise(
	array(
		mcq_item(
			array(
				'incorrectReferences' => array(
					'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.',
					'A. Bryman (2012) Social Research Methods. Oxford: Oxford University Press.',
					'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.',
				),
			)
		),
	),
	array( 'BK02' ),
	'medium',
	array(),
	'MCQ'
);
check( '[3] an "incorrect" reference identical to the correct one is rejected', is_wp_error( $matches_correct ), true );
check( '[3] error code identifies the collision', is_wp_error( $matches_correct ) ? $matches_correct->get_error_code() : null, 'citex_ai_mcq_option_matches_correct' );

// ---------------------------------------------------------------------
// 4. Duplicate incorrectReferences are rejected.
// ---------------------------------------------------------------------
$duplicate = invoke_normalise(
	array(
		mcq_item(
			array(
				'incorrectReferences' => array(
					'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.',
					'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.',
					'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.',
				),
			)
		),
	),
	array( 'BK02' ),
	'medium',
	array(),
	'MCQ'
);
check( '[4] duplicate incorrectReferences are rejected', is_wp_error( $duplicate ), true );
check( '[4] error code identifies the duplicate', is_wp_error( $duplicate ) ? $duplicate->get_error_code() : null, 'citex_ai_mcq_duplicate_option' );

// ---------------------------------------------------------------------
// 5. Missing bibliographic data is rejected — the SAME shared field
// extraction/validation used by the DragDrop path also gates MCQ.
// ---------------------------------------------------------------------
$missing_place = invoke_normalise( array( mcq_item( array( 'place' => '' ) ) ), array( 'BK02' ), 'medium', array(), 'MCQ' );
check( '[5] a missing bibliographic field (place) is rejected for MCQ too', is_wp_error( $missing_place ), true );
check( '[5] error code identifies the missing field', is_wp_error( $missing_place ) ? $missing_place->get_error_code() : null, 'citex_ai_missing_field' );

// ---------------------------------------------------------------------
// 6. Author derivation (surname/initials from authorFullName) is shared
// with DragDrop and applies identically to MCQ candidates.
// ---------------------------------------------------------------------
$multi_name = invoke_normalise(
	array( mcq_item( array( 'authorFullName' => 'John Michael Smith', 'bookTitle' => 'Systems Theory', 'incorrectReferences' => array(
		'Smith J.M. (2012) Systems Theory. Oxford: Oxford University Press.',
		'J.M. Smith (2012) Systems Theory. Oxford: Oxford University Press.',
		'Smith, J.M. (2012) Systems Theory. Oxford:Oxford University Press.',
	), 'scenario' => 'You are referencing the book titled Systems Theory by John Michael Smith, published in 2012 by Oxford University Press in Oxford.' ) ) ),
	array( 'BK02' ),
	'medium',
	array(),
	'MCQ'
);
check( '[6] normalise() succeeds for a multi-given-name MCQ author', is_wp_error( $multi_name ), false );
if ( ! is_wp_error( $multi_name ) ) {
	check( '[6] surname is derived the same way as DragDrop', $multi_name[0]['authorSurname'], 'Smith' );
	check( '[6] initials are derived the same way as DragDrop', $multi_name[0]['authorInitials'], 'J.M.' );
	check( '[6] the Citex-built correct option uses the derived surname/initials', $multi_name[0]['options'][ $multi_name[0]['correctOptionIndex'] ], 'Smith, J.M. (2012) Systems Theory. Oxford: Oxford University Press.' );
}

// ---------------------------------------------------------------------
// 7. Exercise assignment (Citex-owned, by slot index) applies identically
// to MCQ candidates — Gemini's MCQ schema has no exercise field either.
// ---------------------------------------------------------------------
$with_exercise = invoke_normalise( array( mcq_item() ), array( 'BK02' ), 'medium', array( 'Exercise 3' ), 'MCQ' );
check( '[7] MCQ candidates are stamped with their pre-assigned exercise', is_wp_error( $with_exercise ) ? null : $with_exercise[0]['exercise'], 'Exercise 3' );

// ---------------------------------------------------------------------
// 8. A leaked scenario is rejected by the pre-queue quality gate for MCQ
// too (validate_answer_leakage() is reused unchanged).
// ---------------------------------------------------------------------
$leaked = invoke_normalise(
	array( mcq_item( array( 'scenario' => 'You are referencing Social Research Methods by Alan Bryman (initials A.), published in 2012 by Oxford University Press in Oxford.' ) ) ),
	array( 'BK02' ),
	'medium',
	array(),
	'MCQ'
);
check( '[8] a leaked scenario is rejected by the pre-queue quality gate', is_wp_error( $leaked ), true );
check( '[8] error code identifies the quality-gate rejection', is_wp_error( $leaked ) ? $leaked->get_error_code() : null, 'citex_ai_validator_rejected' );

// ---------------------------------------------------------------------
// 9. Sanity check: the default/DragDrop dispatch path is unaffected by the
// new $type parameter — omitting it still produces a DragDrop candidate.
// ---------------------------------------------------------------------
$dragdrop_item = array(
	'scenario'       => 'You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.',
	'authorFullName' => 'Alan Bryman',
	'year'           => '2012',
	'bookTitle'      => 'Social Research Methods',
	'place'          => 'Oxford',
	'publisher'      => 'Oxford University Press',
	'confusingWords' => array( '2015', 'Manchester', 'Brown' ),
);
$dragdrop_result = invoke_normalise( array( $dragdrop_item ), array( 'BK99' ), 'medium' );
check( '[9] omitting $type still produces a DragDrop candidate (default unaffected by MCQ support)', is_wp_error( $dragdrop_result ) ? null : $dragdrop_result[0]['type'], 'DragDrop' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
