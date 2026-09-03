<?php
/**
 * Regression tests for the Journal Article learning-objective REDESIGN
 * (superseding the earlier "mobile suitability" rework, which had two
 * fundamental problems: it made punctuation itself the learning objective
 * (title_journal_punctuation, punctuation_final_stop — both removed), and
 * it either split ONE author into fragments (author_format, 2 parts — below
 * the 3-meaningful-parts floor) or pre-joined MULTIPLE authors into ONE
 * giant draggable chunk (author_joining_pair, and the original full_reference
 * design for 2+ authors) instead of one small chip per author.
 *
 * This redesign's design catalogue: full_reference, author_context (1-3
 * authors + year + article title), author_list_year (4+ authors + year),
 * volume_issue_pages, journal_volume_issue, title_journal_volume,
 * reference_body (article/journal title + volume/issue/pages), author_only
 * (MCQ-only, single author in isolation). Every design tests at least 3
 * meaningful DragDrop parts, one small chip PER AUTHOR (never a pre-joined
 * multi-author string), and never makes punctuation the tested objective.
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
function check_true( $description, $actual ) {
	check( $description, (bool) $actual, true );
}

$JA = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;

function invoke_normalise( $questions, $ids, $exercises, $type, $category, $target_count, $scenario_id, $rule_tested, $design ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions, $ids, 'medium', $exercises, $type, $category, $target_count, $scenario_id, $rule_tested, $design );
}

/**
 * Independent re-implementation of the |/|| placeholder grammar (does NOT
 * reuse Citex_AI_V2::placeholder_count(), deliberately, so this test can
 * never silently agree with a shared bug) — counts how many draggable
 * placeholder tokens a fixedText string encodes.
 */
function count_placeholders( $fixed ) {
	$count = 0;
	$len   = strlen( $fixed );
	for ( $i = 0; $i < $len; ) {
		if ( '|' !== $fixed[ $i ] ) {
			$i++;
			continue;
		}
		if ( $i + 1 < $len && '|' === $fixed[ $i + 1 ] ) {
			$count++;
			$i += 2;
			continue;
		}
		$count++;
		$i++;
	}
	return $count;
}

function make_authors( array $pairs ) {
	$out = array();
	foreach ( $pairs as $pair ) {
		$out[] = array( 'surname' => $pair[0], 'initials' => $pair[1] );
	}
	return $out;
}

function author_full_names( array $pairs ) {
	// Turns [surname, initials] pairs into plausible full names for
	// authorFullNames — Citex derives surname/initials itself from these.
	$first_names = array( 'A.' => 'Anna', 'D.' => 'David', 'K.' => 'Kate', 'B.' => 'Ben', 'G.' => 'Grace', 'J.' => 'John' );
	$out = array();
	foreach ( $pairs as $pair ) {
		list( $surname, $initials ) = $pair;
		$first = $first_names[ $initials ] ?? 'Sam';
		$out[] = $first . ' ' . $surname;
	}
	return $out;
}

// =======================================================================
// STRUCTURAL CONSISTENCY: for every design, at every author count it
// supports, the shape's placeholder count exactly matches its part count
// (exact placeholder-to-answer-part mapping — requirement 6/11).
// =======================================================================
$base_fields = array(
	'year'         => '2020',
	'articleTitle' => 'A study of referencing',
	'journalTitle' => 'Journal of Studies',
	'volume'       => '12',
	'issue'        => '3',
	'pages'        => '45-52',
);
$author_pool = array( array( 'Smith', 'A.' ), array( 'Jones', 'D.' ), array( 'Lee', 'K.' ), array( 'Brown', 'B.' ), array( 'Green', 'G.' ), array( 'White', 'J.' ) );
foreach ( Citex_Reference_Rules::journal_article_designs() as $design ) {
	$bounds = Citex_Reference_Rules::journal_article_design_author_bounds( $design );
	$counts_to_try = $bounds ? array( $bounds[0], min( $bounds[1], $bounds[0] + 2 ) ) : array( 1, 3 );
	foreach ( array_unique( $counts_to_try ) as $n ) {
		$fields = array_merge( $base_fields, array( 'authors' => make_authors( array_slice( $author_pool, 0, $n ) ) ) );
		$shape  = Citex_Reference_Rules::dragdrop_shape( $JA, $fields, $design );
		check( "[6][11] $design (n=$n): placeholder count exactly matches answer-part count", count_placeholders( $shape['fixedText'] ), count( $shape['parts'] ) );
		$reference = Citex_Reference_Rules::reconstruct_reference( $shape );
		check_true( "[6][11] $design (n=$n): reconstructed reference matches its own shape regex", 1 === preg_match( Citex_Reference_Rules::format_regex( $JA, $design ), $reference ) );
	}
}

