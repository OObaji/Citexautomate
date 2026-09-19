<?php
/**
 * Regression tests for Citex_AI_V2's APA in-text citation wiring — mirrors
 * ai-v2-mla-intext-wiring.test.php's own structure, but for APA's own
 * normalise_apa_intext_dragdrop_item()/normalise_apa_intext_mcq_item() leaf
 * normalisers. Critical APA-specific assertions: every candidate DOES carry
 * a year (author-date, like Harvard, unlike MLA), and a 2-person "who" is
 * joined with "&" in parenthetical/quote forms but "and" in narrative form
 * (Citex_APA_Intext_Citation_Rules::joiner_for_form()).
 *
 * Exercises all 4 categories (Book, Edited Book, Journal Article, Website)
 * x all 3 citation forms x both types (DragDrop, MCQ) — 24 combinations —
 * end to end via reflection into the private normalise() method.
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-apa-intext-wiring.test.php` — not shipped in
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
	$v = (string) $v;
	if ( false !== strpos( $v, '<' ) ) { $v = preg_replace( '/<[^>]*>?/', '', $v ); }
	$v = preg_replace( '/[\r\n\t ]+/', ' ', $v );
	return trim( $v );
}
function sanitize_textarea_field( $v ) {
	$v = (string) $v;
	if ( false !== strpos( $v, '<' ) ) { $v = preg_replace( '/<[^>]*>?/', '', $v ); }
	return trim( $v );
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
function get_option( $k, $d = false ) {
	return $d;
}

$root = __DIR__ . '/../citex-tools/includes/';
require $root . 'class-citex-reference-rules.php';
require $root . 'class-citex-book-mcq-variants.php';
require $root . 'class-citex-website-mcq-variants.php';
require $root . 'class-citex-mla-reference-rules.php';
require $root . 'class-citex-mla-book-dragdrop-parts.php';
require $root . 'class-citex-mla-book-mcq-variants.php';
require $root . 'class-citex-book-dragdrop-parts.php';
require $root . 'class-citex-edited-book-dragdrop-parts.php';
require $root . 'class-citex-journal-article-dragdrop-parts.php';
require $root . 'class-citex-website-dragdrop-parts.php';
require $root . 'class-citex-intext-citation-rules.php';
require $root . 'class-citex-intext-dragdrop-parts.php';
require $root . 'class-citex-intext-mcq-variants.php';
require $root . 'class-citex-mla-intext-citation-rules.php';
require $root . 'class-citex-mla-intext-dragdrop-parts.php';
require $root . 'class-citex-mla-intext-mcq-variants.php';
require $root . 'class-citex-apa-reference-rules.php';
require $root . 'class-citex-apa-book-dragdrop-parts.php';
require $root . 'class-citex-apa-book-mcq-variants.php';
require $root . 'class-citex-apa-intext-citation-rules.php';
require $root . 'class-citex-apa-intext-dragdrop-parts.php';
require $root . 'class-citex-apa-intext-mcq-variants.php';
require $root . 'class-citex-question-scenarios.php';
require $root . 'class-citex-question-diversity.php';
require $root . 'class-citex-generated-validator.php';
require $root . 'class-citex-ai-v2.php';

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

function invoke_normalise( array $questions, array $ids, $type, $category, $style, $group, $citation_form ) {
	$m = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$m->setAccessible( true );
	return $m->invokeArgs( null, array( $questions, $ids, 'medium', array( 'Exercise 1' ), $type, $category, null, '', '', 'full_reference', $style, $group, $citation_form ) );
}

$BOOK            = Citex_Reference_Rules::CATEGORY_BOOK;
$EDITED_BOOK     = Citex_Reference_Rules::CATEGORY_EDITED_BOOK;
$JOURNAL_ARTICLE = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;
$WEBSITE         = Citex_Reference_Rules::CATEGORY_WEBSITE;

function item_for( $category, $form, $n, $author_count = null ) {
	global $WEBSITE, $EDITED_BOOK, $JOURNAL_ARTICLE;
	$item = array();
	if ( $category === $WEBSITE ) {
		$item['authorType'] = ( 0 === $n % 2 ) ? 'organisation' : 'individual';
		if ( 'individual' === $item['authorType'] ) {
			$item['authorFullName'] = "Amy Ross$n";
		} else {
			$item['organisationName'] = "WHO$n";
		}
		$item['pageTitle'] = "Page $n";
		$item['year']      = ( 0 === $n % 3 ) ? 'n.d.' : (string) ( 2010 + $n );
	} else {
		$field = $category === $EDITED_BOOK ? 'editorFullNames' : 'authorFullNames';
		$names = array();
		$pool  = array( "Amy Ross$n", "Ben Carter$n", "Kim Lee$n", "Jo Kaur$n" );
		$count = $author_count ?? ( 1 + ( $n % 4 ) );
		for ( $i = 0; $i < $count; $i++ ) { $names[] = $pool[ $i ]; }
		$item[ $field ] = $names;
		$title_field    = $category === $JOURNAL_ARTICLE ? 'articleTitle' : 'bookTitle';
		$item[ $title_field ] = "Title $n";
		$item['year']   = (string) ( 2010 + $n );
	}
	if ( 'parenthetical_quote' === $form ) {
		$item['quote'] = "a short plausible quote $n";
		$item['page']  = (string) ( 10 + $n );
	} else {
		$item['clause'] = "argues that point $n matters";
	}
	return $item;
}

// ---------------------------------------------------------------------
// 1. All 24 combinations (4 categories x 3 forms x 2 types) succeed and
// carry correctly-shaped fields, including a real year (unlike MLA).
// ---------------------------------------------------------------------
$categories = array( $BOOK, $EDITED_BOOK, $JOURNAL_ARTICLE, $WEBSITE );
$forms      = array( 'narrative', 'parenthetical', 'parenthetical_quote' );
$types      = array( 'DragDrop', 'MCQ' );

$n = 100;
foreach ( $categories as $category ) {
	foreach ( $forms as $form ) {
		foreach ( $types as $type ) {
			$n++;
			$item   = item_for( $category, $form, $n );
			$id     = 'AI' . $n;
			$label  = "$category/$form/$type";
			$result = invoke_normalise( array( $item ), array( $id ), $type, $category, 'apa', 'intext', $form );

			if ( is_wp_error( $result ) ) {
				check( "[1] $label normalises without error", false, true );
				echo '    error: ' . $result->get_error_message() . "\n";
				continue;
			}
			$c = $result[0];
			check( "[1] $label: group is InTextCitation", $c['group'] ?? null, 'InTextCitation' );
			check( "[1] $label: source is APA", $c['source'] ?? null, 'APA' );
			check( "[1] $label: citationForm matches requested form", $c['citationForm'] ?? null, $form );
			check( "[1] $label: category matches requested category", $c['category'] ?? null, $category );
			check( "[1] $label: reconstructedReference is non-empty", '' !== ( $c['reconstructedReference'] ?? '' ), true );
			if ( $category !== $WEBSITE ) {
				check( "[1] $label: a real year is carried (APA is author-date, unlike MLA)", $c['year'] ?? '', (string) ( 2010 + $n ) );
			}
			if ( 'DragDrop' === $type ) {
				check( "[1] $label: fixedText is non-empty", '' !== ( $c['fixedText'] ?? '' ), true );
				check( "[1] $label: questionParts is non-empty", ! empty( $c['questionParts'] ?? array() ), true );
				check( "[1] $label: confusingWords count matches questionParts count", count( $c['confusingWords'] ?? array() ), count( $c['questionParts'] ?? array() ) );
				check( "[1] $label: 'year' is among dragdropPartKeys (APA in-text always shows a year)", in_array( 'year', $c['dragdropPartKeys'] ?? array(), true ), true );
			} else {
				check( "[1] $label: exactly 4 options (3 wrong + 1 blank slot for the correct answer)", count( $c['options'] ?? array() ), 4 );
				check( "[1] $label: mcqPattern is apa_intext_mcq_variant", $c['mcqPattern'] ?? null, 'apa_intext_mcq_variant' );
			}
		}
	}
}

// ---------------------------------------------------------------------
// 2. CRITICAL — the form-dependent joiner: a 2-author record joins with
// "&" in a parenthetical citation but "and" in a narrative one.
// ---------------------------------------------------------------------
$two_author_narrative = item_for( $BOOK, 'narrative', 201, 2 );
$narrative_result = invoke_normalise( array( $two_author_narrative ), array( 'AI201' ), 'DragDrop', $BOOK, 'apa', 'intext', 'narrative' );
check( '[2] narrative 2-author reference uses "and", never "&"', is_wp_error( $narrative_result ), false );
if ( ! is_wp_error( $narrative_result ) ) {
	$ref = $narrative_result[0]['reconstructedReference'];
	check( '[2] narrative reference contains " and "', false !== strpos( $ref, ' and ' ), true );
	check( '[2] narrative reference contains no "&"', false !== strpos( $ref, '&' ), false );
}

$two_author_parenthetical = item_for( $BOOK, 'parenthetical', 202, 2 );
$parenthetical_result = invoke_normalise( array( $two_author_parenthetical ), array( 'AI202' ), 'DragDrop', $BOOK, 'apa', 'intext', 'parenthetical' );
check( '[2] parenthetical 2-author reference uses "&", never "and"', is_wp_error( $parenthetical_result ), false );
if ( ! is_wp_error( $parenthetical_result ) ) {
	$ref = $parenthetical_result[0]['reconstructedReference'];
	check( '[2] parenthetical reference contains "&"', false !== strpos( $ref, '&' ), true );
	check( '[2] parenthetical reference contains no " and "', false !== strpos( $ref, ' and ' ), false );
}

// ---------------------------------------------------------------------
// 3. Missing quote/page for the parenthetical_quote form is rejected.
// ---------------------------------------------------------------------
$bad_item = item_for( $BOOK, 'narrative', 1 );
unset( $bad_item['clause'] );
$bad_item['quote'] = 'missing its page';
$bad_result = invoke_normalise( array( $bad_item ), array( 'AI900' ), 'DragDrop', $BOOK, 'apa', 'intext', 'parenthetical_quote' );
check( '[3] a quote-form item missing "page" is rejected as a WP_Error', is_wp_error( $bad_result ), true );

// ---------------------------------------------------------------------
// 4. A missing year for a non-Website category is rejected (APA always
// requires one, unlike MLA's optional year).
// ---------------------------------------------------------------------
$bad_year_item = item_for( $BOOK, 'narrative', 2 );
unset( $bad_year_item['year'] );
$bad_year_result = invoke_normalise( array( $bad_year_item ), array( 'AI901' ), 'DragDrop', $BOOK, 'apa', 'intext', 'narrative' );
check( '[4] a Book item missing "year" is rejected as a WP_Error (APA always requires one)', is_wp_error( $bad_year_result ), true );

// ---------------------------------------------------------------------
// 5. A referencelist call (the default $group) is entirely unaffected.
// ---------------------------------------------------------------------
$book_item = array(
	'authorFullNames' => array( 'Amy Ross' ),
	'bookTitle'       => 'A Short Title',
	'year'            => '2019',
	'publisher'       => 'Routledge',
	'scenario'        => 'A student needs to reference this book.',
);
$ref_result = invoke_normalise( array( $book_item ), array( 'AB01' ), 'DragDrop', $BOOK, 'apa', 'referencelist', '' );
check( '[5] a referencelist call still succeeds unaffected', is_wp_error( $ref_result ), false );
if ( ! is_wp_error( $ref_result ) ) {
	check( '[5] referencelist candidate\'s group is ReferenceList, not InTextCitation', $ref_result[0]['group'] ?? null, 'ReferenceList' );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
