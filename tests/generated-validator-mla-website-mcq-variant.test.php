<?php
/**
 * Regression tests for
 * Citex_Generated_Validator::validate_mla_website_mcq_variant() — the MLA
 * Website MCQ counterpart to validate_mla_book_mcq_variant(), dispatched
 * on a candidate's `mcqPattern` field, backing Citex_MLA_Website_Mcq_Variants.
 * Mirrors tests/generated-validator-mla-book-mcq-variant.test.php's own
 * structure exactly.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-mla-website-mcq-variant.test.php` — not
 * shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

function sanitize_key( $v ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
}

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-website-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-website-mcq-variants.php';
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

$fields = array(
	'author'       => array( 'type' => 'individual', 'fullName' => 'Amy Ross', 'surname' => 'Ross', 'givenName' => 'Amy' ),
	'year'         => '2021',
	'title'        => 'Home Page',
	'url'          => 'https://example.com',
	'accessedDate' => '3 May 2023',
);
$built = Citex_MLA_Website_Mcq_Variants::build( 'complete_reference', $fields );

function mla_web_variant_question( $built, $fields, $overrides = array() ) {
	$author = $fields['author'];
	return array_merge(
		array(
			'source'               => 'MLA',
			'group'                => 'ReferenceList',
			'category'             => 'Website',
			'type'                 => 'MCQ',
			'mcqPattern'           => 'mla_website_mcq_variant',
			'mlaWebsiteMcqVariant' => 'complete_reference',
			'scenario'             => $built['stem'],
			'options'              => array( $built['wrongOptions'][0], $built['wrongOptions'][1], $built['wrongOptions'][2], '' ),
			'hint'                 => 'Think carefully about how each part of the reference should be formatted.',
			'reconstructedReference' => $built['correctAnswer'],
			'authorType'           => $author['type'],
			'authors'              => 'individual' === $author['type'] ? array( $author ) : array(),
			'organisationName'     => 'organisation' === $author['type'] ? ( $author['name'] ?? '' ) : '',
			'year'                 => $fields['year'],
			'pageTitle'            => $fields['title'],
			'url'                  => $fields['url'],
			'accessedDate'         => $fields['accessedDate'],
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A correctly-built variant question PASSES.
// ---------------------------------------------------------------------
$result = Citex_Generated_Validator::validate( mla_web_variant_question( $built, $fields ) );
check( '[1] a correctly-built mla_website_mcq_variant question passes', $result['status'], 'passed' );
check( '[1] no errors reported', $result['errors'], array() );
check( '[1] the reconstructed value returned is the correct answer', $result['reconstructedReference'], $built['correctAnswer'] );

// ---------------------------------------------------------------------
// 2. Not exactly 4 options fails.
// ---------------------------------------------------------------------
$bad_count = Citex_Generated_Validator::validate( mla_web_variant_question( $built, $fields, array( 'options' => array( 'a', 'b', 'c' ) ) ) );
check( '[2] not exactly 4 options fails', $bad_count['status'], 'failed' );
check( '[2] reports MCQ_OPTION_COUNT_MISMATCH', has_error_code( $bad_count, 'mcq_option_count_mismatch' ), true );

// ---------------------------------------------------------------------
// 3. An empty option among the first 3 fails.
// ---------------------------------------------------------------------
$empty_option = Citex_Generated_Validator::validate( mla_web_variant_question( $built, $fields, array( 'options' => array( '', 'x', 'y', '' ) ) ) );
check( '[3] an empty option among the first 3 fails', $empty_option['status'], 'failed' );
check( '[3] reports MCQ_OPTION_EMPTY', has_error_code( $empty_option, 'mcq_option_empty' ), true );

// ---------------------------------------------------------------------
// 4. Option 4 not blank fails.
// ---------------------------------------------------------------------
$fourth_not_blank = Citex_Generated_Validator::validate( mla_web_variant_question( $built, $fields, array( 'options' => array( 'x', 'y', 'z', 'not blank' ) ) ) );
check( '[4] option 4 not blank fails', $fourth_not_blank['status'], 'failed' );
check( '[4] reports MCQ_FOURTH_OPTION_NOT_BLANK', has_error_code( $fourth_not_blank, 'mcq_fourth_option_not_blank' ), true );

// ---------------------------------------------------------------------
// 5. Duplicated options fail.
// ---------------------------------------------------------------------
$duplicate_options = Citex_Generated_Validator::validate( mla_web_variant_question( $built, $fields, array( 'options' => array( 'x', 'x', 'y', '' ) ) ) );
check( '[5] duplicated options fail', $duplicate_options['status'], 'failed' );
check( '[5] reports MCQ_DUPLICATE_OPTION', has_error_code( $duplicate_options, 'mcq_duplicate_option' ), true );

// ---------------------------------------------------------------------
// 6. A missing Answer (reconstructedReference) fails.
// ---------------------------------------------------------------------
$missing_answer = Citex_Generated_Validator::validate( mla_web_variant_question( $built, $fields, array( 'reconstructedReference' => '' ) ) );
check( '[6] a missing answer fails', $missing_answer['status'], 'failed' );
check( '[6] reports MCQ_ANSWER_MISSING', has_error_code( $missing_answer, 'mcq_answer_missing' ), true );

// ---------------------------------------------------------------------
// 7. An option that duplicates the correct answer fails.
// ---------------------------------------------------------------------
$option_matches_answer = Citex_Generated_Validator::validate( mla_web_variant_question( $built, $fields, array(
	'options' => array( $built['correctAnswer'], 'y', 'z', '' ),
) ) );
check( '[7] an option matching the answer fails', $option_matches_answer['status'], 'failed' );
check( '[7] reports MCQ_OPTION_MATCHES_ANSWER', has_error_code( $option_matches_answer, 'mcq_option_matches_answer' ), true );

// ---------------------------------------------------------------------
// 8. CRITICAL — an unrecognised mlaWebsiteMcqVariant fails.
// ---------------------------------------------------------------------
$unknown_variant = Citex_Generated_Validator::validate( mla_web_variant_question( $built, $fields, array( 'mlaWebsiteMcqVariant' => 'nonexistent_variant' ) ) );
check( '[8] an unrecognised mlaWebsiteMcqVariant fails', $unknown_variant['status'], 'failed' );
check( '[8] reports MLA_WEBSITE_MCQ_VARIANT_UNKNOWN', has_error_code( $unknown_variant, 'mla_website_mcq_variant_unknown' ), true );

// ---------------------------------------------------------------------
// 9. CRITICAL — a scenario that does not exactly match the variant's own
// recomputed stem fails, even though the variant id itself is valid.
// ---------------------------------------------------------------------
$wrong_stem = Citex_Generated_Validator::validate( mla_web_variant_question( $built, $fields, array( 'scenario' => 'Some other question text entirely.' ) ) );
check( '[9] a mismatched scenario fails', $wrong_stem['status'], 'failed' );
check( '[9] reports MLA_WEBSITE_MCQ_VARIANT_STEM_MISMATCH', has_error_code( $wrong_stem, 'mla_website_mcq_variant_stem_mismatch' ), true );

// ---------------------------------------------------------------------
// 10. CRITICAL — an answer that is not EXACTLY the variant's own
// recomputed correct answer fails, even if it sounds plausible.
// ---------------------------------------------------------------------
$wrong_answer = Citex_Generated_Validator::validate( mla_web_variant_question( $built, $fields, array(
	'reconstructedReference' => 'Ross, Amy "Something Else." 2021, https://example.com. Accessed 3 May 2023.',
) ) );
check( '[10] an answer that is not exactly the variant\'s own answer fails', $wrong_answer['status'], 'failed' );
check( '[10] reports MLA_WEBSITE_MCQ_VARIANT_ANSWER_MISMATCH', has_error_code( $wrong_answer, 'mla_website_mcq_variant_answer_mismatch' ), true );

// ---------------------------------------------------------------------
// 11. CRITICAL — an option that does not exactly match the variant's own
// recomputed wrong option fails.
// ---------------------------------------------------------------------
$wrong_option = Citex_Generated_Validator::validate( mla_web_variant_question( $built, $fields, array(
	'options' => array( 'Ross, Amy "Something Else Entirely." 2021, https://example.com. Accessed 3 May 2023.', $built['wrongOptions'][1], $built['wrongOptions'][2], '' ),
) ) );
check( '[11] a tampered option fails', $wrong_option['status'], 'failed' );
check( '[11] reports MLA_WEBSITE_MCQ_VARIANT_OPTION_MISMATCH', has_error_code( $wrong_option, 'mla_website_mcq_variant_option_mismatch' ), true );

// ---------------------------------------------------------------------
// 12. Missing hint fails.
// ---------------------------------------------------------------------
$missing_hint = Citex_Generated_Validator::validate( mla_web_variant_question( $built, $fields, array( 'hint' => '' ) ) );
check( '[12] a missing hint fails', $missing_hint['status'], 'failed' );
check( '[12] reports MCQ_HINT_MISSING', has_error_code( $missing_hint, 'mcq_hint_missing' ), true );

// ---------------------------------------------------------------------
// 13. A hint that reproduces the correct answer fails.
// ---------------------------------------------------------------------
$hint_reveals = Citex_Generated_Validator::validate( mla_web_variant_question( $built, $fields, array(
	'hint' => 'The answer is: ' . $built['correctAnswer'],
) ) );
check( '[13] a hint reproducing the answer fails', $hint_reveals['status'], 'failed' );
check( '[13] reports MCQ_HINT_REPRODUCES_ANSWER or MCQ_HINT_REVEALS_ANSWER', has_error_code( $hint_reveals, 'mcq_hint_reproduces_answer' ) || has_error_code( $hint_reveals, 'mcq_hint_reveals_answer' ), true );

// ---------------------------------------------------------------------
// 14. An organisation-author record also passes end-to-end.
// ---------------------------------------------------------------------
$org_fields = array(
	'author'       => array( 'type' => 'organisation', 'name' => 'WHO' ),
	'year'         => '2020',
	'title'        => 'Facts',
	'url'          => 'https://who.example.com',
	'accessedDate' => '14 Jan 2024',
);
$org_built  = Citex_MLA_Website_Mcq_Variants::build( 'complete_reference', $org_fields );
$org_result = Citex_Generated_Validator::validate( mla_web_variant_question( $org_built, $org_fields ) );
check( '[14] a correctly-built organisation-author complete_reference question passes', $org_result['status'], 'passed' );

// ---------------------------------------------------------------------
// 15. An undated record's undated_source variant also passes end-to-end
// — the category's own defining rule.
// ---------------------------------------------------------------------
$undated_fields = array(
	'author'       => array( 'type' => 'individual', 'fullName' => 'Amy Ross', 'surname' => 'Ross', 'givenName' => 'Amy' ),
	'year'         => '',
	'title'        => 'Home Page',
	'url'          => 'https://example.com',
	'accessedDate' => '3 May 2023',
);
$undated_built  = Citex_MLA_Website_Mcq_Variants::build( 'undated_source', $undated_fields );
$undated_result = Citex_Generated_Validator::validate( mla_web_variant_question( $undated_built, $undated_fields, array( 'mlaWebsiteMcqVariant' => 'undated_source' ) ) );
check( '[15] a correctly-built undated_source variant question passes', $undated_result['status'], 'passed' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
