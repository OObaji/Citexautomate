<?php
/**
 * Regression tests for the Journal Article "mobile suitability / exercise
 * design" rework — generation now thinks in terms of SOURCE DATA + LEARNING
 * OBJECTIVE + EXERCISE DESIGN + MOBILE SUITABILITY rather than always
 * building the full 7-part reference. Covers Citex_AI_V2::normalise()'s
 * design-aware construction (author_format, author_joining_pair,
 * volume_issue_pages, title_journal_punctuation, punctuation_final_stop)
 * alongside the original, unchanged full_reference design, plus
 * Citex_Reference_Rules::journal_article_mobile_suitability()'s
 * quality-gate rejection and Citex_Generated_Validator's design-aware
 * consistency checks.
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-journal-article-mobile-designs.test.php` — not shipped
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
function has_error_code( $result, $code ) {
	foreach ( $result['errors'] as $error ) {
		if ( $code === $error['code'] ) {
			return true;
		}
	}
	return false;
}

$JA = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;

function invoke_normalise( $questions, $ids, $exercises, $type, $category, $target_count, $scenario_id, $rule_tested, $design ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions, $ids, 'medium', $exercises, $type, $category, $target_count, $scenario_id, $rule_tested, $design );
}

// ---------------------------------------------------------------------
// 5 & 6. Initials/surname question: author_format derives BOTH the
// surname and the Harvard initial correctly from a real full name, never
// revealed in the scenario.
// ---------------------------------------------------------------------
$item_af = array(
	'scenario'        => 'You are referencing an article written by Sarah Brown, published in 2022 in Learning Studies, volume 9, issue 1, pages 12-20.',
	'authorFullNames' => array( 'Sarah Brown' ),
	'year'            => '2022',
	'articleTitle'    => 'Reading strategies',
	'journalTitle'    => 'Learning Studies',
	'volume'          => '9',
	'issue'           => '1',
	'pages'           => '12-20',
	'questionParts'   => array( 'Brown', 'S.' ),
	'fixedText'       => '|, ||',
	'confusingWords'  => array( 'Brown, B.', 'Sarah, S.', 'Brown, Sarah' ),
);
$result_af = invoke_normalise( array( $item_af ), array( 'JA01' ), array( 'Exercise 1' ), 'DragDrop', $JA, 1, 'author_format', 'author_initial_derivation', 'author_format' );
check( '[1][5][6] one-author "author format" question succeeds', is_wp_error( $result_af ), false );
if ( ! is_wp_error( $result_af ) ) {
	$c = $result_af[0];
	check( '[6] the surname is correctly derived as the first Question Part', $c['questionParts'][0], 'Brown' );
	check( '[5] the Harvard initial is correctly derived as the second Question Part', $c['questionParts'][1], 'S.' );
	check( '[10] this is a genuinely partial design: exactly 2 draggable parts, not 7', count( $c['questionParts'] ), 2 );
	check( 'exerciseDesign is recorded on the candidate', $c['exerciseDesign'], 'author_format' );
	check( 'validates and enters the queue as passed', $c['validationStatus'], 'passed' );
}

// ---------------------------------------------------------------------
// 2. Two-author question: author_joining_pair tests the "and" joining
// component only.
// ---------------------------------------------------------------------
$item_ajp = array(
	'scenario'        => 'You are referencing an article by Anna Smith and David Jones, published in 2022 in Research Studies, volume 14, issue 2, pages 45-52.',
	'authorFullNames' => array( 'Anna Smith', 'David Jones' ),
	'year'            => '2022',
	'articleTitle'    => 'Learning Online',
	'journalTitle'    => 'Research Studies',
	'volume'          => '14',
	'issue'           => '2',
	'pages'           => '45-52',
	'questionParts'   => array( 'Smith, A. and Jones, D.' ),
	'fixedText'       => '|',
	'confusingWords'  => array( 'Smith, A. & Jones, D.', 'Smith, A., Jones, D.', 'Jones, D. and Smith, A.' ),
);
$result_ajp = invoke_normalise( array( $item_ajp ), array( 'JA02' ), array( 'Exercise 2' ), 'DragDrop', $JA, 2, 'author_joining_pair', 'author_joining', 'author_joining_pair' );
check( '[2] two-author "author joining" question succeeds', is_wp_error( $result_ajp ), false );
if ( ! is_wp_error( $result_ajp ) ) {
	check( 'exactly 1 draggable part (the joined pair)', count( $result_ajp[0]['questionParts'] ), 1 );
	check( 'reconstructed reference is the joined pair only', $result_ajp[0]['reconstructedReference'], 'Smith, A. and Jones, D.' );
}

// ---------------------------------------------------------------------
// 3 & 11. Three-author question using the ORIGINAL, unchanged
// full_reference design — "complete-reference DragDrop" still works and
// still produces exactly 7 parts.
// ---------------------------------------------------------------------
$item_3author = array(
	'scenario'        => 'You are referencing an article titled Group learning by Anna Smith, David Jones and Kate Lee, published in 2021 in Learning Studies, volume 5, issue 3, pages 10-18.',
	'authorFullNames' => array( 'Anna Smith', 'David Jones', 'Kate Lee' ),
	'year'            => '2021',
	'articleTitle'    => 'Group learning',
	'journalTitle'    => 'Learning Studies',
	'volume'          => '5',
	'issue'           => '3',
	'pages'           => '10-18',
	'questionParts'   => array( 'Smith, A., Jones, D. and Lee, K.', '2021', 'Group learning', 'Learning Studies', '5', '3', '10-18' ),
	'fixedText'       => '| (||) ||. ||, ||(||), pp.||.',
	'confusingWords'  => array( '2020', 'A different journal', '11-18' ),
);
$result_3 = invoke_normalise( array( $item_3author ), array( 'JA03' ), array( 'Exercise 3' ), 'DragDrop', $JA, 3, 'three_authors', 'author_joining', 'full_reference' );
check( '[3][11] three-author "complete reference" question succeeds', is_wp_error( $result_3 ), false );
if ( ! is_wp_error( $result_3 ) ) {
	check( '[11] complete-reference DragDrop still produces exactly 7 parts', count( $result_3[0]['questionParts'] ), 7 );
	check( '[17] canonicalReference equals the reconstructed reference for the full_reference design', $result_3[0]['canonicalReference'], $result_3[0]['reconstructedReference'] );
}

// ---------------------------------------------------------------------
// 4. Four-author question, still full_reference — occasional larger
// author counts remain usable when names are reasonably short.
// ---------------------------------------------------------------------
$item_4author = array(
	'scenario'        => 'You are referencing an article titled Team projects by Anna Smith, David Jones, Kate Lee and Tom Reed, published in 2020 in Learning Studies, volume 6, issue 1, pages 1-9.',
	'authorFullNames' => array( 'Anna Smith', 'David Jones', 'Kate Lee', 'Tom Reed' ),
	'year'            => '2020',
	'articleTitle'    => 'Team projects',
	'journalTitle'    => 'Learning Studies',
	'volume'          => '6',
	'issue'           => '1',
	'pages'           => '1-9',
	'questionParts'   => array( 'Smith, A., Jones, D., Lee, K. and Reed, T.', '2020', 'Team projects', 'Learning Studies', '6', '1', '1-9' ),
	'fixedText'       => '| (||) ||. ||, ||(||), pp.||.',
	'confusingWords'  => array( '2019', 'A different journal', '2-9' ),
);
$result_4 = invoke_normalise( array( $item_4author ), array( 'JA04' ), array( 'Exercise 4' ), 'DragDrop', $JA, 4, 'four_authors', 'reference_list_all_authors', 'full_reference' );
check( '[4] four-author "complete author construction" question succeeds when names are reasonably short', is_wp_error( $result_4 ), false );

// ---------------------------------------------------------------------
// 7. Punctuation question: punctuation_final_stop shows the full content
// with only the terminal full stop draggable — the validator must accept
// punctuation itself as a valid Question Part.
// ---------------------------------------------------------------------
$item_punct = array(
	'scenario'       => 'You are referencing an article titled Learning Online by Anna Smith, published in 2022 in Research Studies, volume 14, issue 2, pages 45-52.',
	'authorFullNames' => array( 'Anna Smith' ),
	'year'            => '2022',
	'articleTitle'    => 'Learning Online',
	'journalTitle'    => 'Research Studies',
	'volume'          => '14',
	'issue'           => '2',
	'pages'           => '45-52',
	'questionParts'   => array( '.' ),
	'fixedText'       => 'Smith, A. (2022) Learning Online. Research Studies, 14(2), pp.45-52|',
	'confusingWords'  => array( ',', ';', ':' ),
);
$result_punct = invoke_normalise( array( $item_punct ), array( 'JA05' ), array( 'Exercise 5' ), 'DragDrop', $JA, null, 'punctuation_final_stop', 'terminal_punctuation', 'punctuation_final_stop' );
check( '[7] "terminal punctuation" question succeeds — punctuation is a valid Question Part', is_wp_error( $result_punct ), false );
if ( ! is_wp_error( $result_punct ) ) {
	check( 'the single draggable part is literally the full stop', $result_punct[0]['questionParts'][0], '.' );
	check( 'the reconstructed reference is the complete, correctly-punctuated reference', $result_punct[0]['reconstructedReference'], 'Smith, A. (2022) Learning Online. Research Studies, 14(2), pp.45-52.' );
}

// ---------------------------------------------------------------------
// 8 & 9. Volume/issue question and page-range question: volume_issue_pages
// tests the "Volume(Issue), pp.Start-End." structure as 3 short parts.
// ---------------------------------------------------------------------
$item_vip = array(
	'scenario'        => 'You are referencing an article titled Learning Online by Anna Smith, published in 2022 in Research Studies, volume 14, issue 2, pages 45-52.',
	'authorFullNames' => array( 'Anna Smith' ),
	'year'            => '2022',
	'articleTitle'    => 'Learning Online',
	'journalTitle'    => 'Research Studies',
	'volume'          => '14',
	'issue'           => '2',
	'pages'           => '45-52',
	'questionParts'   => array( '14', '2', '45-52' ),
	'fixedText'       => '|(||), pp.||.',
	'confusingWords'  => array( '15(2), pp.45-52.', '14(3), pp.45-52.', '14(2), pp.46-52.' ),
);
$result_vip = invoke_normalise( array( $item_vip ), array( 'JA06' ), array( 'Exercise 1' ), 'DragDrop', $JA, null, 'volume_issue_pages', 'volume_issue_pages_structure', 'volume_issue_pages' );
check( '[8] "volume/issue" question succeeds', is_wp_error( $result_vip ), false );
if ( ! is_wp_error( $result_vip ) ) {
	check( '[9] the page range is correctly one of the draggable parts', $result_vip[0]['questionParts'][2], '45-52' );
	check( 'the reconstructed segment matches the Volume(Issue), pp.Start-End. structure', $result_vip[0]['reconstructedReference'], '14(2), pp.45-52.' );
}

// ---------------------------------------------------------------------
// 12 & 13. Concise MCQ vs complete-reference MCQ: a partial-design MCQ's
// options are short (mobile-friendly); a full-reference MCQ's options are,
// unavoidably, complete references — both must still validate correctly.
// ---------------------------------------------------------------------
$mcq_af = array(
	'authorFullNames' => array( 'Sarah Brown' ), 'year' => '2022', 'articleTitle' => 'Reading strategies', 'journalTitle' => 'Learning Studies', 'volume' => '9', 'issue' => '1', 'pages' => '12-20',
	'distractors' => array(
		array( 'reference' => 'Brown, B.', 'errorReason' => 'Wrong initial.' ),
		array( 'reference' => 'Sarah, S.', 'errorReason' => 'Swapped surname/first name.' ),
		array( 'reference' => 'Brown, Sarah', 'errorReason' => 'First name not abbreviated to an initial.' ),
	),
);
$result_mcq_af = invoke_normalise( array( $mcq_af ), array( 'JA07' ), array( 'Exercise 1' ), 'MCQ', $JA, 1, 'author_format', 'author_initial_derivation', 'author_format' );
check( '[12] concise "author format" MCQ succeeds', is_wp_error( $result_mcq_af ), false );
if ( ! is_wp_error( $result_mcq_af ) ) {
	$max_option_len = max( array_map( 'strlen', array_filter( $result_mcq_af[0]['options'] ) ) );
	check( '[12] every MCQ option is short/mobile-friendly (under 20 characters)', $max_option_len < 20, true );
	check( 'the MCQ stem is the design-specific one, not the generic full-reference stem', $result_mcq_af[0]['scenario'], 'Which of the following correctly formats this author for the Harvard reference list?' );
}

$mcq_full = array(
	'authorFullNames' => array( 'Sarah Mitchell' ), 'year' => '2010', 'articleTitle' => 'A brief guide to Harvard referencing', 'journalTitle' => 'The British Journal of Referencing', 'volume' => '12', 'issue' => '2', 'pages' => '27-35',
	'distractors' => array(
		array( 'reference' => 'Mitchell, S. (2010) A brief guide to Harvard referencing The British Journal of Referencing, 12(2), pp.27-35.', 'errorReason' => 'Missing the full stop after the article title.' ),
		array( 'reference' => 'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing 12(2), pp.27-35.', 'errorReason' => 'Missing the comma after the journal title.' ),
		array( 'reference' => 'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), p.27-35.', 'errorReason' => 'Uses "p." instead of "pp.".' ),
	),
);
$result_mcq_full = invoke_normalise( array( $mcq_full ), array( 'JA08' ), array( 'Exercise 1' ), 'MCQ', $JA, null, '', '', 'full_reference' );
check( '[13] complete-reference MCQ still succeeds', is_wp_error( $result_mcq_full ), false );
if ( ! is_wp_error( $result_mcq_full ) ) {
	check( '[13] the correct answer is the full reference, not a short segment', $result_mcq_full[0]['reconstructedReference'], 'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), pp.27-35.' );
}

// ---------------------------------------------------------------------
// 14. Excessive draggable rejection: an unreasonably long single component
// (a very long journal title) is rejected by the mobile-suitability
// quality-gate check, feeding the existing retry loop.
// ---------------------------------------------------------------------
$item_excessive = array(
	'scenario'        => 'You are referencing an article titled Learning Online by Anna Smith, published in 2022 in a journal with an extremely long name, volume 14, issue 2, pages 45-52.',
	'authorFullNames' => array( 'Anna Smith' ),
	'year'            => '2022',
	'articleTitle'    => 'Learning Online',
	'journalTitle'    => 'International Multidisciplinary Journal of Advanced Interdisciplinary Educational Research and Practice Studies',
	'volume'          => '14',
	'issue'           => '2',
	'pages'           => '45-52',
	'questionParts'   => array( 'Smith, A.', '2022', 'Learning Online', 'International Multidisciplinary Journal of Advanced Interdisciplinary Educational Research and Practice Studies', '14', '2', '45-52' ),
	'fixedText'       => '| (||) ||. ||, ||(||), pp.||.',
	'confusingWords'  => array( '2020', 'A different journal', '46-52' ),
);
$result_excessive = invoke_normalise( array( $item_excessive ), array( 'JA09' ), array( 'Exercise 1' ), 'DragDrop', $JA, 1, '', '', 'full_reference' );
check( '[14] an excessively long single draggable component is rejected', is_wp_error( $result_excessive ), true );
check( '[14] rejected with the mobile-unsuitability error code', is_wp_error( $result_excessive ) ? $result_excessive->get_error_code() : null, 'citex_ai_journal_article_mobile_unsuitable' );

// ---------------------------------------------------------------------
// 15. Long-title handling: a title/journal combination that is longer than
// typical but still within the mobile-suitability thresholds is preserved
// VERBATIM — never truncated or altered.
// ---------------------------------------------------------------------
$item_long_ok = array(
	'scenario'        => 'You are referencing an article titled Understanding student engagement in online learning environments by Anna Smith, published in 2022 in the Journal of Educational Technology Research, volume 14, issue 2, pages 45-52.',
	'authorFullNames' => array( 'Anna Smith' ),
	'year'            => '2022',
	'articleTitle'    => 'Understanding student engagement in online learning environments',
	'journalTitle'    => 'Journal of Educational Technology Research',
	'volume'          => '14',
	'issue'           => '2',
	'pages'           => '45-52',
	'questionParts'   => array( 'Smith, A.', '2022', 'Understanding student engagement in online learning environments', 'Journal of Educational Technology Research', '14', '2', '45-52' ),
	'fixedText'       => '| (||) ||. ||, ||(||), pp.||.',
	'confusingWords'  => array( '2020', 'A different journal', '46-52' ),
);
$result_long_ok = invoke_normalise( array( $item_long_ok ), array( 'JA10' ), array( 'Exercise 1' ), 'DragDrop', $JA, 1, '', '', 'full_reference' );
check( '[15] a longer-but-reasonable real title is not rejected', is_wp_error( $result_long_ok ), false );
if ( ! is_wp_error( $result_long_ok ) ) {
	check( '[15] the article title is preserved verbatim, never truncated', $result_long_ok[0]['articleTitle'], 'Understanding student engagement in online learning environments' );
	check( '[15] no "..." truncation marker anywhere in the title', false !== strpos( $result_long_ok[0]['articleTitle'], '...' ), false );
}

// ---------------------------------------------------------------------
// 16. Answer leakage still applies to the new partial designs — the
// scenario must never reveal the author's initials, regardless of design.
// ---------------------------------------------------------------------
$item_leak = array(
	'scenario'        => 'You are referencing an article written by Sarah Brown (initials S.), published in 2022 in Learning Studies, volume 9, issue 1, pages 12-20.',
	'authorFullNames' => array( 'Sarah Brown' ),
	'year'            => '2022',
	'articleTitle'    => 'Reading strategies',
	'journalTitle'    => 'Learning Studies',
	'volume'          => '9',
	'issue'           => '1',
	'pages'           => '12-20',
	'questionParts'   => array( 'Brown', 'S.' ),
	'fixedText'       => '|, ||',
	'confusingWords'  => array( 'Brown, B.', 'Sarah, S.', 'Brown, Sarah' ),
);
$result_leak = invoke_normalise( array( $item_leak ), array( 'JA11' ), array( 'Exercise 1' ), 'DragDrop', $JA, 1, 'author_format', 'author_initial_derivation', 'author_format' );
check( '[16] answer leakage (the word "initials") is still rejected for the author_format design', is_wp_error( $result_leak ), true );

// ---------------------------------------------------------------------
// 17. Canonical reference reconstruction: for every design, the stored
// canonicalReference is always the FULL reference built from the complete
// real source data, regardless of which subset the exercise itself tests.
// ---------------------------------------------------------------------
$expected_canonical = Citex_Reference_Rules::build_reference(
	$JA,
	array(
		'authors'      => array( array( 'surname' => 'Smith', 'initials' => 'A.' ) ),
		'year'         => '2022',
		'articleTitle' => 'Learning Online',
		'journalTitle' => 'Research Studies',
		'volume'       => '14',
		'issue'        => '2',
		'pages'        => '45-52',
	)
);
check( '[17] volume_issue_pages design still retains the full canonical reference internally', $result_vip[0]['canonicalReference'], $expected_canonical );
check( '[17] author_format design still retains the full canonical reference internally', $result_af[0]['canonicalReference'], Citex_Reference_Rules::build_reference( $JA, array( 'authors' => array( array( 'surname' => 'Brown', 'initials' => 'S.' ) ), 'year' => '2022', 'articleTitle' => 'Reading strategies', 'journalTitle' => 'Learning Studies', 'volume' => '9', 'issue' => '1', 'pages' => '12-20' ) ) );

// ---------------------------------------------------------------------
// 18 & 19. Existing Book / Journal Article (full_reference) regression —
// direct Citex_Generated_Validator checks, unaffected by any of the above.
// ---------------------------------------------------------------------
$book_check = Citex_Generated_Validator::validate( array(
	'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Book', 'type' => 'DragDrop',
	'authors' => array( array( 'surname' => 'Bryman', 'initials' => 'A.' ) ),
	'year' => '2012', 'bookTitle' => 'Social Research Methods', 'place' => 'Oxford', 'publisher' => 'Oxford University Press',
	'fixedText' => '|, || (||) ||. Oxford: Oxford University Press.',
	'questionParts' => array( 'Bryman', 'A.', '2012', 'Social Research Methods' ),
	'confusingWords' => array( '2010', 'London', 'Smith' ),
	'scenario' => 'You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.',
	'reconstructedReference' => 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.',
) );
check( '[18] existing Book validation is completely unaffected by the mobile/partial-design rework', $book_check['status'], 'passed' );

$ja_fields = array( 'authors' => array( array( 'surname' => 'Mitchell', 'initials' => 'S.' ) ), 'year' => '2010', 'articleTitle' => 'A brief guide to Harvard referencing', 'journalTitle' => 'The British Journal of Referencing', 'volume' => '12', 'issue' => '2', 'pages' => '27-35' );
$ja_shape  = Citex_Reference_Rules::dragdrop_shape( $JA, $ja_fields );
$ja_check  = Citex_Generated_Validator::validate( array_merge(
	array(
		'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'type' => 'DragDrop',
		'fixedText' => $ja_shape['fixedText'], 'questionParts' => $ja_shape['parts'],
		'confusingWords' => array( '2015', 'A different journal', '45-52' ),
		'scenario' => 'You are referencing a journal article titled A brief guide to Harvard referencing by Sarah Mitchell, published in 2010 in The British Journal of Referencing, volume 12, issue 2, pages 27-35.',
		'reconstructedReference' => Citex_Reference_Rules::build_reference( $JA, $ja_fields ),
	),
	$ja_fields
) );
check( '[19] existing Journal Article full_reference validation (no exerciseDesign field at all) is completely unaffected', $ja_check['status'], 'passed' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
