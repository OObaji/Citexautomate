<?php
/**
 * End-to-end regression tests for Citex_AI_V2::generate_questions() itself
 * (not just normalise() via reflection, as every other ai-v2 test file
 * does) — the one place that wires together scenario resolution
 * (Citex_Question_Scenarios), the author/editor-count instruction and
 * enforcement it drives, blueprint attachment, the duplicate-reference
 * similarity guard, and scenario-history recording
 * (Citex_Question_Diversity). Stubs wp_remote_post() to return a canned
 * Gemini-shaped response instead of making a real HTTP call.
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-scenario-generation.test.php` — not shipped in
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
function absint( $v ) {
	return abs( intval( $v ) );
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}

$GLOBALS['__options']       = array();
$GLOBALS['__next_response'] = null;
function get_option( $key, $default = false ) {
	return $GLOBALS['__options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}
function wp_remote_post( $url, $args ) {
	if ( is_callable( $GLOBALS['__next_response'] ) ) {
		return ( $GLOBALS['__next_response'] )( $url, $args );
	}
	return $GLOBALS['__next_response'];
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? 200;
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
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

function reset_environment() {
	$GLOBALS['__options']       = array( Citex_AI_V2::OPTION_API_KEY => 'test-key' );
	$GLOBALS['__next_response'] = null;
}

/** Canned Gemini "output_text" shaped response for a fixed set of Book MCQ questions. */
function gemini_response_for( $questions ) {
	$body = wp_json_encode( array( 'questions' => $questions ) );
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array( 'output_text' => $body ) ),
	);
}

function book_mcq_question( $id_index, $author_names, $book_title = 'Understanding digital culture' ) {
	return array(
		'questionId'      => 'BK0' . $id_index,
		'authorFullNames' => $author_names,
		'year'            => '2020',
		'bookTitle'       => $book_title,
		'place'           => 'London',
		'publisher'       => 'SAGE Publications',
		'distractors'     => array(
			array( 'reference' => 'Wrong, X (2020) ' . $book_title . '. London: SAGE Publications.', 'errorReason' => 'Missing comma and initials formatting.' ),
			array( 'reference' => 'Also Wrong, Y. 2020 ' . $book_title . '. London: SAGE Publications.', 'errorReason' => 'Missing parentheses around the year.' ),
			array( 'reference' => 'Still Wrong, Z. (2020) ' . $book_title . '. SAGE Publications: London.', 'errorReason' => 'Place and publisher swapped.' ),
		),
	);
}

// ---------------------------------------------------------------------
// 1. A "two_authors" scenario request: the prompt gets a target-count
// instruction, Gemini's response is enforced to have exactly 2 authors,
// and the resulting candidate carries a blueprint naming the scenario and
// its rule.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__next_response'] = gemini_response_for( array( book_mcq_question( 1, array( 'John Smith', 'Amy Jones' ) ) ) );
$result = Citex_AI_V2::generate_questions( array(
	'quantity'    => 1,
	'starting_id' => 'BK01',
	'difficulty'  => 'medium',
	'type'        => 'mcq',
	'category'    => 'book',
	'scenario'    => 'two_authors',
) );
check( '[1] generation succeeds for a two_authors scenario request', is_wp_error( $result ), false );
if ( ! is_wp_error( $result ) ) {
	$candidate = $result[0];
	check( '[1] blueprint category is Book', $candidate['blueprint']['category'], 'Book' );
	check( '[1] blueprint questionType is MCQ', $candidate['blueprint']['questionType'], 'MCQ' );
	check( '[1] blueprint scenario is two_authors', $candidate['blueprint']['scenario'], 'two_authors' );
	check( '[1] blueprint ruleTested is author_joining', $candidate['blueprint']['ruleTested'], 'author_joining' );
	check( '[1] blueprint difficulty is Medium', $candidate['blueprint']['difficulty'], 'Medium' );
	check( '[1] the reconstructed reference joins both authors correctly', $candidate['reconstructedReference'], 'Smith, J. and Jones, A. (2020) Understanding digital culture. London: SAGE Publications.' );
}

