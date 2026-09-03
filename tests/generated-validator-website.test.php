<?php
/**
 * Regression tests for Citex_Generated_Validator's DEDICATED Website/Web
 * Resource support — a genuinely separate check
 * (validate_website_consistency()) from Book's/Edited Book's/Journal
 * Article's, not a reuse of any of them: only ONE author-or-organisation
 * (no joining rule), a year-or-"n.d." field, no place, and a URL/accessed-
 * date pair no other category has. Independently reconstructs the expected
 * reference from canonical data (WEBSITE_RECONSTRUCTION_MISMATCH).
 *
 * Repo-level only, run with plain `php tests/generated-validator-website.test.php`
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

$WR = Citex_Reference_Rules::CATEGORY_WEBSITE;
$individual = array( 'type' => 'individual', 'surname' => 'Mitchell', 'initials' => 'S.' );
$organisation = array( 'type' => 'organisation', 'name' => 'University of Leeds' );
$canonical_dated = array( 'year' => '2024', 'title' => 'Study skills guide', 'publisher' => 'University of Leeds', 'url' => 'https://www.leeds.ac.uk/study-skills', 'accessedDate' => '3 September 2026' );
$canonical_undated = array( 'year' => 'n.d.', 'title' => 'About us', 'publisher' => 'University of Leeds', 'url' => 'https://www.leeds.ac.uk/about', 'accessedDate' => '3 September 2026' );

function website_dragdrop_question( $author, $fields, $overrides = array() ) {
	$WR = Citex_Reference_Rules::CATEGORY_WEBSITE;
	$full_fields = array_merge( $fields, array( 'author' => $author ) );
	$reference   = Citex_Reference_Rules::build_reference( $WR, $full_fields );
	$shape       = Citex_Reference_Rules::dragdrop_shape( $WR, $full_fields );
	$name = 'individual' === $author['type'] ? $author['surname'] : $author['name'];
	return array_merge(
		array(
			'source'           => 'Harvard',
			'group'            => 'ReferenceList',
			'category'         => 'Website',
			'type'             => 'DragDrop',
			'authorType'       => $author['type'],
			'authors'          => 'individual' === $author['type'] ? array( $author ) : array(),
			'organisationName' => 'organisation' === $author['type'] ? $author['name'] : '',
			'year'             => $fields['year'],
			'title'            => $fields['title'],
			'publisher'        => $fields['publisher'],
			'url'              => $fields['url'],
			'accessedDate'     => $fields['accessedDate'],
			'fixedText'        => $shape['fixedText'],
			'questionParts'    => $shape['parts'],
			'confusingWords'   => array( '2015', 'A different publisher', 'https://example.com/wrong' ),
			'scenario'         => "You are referencing a webpage titled {$fields['title']}, at {$fields['url']}, written by {$name} and published by {$fields['publisher']}.",
			'reconstructedReference' => $reference,
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1 & 6-19 (correct case): a fully correct individual-author, dated
// DragDrop question passes.
// ---------------------------------------------------------------------
$q1 = website_dragdrop_question( $individual, $canonical_dated );
$r1 = Citex_Generated_Validator::validate( $q1 );
check( '[1][6][8][10][12][14][17][19] a correct individual-author, dated DragDrop question passes', $r1['status'], 'passed' );
check( 'no errors reported', $r1['errors'], array() );
check( 'the reconstructed reference matches build_reference()\'s output', $r1['reconstructedReference'], Citex_Reference_Rules::build_reference( $WR, array_merge( $canonical_dated, array( 'author' => $individual ) ) ) );

// ---------------------------------------------------------------------
// 2 & 4: a fully correct organisation-author, undated ("n.d.") question
// also passes.
// ---------------------------------------------------------------------
$q2 = website_dragdrop_question( $organisation, $canonical_undated );
$r2 = Citex_Generated_Validator::validate( $q2 );
check( '[2][4] a correct organisation-author, undated DragDrop question passes', $r2['status'], 'passed' );

// ---------------------------------------------------------------------
// 5. A PDF document source (organisation author, real content variety, no
// structural difference) also passes.
// ---------------------------------------------------------------------
$pdf_author = array( 'type' => 'organisation', 'name' => 'Department for Business Innovation and Skills' );
$pdf_fields = array( 'year' => '2009', 'title' => 'Higher ambitions: the future of universities in a knowledge economy', 'publisher' => 'Department for Business Innovation and Skills', 'url' => 'https://www.gov.uk/government/publications/higher-ambitions', 'accessedDate' => '20 May 2025' );
$q5 = website_dragdrop_question( $pdf_author, $pdf_fields );
$r5 = Citex_Generated_Validator::validate( $q5 );
check( '[5] a correct PDF-document source question passes (identical structure to a webpage)', $r5['status'], 'passed' );

// ---------------------------------------------------------------------
// 7. Missing "[online]" fails.
// ---------------------------------------------------------------------
$q7 = website_dragdrop_question( $individual, $canonical_dated, array( 'fixedText' => '| (||) || ||. Available from: <||> [accessed ||].' ) );
$r7 = Citex_Generated_Validator::validate( $q7 );
check( '[7] missing "[online]" fails', $r7['status'], 'failed' );
check( '[7] reports WEBSITE_FORMAT_MISMATCH', has_error_code( $r7, 'website_format_mismatch' ), true );

// ---------------------------------------------------------------------
// 9. Missing publisher fails.
// ---------------------------------------------------------------------
$q9 = website_dragdrop_question( $individual, array_merge( $canonical_dated, array( 'publisher' => '' ) ) );
$r9 = Citex_Generated_Validator::validate( $q9 );
check( '[9] missing publisher fails', $r9['status'], 'failed' );

// ---------------------------------------------------------------------
// 11. "Available from" missing its colon fails.
// ---------------------------------------------------------------------
$q11 = website_dragdrop_question( $individual, $canonical_dated, array( 'fixedText' => '| (||) || [online]. ||. Available from <||> [accessed ||].' ) );
$r11 = Citex_Generated_Validator::validate( $q11 );
check( '[11] "Available from" missing its colon fails', $r11['status'], 'failed' );
check( '[11] reports WEBSITE_FORMAT_MISMATCH', has_error_code( $r11, 'website_format_mismatch' ), true );

// ---------------------------------------------------------------------
// 13. An invalid/malformed URL fails.
// ---------------------------------------------------------------------
$q13 = website_dragdrop_question( $individual, array_merge( $canonical_dated, array( 'url' => 'not a valid url' ) ), array(
	'questionParts' => array( 'Mitchell, S.', '2024', 'Study skills guide', 'University of Leeds', 'not a valid url', '3 September 2026' ),
	'fixedText' => '| (||) || [online]. ||. Available from: <||> [accessed ||].',
	'reconstructedReference' => 'Mitchell, S. (2024) Study skills guide [online]. University of Leeds. Available from: <not a valid url> [accessed 3 September 2026].',
) );
$r13 = Citex_Generated_Validator::validate( $q13 );
check( '[13] an invalid URL fails', $r13['status'], 'failed' );
check( '[13] reports WEBSITE_URL_MALFORMED', has_error_code( $r13, 'website_url_malformed' ), true );

// ---------------------------------------------------------------------
// 15. A missing accessed date fails.
// ---------------------------------------------------------------------
$q15 = website_dragdrop_question( $individual, array_merge( $canonical_dated, array( 'accessedDate' => '' ) ) );
$r15 = Citex_Generated_Validator::validate( $q15 );
check( '[15] a missing accessed date fails', $r15['status'], 'failed' );
check( '[15] reports WEBSITE_ACCESSED_DATE_MISSING', has_error_code( $r15, 'website_accessed_date_missing' ), true );

// ---------------------------------------------------------------------
// 16. An incorrect accessed date (reconstructed reference disagrees with
// the canonical accessedDate field) fails via independent reconstruction.
// ---------------------------------------------------------------------
$q16 = website_dragdrop_question( $individual, $canonical_dated, array(
	'questionParts' => array( 'Mitchell, S.', '2024', 'Study skills guide', 'University of Leeds', 'https://www.leeds.ac.uk/study-skills', '1 January 2000' ),
) );
$r16 = Citex_Generated_Validator::validate( $q16 );
check( '[16] an incorrect accessed date fails', $r16['status'], 'failed' );
check( '[16] reports WEBSITE_RECONSTRUCTION_MISMATCH', has_error_code( $r16, 'website_reconstruction_mismatch' ), true );

// ---------------------------------------------------------------------
// 18. Incorrect title fails.
// ---------------------------------------------------------------------
$q18 = website_dragdrop_question( $individual, $canonical_dated, array(
	'questionParts' => array( 'Mitchell, S.', '2024', 'A completely different title', 'University of Leeds', 'https://www.leeds.ac.uk/study-skills', '3 September 2026' ),
) );
$r18 = Citex_Generated_Validator::validate( $q18 );
check( '[18] incorrect title fails', $r18['status'], 'failed' );
check( '[18] reports WEBSITE_RECONSTRUCTION_MISMATCH', has_error_code( $r18, 'website_reconstruction_mismatch' ), true );

// ---------------------------------------------------------------------
// 19. Missing final punctuation fails.
// ---------------------------------------------------------------------
$q19 = website_dragdrop_question( $individual, $canonical_dated, array(
	'fixedText' => '| (||) || [online]. ||. Available from: <||> [accessed ||]',
) );
$r19 = Citex_Generated_Validator::validate( $q19 );
check( '[19] missing final full stop fails', $r19['status'], 'failed' );
check( '[19] reports MISSING_FINAL_PERIOD', has_error_code( $r19, 'missing_final_period' ), true );

// ---------------------------------------------------------------------
// 20 & 21. Correct DragDrop placeholder reconstruction passes (already
// proven by r1); an incorrect reconstruction (wrong placeholder count)
// fails.
// ---------------------------------------------------------------------
$q21 = website_dragdrop_question( $individual, $canonical_dated, array( 'fixedText' => '| (||) || [online]. ||.' ) );
$r21 = Citex_Generated_Validator::validate( $q21 );
check( '[21] a fixedText with the wrong placeholder count (4, not 6) fails', $r21['status'], 'failed' );
check( '[21] reports PLACEHOLDER_COUNT_MISMATCH', has_error_code( $r21, 'placeholder_count_mismatch' ), true );

// ---------------------------------------------------------------------
// 22. Scenario/source mismatch: the scenario names a different title than
// the canonical record.
// ---------------------------------------------------------------------
$q22 = website_dragdrop_question( $individual, $canonical_dated, array(
	'scenario' => 'You are referencing a webpage titled A completely different title, at https://www.leeds.ac.uk/study-skills, published by Mitchell.',
) );
$r22 = Citex_Generated_Validator::validate( $q22 );
check( '[22] a scenario naming a different title fails', $r22['status'], 'failed' );
check( '[22] reports WEBSITE_SCENARIO_MISMATCH', has_error_code( $r22, 'website_scenario_mismatch' ), true );

// ---------------------------------------------------------------------
// 23. Answer leakage: (a) an abbreviated citation in the scenario fails
// (shared, category-generic check, reused via the `authors` array); (b) a
// scenario that explicitly states "(n.d.)"/"no date"/"undated" fails
// (Website-specific check).
// ---------------------------------------------------------------------
$q23a = website_dragdrop_question( $individual, $canonical_dated, array(
	'scenario' => 'You are referencing a webpage titled Study skills guide, at https://www.leeds.ac.uk/study-skills, written by Mitchell, S.',
) );
$r23a = Citex_Generated_Validator::validate( $q23a );
check( '[23] a scenario containing an abbreviated citation ("Mitchell, S.") fails', $r23a['status'], 'failed' );
check( '[23] reports ANSWER_LEAKAGE_ABBREVIATED_CITATION', has_error_code( $r23a, 'answer_leakage_abbreviated_citation' ), true );

$q23b = website_dragdrop_question( $organisation, $canonical_undated, array(
	'scenario' => 'You are referencing a University of Leeds webpage titled About us, at https://www.leeds.ac.uk/about — this page has no date, so use (n.d.).',
) );
$r23b = Citex_Generated_Validator::validate( $q23b );
check( '[23] a scenario explicitly stating "(n.d.)" fails', $r23b['status'], 'failed' );
check( '[23] reports WEBSITE_ANSWER_LEAKAGE_ND', has_error_code( $r23b, 'website_answer_leakage_nd' ), true );

// ---------------------------------------------------------------------
// 24 & 25. MCQ: a correct Website MCQ question passes, and the shared MCQ
// shape rules (exactly 4 options, option 4 blank, answer never duplicated,
// no distractor that looks fully correct) all apply exactly as they do for
// every other category.
// ---------------------------------------------------------------------
function website_mcq_question( $author, $fields, $overrides = array() ) {
	$WR = Citex_Reference_Rules::CATEGORY_WEBSITE;
	$full_fields = array_merge( $fields, array( 'author' => $author ) );
	$reference   = Citex_Reference_Rules::build_reference( $WR, $full_fields );
	return array_merge(
		array(
			'source'           => 'Harvard',
			'group'            => 'ReferenceList',
			'category'         => 'Website',
			'type'             => 'MCQ',
			'authorType'       => $author['type'],
			'authors'          => 'individual' === $author['type'] ? array( $author ) : array(),
			'organisationName' => 'organisation' === $author['type'] ? $author['name'] : '',
			'year'             => $fields['year'],
			'title'            => $fields['title'],
			'publisher'        => $fields['publisher'],
			'url'              => $fields['url'],
			'accessedDate'     => $fields['accessedDate'],
			'scenario'         => Citex_Reference_Rules::mcq_question_stem( $WR ),
			'options'          => array(
				'Mitchell, S. (2024) Study skills guide University of Leeds. Available from: <https://www.leeds.ac.uk/study-skills> [accessed 3 September 2026].',
				'Mitchell, S. (2024) Study skills guide [online]. University of Leeds. Available from <https://www.leeds.ac.uk/study-skills> [accessed 3 September 2026].',
				'Mitchell, S. (2024) Study skills guide [online]. University of Leeds. Available from: https://www.leeds.ac.uk/study-skills [accessed 3 September 2026].',
				'',
			),
			'hint'             => Citex_Reference_Rules::mcq_hint( $WR ),
			'reconstructedReference' => $reference,
		),
		$overrides
	);
}
$mcq_q = website_mcq_question( $individual, $canonical_dated );
$mcq_r = Citex_Generated_Validator::validate( $mcq_q );
check( '[24] a correct Website MCQ question passes', $mcq_r['status'], 'passed' );
check( 'no errors reported', $mcq_r['errors'], array() );

$mcq_bad_count = website_mcq_question( $individual, $canonical_dated, array( 'options' => array( 'a', 'b', 'c' ) ) );
$mcq_bad_count_r = Citex_Generated_Validator::validate( $mcq_bad_count );
check( '[24] not exactly 4 options fails', $mcq_bad_count_r['status'], 'failed' );
check( '[24] reports MCQ_OPTION_COUNT_MISMATCH', has_error_code( $mcq_bad_count_r, 'mcq_option_count_mismatch' ), true );

$mcq_option_matches_answer = website_mcq_question( $individual, $canonical_dated );
$mcq_option_matches_answer['options'][0] = $mcq_option_matches_answer['reconstructedReference'];
$mcq_option_matches_answer_r = Citex_Generated_Validator::validate( $mcq_option_matches_answer );
check( '[24] an option matching the correct answer fails', $mcq_option_matches_answer_r['status'], 'failed' );
check( '[24] reports MCQ_OPTION_MATCHES_ANSWER', has_error_code( $mcq_option_matches_answer_r, 'mcq_option_matches_answer' ), true );

$mcq_duplicate = website_mcq_question( $individual, $canonical_dated );
$mcq_duplicate['options'][1] = $mcq_duplicate['options'][0];
$mcq_duplicate_r = Citex_Generated_Validator::validate( $mcq_duplicate );
check( '[25] a duplicated distractor option fails', $mcq_duplicate_r['status'], 'failed' );
check( '[25] reports MCQ_DUPLICATE_OPTION', has_error_code( $mcq_duplicate_r, 'mcq_duplicate_option' ), true );

$mcq_distractor_looks_correct = website_mcq_question( $individual, $canonical_dated );
$mcq_distractor_looks_correct['options'][0] = Citex_Reference_Rules::build_reference( $WR, array_merge( $canonical_undated, array( 'author' => $organisation ) ) );
$mcq_distractor_looks_correct_r = Citex_Generated_Validator::validate( $mcq_distractor_looks_correct );
check( '[25] a distractor that is itself a fully valid Harvard reference fails (creates a second plausible answer)', $mcq_distractor_looks_correct_r['status'], 'failed' );
check( '[25] reports MCQ_DISTRACTOR_LOOKS_CORRECT', has_error_code( $mcq_distractor_looks_correct_r, 'mcq_distractor_looks_correct' ), true );

// ---------------------------------------------------------------------
// [category/exercise/type dispatch] Website is a supported generated
// category (no UNSUPPORTED_GENERATED_FORMAT), and an unrecognised category
// is still rejected as unsupported (no accidental wildcard match).
// ---------------------------------------------------------------------
check( 'Website is a supported generated category (no UNSUPPORTED_GENERATED_FORMAT)', has_error_code( $r1, 'unsupported_generated_format' ), false );

$unsupported = Citex_Generated_Validator::validate( array( 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Not A Real Category', 'type' => 'DragDrop' ) );
check( 'an unrecognised category is still rejected as unsupported', has_error_code( $unsupported, 'unsupported_generated_format' ), true );

// ---------------------------------------------------------------------
// 30 & 31. Existing Book / Journal Article validation is completely
// unaffected by Website support.
// ---------------------------------------------------------------------
$book_regression = Citex_Generated_Validator::validate( array(
	'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Book', 'type' => 'DragDrop',
	'authors' => array( array( 'surname' => 'Bryman', 'initials' => 'A.' ) ),
	'year' => '2012', 'bookTitle' => 'Social Research Methods', 'place' => 'Oxford', 'publisher' => 'Oxford University Press',
	'fixedText' => '|, || (||) ||. Oxford: Oxford University Press.',
	'questionParts' => array( 'Bryman', 'A.', '2012', 'Social Research Methods' ),
	'confusingWords' => array( '2010', 'London', 'Smith' ),
	'scenario' => 'You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.',
	'reconstructedReference' => 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.',
) );
check( '[30] existing Book validation is completely unaffected by Website support', $book_regression['status'], 'passed' );

$ja_regression_fields = array( 'authors' => array( array( 'surname' => 'Mitchell', 'initials' => 'S.' ) ), 'year' => '2010', 'articleTitle' => 'A brief guide to Harvard referencing', 'journalTitle' => 'The British Journal of Referencing', 'volume' => '12', 'issue' => '2', 'pages' => '27-35' );
$JA = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;
$ja_shape = Citex_Reference_Rules::dragdrop_shape( $JA, $ja_regression_fields );
$journal_article_regression = Citex_Generated_Validator::validate( array_merge(
	array(
		'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'type' => 'DragDrop',
		'fixedText' => $ja_shape['fixedText'], 'questionParts' => $ja_shape['parts'],
		'confusingWords' => array( '2015', 'A different journal', '45-52' ),
		'scenario' => 'You are referencing a journal article titled A brief guide to Harvard referencing by Sarah Mitchell, published in 2010 in The British Journal of Referencing, volume 12, issue 2, pages 27-35.',
		'reconstructedReference' => Citex_Reference_Rules::build_reference( $JA, $ja_regression_fields ),
	),
	$ja_regression_fields
) );
check( '[31] existing Journal Article validation is completely unaffected by Website support', $journal_article_regression['status'], 'passed' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