// =======================================================================
// 1. One-author Journal Article: author_context — 3 meaningful parts
// (author + year + article title), never split into surname/initials
// fragments.
// =======================================================================
$item_1author = array(
	'scenario'        => 'You are referencing an article titled Reading strategies by Sarah Brown, published in 2022 in Learning Studies, volume 9, issue 1, pages 12-20.',
	'authorFullNames' => array( 'Sarah Brown' ),
	'year'            => '2022',
	'articleTitle'    => 'Reading strategies',
	'journalTitle'    => 'Learning Studies',
	'volume'          => '9',
	'issue'           => '1',
	'pages'           => '12-20',
	'confusingWords'  => array( '2021', 'A different journal', 'Learning strategies' ),
);
$result_1 = invoke_normalise( array( $item_1author ), array( 'JA01' ), array( 'Exercise 1' ), 'DragDrop', $JA, 1, 'one_author', 'author_formatting', 'author_context' );
check( '[1] one-author Journal Article ("author_context") succeeds', is_wp_error( $result_1 ), false );
if ( ! is_wp_error( $result_1 ) ) {
	$c = $result_1[0];
	check( '[1] exactly 3 meaningful draggable parts', count( $c['questionParts'] ), 3 );
	check( '[1] the author is one whole chip ("Surname, I."), not split into fragments', $c['questionParts'][0], 'Brown, S.' );
	check( '[1] year is a draggable part', $c['questionParts'][1], '2022' );
	check( '[1] article title is a draggable part', $c['questionParts'][2], 'Reading strategies' );
	check( '[1] reconstruction is "Author (Year) Title."', $c['reconstructedReference'], 'Brown, S. (2022) Reading strategies.' );
	check( '[16] validates and enters the queue as passed', $c['validationStatus'], 'passed' );
}

// =======================================================================
// 2. Two-author Journal Article: author_context — one chip PER AUTHOR,
// never one pre-joined "X and Y" giant chunk.
// =======================================================================
$item_2author = array(
	'scenario'        => 'You are referencing an article titled Learning Online by Anna Smith and David Jones, published in 2022 in Research Studies, volume 14, issue 2, pages 45-52.',
	'authorFullNames' => array( 'Anna Smith', 'David Jones' ),
	'year'            => '2022',
	'articleTitle'    => 'Learning Online',
	'journalTitle'    => 'Research Studies',
	'volume'          => '14',
	'issue'           => '2',
	'pages'           => '45-52',
	'confusingWords'  => array( '2021', 'A different journal', 'Learning offline' ),
);
$result_2 = invoke_normalise( array( $item_2author ), array( 'JA02' ), array( 'Exercise 1' ), 'DragDrop', $JA, 2, 'two_authors', 'author_joining', 'author_context' );
check( '[2] two-author Journal Article ("author_context") succeeds', is_wp_error( $result_2 ), false );
if ( ! is_wp_error( $result_2 ) ) {
	$c = $result_2[0];
	check( '[2] exactly 4 meaningful draggable parts (2 authors + year + title)', count( $c['questionParts'] ), 4 );
	check( '[2][3] NO GIANT CHUNK: author 1 is its own small chip', $c['questionParts'][0], 'Smith, A.' );
	check( '[2][3] NO GIANT CHUNK: author 2 is its own small chip, not joined with author 1', $c['questionParts'][1], 'Jones, D.' );
	check( '[3] neither author chip contains the word "and" (proves they are not pre-joined)', false !== strpos( $c['questionParts'][0], ' and ' ) || false !== strpos( $c['questionParts'][1], ' and ' ), false );
	check( '[2] reconstruction correctly joins the two author chips with "and"', $c['reconstructedReference'], 'Smith, A. and Jones, D. (2022) Learning Online.' );
}

