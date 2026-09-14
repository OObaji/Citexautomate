<?php
/**
 * Regression tests for Citex_Generated_Validator's APA Phase 2 DragDrop/MCQ
 * blocks (Edited Book, Journal Article, Website) — mirrors
 * tests/generated-validator-apa-book-dragdrop.test.php/
 * generated-validator-apa-book-mcq-variant.test.php's own "tamper one
 * field, confirm the exact-match check catches it" pattern, consolidated
 * across all 3 new categories in one file.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-apa-phase2.test.php` — not shipped in
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
require __DIR__ . '/../citex-tools/includes/class-citex-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-edited-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-journal-article-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-website-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-website-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-edited-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-edited-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-journal-article-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-journal-article-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-website-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-website-mcq-variants.php';
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
		if ( $error['code'] === $code ) {
			return true;
		}
	}
	return false;
}

// ---------------------------------------------------------------------
// Edited Book DragDrop.
// ---------------------------------------------------------------------
$eb_editors = array( array( 'surname' => 'Ross', 'initials' => 'A.', 'fullName' => 'Amy Ross' ) );
$eb_fields  = array( 'year' => '2019', 'title' => 'Urban planning today', 'publisher' => 'Routledge' );
$eb_keys    = Citex_APA_Edited_Book_Dragdrop_Parts::select_parts( 'AE01', $eb_editors );
$eb_built   = Citex_APA_Edited_Book_Dragdrop_Parts::build( $eb_keys, $eb_editors, $eb_fields );
$eb_ref     = Citex_APA_Reference_Rules::build_reference( Citex_APA_Reference_Rules::CATEGORY_EDITED_BOOK, array_merge( $eb_fields, array( 'editors' => $eb_editors ) ) );
function eb_question( $keys, $built, $editors, $fields, $ref, $overrides = array() ) {
	return array_merge(
		array(
			'source' => 'APA', 'group' => 'ReferenceList', 'category' => 'Edited Book', 'type' => 'DragDrop',
			'scenario' => 'You are referencing an edited book titled ' . $fields['title'] . ' by Amy Ross, published in ' . $fields['year'] . ' by ' . $fields['publisher'] . '.',
			'dragdropPartKeys' => $keys, 'fixedText' => $built['fixedText'], 'questionParts' => $built['parts'], 'confusingWords' => $built['confusingWords'],
			'reconstructedReference' => $ref, 'editors' => $editors, 'year' => $fields['year'], 'bookTitle' => $fields['title'], 'publisher' => $fields['publisher'],
		),
		$overrides
	);
}
check( '[EB DD] a correctly-built selection passes', Citex_Generated_Validator::validate( eb_question( $eb_keys, $eb_built, $eb_editors, $eb_fields, $eb_ref ) )['status'], 'passed' );
$eb_tampered = Citex_Generated_Validator::validate( eb_question( $eb_keys, $eb_built, $eb_editors, $eb_fields, $eb_ref, array( 'fixedText' => 'X' . $eb_built['fixedText'] ) ) );
check( '[EB DD] a tampered Fixed Text fails', $eb_tampered['status'], 'failed' );
check( '[EB DD] reports APA_EDITED_BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', has_error_code( $eb_tampered, 'apa_edited_book_dragdrop_fixed_text_mismatch' ), true );

// ---------------------------------------------------------------------
// Edited Book MCQ.
// ---------------------------------------------------------------------
$eb_mcq_fields = array( 'editors' => $eb_editors, 'year' => '2019', 'title' => 'Urban planning today', 'publisher' => 'Routledge' );
$eb_variant    = Citex_APA_Edited_Book_Mcq_Variants::variant_for( 'AE01', 1 );
$eb_mcq_built  = Citex_APA_Edited_Book_Mcq_Variants::build( $eb_variant, $eb_mcq_fields );
function eb_mcq_question( $variant, $built, $editors, $fields, $overrides = array() ) {
	return array_merge(
		array(
			'source' => 'APA', 'group' => 'ReferenceList', 'category' => 'Edited Book', 'type' => 'MCQ', 'mcqPattern' => 'apa_edited_book_mcq_variant',
			'apaEditedBookMcqVariant' => $variant, 'scenario' => $built['stem'], 'editors' => $editors, 'year' => $fields['year'], 'bookTitle' => $fields['title'], 'publisher' => $fields['publisher'],
			'options' => array_merge( $built['wrongOptions'], array( '' ) ), 'hint' => 'a safe hint', 'reconstructedReference' => $built['correctAnswer'],
		),
		$overrides
	);
}
check( '[EB MCQ] a correctly-built question passes', Citex_Generated_Validator::validate( eb_mcq_question( $eb_variant, $eb_mcq_built, $eb_editors, $eb_mcq_fields ) )['status'], 'passed' );
$eb_mcq_tampered = Citex_Generated_Validator::validate( eb_mcq_question( $eb_variant, $eb_mcq_built, $eb_editors, $eb_mcq_fields, array( 'options' => array( 'Wrong 1', 'Wrong 2', 'Wrong 3', '' ) ) ) );
check( '[EB MCQ] tampered options fail', $eb_mcq_tampered['status'], 'failed' );
check( '[EB MCQ] reports APA_EDITED_BOOK_MCQ_VARIANT_OPTION_MISMATCH', has_error_code( $eb_mcq_tampered, 'apa_edited_book_mcq_variant_option_mismatch' ), true );

// ---------------------------------------------------------------------
// Journal Article DragDrop.
// ---------------------------------------------------------------------
$ja_authors = array( array( 'surname' => 'Smith', 'initials' => 'J.', 'fullName' => 'John Smith' ) );
$ja_fields  = array( 'articleTitle' => 'Climate change and policy', 'journalTitle' => 'Journal of Environmental Studies', 'volume' => '12', 'issue' => '3', 'year' => '2020', 'pages' => '45-60' );
$ja_keys    = Citex_APA_Journal_Article_Dragdrop_Parts::select_parts( 'AJ01', $ja_authors );
$ja_built   = Citex_APA_Journal_Article_Dragdrop_Parts::build( $ja_keys, $ja_authors, $ja_fields );
$ja_ref     = Citex_APA_Reference_Rules::build_reference( Citex_APA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, array_merge( $ja_fields, array( 'authors' => $ja_authors ) ) );
$ja_scenario = 'You are referencing an article titled ' . $ja_fields['articleTitle'] . ' by John Smith, published in ' . $ja_fields['year'] . ' in the journal ' . $ja_fields['journalTitle'] . ', volume ' . $ja_fields['volume'] . ', issue ' . $ja_fields['issue'] . ', pages 45 to 60.';
$ja_question = array(
	'source' => 'APA', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'type' => 'DragDrop',
	'scenario' => $ja_scenario,
	'dragdropPartKeys' => $ja_keys, 'fixedText' => $ja_built['fixedText'], 'questionParts' => $ja_built['parts'], 'confusingWords' => $ja_built['confusingWords'],
	'reconstructedReference' => $ja_ref, 'authors' => $ja_authors, 'year' => $ja_fields['year'], 'articleTitle' => $ja_fields['articleTitle'], 'journalTitle' => $ja_fields['journalTitle'], 'volume' => $ja_fields['volume'], 'issue' => $ja_fields['issue'], 'pages' => $ja_fields['pages'],
);
check( '[JA DD] a correctly-built selection passes', Citex_Generated_Validator::validate( $ja_question )['status'], 'passed' );
$ja_tampered_question = array_merge( $ja_question, array( 'questionParts' => array_merge( array( 'Wrong' ), array_slice( $ja_built['parts'], 1 ) ) ) );
$ja_tampered = Citex_Generated_Validator::validate( $ja_tampered_question );
check( '[JA DD] tampered Question Parts fail', $ja_tampered['status'], 'failed' );
check( '[JA DD] reports APA_JOURNAL_ARTICLE_DRAGDROP_PARTS_MISMATCH', has_error_code( $ja_tampered, 'apa_journal_article_dragdrop_parts_mismatch' ), true );

// CRITICAL — a Harvard-shaped reference ("pp." prefix) for an APA-sourced
// Journal Article record fails the APA-specific format check. Uses a
// selection that draws only "year" (never "pages"), so the real page range
// "45–60" sits as LITERAL text in fixedText — the one place a str_replace()
// on that literal text can actually take effect (drawing "pages" instead
// would blank it out into a "||" placeholder, leaving nothing literal to
// replace).
$ja_harvard_keys  = array( 'year' );
$ja_harvard_built = Citex_APA_Journal_Article_Dragdrop_Parts::build( $ja_harvard_keys, $ja_authors, $ja_fields );
check( '[JA DD setup] the literal page range is present in fixedText before tampering', false !== strpos( $ja_harvard_built['fixedText'], '45–60' ), true );
$ja_harvard_question = array(
	'source' => 'APA', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'type' => 'DragDrop',
	'scenario' => $ja_scenario,
	'dragdropPartKeys' => $ja_harvard_keys,
	'fixedText' => str_replace( ', 45–60.', ', pp. 45–60.', $ja_harvard_built['fixedText'] ),
	'questionParts' => $ja_harvard_built['parts'],
	'confusingWords' => $ja_harvard_built['confusingWords'],
	'reconstructedReference' => str_replace( ', 45–60.', ', pp. 45–60.', $ja_ref ),
	'authors' => $ja_authors, 'year' => $ja_fields['year'], 'articleTitle' => $ja_fields['articleTitle'], 'journalTitle' => $ja_fields['journalTitle'], 'volume' => $ja_fields['volume'], 'issue' => $ja_fields['issue'], 'pages' => $ja_fields['pages'],
);
$ja_harvard_result = Citex_Generated_Validator::validate( $ja_harvard_question );
check( '[JA DD] a "pp."-prefixed (Harvard-style) reference for an APA record fails', $ja_harvard_result['status'], 'failed' );
check( '[JA DD] reports APA_JOURNAL_ARTICLE_FORMAT_MISMATCH', has_error_code( $ja_harvard_result, 'apa_journal_article_format_mismatch' ), true );

// ---------------------------------------------------------------------
// Journal Article MCQ.
// ---------------------------------------------------------------------
$ja_mcq_fields = array( 'authors' => $ja_authors, 'articleTitle' => $ja_fields['articleTitle'], 'journalTitle' => $ja_fields['journalTitle'], 'volume' => $ja_fields['volume'], 'issue' => $ja_fields['issue'], 'year' => $ja_fields['year'], 'pages' => $ja_fields['pages'] );
$ja_variant    = Citex_APA_Journal_Article_Mcq_Variants::variant_for( 'AJ01', 1 );
$ja_mcq_built  = Citex_APA_Journal_Article_Mcq_Variants::build( $ja_variant, $ja_mcq_fields );
$ja_mcq_question = array(
	'source' => 'APA', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'type' => 'MCQ', 'mcqPattern' => 'apa_journal_article_mcq_variant',
	'apaJournalArticleMcqVariant' => $ja_variant, 'scenario' => $ja_mcq_built['stem'], 'authors' => $ja_authors, 'year' => $ja_fields['year'], 'articleTitle' => $ja_fields['articleTitle'], 'journalTitle' => $ja_fields['journalTitle'], 'volume' => $ja_fields['volume'], 'issue' => $ja_fields['issue'], 'pages' => $ja_fields['pages'],
	'options' => array_merge( $ja_mcq_built['wrongOptions'], array( '' ) ), 'hint' => 'a safe hint', 'reconstructedReference' => $ja_mcq_built['correctAnswer'],
);
check( '[JA MCQ] a correctly-built question passes', Citex_Generated_Validator::validate( $ja_mcq_question )['status'], 'passed' );
$ja_mcq_tampered = Citex_Generated_Validator::validate( array_merge( $ja_mcq_question, array( 'reconstructedReference' => 'Something else entirely.' ) ) );
check( '[JA MCQ] a tampered answer fails', $ja_mcq_tampered['status'], 'failed' );
check( '[JA MCQ] reports APA_JOURNAL_ARTICLE_MCQ_VARIANT_ANSWER_MISMATCH', has_error_code( $ja_mcq_tampered, 'apa_journal_article_mcq_variant_answer_mismatch' ), true );

// ---------------------------------------------------------------------
// Website DragDrop + MCQ (individual and organisation).
// ---------------------------------------------------------------------
$web_author = array( 'type' => 'individual', 'surname' => 'Smith', 'initials' => 'J.', 'fullName' => 'John Smith' );
$web_fields = array( 'year' => '2020', 'title' => 'Understanding climate policy', 'url' => 'https://example.com' );
$web_keys   = Citex_APA_Website_Dragdrop_Parts::select_parts( 'AW01' );
$web_built  = Citex_APA_Website_Dragdrop_Parts::build( $web_keys, $web_author, $web_fields );
$web_ref    = Citex_APA_Reference_Rules::build_reference( Citex_APA_Reference_Rules::CATEGORY_WEBSITE, array_merge( $web_fields, array( 'author' => $web_author ) ) );
$web_scenario = 'You are referencing a webpage titled ' . $web_fields['title'] . ' by John Smith, at ' . $web_fields['url'] . '.';
$web_question = array(
	'source' => 'APA', 'group' => 'ReferenceList', 'category' => 'Website', 'type' => 'DragDrop',
	'scenario' => $web_scenario,
	'dragdropPartKeys' => $web_keys, 'fixedText' => $web_built['fixedText'], 'questionParts' => $web_built['parts'], 'confusingWords' => $web_built['confusingWords'],
	'reconstructedReference' => $web_ref, 'authorType' => 'individual', 'authors' => array( $web_author ), 'year' => $web_fields['year'], 'pageTitle' => $web_fields['title'], 'url' => $web_fields['url'],
);
check( '[Web DD] a correctly-built selection passes', Citex_Generated_Validator::validate( $web_question )['status'], 'passed' );
$web_tampered = Citex_Generated_Validator::validate( array_merge( $web_question, array( 'confusingWords' => array_merge( array( 'Wrong' ), array_slice( $web_built['confusingWords'], 1 ) ) ) ) );
check( '[Web DD] tampered confusing words fail', $web_tampered['status'], 'failed' );
check( '[Web DD] reports APA_WEBSITE_DRAGDROP_CONFUSING_WORDS_MISMATCH', has_error_code( $web_tampered, 'apa_website_dragdrop_confusing_words_mismatch' ), true );

// CRITICAL — a Harvard-shaped ("Available at:") reference for an APA
// Website record fails the APA-specific format check, never silently
// passing as if it were correct.
$web_harvard_shaped = array_merge( $web_question, array( 'reconstructedReference' => 'Smith, J. (2020) Understanding climate policy. Available at: https://example.com (Accessed: 1 January 2024).' ) );
$web_harvard_result = Citex_Generated_Validator::validate( $web_harvard_shaped );
check( '[Web DD] a Harvard-shaped ("Available at:") reference for an APA record fails', $web_harvard_result['status'], 'failed' );

$web_mcq_fields = array( 'author' => $web_author, 'year' => $web_fields['year'], 'title' => $web_fields['title'], 'url' => $web_fields['url'] );
$web_variant    = Citex_APA_Website_Mcq_Variants::variant_for( 'AW01' );
$web_mcq_built  = Citex_APA_Website_Mcq_Variants::build( $web_variant, $web_mcq_fields );
$web_mcq_question = array(
	'source' => 'APA', 'group' => 'ReferenceList', 'category' => 'Website', 'type' => 'MCQ', 'mcqPattern' => 'apa_website_mcq_variant',
	'apaWebsiteMcqVariant' => $web_variant, 'scenario' => $web_mcq_built['stem'], 'authorType' => 'individual', 'authors' => array( $web_author ), 'year' => $web_fields['year'], 'pageTitle' => $web_fields['title'], 'url' => $web_fields['url'],
	'options' => array_merge( $web_mcq_built['wrongOptions'], array( '' ) ), 'hint' => 'a safe hint', 'reconstructedReference' => $web_mcq_built['correctAnswer'],
);
check( '[Web MCQ] a correctly-built question passes', Citex_Generated_Validator::validate( $web_mcq_question )['status'], 'passed' );
$web_mcq_tampered = Citex_Generated_Validator::validate( array_merge( $web_mcq_question, array( 'apaWebsiteMcqVariant' => 'not_a_real_variant' ) ) );
check( '[Web MCQ] an unrecognised variant id fails', $web_mcq_tampered['status'], 'failed' );
check( '[Web MCQ] reports APA_WEBSITE_MCQ_VARIANT_UNKNOWN', has_error_code( $web_mcq_tampered, 'apa_website_mcq_variant_unknown' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
