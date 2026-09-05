<?php
/**
 * Regression tests for Citex_Generated_Validator::validate_identify_error()
 * — the "Identify the error" MCQ counterpart to validate_mcq(), dispatched
 * on a candidate's `mcqPattern` field. Exercises the validator directly
 * against hand-built candidate fixtures (not via Citex_AI_V2) so every
 * branch can be hit precisely, the same style as
 * generated-validator-mcq.test.php.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-identify-error.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

function sanitize_key( $v ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
}

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-mcq-variants.php';
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

function book_identify_error_question( $overrides = array() ) {
	return array_merge(
		array(
			'source'    => 'Harvard',
			'group'     => 'ReferenceList',
			'category'  => 'Book',
			'type'      => 'MCQ',
			'mcqPattern' => 'identify_error',
			'scenario'  => "What is incorrect about the following Harvard reference?\n\nBryman A. (2012) Social Research Methods. Oxford: Oxford University Press.",
			'authors'   => array( array( 'fullName' => 'Alan Bryman', 'surname' => 'Bryman', 'initials' => 'A.' ) ),
			'year'      => '2012',
			'bookTitle' => 'Social Research Methods',
			'place'     => 'Oxford',
			'publisher' => 'Oxford University Press',
			'brokenReference' => 'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.',
			'options'   => array(
				'The publication year is not enclosed in parentheses.',
				'The place of publication and publisher have been swapped.',
				'The book title is missing its final full stop.',
				'',
			),
			'hint'      => 'Work through the reference rule by rule: the order of surname and initials, and the punctuation between title, place and publisher.',
			'reconstructedReference' => 'Uses the author\'s full first name instead of initials, and is missing the comma after the surname.',
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A fully correct identify_error question PASSES.
// ---------------------------------------------------------------------
$result = Citex_Generated_Validator::validate( book_identify_error_question() );
check( '[1] a correct identify_error question passes', $result['status'], 'passed' );
check( '[1] no errors reported', $result['errors'], array() );
check( '[1] the reconstructed value returned is the true description', $result['reconstructedReference'], 'Uses the author\'s full first name instead of initials, and is missing the comma after the surname.' );

// ---------------------------------------------------------------------
// 2. Not exactly 4 options fails immediately.
// ---------------------------------------------------------------------
$bad_count = Citex_Generated_Validator::validate( book_identify_error_question( array( 'options' => array( 'a', 'b', 'c' ) ) ) );
check( '[2] not exactly 4 options fails', $bad_count['status'], 'failed' );
check( '[2] reports MCQ_OPTION_COUNT_MISMATCH', has_error_code( $bad_count, 'mcq_option_count_mismatch' ), true );

// ---------------------------------------------------------------------
// 3. An empty option among the first 3 fails.
// ---------------------------------------------------------------------
$empty_option = Citex_Generated_Validator::validate( book_identify_error_question( array( 'options' => array( '', 'x', 'y', '' ) ) ) );
check( '[3] an empty option among the first 3 fails', $empty_option['status'], 'failed' );
check( '[3] reports MCQ_OPTION_EMPTY', has_error_code( $empty_option, 'mcq_option_empty' ), true );

// ---------------------------------------------------------------------
// 4. Option 4 not blank fails.
// ---------------------------------------------------------------------
$fourth_not_blank = Citex_Generated_Validator::validate( book_identify_error_question( array( 'options' => array( 'x', 'y', 'z', 'not blank' ) ) ) );
check( '[4] option 4 not blank fails', $fourth_not_blank['status'], 'failed' );
check( '[4] reports MCQ_FOURTH_OPTION_NOT_BLANK', has_error_code( $fourth_not_blank, 'mcq_fourth_option_not_blank' ), true );

// ---------------------------------------------------------------------
// 5. Two identical options fail as a duplicate.
// ---------------------------------------------------------------------
$duplicate_options = Citex_Generated_Validator::validate( book_identify_error_question( array( 'options' => array( 'x', 'x', 'y', '' ) ) ) );
check( '[5] duplicated options fail', $duplicate_options['status'], 'failed' );
check( '[5] reports MCQ_DUPLICATE_OPTION', has_error_code( $duplicate_options, 'mcq_duplicate_option' ), true );

// ---------------------------------------------------------------------
// 6. A missing Answer (reconstructedReference) fails.
// ---------------------------------------------------------------------
$missing_answer = Citex_Generated_Validator::validate( book_identify_error_question( array( 'reconstructedReference' => '' ) ) );
check( '[6] a missing answer fails', $missing_answer['status'], 'failed' );
check( '[6] reports MCQ_ANSWER_MISSING', has_error_code( $missing_answer, 'mcq_answer_missing' ), true );

// ---------------------------------------------------------------------
// 7. An option that duplicates the true description (answer) fails.
// ---------------------------------------------------------------------
$option_matches_answer = Citex_Generated_Validator::validate( book_identify_error_question( array( 'options' => array(
	'Uses the author\'s full first name instead of initials, and is missing the comma after the surname.',
	'y', 'z', '',
) ) ) );
check( '[7] an option matching the answer fails', $option_matches_answer['status'], 'failed' );
check( '[7] reports MCQ_OPTION_MATCHES_ANSWER', has_error_code( $option_matches_answer, 'mcq_option_matches_answer' ), true );

// ---------------------------------------------------------------------
// 8. A missing brokenReference fails.
// ---------------------------------------------------------------------
$missing_broken = Citex_Generated_Validator::validate( book_identify_error_question( array( 'brokenReference' => '' ) ) );
check( '[8] a missing brokenReference fails', $missing_broken['status'], 'failed' );
check( '[8] reports IDENTIFY_ERROR_BROKEN_REFERENCE_MISSING', has_error_code( $missing_broken, 'identify_error_broken_reference_missing' ), true );

// ---------------------------------------------------------------------
// 9. CRITICAL — a brokenReference that is actually a fully valid Harvard
// reference fails: the whole point of this question type is a genuinely
// wrong reference.
// ---------------------------------------------------------------------
$not_broken = Citex_Generated_Validator::validate( book_identify_error_question( array(
	'brokenReference' => 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.',
	'scenario'         => "What is incorrect about the following Harvard reference?\n\nBryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.",
) ) );
check( '[9] a fully valid "broken" reference fails', $not_broken['status'], 'failed' );
check( '[9] reports IDENTIFY_ERROR_REFERENCE_NOT_BROKEN', has_error_code( $not_broken, 'identify_error_reference_not_broken' ), true );

// ---------------------------------------------------------------------
// 10. A brokenReference missing a canonical fact (e.g. the wrong title)
// fails — the shown reference must still describe the SAME book, only
// with the one deliberate formatting mistake.
// ---------------------------------------------------------------------
$wrong_title = Citex_Generated_Validator::validate( book_identify_error_question( array(
	'brokenReference' => 'Bryman A. (2012) A Totally Different Title. Oxford: Oxford University Press.',
) ) );
check( '[10] a brokenReference with a substituted fact fails', $wrong_title['status'], 'failed' );
check( '[10] reports IDENTIFY_ERROR_REFERENCE_MISMATCH', has_error_code( $wrong_title, 'identify_error_reference_mismatch' ), true );

// ---------------------------------------------------------------------
// 11. A brokenReference genuinely broken via the place/publisher-swap
// pattern specifically (the one Harvard mistake the generic shape regex
// alone cannot see — this is exactly why validate_reference_format() is
// called with the full place/publisher/designation/editor-join params
// here, not just the category) is correctly recognised as broken and
// passes overall.
// ---------------------------------------------------------------------
$place_publisher_swap = Citex_Generated_Validator::validate( book_identify_error_question( array(
	'brokenReference' => 'Bryman, A. (2012) Social Research Methods. Oxford University Press: Oxford.',
	'scenario'         => "What is incorrect about the following Harvard reference?\n\nBryman, A. (2012) Social Research Methods. Oxford University Press: Oxford.",
	'reconstructedReference' => 'The place of publication and publisher have been swapped.',
	'options' => array(
		'The publication year is not enclosed in parentheses.',
		'The author\'s initials come before the surname.',
		'The book title is missing its final full stop.',
		'',
	),
) ) );
check( '[11] a place/publisher-swap brokenReference is recognised as genuinely broken', $place_publisher_swap['status'], 'passed' );
check( '[11] no errors reported', $place_publisher_swap['errors'], array() );

// ---------------------------------------------------------------------
// 12. Missing hint fails.
// ---------------------------------------------------------------------
$missing_hint = Citex_Generated_Validator::validate( book_identify_error_question( array( 'hint' => '' ) ) );
check( '[12] a missing hint fails', $missing_hint['status'], 'failed' );
check( '[12] reports MCQ_HINT_MISSING', has_error_code( $missing_hint, 'mcq_hint_missing' ), true );

// ---------------------------------------------------------------------
// 13. A hint that reproduces the true description fails (reuses
// validate_mcq_hint_safety() unchanged).
// ---------------------------------------------------------------------
$hint_reveals = Citex_Generated_Validator::validate( book_identify_error_question( array(
	'hint' => 'The answer is: Uses the author\'s full first name instead of initials, and is missing the comma after the surname.',
) ) );
check( '[13] a hint reproducing the answer fails', $hint_reveals['status'], 'failed' );
check( '[13] reports MCQ_HINT_REPRODUCES_ANSWER or MCQ_HINT_REVEALS_ANSWER', has_error_code( $hint_reveals, 'mcq_hint_reproduces_answer' ) || has_error_code( $hint_reveals, 'mcq_hint_reveals_answer' ), true );

// ---------------------------------------------------------------------
// 14. Edited Book identify_error: designation-mismatch is correctly
// recognised as a genuinely broken reference (uses expected_designation_for(),
// which reads $question['editors']).
// ---------------------------------------------------------------------
function edited_book_identify_error_question( $overrides = array() ) {
	return array_merge(
		array(
			'source'    => 'Harvard',
			'group'     => 'ReferenceList',
			'category'  => 'Edited Book',
			'type'      => 'MCQ',
			'mcqPattern' => 'identify_error',
			'scenario'  => "What is incorrect about the following Harvard reference?\n\nMiller, V. (2020) Understanding digital culture. London: SAGE Publications.",
			'editors'   => array( array( 'fullName' => 'Vincent Miller', 'surname' => 'Miller', 'initials' => 'V.' ) ),
			'year'      => '2020',
			'bookTitle' => 'Understanding digital culture',
			'place'     => 'London',
			'publisher' => 'SAGE Publications',
			'brokenReference' => 'Miller, V. (2020) Understanding digital culture. London: SAGE Publications.',
			'options'   => array(
				'The publication year is not enclosed in parentheses.',
				'The place of publication and publisher have been swapped.',
				'The book title is missing its final full stop.',
				'',
			),
			'hint'      => 'Work through the reference rule by rule: the editor designation and whether it matches the editor count.',
			'reconstructedReference' => 'Missing the editor designation (ed.) entirely.',
		),
		$overrides
	);
}
$eb_result = Citex_Generated_Validator::validate( edited_book_identify_error_question() );
check( '[14] Edited Book: missing designation is recognised as genuinely broken', $eb_result['status'], 'passed' );
check( '[14] no errors reported', $eb_result['errors'], array() );

// A designation that ACTUALLY matches the editor count (i.e. not broken
// the way claimed) fails.
$eb_not_broken = Citex_Generated_Validator::validate( edited_book_identify_error_question( array(
	'brokenReference' => 'Miller, V. (ed.) (2020) Understanding digital culture. London: SAGE Publications.',
	'scenario'         => "What is incorrect about the following Harvard reference?\n\nMiller, V. (ed.) (2020) Understanding digital culture. London: SAGE Publications.",
) ) );
check( '[14] Edited Book: a fully correct reference fails as "not broken"', $eb_not_broken['status'], 'failed' );
check( '[14] reports IDENTIFY_ERROR_REFERENCE_NOT_BROKEN', has_error_code( $eb_not_broken, 'identify_error_reference_not_broken' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