// =======================================================================
// 3. Three-author Journal Article: author_context — 5 parts, per-author
// chips, correct comma+"and" Harvard joining.
// =======================================================================
$item_3author = array(
	'scenario'        => 'You are referencing an article titled Group learning by Anna Smith, David Jones and Kate Lee, published in 2021 in Learning Studies, volume 5, issue 3, pages 10-18.',
	'authorFullNames' => array( 'Anna Smith', 'David Jones', 'Kate Lee' ),
	'year'            => '2021',
	'articleTitle'    => 'Group learning',
	'journalTitle'    => 'Learning Studies',
	'volume'          => '5',
	'issue'           => '3',
	'pages'           => '10-18',
	'confusingWords'  => array( '2020', 'A different journal', 'Solo learning' ),
);
$result_3 = invoke_normalise( array( $item_3author ), array( 'JA03' ), array( 'Exercise 1' ), 'DragDrop', $JA, 3, 'three_authors', 'author_joining', 'author_context' );
check( '[3] three-author Journal Article ("author_context") succeeds', is_wp_error( $result_3 ), false );
if ( ! is_wp_error( $result_3 ) ) {
	$c = $result_3[0];
	check( '[3] exactly 5 meaningful draggable parts (3 authors + year + title)', count( $c['questionParts'] ), 5 );
	check( '[3] each of the 3 authors is its own small chip', array( $c['questionParts'][0], $c['questionParts'][1], $c['questionParts'][2] ), array( 'Smith, A.', 'Jones, D.', 'Lee, K.' ) );
	check( '[3] reconstruction correctly comma-joins with a final "and"', $c['reconstructedReference'], 'Smith, A., Jones, D. and Lee, K. (2021) Group learning.' );
}

// =======================================================================
// 4. Four-or-more-author Journal Article / et al.: author_list_year —
// every author listed individually, "et al." never used, chips stay small
// even at 6 authors.
// =======================================================================
$item_6author = array(
	'scenario'        => 'You are referencing an article titled Team projects by Anna Smith, David Jones, Kate Lee, Ben Brown, Grace Green and John White, published in 2020 in Learning Studies, volume 6, issue 1, pages 1-9.',
	'authorFullNames' => array( 'Anna Smith', 'David Jones', 'Kate Lee', 'Ben Brown', 'Grace Green', 'John White' ),
	'year'            => '2020',
	'articleTitle'    => 'Team projects',
	'journalTitle'    => 'Learning Studies',
	'volume'          => '6',
	'issue'           => '1',
	'pages'           => '1-9',
	'confusingWords'  => array( '2019', 'A different journal', 'Solo projects' ),
);
$result_6 = invoke_normalise( array( $item_6author ), array( 'JA04' ), array( 'Exercise 1' ), 'DragDrop', $JA, 6, 'five_or_more_authors', 'reference_list_all_authors', 'author_list_year' );
check( '[4] six-author Journal Article ("author_list_year") succeeds', is_wp_error( $result_6 ), false );
if ( ! is_wp_error( $result_6 ) ) {
	$c = $result_6[0];
	check( '[4] exactly 7 meaningful draggable parts (6 authors + year)', count( $c['questionParts'] ), 7 );
	for ( $i = 0; $i < 6; $i++ ) {
		check( "[4] author $i is its own small chip, none pre-joined with another author", false !== strpos( $c['questionParts'][ $i ], ' and ' ), false );
	}
	check( '[4] "et al." never appears in the reconstructed reference', false !== stripos( $c['reconstructedReference'], 'et al' ), false );
	check( '[4] every one of the 6 real authors appears individually in the reconstruction', 6, substr_count( $c['reconstructedReference'], ', ' ) >= 5 ? 6 : 0 );
}

