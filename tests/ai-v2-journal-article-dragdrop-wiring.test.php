<?php
/**
 * Regression tests for Citex_AI_V2's Journal Article DragDrop generation
 * path — replaced entirely (per explicit user request) by the same "always
 * the full reference, exactly 3 random blanks" model Book DragDrop already
 * uses (Citex_Journal_Article_Dragdrop_Parts), removing the old fixed
 * named-design catalogue's use for DragDrop (journal_article_dragdrop_shape()
 * and friends remain fully intact and untouched — Journal Article MCQ still
 * uses them).
 *
 * Gemini now supplies ONLY the canonical article record
 * (authorFullNames/year/articleTitle/journalTitle/volume/issue/pages) and a
 * non-leaking scenario — no questionParts, no fixedText, no confusingWords,
 * no exerciseDesign. normalise_journal_article_item() picks a selection per
 * QUESTION (seeded by that question's own id) and builds the entire
 * question deterministically via
 * Citex_Journal_Article_Dragdrop_Parts::select_parts()/build().
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-journal-article-dragdrop-wiring.test.php` — not shipped
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
function absint( $v ) {
	return abs( intval( $v ) );
}
function get_option( $key, $default = null ) {
	return $default;
}

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-edited-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-journal-article-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-website-dragdrop-parts.php';
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

function invoke_normalise( $questions, $ids, $target_count = null ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions, $ids, 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $target_count );
}

function ja_dragdrop_item( $author_names, $suffix ) {
	return array(
		'scenario'      => 'You are creating a reference for an article titled Article ' . $suffix . ' by ' . implode( ' and ', $author_names ) . ', published in 2021 in the journal Sociology, volume 55, issue 3, pages 410 to 425.',
		'authorFullNames' => $author_names,
		'year'          => '2021',
		'articleTitle'  => 'Article ' . $suffix,
		'journalTitle'  => 'Sociology',
		'volume'        => '55',
		'issue'         => '3',
		'pages'         => '410-425',
	);
}

// ---------------------------------------------------------------------
// 1. A single-author question is fully Citex-authored: dragdropPartKeys,
// questionParts/fixedText/confusingWords exactly match
// Citex_Journal_Article_Dragdrop_Parts::build()'s own recomputation, and it
// round-trips through Citex_Generated_Validator::validate(). The full
// reference is always shown — nothing is ever omitted.
// ---------------------------------------------------------------------
$single_item   = ja_dragdrop_item( array( 'Adam Ross' ), 'One' );
$single_result = invoke_normalise( array( $single_item ), array( 'JA01' ) );
check( '[1] normalise() succeeds for a single-author Journal Article DragDrop item', is_wp_error( $single_result ), false );
if ( ! is_wp_error( $single_result ) ) {
	$candidate = $single_result[0];
	check( '[1] dragdropPartKeys is a non-empty array', ! empty( $candidate['dragdropPartKeys'] ), true );
	check( '[1] Question Parts count is exactly 3', count( $candidate['questionParts'] ), 3 );
	check( '[1] confusingWords count matches questionParts count', count( $candidate['confusingWords'] ), count( $candidate['questionParts'] ) );
	check( '[1] no exerciseDesign field at all (DragDrop no longer uses named designs)', array_key_exists( 'exerciseDesign', $candidate ), false );

	$expected_authors = array( array( 'surname' => 'Ross', 'initials' => 'A.', 'fullName' => 'Adam Ross' ) );
	$expected_fields  = array( 'year' => '2021', 'articleTitle' => 'Article One', 'journalTitle' => 'Sociology', 'volume' => '55', 'issue' => '3', 'pages' => '410-425' );
	$expected_built   = Citex_Journal_Article_Dragdrop_Parts::build( $candidate['dragdropPartKeys'], $expected_authors, $expected_fields );
	check( '[1] Question Parts are exactly Citex_Journal_Article_Dragdrop_Parts::build()\'s own output', $candidate['questionParts'], $expected_built['parts'] );
	check( '[1] Fixed Text is exactly Citex_Journal_Article_Dragdrop_Parts::build()\'s own output', $candidate['fixedText'], $expected_built['fixedText'] );
	check( '[1] confusingWords is exactly Citex_Journal_Article_Dragdrop_Parts::build()\'s own output', $candidate['confusingWords'], $expected_built['confusingWords'] );
	check( '[1] reconstructedReference always shows the COMPLETE reference', $candidate['reconstructedReference'], 'Ross, A. (2021) ‘Article One’, Sociology, 55(3), pp. 410–425.' );

	$validated = Citex_Generated_Validator::validate( $candidate );
	check( '[1] the candidate passes Citex_Generated_Validator::validate()', $validated['status'], 'passed' );
}

// ---------------------------------------------------------------------
// 2. A large batch of single-author questions is not all the same
// selection — genuine per-question variety.
// ---------------------------------------------------------------------
$batch_items = array();
$batch_ids   = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$batch_items[] = ja_dragdrop_item( array( 'Adam Ross' ), (string) $i );
	$batch_ids[]   = 'JA' . str_pad( $i, 2, '0', STR_PAD_LEFT );
}
$batch_result = invoke_normalise( $batch_items, $batch_ids );
check( '[2] normalise() succeeds for a 30-question batch', is_wp_error( $batch_result ), false );
if ( ! is_wp_error( $batch_result ) ) {
	$selections_seen = array_unique( array_map( function ( $c ) { return implode( ',', $c['dragdropPartKeys'] ); }, $batch_result ) );
	check( '[2] a batch of 30 single-author questions is not all the same selection', count( $selections_seen ) > 1, true );
	foreach ( $batch_result as $candidate ) {
		$validated = Citex_Generated_Validator::validate( $candidate );
		if ( 'passed' !== $validated['status'] ) {
			check( '[2] every candidate in the batch passes validation: ' . $candidate['questionId'], $validated['status'], 'passed' );
		}
	}
}

// ---------------------------------------------------------------------
// 3. Multi-author records (2, 3, 6 authors) generate and validate
// correctly too — "and" and a non-first author becoming a part are both
// exercised across a spread of seeds.
// ---------------------------------------------------------------------
function ja_check_multi_author( $section, $author_full_names ) {
	$items = array();
	$ids   = array();
	for ( $i = 1; $i <= 20; $i++ ) {
		$items[] = ja_dragdrop_item( $author_full_names, $section . $i );
		$ids[]   = 'JA' . str_pad( $i, 2, '0', STR_PAD_LEFT ) . $section;
	}
	$result = invoke_normalise( $items, $ids );
	check( "[3] $section: normalise() succeeds", is_wp_error( $result ), false );
	if ( is_wp_error( $result ) ) {
		return;
	}
	$all_valid = true;
	foreach ( $result as $candidate ) {
		$validated = Citex_Generated_Validator::validate( $candidate );
		if ( 'passed' !== $validated['status'] ) {
			$all_valid = false;
		}
	}
	check( "[3] $section: every candidate passes validation", $all_valid, true );
}
ja_check_multi_author( 'two', array( 'Adam Ross', 'Beth King' ) );
ja_check_multi_author( 'three', array( 'John Carter', 'Emma Green', 'David Smith' ) );
ja_check_multi_author( 'six', array( 'Adam Ross', 'Beth King', 'Carl Cole', 'Dana Reed', 'Eric Shaw', 'Faye Bell' ) );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
