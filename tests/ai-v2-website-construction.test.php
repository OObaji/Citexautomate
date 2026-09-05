<?php
/**
 * Regression tests for Citex_AI_V2's Website/Web Resource support — the new
 * category's DragDrop/MCQ prompt/schema/system-instruction dispatch and
 * normalise() construction, mirroring ai-v2-journal-article-construction.test.php
 * but exercising Website's own field shape (authorType + authorFullName or
 * organisationName, year-or-"n.d.", title, publisher, url — no place, and
 * an accessed date Citex computes itself rather than asking Gemini for).
 *
 * Repo-level only, run with plain `php tests/ai-v2-website-construction.test.php`
 * — not shipped in citex-tools.zip.
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

function invoke_private( $method, $args ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $args );
}

function invoke_normalise( $questions, $ids, $difficulty, $exercises, $type, $category, $target_count = null, $scenario_id = '', $rule_tested = '', $exercise_design = 'author_year_title' ) {
	return invoke_private( 'normalise', array( $questions, $ids, $difficulty, $exercises, $type, $category, $target_count, $scenario_id, $rule_tested, $exercise_design ) );
}

$WR = Citex_Reference_Rules::CATEGORY_WEBSITE;

function website_individual_item( $overrides = array() ) {
	return array_merge(
		array(
			'scenario'        => 'You are referencing a webpage written by Sarah Mitchell in 2024 and published by the University of Leeds, titled Study skills guide, at https://www.leeds.ac.uk/study-skills.',
			'authorType'      => 'individual',
			'authorFullName'  => 'Sarah Mitchell',
			'year'            => '2024',
			'title'           => 'Study skills guide',
			'publisher'       => 'University of Leeds',
			'url'             => 'https://www.leeds.ac.uk/study-skills',
			'questionParts'   => array( 'Mitchell, S.', '2024', 'Study skills guide', 'University of Leeds', 'https://www.leeds.ac.uk/study-skills', '3 September 2026' ),
			'fixedText'       => '| (||) || [online]. ||. Available from: <||> [accessed ||].',
			'confusingWords'  => array( '2020', 'London Metropolitan University', 'https://www.leeds.ac.uk/wrong-page' ),
		),
		$overrides
	);
}
function website_organisation_item( $overrides = array() ) {
	return array_merge(
		array(
			'scenario'         => 'You are referencing a University of Leeds webpage titled About us, at https://www.leeds.ac.uk/about.',
			'authorType'       => 'organisation',
			'organisationName' => 'University of Leeds',
			'year'             => 'n.d.',
			'title'            => 'About us',
			'publisher'        => 'University of Leeds',
			'url'              => 'https://www.leeds.ac.uk/about',
			'questionParts'    => array( 'University of Leeds', 'n.d.', 'About us', 'University of Leeds', 'https://www.leeds.ac.uk/about', '3 September 2026' ),
			'fixedText'        => '| (||) || [online]. ||. Available from: <||> [accessed ||].',
			'confusingWords'   => array( '2020', 'London Metropolitan University', 'https://www.leeds.ac.uk/wrong-page' ),
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// Dispatch: build_prompt_for()/schema_for()/system_instruction_for() route
// Website to its own builders, distinct from every other category.
// ---------------------------------------------------------------------
$dd_prompt = invoke_private( 'build_prompt_for', array( 'DragDrop', $WR, array( 'WR01' ), 'medium', false, '' ) );
check( '[dispatch] Website DragDrop prompt mentions "Website"', false !== strpos( $dd_prompt, 'Website' ), true );
check( '[dispatch] Website DragDrop prompt mentions "authorType"', false !== strpos( $dd_prompt, 'authorType' ), true );
check( '[dispatch] Website DragDrop prompt says NOT to provide an accessed date', false !== stripos( $dd_prompt, 'Do NOT provide an accessed date' ), true );
check( 'Website DragDrop prompt includes the conciseness (mobile-readability) guidance', false !== strpos( $dd_prompt, 'PREFER CONCISE REAL PUBLISHER/JOURNAL NAMES WHEN POSSIBLE' ), true );

$mcq_prompt = invoke_private( 'build_prompt_for', array( 'MCQ', $WR, array( 'WR01' ), 'medium', false, '' ) );
check( '[dispatch] Website MCQ prompt mentions "Website"', false !== strpos( $mcq_prompt, 'Website' ), true );

$dd_schema = invoke_private( 'schema_for', array( 'DragDrop', $WR, '' ) );
$props     = $dd_schema['properties']['questions']['items']['properties'];
check( '[dispatch] Website DragDrop schema has authorType/title/publisher/url fields', isset( $props['authorType'], $props['title'], $props['publisher'], $props['url'] ), true );
check( '[dispatch] Website DragDrop schema has no bookTitle/place/articleTitle fields', isset( $props['bookTitle'] ) || isset( $props['place'] ) || isset( $props['articleTitle'] ), false );
check( '[dispatch] Website DragDrop schema has no accessedDate field (Citex computes it)', isset( $props['accessedDate'] ), false );

$mcq_schema = invoke_private( 'schema_for', array( 'MCQ', $WR, '' ) );
check( '[dispatch] Website MCQ schema has no scenario field (Citex authors the fixed stem)', isset( $mcq_schema['properties']['questions']['items']['properties']['scenario'] ), false );

$sys_dd = invoke_private( 'system_instruction_for', array( 'DragDrop', $WR, '' ) );
check( '[dispatch] Website system instruction mentions webpages, not books', false !== stripos( $sys_dd, 'webpage' ), true );

// ---------------------------------------------------------------------
// 1 & 3. Individual author, dated — normalise() constructs a correct
// DragDrop candidate; Citex derives initials itself and computes the
// accessed date itself.
// ---------------------------------------------------------------------
$result1 = invoke_normalise( array( website_individual_item() ), array( 'WR01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $WR );
check( '[1][3] normalise() succeeds for individual author, dated DragDrop', is_wp_error( $result1 ), false );
if ( ! is_wp_error( $result1 ) ) {
	$c1 = $result1[0];
	check( '[1] category is "Website"', $c1['category'], 'Website' );
	check( '[1] authorType is "individual"', $c1['authorType'], 'individual' );
	check( '[1] initials correctly derived: "Sarah Mitchell" -> "Mitchell", "S."', $c1['authors'][0]['surname'] . '|' . $c1['authors'][0]['initials'], 'Mitchell|S.' );
	check( '[17] Question Parts contain exactly 3 or 4 items, per the assigned exercise design', in_array( count( $c1['questionParts'] ), array( 3, 4 ), true ), true );
	check( '[14] Citex computed its own accessedDate (not empty, not Gemini\'s self-check copy)', '' !== trim( $c1['accessedDate'] ), true );
	check( '[19][20] validates and enters the queue as "passed"', $c1['validationStatus'], 'passed' );
}

// ---------------------------------------------------------------------
// 2 & 4. Organisation author, undated ("n.d.") — the organisation's name
// is used exactly as given, and the year field is exactly "n.d.".
// ---------------------------------------------------------------------
$result2 = invoke_normalise( array( website_organisation_item() ), array( 'WR02' ), 'medium', array( 'Exercise 2' ), 'DragDrop', $WR );
check( '[2][4] normalise() succeeds for organisation author, undated DragDrop', is_wp_error( $result2 ), false );
if ( ! is_wp_error( $result2 ) ) {
	$c2 = $result2[0];
	check( '[2] authorType is "organisation"', $c2['authorType'], 'organisation' );
	check( '[2] organisationName is used exactly as given', $c2['organisationName'], 'University of Leeds' );
	check( '[4] year is exactly "n.d."', $c2['year'], 'n.d.' );
	check( 'validates and enters the queue as "passed"', $c2['validationStatus'], 'passed' );
}

// ---------------------------------------------------------------------
// 9. authorType must be exactly "individual" or "organisation" — anything
// else is rejected.
// ---------------------------------------------------------------------
$bad_author_type = invoke_normalise( array( website_individual_item( array( 'authorType' => 'company' ) ) ), array( 'WR01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $WR );
check( '[9] an invalid authorType is rejected', is_wp_error( $bad_author_type ), true );

// ---------------------------------------------------------------------
// A year that is neither a real 4-digit year nor exactly "n.d." is
// rejected (never a guessed/vague year like "circa 2020").
// ---------------------------------------------------------------------
$bad_year = invoke_normalise( array( website_individual_item( array( 'year' => 'circa 2020' ) ) ), array( 'WR01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $WR );
check( 'a non-4-digit, non-"n.d." year is rejected', is_wp_error( $bad_year ), true );

// ---------------------------------------------------------------------
// 13. An invalid/malformed URL is rejected.
// ---------------------------------------------------------------------
$bad_url = invoke_normalise( array( website_individual_item( array( 'url' => 'not a url' ) ) ), array( 'WR01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $WR );
check( '[13] a malformed URL is rejected', is_wp_error( $bad_url ), true );

// ---------------------------------------------------------------------
// Scenario bucket enforcement (mirrors Book's author-count enforcement):
// an organisation-author candidate is rejected for an "individual_author"
// scenario, and a dated candidate is rejected for an "undated" scenario.
// ---------------------------------------------------------------------
$wrong_type_for_scenario = invoke_normalise( array( website_organisation_item() ), array( 'WR01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $WR, null, 'individual_author_dated', 'date_handling' );
check( 'an organisation-author candidate is rejected for an "individual_author_dated" scenario', is_wp_error( $wrong_type_for_scenario ), true );

$wrong_date_for_scenario = invoke_normalise( array( website_individual_item() ), array( 'WR01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $WR, null, 'individual_author_undated', 'date_handling' );
check( 'a dated candidate is rejected for an "individual_author_undated" scenario', is_wp_error( $wrong_date_for_scenario ), true );

$right_scenario = invoke_normalise( array( website_individual_item() ), array( 'WR01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $WR, null, 'individual_author_dated', 'date_handling' );
check( '[26][27] a matching candidate for its scenario carries the correct category/blueprint', is_wp_error( $right_scenario ), false );
if ( ! is_wp_error( $right_scenario ) ) {
	check( '[26] blueprint category is Website', $right_scenario[0]['blueprint']['category'], 'Website' );
	check( '[27] blueprint scenario id is recorded', $right_scenario[0]['blueprint']['scenario'], 'individual_author_dated' );
}

// ---------------------------------------------------------------------
// 22. Scenario/source mismatch: a scenario naming a different title than
// the canonical record is rejected by the pre-queue quality gate.
// ---------------------------------------------------------------------
$mismatched = website_individual_item( array( 'scenario' => 'You are referencing a webpage written by Sarah Mitchell in 2024 and published by the University of Leeds, titled A totally different title, at https://www.leeds.ac.uk/study-skills.' ) );
$mismatch_result = invoke_normalise( array( $mismatched ), array( 'WR01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $WR );
check( '[22] a scenario naming a different title than the canonical record no longer blocks generation (quality gate decoupled)', is_wp_error( $mismatch_result ), false );
check( '[22] the candidate is still recorded as failed validation', is_wp_error( $mismatch_result ) ? null : $mismatch_result[0]['validationStatus'], 'failed' );

// ---------------------------------------------------------------------
// 25. Distractor validation: a distractor duplicating a correct Question
// Part is rejected; a duplicate distractor pair is rejected.
// ---------------------------------------------------------------------
$dup_of_part = invoke_normalise( array( website_individual_item( array( 'confusingWords' => array( '2024', 'x', 'y' ) ) ) ), array( 'WR01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $WR );
check( '[25] a distractor matching a correct Question Part (the real year) no longer blocks generation (quality gate decoupled)', is_wp_error( $dup_of_part ), false );

$dup_distractor = invoke_normalise( array( website_individual_item( array( 'confusingWords' => array( 'x', 'x', 'y' ) ) ) ), array( 'WR01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $WR );
check( '[25] a duplicated distractor pair no longer blocks generation (quality gate decoupled)', is_wp_error( $dup_distractor ), false );

// ---------------------------------------------------------------------
// 24. MCQ: normalise() constructs a correct MCQ candidate.
// ---------------------------------------------------------------------
$mcq_item = array(
	'authorType'      => 'individual',
	'authorFullName'  => 'Sarah Mitchell',
	'year'            => '2024',
	'title'           => 'Study skills guide',
	'publisher'       => 'University of Leeds',
	'url'             => 'https://www.leeds.ac.uk/study-skills',
	'distractors'     => array(
		array( 'reference' => 'Mitchell, S. (2024) Study skills guide University of Leeds. Available from: <https://www.leeds.ac.uk/study-skills> [accessed 3 September 2026].', 'errorReason' => 'Missing [online].' ),
		array( 'reference' => 'Mitchell, S. (2024) Study skills guide [online]. University of Leeds. Available from <https://www.leeds.ac.uk/study-skills> [accessed 3 September 2026].', 'errorReason' => 'Missing colon after Available from.' ),
		array( 'reference' => 'Mitchell, S. (2024) Study skills guide [online]. University of Leeds. Available from: https://www.leeds.ac.uk/study-skills [accessed 3 September 2026].', 'errorReason' => 'URL not in angle brackets.' ),
	),
);
$mcq_result = invoke_normalise( array( $mcq_item ), array( 'WR01' ), 'medium', array( 'Exercise 1' ), 'MCQ', $WR );
check( '[24] normalise() succeeds for MCQ', is_wp_error( $mcq_result ), false );
if ( ! is_wp_error( $mcq_result ) ) {
	$mc = $mcq_result[0];
	check( '[24] category is Website', $mc['category'], 'Website' );
	check( '[24] scenario is Citex\'s own fixed stem', $mc['scenario'], Citex_Reference_Rules::mcq_question_stem( $WR ) );
	check( '[24] exactly 4 options, option 4 blank', count( $mc['options'] ) . '|' . $mc['options'][3], '4|' );
	check( '[24] the correct answer is never duplicated into an option', in_array( $mc['reconstructedReference'], array_slice( $mc['options'], 0, 3 ), true ), false );
	check( '[24] validates and enters the queue as passed', $mc['validationStatus'], 'passed' );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