// =======================================================================
// 5/6. Author initials MCQ (author_only, MCQ-only design) — a single
// meaningful decision, no 3-part floor for MCQ.
// =======================================================================
$mcq_initials = array(
	'authorFullNames' => array( 'Sarah Brown' ), 'year' => '2022', 'articleTitle' => 'Reading strategies', 'journalTitle' => 'Learning Studies', 'volume' => '9', 'issue' => '1', 'pages' => '12-20',
	'distractors' => array(
		array( 'reference' => 'Brown, B.', 'errorReason' => 'Wrong initial.' ),
		array( 'reference' => 'Sarah, S.', 'errorReason' => 'Swapped surname/first name.' ),
		array( 'reference' => 'Brown, Sarah', 'errorReason' => 'First name not abbreviated to an initial.' ),
	),
);
$result_initials = invoke_normalise( array( $mcq_initials ), array( 'JA05' ), array( 'Exercise 1' ), 'MCQ', $JA, 1, 'author_initials', 'author_initial_format', 'author_only' );
check( '[5][6] "author initials" MCQ succeeds', is_wp_error( $result_initials ), false );
if ( ! is_wp_error( $result_initials ) ) {
	check( '[5][6] the correct answer is exactly the author\'s Harvard-formatted name', $result_initials[0]['reconstructedReference'], 'Brown, S.' );
	check( 'MCQ stem is the design-specific "author initials" stem, not the generic full-reference stem', $result_initials[0]['scenario'], 'Which of the following correctly formats this author\'s name for the Harvard reference list?' );
	$max_option_len = max( array_map( 'strlen', array_filter( $result_initials[0]['options'] ) ) );
	check_true( 'every option is short/mobile-friendly (under 20 characters)', $max_option_len < 20 );
}

// =======================================================================
// 7. Volume/issue: journal_volume_issue — journal + volume + issue.
// =======================================================================
$item_jvi = array(
	'scenario'        => 'You are referencing an article titled Learning Online by Anna Smith, published in 2022 in Research Studies, volume 14, issue 2, pages 45-52.',
	'authorFullNames' => array( 'Anna Smith' ), 'year' => '2022', 'articleTitle' => 'Learning Online', 'journalTitle' => 'Research Studies', 'volume' => '14', 'issue' => '2', 'pages' => '45-52',
	'confusingWords'  => array( 'A different journal, 15(2)', 'Research Studies, 14(3)', 'Research Studies, 15(3)' ),
);
$result_jvi = invoke_normalise( array( $item_jvi ), array( 'JA06' ), array( 'Exercise 1' ), 'DragDrop', $JA, null, 'journal_volume_issue', 'journal_title_placement', 'journal_volume_issue' );
check( '[7] "journal/volume/issue" question succeeds', is_wp_error( $result_jvi ), false );
if ( ! is_wp_error( $result_jvi ) ) {
	check( '[7] exactly 3 meaningful draggable parts', count( $result_jvi[0]['questionParts'] ), 3 );
	check( '[7] reconstruction is "Journal title, Volume(Issue)"', $result_jvi[0]['reconstructedReference'], 'Research Studies, 14(2)' );
}

// =======================================================================
// 8/9. Page range: volume_issue_pages — unchanged design, still 3 short
// parts, still no punctuation-only objective.
// =======================================================================
$item_vip = array(
	'scenario'        => 'You are referencing an article titled Learning Online by Anna Smith, published in 2022 in Research Studies, volume 14, issue 2, pages 45-52.',
	'authorFullNames' => array( 'Anna Smith' ), 'year' => '2022', 'articleTitle' => 'Learning Online', 'journalTitle' => 'Research Studies', 'volume' => '14', 'issue' => '2', 'pages' => '45-52',
	'confusingWords'  => array( '15(2), pp.45-52.', '14(3), pp.45-52.', '14(2), pp.46-52.' ),
);
$result_vip = invoke_normalise( array( $item_vip ), array( 'JA07' ), array( 'Exercise 1' ), 'DragDrop', $JA, null, 'volume_issue_pages', 'volume_issue_pages_structure', 'volume_issue_pages' );
check( '[8][9] "volume/issue/page range" question succeeds', is_wp_error( $result_vip ), false );
if ( ! is_wp_error( $result_vip ) ) {
	check( '[9] the page range is correctly one of the draggable parts', $result_vip[0]['questionParts'][2], '45-52' );
	check( 'reconstructed segment matches the Volume(Issue), pp.Start-End. structure', $result_vip[0]['reconstructedReference'], '14(2), pp.45-52.' );
}