// ---------------------------------------------------------------------
// 2. Author-count enforcement: a "two_authors" scenario request where
// Gemini supplies only 1 author must be rejected (and, since it never
// succeeds within MAX_QUALITY_ATTEMPTS, the whole call fails) — the
// scenario assignment is authoritative, not just a hint.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__next_response'] = gemini_response_for( array( book_mcq_question( 2, array( 'John Smith' ) ) ) );
$mismatched = Citex_AI_V2::generate_questions( array(
	'quantity'    => 1,
	'starting_id' => 'BK02',
	'difficulty'  => 'medium',
	'type'        => 'mcq',
	'category'    => 'book',
	'scenario'    => 'two_authors',
) );
check( '[2] a scenario/author-count mismatch is rejected (not silently accepted)', is_wp_error( $mismatched ), true );
check( '[2] error names the generation failure after exhausting retries', is_wp_error( $mismatched ) ? $mismatched->get_error_code() : null, 'citex_ai_generation_failed' );

// ---------------------------------------------------------------------
// 3. No scenario at all (any caller outside Citex_Generator's own
// scenario-group loop): author count is left completely unconstrained —
// the exact pre-framework behaviour — and the candidate still carries a
// valid (empty-scenario) blueprint rather than erroring.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__next_response'] = gemini_response_for( array( book_mcq_question( 3, array( 'John Smith', 'Amy Jones', 'Tom Brown' ) ) ) );
$no_scenario = Citex_AI_V2::generate_questions( array(
	'quantity'    => 1,
	'starting_id' => 'BK03',
	'difficulty'  => 'medium',
	'type'        => 'mcq',
	'category'    => 'book',
) );
check( '[3] omitting scenario leaves author count unconstrained (3 authors succeeds)', is_wp_error( $no_scenario ), false );
if ( ! is_wp_error( $no_scenario ) ) {
	check( '[3] blueprint scenario is empty when none was assigned', $no_scenario[0]['blueprint']['scenario'], '' );
	check( '[3] blueprint ruleTested is empty when none was assigned', $no_scenario[0]['blueprint']['ruleTested'], '' );
}

// ---------------------------------------------------------------------
// 4. Duplicate-reference guard: a real book already in the pending queue
// (passed as existing_references) must not be regenerated — the request
// fails rather than silently adding a second question for the same book.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__next_response'] = gemini_response_for( array( book_mcq_question( 4, array( 'John Smith' ), 'Already Used Book' ) ) );
$duplicate = Citex_AI_V2::generate_questions( array(
	'quantity'             => 1,
	'starting_id'          => 'BK04',
	'difficulty'           => 'medium',
	'type'                 => 'mcq',
	'category'             => 'book',
	'existing_references'  => array( 'Smith, J. (2020) Already Used Book. London: SAGE Publications.' ),
) );
check( '[4] a reference duplicating an existing pending question is rejected', is_wp_error( $duplicate ), true );
check( '[4] error names the generation failure', is_wp_error( $duplicate ) ? $duplicate->get_error_code() : null, 'citex_ai_generation_failed' );
check( '[4] the failure message names the duplicate quality issue', is_wp_error( $duplicate ) ? ( false !== strpos( $duplicate->get_error_message(), 'duplicates one already in the pending queue' ) ) : false, true );

// ---------------------------------------------------------------------
// 5. A successful scenario-driven generation records history — the next
// assign_scenarios() call for the same category/type deprioritises the
// scenario just generated.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__next_response'] = gemini_response_for( array( book_mcq_question( 5, array( 'John Smith' ) ) ) );
$history_before = Citex_Question_Diversity::get_history( Citex_Reference_Rules::CATEGORY_BOOK );
check( '[5] no history before this generation', count( $history_before ), 0 );
$recorded = Citex_AI_V2::generate_questions( array(
	'quantity'    => 1,
	'starting_id' => 'BK05',
	'difficulty'  => 'medium',
	'type'        => 'mcq',
	'category'    => 'book',
	'scenario'    => 'one_author',
) );
check( '[5] generation succeeds', is_wp_error( $recorded ), false );
$history_after = Citex_Question_Diversity::get_history( Citex_Reference_Rules::CATEGORY_BOOK );
check( '[5] exactly one history entry was recorded', count( $history_after ), 1 );
check( '[5] the recorded entry names the one_author scenario', $history_after[0]['scenario'], 'one_author' );
$next_assignment = Citex_Question_Diversity::assign_scenarios( Citex_Reference_Rules::CATEGORY_BOOK, 'MCQ', 1 );
check( '[5] the next scenario assignment avoids the just-generated one_author scenario', $next_assignment[0], 'two_authors' );

