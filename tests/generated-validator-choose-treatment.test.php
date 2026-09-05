<?php
/**
 * Regression tests for Citex_Generated_Validator::validate_choose_treatment()
 * — the "Choose the correct rule/treatment" MCQ counterpart to
 * validate_mcq(), dispatched on a candidate's `mcqPattern` field.
 * Exercises the validator directly against hand-built candidate fixtures,
 * the same style as generated-validator-identify-error.test.php.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-choose-treatment.test.php` — not shipped
 * in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

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
		if ( $error['code'] === $code ) {
			return true;
		}
	}
	return false;
}

function treatment_question( $overrides = array() ) {
	return array_merge(
		array(
			'source'          => 'Harvard',
			'group'           => 'ReferenceList',
			'category'        => 'Book',
			'type'            => 'MCQ',
			'mcqPattern'      => 'choose_treatment',
			'treatmentBucket' => 'four_or_more_authors',
			'scenario'        => 'Which statement is correct about a book with four or more authors in the Harvard reference list?',
			'options'         => array(
				'The first author is listed and the rest are shortened to et al.',
				'Only the first three authors are listed; the rest are omitted.',
				'Authors are joined with an ampersand (&) instead of commas and "and".',
				'',
			),
			'hint'            => 'Think about how the joining of multiple author names changes (or doesn\'t change) as the author count grows.',
			'reconstructedReference' => 'All authors should be included; et al. is not used in the reference list.',
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A fully correct choose_treatment question PASSES — the exact "et al."
// misconception test from the user's own worked example.
// ---------------------------------------------------------------------
$result = Citex_Generated_Validator::validate( treatment_question() );
check( '[1] a correct choose_treatment question passes', $result['status'], 'passed' );
check( '[1] no errors reported', $result['errors'], array() );
check( '[1] the reconstructed value returned is the true statement', $result['reconstructedReference'], 'All authors should be included; et al. is not used in the reference list.' );

// ---------------------------------------------------------------------
// 2. Not exactly 4 options fails.
// ---------------------------------------------------------------------
$bad_count = Citex_Generated_Validator::validate( treatment_question( array( 'options' => array( 'a', 'b', 'c' ) ) ) );
check( '[2] not exactly 4 options fails', $bad_count['status'], 'failed' );
check( '[2] reports MCQ_OPTION_COUNT_MISMATCH', has_error_code( $bad_count, 'mcq_option_count_mismatch' ), true );

// ---------------------------------------------------------------------
// 3. An empty option among the first 3 fails.
// ---------------------------------------------------------------------
$empty_option = Citex_Generated_Validator::validate( treatment_question( array( 'options' => array( '', 'x', 'y', '' ) ) ) );
check( '[3] an empty option among the first 3 fails', $empty_option['status'], 'failed' );
check( '[3] reports MCQ_OPTION_EMPTY', has_error_code( $empty_option, 'mcq_option_empty' ), true );

// ---------------------------------------------------------------------
// 4. Option 4 not blank fails.
// ---------------------------------------------------------------------
$fourth_not_blank = Citex_Generated_Validator::validate( treatment_question( array( 'options' => array( 'x', 'y', 'z', 'not blank' ) ) ) );
check( '[4] option 4 not blank fails', $fourth_not_blank['status'], 'failed' );
check( '[4] reports MCQ_FOURTH_OPTION_NOT_BLANK', has_error_code( $fourth_not_blank, 'mcq_fourth_option_not_blank' ), true );

// ---------------------------------------------------------------------
// 5. Duplicated options fail.
// ---------------------------------------------------------------------
$duplicate_options = Citex_Generated_Validator::validate( treatment_question( array( 'options' => array( 'x', 'x', 'y', '' ) ) ) );
check( '[5] duplicated options fail', $duplicate_options['status'], 'failed' );
check( '[5] reports MCQ_DUPLICATE_OPTION', has_error_code( $duplicate_options, 'mcq_duplicate_option' ), true );

// ---------------------------------------------------------------------
// 6. A missing Answer (reconstructedReference) fails.
// ---------------------------------------------------------------------
$missing_answer = Citex_Generated_Validator::validate( treatment_question( array( 'reconstructedReference' => '' ) ) );
check( '[6] a missing answer fails', $missing_answer['status'], 'failed' );
check( '[6] reports MCQ_ANSWER_MISSING', has_error_code( $missing_answer, 'mcq_answer_missing' ), true );

// ---------------------------------------------------------------------
// 7. An option that duplicates the true statement fails.
// ---------------------------------------------------------------------
$option_matches_answer = Citex_Generated_Validator::validate( treatment_question( array( 'options' => array(
	'All authors should be included; et al. is not used in the reference list.',
	'y', 'z', '',
) ) ) );
check( '[7] an option matching the answer fails', $option_matches_answer['status'], 'failed' );
check( '[7] reports MCQ_OPTION_MATCHES_ANSWER', has_error_code( $option_matches_answer, 'mcq_option_matches_answer' ), true );

// ---------------------------------------------------------------------
// 8. CRITICAL — an unrecognised treatmentBucket fails.
// ---------------------------------------------------------------------
$unknown_bucket = Citex_Generated_Validator::validate( treatment_question( array( 'treatmentBucket' => 'nonexistent_bucket' ) ) );
check( '[8] an unrecognised treatmentBucket fails', $unknown_bucket['status'], 'failed' );
check( '[8] reports TREATMENT_BUCKET_UNKNOWN', has_error_code( $unknown_bucket, 'treatment_bucket_unknown' ), true );

// ---------------------------------------------------------------------
// 9. CRITICAL — a scenario that does not exactly match Citex's own fixed
// stem for this bucket fails — defense in depth against a stray value
// slipping through from some other path (the same principle as
// MCQ_QUESTION_STEM_MISMATCH for select_correct).
// ---------------------------------------------------------------------
$wrong_stem = Citex_Generated_Validator::validate( treatment_question( array( 'scenario' => 'Some other question text entirely.' ) ) );
check( '[9] a mismatched scenario fails', $wrong_stem['status'], 'failed' );
check( '[9] reports TREATMENT_STEM_MISMATCH', has_error_code( $wrong_stem, 'treatment_stem_mismatch' ), true );

// ---------------------------------------------------------------------
// 10. CRITICAL — an answer that is not EXACTLY Citex's own fixed correct
// statement for this bucket fails, even if it sounds plausible — Citex is
// the sole authority for the true statement's exact wording.
// ---------------------------------------------------------------------
$wrong_answer = Citex_Generated_Validator::validate( treatment_question( array( 'reconstructedReference' => 'All authors are always included in the reference list.' ) ) );
check( '[10] an answer that is not exactly the true statement fails', $wrong_answer['status'], 'failed' );
check( '[10] reports TREATMENT_ANSWER_MISMATCH', has_error_code( $wrong_answer, 'treatment_answer_mismatch' ), true );

// ---------------------------------------------------------------------
// 11. Missing hint fails.
// ---------------------------------------------------------------------
$missing_hint = Citex_Generated_Validator::validate( treatment_question( array( 'hint' => '' ) ) );
check( '[11] a missing hint fails', $missing_hint['status'], 'failed' );
check( '[11] reports MCQ_HINT_MISSING', has_error_code( $missing_hint, 'mcq_hint_missing' ), true );

// ---------------------------------------------------------------------
// 12. A hint that reproduces the true statement fails.
// ---------------------------------------------------------------------
$hint_reveals = Citex_Generated_Validator::validate( treatment_question( array(
	'hint' => 'The answer is: All authors should be included; et al. is not used in the reference list.',
) ) );
check( '[12] a hint reproducing the answer fails', $hint_reveals['status'], 'failed' );
check( '[12] reports MCQ_HINT_REPRODUCES_ANSWER or MCQ_HINT_REVEALS_ANSWER', has_error_code( $hint_reveals, 'mcq_hint_reproduces_answer' ) || has_error_code( $hint_reveals, 'mcq_hint_reveals_answer' ), true );

// ---------------------------------------------------------------------
// 13. Edited Book buckets work identically — two_editors and
// three_or_more_editors, using the designation/joining rule statements.
// ---------------------------------------------------------------------
function edited_treatment_question( $overrides = array() ) {
	return array_merge(
		array(
			'source'          => 'Harvard',
			'group'           => 'ReferenceList',
			'category'        => 'Edited Book',
			'type'            => 'MCQ',
			'mcqPattern'      => 'choose_treatment',
			'treatmentBucket' => 'two_editors',
			'scenario'        => 'Which of the following statements is correct about referencing a book edited by two people in the Harvard reference list?',
			'options'         => array(
				'Both editors are joined with an ampersand and the designation is "(ed.)".',
				'Only the first editor is listed, followed by "(eds)".',
				'The editors are joined with a comma only, with no designation at all.',
				'',
			),
			'hint'            => 'Think about how the editor designation changes as the editor count grows.',
			'reconstructedReference' => 'Both editors are included, joined by "and", followed by the designation "(eds)" — e.g. Smith, J. and Jones, A. (eds).',
		),
		$overrides
	);
}
$eb_result = Citex_Generated_Validator::validate( edited_treatment_question() );
check( '[13] Edited Book two_editors: a correct question passes', $eb_result['status'], 'passed' );
check( '[13] no errors reported', $eb_result['errors'], array() );

$eb_three_or_more = Citex_Generated_Validator::validate( edited_treatment_question( array(
	'treatmentBucket' => 'three_or_more_editors',
	'scenario'         => 'Which of the following statements is correct about referencing a book edited by three or more people in the Harvard reference list?',
	'reconstructedReference' => 'All editors are included, separated by commas with "and" before the final editor, followed by the designation "(eds)" — e.g. Smith, J., Jones, A. and Brown, T. (eds).',
) ) );
check( '[13] Edited Book three_or_more_editors: a correct question passes', $eb_three_or_more['status'], 'passed' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
