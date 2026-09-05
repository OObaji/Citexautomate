<?php
/**
 * Regression tests for Citex_AI_V2's Book MCQ generation path — replaced
 * entirely (per explicit user request) by the user's own fixed 16-variant
 * catalogue (Citex_Book_Mcq_Variants), removing the original "select the
 * correct full reference" mechanic, "Identify the error", and "Choose the
 * correct rule/treatment" for Book specifically (all three remain in place,
 * untouched, for Edited Book/Journal Article/Website).
 *
 * Unlike the mechanic this replaces, Gemini supplies ONLY the canonical
 * book record (authorFullNames/year/bookTitle/place/publisher) — no
 * distractors, no error reasons. normalise_book_mcq_variant_item() picks a
 * variant per QUESTION (seeded by that question's own id, filtered to
 * variants compatible with the record's real author count) and builds the
 * entire question — stem, all 4 options, and the answer — deterministically
 * via Citex_Book_Mcq_Variants::build().
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-book-mcq-variant-wiring.test.php` — not shipped in
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
	return $reflection->invoke( null, $questions, $ids, 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, $target_count );
}

function mcq_item( $author_names, $suffix ) {
	return array(
		'authorFullNames' => $author_names,
		'year'            => '2021',
		'bookTitle'       => "Book $suffix",
		'place'           => 'London',
		'publisher'       => 'Routledge',
	);
}

$known_variants = Citex_Book_Mcq_Variants::variants();

// ---------------------------------------------------------------------
// 1. A single-author question is fully Citex-authored: mcqPattern,
// bookMcqVariant, and an exact match against Citex_Book_Mcq_Variants::build()'s
// own output for that variant and this record's fields.
// ---------------------------------------------------------------------
$single_item = mcq_item( array( 'Andrew Brown' ), 'One' );
$single_result = invoke_normalise( array( $single_item ), array( 'BK01' ) );
check( '[1] normalise() succeeds for a single-author Book MCQ item', is_wp_error( $single_result ), false );
if ( ! is_wp_error( $single_result ) ) {
	$candidate = $single_result[0];
	check( '[1] mcqPattern is book_mcq_variant', $candidate['mcqPattern'], 'book_mcq_variant' );
	check( '[1] bookMcqVariant is one of the 16 known variants', in_array( $candidate['bookMcqVariant'], $known_variants, true ), true );
	check( '[1] options has exactly 4 slots', count( $candidate['options'] ), 4 );
	check( '[1] option 4 is always blank', $candidate['options'][3], '' );
	check( '[1] the correct answer never appears in any option slot', in_array( $candidate['reconstructedReference'], array_slice( $candidate['options'], 0, 3 ), true ), false );

	$expected = Citex_Book_Mcq_Variants::build(
		$candidate['bookMcqVariant'],
		array( 'authors' => array( array( 'surname' => 'Brown', 'initials' => 'A.', 'fullName' => 'Andrew Brown' ) ), 'year' => '2021', 'title' => 'Book One', 'place' => 'London', 'publisher' => 'Routledge' )
	);
	check( '[1] scenario is exactly the variant\'s own stem', $candidate['scenario'], $expected['stem'] );
	check( '[1] reconstructedReference is exactly the variant\'s own correct answer', $candidate['reconstructedReference'], $expected['correctAnswer'] );
	check( '[1] options 1-3 are exactly the variant\'s own wrong options', array_slice( $candidate['options'], 0, 3 ), $expected['wrongOptions'] );
	check( '[1] a non-empty hint is generated', '' !== trim( (string) $candidate['hint'] ), true );

	$validated = Citex_Generated_Validator::validate( $candidate );
	check( '[1] the candidate passes Citex_Generated_Validator::validate()', $validated['status'], 'passed' );
}

// ---------------------------------------------------------------------
// 2. A large batch of single-author questions is not all the same
// variant — genuine per-question variety.
// ---------------------------------------------------------------------
$batch_items = array();
$batch_ids   = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$batch_items[] = mcq_item( array( 'Andrew Brown' ), (string) $i );
	$batch_ids[]   = 'BK' . str_pad( $i, 2, '0', STR_PAD_LEFT );
}
$batch_result = invoke_normalise( $batch_items, $batch_ids );
check( '[2] normalise() succeeds for a 30-question batch', is_wp_error( $batch_result ), false );
if ( ! is_wp_error( $batch_result ) ) {
	$variants_seen = array_unique( array_column( $batch_result, 'bookMcqVariant' ) );
	check( '[2] a batch of 30 single-author questions is not all the same variant', count( $variants_seen ) > 1, true );
	foreach ( $batch_result as $candidate ) {
		if ( ! in_array( $candidate['bookMcqVariant'], $known_variants, true ) ) {
			check( '[2] every candidate\'s bookMcqVariant is a known variant', $candidate['bookMcqVariant'], '(unknown)' );
		}
	}
}

// ---------------------------------------------------------------------
// 3. An author-count-specific bucket (target_count) only ever produces a
// variant compatible with that exact count — 'two_authors'/'author_order'
// never assigned to a 3- or 4-author record, etc.
// ---------------------------------------------------------------------
function check_variant_compatible_with_count( $section, $author_full_names, $target_count ) {
	$items = array();
	$ids   = array();
	for ( $i = 1; $i <= 20; $i++ ) {
		$items[] = mcq_item( $author_full_names, (string) $i );
		$ids[]   = 'BK' . str_pad( $i, 2, '0', STR_PAD_LEFT ) . $section;
	}
	$result = invoke_normalise( $items, $ids, $target_count );
	check( "[3] $section: normalise() succeeds", is_wp_error( $result ), false );
	if ( is_wp_error( $result ) ) {
		return;
	}
	$all_compatible = true;
	foreach ( $result as $candidate ) {
		$bounds = Citex_Book_Mcq_Variants::variant_author_requirement( $candidate['bookMcqVariant'] );
		if ( null !== $bounds && ( $target_count < $bounds[0] || $target_count > $bounds[1] ) ) {
			$all_compatible = false;
		}
	}
	check( "[3] $section: every assigned variant is compatible with $target_count author(s)", $all_compatible, true );
}
check_variant_compatible_with_count( 'two', array( 'Andrew Brown', 'James Smith' ), 2 );
check_variant_compatible_with_count( 'three', array( 'John Carter', 'Emma Green', 'David Smith' ), 3 );
check_variant_compatible_with_count( 'four', array( 'Andrew Brown', 'John Carter', 'Paul Evans', 'Tom Wilson' ), 4 );

// ---------------------------------------------------------------------
// 4. A record with no exerciseDesign field at all (that field belongs only
// to DragDrop's field-variety mechanism — see class-citex-reference-rules.php) —
// Book MCQ candidates never carry it.
// ---------------------------------------------------------------------
if ( ! is_wp_error( $single_result ) ) {
	check( '[4] Book MCQ candidates carry no exerciseDesign field at all', array_key_exists( 'exerciseDesign', $single_result[0] ), false );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