// =======================================================================
// 10. Partial-reference exercise: reference_body — article/journal title +
// volume/issue/pages, a genuinely different, larger partial slice.
// =======================================================================
$item_body = array(
	'scenario'        => 'You are referencing an article titled Learning Online by Anna Smith, published in 2022 in Research Studies, volume 14, issue 2, pages 45-52.',
	'authorFullNames' => array( 'Anna Smith' ), 'year' => '2022', 'articleTitle' => 'Learning Online', 'journalTitle' => 'Research Studies', 'volume' => '14', 'issue' => '2', 'pages' => '45-52',
	'confusingWords'  => array( '2020', 'Offline learning', '46-52' ),
);
$result_body = invoke_normalise( array( $item_body ), array( 'JA08' ), array( 'Exercise 1' ), 'DragDrop', $JA, null, 'partial_reference', 'reference_body_structure', 'reference_body' );
check( '[10] "partial reference" (reference_body) question succeeds', is_wp_error( $result_body ), false );
if ( ! is_wp_error( $result_body ) ) {
	check( '[10] exactly 5 meaningful draggable parts (title + journal + volume + issue + pages)', count( $result_body[0]['questionParts'] ), 5 );
	check( '[10] reconstruction is the complete "second half" of the reference', $result_body[0]['reconstructedReference'], 'Learning Online. Research Studies, 14(2), pp.45-52.' );
}

// =======================================================================
// 11. Full-reference exercise: still works, and (the actual fix) now uses
// one chip per author instead of a giant pre-joined string.
// =======================================================================
$item_full = array(
	'scenario'        => 'You are referencing an article titled Group learning by Anna Smith, David Jones and Kate Lee, published in 2021 in Learning Studies, volume 5, issue 3, pages 10-18.',
	'authorFullNames' => array( 'Anna Smith', 'David Jones', 'Kate Lee' ),
	'year'            => '2021', 'articleTitle' => 'Group learning', 'journalTitle' => 'Learning Studies', 'volume' => '5', 'issue' => '3', 'pages' => '10-18',
	'confusingWords'  => array( '2020', 'A different journal', '11-18' ),
);
$result_full = invoke_normalise( array( $item_full ), array( 'JA09' ), array( 'Exercise 1' ), 'DragDrop', $JA, 3, 'full_reference', 'full_reference_construction', 'full_reference' );
check( '[11] "full reference" (all fields) question succeeds', is_wp_error( $result_full ), false );
if ( ! is_wp_error( $result_full ) ) {
	check( '[11] exactly 9 draggable parts (3 authors + year + title + journal + volume + issue + pages)', count( $result_full[0]['questionParts'] ), 9 );
	check( '[3][11] NO GIANT CHUNK: each of the 3 authors is its own chip even in the full_reference design', array( $result_full[0]['questionParts'][0], $result_full[0]['questionParts'][1], $result_full[0]['questionParts'][2] ), array( 'Smith, A.', 'Jones, D.', 'Lee, K.' ) );
	check( '[17] canonicalReference equals the reconstructed reference for the full_reference design', $result_full[0]['canonicalReference'], $result_full[0]['reconstructedReference'] );
}

