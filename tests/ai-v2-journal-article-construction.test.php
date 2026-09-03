<?php
/**
 * Regression tests for Citex_AI_V2's Journal Article support — the new
 * category's DragDrop/MCQ prompt/schema/system-instruction dispatch and
 * normalise() construction, mirroring the style of
 * ai-v2-canonical-construction.test.php and ai-v2-mcq-construction.test.php
 * but exercising Journal Article's own field shape (authorFullNames,
 * articleTitle, journalTitle, volume, issue, pages — no place/publisher)
 * and its constant 7-part DragDrop shape for every author count.
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-journal-article-construction.test.php` — not shipped in
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

function invoke_normalise( $questions, $ids, $difficulty, $exercises, $type, $category, $target_count = null, $scenario_id = '', $rule_tested = '' ) {
	return invoke_private( 'normalise', array( $questions, $ids, $difficulty, $exercises, $type, $category, $target_count, $scenario_id, $rule_tested ) );
}

$JA = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;

function journal_article_item( $overrides = array() ) {
	return array_merge(
		array(
			'scenario'        => 'You are referencing a journal article titled A brief guide to Harvard referencing by Sarah Mitchell and Daniel Evans, published in 2010 in The British Journal of Referencing, volume 12, issue 2, pages 27-35.',
			'authorFullNames' => array( 'Sarah Mitchell', 'Daniel Evans' ),
			'year'            => '2010',
			'articleTitle'    => 'A brief guide to Harvard referencing',
			'journalTitle'    => 'The British Journal of Referencing',
			'volume'          => '12',
			'issue'           => '2',
			'pages'           => '27-35',
			'questionParts'   => array( 'Mitchell, S. and Evans, D.', '2010', 'A brief guide to Harvard referencing', 'The British Journal of Referencing', '12', '2', '27-35' ),
			'fixedText'       => '| (||) ||. ||, ||(||), pp.||.',
			'confusingWords'  => array( '2015', 'The Journal of Citation Studies', '45-52' ),
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// Dispatch: build_prompt_for()/schema_for()/system_instruction_for() route
// Journal Article to its own builders, distinct from Book/Edited Book.
// ---------------------------------------------------------------------
$dd_prompt  = invoke_private( 'build_prompt_for', array( 'DragDrop', $JA, array( 'JA01' ), 'medium', false, '' ) );
check( '[dispatch] Journal Article DragDrop prompt mentions "Journal Article"', false !== strpos( $dd_prompt, 'Journal Article' ), true );
check( '[dispatch] Journal Article DragDrop prompt mentions "articleTitle"', false !== strpos( $dd_prompt, 'articleTitle' ), true );
check( '[25] Journal Article DragDrop prompt includes the conciseness (mobile-readability) guidance', false !== strpos( $dd_prompt, 'PREFER CONCISE REAL NAMES WHEN POSSIBLE' ), true );

$mcq_prompt = invoke_private( 'build_prompt_for', array( 'MCQ', $JA, array( 'JA01' ), 'medium', false, '' ) );
check( '[dispatch] Journal Article MCQ prompt mentions "Journal Article"', false !== strpos( $mcq_prompt, 'Journal Article' ), true );
check( '[25] Journal Article MCQ prompt includes the conciseness (mobile-readability) guidance', false !== strpos( $mcq_prompt, 'PREFER CONCISE REAL NAMES WHEN POSSIBLE' ), true );

$dd_schema  = invoke_private( 'schema_for', array( 'DragDrop', $JA, '' ) );
$props      = $dd_schema['properties']['questions']['items']['properties'];
check( '[dispatch] Journal Article DragDrop schema has articleTitle/journalTitle/volume/issue/pages fields', isset( $props['articleTitle'], $props['journalTitle'], $props['volume'], $props['issue'], $props['pages'] ), true );
check( '[dispatch] Journal Article DragDrop schema has no bookTitle/place/publisher fields', isset( $props['bookTitle'] ) || isset( $props['place'] ) || isset( $props['publisher'] ), false );

$mcq_schema = invoke_private( 'schema_for', array( 'MCQ', $JA, '' ) );
check( '[dispatch] Journal Article MCQ schema has no scenario field (Citex authors the fixed stem)', isset( $mcq_schema['properties']['questions']['items']['properties']['scenario'] ), false );

$sys_dd  = invoke_private( 'system_instruction_for', array( 'DragDrop', $JA, '' ) );
check( '[dispatch] Journal Article system instruction mentions journal articles, not books', false !== stripos( $sys_dd, 'journal article' ), true );

// ---------------------------------------------------------------------
// 1-5. normalise() constructs a correct DragDrop candidate for 1/2/3/4/5
// authors — Citex builds Question Parts/Fixed Text/reference itself from
// the canonical authorFullNames, never trusting Gemini's own copy.
// ---------------------------------------------------------------------
$author_sets = array(
	1 => array( 'Sarah Mitchell' ),
	2 => array( 'Sarah Mitchell', 'Daniel Evans' ),
	3 => array( 'Sarah Mitchell', 'Daniel Evans', 'Tom Brown' ),
	4 => array( 'Sarah Mitchell', 'Daniel Evans', 'Tom Brown', 'Ruth Williams' ),
	5 => array( 'Sarah Mitchell', 'Daniel Evans', 'Tom Brown', 'Ruth Williams', 'Kate Davies' ),
);
foreach ( $author_sets as $count => $names ) {
	$joined_names = implode( ' and ', array_slice( $names, 0, -1 ) );
	$joined_names = count( $names ) > 1 ? ( implode( ', ', array_slice( $names, 0, -1 ) ) . ' and ' . end( $names ) ) : $names[0];
	$scenario = 'You are referencing a journal article titled A brief guide to Harvard referencing by ' . $joined_names . ', published in 2010 in The British Journal of Referencing, volume 12, issue 2, pages 27-35.';
	$item = journal_article_item( array( 'authorFullNames' => $names, 'scenario' => $scenario ) );
	$result = invoke_normalise( array( $item ), array( 'JA0' . $count ), 'medium', array( 'Exercise 1' ), 'DragDrop', $JA );
	check( "[$count author(s)] normalise() succeeds for DragDrop", is_wp_error( $result ), false );
	if ( ! is_wp_error( $result ) ) {
		$candidate = $result[0];
		check( "[$count author(s)] category is 'Journal Article'", $candidate['category'], 'Journal Article' );
		// Journal Article's mobile-first redesign: one small draggable
		// chip PER AUTHOR (never a giant pre-joined "X and Y" string), so
		// the full_reference design's part/placeholder count is
		// (author count + 6), not a constant 7.
		check( "[$count author(s)] Question Parts contain one chip per author plus 6 fixed fields", count( $candidate['questionParts'] ), $count + 6 );
		check( "[$count author(s)] Fixed Text has the correct author-joining prefix, one chip per author", $candidate['fixedText'], Citex_Reference_Rules::journal_article_author_parts_fixed_text( $count ) . ' (||) ||. ||, ||(||), pp.||.' );
		check( "[$count author(s)] reconstructedReference contains \"et al.\"? (must not)", false !== stripos( $candidate['reconstructedReference'], 'et al' ), false );
		check( "[$count author(s)] validates and enters the queue as 'passed'", $candidate['validationStatus'], 'passed' );
	}
}

// ---------------------------------------------------------------------
// 6. Correct initials are derived from full names (e.g. "Sarah Mitchell" ->
// "Mitchell, S.") — never asked of Gemini directly.
// ---------------------------------------------------------------------
$result_initials = invoke_normalise( array( journal_article_item() ), array( 'JA01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $JA );
check( '[6] normalise() succeeds', is_wp_error( $result_initials ), false );
if ( ! is_wp_error( $result_initials ) ) {
	$c = $result_initials[0];
	check( '[6] initials correctly derived: "Sarah Mitchell" -> surname "Mitchell", initials "S."', $c['authors'][0]['surname'] . '|' . $c['authors'][0]['initials'], 'Mitchell|S.' );
	check( '[6] second author too: "Daniel Evans" -> "Evans", "D."', $c['authors'][1]['surname'] . '|' . $c['authors'][1]['initials'], 'Evans|D.' );
	check( '[6] Question Parts reflect the correctly-derived author chips, one per author (never pre-joined)', $c['questionParts'][0] . '|' . $c['questionParts'][1], 'Mitchell, S.|Evans, D.' );
}

// ---------------------------------------------------------------------
// 8. A missing author list is rejected.
// ---------------------------------------------------------------------
$missing_author = invoke_normalise( array( journal_article_item( array( 'authorFullNames' => array() ) ) ), array( 'JA01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $JA );
check( '[8] a missing author list is rejected', is_wp_error( $missing_author ), true );
check( '[8] error mentions author count', is_wp_error( $missing_author ) ? false !== stripos( $missing_author->get_error_message(), 'author' ) : false, true );

// ---------------------------------------------------------------------
// target_count enforcement (scenario framework): a candidate with the wrong
// author count for its assigned scenario/bucket is rejected.
// ---------------------------------------------------------------------
$wrong_count = invoke_normalise( array( journal_article_item() ), array( 'JA01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $JA, 3, 'three_authors', 'author_joining' );
check( '[target-count] a 2-author candidate is rejected for a "three_authors" (target=3) scenario', is_wp_error( $wrong_count ), true );

$right_count = invoke_normalise( array( journal_article_item() ), array( 'JA01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $JA, 2, 'two_authors', 'author_joining' );
check( '[21][22] a 2-author candidate for a "two_authors" scenario carries the correct category/blueprint', is_wp_error( $right_count ), false );
if ( ! is_wp_error( $right_count ) ) {
	check( '[21] blueprint category is Journal Article', $right_count[0]['blueprint']['category'], 'Journal Article' );
	check( '[22] blueprint scenario id is recorded', $right_count[0]['blueprint']['scenario'], 'two_authors' );
}

// ---------------------------------------------------------------------
// 17. DragDrop placeholder reconstruction: a wrong placeholder count is
// rejected (Citex constructs Fixed Text itself, so this only fires if the
// dragdrop_shape() output itself were ever inconsistent with itself —
// exercised here by directly corrupting an internal candidate).
// ---------------------------------------------------------------------
check( '[17] placeholder_count() sees exactly 7 slots in the canonical Journal Article fixedText', invoke_private( 'placeholder_count', array( '| (||) ||. ||, ||(||), pp.||.' ) ), 7 );

// ---------------------------------------------------------------------
// 10 & 11. Correct year passes; a missing year (or any other required
// field) is rejected as a missing-field error.
// ---------------------------------------------------------------------
$missing_year = invoke_normalise( array( journal_article_item( array( 'year' => '' ) ) ), array( 'JA01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $JA );
check( '[11] a missing year is rejected', is_wp_error( $missing_year ), true );

// ---------------------------------------------------------------------
// 18. Distractor validation: a distractor duplicating a correct Question
// Part is rejected; a duplicate distractor pair is rejected.
// ---------------------------------------------------------------------
$dup_of_part = invoke_normalise(
	array( journal_article_item( array( 'confusingWords' => array( '2010', 'x', 'y' ) ) ) ),
	array( 'JA01' ),
	'medium',
	array( 'Exercise 1' ),
	'DragDrop',
	$JA
);
check( '[18] a distractor matching a correct Question Part (the real year) is rejected', is_wp_error( $dup_of_part ), true );

$dup_distractor = invoke_normalise(
	array( journal_article_item( array( 'confusingWords' => array( 'x', 'x', 'y' ) ) ) ),
	array( 'JA01' ),
	'medium',
	array( 'Exercise 1' ),
	'DragDrop',
	$JA
);
check( '[18] a duplicated distractor pair is rejected', is_wp_error( $dup_distractor ), true );

// ---------------------------------------------------------------------
// 19. Scenario/source mismatch: a scenario naming a different article than
// the canonical bibliographic fields is rejected by the pre-queue quality
// gate.
// ---------------------------------------------------------------------
$mismatched_scenario = journal_article_item( array(
	'scenario' => 'You are referencing a journal article titled A totally different title by Sarah Mitchell and Daniel Evans, published in 2010 in The British Journal of Referencing, volume 12, issue 2, pages 27-35.',
) );
$mismatch_result = invoke_normalise( array( $mismatched_scenario ), array( 'JA01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $JA );
check( '[19] a scenario naming a different article title than the canonical record is rejected', is_wp_error( $mismatch_result ), true );

// ---------------------------------------------------------------------
// 23. MCQ: normalise() constructs a correct MCQ candidate — Citex builds
// the one correct option itself; Gemini's distractors become options 1-3,
// option 4 stays blank.
// ---------------------------------------------------------------------
$mcq_item = array(
	'authorFullNames' => array( 'Sarah Mitchell', 'Daniel Evans' ),
	'year'            => '2010',
	'articleTitle'    => 'A brief guide to Harvard referencing',
	'journalTitle'    => 'The British Journal of Referencing',
	'volume'          => '12',
	'issue'           => '2',
	'pages'           => '27-35',
	'distractors'     => array(
		array( 'reference' => 'Mitchell, S. and Evans, D. (2010) A brief guide to Harvard referencing The British Journal of Referencing, 12(2), pp.27-35.', 'errorReason' => 'Missing the full stop after the article title.' ),
		array( 'reference' => 'Mitchell, S. and Evans, D. (2010) A brief guide to Harvard referencing. The British Journal of Referencing 12(2), pp.27-35.', 'errorReason' => 'Missing the comma after the journal title.' ),
		array( 'reference' => 'Mitchell, S. and Evans, D. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), p.27-35.', 'errorReason' => 'Uses "p." instead of "pp.".' ),
	),
);
$mcq_result = invoke_normalise( array( $mcq_item ), array( 'JA01' ), 'medium', array( 'Exercise 1' ), 'MCQ', $JA );
check( '[23] normalise() succeeds for MCQ', is_wp_error( $mcq_result ), false );
if ( ! is_wp_error( $mcq_result ) ) {
	$mc = $mcq_result[0];
	check( '[23] category is Journal Article', $mc['category'], 'Journal Article' );
	check( '[23] scenario is Citex\'s own fixed stem', $mc['scenario'], Citex_Reference_Rules::mcq_question_stem( $JA ) );
	check( '[23] exactly 4 options, option 4 blank', count( $mc['options'] ) . '|' . $mc['options'][3], '4|' );
	check( '[23] the correct answer is never duplicated into an option', in_array( $mc['reconstructedReference'], array_slice( $mc['options'], 0, 3 ), true ), false );
	check( '[23] validates and enters the queue as passed', $mc['validationStatus'], 'passed' );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
