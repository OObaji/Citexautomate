<?php
/**
 * Regression tests for Citex_AI_V2's MCQ generation path — added alongside
 * DragDrop as Citex's second supported question type.
 *
 * Mirrors the "Citex, not Gemini, is the authority" principle already used
 * for DragDrop's Question Parts/Fixed Text: Gemini supplies the same
 * canonical bibliographic fields (authorFullName/year/bookTitle/place/publisher)
 * plus exactly 3 `distractors` — {reference, errorReason} objects, never the
 * correct answer itself — Citex constructs the single correctly-formatted
 * Harvard reference from the canonical fields (normalise_mcq_item()) and
 * places it at a question-ID-derived (not random, so tests stay
 * deterministic) position among the 4 options.
 *
 * The `errorReason` requirement (every distractor must name the specific
 * Harvard rule it breaks) is the fix for MCQ questions being rejected by
 * MCQ_DISTRACTOR_LOOKS_CORRECT: Gemini is now made to commit to a specific
 * claim about why each distractor is wrong, structurally enforced here —
 * see extract_mcq_distractors(). This never weakens
 * MCQ_DISTRACTOR_LOOKS_CORRECT itself (Citex_Generated_Validator still
 * independently re-validates every option against the real Harvard format
 * rules) — a distractor with a plausible-sounding errorReason that is
 * still, when read literally, a fully valid reference is still rejected.
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

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
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

function invoke_normalise( $questions, $ids, $difficulty, $exercises = array(), $type = 'DragDrop', $category = null ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions, $ids, $difficulty, $exercises, $type, $category );
}

function distractor( $reference, $reason = 'Uses the author\'s full first name instead of initials.' ) {
	return array( 'reference' => $reference, 'errorReason' => $reason );
}

function mcq_item( $overrides = array() ) {
	return array_merge(
		array(
			'scenario'    => 'You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.',
			'authorFullName' => 'Alan Bryman',
			'year'        => '2012',
			'bookTitle'   => 'Social Research Methods',
			'place'       => 'Oxford',
			'publisher'   => 'Oxford University Press',
			'distractors' => array(
				distractor( 'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.', 'Uses the author\'s full first name instead of initials.' ),
				distractor( 'A. Bryman (2012) Social Research Methods. Oxford: Oxford University Press.', 'Places the initials before the surname.' ),
				distractor( 'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.', 'Missing the space after the colon between place and publisher.' ),
			),
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A valid MCQ item produces a correctly-shaped MCQ candidate: type=MCQ,
// exactly 4 options, and the correct option is CITEX'S OWN construction —
// never one of Gemini's distractors, even coincidentally. Each option's
// error reason rides along in optionErrorReasons (null at the correct slot).
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
	check( '[1] the correct option is Citex\'s own construction, not any Gemini distractor', $candidate['options'][0], 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.' );
	check( '[1] reconstructedReference matches the correct option', $candidate['reconstructedReference'], $candidate['options'][0] );
	check( '[1] the other 3 options are exactly Gemini\'s distractor references', array_slice( $candidate['options'], 1 ), array_column( mcq_item()['distractors'], 'reference' ) );
	check( '[1] validation passed (pre-queue quality gate)', $candidate['validationStatus'], 'passed' );
	check( '[1] correctOptionLetter matches correctOptionIndex (0 -> A)', $candidate['correctOptionLetter'], 'A' );
	check( '[1] an explanation is generated (written to the real Hint field on population)', '' !== trim( $candidate['explanation'] ), true );
	check( '[1] the explanation names the correct letter', false !== strpos( $candidate['explanation'], 'A is correct' ), true );
	check( '[1] optionErrorReasons has 4 entries, aligned with options', count( $candidate['optionErrorReasons'] ), 4 );
	check( '[1] the correct slot\'s error reason is null', $candidate['optionErrorReasons'][0], null );
	check( '[1] the other 3 slots carry their distractor\'s error reason', array_slice( $candidate['optionErrorReasons'], 1 ), array_column( mcq_item()['distractors'], 'errorReason' ) );
}

// A second ID with a different crc32-derived slot proves the position
// genuinely varies per question rather than being a fixed constant.
$result2 = invoke_normalise( array( mcq_item() ), array( 'BK04' ), 'medium', array(), 'MCQ' );
if ( ! is_wp_error( $result2 ) ) {
	check( '[1] a different question ID lands the correct option in a different slot', $result2[0]['correctOptionIndex'], 1 );
	check( '[1] the correct option is still Citex\'s own construction at the new slot', $result2[0]['options'][1], 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.' );
	check( '[1] correctOptionLetter tracks the new slot (1 -> B)', $result2[0]['correctOptionLetter'], 'B' );
	check( '[1] the explanation names the new correct letter', false !== strpos( $result2[0]['explanation'], 'B is correct' ), true );
	check( '[1] the correct slot\'s error reason is still null at the new slot', $result2[0]['optionErrorReasons'][1], null );
}

// ---------------------------------------------------------------------
// 2. Exactly 3 distractors are required.
// ---------------------------------------------------------------------
$two = invoke_normalise( array( mcq_item( array( 'distractors' => array( distractor( 'a' ), distractor( 'b' ) ) ) ) ), array( 'BK02' ), 'medium', array(), 'MCQ' );
check( '[2] 2 distractors (not 3) is rejected', is_wp_error( $two ), true );
check( '[2] error code identifies the option-count problem', is_wp_error( $two ) ? $two->get_error_code() : null, 'citex_ai_bad_mcq_options' );

$four = invoke_normalise( array( mcq_item( array( 'distractors' => array( distractor( 'a' ), distractor( 'b' ), distractor( 'c' ), distractor( 'd' ) ) ) ) ), array( 'BK02' ), 'medium', array(), 'MCQ' );
check( '[2] 4 distractors (not 3) is rejected', is_wp_error( $four ), true );

// ---------------------------------------------------------------------
// 3. An "incorrect" reference identical to the correct one is rejected —
// Gemini must never be trusted to supply the correct answer, even by
// coincidence, even when it supplies an errorReason for it.
// ---------------------------------------------------------------------
$matches_correct = invoke_normalise(
	array(
		mcq_item(
			array(
				'distractors' => array(
					distractor( 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.', 'Claimed mistake that is not actually present.' ),
					distractor( 'A. Bryman (2012) Social Research Methods. Oxford: Oxford University Press.' ),
					distractor( 'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.' ),
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
// 4. Duplicate distractor references are rejected.
// ---------------------------------------------------------------------
$duplicate = invoke_normalise(
	array(
		mcq_item(
			array(
				'distractors' => array(
					distractor( 'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.' ),
					distractor( 'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.' ),
					distractor( 'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.' ),
				),
			)
		),
	),
	array( 'BK02' ),
	'medium',
	array(),
	'MCQ'
);
check( '[4] duplicate distractor references are rejected', is_wp_error( $duplicate ), true );
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
	array( mcq_item( array( 'authorFullName' => 'John Michael Smith', 'bookTitle' => 'Systems Theory', 'distractors' => array(
		distractor( 'Smith J.M. (2012) Systems Theory. Oxford: Oxford University Press.' ),
		distractor( 'J.M. Smith (2012) Systems Theory. Oxford: Oxford University Press.' ),
		distractor( 'Smith, J.M. (2012) Systems Theory. Oxford:Oxford University Press.' ),
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

// ---------------------------------------------------------------------
// 10. A distractor with an empty/missing errorReason is rejected before
// ever reaching the Harvard format checks — "if an incorrect option
// cannot be given a specific valid error reason, reject and regenerate".
// ---------------------------------------------------------------------
$missing_reason = invoke_normalise(
	array( mcq_item( array( 'distractors' => array(
		array( 'reference' => 'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.', 'errorReason' => '' ),
		distractor( 'A. Bryman (2012) Social Research Methods. Oxford: Oxford University Press.' ),
		distractor( 'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.' ),
	) ) ) ),
	array( 'BK02' ),
	'medium',
	array(),
	'MCQ'
);
check( '[10] a distractor with an empty errorReason is rejected', is_wp_error( $missing_reason ), true );
check( '[10] error code identifies the missing reason', is_wp_error( $missing_reason ) ? $missing_reason->get_error_code() : null, 'citex_ai_mcq_distractor_reason_missing' );

$missing_reference = invoke_normalise(
	array( mcq_item( array( 'distractors' => array(
		array( 'reference' => '', 'errorReason' => 'Uses the full first name instead of initials.' ),
		distractor( 'A. Bryman (2012) Social Research Methods. Oxford: Oxford University Press.' ),
		distractor( 'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.' ),
	) ) ) ),
	array( 'BK02' ),
	'medium',
	array(),
	'MCQ'
);
check( '[10] a distractor with an empty reference is rejected', is_wp_error( $missing_reference ), true );
check( '[10] error code identifies the missing reference', is_wp_error( $missing_reference ) ? $missing_reference->get_error_code() : null, 'citex_ai_mcq_distractor_missing_reference' );

// ---------------------------------------------------------------------
// 11. CRITICAL — supplying an errorReason does not bypass the existing
// ambiguity gate. A distractor that is fully correctly formatted (for a
// DIFFERENT book, so it doesn't collide with the correct option) still
// fails MCQ_DISTRACTOR_LOOKS_CORRECT even though Gemini claimed a mistake.
// This is the exact BK21 bug report: the fix is fewer ambiguous questions
// reaching this gate, never a weaker gate.
// ---------------------------------------------------------------------
$still_ambiguous = invoke_normalise(
	array( mcq_item( array( 'distractors' => array(
		distractor( 'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.' ),
		distractor( 'A. Bryman (2012) Social Research Methods. Oxford: Oxford University Press.' ),
		// Fully valid Harvard Book shape for a different, unrelated book —
		// Gemini claims a mistake, but none is actually present.
		distractor( 'Adams, R. (2015) A Totally Different Book. Manchester: Routledge.', 'Wrong publisher for this book.' ),
	) ) ) ),
	array( 'BK02' ),
	'medium',
	array(),
	'MCQ'
);
check( '[11] a distractor that is fully valid despite its claimed errorReason still fails the quality gate', is_wp_error( $still_ambiguous ), true );
check( '[11] error code is the existing validator-rejection code (MCQ_DISTRACTOR_LOOKS_CORRECT), not silently bypassed', is_wp_error( $still_ambiguous ) ? $still_ambiguous->get_error_code() : null, 'citex_ai_validator_rejected' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