// ---------------------------------------------------------------------
// 6. The "identify_error" scenario routes through generate_questions() to
// its own prompt/schema/system-instruction/normaliser end-to-end — a
// completely different Gemini response shape (brokenReference +
// wrongDescriptions, no `distractors` array at all) from every other MCQ
// scenario, proving build_prompt_for()/schema_for()/system_instruction_for()
// correctly dispatch on scenario id, not just type/category.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__next_response'] = gemini_response_for( array( array(
	'questionId'      => 'BK06',
	'authorFullNames' => array( 'Alan Bryman' ),
	'year'            => '2012',
	'bookTitle'       => 'Social Research Methods',
	'place'           => 'Oxford',
	'publisher'       => 'Oxford University Press',
	'brokenReference' => array(
		'reference'   => 'Bryman A. (2012) Social Research Methods. Oxford: Oxford University Press.',
		'errorReason' => 'Uses the author\'s full first name instead of initials, and is missing the comma after the surname.',
	),
	'wrongDescriptions' => array(
		'The publication year is not enclosed in parentheses.',
		'The place of publication and publisher have been swapped.',
		'The book title is missing its final full stop.',
	),
) ) );
$identify_error_result = Citex_AI_V2::generate_questions( array(
	'quantity'    => 1,
	'starting_id' => 'BK06',
	'difficulty'  => 'medium',
	'type'        => 'mcq',
	'category'    => 'book',
	'scenario'    => 'identify_error',
) );
check( '[6] generation succeeds for the identify_error scenario', is_wp_error( $identify_error_result ), false );
if ( ! is_wp_error( $identify_error_result ) ) {
	$identify_error_candidate = $identify_error_result[0];
	check( '[6] mcqPattern is identify_error', $identify_error_candidate['mcqPattern'], 'identify_error' );
	check( '[6] blueprint scenario is identify_error', $identify_error_candidate['blueprint']['scenario'], 'identify_error' );
	check( '[6] the Answer field holds the true description, not a reference', $identify_error_candidate['reconstructedReference'], 'Uses the author\'s full first name instead of initials, and is missing the comma after the surname.' );
}

// ---------------------------------------------------------------------
// 7. The "choose_treatment_*" scenario routes through generate_questions()
// to its own prompt/schema/system-instruction/normaliser end-to-end — an
// even simpler Gemini response shape than identify_error (no bibliographic
// fields at all, just wrongStatements), proving the dispatch generalises
// to a genuinely different mechanic shape, not just two special cases.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__next_response'] = gemini_response_for( array( array(
	'questionId'      => 'BK07',
	'wrongStatements' => array(
		'The first author is listed and the rest are shortened to et al.',
		'Only the first three authors are listed; the rest are omitted.',
		'Authors are joined with an ampersand (&) instead of commas and "and".',
	),
) ) );
$treatment_result = Citex_AI_V2::generate_questions( array(
	'quantity'    => 1,
	'starting_id' => 'BK07',
	'difficulty'  => 'medium',
	'type'        => 'mcq',
	'category'    => 'book',
	'scenario'    => 'choose_treatment_four_or_more_authors',
) );
check( '[7] generation succeeds for the choose_treatment_four_or_more_authors scenario', is_wp_error( $treatment_result ), false );
if ( ! is_wp_error( $treatment_result ) ) {
	$treatment_candidate = $treatment_result[0];
	check( '[7] mcqPattern is choose_treatment', $treatment_candidate['mcqPattern'], 'choose_treatment' );
	check( '[7] blueprint scenario is the full choose_treatment_ scenario id', $treatment_candidate['blueprint']['scenario'], 'choose_treatment_four_or_more_authors' );
	check( '[7] the Answer field holds Citex\'s own fixed true statement', $treatment_candidate['reconstructedReference'], 'All authors should be included; et al. is not used in the reference list.' );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