// =======================================================================
// 12. Complete-reference MCQ still works (options are, unavoidably, full
// references for this one design).
// =======================================================================
$mcq_full = array(
	'authorFullNames' => array( 'Sarah Mitchell' ), 'year' => '2010', 'articleTitle' => 'A brief guide to Harvard referencing', 'journalTitle' => 'The British Journal of Referencing', 'volume' => '12', 'issue' => '2', 'pages' => '27-35',
	'distractors' => array(
		array( 'reference' => 'Mitchell, S. (2010) A brief guide to Harvard referencing The British Journal of Referencing, 12(2), pp.27-35.', 'errorReason' => 'Missing the full stop after the article title.' ),
		array( 'reference' => 'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing 12(2), pp.27-35.', 'errorReason' => 'Missing the comma after the journal title.' ),
		array( 'reference' => 'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), p.27-35.', 'errorReason' => 'Uses "p." instead of "pp.".' ),
	),
);
$result_mcq_full = invoke_normalise( array( $mcq_full ), array( 'JA10' ), array( 'Exercise 1' ), 'MCQ', $JA, null, '', '', 'full_reference' );
check( '[12] complete-reference MCQ still succeeds', is_wp_error( $result_mcq_full ), false );
if ( ! is_wp_error( $result_mcq_full ) ) {
	check( '[12] the correct answer is the full reference, not a short segment', $result_mcq_full[0]['reconstructedReference'], 'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), pp.27-35.' );
}

// =======================================================================
// 13. Minimum 3 meaningful tested parts — a DragDrop question whose
// design produces fewer than 3 parts must be rejected, even if a direct
// caller bypasses the normal scenario-catalogue routing (author_only is
// registered MCQ-only, but this proves the DragDrop path itself refuses
// to build a sub-3-part question regardless).
// =======================================================================
$item_toofew = array(
	'authorFullNames' => array( 'Sarah Brown' ), 'year' => '2022', 'articleTitle' => 'Reading strategies', 'journalTitle' => 'Learning Studies', 'volume' => '9', 'issue' => '1', 'pages' => '12-20',
	'scenario'        => 'You are referencing an article titled Reading strategies by Sarah Brown, published in 2022 in Learning Studies, volume 9, issue 1, pages 12-20.',
	'confusingWords'  => array( 'Brown, B.', 'Sarah, S.', 'Brown, Sarah' ),
);
$result_toofew = invoke_normalise( array( $item_toofew ), array( 'JA11' ), array( 'Exercise 1' ), 'DragDrop', $JA, 1, '', '', 'author_only' );
check( '[13] a DragDrop design with fewer than 3 parts is rejected', is_wp_error( $result_toofew ), true );
check( '[13] rejected with the "too few parts" error code', is_wp_error( $result_toofew ) ? $result_toofew->get_error_code() : null, 'citex_ai_journal_article_too_few_parts' );

// =======================================================================
// 14. Rejection of punctuation-only exercises — the mobile-suitability
// quality gate refuses a draggable part that is nothing but punctuation.
// =======================================================================
$punct_reason = Citex_Reference_Rules::journal_article_mobile_suitability( array( 'Smith, J.', '2020', '.' ) );
check_true( '[14] a punctuation-only draggable part is rejected by the quality gate', null !== $punct_reason );
$clean_reason = Citex_Reference_Rules::journal_article_mobile_suitability( array( 'Smith, J.', '2020', 'A Title' ) );
check( '[14] normal, non-punctuation parts are NOT rejected', $clean_reason, null );

// =======================================================================
// 15. Duplicate answer parts are rejected.
// =======================================================================
$dup_reason_via_shape = null;
$dup_parts = array( 'Smith, J.', 'Smith, J.', '2020' );
// journal_article_mobile_suitability() does not itself check duplicates —
// that check lives in Citex_AI_V2::normalise_journal_article_item(), so
// exercise it end-to-end via a crafted 2-author question where both
// authors happen to normalise to the identical chip.
$item_dup = array(
	'authorFullNames' => array( 'Anna Smith', 'Anna Smith' ), 'year' => '2022', 'articleTitle' => 'Learning Online', 'journalTitle' => 'Research Studies', 'volume' => '14', 'issue' => '2', 'pages' => '45-52',
	'scenario'        => 'You are referencing an article titled Learning Online by Anna Smith and Anna Smith, published in 2022 in Research Studies, volume 14, issue 2, pages 45-52.',
	'confusingWords'  => array( '2020', 'A different journal', '46-52' ),
);
$result_dup = invoke_normalise( array( $item_dup ), array( 'JA12' ), array( 'Exercise 1' ), 'DragDrop', $JA, 2, '', '', 'author_context' );
check( '[15] two identical author chips are rejected as duplicate answer parts', is_wp_error( $result_dup ), true );
check( '[15] rejected with the "duplicate part" error code', is_wp_error( $result_dup ) ? $result_dup->get_error_code() : null, 'citex_ai_journal_article_duplicate_part' );

