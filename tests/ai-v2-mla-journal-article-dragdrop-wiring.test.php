<?php
/**
 * Regression tests for Citex_AI_V2's MLA Journal Article DragDrop
 * generation path — mirrors tests/ai-v2-mla-book-dragdrop-wiring.test.php's
 * own structure exactly, but via Citex_MLA_Journal_Article_Dragdrop_Parts:
 * authors, articleTitle/journalTitle/volume/issue/pages (no publisher/place
 * at all), and snake_case dragdropPartKeys entries (article_title/
 * journal_title).
 *
 * Gemini supplies ONLY the canonical journal article record and a
 * non-leaking scenario — no questionParts, no fixedText, no
 * confusingWords. normalise_mla_journal_article_dragdrop_item() picks a
 * selection per QUESTION (seeded by that question's own id) and builds the
 * entire question deterministically via
 * Citex_MLA_Journal_Article_Dragdrop_Parts::select_parts()/build().
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-mla-journal-article-dragdrop-wiring.test.php` — not
 * shipped in citex-tools.zip.
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
	return $reflection->invoke( null, $questions, $ids, 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $target_count, '', '', 'full_reference', 'mla' );
}

function mla_ja_dragdrop_item( $author_names, $suffix ) {
	// Volume/issue are spread far apart (tens vs hundreds) so the
	// deterministic ±1/±2 year_distractor() mutation used for both can
	// never accidentally land on the other field's own correct value —
	// a real but rare cross-field collision this test fixture must avoid,
	// not a production bug (Citex_AI_V2's own retry loop absorbs it at
	// generation time; this test calls normalise() directly, bypassing
	// that loop).
	$volume = (string) ( 20 + crc32( 'volume|' . $suffix ) % 30 );
	$issue  = (string) ( 200 + crc32( 'issue|' . $suffix ) % 30 );
	$surnames = array_map(
		function ( $full_name ) {
			$parts = explode( ' ', $full_name );
			return end( $parts );
		},
		$author_names
	);
	return array(
		'scenario'        => sprintf(
			'You are referencing an article titled "Art %1$s" by %2$s, published in %3$s in the journal J. Media %1$s, volume %4$s, issue %5$s, pages 10-25.',
			$suffix,
			implode( ' and ', $surnames ),
			'2019',
			$volume,
			$issue
		),
		'authorFullNames' => $author_names,
		'articleTitle'    => "Art $suffix",
		'journalTitle'    => 'J. Media ' . $suffix,
		'volume'          => $volume,
		'issue'           => $issue,
		'year'            => '2019',
		'pages'           => '10-25',
	);
}

// ---------------------------------------------------------------------
// 1. A single-author question is fully Citex-authored: source/category,
// no place/publisher field at all, author carries givenName not
// initials, dragdropPartKeys/questionParts/fixedText/confusingWords
// exactly match Citex_MLA_Journal_Article_Dragdrop_Parts::build()'s own
// recomputation, dragdropPartKeys entries are snake_case, and it
// round-trips through Citex_Generated_Validator::validate().
// ---------------------------------------------------------------------
$single_item   = mla_ja_dragdrop_item( array( 'John Smith' ), 'One' );
$single_result = invoke_normalise( array( $single_item ), array( 'MJ01' ) );
check( '[1] normalise() succeeds for a single-author MLA Journal Article DragDrop item', is_wp_error( $single_result ), false );
if ( ! is_wp_error( $single_result ) ) {
	$candidate = $single_result[0];
	check( '[1] source is MLA', $candidate['source'], 'MLA' );
	check( '[1] category is Journal Article', $candidate['category'], 'Journal Article' );
	check( '[1] no place field is carried at all', array_key_exists( 'place', $candidate ), false );
	check( '[1] no publisher field is carried at all', array_key_exists( 'publisher', $candidate ), false );
	check( '[1] author carries givenName, not initials', $candidate['authors'][0]['givenName'], 'John' );
	check( '[1] dragdropPartKeys is a non-empty array', ! empty( $candidate['dragdropPartKeys'] ), true );
	foreach ( $candidate['dragdropPartKeys'] as $key ) {
		check( "[1] dragdropPartKeys entry \"$key\" is lowercase snake_case (never camelCase)", $key, strtolower( $key ) );
	}
	check( '[1] Question Parts count is exactly 3', count( $candidate['questionParts'] ), 3 );
	check( '[1] confusingWords count matches questionParts count', count( $candidate['confusingWords'] ), count( $candidate['questionParts'] ) );

	$expected_authors = array( array( 'surname' => 'Smith', 'givenName' => 'John', 'fullName' => 'John Smith' ) );
	$expected_fields  = array( 'articleTitle' => 'Art One', 'journalTitle' => 'J. Media One', 'volume' => $candidate['volume'], 'issue' => $candidate['issue'], 'year' => '2019', 'pages' => '10-25' );
	$expected_built   = Citex_MLA_Journal_Article_Dragdrop_Parts::build( $candidate['dragdropPartKeys'], $expected_authors, $expected_fields );
	check( '[1] Question Parts are exactly Citex_MLA_Journal_Article_Dragdrop_Parts::build()\'s own output', $candidate['questionParts'], $expected_built['parts'] );
	check( '[1] Fixed Text is exactly Citex_MLA_Journal_Article_Dragdrop_Parts::build()\'s own output', $candidate['fixedText'], $expected_built['fixedText'] );
	check( '[1] confusingWords is exactly Citex_MLA_Journal_Article_Dragdrop_Parts::build()\'s own output', $candidate['confusingWords'], $expected_built['confusingWords'] );

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
	$batch_items[] = mla_ja_dragdrop_item( array( 'John Smith' ), (string) $i );
	$batch_ids[]   = 'MJ' . str_pad( $i, 2, '0', STR_PAD_LEFT );
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
// 3. Multi-author records (2, 3, 4 authors) generate and validate
// correctly too.
// ---------------------------------------------------------------------
function check_ja_multi_author( $section, $author_full_names ) {
	$items = array();
	$ids   = array();
	for ( $i = 1; $i <= 20; $i++ ) {
		$items[] = mla_ja_dragdrop_item( $author_full_names, $section . $i );
		$ids[]   = 'MJ' . str_pad( $i, 2, '0', STR_PAD_LEFT ) . $section;
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
check_ja_multi_author( 'two', array( 'Amy Ross', 'Ben Carter' ) );
check_ja_multi_author( 'three', array( 'Amy Ross', 'Ben Carter', 'Kim Lee' ) );
check_ja_multi_author( 'four', array( 'Amy Ross', 'Ben Carter', 'Kim Lee', 'Tom Wilson' ) );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
