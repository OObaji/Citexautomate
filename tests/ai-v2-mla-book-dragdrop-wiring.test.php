<?php
/**
 * Regression tests for Citex_AI_V2's MLA Book DragDrop generation path —
 * mirrors tests/ai-v2-book-dragdrop-wiring.test.php's own structure exactly,
 * but for MLA's genuinely different rule (Citex_MLA_Book_Dragdrop_Parts): no
 * `place` field at all, the full given name is used (never an initial), and
 * only the FIRST author is ever split into draggable pieces — a second
 * author is folded in as plain "First Last" text, and 3+ authors collapse
 * into a single "joiner" candidate carrying "et al.".
 *
 * Gemini supplies ONLY the canonical book record (authorFullNames/year/
 * bookTitle/publisher) and a non-leaking scenario — no questionParts, no
 * fixedText, no confusingWords. normalise_mla_book_dragdrop_item() picks a
 * selection per QUESTION (seeded by that question's own id) and builds the
 * entire question deterministically via
 * Citex_MLA_Book_Dragdrop_Parts::select_parts()/build().
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-mla-book-dragdrop-wiring.test.php` — not shipped in
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
	return $reflection->invoke( null, $questions, $ids, 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_BOOK, $target_count, '', '', 'full_reference', 'mla' );
}

function mla_dragdrop_item( $author_names, $suffix, $publisher = 'Routledge' ) {
	return array(
		'scenario'        => "You are referencing a book titled Book $suffix by " . implode( ' and ', $author_names ) . ", published in 2019 by $publisher.",
		'authorFullNames' => $author_names,
		'year'            => '2019',
		'bookTitle'       => "Book $suffix",
		'publisher'       => $publisher,
	);
}

function diverse_publisher( $i ) {
	$publishers = array( 'Routledge', 'Pearson', 'SAGE', 'Wiley', 'Springer', 'Oxford University Press', 'Bloomsbury' );
	return $publishers[ $i % count( $publishers ) ];
}

// ---------------------------------------------------------------------
// 1. A single-author question is fully Citex-authored: source/category, no
// `place` field at all, author carries givenName not initials,
// dragdropPartKeys/questionParts/fixedText/confusingWords exactly match
// Citex_MLA_Book_Dragdrop_Parts::build()'s own recomputation, and it
// round-trips through Citex_Generated_Validator::validate().
// ---------------------------------------------------------------------
$single_item   = mla_dragdrop_item( array( 'John Smith' ), 'One' );
$single_result = invoke_normalise( array( $single_item ), array( 'MB01' ) );
check( '[1] normalise() succeeds for a single-author MLA Book DragDrop item', is_wp_error( $single_result ), false );
if ( ! is_wp_error( $single_result ) ) {
	$candidate = $single_result[0];
	check( '[1] source is MLA', $candidate['source'], 'MLA' );
	check( '[1] category is Book', $candidate['category'], 'Book' );
	check( '[1] no place field is carried at all', array_key_exists( 'place', $candidate ), false );
	check( '[1] author carries givenName, not initials', $candidate['authors'][0]['givenName'], 'John' );
	check( '[1] dragdropPartKeys is a non-empty array', ! empty( $candidate['dragdropPartKeys'] ), true );
	check( '[1] Question Parts count is exactly 3', count( $candidate['questionParts'] ), 3 );
	check( '[1] confusingWords count matches questionParts count', count( $candidate['confusingWords'] ), count( $candidate['questionParts'] ) );
	check( '[1] no exerciseDesign field at all', array_key_exists( 'exerciseDesign', $candidate ), false );

	$expected_authors = array( array( 'surname' => 'Smith', 'givenName' => 'John', 'fullName' => 'John Smith' ) );
	$expected_fields  = array( 'year' => '2019', 'title' => 'Book One', 'publisher' => 'Routledge' );
	$expected_built   = Citex_MLA_Book_Dragdrop_Parts::build( $candidate['dragdropPartKeys'], $expected_authors, $expected_fields );
	check( '[1] Question Parts are exactly Citex_MLA_Book_Dragdrop_Parts::build()\'s own output', $candidate['questionParts'], $expected_built['parts'] );
	check( '[1] Fixed Text is exactly Citex_MLA_Book_Dragdrop_Parts::build()\'s own output', $candidate['fixedText'], $expected_built['fixedText'] );
	check( '[1] confusingWords is exactly Citex_MLA_Book_Dragdrop_Parts::build()\'s own output', $candidate['confusingWords'], $expected_built['confusingWords'] );
	check( '[1] reconstructedReference matches build_reference()', $candidate['reconstructedReference'], 'Smith, John. Book One. Routledge, 2019.' );

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
	$batch_items[] = mla_dragdrop_item( array( 'John Smith' ), (string) $i, diverse_publisher( $i ) );
	$batch_ids[]   = 'MB' . str_pad( $i, 2, '0', STR_PAD_LEFT );
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
// 3. Multi-author records (2, 3, 4 authors — MLA collapses every author
// after the first into "et al." once there are 3 or more) generate and
// validate correctly too — the "joiner" candidate ("and"/"et al.") is
// exercised across a spread of seeds for each count.
// ---------------------------------------------------------------------
function check_multi_author( $section, $author_full_names ) {
	$items = array();
	$ids   = array();
	for ( $i = 1; $i <= 20; $i++ ) {
		$items[] = mla_dragdrop_item( $author_full_names, $section . $i, diverse_publisher( $i ) );
		$ids[]   = 'MB' . str_pad( $i, 2, '0', STR_PAD_LEFT ) . $section;
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
check_multi_author( 'two', array( 'Amy Ross', 'Ben Carter' ) );
check_multi_author( 'three', array( 'Amy Ross', 'Ben Carter', 'Kim Lee' ) );
check_multi_author( 'four', array( 'Amy Ross', 'Ben Carter', 'Kim Lee', 'Tom Wilson' ) );

// ---------------------------------------------------------------------
// 4. HARD ENFORCEMENT (never gated behind QUALITY_GATE_ENABLED): a batch
// that reuses the exact same publisher on every question is rejected
// outright — MLA carries no `place` field at all, so only the
// publisher-dominance check can ever fire for it (mirrors Website's own
// "has publisher but no place" carve-out in check_place_publisher_diversity()).
// ---------------------------------------------------------------------
$repetitive_items = array();
$repetitive_ids   = array();
for ( $i = 1; $i <= 10; $i++ ) {
	$repetitive_items[] = mla_dragdrop_item( array( 'John Smith' ), 'R' . $i, 'Routledge' );
	$repetitive_ids[]   = 'MBR' . $i;
}
$repetitive_result = invoke_normalise( $repetitive_items, $repetitive_ids );
check( '[4] a batch reusing the exact same publisher on every question is rejected', is_wp_error( $repetitive_result ), true );
check( '[4] error code identifies the dominant publisher', is_wp_error( $repetitive_result ) ? $repetitive_result->get_error_code() : null, 'citex_ai_publisher_not_diverse' );

$genuinely_diverse_items = array();
$genuinely_diverse_ids   = array();
for ( $i = 1; $i <= 10; $i++ ) {
	$genuinely_diverse_items[] = mla_dragdrop_item( array( 'John Smith' ), 'V' . $i, diverse_publisher( $i ) );
	$genuinely_diverse_ids[]   = 'MBV' . $i;
}
$genuinely_diverse_result = invoke_normalise( $genuinely_diverse_items, $genuinely_diverse_ids );
check( '[4] a genuinely diverse batch is NOT rejected', is_wp_error( $genuinely_diverse_result ), false );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
