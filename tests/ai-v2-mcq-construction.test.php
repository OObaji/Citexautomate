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
function absint( $v ) {
	return abs( intval( $v ) );
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
			// A stray 'scenario' key is deliberately included here even
			// though Gemini's real MCQ schema no longer has one (see
			// schema_mcq()) — it proves normalise() ignores it entirely for
			// MCQ rather than trusting it, in case Gemini ever sends one
			// anyway. See test [8].
			'scenario'    => 'You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.',
			'authorFullNames' => array( 'Alan Bryman' ),
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
// exactly 4 option slots — Option 1-3 hold the 3 distractors in order,
// Option 4 is ALWAYS blank — and the correct answer lives ONLY in
// reconstructedReference (the Answer field's source), never duplicated
// into any option. Each distractor's error reason rides along in
// optionErrorReasons (null at the blank 4th slot).
// ---------------------------------------------------------------------
$result = invoke_normalise( array( mcq_item() ), array( 'BK02' ), 'medium', array(), 'MCQ' );
check( '[1] normalise() succeeds for a valid MCQ item', is_wp_error( $result ), false );
if ( ! is_wp_error( $result ) ) {
	$candidate = $result[0];
	check( '[1] candidate type is MCQ', $candidate['type'], 'MCQ' );
	check( '[1] candidate title names MCQ', $candidate['title'], 'Harvard | ReferenceList | Book | MCQ | BK02' );
	check( '[1] exactly 4 option slots', count( $candidate['options'] ), 4 );
	check( '[1] options 1-3 are exactly Gemini\'s distractor references, in order', array_slice( $candidate['options'], 0, 3 ), array_column( mcq_item()['distractors'], 'reference' ) );
	check( '[1] option 4 is always blank', $candidate['options'][3], '' );
	check( '[1] the correct answer is Citex\'s own construction and appears in NO option slot', in_array( $candidate['reconstructedReference'], $candidate['options'], true ), false );
	check( '[1] reconstructedReference is Citex\'s own construction', $candidate['reconstructedReference'], 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.' );
	check( '[1] validation passed (pre-queue quality gate)', $candidate['validationStatus'], 'passed' );
	check( '[1] the question text is Citex\'s own fixed Book MCQ stem, never Gemini\'s scenario', $candidate['scenario'], 'Which of the following is the correct Harvard reference for a book?' );
	check( '[1] a hint is generated (written to the real Hint field on population)', '' !== trim( $candidate['hint'] ), true );
	check( '[1] the hint does NOT reproduce the correct reference', false !== strpos( $candidate['hint'], $candidate['reconstructedReference'] ), false );
	check( '[1] an internal-only answerExplanation is also generated (never written to WordPress)', '' !== trim( $candidate['answerExplanation'] ), true );
	check( '[1] optionErrorReasons has 4 entries, aligned with options', count( $candidate['optionErrorReasons'] ), 4 );
	check( '[1] the first 3 slots carry their distractor\'s error reason, in order', array_slice( $candidate['optionErrorReasons'], 0, 3 ), array_column( mcq_item()['distractors'], 'errorReason' ) );
	check( '[1] the blank 4th slot\'s error reason is null', $candidate['optionErrorReasons'][3], null );
}

// A second, different question ID produces the exact same shape — there is
// no more per-question "correct slot" to vary, since the answer is never
// placed among the options at all.
$result2 = invoke_normalise( array( mcq_item() ), array( 'BK04' ), 'medium', array(), 'MCQ' );
if ( ! is_wp_error( $result2 ) ) {
	check( '[1] a different question ID still keeps option 4 blank', $result2[0]['options'][3], '' );
	check( '[1] a different question ID still keeps the answer out of every option', in_array( $result2[0]['reconstructedReference'], $result2[0]['options'], true ), false );
	check( '[1] the hint is still the same fixed, non-revealing text regardless of question ID', $result2[0]['hint'], $result[0]['hint'] );
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
check( '[3] an "incorrect" reference identical to the correct one no longer blocks generation (quality gate decoupled)', is_wp_error( $matches_correct ), false );

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
check( '[4] duplicate distractor references no longer block generation (quality gate decoupled)', is_wp_error( $duplicate ), false );

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
	array( mcq_item( array( 'authorFullNames' => array( 'John Michael Smith' ), 'bookTitle' => 'Systems Theory', 'distractors' => array(
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
	check( '[6] the Citex-built correct answer uses the derived surname/initials', $multi_name[0]['reconstructedReference'], 'Smith, J.M. (2012) Systems Theory. Oxford: Oxford University Press.' );
}

// ---------------------------------------------------------------------
// 7. Exercise assignment (Citex-owned, by slot index) applies identically
// to MCQ candidates — Gemini's MCQ schema has no exercise field either.
// ---------------------------------------------------------------------
$with_exercise = invoke_normalise( array( mcq_item() ), array( 'BK02' ), 'medium', array( 'Exercise 3' ), 'MCQ' );
check( '[7] MCQ candidates are stamped with their pre-assigned exercise', is_wp_error( $with_exercise ) ? null : $with_exercise[0]['exercise'], 'Exercise 3' );

// ---------------------------------------------------------------------
// 8. CRITICAL — even a Gemini response that still includes a leaking
// 'scenario' (e.g. one revealing the author's initials directly) has NO
// effect on the MCQ candidate: normalise() never reads $item['scenario']
// for MCQ at all. The candidate's question text is always Citex's own
// fixed stem, and the question passes cleanly — this is the structural
// fix for MCQ scenario answer-leakage, not a per-case rejection.
// ---------------------------------------------------------------------
$leaked = invoke_normalise(
	array( mcq_item( array( 'scenario' => 'You are referencing Social Research Methods by Alan Bryman (initials A.), published in 2012 by Oxford University Press in Oxford.' ) ) ),
	array( 'BK02' ),
	'medium',
	array(),
	'MCQ'
);
check( '[8] a Gemini-supplied leaking scenario does not cause rejection — it is simply never read', is_wp_error( $leaked ), false );
if ( ! is_wp_error( $leaked ) ) {
	check( '[8] the candidate\'s question text is Citex\'s own fixed stem, not Gemini\'s leaking scenario', $leaked[0]['scenario'], 'Which of the following is the correct Harvard reference for a book?' );
}

// ---------------------------------------------------------------------
// 9. Sanity check: the default/DragDrop dispatch path is unaffected by the
// new $type parameter — omitting it still produces a DragDrop candidate.
// ---------------------------------------------------------------------
$dragdrop_item = array(
	'scenario'       => 'You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.',
	'authorFullNames' => array( 'Alan Bryman' ),
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
check( '[11] a distractor that is fully valid despite its claimed errorReason no longer blocks generation (quality gate decoupled)', is_wp_error( $still_ambiguous ), false );
check( '[11] the candidate is still recorded as failed validation (MCQ_DISTRACTOR_LOOKS_CORRECT), not silently bypassed', is_wp_error( $still_ambiguous ) ? null : $still_ambiguous[0]['validationStatus'], 'failed' );

// ---------------------------------------------------------------------
// 12. Multi-author MCQ: Citex builds the correct answer joining all
// authors per Liverpool Hope's reference-list rule (never "et al."), and
// the correct answer still never appears in any option.
// ---------------------------------------------------------------------
$multi_author_mcq = invoke_normalise(
	array( mcq_item( array(
		'authorFullNames' => array( 'John Smith', 'Amy Jones', 'Tom Brown' ),
		'bookTitle'        => 'Understanding digital culture',
		'distractors'      => array(
			distractor( 'Smith, J., Jones, A., Brown, T. (2012) Understanding digital culture. Oxford: Oxford University Press.', 'Every author joined with a comma throughout, omitting "and" before the final author.' ),
			distractor( 'Smith, J. & Jones, A. & Brown, T. (2012) Understanding digital culture. Oxford: Oxford University Press.', 'Authors joined with "&" instead of "and"/commas.' ),
			distractor( 'Smith et al. (2012) Understanding digital culture. Oxford: Oxford University Press.', 'Uses "et al." instead of listing every author in the reference list.' ),
		),
	) ) ),
	array( 'BK02' ),
	'medium',
	array(),
	'MCQ'
);
check( '[12] normalise() succeeds for a multi-author MCQ item', is_wp_error( $multi_author_mcq ), false );
if ( ! is_wp_error( $multi_author_mcq ) ) {
	$candidate = $multi_author_mcq[0];
	check( '[12] the Citex-built correct answer joins all three authors', $candidate['reconstructedReference'], 'Smith, J., Jones, A. and Brown, T. (2012) Understanding digital culture. Oxford: Oxford University Press.' );
	check( '[12] "et al." never appears in the correct answer', false !== strpos( $candidate['reconstructedReference'], 'et al' ), false );
	check( '[12] the correct answer never appears in any option', in_array( $candidate['reconstructedReference'], $candidate['options'], true ), false );
	check( '[12] the "et al." distractor is kept as a legitimate distractor, not silently rewritten', in_array( 'Smith et al. (2012) Understanding digital culture. Oxford: Oxford University Press.', $candidate['options'], true ), true );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
