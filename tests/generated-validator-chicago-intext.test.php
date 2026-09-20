<?php
/**
 * Regression tests for Citex_Generated_Validator's Chicago (Author-Date)
 * in-text citation wiring — validate_intext_dragdrop()/
 * validate_chicago_intext_mcq_variant(), dispatched off the
 * `group`/`mcqPattern`/`source` fields Citex_AI_V2::normalise_intext_item()
 * now produces for style 'chicago'. Mirrors
 * tests/generated-validator-apa-intext.test.php's own structure.
 *
 * Positive coverage: every one of the 24 combinations (4 categories x 3
 * forms x 2 types) Citex_AI_V2::normalise() produces for Chicago in-text
 * citation validates as 'passed'. Negative coverage: deliberately
 * corrupting one field at a time makes validate() FAIL — including the
 * single most Chicago-distinctive mistake, a comma wrongly inserted
 * between the author and the year (borrowing Harvard/APA/MHRA's own rule),
 * and a "p." prefix wrongly added before a page number.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-chicago-intext.test.php` — not shipped in
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
require $root . 'class-citex-apa-intext-citation-rules.php';
require $root . 'class-citex-apa-intext-dragdrop-parts.php';
require $root . 'class-citex-apa-intext-mcq-variants.php';
require $root . 'class-citex-chicago-reference-rules.php';
require $root . 'class-citex-chicago-book-dragdrop-parts.php';
require $root . 'class-citex-chicago-book-mcq-variants.php';
require $root . 'class-citex-chicago-intext-citation-rules.php';
require $root . 'class-citex-chicago-intext-dragdrop-parts.php';
require $root . 'class-citex-chicago-intext-mcq-variants.php';
require $root . 'class-citex-mhra-reference-rules.php';
require $root . 'class-citex-mhra-intext-citation-rules.php';
require $root . 'class-citex-mhra-intext-dragdrop-parts.php';
require $root . 'class-citex-mhra-intext-mcq-variants.php';
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
		$item[ $field ]       = $names;
		$title_field          = $category === $JOURNAL_ARTICLE ? 'articleTitle' : 'bookTitle';
		$item[ $title_field ] = "Title $n";
		$item['year']         = (string) ( 2010 + $n );
	}
	if ( 'parenthetical_quote' === $form ) {
		$item['quote'] = "a short plausible quote $n";
		$item['page']  = (string) ( 10 + $n );
	} else {
		$item['clause'] = "argues that point $n matters";
	}
	return $item;
}

function build_candidate( $category, $form, $type, $n, $author_count = null ) {
	$item   = item_for( $category, $form, $n, $author_count );
	$id     = 'CI' . $n;
	$result = invoke_normalise( array( $item ), array( $id ), $type, $category, 'chicago', 'intext', $form );
	return is_wp_error( $result ) ? $result : $result[0];
}

// ---------------------------------------------------------------------
// 1. All 24 combinations validate as 'passed' — the full generate ->
// validate pipeline, end to end.
// ---------------------------------------------------------------------
$categories = array( $BOOK, $EDITED_BOOK, $JOURNAL_ARTICLE, $WEBSITE );
$forms      = array( 'narrative', 'parenthetical', 'parenthetical_quote' );
$types      = array( 'DragDrop', 'MCQ' );

$n = 300;
$sample_dragdrop = null;
$sample_mcq      = null;
foreach ( $categories as $category ) {
	foreach ( $forms as $form ) {
		foreach ( $types as $type ) {
			$n++;
			$label = "$category/$form/$type";
			$c     = build_candidate( $category, $form, $type, $n );
			if ( is_wp_error( $c ) ) {
				check( "[1] $label built without error", false, true );
				echo '    error: ' . $c->get_error_message() . "\n";
				continue;
			}
			$v = Citex_Generated_Validator::validate( $c );
			check( "[1] $label validates as 'passed'", $v['status'] ?? '?', 'passed' );
			if ( 'DragDrop' === $type && null === $sample_dragdrop ) { $sample_dragdrop = $c; }
			if ( 'MCQ' === $type && null === $sample_mcq ) { $sample_mcq = $c; }
		}
	}
}

// ---------------------------------------------------------------------
// 2. Negative coverage — DragDrop: corrupting fixedText/questionParts/
// confusingWords/reconstructedReference each makes validate() FAIL.
// ---------------------------------------------------------------------
if ( null !== $sample_dragdrop ) {
	$bad = $sample_dragdrop;
	$bad['fixedText'] = $bad['fixedText'] . ' EXTRA';
	check( '[2] tampering with fixedText fails validation', ( Citex_Generated_Validator::validate( $bad )['status'] ?? '?' ), 'failed' );

	$bad = $sample_dragdrop;
	$bad['questionParts'][0] = $bad['questionParts'][0] . 'XYZ';
	check( '[2] tampering with a questionPart fails validation', ( Citex_Generated_Validator::validate( $bad )['status'] ?? '?' ), 'failed' );

	$bad = $sample_dragdrop;
	$bad['confusingWords'][0] = $bad['reconstructedReference']; // leaking the answer as a distractor
	check( '[2] a confusingWord leaking the correct value fails validation', ( Citex_Generated_Validator::validate( $bad )['status'] ?? '?' ), 'failed' );

	$bad = $sample_dragdrop;
	$bad['reconstructedReference'] = 'Not the real reconstructed sentence at all.';
	check( '[2] a tampered reconstructedReference fails validation', ( Citex_Generated_Validator::validate( $bad )['status'] ?? '?' ), 'failed' );
} else {
	check( '[2] a DragDrop sample was available to corrupt', false, true );
}

// ---------------------------------------------------------------------
// 3. Negative coverage — MCQ: a tampered option or reconstructedReference
// fails validation too.
// ---------------------------------------------------------------------
if ( null !== $sample_mcq ) {
	$bad = $sample_mcq;
	$bad['options'][0] = $bad['options'][0] . ' EXTRA';
	check( '[3] tampering with an MCQ option fails validation', ( Citex_Generated_Validator::validate( $bad )['status'] ?? '?' ), 'failed' );

	$bad = $sample_mcq;
	$bad['reconstructedReference'] = 'Not the real correct answer at all.';
	check( '[3] a tampered MCQ reconstructedReference fails validation', ( Citex_Generated_Validator::validate( $bad )['status'] ?? '?' ), 'failed' );
} else {
	check( '[3] an MCQ sample was available to corrupt', false, true );
}

// ---------------------------------------------------------------------
// 4. CRITICAL — the single most Chicago-distinctive in-text mistake: a
// comma wrongly inserted between the author and the year (Harvard/APA/
// MHRA's own rule, but never Chicago's) must fail validation.
// ---------------------------------------------------------------------
$parenthetical_sample = build_candidate( $BOOK, 'parenthetical', 'DragDrop', 9001 );
if ( ! is_wp_error( $parenthetical_sample ) ) {
	$broken = $parenthetical_sample;
	$year   = $broken['year'];
	$broken['reconstructedReference'] = str_replace( ' ' . $year . ')', ', ' . $year . ')', $broken['reconstructedReference'] );
	check( '[4] a comma wrongly added before the year fails validation', ( Citex_Generated_Validator::validate( $broken )['status'] ?? '?' ), 'failed' );
} else {
	check( '[4] a parenthetical sample was available for the comma check', false, true );
}

// ---------------------------------------------------------------------
// 5. CRITICAL — a "p." prefix wrongly added before a page number (again,
// Harvard/APA/MHRA's own rule, never Chicago's) must fail validation.
// ---------------------------------------------------------------------
$quote_sample = build_candidate( $BOOK, 'parenthetical_quote', 'DragDrop', 9002 );
if ( ! is_wp_error( $quote_sample ) ) {
	$broken = $quote_sample;
	$page   = $broken['page'];
	$broken['reconstructedReference'] = str_replace( ', ' . $page . ')', ', p. ' . $page . ')', $broken['reconstructedReference'] );
	check( '[5] a "p." prefix wrongly added before the page fails validation', ( Citex_Generated_Validator::validate( $broken )['status'] ?? '?' ), 'failed' );
} else {
	check( '[5] a direct-quote sample was available for the "p." prefix check', false, true );
}

// ---------------------------------------------------------------------
// 6. A missing year for a non-Website category (Chicago always requires
// one, unlike MLA's optional year) must fail validation.
// ---------------------------------------------------------------------
$narrative_sample = build_candidate( $BOOK, 'narrative', 'DragDrop', 9003 );
if ( ! is_wp_error( $narrative_sample ) ) {
	$broken = $narrative_sample;
	$broken['reconstructedReference'] = str_replace( '(' . $broken['year'] . ')', '', $broken['reconstructedReference'] );
	check( '[6] a year wrongly omitted from a Chicago in-text citation fails validation', ( Citex_Generated_Validator::validate( $broken )['status'] ?? '?' ), 'failed' );
} else {
	check( '[6] a narrative sample was available for the year-omission check', false, true );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