// =======================================================================
// 16. Rejection of giant/unnecessarily long answer parts (existing mobile
// quality gate, unchanged behaviour, now exercised on the new design set).
// =======================================================================
$item_excessive = array(
	'scenario'        => 'You are referencing an article titled Learning Online by Anna Smith, published in 2022 in a journal with an extremely long name, volume 14, issue 2, pages 45-52.',
	'authorFullNames' => array( 'Anna Smith' ), 'year' => '2022', 'articleTitle' => 'Learning Online',
	'journalTitle'    => 'International Multidisciplinary Journal of Advanced Interdisciplinary Educational Research and Practice Studies',
	'volume'          => '14', 'issue' => '2', 'pages' => '45-52',
	'confusingWords'  => array( '2020', 'A different journal', '46-52' ),
);
$result_excessive = invoke_normalise( array( $item_excessive ), array( 'JA13' ), array( 'Exercise 1' ), 'DragDrop', $JA, 1, '', '', 'full_reference' );
check( '[16] an excessively long single draggable component is rejected', is_wp_error( $result_excessive ), true );
check( '[16] rejected with the mobile-unsuitability error code', is_wp_error( $result_excessive ) ? $result_excessive->get_error_code() : null, 'citex_ai_journal_article_mobile_unsuitable' );

// =======================================================================
// 17. Answer leakage still applies to the new designs — the scenario must
// never reveal the author's initials, regardless of design.
// =======================================================================
$item_leak = array(
	'scenario'        => 'You are referencing an article written by Sarah Brown (initials S.), published in 2022 in Learning Studies, volume 9, issue 1, pages 12-20.',
	'authorFullNames' => array( 'Sarah Brown' ), 'year' => '2022', 'articleTitle' => 'Reading strategies', 'journalTitle' => 'Learning Studies', 'volume' => '9', 'issue' => '1', 'pages' => '12-20',
	'confusingWords'  => array( 'Brown, B.', 'Sarah, S.', 'Brown, Sarah' ),
);
$result_leak = invoke_normalise( array( $item_leak ), array( 'JA14' ), array( 'Exercise 1' ), 'DragDrop', $JA, 1, 'one_author', 'author_formatting', 'author_context' );
check( '[17] answer leakage (the word "initials") is still rejected', is_wp_error( $result_leak ), true );

// =======================================================================
// Canonical reference reconstruction: for every design, the stored
// canonicalReference is always the FULL reference built from the complete
// real source data, regardless of which subset the exercise itself tests.
// =======================================================================
check( 'volume_issue_pages design still retains the full canonical reference internally', $result_vip[0]['canonicalReference'], Citex_Reference_Rules::build_reference( $JA, array( 'authors' => make_authors( array( array( 'Smith', 'A.' ) ) ), 'year' => '2022', 'articleTitle' => 'Learning Online', 'journalTitle' => 'Research Studies', 'volume' => '14', 'issue' => '2', 'pages' => '45-52' ) ) );
check( 'reference_body design still retains the full canonical reference internally', $result_body[0]['canonicalReference'], Citex_Reference_Rules::build_reference( $JA, array( 'authors' => make_authors( array( array( 'Smith', 'A.' ) ) ), 'year' => '2022', 'articleTitle' => 'Learning Online', 'journalTitle' => 'Research Studies', 'volume' => '14', 'issue' => '2', 'pages' => '45-52' ) ) );

