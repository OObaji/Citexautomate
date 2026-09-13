<?php
/**
 * Regression tests for Citex_Generated_Validator::validate_website_mcq_variant()
 * — the Website MCQ counterpart to validate_mcq(), dispatched on a
 * candidate's `mcqPattern` field, backing Citex_Website_Mcq_Variants
 * (mirrors Book's own identical move — see
 * generated-validator-book-mcq-variant.test.php). Exercises the validator
 * directly against hand-built candidate fixtures.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-website-mcq-variant.test.php` — not
 * shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

function sanitize_key( $v ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
}

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-website-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-website-dragdrop-parts.php';
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

// The 'complete_reference' variant against an individual-author record —
// deterministic, so the fixture's stem/options/answer are computed
// directly from Citex_Website_Mcq_Variants::build() itself rather than
// hand-typed, keeping the fixture perpetually in sync with the builder.
$individual = array( 'type' => 'individual', 'surname' => 'Ross', 'initials' => 'T.', 'fullName' => 'Tom Ross' );
$fields = array(
	'author'       => $individual,
	'year'         => '2022',
	'title'        => 'Digital skills',
	'publisher'    => 'SAGE',
	'url'          => 'https://www.sage.com',
	'accessedDate' => '12 September 2026',
);
$built = Citex_Website_Mcq_Variants::build( 'complete_reference', $fields );

function website_variant_question( $built, $fields, $overrides = array() ) {
	$author = $fields['author'];
	return array_merge(
		array(
			'source'                 => 'Harvard',
			'group'                  => 'ReferenceList',
			'category'               => 'Website',
			'type'                   => 'MCQ',
			'mcqPattern'             => 'website_mcq_variant',
			'websiteMcqVariant'      => 'complete_reference',
			'scenario'               => $built['stem'],
			'options'                => array( $built['wrongOptions'][0], $built['wrongOptions'][1], $built['wrongOptions'][2], '' ),
			'hint'                   => 'Think carefully about how each part of the reference should be formatted.',
			'reconstructedReference' => $built['correctAnswer'],
			'authorType'             => $author['type'],
			'authors'                => 'individual' === $author['type'] ? array( $author ) : array(),
			'organisationName'       => 'organisation' === $author['type'] ? $author['name'] : '',
			'year'                   => $fields['year'],
			'pageTitle'              => $fields['title'],
			'publisher'              => $fields['publisher'],
			'url'                    => $fields['url'],
			'accessedDate'           => $fields['accessedDate'],
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A correctly-built variant question PASSES.
// ---------------------------------------------------------------------
$result = Citex_Generated_Validator::validate( website_variant_question( $built, $fields ) );
check( '[1] a correctly-built website_mcq_variant question passes', $result['status'], 'passed' );
check( '[1] no errors reported', $result['errors'], array() );
check( '[1] the reconstructed value returned is the correct answer', $result['reconstructedReference'], $built['correctAnswer'] );

// ---------------------------------------------------------------------
// 2. Not exactly 4 options fails.
// ---------------------------------------------------------------------
$bad_count = Citex_Generated_Validator::validate( website_variant_question( $built, $fields, array( 'options' => array( 'a', 'b', 'c' ) ) ) );
check( '[2] not exactly 4 options fails', $bad_count['status'], 'failed' );
check( '[2] reports MCQ_OPTION_COUNT_MISMATCH', has_error_code( $bad_count, 'mcq_option_count_mismatch' ), true );

// ---------------------------------------------------------------------
// 3. An empty option among the first 3 fails.
// ---------------------------------------------------------------------
$empty_option = Citex_Generated_Validator::validate( website_variant_question( $built, $fields, array( 'options' => array( '', 'x', 'y', '' ) ) ) );
check( '[3] an empty option among the first 3 fails', $empty_option['status'], 'failed' );
check( '[3] reports MCQ_OPTION_EMPTY', has_error_code( $empty_option, 'mcq_option_empty' ), true );

// ---------------------------------------------------------------------
// 4. Option 4 not blank fails.
// ---------------------------------------------------------------------
$fourth_not_blank = Citex_Generated_Validator::validate( website_variant_question( $built, $fields, array( 'options' => array( 'x', 'y', 'z', 'not blank' ) ) ) );
check( '[4] option 4 not blank fails', $fourth_not_blank['status'], 'failed' );
check( '[4] reports MCQ_FOURTH_OPTION_NOT_BLANK', has_error_code( $fourth_not_blank, 'mcq_fourth_option_not_blank' ), true );

// ---------------------------------------------------------------------
// 5. Duplicated options fail.
// ---------------------------------------------------------------------
$duplicate_options = Citex_Generated_Validator::validate( website_variant_question( $built, $fields, array( 'options' => array( 'x', 'x', 'y', '' ) ) ) );
check( '[5] duplicated options fail', $duplicate_options['status'], 'failed' );
check( '[5] reports MCQ_DUPLICATE_OPTION', has_error_code( $duplicate_options, 'mcq_duplicate_option' ), true );

// ---------------------------------------------------------------------
// 6. A missing Answer (reconstructedReference) fails.
// ---------------------------------------------------------------------
$missing_answer = Citex_Generated_Validator::validate( website_variant_question( $built, $fields, array( 'reconstructedReference' => '' ) ) );
check( '[6] a missing answer fails', $missing_answer['status'], 'failed' );
check( '[6] reports MCQ_ANSWER_MISSING', has_error_code( $missing_answer, 'mcq_answer_missing' ), true );

// ---------------------------------------------------------------------
// 7. An option that duplicates the correct answer fails.
// ---------------------------------------------------------------------
$option_matches_answer = Citex_Generated_Validator::validate( website_variant_question( $built, $fields, array(
	'options' => array( $built['correctAnswer'], 'y', 'z', '' ),
) ) );
check( '[7] an option matching the answer fails', $option_matches_answer['status'], 'failed' );
check( '[7] reports MCQ_OPTION_MATCHES_ANSWER', has_error_code( $option_matches_answer, 'mcq_option_matches_answer' ), true );

// ---------------------------------------------------------------------
// 8. CRITICAL — an unrecognised websiteMcqVariant fails.
// ---------------------------------------------------------------------
$unknown_variant = Citex_Generated_Validator::validate( website_variant_question( $built, $fields, array( 'websiteMcqVariant' => 'nonexistent_variant' ) ) );
check( '[8] an unrecognised websiteMcqVariant fails', $unknown_variant['status'], 'failed' );
check( '[8] reports WEBSITE_MCQ_VARIANT_UNKNOWN', has_error_code( $unknown_variant, 'website_mcq_variant_unknown' ), true );

// ---------------------------------------------------------------------
// 9. CRITICAL — a scenario that does not exactly match the variant's own
// recomputed stem fails, even though the variant id itself is valid.
// ---------------------------------------------------------------------
$wrong_stem = Citex_Generated_Validator::validate( website_variant_question( $built, $fields, array( 'scenario' => 'Some other question text entirely.' ) ) );
check( '[9] a mismatched scenario fails', $wrong_stem['status'], 'failed' );
check( '[9] reports WEBSITE_MCQ_VARIANT_STEM_MISMATCH', has_error_code( $wrong_stem, 'website_mcq_variant_stem_mismatch' ), true );

// ---------------------------------------------------------------------
// 10. CRITICAL — an answer that is not EXACTLY the variant's own recomputed
// correct answer fails, even if it sounds plausible.
// ---------------------------------------------------------------------
$wrong_answer = Citex_Generated_Validator::validate( website_variant_question( $built, $fields, array(
	'reconstructedReference' => 'Ross, T. (2022) Digital skills [online]. WHO. Available from: <https://www.who.int> [accessed 12 September 2026].',
) ) );
check( '[10] an answer that is not exactly the variant\'s own answer fails', $wrong_answer['status'], 'failed' );
check( '[10] reports WEBSITE_MCQ_VARIANT_ANSWER_MISMATCH', has_error_code( $wrong_answer, 'website_mcq_variant_answer_mismatch' ), true );

// ---------------------------------------------------------------------
// 11. CRITICAL — an option that does not exactly match the variant's own
// recomputed wrong option fails.
// ---------------------------------------------------------------------
$wrong_option = Citex_Generated_Validator::validate( website_variant_question( $built, $fields, array(
	'options' => array( 'Ross, T. (2022) Something Else Entirely [online]. SAGE. Available from: <https://www.sage.com> [accessed 12 September 2026].', $built['wrongOptions'][1], $built['wrongOptions'][2], '' ),
) ) );
check( '[11] a tampered option fails', $wrong_option['status'], 'failed' );
check( '[11] reports WEBSITE_MCQ_VARIANT_OPTION_MISMATCH', has_error_code( $wrong_option, 'website_mcq_variant_option_mismatch' ), true );

// ---------------------------------------------------------------------
// 12. Missing hint fails.
// ---------------------------------------------------------------------
$missing_hint = Citex_Generated_Validator::validate( website_variant_question( $built, $fields, array( 'hint' => '' ) ) );
check( '[12] a missing hint fails', $missing_hint['status'], 'failed' );
check( '[12] reports MCQ_HINT_MISSING', has_error_code( $missing_hint, 'mcq_hint_missing' ), true );

// ---------------------------------------------------------------------
// 13. A hint that reproduces the correct answer fails.
// ---------------------------------------------------------------------
$hint_reveals = Citex_Generated_Validator::validate( website_variant_question( $built, $fields, array(
	'hint' => 'The answer is: ' . $built['correctAnswer'],
) ) );
check( '[13] a hint reproducing the answer fails', $hint_reveals['status'], 'failed' );
check( '[13] reports MCQ_HINT_REPRODUCES_ANSWER or MCQ_HINT_REVEALS_ANSWER', has_error_code( $hint_reveals, 'mcq_hint_reproduces_answer' ) || has_error_code( $hint_reveals, 'mcq_hint_reveals_answer' ), true );

// ---------------------------------------------------------------------
// 14. An organisation-author record also passes — proves the validator
// handles both author types, not just individuals.
// ---------------------------------------------------------------------
$organisation = array( 'type' => 'organisation', 'name' => 'British Council' );
$org_fields = array(
	'author'       => $organisation,
	'year'         => 'n.d.',
	'title'        => 'About us',
	'publisher'    => 'BBC',
	'url'          => 'https://www.bbc.co.uk',
	'accessedDate' => '12 September 2026',
);
$org_built = Citex_Website_Mcq_Variants::build( 'complete_reference', $org_fields );
$org_result = Citex_Generated_Validator::validate( website_variant_question( $org_built, $org_fields ) );
check( '[14] a correctly-built organisation-author variant question passes', $org_result['status'], 'passed' );

// ---------------------------------------------------------------------
// 15. A different variant (reference_structure, fully static) also
// passes — proves the validator is not hardcoded to a single variant id.
// ---------------------------------------------------------------------
$structure_built = Citex_Website_Mcq_Variants::build( 'reference_structure', $fields );
$structure_result = Citex_Generated_Validator::validate( website_variant_question( $structure_built, $fields, array( 'websiteMcqVariant' => 'reference_structure' ) ) );
check( '[15] a correctly-built reference_structure variant question passes', $structure_result['status'], 'passed' );

// ---------------------------------------------------------------------
// 16. The "not_a_correct_reference" variant's inverted shape (correctAnswer
// is the flawed reference, wrongOptions are genuinely valid references for
// other sources) still validates cleanly when built correctly.
// ---------------------------------------------------------------------
$not_correct_built = Citex_Website_Mcq_Variants::build( 'not_a_correct_reference', $fields );
$not_correct_result = Citex_Generated_Validator::validate( website_variant_question( $not_correct_built, $fields, array( 'websiteMcqVariant' => 'not_a_correct_reference' ) ) );
check( '[16] a correctly-built not_a_correct_reference variant question passes', $not_correct_result['status'], 'passed' );
check( '[16] its stem matches the requested wording', $not_correct_built['stem'], 'Which of the following is NOT a correct Harvard reference for a website?' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
