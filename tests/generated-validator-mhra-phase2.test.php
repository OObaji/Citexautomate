<?php
/**
 * Regression tests for Citex_Generated_Validator's MHRA Phase 2 DragDrop/MCQ
 * blocks (Edited Book, Journal Article, Website) — mirrors
 * tests/generated-validator-chicago-phase2.test.php's own "tamper one
 * field, confirm the exact-match check catches it" pattern, consolidated
 * across all 3 new categories in one file, WITH the `place` field MHRA
 * keeps throughout, and — the single biggest structural difference from
 * every other style's own Website block in this codebase — NO `year` field
 * at all for Website (only `accessedDate`).
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-mhra-phase2.test.php` — not shipped in
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
require __DIR__ . '/../citex-tools/includes/class-citex-chicago-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-chicago-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-chicago-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-edited-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-edited-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-journal-article-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-journal-article-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-website-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-website-mcq-variants.php';
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
$eb_editors = array( array( 'surname' => 'Ross', 'givenName' => 'Amy', 'fullName' => 'Amy Ross' ) );
$eb_fields  = array( 'year' => '2019', 'title' => 'Urban Planning Today', 'place' => 'London', 'publisher' => 'Routledge' );
$eb_keys    = Citex_MHRA_Edited_Book_Dragdrop_Parts::select_parts( 'HE01', $eb_editors );
$eb_built   = Citex_MHRA_Edited_Book_Dragdrop_Parts::build( $eb_keys, $eb_editors, $eb_fields );
$eb_ref     = Citex_MHRA_Reference_Rules::build_reference( Citex_MHRA_Reference_Rules::CATEGORY_EDITED_BOOK, array_merge( $eb_fields, array( 'editors' => $eb_editors ) ) );
function eb_question( $keys, $built, $editors, $fields, $ref, $overrides = array() ) {
	return array_merge(
		array(
			'source' => 'MHRA', 'group' => 'ReferenceList', 'category' => 'Edited Book', 'type' => 'DragDrop',
			'scenario' => 'You are referencing an edited book titled ' . $fields['title'] . ' by Amy Ross, published in ' . $fields['year'] . ' by ' . $fields['publisher'] . ' in ' . $fields['place'] . '.',
			'dragdropPartKeys' => $keys, 'fixedText' => $built['fixedText'], 'questionParts' => $built['parts'], 'confusingWords' => $built['confusingWords'],
			'reconstructedReference' => $ref, 'editors' => $editors, 'year' => $fields['year'], 'bookTitle' => $fields['title'], 'place' => $fields['place'], 'publisher' => $fields['publisher'],
		),
		$overrides
	);
}
check( '[EB DD] a correctly-built selection passes', Citex_Generated_Validator::validate( eb_question( $eb_keys, $eb_built, $eb_editors, $eb_fields, $eb_ref ) )['status'], 'passed' );
$eb_tampered = Citex_Generated_Validator::validate( eb_question( $eb_keys, $eb_built, $eb_editors, $eb_fields, $eb_ref, array( 'fixedText' => 'X' . $eb_built['fixedText'] ) ) );
check( '[EB DD] a tampered Fixed Text fails', $eb_tampered['status'], 'failed' );
check( '[EB DD] reports MHRA_EDITED_BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', has_error_code( $eb_tampered, 'mhra_edited_book_dragdrop_fixed_text_mismatch' ), true );

// ---------------------------------------------------------------------
// Edited Book MCQ.
// ---------------------------------------------------------------------
$eb_mcq_fields = array( 'editors' => $eb_editors, 'year' => '2019', 'title' => 'Urban Planning Today', 'place' => 'London', 'publisher' => 'Routledge' );
$eb_variant    = Citex_MHRA_Edited_Book_Mcq_Variants::variant_for( 'HE01', 1 );
$eb_mcq_built  = Citex_MHRA_Edited_Book_Mcq_Variants::build( $eb_variant, $eb_mcq_fields );
function eb_mcq_question( $variant, $built, $editors, $fields, $overrides = array() ) {
	return array_merge(
		array(
			'source' => 'MHRA', 'group' => 'ReferenceList', 'category' => 'Edited Book', 'type' => 'MCQ', 'mcqPattern' => 'mhra_edited_book_mcq_variant',
			'mhraEditedBookMcqVariant' => $variant, 'scenario' => $built['stem'], 'editors' => $editors, 'year' => $fields['year'], 'bookTitle' => $fields['title'], 'place' => $fields['place'], 'publisher' => $fields['publisher'],
			'options' => array_merge( $built['wrongOptions'], array( '' ) ), 'hint' => 'a safe hint', 'reconstructedReference' => $built['correctAnswer'],
		),
		$overrides
	);
}
check( '[EB MCQ] a correctly-built question passes', Citex_Generated_Validator::validate( eb_mcq_question( $eb_variant, $eb_mcq_built, $eb_editors, $eb_mcq_fields ) )['status'], 'passed' );
$eb_mcq_tampered = Citex_Generated_Validator::validate( eb_mcq_question( $eb_variant, $eb_mcq_built, $eb_editors, $eb_mcq_fields, array( 'options' => array( 'Wrong 1', 'Wrong 2', 'Wrong 3', '' ) ) ) );
check( '[EB MCQ] tampered options fail', $eb_mcq_tampered['status'], 'failed' );
check( '[EB MCQ] reports MHRA_EDITED_BOOK_MCQ_VARIANT_OPTION_MISMATCH', has_error_code( $eb_mcq_tampered, 'mhra_edited_book_mcq_variant_option_mismatch' ), true );

// ---------------------------------------------------------------------
// Journal Article DragDrop.
// ---------------------------------------------------------------------
$ja_authors  = array( array( 'surname' => 'Smith', 'givenName' => 'John', 'fullName' => 'John Smith' ) );
$ja_fields   = array( 'articleTitle' => 'Climate Change and Policy', 'journalTitle' => 'Journal of Environmental Studies', 'volume' => '12', 'issue' => '3', 'year' => '2020', 'pages' => '45-60' );
$ja_keys     = Citex_MHRA_Journal_Article_Dragdrop_Parts::select_parts( 'HJ01', $ja_authors );
$ja_built    = Citex_MHRA_Journal_Article_Dragdrop_Parts::build( $ja_keys, $ja_authors, $ja_fields );
$ja_ref      = Citex_MHRA_Reference_Rules::build_reference( Citex_MHRA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, array_merge( $ja_fields, array( 'authors' => $ja_authors ) ) );
$ja_scenario = 'You are referencing an article titled ' . $ja_fields['articleTitle'] . ' by John Smith, published in ' . $ja_fields['year'] . ' in the journal ' . $ja_fields['journalTitle'] . ', volume ' . $ja_fields['volume'] . ', issue ' . $ja_fields['issue'] . ', pages 45 to 60.';
$ja_question = array(
	'source' => 'MHRA', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'type' => 'DragDrop',
	'scenario' => $ja_scenario,
	'dragdropPartKeys' => $ja_keys, 'fixedText' => $ja_built['fixedText'], 'questionParts' => $ja_built['parts'], 'confusingWords' => $ja_built['confusingWords'],
	'reconstructedReference' => $ja_ref, 'authors' => $ja_authors, 'year' => $ja_fields['year'], 'articleTitle' => $ja_fields['articleTitle'], 'journalTitle' => $ja_fields['journalTitle'], 'volume' => $ja_fields['volume'], 'issue' => $ja_fields['issue'], 'pages' => $ja_fields['pages'],
);
check( '[JA DD] a correctly-built selection passes', Citex_Generated_Validator::validate( $ja_question )['status'], 'passed' );
$ja_tampered_question = array_merge( $ja_question, array( 'questionParts' => array_merge( array( 'Wrong' ), array_slice( $ja_built['parts'], 1 ) ) ) );
$ja_tampered = Citex_Generated_Validator::validate( $ja_tampered_question );
check( '[JA DD] tampered Question Parts fail', $ja_tampered['status'], 'failed' );
check( '[JA DD] reports MHRA_JOURNAL_ARTICLE_DRAGDROP_PARTS_MISMATCH', has_error_code( $ja_tampered, 'mhra_journal_article_dragdrop_parts_mismatch' ), true );

// CRITICAL — a Chicago-shaped (double-quoted article title) reference for
// an MHRA-sourced Journal Article record fails the MHRA-specific format
// check. Uses a selection that draws only "year" (never "article_title"),
// so the real single-quoted title sits as LITERAL text in fixedText — the
// one place a str_replace() on that literal text can actually take effect.
$ja_chicago_shaped_keys  = array( 'year' );
$ja_chicago_shaped_built = Citex_MHRA_Journal_Article_Dragdrop_Parts::build( $ja_chicago_shaped_keys, $ja_authors, $ja_fields );
check( '[JA DD setup] the literal single-quoted title is present in fixedText before tampering', false !== strpos( $ja_chicago_shaped_built['fixedText'], "'Climate Change and Policy'" ), true );
$ja_chicago_shaped_question = array(
	'source' => 'MHRA', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'type' => 'DragDrop',
	'scenario' => $ja_scenario,
	'dragdropPartKeys' => $ja_chicago_shaped_keys,
	'fixedText' => str_replace( "'Climate Change and Policy'", '"Climate Change and Policy"', $ja_chicago_shaped_built['fixedText'] ),
	'questionParts' => $ja_chicago_shaped_built['parts'],
	'confusingWords' => $ja_chicago_shaped_built['confusingWords'],
	'reconstructedReference' => str_replace( "'Climate Change and Policy'", '"Climate Change and Policy"', $ja_ref ),
	'authors' => $ja_authors, 'year' => $ja_fields['year'], 'articleTitle' => $ja_fields['articleTitle'], 'journalTitle' => $ja_fields['journalTitle'], 'volume' => $ja_fields['volume'], 'issue' => $ja_fields['issue'], 'pages' => $ja_fields['pages'],
);
$ja_chicago_shaped_result = Citex_Generated_Validator::validate( $ja_chicago_shaped_question );
check( '[JA DD] a double-quoted (Chicago-style) article title for an MHRA record fails', $ja_chicago_shaped_result['status'], 'failed' );
check( '[JA DD] reports MHRA_JOURNAL_ARTICLE_FORMAT_MISMATCH', has_error_code( $ja_chicago_shaped_result, 'mhra_journal_article_format_mismatch' ), true );

// ---------------------------------------------------------------------
// Journal Article MCQ.
// ---------------------------------------------------------------------
$ja_mcq_fields   = array( 'authors' => $ja_authors, 'articleTitle' => $ja_fields['articleTitle'], 'journalTitle' => $ja_fields['journalTitle'], 'volume' => $ja_fields['volume'], 'issue' => $ja_fields['issue'], 'year' => $ja_fields['year'], 'pages' => $ja_fields['pages'] );
$ja_variant      = Citex_MHRA_Journal_Article_Mcq_Variants::variant_for( 'HJ01', 1 );
$ja_mcq_built    = Citex_MHRA_Journal_Article_Mcq_Variants::build( $ja_variant, $ja_mcq_fields );
$ja_mcq_question = array(
	'source' => 'MHRA', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'type' => 'MCQ', 'mcqPattern' => 'mhra_journal_article_mcq_variant',
	'mhraJournalArticleMcqVariant' => $ja_variant, 'scenario' => $ja_mcq_built['stem'], 'authors' => $ja_authors, 'year' => $ja_fields['year'], 'articleTitle' => $ja_fields['articleTitle'], 'journalTitle' => $ja_fields['journalTitle'], 'volume' => $ja_fields['volume'], 'issue' => $ja_fields['issue'], 'pages' => $ja_fields['pages'],
	'options' => array_merge( $ja_mcq_built['wrongOptions'], array( '' ) ), 'hint' => 'a safe hint', 'reconstructedReference' => $ja_mcq_built['correctAnswer'],
);
check( '[JA MCQ] a correctly-built question passes', Citex_Generated_Validator::validate( $ja_mcq_question )['status'], 'passed' );
$ja_mcq_tampered = Citex_Generated_Validator::validate( array_merge( $ja_mcq_question, array( 'reconstructedReference' => 'Something else entirely.' ) ) );
check( '[JA MCQ] a tampered answer fails', $ja_mcq_tampered['status'], 'failed' );
check( '[JA MCQ] reports MHRA_JOURNAL_ARTICLE_MCQ_VARIANT_ANSWER_MISMATCH', has_error_code( $ja_mcq_tampered, 'mhra_journal_article_mcq_variant_answer_mismatch' ), true );

// ---------------------------------------------------------------------
// Website DragDrop + MCQ (individual and organisation). No `year` field at
// all — only `accessedDate` — see Citex_MHRA_Reference_Rules's own
// docblock.
// ---------------------------------------------------------------------
$web_author   = array( 'type' => 'individual', 'surname' => 'Smith', 'givenName' => 'John', 'fullName' => 'John Smith' );
$web_fields   = array( 'title' => 'Understanding Climate Policy', 'url' => 'https://example.com', 'accessedDate' => '5 May 2023' );
$web_keys     = Citex_MHRA_Website_Dragdrop_Parts::select_parts( 'HW01' );
$web_built    = Citex_MHRA_Website_Dragdrop_Parts::build( $web_keys, $web_author, $web_fields );
$web_ref      = Citex_MHRA_Reference_Rules::build_reference( Citex_MHRA_Reference_Rules::CATEGORY_WEBSITE, array_merge( $web_fields, array( 'author' => $web_author ) ) );
$web_scenario = 'You are referencing a webpage titled ' . $web_fields['title'] . ' by John Smith, at ' . $web_fields['url'] . '.';
$web_question = array(
	'source' => 'MHRA', 'group' => 'ReferenceList', 'category' => 'Website', 'type' => 'DragDrop',
	'scenario' => $web_scenario,
	'dragdropPartKeys' => $web_keys, 'fixedText' => $web_built['fixedText'], 'questionParts' => $web_built['parts'], 'confusingWords' => $web_built['confusingWords'],
	'reconstructedReference' => $web_ref, 'authorType' => 'individual', 'authors' => array( $web_author ), 'pageTitle' => $web_fields['title'], 'url' => $web_fields['url'], 'accessedDate' => $web_fields['accessedDate'],
);
check( '[Web DD] a correctly-built selection passes', Citex_Generated_Validator::validate( $web_question )['status'], 'passed' );
$web_tampered = Citex_Generated_Validator::validate( array_merge( $web_question, array( 'confusingWords' => array_merge( array( 'Wrong' ), array_slice( $web_built['confusingWords'], 1 ) ) ) ) );
check( '[Web DD] tampered confusing words fail', $web_tampered['status'], 'failed' );
check( '[Web DD] reports MHRA_WEBSITE_DRAGDROP_CONFUSING_WORDS_MISMATCH', has_error_code( $web_tampered, 'mhra_website_dragdrop_confusing_words_mismatch' ), true );

// CRITICAL — a reference with the URL missing its angle brackets for an
// MHRA Website record fails the MHRA-specific format check, never silently
// passing as if it were correct.
$web_no_brackets = array_merge( $web_question, array( 'reconstructedReference' => "Smith, John, 'Understanding Climate Policy', https://example.com [accessed 5 May 2023]." ) );
$web_no_brackets_result = Citex_Generated_Validator::validate( $web_no_brackets );
check( '[Web DD] a reference with the URL missing its angle brackets fails', $web_no_brackets_result['status'], 'failed' );

$web_mcq_fields   = array( 'author' => $web_author, 'title' => $web_fields['title'], 'url' => $web_fields['url'], 'accessedDate' => $web_fields['accessedDate'] );
$web_variant      = Citex_MHRA_Website_Mcq_Variants::variant_for( 'HW01' );
$web_mcq_built    = Citex_MHRA_Website_Mcq_Variants::build( $web_variant, $web_mcq_fields );
$web_mcq_question = array(
	'source' => 'MHRA', 'group' => 'ReferenceList', 'category' => 'Website', 'type' => 'MCQ', 'mcqPattern' => 'mhra_website_mcq_variant',
	'mhraWebsiteMcqVariant' => $web_variant, 'scenario' => $web_mcq_built['stem'], 'authorType' => 'individual', 'authors' => array( $web_author ), 'pageTitle' => $web_fields['title'], 'url' => $web_fields['url'], 'accessedDate' => $web_fields['accessedDate'],
	'options' => array_merge( $web_mcq_built['wrongOptions'], array( '' ) ), 'hint' => 'a safe hint', 'reconstructedReference' => $web_mcq_built['correctAnswer'],
);
check( '[Web MCQ] a correctly-built question passes', Citex_Generated_Validator::validate( $web_mcq_question )['status'], 'passed' );
$web_mcq_tampered = Citex_Generated_Validator::validate( array_merge( $web_mcq_question, array( 'mhraWebsiteMcqVariant' => 'not_a_real_variant' ) ) );
check( '[Web MCQ] an unrecognised variant id fails', $web_mcq_tampered['status'], 'failed' );
check( '[Web MCQ] reports MHRA_WEBSITE_MCQ_VARIANT_UNKNOWN', has_error_code( $web_mcq_tampered, 'mhra_website_mcq_variant_unknown' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
