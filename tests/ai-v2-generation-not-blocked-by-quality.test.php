<?php
/**
 * Proves this sprint's core change end-to-end across the authoritative
 * 8-combination test matrix (Book/Edited Book/Journal Article/Website x
 * DragDrop/MCQ): a candidate that would have failed today's quality gate
 * (an oversized/mobile-unsuitable component, a distractor duplicating a
 * correct part, a duplicate distractor, or an MCQ option colliding with
 * the correct answer) is no longer rejected by normalise() while
 * Citex_AI_V2::QUALITY_GATE_ENABLED is false — GENERATE -> NORMALISE ->
 * STORE, not GENERATE -> VALIDATE -> RETRY -> STORE. This does not weaken
 * Citex_Generated_Validator or Citex_Reference_Rules themselves — only
 * this file's own dedicated checks (in
 * tests/generated-validator-*.test.php, which call
 * Citex_Generated_Validator::validate() directly and are untouched by
 * this sprint) prove those rules are still fully enforced.
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-generation-not-blocked-by-quality.test.php` — not
 * shipped in citex-tools.zip.
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
function check_true( $description, $actual ) {
	check( $description, $actual, true );
}

function invoke_normalise( $questions, $ids, $difficulty, $exercises, $type, $category, $target_count = null, $scenario_id = '', $rule_tested = '', $exercise_design = 'full_reference' ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, array( $questions, $ids, $difficulty, $exercises, $type, $category, $target_count, $scenario_id, $rule_tested, $exercise_design ) );
}

$BOOK = Citex_Reference_Rules::CATEGORY_BOOK;
$EB   = Citex_Reference_Rules::CATEGORY_EDITED_BOOK;
$JA   = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;
$WR   = Citex_Reference_Rules::CATEGORY_WEBSITE;

// ---------------------------------------------------------------------
// 0. Sanity: the flags this whole sprint hinges on.
// ---------------------------------------------------------------------
check( '[0] QUALITY_GATE_ENABLED is false', Citex_AI_V2::QUALITY_GATE_ENABLED, false );
check( '[0] MAX_GENERATION_ATTEMPTS is 2', Citex_AI_V2::MAX_GENERATION_ATTEMPTS, 2 );
check( '[0] web_verification_enabled() defaults to false', Citex_AI_V2::web_verification_enabled(), false );

// =======================================================================
// 1. Book, DragDrop — a distractor duplicating the correct year (would
// have failed 'citex_ai_distractor_matches_part').
// =======================================================================
$book_dragdrop = array(
	'scenario'        => 'You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.',
	'authorFullNames' => array( 'Alan Bryman' ),
	'year'             => '2012',
	'bookTitle'        => 'Social Research Methods',
	'place'            => 'Oxford',
	'publisher'        => 'Oxford University Press',
	'confusingWords'   => array( '2012', 'London', 'Sage' ),
);
$r1 = invoke_normalise( array( $book_dragdrop ), array( 'BK01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $BOOK );
check( '[1] Book DragDrop: a distractor duplicating a correct part no longer blocks generation', is_wp_error( $r1 ), false );

// =======================================================================
// 2. Book, MCQ — an "incorrect" option identical to the correct one
// (would have failed 'citex_ai_mcq_option_matches_correct').
// =======================================================================
$book_mcq = array(
	'scenario'         => 'You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.',
	'authorFullNames'  => array( 'Alan Bryman' ),
	'year'              => '2012',
	'bookTitle'         => 'Social Research Methods',
	'place'             => 'Oxford',
	'publisher'         => 'Oxford University Press',
	'distractors'       => array(
		array( 'reference' => 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.', 'errorReason' => 'Identical to the correct answer.' ),
		array( 'reference' => 'A. Bryman (2012) Social Research Methods. Oxford: Oxford University Press.', 'errorReason' => 'Places the initials before the surname.' ),
		array( 'reference' => 'Bryman, A. (2012) Social Research Methods. Oxford:Oxford University Press.', 'errorReason' => 'Missing the space after the colon.' ),
	),
);
$r2 = invoke_normalise( array( $book_mcq ), array( 'BK02' ), 'medium', array(), 'MCQ', $BOOK );
check( '[2] Book MCQ: an incorrect option identical to the correct one no longer blocks generation', is_wp_error( $r2 ), false );

// =======================================================================
// 3. Edited Book, DragDrop — two identical distractors (would have failed
// 'citex_ai_duplicate_distractor').
// =======================================================================
$eb_dragdrop = array(
	'scenario'        => 'You are referencing a book edited by Vincent Miller, titled Understanding digital culture, published in 2020 by SAGE Publications in London.',
	'editorFullNames' => array( 'Vincent Miller' ),
	'year'             => '2020',
	'bookTitle'        => 'Understanding digital culture',
	'place'            => 'London',
	'publisher'        => 'SAGE Publications',
	'confusingWords'   => array( 'author', 'author', '2019' ),
);
$r3 = invoke_normalise( array( $eb_dragdrop ), array( 'EB01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $EB );
check( '[3] Edited Book DragDrop: a duplicated distractor pair no longer blocks generation', is_wp_error( $r3 ), false );

// =======================================================================
// 4. Edited Book, MCQ — a duplicate incorrect option (would have failed
// 'citex_ai_mcq_duplicate_option').
// =======================================================================
$eb_mcq = array(
	'scenario'         => 'You are referencing a book edited by Vincent Miller, titled Understanding digital culture, published in 2020 by SAGE Publications in London.',
	'editorFullNames'  => array( 'Vincent Miller' ),
	'year'              => '2020',
	'bookTitle'         => 'Understanding digital culture',
	'place'             => 'London',
	'publisher'         => 'SAGE Publications',
	'distractors'       => array(
		array( 'reference' => 'Miller, V. (ed.) 2020 Understanding digital culture. London: SAGE Publications.', 'errorReason' => 'Missing parentheses around the year.' ),
		array( 'reference' => 'Miller, V. (ed.) 2020 Understanding digital culture. London: SAGE Publications.', 'errorReason' => 'Duplicate of the previous option.' ),
		array( 'reference' => 'Miller, V. (eds) (2020) Understanding digital culture. London: SAGE Publications.', 'errorReason' => 'Wrong editor designation for a single editor.' ),
	),
);
$r4 = invoke_normalise( array( $eb_mcq ), array( 'EB02' ), 'medium', array(), 'MCQ', $EB );
check( '[4] Edited Book MCQ: a duplicate incorrect option no longer blocks generation', is_wp_error( $r4 ), false );

// =======================================================================
// 5. Journal Article, DragDrop — an oversized/mobile-unsuitable component
// (would have failed 'citex_ai_journal_article_mobile_unsuitable').
// =======================================================================
$ja_dragdrop = array(
	'scenario'        => 'You are referencing an article titled Learning Online by Anna Smith, published in 2020 in a journal with an extremely long name, volume 12, issue 3, pages 45-52.',
	'authorFullNames' => array( 'Anna Smith' ),
	'year'             => '2020',
	'articleTitle'     => 'Learning Online',
	'journalTitle'     => 'International Multidisciplinary Journal of Advanced Interdisciplinary Educational Research and Practice Studies',
	'volume'           => '12',
	'issue'            => '3',
	'pages'            => '45-52',
	'confusingWords'   => array( '2019', 'Different Journal', '99-100' ),
);
$r5 = invoke_normalise( array( $ja_dragdrop ), array( 'JA01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $JA, null, '', '', 'author_year_journal' );
check( '[5] Journal Article DragDrop: an oversized component no longer blocks generation', is_wp_error( $r5 ), false );
check_true( '[5] Citex_Reference_Rules::journal_article_mobile_suitability() itself still flags the same component (rule not weakened)', ! is_wp_error( $r5 ) && null !== Citex_Reference_Rules::journal_article_mobile_suitability( $r5[0]['questionParts'] ) );

// =======================================================================
// 6. Journal Article, MCQ — same oversized-component problem applied to a
// short-segment MCQ design (would have failed
// 'citex_ai_journal_article_mobile_unsuitable').
// =======================================================================
$ja_mcq = array(
	'scenario'        => 'You are referencing an article titled Learning Online by Anna Smith, published in 2020 in a journal with an extremely long name, volume 12, issue 3, pages 45-52.',
	'authorFullNames' => array( 'Anna Smith' ),
	'year'             => '2020',
	'articleTitle'     => 'Learning Online',
	'journalTitle'     => 'International Multidisciplinary Journal of Advanced Interdisciplinary Educational Research and Practice Studies',
	'volume'           => '12',
	'issue'            => '3',
	'pages'            => '45-52',
	'distractors'      => array(
		array( 'reference' => 'Smith, A., 2019, 3.', 'errorReason' => 'Wrong year.' ),
		array( 'reference' => 'Smith, A., 2020, 4.', 'errorReason' => 'Wrong issue.' ),
		array( 'reference' => 'Smyth, A., 2020, 3.', 'errorReason' => 'Misspelled surname.' ),
	),
);
$r6 = invoke_normalise( array( $ja_mcq ), array( 'JA02' ), 'medium', array(), 'MCQ', $JA, null, '', '', 'author_year_issue' );
check( '[6] Journal Article MCQ: an oversized component no longer blocks generation', is_wp_error( $r6 ), false );

// =======================================================================
// 7. Website, DragDrop — a distractor duplicating the correct year (would
// have failed 'citex_ai_distractor_matches_part').
// =======================================================================
$wr_dragdrop = array(
	'scenario'         => 'You are referencing a webpage written by Sarah Mitchell in 2024 and published by the University of Leeds, titled Study skills guide, at https://www.leeds.ac.uk/study-skills.',
	'authorType'       => 'individual',
	'authorFullName'   => 'Sarah Mitchell',
	'year'              => '2024',
	'title'             => 'Study skills guide',
	'publisher'         => 'University of Leeds',
	'url'               => 'https://www.leeds.ac.uk/study-skills',
	'confusingWords'    => array( '2024', 'London Metropolitan University', 'https://www.leeds.ac.uk/wrong-page' ),
);
$r7 = invoke_normalise( array( $wr_dragdrop ), array( 'WR01' ), 'medium', array( 'Exercise 1' ), 'DragDrop', $WR, null, '', '', 'author_year_title' );
check( '[7] Website DragDrop: a distractor duplicating a correct part no longer blocks generation', is_wp_error( $r7 ), false );

// =======================================================================
// 8. Website, MCQ — a duplicate incorrect option (would have failed
// 'citex_ai_mcq_duplicate_option').
// =======================================================================
$wr_mcq = array(
	'scenario'         => 'You are referencing a webpage written by Sarah Mitchell in 2024 and published by the University of Leeds, titled Study skills guide, at https://www.leeds.ac.uk/study-skills.',
	'authorType'       => 'individual',
	'authorFullName'   => 'Sarah Mitchell',
	'year'              => '2024',
	'title'             => 'Study skills guide',
	'publisher'         => 'University of Leeds',
	'url'               => 'https://www.leeds.ac.uk/study-skills',
	'distractors'       => array(
		array( 'reference' => 'Mitchell, S. (2024) Study skills guide. University of Leeds. Available from: <https://www.leeds.ac.uk/study-skills> [accessed 3 September 2026].', 'errorReason' => 'Missing [online].' ),
		array( 'reference' => 'Mitchell, S. (2024) Study skills guide. University of Leeds. Available from: <https://www.leeds.ac.uk/study-skills> [accessed 3 September 2026].', 'errorReason' => 'Duplicate of the previous option.' ),
		array( 'reference' => 'Mitchell, S. 2024 Study skills guide [online]. University of Leeds. Available from: <https://www.leeds.ac.uk/study-skills> [accessed 3 September 2026].', 'errorReason' => 'Missing parentheses around the year.' ),
	),
);
$r8 = invoke_normalise( array( $wr_mcq ), array( 'WR02' ), 'medium', array(), 'MCQ', $WR );
check( '[8] Website MCQ: a duplicate incorrect option no longer blocks generation', is_wp_error( $r8 ), false );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
