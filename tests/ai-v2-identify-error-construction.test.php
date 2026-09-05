<?php
/**
 * Regression tests for Citex_AI_V2's "Identify the error" MCQ mechanic
 * (Citex_Question_Scenarios' `identify_error`, both categories) —
 * normalise_identify_error_item() via the normalise() dispatch.
 *
 * Unlike every other MCQ pattern, this one's options are plain-English
 * error DESCRIPTIONS, not Harvard reference strings: the scenario shown to
 * the student is Citex's fixed "What is incorrect about the following
 * Harvard reference?" stem followed by Gemini's (validated) brokenReference,
 * options 1-3 are Gemini's wrongDescriptions, option 4 is always blank, and
 * the Answer field (reconstructedReference) holds the TRUE description
 * (brokenReference's own errorReason).
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-identify-error-construction.test.php` — not shipped in
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

function book_identify_error_item( $overrides = array() ) {
	return array_merge(
		array(
			'questionId'         => 'BK01',
			'authorFullNames'    => array( 'Alan Bryman' ),
			'year'               => '2012',
			'bookTitle'          => 'Social Research Methods',
			'place'              => 'Oxford',
			'publisher'          => 'Oxford University Press',
			'brokenReference'    => array(
				'reference'   => 'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.',
				'errorReason' => 'Uses the author\'s full first name instead of initials, and is missing the comma after the surname.',
			),
			'wrongDescriptions' => array(
				'The publication year is not enclosed in parentheses.',
				'The place of publication and publisher have been swapped.',
				'The book title is missing its final full stop.',
			),
		),
		$overrides
	);
}

function edited_book_identify_error_item( $overrides = array() ) {
	return array_merge(
		array(
			'questionId'         => 'EB01',
			'editorFullNames'    => array( 'Vincent Miller' ),
			'year'               => '2020',
			'bookTitle'          => 'Understanding digital culture',
			'place'              => 'London',
			'publisher'          => 'SAGE Publications',
			'brokenReference'    => array(
				'reference'   => 'Miller, V. (2020) Understanding digital culture. London: SAGE Publications.',
				'errorReason' => 'Missing the editor designation (ed.) entirely.',
			),
			'wrongDescriptions' => array(
				'The publication year is not enclosed in parentheses.',
				'The place of publication and publisher have been swapped.',
				'The book title is missing its final full stop.',
			),
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A valid Book identify_error item produces a correctly-shaped
// candidate: exactly 4 options (3 wrong descriptions + blank), the true
// description lives only in the Answer field, the scenario embeds the
// broken reference, and mcqPattern is stamped for the validator dispatch.
// ---------------------------------------------------------------------
$result = invoke_normalise( array( book_identify_error_item() ), array( 'BK01' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 1, 'identify_error', 'error_identification' );
check( '[1] normalise() succeeds for a valid Book identify_error item', is_wp_error( $result ), false );
if ( ! is_wp_error( $result ) ) {
	$candidate = $result[0];
	check( '[1] candidate type is MCQ', $candidate['type'], 'MCQ' );
	check( '[1] candidate category is Book', $candidate['category'], 'Book' );
	check( '[1] mcqPattern is identify_error', $candidate['mcqPattern'], 'identify_error' );
	check( '[1] exactly 4 option slots', count( $candidate['options'] ), 4 );
	check( '[1] options 1-3 are exactly the wrongDescriptions, in order', array_slice( $candidate['options'], 0, 3 ), book_identify_error_item()['wrongDescriptions'] );
	check( '[1] option 4 is always blank', $candidate['options'][3], '' );
	check( '[1] the Answer field holds the TRUE description (errorReason)', $candidate['reconstructedReference'], 'Uses the author\'s full first name instead of initials, and is missing the comma after the surname.' );
	check( '[1] the true description never appears in any option', in_array( $candidate['reconstructedReference'], $candidate['options'], true ), false );
	check( '[1] the scenario embeds the broken reference', false !== strpos( $candidate['scenario'], 'Bryman A. (2012) Social Research Methods.' ), true );
	check( '[1] the scenario starts with the fixed "What is incorrect" stem', 0 === strpos( $candidate['scenario'], 'What is incorrect about the following Harvard reference?' ), true );
	check( '[1] validation passed (pre-queue quality gate)', $candidate['validationStatus'], 'passed' );
	check( '[1] a hint is generated', '' !== trim( $candidate['hint'] ), true );
	check( '[1] the hint does not reproduce the true description', false !== strpos( $candidate['hint'], $candidate['reconstructedReference'] ), false );
	check( '[1] blueprint scenario is identify_error', $candidate['blueprint']['scenario'], 'identify_error' );
}

// ---------------------------------------------------------------------
// 2. A valid Edited Book identify_error item works identically, using the
// `editors` field instead of `authors` and the designation-mistake pattern.
// ---------------------------------------------------------------------
$eb_result = invoke_normalise( array( edited_book_identify_error_item() ), array( 'EB01' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_EDITED_BOOK, 1, 'identify_error', 'error_identification' );
check( '[2] normalise() succeeds for a valid Edited Book identify_error item', is_wp_error( $eb_result ), false );
if ( ! is_wp_error( $eb_result ) ) {
	$eb_candidate = $eb_result[0];
	check( '[2] candidate category is Edited Book', $eb_candidate['category'], 'Edited Book' );
	check( '[2] editors array carries the derived surname/initials', $eb_candidate['editors'], array( array( 'fullName' => 'Vincent Miller', 'surname' => 'Miller', 'initials' => 'V.' ) ) );
	check( '[2] the Answer field holds the true description', $eb_candidate['reconstructedReference'], 'Missing the editor designation (ed.) entirely.' );
	check( '[2] validation passed', $eb_candidate['validationStatus'], 'passed' );
}

// ---------------------------------------------------------------------
// 3. A "broken" reference that is actually fully valid must be rejected —
// the whole point of this question type is showing a genuinely wrong
// reference, and Citex independently re-validates it rather than trusting
// Gemini's claim.
// ---------------------------------------------------------------------
$not_broken = invoke_normalise(
	array( book_identify_error_item( array( 'brokenReference' => array( 'reference' => 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.', 'errorReason' => 'Missing comma after surname.' ) ) ) ),
	array( 'BK02' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 1, 'identify_error', 'error_identification'
);
check( '[3] a "broken" reference that is actually fully valid no longer blocks generation (quality gate decoupled)', is_wp_error( $not_broken ), false );
check( '[3] the candidate is still recorded as failed validation', is_wp_error( $not_broken ) ? null : $not_broken[0]['validationStatus'], 'failed' );

// ---------------------------------------------------------------------
// 4. A wrongDescription identical to the true description (errorReason)
// is rejected before validation even runs — the answer must never be
// duplicated into an option.
// ---------------------------------------------------------------------
$duplicate_answer = invoke_normalise(
	array( book_identify_error_item( array( 'wrongDescriptions' => array(
		'Uses the author\'s full first name instead of initials, and is missing the comma after the surname.',
		'The place of publication and publisher have been swapped.',
		'The book title is missing its final full stop.',
	) ) ) ),
	array( 'BK03' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 1, 'identify_error', 'error_identification'
);
check( '[4] a wrongDescription identical to the true description no longer blocks generation (quality gate decoupled)', is_wp_error( $duplicate_answer ), false );

// ---------------------------------------------------------------------
// 5. Fewer or more than 3 wrongDescriptions is rejected.
// ---------------------------------------------------------------------
$too_few = invoke_normalise(
	array( book_identify_error_item( array( 'wrongDescriptions' => array( 'Only one description.' ) ) ) ),
	array( 'BK04' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 1, 'identify_error', 'error_identification'
);
check( '[5] fewer than 3 wrongDescriptions is rejected', is_wp_error( $too_few ), true );
check( '[5] error code identifies the option-count problem', is_wp_error( $too_few ) ? $too_few->get_error_code() : null, 'citex_ai_identify_error_bad_option_count' );

// ---------------------------------------------------------------------
// 6. Two identical wrongDescriptions is rejected as a duplicate option.
// ---------------------------------------------------------------------
$duplicate_option = invoke_normalise(
	array( book_identify_error_item( array( 'wrongDescriptions' => array(
		'The publication year is not enclosed in parentheses.',
		'The publication year is not enclosed in parentheses.',
		'The book title is missing its final full stop.',
	) ) ) ),
	array( 'BK05' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 1, 'identify_error', 'error_identification'
);
check( '[6] two identical wrongDescriptions no longer blocks generation (quality gate decoupled)', is_wp_error( $duplicate_option ), false );

// ---------------------------------------------------------------------
// 7. A missing brokenReference or missing errorReason is rejected before
// anything else is checked.
// ---------------------------------------------------------------------
$missing_reference = invoke_normalise(
	array( book_identify_error_item( array( 'brokenReference' => array( 'reference' => '', 'errorReason' => 'Something.' ) ) ) ),
	array( 'BK06' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 1, 'identify_error', 'error_identification'
);
check( '[7] a missing brokenReference text is rejected', is_wp_error( $missing_reference ), true );
check( '[7] error code identifies the missing reference', is_wp_error( $missing_reference ) ? $missing_reference->get_error_code() : null, 'citex_ai_identify_error_missing_reference' );

$missing_reason = invoke_normalise(
	array( book_identify_error_item( array( 'brokenReference' => array( 'reference' => 'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.', 'errorReason' => '' ) ) ) ),
	array( 'BK07' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 1, 'identify_error', 'error_identification'
);
check( '[7] a missing errorReason is rejected', is_wp_error( $missing_reason ), true );
check( '[7] error code identifies the missing reason', is_wp_error( $missing_reason ) ? $missing_reason->get_error_code() : null, 'citex_ai_identify_error_missing_reason' );

// ---------------------------------------------------------------------
// 8. Target-count enforcement applies here too: identify_error's own
// catalog entry targets exactly 1 author/editor — a mismatch is rejected.
// ---------------------------------------------------------------------
$count_mismatch = invoke_normalise(
	array( book_identify_error_item( array( 'authorFullNames' => array( 'Alan Bryman', 'Jo Martin' ) ) ) ),
	array( 'BK08' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 1, 'identify_error', 'error_identification'
);
check( '[8] a 2-author item is rejected when the scenario targets exactly 1', is_wp_error( $count_mismatch ), true );
check( '[8] error code identifies the count mismatch', is_wp_error( $count_mismatch ) ? $count_mismatch->get_error_code() : null, 'citex_ai_author_count_mismatch' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
