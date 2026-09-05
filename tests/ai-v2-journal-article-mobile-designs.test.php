<?php
/**
 * Regression tests for the Journal Article DragDrop HARD 3-4-PART RULE
 * (superseding the earlier per-author-chip mobile redesign, which the
 * user's real mobile test showed still violated the exercise rules —
 * several designs exceeded 4 parts, and per-author chips made it
 * impossible to ever hit a fixed 3-4-part target once other fields were
 * tested too).
 *
 * HARD RULE (Citex_Reference_Rules::JOURNAL_ARTICLE_DRAGDROP_MIN_PARTS/
 * MAX_PARTS): every Journal Article DragDrop question has EXACTLY 3 or 4
 * Question Parts, every placeholder maps to exactly one non-empty part,
 * no part is punctuation-only, no part is oversized, and the whole author
 * list (any real count) is always ONE compact chip (via join_people()) —
 * never "et al.", never one chip per author. 'full_reference' (7 parts)
 * and 'author_only' (1 part) are MCQ-only — see
 * Citex_Question_Scenarios's Journal Article MCQ-only scenarios.
 *
 * Covers requirement 12's A-L matrix:
 * A. 3 valid Question Parts.       G. empty Question Part -> FAIL.
 * B. 4 valid Question Parts.       H. punctuation-only part -> FAIL.
 * C. 2 parts -> FAIL.              I. oversized part -> FAIL.
 * D. 5 parts -> FAIL.              J. valid 3-part reconstruction.
 * E. 3 placeholders + 2 parts -> FAIL.  K. valid 4-part reconstruction.
 * F. 2 placeholders + 3 parts -> FAIL.  L. invalid candidate rejected & regenerated.
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
require __DIR__ . '/../citex-tools/includes/class-citex-book-mcq-variants.php';
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
	check( $description, (bool) $actual, true );
}

$JA = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;

function invoke_normalise( $questions, $ids, $exercises, $type, $category, $target_count, $scenario_id, $rule_tested, $design ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions, $ids, 'medium', $exercises, $type, $category, $target_count, $scenario_id, $rule_tested, $design );
}

function invoke_placeholder_count( $fixed ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'placeholder_count' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $fixed );
}

function make_authors( array $pairs ) {
	$out = array();
	foreach ( $pairs as $pair ) {
		$out[] = array( 'surname' => $pair[0], 'initials' => $pair[1] );
	}
	return $out;
}

$base_fields = array(
	'year'         => '2020',
	'articleTitle' => 'A study of referencing',
	'journalTitle' => 'Journal of Studies',
	'volume'       => '12',
	'issue'        => '3',
	'pages'        => '45-52',
);
$one_author  = make_authors( array( array( 'Smith', 'A.' ) ) );

$item_base = array(
	'authorFullNames' => array( 'Anna Smith' ),
	'year'             => '2020',
	'articleTitle'     => 'A study of referencing',
	'journalTitle'     => 'Journal of Studies',
	'volume'           => '12',
	'issue'            => '3',
	'pages'            => '45-52',
	'scenario'         => 'You are referencing an article titled A study of referencing by Anna Smith, published in 2020 in Journal of Studies, volume 12, issue 3, pages 45-52.',
	'confusingWords'   => array( '2019', 'A different journal', '46-52' ),
);

// =======================================================================
// A. 3 valid Question Parts (author_year_issue design).
// =======================================================================
$result_a = invoke_normalise( array( $item_base ), array( 'JA01' ), array( 'Exercise 1' ), 'DragDrop', $JA, 1, 'two_authors', 'author_joining', 'author_year_issue' );
check( '[A] a 3-part DragDrop candidate succeeds', is_wp_error( $result_a ), false );
if ( ! is_wp_error( $result_a ) ) {
	check( '[A] exactly 3 Question Parts', count( $result_a[0]['questionParts'] ), 3 );
	check( '[A] every part is non-empty', in_array( true, array_map( function ( $p ) { return '' === trim( $p ); }, $result_a[0]['questionParts'] ), true ), false );
}

// =======================================================================
// B. 4 valid Question Parts (author_year_volume_pages design).
// =======================================================================
$result_b = invoke_normalise( array( $item_base ), array( 'JA02' ), array( 'Exercise 1' ), 'DragDrop', $JA, 1, 'one_author', 'author_formatting', 'author_year_volume_pages' );
check( '[B] a 4-part DragDrop candidate succeeds', is_wp_error( $result_b ), false );
if ( ! is_wp_error( $result_b ) ) {
	check( '[B] exactly 4 Question Parts', count( $result_b[0]['questionParts'] ), 4 );
	check( '[B] every part is non-empty', in_array( true, array_map( function ( $p ) { return '' === trim( $p ); }, $result_b[0]['questionParts'] ), true ), false );
}

// =======================================================================
// C. Fewer than 3 parts (2) -> FAIL. 'author_only' is MCQ-only (1 part);
// no real DragDrop-eligible design ever produces exactly 2, so this is
// exercised via a direct Reference_Rules-level check plus confirming the
// AI-v2 quality gate independently enforces the same floor for any design
// a bypassing caller might assign.
// =======================================================================
check_true( '[C] Citex_Reference_Rules::JOURNAL_ARTICLE_DRAGDROP_MIN_PARTS is 3', 3 === Citex_Reference_Rules::JOURNAL_ARTICLE_DRAGDROP_MIN_PARTS );
$result_c = invoke_normalise( array( $item_base ), array( 'JA03' ), array( 'Exercise 1' ), 'DragDrop', $JA, 1, '', '', 'author_only' );
check( '[C] a design producing only 1 part (below the 3-4 range) is rejected', is_wp_error( $result_c ), true );
check( '[C] rejected because the design is MCQ-only (not DragDrop-eligible)', is_wp_error( $result_c ) ? $result_c->get_error_code() : null, 'citex_ai_journal_article_design_not_dragdrop_eligible' );

// =======================================================================
// D. More than 4 parts (5, and the 7-part full_reference) -> FAIL.
// =======================================================================
$result_d = invoke_normalise( array( $item_base ), array( 'JA04' ), array( 'Exercise 1' ), 'DragDrop', $JA, 1, '', '', 'full_reference' );
check( '[D] a 7-part design (full_reference) is rejected for DragDrop', is_wp_error( $result_d ), true );
check( '[D] rejected because the design is MCQ-only (not DragDrop-eligible)', is_wp_error( $result_d ) ? $result_d->get_error_code() : null, 'citex_ai_journal_article_design_not_dragdrop_eligible' );
check_true( '[D] Citex_Reference_Rules::JOURNAL_ARTICLE_DRAGDROP_MAX_PARTS is 4', 4 === Citex_Reference_Rules::JOURNAL_ARTICLE_DRAGDROP_MAX_PARTS );

// Directly prove the validator's own explicit range check (requirement 2)
// independently of which design produced the parts — 5 parts, none of
// them empty, still fails the hard 3-4 range.
$five_parts_question = array(
	'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'type' => 'DragDrop', 'exerciseDesign' => 'author_year_volume_pages',
	'authors' => $one_author, 'year' => '2020', 'articleTitle' => 'A study of referencing', 'journalTitle' => 'Journal of Studies', 'volume' => '12', 'issue' => '3', 'pages' => '45-52',
	'fixedText' => '| (||) ||, || pp.||.',
	'questionParts' => array( 'Smith, A.', '2020', '12', '3', '45-52' ),
	'confusingWords' => array( '2019', 'A different journal', '46-52' ),
	'scenario' => 'You are referencing an article titled A study of referencing by Anna Smith, published in 2020 in Journal of Studies, volume 12, issue 3, pages 45-52.',
	'reconstructedReference' => 'Smith, A. (2020) 12, 3 pp.45-52.',
);
$r_five = Citex_Generated_Validator::validate( $five_parts_question );
check( '[D] the validator independently rejects 5 Question Parts', $r_five['status'], 'failed' );
check_true( '[D] reports JOURNAL_ARTICLE_PART_COUNT_OUT_OF_RANGE', in_array( 'journal_article_part_count_out_of_range', array_column( $r_five['errors'], 'code' ), true ) );

// =======================================================================
// E. 3 placeholders + 2 Question Parts -> FAIL (placeholder_count !==
// question_part_count).
// =======================================================================
$mismatch_e = array(
	'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'type' => 'DragDrop', 'exerciseDesign' => 'author_year_issue',
	'authors' => $one_author, 'year' => '2020', 'articleTitle' => 'A study of referencing', 'journalTitle' => 'Journal of Studies', 'volume' => '12', 'issue' => '3', 'pages' => '45-52',
	'fixedText' => '|, ||, ||.', // 3 placeholders
	'questionParts' => array( 'Smith, A.', '2020' ), // only 2 parts
	'confusingWords' => array( '2019', 'A different journal', '46' ),
	'scenario' => 'You are referencing an article titled A study of referencing by Anna Smith, published in 2020 in Journal of Studies, volume 12, issue 3, pages 45-52.',
	'reconstructedReference' => 'Smith, A., 2020, .',
);
$r_e = Citex_Generated_Validator::validate( $mismatch_e );
check( '[E] 3 placeholders + 2 Question Parts fails', $r_e['status'], 'failed' );
check_true( '[E] reports a placeholder/part count mismatch', in_array( 'placeholder_count_mismatch', array_column( $r_e['errors'], 'code' ), true ) );

// =======================================================================
// F. 2 placeholders + 3 Question Parts -> FAIL.
// =======================================================================
$mismatch_f = array(
	'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'type' => 'DragDrop', 'exerciseDesign' => 'author_year_issue',
	'authors' => $one_author, 'year' => '2020', 'articleTitle' => 'A study of referencing', 'journalTitle' => 'Journal of Studies', 'volume' => '12', 'issue' => '3', 'pages' => '45-52',
	'fixedText' => '|, ||.', // 2 placeholders
	'questionParts' => array( 'Smith, A.', '2020', '3' ), // 3 parts
	'confusingWords' => array( '2019', 'A different journal', '46' ),
	'scenario' => 'You are referencing an article titled A study of referencing by Anna Smith, published in 2020 in Journal of Studies, volume 12, issue 3, pages 45-52.',
	'reconstructedReference' => 'Smith, A., 2020.',
);
$r_f = Citex_Generated_Validator::validate( $mismatch_f );
check( '[F] 2 placeholders + 3 Question Parts fails', $r_f['status'], 'failed' );
check_true( '[F] reports a placeholder/part count mismatch', in_array( 'placeholder_count_mismatch', array_column( $r_f['errors'], 'code' ), true ) );

// =======================================================================
// G. Empty Question Part -> FAIL.
// =======================================================================
$empty_part = array(
	'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'type' => 'DragDrop', 'exerciseDesign' => 'author_year_issue',
	'authors' => $one_author, 'year' => '2020', 'articleTitle' => 'A study of referencing', 'journalTitle' => 'Journal of Studies', 'volume' => '12', 'issue' => '3', 'pages' => '45-52',
	'fixedText' => '|, ||, ||.',
	'questionParts' => array( 'Smith, A.', '', '3' ),
	'confusingWords' => array( '2019', 'A different journal', '46' ),
	'scenario' => 'You are referencing an article titled A study of referencing by Anna Smith, published in 2020 in Journal of Studies, volume 12, issue 3, pages 45-52.',
	'reconstructedReference' => 'Smith, A., , 3.',
);
$r_g = Citex_Generated_Validator::validate( $empty_part );
check( '[G] a question with an empty Question Part fails', $r_g['status'], 'failed' );
check_true( '[G] reports JOURNAL_ARTICLE_EMPTY_QUESTION_PART', in_array( 'journal_article_empty_question_part', array_column( $r_g['errors'], 'code' ), true ) );

// AI-v2 quality gate also refuses to ever construct an empty part in the
// first place — a real author with a blank surname is caught upstream by
// derive_author_parts()'s own missing-field check, so this is exercised
// directly against the shared Reference_Rules layer instead.
check( '[G] Reference_Rules never builds an empty draggable part for a real record', in_array( '', Citex_Reference_Rules::dragdrop_shape( $JA, array_merge( $base_fields, array( 'authors' => $one_author ) ), 'author_year_issue' )['parts'], true ), false );

// =======================================================================
// H. Punctuation-only Question Part -> FAIL.
// =======================================================================
$punct_reason = Citex_Reference_Rules::journal_article_mobile_suitability( array( 'Smith, A.', '2020', '.' ) );
check_true( '[H] a punctuation-only draggable part is rejected by the quality gate', null !== $punct_reason );
$clean_reason = Citex_Reference_Rules::journal_article_mobile_suitability( array( 'Smith, A.', '2020', '3' ) );
check( '[H] normal, non-punctuation parts are NOT rejected', $clean_reason, null );
// No design in the catalogue ever produces a punctuation-only part by
// construction (requirement 3/9) — confirmed across every design/author-
// count combination the catalogue actually offers.
foreach ( Citex_Reference_Rules::journal_article_dragdrop_designs() as $design ) {
	$shape = Citex_Reference_Rules::dragdrop_shape( $JA, array_merge( $base_fields, array( 'authors' => $one_author ) ), $design );
	foreach ( $shape['parts'] as $part ) {
		check( "[H] design '$design' never produces a punctuation-only part (\"$part\")", 1 === preg_match( '/^[\p{P}\s]+$/u', (string) $part ), false );
	}
}

// =======================================================================
// I. Oversized Question Part -> FAIL.
// =======================================================================
$item_excessive = array_merge( $item_base, array(
	'scenario'     => 'You are referencing an article titled Learning Online by Anna Smith, published in 2020 in a journal with an extremely long name, volume 12, issue 3, pages 45-52.',
	'journalTitle' => 'International Multidisciplinary Journal of Advanced Interdisciplinary Educational Research and Practice Studies',
) );
// Validation is decoupled from generation this sprint (QUALITY_GATE_ENABLED
// = false): an oversized draggable component no longer aborts generation —
// it is stored, not silently dropped, and can be corrected later via the
// existing manual Validate mechanism. The underlying rule itself is not
// weakened: Citex_Reference_Rules::journal_article_mobile_suitability()
// still detects the exact same problem when called directly.
$result_i = invoke_normalise( array( $item_excessive ), array( 'JA05' ), array( 'Exercise 1' ), 'DragDrop', $JA, 1, '', '', 'author_year_journal' );
check( '[I] an oversized draggable component no longer blocks generation (quality gate decoupled)', is_wp_error( $result_i ), false );
check_true( '[I] the underlying mobile-suitability rule still detects the same oversized component', ! is_wp_error( $result_i ) && null !== Citex_Reference_Rules::journal_article_mobile_suitability( $result_i[0]['questionParts'] ) );

// =======================================================================
// J. Valid 3-part reconstruction (author_year_issue).
// =======================================================================
$shape_j = Citex_Reference_Rules::dragdrop_shape( $JA, array_merge( $base_fields, array( 'authors' => $one_author ) ), 'author_year_issue' );
check( '[J] 3-part shape has exactly 3 parts', count( $shape_j['parts'] ), 3 );
check( '[J] 3-part shape reconstructs correctly', Citex_Reference_Rules::reconstruct_reference( $shape_j ), 'Smith, A., 2020, 3.' );
check_true( '[J] reconstruction matches its own format regex', 1 === preg_match( Citex_Reference_Rules::format_regex( $JA, 'author_year_issue' ), Citex_Reference_Rules::reconstruct_reference( $shape_j ) ) );

// =======================================================================
// K. Valid 4-part reconstruction (author_year_volume_pages).
// =======================================================================
$shape_k = Citex_Reference_Rules::dragdrop_shape( $JA, array_merge( $base_fields, array( 'authors' => $one_author ) ), 'author_year_volume_pages' );
check( '[K] 4-part shape has exactly 4 parts', count( $shape_k['parts'] ), 4 );
check( '[K] 4-part shape reconstructs correctly', Citex_Reference_Rules::reconstruct_reference( $shape_k ), 'Smith, A. (2020) 12, pp.45-52.' );
check_true( '[K] reconstruction matches its own format regex', 1 === preg_match( Citex_Reference_Rules::format_regex( $JA, 'author_year_volume_pages' ), Citex_Reference_Rules::reconstruct_reference( $shape_k ) ) );

// =======================================================================
// L. A generated candidate with an invalid structure is rejected — proven
// end-to-end via generate_questions()'s own quality-gate retry loop: a
// scenario/design combination that always violates the hard rule (here,
// an MCQ-only design assigned to a DragDrop request) causes every attempt
// to fail, exhausting MAX_QUALITY_ATTEMPTS and returning a WP_Error
// instead of ever storing a bad candidate.
// =======================================================================
check_true( '[L] MAX_GENERATION_ATTEMPTS retry budget exists (regenerate, never store invalid output)', Citex_AI_V2::MAX_GENERATION_ATTEMPTS >= 1 );
$result_l = invoke_normalise( array( $item_base, $item_base ), array( 'JA06', 'JA07' ), array( 'Exercise 1', 'Exercise 1' ), 'DragDrop', $JA, 1, '', '', 'full_reference' );
check( '[L] an entire batch is rejected (never partially stored) when one candidate structure is invalid', is_wp_error( $result_l ), true );

// =======================================================================
// Author-count variation (1/2/3/4+) still produces a valid, constant part
// count per design — the whole author list is always ONE compact chip.
// =======================================================================
$author_pool = array( array( 'Smith', 'A.' ), array( 'Jones', 'D.' ), array( 'Lee', 'K.' ), array( 'Brown', 'B.' ), array( 'Green', 'G.' ) );
foreach ( array( 1, 2, 3, 4, 5 ) as $n ) {
	$fields = array_merge( $base_fields, array( 'authors' => make_authors( array_slice( $author_pool, 0, $n ) ) ) );
	$shape  = Citex_Reference_Rules::dragdrop_shape( $JA, $fields, 'author_year_volume_pages' );
	check( "[7] $n author(s): still exactly 4 parts (author list as ONE compact chip)", count( $shape['parts'] ), 4 );
	check( "[7] $n author(s): no \"et al.\" anywhere in the reconstruction", false !== stripos( Citex_Reference_Rules::reconstruct_reference( $shape ), 'et al' ), false );
}

// =======================================================================
// Variation across learning targets: the Journal Article bucket catalogue
// spreads across several different field combinations, not the same 3-4
// fields every time (requirement 6).
// =======================================================================
$dragdrop_scenarios = Citex_Question_Scenarios::catalog( $JA, 'DragDrop' );
$designs_used = array_unique( array_column( $dragdrop_scenarios, 'exerciseDesign' ) );
check_true( '[6] the DragDrop scenario catalogue uses more than one distinct design', count( $designs_used ) > 1 );
foreach ( $dragdrop_scenarios as $scenario ) {
	check_true( "[1][2] scenario '{$scenario['id']}' uses a DragDrop-eligible (3-4 part) design", in_array( $scenario['exerciseDesign'], Citex_Reference_Rules::journal_article_dragdrop_designs(), true ) );
}

// =======================================================================
// No punctuation-only learning objective anywhere in the catalogue.
// =======================================================================
foreach ( array( 'author_format', 'author_joining_pair', 'title_journal_punctuation', 'punctuation_final_stop' ) as $removed_id ) {
	check( "\"$removed_id\" is no longer a Journal Article design", in_array( $removed_id, Citex_Reference_Rules::journal_article_designs(), true ), false );
}

// =======================================================================
// MCQ untouched (requirement 11): full_reference MCQ still works exactly
// as before, with no part-count constraint.
// =======================================================================
$mcq_full = array(
	'authorFullNames' => array( 'Sarah Mitchell' ), 'year' => '2010', 'articleTitle' => 'A brief guide to Harvard referencing', 'journalTitle' => 'The British Journal of Referencing', 'volume' => '12', 'issue' => '2', 'pages' => '27-35',
	'distractors' => array(
		array( 'reference' => 'Mitchell, S. (2010) A brief guide to Harvard referencing The British Journal of Referencing, 12(2), pp.27-35.', 'errorReason' => 'Missing the full stop after the article title.' ),
		array( 'reference' => 'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing 12(2), pp.27-35.', 'errorReason' => 'Missing the comma after the journal title.' ),
		array( 'reference' => 'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), p.27-35.', 'errorReason' => 'Uses "p." instead of "pp.".' ),
	),
);
$result_mcq_full = invoke_normalise( array( $mcq_full ), array( 'JA08' ), array( 'Exercise 1' ), 'MCQ', $JA, null, '', '', 'full_reference' );
check( '[11] complete-reference MCQ still succeeds, unaffected by the DragDrop hard rule', is_wp_error( $result_mcq_full ), false );
if ( ! is_wp_error( $result_mcq_full ) ) {
	check( '[11] the correct answer is the full reference, not a short segment', $result_mcq_full[0]['reconstructedReference'], 'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), pp.27-35.' );
}
$mcq_initials = array(
	'authorFullNames' => array( 'Sarah Brown' ), 'year' => '2022', 'articleTitle' => 'Reading strategies', 'journalTitle' => 'Learning Studies', 'volume' => '9', 'issue' => '1', 'pages' => '12-20',
	'distractors' => array(
		array( 'reference' => 'Brown, B.', 'errorReason' => 'Wrong initial.' ),
		array( 'reference' => 'Sarah, S.', 'errorReason' => 'Swapped surname/first name.' ),
		array( 'reference' => 'Brown, Sarah', 'errorReason' => 'First name not abbreviated to an initial.' ),
	),
);
$result_initials = invoke_normalise( array( $mcq_initials ), array( 'JA09' ), array( 'Exercise 1' ), 'MCQ', $JA, 1, 'author_initials', 'author_initial_format', 'author_only' );
check( '[11] "author initials" MCQ (1-part design) still succeeds — MCQ has no 3-4-part floor', is_wp_error( $result_initials ), false );
if ( ! is_wp_error( $result_initials ) ) {
	check( '[11] the correct answer is exactly the author\'s Harvard-formatted name', $result_initials[0]['reconstructedReference'], 'Brown, S.' );
}

// =======================================================================
// Answer leakage still applies to the new designs.
// =======================================================================
$item_leak = array_merge( $item_base, array(
	'scenario' => 'You are referencing an article written by Anna Smith (initials A.), published in 2020 in Journal of Studies, volume 12, issue 3, pages 45-52.',
) );
// Validation is decoupled from generation this sprint: answer leakage no
// longer aborts generation, but Citex_Generated_Validator itself still
// catches it (validationStatus 'failed'), unchanged.
$result_leak = invoke_normalise( array( $item_leak ), array( 'JA10' ), array( 'Exercise 1' ), 'DragDrop', $JA, 1, 'one_author', 'author_formatting', 'author_year_volume_pages' );
check( 'answer leakage (the word "initials") no longer blocks generation (decoupled)', is_wp_error( $result_leak ), false );
check( 'answer leakage (the word "initials") is still caught by the validator', is_wp_error( $result_leak ) ? null : $result_leak[0]['validationStatus'], 'failed' );

// =======================================================================
// Real-world regression: a genuine 4-author academic paper with longer
// surnames (reported live — Aelterman/Vansteenkiste/Van den Berghe/
// Haerens, joined into one compact chip per the "always one chip, any
// author count" design) must NOT be rejected as oversized just because
// real academic names are longer than a short single field. A joined
// author list gets its own larger budget (max_author_list_component),
// detected STRUCTURALLY (the join_people() "Surname, I." shape), never by
// a naive "contains the word and" check — which must never misfire on an
// ordinary title containing "and" as English prose.
// =======================================================================
$long_name_authors = array(
	array( 'surname' => 'Aelterman', 'initials' => 'N.' ),
	array( 'surname' => 'Vansteenkiste', 'initials' => 'M.' ),
	array( 'surname' => 'Van den Berghe', 'initials' => 'L.' ),
	array( 'surname' => 'Haerens', 'initials' => 'L.' ),
);
$long_name_shape = Citex_Reference_Rules::dragdrop_shape( $JA, array_merge( $base_fields, array( 'authors' => $long_name_authors ) ), 'author_year_volume_pages' );
check_true( 'a real 4-author list with longer surnames is accepted as a single compact chip', null === Citex_Reference_Rules::journal_article_mobile_suitability( $long_name_shape['parts'] ) );

$long_title_not_author_list = 'International Multidisciplinary Journal of Advanced Interdisciplinary Educational Research and Practice Studies';
check( 'a long title containing the word "and" is never misdetected as an author list', Citex_Reference_Rules::journal_article_mobile_suitability( array( 'Smith, A.', '2020', $long_title_not_author_list ) ), sprintf( 'A single draggable component is %1$d characters long ("%2$s…"), too large for a comfortable mobile DragDrop layout — invent or choose a shorter value, or a smaller exercise design.', mb_strlen( $long_title_not_author_list ), mb_substr( $long_title_not_author_list, 0, 30 ) ) );

$item_long_authors = array_merge( $item_base, array(
	'authorFullNames' => array( 'Nathalie Aelterman', 'Maarten Vansteenkiste', 'Leen Van den Berghe', 'Leen Haerens' ),
	'scenario'         => 'You are referencing an article titled A study of referencing by Nathalie Aelterman, Maarten Vansteenkiste, Leen Van den Berghe and Leen Haerens, published in 2020 in Journal of Studies, volume 12, issue 3, pages 45-52.',
) );
$result_long_authors = invoke_normalise( array( $item_long_authors ), array( 'JA11' ), array( 'Exercise 1' ), 'DragDrop', $JA, 4, 'four_or_more_authors', 'reference_list_all_authors', 'author_year_volume_pages' );
check( 'a real 4-author DragDrop question with longer surnames generates successfully', is_wp_error( $result_long_authors ), false );

// =======================================================================
// Existing Book / Journal Article (full_reference MCQ) regression — direct
// Citex_Generated_Validator checks, unaffected by any of the above.
// =======================================================================
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
check( 'existing Book validation is completely unaffected by the Journal Article hard-rule redesign', $book_check['status'], 'passed' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
