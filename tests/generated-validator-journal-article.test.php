<?php
/**
 * Regression tests for Citex_Generated_Validator's DEDICATED Journal Article
 * support — a genuinely separate check (validate_journal_article_consistency())
 * from Book's/Edited Book's, not a reuse of either: no place/publisher
 * concept, a constant 7-part DragDrop shape for every author count, an
 * independent reconstruction of the expected reference from canonical data
 * (JOURNAL_ARTICLE_RECONSTRUCTION_MISMATCH), and an explicit "et al. is
 * never valid" check that applies starting at 1 author, not just 4+.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-journal-article.test.php` — not shipped in
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

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
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
		if ( $code === $error['code'] ) {
			return true;
		}
	}
	return false;
}

function one_author() {
	return array( array( 'surname' => 'Mitchell', 'initials' => 'S.' ) );
}
function two_authors() {
	return array(
		array( 'surname' => 'Mitchell', 'initials' => 'S.' ),
		array( 'surname' => 'Evans', 'initials' => 'D.' ),
	);
}

$JA = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;
$canonical_fields = array(
	'year'         => '2010',
	'articleTitle' => 'A brief guide to Harvard referencing',
	'journalTitle' => 'The British Journal of Referencing',
	'volume'       => '12',
	'issue'        => '2',
	'pages'        => '27-35',
);

// DragDrop questions must use one of the 3-4-part designs (see
// Citex_Reference_Rules::journal_article_dragdrop_designs()) —
// 'full_reference' (dragdrop_shape()'s default with no design given) is
// MCQ-only. 'author_year_volume_pages' (4 parts: author list, year, volume,
// pages) is used throughout this file's DragDrop fixtures.
function journal_article_dragdrop_question( $authors, $fields, $overrides = array() ) {
	$JA = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;
	$design = 'author_year_volume_pages';
	$full_fields = array_merge( $fields, array( 'authors' => $authors ) );
	$shape       = Citex_Reference_Rules::dragdrop_shape( $JA, $full_fields, $design );
	$reference   = Citex_Reference_Rules::reconstruct_reference( $shape );
	$scenario_names = implode( ' and ', array_map( function ( $a ) { return $a['surname']; }, $authors ) );
	return array_merge(
		array(
			'source'         => 'Harvard',
			'group'          => 'ReferenceList',
			'category'       => 'Journal Article',
			'type'           => 'DragDrop',
			'exerciseDesign' => $design,
			'authors'        => $authors,
			'year'           => $fields['year'],
			'articleTitle'   => $fields['articleTitle'],
			'journalTitle'   => $fields['journalTitle'],
			'volume'         => $fields['volume'],
			'issue'          => $fields['issue'],
			'pages'          => $fields['pages'],
			'fixedText'      => $shape['fixedText'],
			'questionParts'  => $shape['parts'],
			'confusingWords' => array( '2015', 'A different journal name', '99-100' ),
			'scenario'       => "You are referencing a journal article titled {$fields['articleTitle']} by {$scenario_names}, published in {$fields['year']} in {$fields['journalTitle']}, volume {$fields['volume']}, issue {$fields['issue']}, pages {$fields['pages']}.",
			'reconstructedReference' => $reference,
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// A fully correct Journal Article DragDrop question passes (1 author).
// ---------------------------------------------------------------------
$q1 = journal_article_dragdrop_question( one_author(), $canonical_fields );
$r1 = Citex_Generated_Validator::validate( $q1 );
check( '[1] a correct 1-author DragDrop question passes', $r1['status'], 'passed' );
check( '[1] no errors reported', $r1['errors'], array() );
check( '[1] the reconstructed reference matches the design\'s own reconstruction', $r1['reconstructedReference'], Citex_Reference_Rules::reconstruct_reference( Citex_Reference_Rules::dragdrop_shape( $JA, array_merge( $canonical_fields, array( 'authors' => one_author() ) ), 'author_year_volume_pages' ) ) );

// ---------------------------------------------------------------------
// A fully correct 2-author question also passes.
// ---------------------------------------------------------------------
$q2 = journal_article_dragdrop_question( two_authors(), $canonical_fields );
$r2 = Citex_Generated_Validator::validate( $q2 );
check( '[2] a correct 2-author DragDrop question passes', $r2['status'], 'passed' );

// ---------------------------------------------------------------------
// 7. Incorrect initials fail — the reconstructed reference no longer
// contains the canonical initials, so both the per-fact check and the
// independent reconstruction comparison catch it.
// ---------------------------------------------------------------------
$wrong_initials_authors = array( array( 'surname' => 'Mitchell', 'initials' => 'X.' ) );
$q_wrong_initials = journal_article_dragdrop_question( one_author(), $canonical_fields, array(
	'reconstructedReference' => 'Mitchell, X. (2010) 12, pp.27-35.',
	'questionParts'          => array( 'Mitchell, X.', '2010', '12', '27-35' ),
) );
$r_wrong_initials = Citex_Generated_Validator::validate( $q_wrong_initials );
check( '[7] incorrect initials in the reconstructed reference fail', $r_wrong_initials['status'], 'failed' );
check( '[7] reports JOURNAL_ARTICLE_RECONSTRUCTION_MISMATCH', has_error_code( $r_wrong_initials, 'journal_article_reconstruction_mismatch' ), true );

// ---------------------------------------------------------------------
// 9. "et al." in the reconstructed reference is rejected outright, for a
// SINGLE author too (not just 4+) — the one Liverpool Hope misconception
// this category exists to test.
// ---------------------------------------------------------------------
$q_et_al = journal_article_dragdrop_question( one_author(), $canonical_fields, array(
	'reconstructedReference' => 'Mitchell et al. (2010) 12, pp.27-35.',
	'questionParts'          => array( 'Mitchell et al.', '2010', '12', '27-35' ),
	'fixedText'              => '| (||) ||, pp.||.',
) );
$r_et_al = Citex_Generated_Validator::validate( $q_et_al );
check( '[9] "et al." in the reference is rejected', $r_et_al['status'], 'failed' );
check( '[9] reports JOURNAL_ARTICLE_ET_AL_USED', has_error_code( $r_et_al, 'journal_article_et_al_used' ), true );

// ---------------------------------------------------------------------
// 10 & 11. Correct year passes (already proven by [1]); an incorrect year
// (reconstructed reference disagrees with the canonical year field) fails.
// ---------------------------------------------------------------------
$q_wrong_year = journal_article_dragdrop_question( one_author(), $canonical_fields, array(
	'reconstructedReference' => 'Mitchell, S. (1999) 12, pp.27-35.',
	'questionParts'          => array( 'Mitchell, S.', '1999', '12', '27-35' ),
) );
$r_wrong_year = Citex_Generated_Validator::validate( $q_wrong_year );
check( '[11] an incorrect year fails', $r_wrong_year['status'], 'failed' );
check( '[11] reports JOURNAL_ARTICLE_RECONSTRUCTION_MISMATCH', has_error_code( $r_wrong_year, 'journal_article_reconstruction_mismatch' ), true );

// ---------------------------------------------------------------------
// 12. Missing comma before "pp." fails the shared format check (shared
// across every category) — for the 'author_year_volume_pages' design's
// "Author (Year) Volume, pp.Start-End." shape.
// ---------------------------------------------------------------------
$q_missing_pp_comma = journal_article_dragdrop_question( one_author(), $canonical_fields, array(
	'fixedText' => '| (||) || pp.||.',
) );
$r_missing_pp_comma = Citex_Generated_Validator::validate( $q_missing_pp_comma );
check( '[12] missing comma before "pp." fails', $r_missing_pp_comma['status'], 'failed' );
check( '[12] reports JOURNAL_ARTICLE_FORMAT_MISMATCH (Journal Article\'s own format code, not Book\'s)', has_error_code( $r_missing_pp_comma, 'journal_article_format_mismatch' ), true );

// ---------------------------------------------------------------------
// 13. Year not wrapped in parentheses fails the same shape check.
// ---------------------------------------------------------------------
$q_missing_year_parens = journal_article_dragdrop_question( one_author(), $canonical_fields, array(
	'fixedText' => '| || ||, pp.||.',
) );
$r_missing_year_parens = Citex_Generated_Validator::validate( $q_missing_year_parens );
check( '[13] a year not wrapped in parentheses fails', $r_missing_year_parens['status'], 'failed' );
check( '[13] reports JOURNAL_ARTICLE_FORMAT_MISMATCH', has_error_code( $r_missing_year_parens, 'journal_article_format_mismatch' ), true );

// ---------------------------------------------------------------------
// 15 & 16. Page range: missing the "pp." prefix fails.
// ---------------------------------------------------------------------
$q_missing_pp = journal_article_dragdrop_question( one_author(), $canonical_fields, array(
	'fixedText' => '| (||) ||, ||.',
) );
$r_missing_pp = Citex_Generated_Validator::validate( $q_missing_pp );
check( '[16] a missing "pp." prefix fails', $r_missing_pp['status'], 'failed' );
check( '[16] reports JOURNAL_ARTICLE_FORMAT_MISMATCH', has_error_code( $r_missing_pp, 'journal_article_format_mismatch' ), true );

// ---------------------------------------------------------------------
// 17. DragDrop placeholder reconstruction: Question Parts not matching the
// canonical 4-part shape fails (JOURNAL_ARTICLE_PARTS_MISMATCH).
// ---------------------------------------------------------------------
$q_bad_parts = journal_article_dragdrop_question( one_author(), $canonical_fields, array(
	'questionParts' => array( 'Mitchell, S.', '2010', '99', '27-35' ),
) );
$r_bad_parts = Citex_Generated_Validator::validate( $q_bad_parts );
check( '[17] Question Parts not matching the canonical record fail', $r_bad_parts['status'], 'failed' );
check( '[17] reports JOURNAL_ARTICLE_PARTS_MISMATCH', has_error_code( $r_bad_parts, 'journal_article_parts_mismatch' ), true );

$q_wrong_placeholder_count = journal_article_dragdrop_question( one_author(), $canonical_fields, array(
	'fixedText' => '| (||) ||.',
) );
$r_wrong_placeholder_count = Citex_Generated_Validator::validate( $q_wrong_placeholder_count );
check( '[17] a fixedText with the wrong placeholder count (3, not 4) fails', $r_wrong_placeholder_count['status'], 'failed' );
check( '[17] reports PLACEHOLDER_COUNT_MISMATCH', has_error_code( $r_wrong_placeholder_count, 'placeholder_count_mismatch' ), true );

// ---------------------------------------------------------------------
// 19. Scenario/source mismatch: the scenario names a different year than
// the canonical record. (The 'author_year_volume_pages' design's tested
// fields are authors/year/volume/pages — NOT articleTitle — so a scenario
// mismatch is exercised on a field this design actually checks.)
// ---------------------------------------------------------------------
$q_scenario_mismatch = journal_article_dragdrop_question( one_author(), $canonical_fields, array(
	'scenario' => 'You are referencing a journal article titled A brief guide to Harvard referencing by Sarah Mitchell, published in 1975 in The British Journal of Referencing, volume 12, issue 2, pages 27-35.',
) );
$r_scenario_mismatch = Citex_Generated_Validator::validate( $q_scenario_mismatch );
check( '[19] a scenario naming a different year fails', $r_scenario_mismatch['status'], 'failed' );
check( '[19] reports JOURNAL_ARTICLE_SCENARIO_MISMATCH', has_error_code( $r_scenario_mismatch, 'journal_article_scenario_mismatch' ), true );

// ---------------------------------------------------------------------
// 20. Answer leakage: a scenario naming the author's initials directly
// fails (the shared, category-generic answer-leakage check already applies
// to any `authors` array, including Journal Article's).
// ---------------------------------------------------------------------
$q_leak = journal_article_dragdrop_question( one_author(), $canonical_fields, array(
	'scenario' => 'You are referencing a journal article titled A brief guide to Harvard referencing by Mitchell, S., published in 2010 in The British Journal of Referencing, volume 12, issue 2, pages 27-35.',
) );
$r_leak = Citex_Generated_Validator::validate( $q_leak );
check( '[20] a scenario containing an abbreviated citation ("Mitchell, S.") fails', $r_leak['status'], 'failed' );
check( '[20] reports ANSWER_LEAKAGE_ABBREVIATED_CITATION', has_error_code( $r_leak, 'answer_leakage_abbreviated_citation' ), true );

// ---------------------------------------------------------------------
// 23. MCQ: a correct Journal Article MCQ question passes, and the shared
// MCQ shape rules (exactly 4 options, option 4 blank, answer never
// duplicated into an option) all apply exactly as they do for Book/Edited
// Book.
// ---------------------------------------------------------------------
function journal_article_mcq_question( $authors, $fields, $overrides = array() ) {
	$JA = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;
	$full_fields = array_merge( $fields, array( 'authors' => $authors ) );
	$reference   = Citex_Reference_Rules::build_reference( $JA, $full_fields );
	return array_merge(
		array(
			'source'      => 'Harvard',
			'group'       => 'ReferenceList',
			'category'    => 'Journal Article',
			'type'        => 'MCQ',
			'authors'     => $authors,
			'year'        => $fields['year'],
			'articleTitle'=> $fields['articleTitle'],
			'journalTitle'=> $fields['journalTitle'],
			'volume'      => $fields['volume'],
			'issue'       => $fields['issue'],
			'pages'       => $fields['pages'],
			'scenario'    => Citex_Reference_Rules::mcq_question_stem( $JA ),
			'options'     => array(
				'Mitchell, S. (2010) A brief guide to Harvard referencing The British Journal of Referencing, 12(2), pp.27-35.',
				'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing 12(2), pp.27-35.',
				'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), p.27-35.',
				'',
			),
			'hint'        => Citex_Reference_Rules::mcq_hint( $JA ),
			'reconstructedReference' => $reference,
		),
		$overrides
	);
}
$mcq_q = journal_article_mcq_question( one_author(), $canonical_fields );
$mcq_r = Citex_Generated_Validator::validate( $mcq_q );
check( '[23] a correct Journal Article MCQ question passes', $mcq_r['status'], 'passed' );
check( '[23] no errors reported', $mcq_r['errors'], array() );

$mcq_bad_count = journal_article_mcq_question( one_author(), $canonical_fields, array( 'options' => array( 'a', 'b', 'c' ) ) );
$mcq_bad_count_r = Citex_Generated_Validator::validate( $mcq_bad_count );
check( '[23] not exactly 4 options fails', $mcq_bad_count_r['status'], 'failed' );
check( '[23] reports MCQ_OPTION_COUNT_MISMATCH', has_error_code( $mcq_bad_count_r, 'mcq_option_count_mismatch' ), true );

$mcq_option_matches_answer = journal_article_mcq_question( one_author(), $canonical_fields );
$mcq_option_matches_answer['options'][0] = $mcq_option_matches_answer['reconstructedReference'];
$mcq_option_matches_answer_r = Citex_Generated_Validator::validate( $mcq_option_matches_answer );
check( '[23] an option matching the correct answer fails', $mcq_option_matches_answer_r['status'], 'failed' );
check( '[23] reports MCQ_OPTION_MATCHES_ANSWER', has_error_code( $mcq_option_matches_answer_r, 'mcq_option_matches_answer' ), true );

$mcq_distractor_looks_correct = journal_article_mcq_question( one_author(), $canonical_fields );
$mcq_distractor_looks_correct['options'][0] = Citex_Reference_Rules::build_reference( $JA, array_merge( $canonical_fields, array( 'authors' => two_authors() ) ) );
$mcq_distractor_looks_correct_r = Citex_Generated_Validator::validate( $mcq_distractor_looks_correct );
check( '[18][23] a distractor that is itself a fully valid Harvard reference fails (creates a second plausible answer)', $mcq_distractor_looks_correct_r['status'], 'failed' );
check( '[18][23] reports MCQ_DISTRACTOR_LOOKS_CORRECT', has_error_code( $mcq_distractor_looks_correct_r, 'mcq_distractor_looks_correct' ), true );

// ---------------------------------------------------------------------
// 21 & 24. Category assignment / the general dispatcher: a Journal Article
// record with category "Journal Article" is routed to validate_dragdrop()/
// validate_mcq() exactly like Book/Edited Book — validate() no longer
// rejects it as an UNSUPPORTED_GENERATED_FORMAT, which is what "a question
// without the correct category must not be considered complete" ultimately
// depends on downstream (Citex_Populator resolves the SAME `category` field
// to a real WordPress taxonomy term — see class-citex-populator.php).
// ---------------------------------------------------------------------
check( '[21] Journal Article is a supported generated category (no UNSUPPORTED_GENERATED_FORMAT)', has_error_code( $r1, 'unsupported_generated_format' ), false );

$unsupported = Citex_Generated_Validator::validate( array( 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Not A Real Category', 'type' => 'DragDrop' ) );
check( '[21] an unrecognised category is still rejected as unsupported (no accidental wildcard match)', has_error_code( $unsupported, 'unsupported_generated_format' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
