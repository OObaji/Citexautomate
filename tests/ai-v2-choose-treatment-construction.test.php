<?php
/**
 * Regression tests for Citex_AI_V2's "Choose the correct rule/treatment"
 * MCQ mechanic (Citex_Question_Scenarios' `choose_treatment_*`, both
 * categories) — normalise_choose_treatment_item() via the normalise()
 * dispatch.
 *
 * Unlike every other MCQ pattern, this one has NO bibliographic record at
 * all: it tests pure rule knowledge ("which statement is correct about how
 * N authors/editors are referenced"), so Citex authors both the question
 * stem AND the correct answer itself
 * (Citex_Reference_Rules::treatment_question()) — Gemini's only job is
 * three plausible-but-wrong `wrongStatements`.
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-choose-treatment-construction.test.php` — not shipped
 * in citex-tools.zip.
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
require __DIR__ . '/../citex-tools/includes/class-citex-question-scenarios.php';
require __DIR__ . '/../citex-tools/includes/class-citex-question-diversity.php';
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

function invoke_normalise( $questions, $ids, $difficulty, $exercises, $type, $category, $target_count = null, $scenario_id = '', $rule_tested = '' ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions, $ids, $difficulty, $exercises, $type, $category, $target_count, $scenario_id, $rule_tested );
}

function four_or_more_authors_item( $overrides = array() ) {
	return array_merge(
		array(
			'questionId'      => 'BK01',
			'wrongStatements' => array(
				'The first author is listed and the rest are shortened to et al.',
				'Only the first three authors are listed; the rest are omitted.',
				'Authors are joined with an ampersand (&) instead of commas and "and".',
			),
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. The exact "et al." misconception test from the user's own worked
// example: Book, four_or_more_authors. The correct answer must be exactly
// Citex's own fixed statement — the "et al. is not used" rule.
// ---------------------------------------------------------------------
$result = invoke_normalise( array( four_or_more_authors_item() ), array( 'BK01' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 4, 'choose_treatment_four_or_more_authors', 'reference_list_all_authors' );
check( '[1] normalise() succeeds for a valid choose_treatment item', is_wp_error( $result ), false );
if ( ! is_wp_error( $result ) ) {
	$candidate = $result[0];
	check( '[1] candidate type is MCQ', $candidate['type'], 'MCQ' );
	check( '[1] mcqPattern is choose_treatment', $candidate['mcqPattern'], 'choose_treatment' );
	check( '[1] treatmentBucket is four_or_more_authors', $candidate['treatmentBucket'], 'four_or_more_authors' );
	check( '[1] the stem matches the user\'s own confirmed wording', $candidate['scenario'], 'Which statement is correct about a book with four or more authors in the Liverpool Hope Harvard reference list?' );
	check( '[1] the Answer field is exactly the confirmed correct statement', $candidate['reconstructedReference'], 'All authors should be included; et al. is not used in the reference list.' );
	check( '[1] exactly 4 option slots', count( $candidate['options'] ), 4 );
	check( '[1] options 1-3 are exactly the wrongStatements, in order', array_slice( $candidate['options'], 0, 3 ), four_or_more_authors_item()['wrongStatements'] );
	check( '[1] option 4 is always blank', $candidate['options'][3], '' );
	check( '[1] the true statement never appears in any option', in_array( $candidate['reconstructedReference'], $candidate['options'], true ), false );
	check( '[1] no bibliographic fields are present at all (pure rule knowledge)', isset( $candidate['authorFullNames'] ) || isset( $candidate['bookTitle'] ), false );
	check( '[1] validation passed', $candidate['validationStatus'], 'passed' );
	check( '[1] a hint is generated', '' !== trim( $candidate['hint'] ), true );
	check( '[1] the hint does not reproduce the true statement', false !== strpos( $candidate['hint'], $candidate['reconstructedReference'] ), false );
	check( '[1] blueprint scenario is the full choose_treatment_ scenario id', $candidate['blueprint']['scenario'], 'choose_treatment_four_or_more_authors' );
}

// ---------------------------------------------------------------------
// 2. Book two_authors and three_authors buckets produce their own correct
// stems/statements.
// ---------------------------------------------------------------------
$two_authors_result = invoke_normalise(
	array( array( 'questionId' => 'BK02', 'wrongStatements' => array( 'Two authors are joined with a comma only, with no "and".', 'Only the first author is listed.', 'Two authors are joined with an ampersand.' ) ) ),
	array( 'BK02' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 2, 'choose_treatment_two_authors', 'author_joining'
);
check( '[2] two_authors bucket succeeds', is_wp_error( $two_authors_result ), false );
if ( ! is_wp_error( $two_authors_result ) ) {
	check( '[2] two_authors correct statement', $two_authors_result[0]['reconstructedReference'], 'Both authors are included, joined by "and" — e.g. Smith, J. and Jones, A.' );
}

$three_authors_result = invoke_normalise(
	array( array( 'questionId' => 'BK03', 'wrongStatements' => array( 'All three authors are joined with commas only, with no "and".', 'Only the first two authors are listed.', 'The authors are joined with an ampersand throughout.' ) ) ),
	array( 'BK03' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 3, 'choose_treatment_three_authors', 'author_joining'
);
check( '[2] three_authors bucket succeeds', is_wp_error( $three_authors_result ), false );
if ( ! is_wp_error( $three_authors_result ) ) {
	check( '[2] three_authors correct statement', $three_authors_result[0]['reconstructedReference'], 'All three authors are included, separated by commas with "and" before the final author — e.g. Smith, J., Jones, A. and Brown, T.' );
}

// ---------------------------------------------------------------------
// 3. Edited Book buckets (two_editors, three_or_more_editors) work
// identically, using the designation/joining rule statements.
// ---------------------------------------------------------------------
$two_editors_result = invoke_normalise(
	array( array( 'questionId' => 'EB01', 'wrongStatements' => array( 'Both editors are joined with an ampersand and the designation is "(ed.)".', 'Only the first editor is listed, followed by "(eds)".', 'The editors are joined with a comma only, with no designation at all.' ) ) ),
	array( 'EB01' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_EDITED_BOOK, 2, 'choose_treatment_two_editors', 'editor_designation'
);
check( '[3] two_editors bucket succeeds', is_wp_error( $two_editors_result ), false );
if ( ! is_wp_error( $two_editors_result ) ) {
	check( '[3] candidate category is Edited Book', $two_editors_result[0]['category'], 'Edited Book' );
	check( '[3] two_editors correct statement', $two_editors_result[0]['reconstructedReference'], 'Both editors are included, joined by "and", followed by the designation "(eds)" — e.g. Smith, J. and Jones, A. (eds).' );
}

$three_or_more_editors_result = invoke_normalise(
	array( array( 'questionId' => 'EB02', 'wrongStatements' => array( 'All editors are joined with commas only, with no "and", followed by "(ed.)".', 'Only the first editor is listed, followed by "(eds)".', 'The editors are joined with an ampersand throughout.' ) ) ),
	array( 'EB02' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_EDITED_BOOK, 3, 'choose_treatment_three_or_more_editors', 'editor_joining'
);
check( '[3] three_or_more_editors bucket succeeds', is_wp_error( $three_or_more_editors_result ), false );
if ( ! is_wp_error( $three_or_more_editors_result ) ) {
	check( '[3] three_or_more_editors correct statement', $three_or_more_editors_result[0]['reconstructedReference'], 'All editors are included, separated by commas with "and" before the final editor, followed by the designation "(eds)" — e.g. Smith, J., Jones, A. and Brown, T. (eds).' );
}

// ---------------------------------------------------------------------
// 4. Not exactly 3 wrongStatements is rejected.
// ---------------------------------------------------------------------
$too_few = invoke_normalise(
	array( four_or_more_authors_item( array( 'wrongStatements' => array( 'Only one statement.' ) ) ) ),
	array( 'BK04' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 4, 'choose_treatment_four_or_more_authors', 'reference_list_all_authors'
);
check( '[4] fewer than 3 wrongStatements is rejected', is_wp_error( $too_few ), true );
check( '[4] error code identifies the option-count problem', is_wp_error( $too_few ) ? $too_few->get_error_code() : null, 'citex_ai_treatment_bad_option_count' );

// ---------------------------------------------------------------------
// 5. A wrongStatement identical to the true statement is rejected.
// ---------------------------------------------------------------------
$duplicate_answer = invoke_normalise(
	array( four_or_more_authors_item( array( 'wrongStatements' => array(
		'All authors should be included; et al. is not used in the reference list.',
		'Only the first three authors are listed; the rest are omitted.',
		'Authors are joined with an ampersand (&) instead of commas and "and".',
	) ) ) ),
	array( 'BK05' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 4, 'choose_treatment_four_or_more_authors', 'reference_list_all_authors'
);
check( '[5] a wrongStatement identical to the true statement no longer blocks generation (quality gate decoupled)', is_wp_error( $duplicate_answer ), false );

// ---------------------------------------------------------------------
// 6. Two identical wrongStatements is rejected as a duplicate option.
// ---------------------------------------------------------------------
$duplicate_option = invoke_normalise(
	array( four_or_more_authors_item( array( 'wrongStatements' => array(
		'The first author is listed and the rest are shortened to et al.',
		'The first author is listed and the rest are shortened to et al.',
		'Authors are joined with an ampersand (&) instead of commas and "and".',
	) ) ) ),
	array( 'BK06' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 4, 'choose_treatment_four_or_more_authors', 'reference_list_all_authors'
);
check( '[6] two identical wrongStatements no longer blocks generation (quality gate decoupled)', is_wp_error( $duplicate_option ), false );

// ---------------------------------------------------------------------
// 7. An unrecognised treatment bucket is rejected.
// ---------------------------------------------------------------------
$unknown_bucket = invoke_normalise(
	array( four_or_more_authors_item() ),
	array( 'BK07' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, null, 'choose_treatment_nonexistent_bucket', ''
);
check( '[7] an unrecognised bucket is rejected', is_wp_error( $unknown_bucket ), true );
check( '[7] error code identifies the unknown bucket', is_wp_error( $unknown_bucket ) ? $unknown_bucket->get_error_code() : null, 'citex_ai_unknown_treatment_bucket' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
