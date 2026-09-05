<?php
/**
 * Regression tests for Citex_Generated_Validator::validate_book_mcq_variant()
 * — the Book MCQ counterpart to validate_mcq(), dispatched on a candidate's
 * `mcqPattern` field, backing the user's own fixed 16-variant catalogue
 * (Citex_Book_Mcq_Variants). Exercises the validator directly against
 * hand-built candidate fixtures, the same style as
 * generated-validator-choose-treatment.test.php.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-book-mcq-variant.test.php` — not shipped
 * in citex-tools.zip.
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

// The 'complete_reference' variant (Variant 1 in the user's spec) against a
// single-author record — deterministic, so the fixture's stem/options/answer
// are computed directly from Citex_Book_Mcq_Variants::build() itself rather
// than hand-typed, keeping the fixture perpetually in sync with the builder.
$fields = array(
	'authors'   => array( array( 'surname' => 'Brown', 'initials' => 'A.', 'fullName' => 'Andrew Brown' ) ),
	'year'      => '2021',
	'title'     => 'Digital Culture',
	'place'     => 'London',
	'publisher' => 'Routledge',
);
$built = Citex_Book_Mcq_Variants::build( 'complete_reference', $fields );

function book_variant_question( $built, $fields, $overrides = array() ) {
	return array_merge(
		array(
			'source'                 => 'Harvard',
			'group'                  => 'ReferenceList',
			'category'               => 'Book',
			'type'                   => 'MCQ',
			'mcqPattern'             => 'book_mcq_variant',
			'bookMcqVariant'         => 'complete_reference',
			'scenario'               => $built['stem'],
			'options'                => array( $built['wrongOptions'][0], $built['wrongOptions'][1], $built['wrongOptions'][2], '' ),
			'hint'                   => 'Think carefully about how each part of the reference should be formatted.',
			'reconstructedReference' => $built['correctAnswer'],
			'authors'                => $fields['authors'],
			'year'                   => $fields['year'],
			'bookTitle'              => $fields['title'],
			'place'                  => $fields['place'],
			'publisher'              => $fields['publisher'],
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A correctly-built variant question PASSES.
// ---------------------------------------------------------------------
$result = Citex_Generated_Validator::validate( book_variant_question( $built, $fields ) );
check( '[1] a correctly-built book_mcq_variant question passes', $result['status'], 'passed' );
check( '[1] no errors reported', $result['errors'], array() );
check( '[1] the reconstructed value returned is the correct answer', $result['reconstructedReference'], $built['correctAnswer'] );

// ---------------------------------------------------------------------
// 2. Not exactly 4 options fails.
// ---------------------------------------------------------------------
$bad_count = Citex_Generated_Validator::validate( book_variant_question( $built, $fields, array( 'options' => array( 'a', 'b', 'c' ) ) ) );
check( '[2] not exactly 4 options fails', $bad_count['status'], 'failed' );
check( '[2] reports MCQ_OPTION_COUNT_MISMATCH', has_error_code( $bad_count, 'mcq_option_count_mismatch' ), true );

// ---------------------------------------------------------------------
// 3. An empty option among the first 3 fails.
// ---------------------------------------------------------------------
$empty_option = Citex_Generated_Validator::validate( book_variant_question( $built, $fields, array( 'options' => array( '', 'x', 'y', '' ) ) ) );
check( '[3] an empty option among the first 3 fails', $empty_option['status'], 'failed' );
check( '[3] reports MCQ_OPTION_EMPTY', has_error_code( $empty_option, 'mcq_option_empty' ), true );

// ---------------------------------------------------------------------
// 4. Option 4 not blank fails.
// ---------------------------------------------------------------------
$fourth_not_blank = Citex_Generated_Validator::validate( book_variant_question( $built, $fields, array( 'options' => array( 'x', 'y', 'z', 'not blank' ) ) ) );
check( '[4] option 4 not blank fails', $fourth_not_blank['status'], 'failed' );
check( '[4] reports MCQ_FOURTH_OPTION_NOT_BLANK', has_error_code( $fourth_not_blank, 'mcq_fourth_option_not_blank' ), true );

// ---------------------------------------------------------------------
// 5. Duplicated options fail.
// ---------------------------------------------------------------------
$duplicate_options = Citex_Generated_Validator::validate( book_variant_question( $built, $fields, array( 'options' => array( 'x', 'x', 'y', '' ) ) ) );
check( '[5] duplicated options fail', $duplicate_options['status'], 'failed' );
check( '[5] reports MCQ_DUPLICATE_OPTION', has_error_code( $duplicate_options, 'mcq_duplicate_option' ), true );

// ---------------------------------------------------------------------
// 6. A missing Answer (reconstructedReference) fails.
// ---------------------------------------------------------------------
$missing_answer = Citex_Generated_Validator::validate( book_variant_question( $built, $fields, array( 'reconstructedReference' => '' ) ) );
check( '[6] a missing answer fails', $missing_answer['status'], 'failed' );
check( '[6] reports MCQ_ANSWER_MISSING', has_error_code( $missing_answer, 'mcq_answer_missing' ), true );

// ---------------------------------------------------------------------
// 7. An option that duplicates the correct answer fails.
// ---------------------------------------------------------------------
$option_matches_answer = Citex_Generated_Validator::validate( book_variant_question( $built, $fields, array(
	'options' => array( $built['correctAnswer'], 'y', 'z', '' ),
) ) );
check( '[7] an option matching the answer fails', $option_matches_answer['status'], 'failed' );
check( '[7] reports MCQ_OPTION_MATCHES_ANSWER', has_error_code( $option_matches_answer, 'mcq_option_matches_answer' ), true );

// ---------------------------------------------------------------------
// 8. CRITICAL — an unrecognised bookMcqVariant fails.
// ---------------------------------------------------------------------
$unknown_variant = Citex_Generated_Validator::validate( book_variant_question( $built, $fields, array( 'bookMcqVariant' => 'nonexistent_variant' ) ) );
check( '[8] an unrecognised bookMcqVariant fails', $unknown_variant['status'], 'failed' );
check( '[8] reports BOOK_MCQ_VARIANT_UNKNOWN', has_error_code( $unknown_variant, 'book_mcq_variant_unknown' ), true );

// ---------------------------------------------------------------------
// 9. CRITICAL — a scenario that does not exactly match the variant's own
// recomputed stem fails, even though the variant id itself is valid.
// ---------------------------------------------------------------------
$wrong_stem = Citex_Generated_Validator::validate( book_variant_question( $built, $fields, array( 'scenario' => 'Some other question text entirely.' ) ) );
check( '[9] a mismatched scenario fails', $wrong_stem['status'], 'failed' );
check( '[9] reports BOOK_MCQ_VARIANT_STEM_MISMATCH', has_error_code( $wrong_stem, 'book_mcq_variant_stem_mismatch' ), true );

// ---------------------------------------------------------------------
// 10. CRITICAL — an answer that is not EXACTLY the variant's own recomputed
// correct answer fails, even if it sounds plausible.
// ---------------------------------------------------------------------
$wrong_answer = Citex_Generated_Validator::validate( book_variant_question( $built, $fields, array(
	'reconstructedReference' => 'Brown, A. (2021) Digital Culture. Oxford: Routledge.',
) ) );
check( '[10] an answer that is not exactly the variant\'s own answer fails', $wrong_answer['status'], 'failed' );
check( '[10] reports BOOK_MCQ_VARIANT_ANSWER_MISMATCH', has_error_code( $wrong_answer, 'book_mcq_variant_answer_mismatch' ), true );

// ---------------------------------------------------------------------
// 11. CRITICAL — an option that does not exactly match the variant's own
// recomputed wrong option fails.
// ---------------------------------------------------------------------
$wrong_option = Citex_Generated_Validator::validate( book_variant_question( $built, $fields, array(
	'options' => array( 'Brown, A. (2021) Something Else Entirely. London: Routledge.', $built['wrongOptions'][1], $built['wrongOptions'][2], '' ),
) ) );
check( '[11] a tampered option fails', $wrong_option['status'], 'failed' );
check( '[11] reports BOOK_MCQ_VARIANT_OPTION_MISMATCH', has_error_code( $wrong_option, 'book_mcq_variant_option_mismatch' ), true );

// ---------------------------------------------------------------------
// 12. Missing hint fails.
// ---------------------------------------------------------------------
$missing_hint = Citex_Generated_Validator::validate( book_variant_question( $built, $fields, array( 'hint' => '' ) ) );
check( '[12] a missing hint fails', $missing_hint['status'], 'failed' );
check( '[12] reports MCQ_HINT_MISSING', has_error_code( $missing_hint, 'mcq_hint_missing' ), true );

// ---------------------------------------------------------------------
// 13. A hint that reproduces the correct answer fails.
// ---------------------------------------------------------------------
$hint_reveals = Citex_Generated_Validator::validate( book_variant_question( $built, $fields, array(
	'hint' => 'The answer is: ' . $built['correctAnswer'],
) ) );
check( '[13] a hint reproducing the answer fails', $hint_reveals['status'], 'failed' );
check( '[13] reports MCQ_HINT_REPRODUCES_ANSWER or MCQ_HINT_REVEALS_ANSWER', has_error_code( $hint_reveals, 'mcq_hint_reproduces_answer' ) || has_error_code( $hint_reveals, 'mcq_hint_reveals_answer' ), true );

// ---------------------------------------------------------------------
// 14. A different variant (two_authors, two-author record) also passes —
// proves the validator is not hardcoded to a single variant id.
// ---------------------------------------------------------------------
$two_author_fields = array(
	'authors'   => array(
		array( 'surname' => 'Brown', 'initials' => 'A.', 'fullName' => 'Andrew Brown' ),
		array( 'surname' => 'Smith', 'initials' => 'J.', 'fullName' => 'James Smith' ),
	),
	'year'      => '2021',
	'title'     => 'Digital Culture',
	'place'     => 'London',
	'publisher' => 'Routledge',
);
$two_author_built = Citex_Book_Mcq_Variants::build( 'two_authors', $two_author_fields );
$two_author_result = Citex_Generated_Validator::validate( book_variant_question( $two_author_built, $two_author_fields, array( 'bookMcqVariant' => 'two_authors' ) ) );
check( '[14] a correctly-built two_authors variant question passes', $two_author_result['status'], 'passed' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
