<?php
/**
 * Regression tests for Citex_Generated_Validator's MCQ support, added
 * alongside DragDrop as Citex's second supported question type.
 *
 * MCQ reuses validate_bibliographic_consistency() and
 * validate_answer_leakage() unchanged (both already operate on
 * authorSurname/authorInitials/scenario fields present on any question
 * type, not on DragDrop-specific fixedText/questionParts), plus a new
 * shared validate_reference_format() helper (the same Harvard Book format
 * checks previously inlined in the DragDrop path) applied to whichever of
 * the 4 options is marked correct.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-mcq.test.php` — not shipped in
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

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-generated-validator.php';

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
function has_error_code( $result, $code ) {
	foreach ( $result['errors'] as $error ) {
		if ( $code === $error['code'] ) {
			return true;
		}
	}
	return false;
}

/**
 * The correct reference is 'Bryman, A. (2012) Social Research Methods.
 * Oxford: Oxford University Press.' at options[$correct_index] by default —
 * matching what Citex_AI_V2::normalise_mcq_item() actually produces (Citex
 * builds the correct option itself; Gemini only ever supplies incorrect ones).
 */
function mcq_question( $overrides = array() ) {
	$correct  = 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.';
	$options  = array(
		'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.', // missing comma after surname
		$correct,
		'A. Bryman (2012) Social Research Methods. Oxford: Oxford University Press.', // wrong order
		'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.', // missing space after colon
	);
	return array_merge(
		array(
			'source'                 => 'Harvard',
			'group'                  => 'ReferenceList',
			'category'               => 'Book',
			'type'                   => 'MCQ',
			'authorSurname'          => 'Bryman',
			'authorInitials'         => 'A.',
			'year'                   => '2012',
			'bookTitle'              => 'Social Research Methods',
			'place'                  => 'Oxford',
			'publisher'              => 'Oxford University Press',
			// Citex's own fixed, category-generic MCQ question stem — never a
			// per-book scenario (Gemini is not even asked for one for MCQ
			// any more; see schema_mcq()).
			'scenario'               => 'Which of the following is the correct Harvard reference for a book?',
			'options'                => $options,
			'correctOptionIndex'     => 1,
			'reconstructedReference' => $correct,
			// A non-revealing hint — never names a letter or reproduces the
			// correct reference (see Citex_Reference_Rules::mcq_hint()).
			'hint'                   => 'Check the order of the author\'s surname and initials, the position of the year, and the punctuation between the title, place and publisher.',
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A correct, well-formed MCQ question passes.
// ---------------------------------------------------------------------
$good = Citex_Generated_Validator::validate( mcq_question() );
check( '[1] a correct MCQ question passes', $good['status'], 'passed' );
check( '[1] no errors reported', $good['errors'], array() );
check( '[1] reconstructedReference is the correct option\'s text', $good['reconstructedReference'], 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.' );

// ---------------------------------------------------------------------
// 2. Wrong number of options fails structurally, without crashing on the
// out-of-range correctOptionIndex checks.
// ---------------------------------------------------------------------
$three_options = Citex_Generated_Validator::validate( mcq_question( array( 'options' => array( 'a', 'b', 'c' ) ) ) );
check( '[2] 3 options (not 4) fails', $three_options['status'], 'failed' );
check( '[2] reports MCQ_OPTION_COUNT_MISMATCH', has_error_code( $three_options, 'mcq_option_count_mismatch' ), true );

$five_options = Citex_Generated_Validator::validate( mcq_question( array( 'options' => array( 'a', 'b', 'c', 'd', 'e' ) ) ) );
check( '[2] 5 options (not 4) fails', $five_options['status'], 'failed' );
check( '[2] reports MCQ_OPTION_COUNT_MISMATCH', has_error_code( $five_options, 'mcq_option_count_mismatch' ), true );

// ---------------------------------------------------------------------
// 3. An empty option fails.
// ---------------------------------------------------------------------
$empty_option = Citex_Generated_Validator::validate( mcq_question( array( 'options' => array( '', 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.', 'x', 'y' ) ) ) );
check( '[3] an empty option fails', $empty_option['status'], 'failed' );
check( '[3] reports MCQ_OPTION_EMPTY', has_error_code( $empty_option, 'mcq_option_empty' ), true );

// ---------------------------------------------------------------------
// 4. An out-of-range correctOptionIndex fails safely.
// ---------------------------------------------------------------------
$bad_index = Citex_Generated_Validator::validate( mcq_question( array( 'correctOptionIndex' => 4 ) ) );
check( '[4] correctOptionIndex 4 (out of range 0-3) fails', $bad_index['status'], 'failed' );
check( '[4] reports MCQ_CORRECT_INDEX_INVALID', has_error_code( $bad_index, 'mcq_correct_index_invalid' ), true );

$negative_index = Citex_Generated_Validator::validate( mcq_question( array( 'correctOptionIndex' => -1 ) ) );
check( '[4] a missing/negative correctOptionIndex fails', $negative_index['status'], 'failed' );
check( '[4] reports MCQ_CORRECT_INDEX_INVALID', has_error_code( $negative_index, 'mcq_correct_index_invalid' ), true );

// ---------------------------------------------------------------------
// 5. Duplicate options fail.
// ---------------------------------------------------------------------
$correct = 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.';
$duplicate = Citex_Generated_Validator::validate( mcq_question( array( 'options' => array( $correct, $correct, 'x', 'y' ), 'correctOptionIndex' => 0 ) ) );
check( '[5] a duplicated correct option fails', $duplicate['status'], 'failed' );
check( '[5] reports MCQ_DUPLICATE_OPTION', has_error_code( $duplicate, 'mcq_duplicate_option' ), true );

// ---------------------------------------------------------------------
// 5b. A missing hint fails — it is written into the real "Hint" field on
// population, so a missing one is a structural gap.
// ---------------------------------------------------------------------
$missing_hint = Citex_Generated_Validator::validate( mcq_question( array( 'hint' => '' ) ) );
check( '[5b] a missing hint fails', $missing_hint['status'], 'failed' );
check( '[5b] reports MCQ_HINT_MISSING', has_error_code( $missing_hint, 'mcq_hint_missing' ), true );

// ---------------------------------------------------------------------
// 5f. CRITICAL — a hint that names the correct option fails, even though
// it is otherwise well-formed. This is the direct fix for the reported
// "the hint literally tells the student C is correct" bug.
// ---------------------------------------------------------------------
$revealing_hint = Citex_Generated_Validator::validate( mcq_question( array( 'hint' => 'B is correct because it follows the required Harvard reference structure.' ) ) );
check( '[5f] a hint naming the correct option letter fails', $revealing_hint['status'], 'failed' );
check( '[5f] reports MCQ_HINT_REVEALS_ANSWER', has_error_code( $revealing_hint, 'mcq_hint_reveals_answer' ), true );

$revealing_hint2 = Citex_Generated_Validator::validate( mcq_question( array( 'hint' => 'The answer is the second option, which follows the correct structure.' ) ) );
check( '[5f] a hint saying "the answer is..." fails', $revealing_hint2['status'], 'failed' );
check( '[5f] reports MCQ_HINT_REVEALS_ANSWER for "the answer is"', has_error_code( $revealing_hint2, 'mcq_hint_reveals_answer' ), true );

// ---------------------------------------------------------------------
// 5g. A hint that reproduces the full correct reference text fails, even
// without naming a letter.
// ---------------------------------------------------------------------
$reproducing_hint = Citex_Generated_Validator::validate( mcq_question( array( 'hint' => 'The correct reference reads: Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.' ) ) );
check( '[5g] a hint reproducing the full correct reference fails', $reproducing_hint['status'], 'failed' );
check( '[5g] reports MCQ_HINT_REPRODUCES_ANSWER', has_error_code( $reproducing_hint, 'mcq_hint_reproduces_answer' ), true );

// A safe, generic hint (matching Citex_Reference_Rules::mcq_hint()'s own
// style) must NOT trip either check — proves these are not false positives.
$safe_hint = Citex_Generated_Validator::validate( mcq_question() );
check( '[5g] the default fixture\'s safe, generic hint triggers neither hint-safety check', $safe_hint['status'], 'passed' );

// ---------------------------------------------------------------------
// 5c. A distractor that ALSO happens to pass every Harvard Book format
// rule (different wording, still structurally valid) is a second
// plausible answer and must fail — "avoid situations where two answers
// could reasonably be considered correct."
// ---------------------------------------------------------------------
$two_look_correct = Citex_Generated_Validator::validate(
	mcq_question(
		array(
			'options' => array(
				'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.', // correct
				'Smith, J. (2018) A Different Book. London: A Different Press.', // WRONG book, but structurally well-formed too
				'A. Bryman (2012) Social Research Methods. Oxford: Oxford University Press.', // wrong order — genuinely malformed
				'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.', // missing space — genuinely malformed
			),
			'correctOptionIndex'     => 0,
			'reconstructedReference' => 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.',
		)
	)
);
check( '[5c] a well-formed-but-wrong-book distractor fails (would read as a second correct answer)', $two_look_correct['status'], 'failed' );
check( '[5c] reports MCQ_DISTRACTOR_LOOKS_CORRECT', has_error_code( $two_look_correct, 'mcq_distractor_looks_correct' ), true );

// ---------------------------------------------------------------------
// 5d. CRITICAL — the reported bug (Question BK21: "Option 4 ... passes
// every Harvard format rule too"). A distractor that swaps place and
// publisher (a real, recommended distractor pattern — see
// Citex_Reference_Rules::mcq_distractor_patterns()) keeps the same book,
// author, year and title, so the generic shape regex ("X: Y.") cannot
// tell it apart from a genuinely correct reference on its own. Since
// Citex knows this question's real place/publisher, it must recognise the
// swap directly and NOT treat it as a second plausible answer — the whole
// question must PASS.
// ---------------------------------------------------------------------
$swapped_distractor = Citex_Generated_Validator::validate(
	mcq_question(
		array(
			'options' => array(
				'Bryman, A. (2012) Social Research Methods. Oxford University Press: Oxford.', // place/publisher swapped
				'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.', // correct
				'A. Bryman (2012) Social Research Methods. Oxford: Oxford University Press.', // wrong order — genuinely malformed
				'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.', // missing space — genuinely malformed
			),
			'correctOptionIndex'     => 1,
			'reconstructedReference' => 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.',
		)
	)
);
check( '[5d] a place/publisher-swapped distractor no longer creates false ambiguity — the question PASSES', $swapped_distractor['status'], 'passed' );
check( '[5d] no errors reported', $swapped_distractor['errors'], array() );

// ---------------------------------------------------------------------
// 5e. Sanity check that the new rule actually fires: a reference with
// place and publisher swapped is itself flagged PLACE_PUBLISHER_ORDER_MISMATCH
// when it is the one marked correct (proving 5d passes because the swap is
// genuinely detected, not because the check is a no-op).
// ---------------------------------------------------------------------
$swapped_as_correct = Citex_Generated_Validator::validate(
	mcq_question(
		array(
			'options'                => array( 'x', 'Bryman, A. (2012) Social Research Methods. Oxford University Press: Oxford.', 'y', 'z' ),
			'correctOptionIndex'     => 1,
			'reconstructedReference' => '',
		)
	)
);
check( '[5e] a swapped place/publisher reference fails when marked correct', $swapped_as_correct['status'], 'failed' );
check( '[5e] reports PLACE_PUBLISHER_ORDER_MISMATCH', has_error_code( $swapped_as_correct, 'place_publisher_order_mismatch' ), true );

// ---------------------------------------------------------------------
// 6. The correct option itself must satisfy the Harvard Book format rules
// (reused from DragDrop via validate_reference_format()) — a malformed
// "correct" answer fails exactly like a malformed DragDrop reconstruction.
// ---------------------------------------------------------------------
$bad_format = Citex_Generated_Validator::validate(
	mcq_question(
		array(
			'options'            => array( 'x', 'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.', 'y', 'z' ),
			'correctOptionIndex' => 1,
			'reconstructedReference' => '',
		)
	)
);
check( '[6] a correct option missing the space after the colon FAILS (reused Harvard format check)', $bad_format['status'], 'failed' );
check( '[6] reports MISSING_SPACE_AFTER_COLON', has_error_code( $bad_format, 'missing_space_after_colon' ), true );

// ---------------------------------------------------------------------
// 7. Answer leakage is reused unchanged: a scenario containing the
// abbreviated citation fails exactly like it would for DragDrop.
// ---------------------------------------------------------------------
$leaked = Citex_Generated_Validator::validate(
	mcq_question(
		array(
			'scenario' => 'You are referencing Social Research Methods by Alan Bryman (initials A.), published in 2012 by Oxford University Press in Oxford.',
		)
	)
);
check( '[7] MCQ reuses answer-leakage validation ("(initials A.)") and FAILS', $leaked['status'], 'failed' );
check( '[7] reports ANSWER_LEAKAGE_INITIALS_WORD', has_error_code( $leaked, 'answer_leakage_initials_word' ), true );

// ---------------------------------------------------------------------
// 8. MCQ's question text must be EXACTLY Citex's own fixed stem.
// Bibliographic-consistency's scenario-vs-facts check is now skipped for
// MCQ (its scenario is generic by design, naming no book-specific fact at
// all — see validate_consistency()'s $check_scenario), but a scenario
// other than the exact fixed stem still fails, via the new
// MCQ_QUESTION_STEM_MISMATCH check instead.
// ---------------------------------------------------------------------
$mismatched_scenario = Citex_Generated_Validator::validate(
	mcq_question(
		array(
			'scenario' => 'You are referencing a book by Alan Bryman, published in 2012 by Oxford University Press in Oxford.',
		)
	)
);
check( '[8] a scenario other than the fixed MCQ stem still FAILS', $mismatched_scenario['status'], 'failed' );
check( '[8] reports MCQ_QUESTION_STEM_MISMATCH', has_error_code( $mismatched_scenario, 'mcq_question_stem_mismatch' ), true );
check( '[8] does NOT report BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH — that check is skipped for MCQ', has_error_code( $mismatched_scenario, 'bibliographic_consistency_scenario_mismatch' ), false );

// ---------------------------------------------------------------------
// 8b. The reference-must-contain-the-facts checks (unaffected by
// $check_scenario) still run for MCQ — a correct option that omits a
// canonical fact (here: the book title) still fails bibliographic
// consistency, just via the reference check rather than the (now-skipped)
// scenario one.
// ---------------------------------------------------------------------
$reference_missing_title = Citex_Generated_Validator::validate(
	mcq_question(
		array(
			'options'                => array(
				'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.',
				'Bryman, A. (2012) A Different Title Entirely. Oxford: Oxford University Press.', // correct option, but wrong title
				'A. Bryman (2012) Social Research Methods. Oxford: Oxford University Press.',
				'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.',
			),
			'reconstructedReference' => 'Bryman, A. (2012) A Different Title Entirely. Oxford: Oxford University Press.',
		)
	)
);
check( '[8b] the correct option omitting the canonical book title still FAILS', $reference_missing_title['status'], 'failed' );
check( '[8b] reports BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', has_error_code( $reference_missing_title, 'bibliographic_consistency_reference_mismatch' ), true );

// ---------------------------------------------------------------------
// 9. The generated expected reference (reconstructedReference) must match
// the correct option's own text.
// ---------------------------------------------------------------------
$mismatched_expected = Citex_Generated_Validator::validate( mcq_question( array( 'reconstructedReference' => 'A completely different reference.' ) ) );
check( '[9] a reconstructedReference that does not match the correct option FAILS', $mismatched_expected['status'], 'failed' );
check( '[9] reports RECONSTRUCTED_REFERENCE_MISMATCH', has_error_code( $mismatched_expected, 'reconstructed_reference_mismatch' ), true );

// ---------------------------------------------------------------------
// 10. Unsupported combinations (wrong source/group/category, or an
// unrecognised type) still fail with UNSUPPORTED_GENERATED_FORMAT — MCQ
// support does not loosen this gate for anything else.
// ---------------------------------------------------------------------
$wrong_category = Citex_Generated_Validator::validate( mcq_question( array( 'category' => 'Website' ) ) );
check( '[10] category Website (not Book) still fails UNSUPPORTED_GENERATED_FORMAT', $wrong_category['status'], 'failed' );
check( '[10] reports UNSUPPORTED_GENERATED_FORMAT', has_error_code( $wrong_category, 'unsupported_generated_format' ), true );

$unknown_type = Citex_Generated_Validator::validate( mcq_question( array( 'type' => 'ShortAnswer' ) ) );
check( '[10] an unrecognised type still fails UNSUPPORTED_GENERATED_FORMAT', $unknown_type['status'], 'failed' );
check( '[10] reports UNSUPPORTED_GENERATED_FORMAT', has_error_code( $unknown_type, 'unsupported_generated_format' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
