<?php
/**
 * Regression tests for Citex_AI_V2's APA Phase 2 generation path (Edited
 * Book, Journal Article, Website) — mirrors
 * tests/ai-v2-mla-edited-book-dragdrop-wiring.test.php's own structure, but
 * exercising all 3 new APA categories' DragDrop AND MCQ normalisers in one
 * file, since each category's own APA-Book-Phase-1 wiring test already
 * proved the "Citex authors the entire question, Gemini supplies only the
 * canonical record" pattern works end-to-end — this file's job is proving
 * that pattern now also holds for Edited Book/Journal Article/Website.
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-apa-phase2-wiring.test.php` — not shipped in
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
function absint( $v ) {
	return abs( intval( $v ) );
}
function get_option( $key, $default = null ) {
	return $default;
}

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-website-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-edited-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-edited-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-journal-article-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-journal-article-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-website-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-website-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-edited-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-journal-article-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-website-dragdrop-parts.php';
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

function invoke_normalise( $questions, $ids, $category, $type ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions, $ids, 'medium', array(), $type, $category, null, '', '', 'full_reference', 'apa' );
}

function diverse_publisher( $i ) {
	$publishers = array( 'Routledge', 'Pearson', 'SAGE', 'Wiley', 'Springer', 'Oxford University Press', 'Bloomsbury' );
	return $publishers[ $i % count( $publishers ) ];
}

// ---------------------------------------------------------------------
// Edited Book — DragDrop and MCQ, both round-trip through the validator.
// ---------------------------------------------------------------------
$eb_items = array();
$eb_ids   = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$eb_items[] = array(
		'scenario'        => "You are referencing an edited book titled Digital culture $i, edited by Amy Ross, published in 2019 by " . diverse_publisher( $i ) . '.',
		'editorFullNames' => array( 'Amy Ross' ),
		'year'            => '2019',
		'bookTitle'       => "Digital culture $i",
		'publisher'       => diverse_publisher( $i ),
	);
	$eb_ids[] = 'AE' . str_pad( $i, 2, '0', STR_PAD_LEFT );
}
$eb_dd_result = invoke_normalise( $eb_items, $eb_ids, Citex_Reference_Rules::CATEGORY_EDITED_BOOK, 'DragDrop' );
check( '[EditedBook DragDrop] normalise() succeeds', is_wp_error( $eb_dd_result ), false );
if ( ! is_wp_error( $eb_dd_result ) ) {
	$all_valid = true;
	foreach ( $eb_dd_result as $candidate ) {
		if ( 'APA' !== $candidate['source'] || 'Edited Book' !== $candidate['category'] || array_key_exists( 'place', $candidate ) || 'passed' !== Citex_Generated_Validator::validate( $candidate )['status'] ) {
			$all_valid = false;
		}
	}
	check( '[EditedBook DragDrop] every candidate is APA/Edited Book, no place field, and validates', $all_valid, true );
	check( '[EditedBook DragDrop] "designation" is always among the selected keys', in_array( 'designation', $eb_dd_result[0]['dragdropPartKeys'], true ), true );
}
$eb_mcq_result = invoke_normalise( $eb_items, $eb_ids, Citex_Reference_Rules::CATEGORY_EDITED_BOOK, 'MCQ' );
check( '[EditedBook MCQ] normalise() succeeds', is_wp_error( $eb_mcq_result ), false );
if ( ! is_wp_error( $eb_mcq_result ) ) {
	$all_valid = true;
	foreach ( $eb_mcq_result as $candidate ) {
		if ( 'apa_edited_book_mcq_variant' !== $candidate['mcqPattern'] || 'passed' !== Citex_Generated_Validator::validate( $candidate )['status'] ) {
			$all_valid = false;
		}
	}
	check( '[EditedBook MCQ] every candidate has mcqPattern apa_edited_book_mcq_variant and validates', $all_valid, true );
}

// ---------------------------------------------------------------------
// Journal Article — DragDrop and MCQ.
// ---------------------------------------------------------------------
$ja_items = array();
$ja_ids   = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$ja_items[] = array(
		'scenario'      => "You are referencing an article titled Climate policy $i by John Smith, published in 2020 in the journal Journal of Environmental Studies, volume 12, issue 3, pages 45 to 60.",
		'authorFullNames' => array( 'John Smith' ),
		'year'          => '2020',
		'articleTitle'  => "Climate policy $i",
		'journalTitle'  => 'Journal of Environmental Studies',
		'volume'        => '12',
		'issue'         => '3',
		'pages'         => '45-60',
	);
	$ja_ids[] = 'AJ' . str_pad( $i, 2, '0', STR_PAD_LEFT );
}
$ja_dd_result = invoke_normalise( $ja_items, $ja_ids, Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, 'DragDrop' );
check( '[JournalArticle DragDrop] normalise() succeeds', is_wp_error( $ja_dd_result ), false );
if ( ! is_wp_error( $ja_dd_result ) ) {
	$all_valid = true;
	foreach ( $ja_dd_result as $candidate ) {
		if ( 'APA' !== $candidate['source'] || 'Journal Article' !== $candidate['category'] || 'passed' !== Citex_Generated_Validator::validate( $candidate )['status'] ) {
			$all_valid = false;
		}
	}
	check( '[JournalArticle DragDrop] every candidate is APA/Journal Article and validates', $all_valid, true );
}
$ja_mcq_result = invoke_normalise( $ja_items, $ja_ids, Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, 'MCQ' );
check( '[JournalArticle MCQ] normalise() succeeds', is_wp_error( $ja_mcq_result ), false );
if ( ! is_wp_error( $ja_mcq_result ) ) {
	$all_valid = true;
	foreach ( $ja_mcq_result as $candidate ) {
		if ( 'apa_journal_article_mcq_variant' !== $candidate['mcqPattern'] || 'passed' !== Citex_Generated_Validator::validate( $candidate )['status'] ) {
			$all_valid = false;
		}
	}
	check( '[JournalArticle MCQ] every candidate has mcqPattern apa_journal_article_mcq_variant and validates', $all_valid, true );
}

// ---------------------------------------------------------------------
// Website — DragDrop and MCQ, individual (dated) + organisation (n.d.).
// ---------------------------------------------------------------------
function website_item( $i, $dated ) {
	$who = $dated ? 'John Smith' : 'World Health Organization';
	$item = array(
		'scenario'  => "You are referencing a webpage titled Climate data hub $i by $who, at https://example$i.com.",
		'title'     => "Climate data hub $i",
		'url'       => "https://example$i.com",
		'publisher' => diverse_publisher( $i ),
	);
	if ( $dated ) {
		$item['authorType']     = 'individual';
		$item['authorFullName'] = $who;
		$item['year']           = '2020';
	} else {
		$item['authorType']        = 'organisation';
		$item['organisationName']  = $who;
		$item['year']              = 'n.d.';
	}
	return $item;
}
$web_items = array();
$web_ids   = array();
for ( $i = 1; $i <= 10; $i++ ) {
	$web_items[] = website_item( $i, 0 === $i % 2 );
	$web_ids[]   = 'AW' . str_pad( $i, 2, '0', STR_PAD_LEFT );
}
$web_dd_result = invoke_normalise( $web_items, $web_ids, Citex_Reference_Rules::CATEGORY_WEBSITE, 'DragDrop' );
check( '[Website DragDrop] normalise() succeeds', is_wp_error( $web_dd_result ), false );
if ( ! is_wp_error( $web_dd_result ) ) {
	$all_valid = true;
	foreach ( $web_dd_result as $candidate ) {
		if ( 'APA' !== $candidate['source'] || array_key_exists( 'accessedDate', $candidate ) || 'passed' !== Citex_Generated_Validator::validate( $candidate )['status'] ) {
			$all_valid = false;
		}
	}
	check( '[Website DragDrop] every candidate is APA, has no accessedDate field, and validates', $all_valid, true );
}
$web_mcq_result = invoke_normalise( $web_items, $web_ids, Citex_Reference_Rules::CATEGORY_WEBSITE, 'MCQ' );
check( '[Website MCQ] normalise() succeeds', is_wp_error( $web_mcq_result ), false );
if ( ! is_wp_error( $web_mcq_result ) ) {
	$all_valid = true;
	foreach ( $web_mcq_result as $candidate ) {
		if ( 'apa_website_mcq_variant' !== $candidate['mcqPattern'] || 'passed' !== Citex_Generated_Validator::validate( $candidate )['status'] ) {
			$all_valid = false;
		}
	}
	check( '[Website MCQ] every candidate has mcqPattern apa_website_mcq_variant and validates', $all_valid, true );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