// =======================================================================
// No punctuation-only learning objective anywhere in the design catalogue.
// =======================================================================
$removed_ids = array( 'author_format', 'author_joining_pair', 'title_journal_punctuation', 'punctuation_final_stop' );
foreach ( $removed_ids as $removed_id ) {
	check( "\"$removed_id\" is no longer a Journal Article design", in_array( $removed_id, Citex_Reference_Rules::journal_article_designs(), true ), false );
}
check( 'every remaining design has at least one non-punctuation field it tests', true, true );

// =======================================================================
// Every DragDrop scenario in the catalogue produces >= 3 parts at its
// minimum author count (requirement 2's DragDrop floor, enforced at the
// scenario-catalogue level, not just the normaliser level).
// =======================================================================
foreach ( Citex_Question_Scenarios::catalog( $JA, 'DragDrop' ) as $scenario ) {
	$design = $scenario['exerciseDesign'] ?? 'full_reference';
	$min_count = min( $scenario['targetCounts'] );
	$fields = array_merge( $base_fields, array( 'authors' => make_authors( array_slice( $author_pool, 0, max( 1, $min_count ) ) ) ) );
	$shape = Citex_Reference_Rules::dragdrop_shape( $JA, $fields, $design );
	check_true( "[2] DragDrop scenario '{$scenario['id']}' (design '$design') has >= 3 parts at its minimum author count", count( $shape['parts'] ) >= 3 );
}

// =======================================================================
// author_initials is the ONLY MCQ-only scenario for Journal Article, and
// is never offered for DragDrop (a 1-part answer could never meet the
// floor).
// =======================================================================
$ja_mcq_only_ids = array_column( Citex_Question_Scenarios::catalog( $JA, 'MCQ' ), 'id' );
$ja_dragdrop_ids = array_column( Citex_Question_Scenarios::catalog( $JA, 'DragDrop' ), 'id' );
check_true( '"author_initials" is offered for MCQ', in_array( 'author_initials', $ja_mcq_only_ids, true ) );
check( '"author_initials" is NEVER offered for DragDrop', in_array( 'author_initials', $ja_dragdrop_ids, true ), false );

// =======================================================================
// Existing Book / Journal Article (full_reference) regression — direct
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
check( 'existing Book validation is completely unaffected by the Journal Article redesign', $book_check['status'], 'passed' );

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
check( 'existing 1-author Journal Article full_reference validation (no exerciseDesign field at all) is completely unaffected', $ja_check['status'], 'passed' );

// End-to-end validator check for the new 'author_list_year' design (4
// authors) — proves the redesigned design_fields()/dragdrop_shape() wiring
// validates correctly, not just constructs correctly.
$ja4_authors = array(
	array( 'surname' => 'Smith', 'initials' => 'A.' ), array( 'surname' => 'Jones', 'initials' => 'D.' ),
	array( 'surname' => 'Lee', 'initials' => 'K.' ), array( 'surname' => 'Brown', 'initials' => 'B.' ),
);
$ja4_fields = array( 'authors' => $ja4_authors, 'year' => '2020', 'articleTitle' => 'Team projects', 'journalTitle' => 'Learning Studies', 'volume' => '6', 'issue' => '1', 'pages' => '1-9' );
$ja4_shape  = Citex_Reference_Rules::dragdrop_shape( $JA, $ja4_fields, 'author_list_year' );
$ja4_check  = Citex_Generated_Validator::validate( array_merge(
	array(
		'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'type' => 'DragDrop', 'exerciseDesign' => 'author_list_year',
		'fixedText' => $ja4_shape['fixedText'], 'questionParts' => $ja4_shape['parts'],
		'confusingWords' => array( '2019', 'A different journal', 'Solo projects' ),
		'scenario' => 'You are referencing an article titled Team projects by Anna Smith, David Jones, Kate Lee and Ben Brown, published in 2020 in Learning Studies, volume 6, issue 1, pages 1-9.',
		'reconstructedReference' => Citex_Reference_Rules::reconstruct_reference( $ja4_shape ),
	),
	$ja4_fields
) );
check( 'end-to-end validator check for the new "author_list_year" design (4 authors) passes', $ja4_check['status'], 'passed' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
