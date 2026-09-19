<?php
/**
 * Regression tests for Citex_AI_V2's MLA Edited Book MCQ generation path —
 * mirrors tests/ai-v2-mla-book-mcq-variant-wiring.test.php's own structure
 * exactly, but for Citex_MLA_Edited_Book_Mcq_Variants: Gemini supplies only
 * the canonical record (editorFullNames/year/bookTitle/publisher — no
 * `place` field at all), normalise_mla_edited_book_mcq_item() picks a
 * variant per QUESTION (seeded by that question's own id, filtered to
 * variants compatible with the record's real editor count) and builds the
 * entire question deterministically via Citex_MLA_Edited_Book_Mcq_Variants::build().
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-mla-edited-book-mcq-variant-wiring.test.php` — not
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
	return $reflection->invoke( null, $questions, $ids, 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $target_count, '', '', 'full_reference', 'mla' );
}

function mla_eb_mcq_item( $editor_names, $suffix, $publisher = 'Routledge' ) {
	return array(
		'editorFullNames' => $editor_names,
		'year'            => '2021',
		'bookTitle'       => "Book $suffix",
		'publisher'       => $publisher,
	);
}

function diverse_publisher( $i ) {
	$publishers = array( 'Routledge', 'Pearson', 'SAGE', 'Wiley', 'Springer', 'Oxford University Press', 'Bloomsbury' );
	return $publishers[ $i % count( $publishers ) ];
}

$known_variants = Citex_MLA_Edited_Book_Mcq_Variants::variants();

// ---------------------------------------------------------------------
// 1. A single-editor question is fully Citex-authored: source/category,
// mcqPattern, mlaEditedBookMcqVariant, and an exact match against
// Citex_MLA_Edited_Book_Mcq_Variants::build()'s own output.
// ---------------------------------------------------------------------
$single_item   = mla_eb_mcq_item( array( 'John Smith' ), 'One' );
$single_result = invoke_normalise( array( $single_item ), array( 'ME01' ) );
check( '[1] normalise() succeeds for a single-editor MLA Edited Book MCQ item', is_wp_error( $single_result ), false );
if ( ! is_wp_error( $single_result ) ) {
	$candidate = $single_result[0];
	check( '[1] source is MLA', $candidate['source'], 'MLA' );
	check( '[1] category is Edited Book', $candidate['category'], 'Edited Book' );
	check( '[1] mcqPattern is mla_edited_book_mcq_variant', $candidate['mcqPattern'], 'mla_edited_book_mcq_variant' );
	check( '[1] mlaEditedBookMcqVariant is one of the 8 known variants', in_array( $candidate['mlaEditedBookMcqVariant'], $known_variants, true ), true );
	check( '[1] no place field is carried at all', array_key_exists( 'place', $candidate ), false );
	check( '[1] editor carries givenName, not initials', $candidate['editors'][0]['givenName'], 'John' );
	check( '[1] editor carries no initials field', array_key_exists( 'initials', $candidate['editors'][0] ), false );
	check( '[1] options has exactly 4 slots', count( $candidate['options'] ), 4 );
	check( '[1] option 4 is always blank', $candidate['options'][3], '' );
	check( '[1] the correct answer never appears in any option slot', in_array( $candidate['reconstructedReference'], array_slice( $candidate['options'], 0, 3 ), true ), false );

	$expected = Citex_MLA_Edited_Book_Mcq_Variants::build(
		$candidate['mlaEditedBookMcqVariant'],
		array( 'editors' => array( array( 'surname' => 'Smith', 'givenName' => 'John', 'fullName' => 'John Smith' ) ), 'year' => '2021', 'title' => 'Book One', 'publisher' => 'Routledge' )
	);
	check( '[1] scenario is exactly the variant\'s own stem', $candidate['scenario'], $expected['stem'] );
	check( '[1] reconstructedReference is exactly the variant\'s own correct answer', $candidate['reconstructedReference'], $expected['correctAnswer'] );
	check( '[1] options 1-3 are exactly the variant\'s own wrong options', array_slice( $candidate['options'], 0, 3 ), $expected['wrongOptions'] );
	check( '[1] a non-empty hint is generated', '' !== trim( (string) $candidate['hint'] ), true );

	$validated = Citex_Generated_Validator::validate( $candidate );
	check( '[1] the candidate passes Citex_Generated_Validator::validate()', $validated['status'], 'passed' );
}

// ---------------------------------------------------------------------
// 2. A large batch of single-editor questions is not all the same
// variant — genuine per-question variety.
// ---------------------------------------------------------------------
$batch_items = array();
$batch_ids   = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$batch_items[] = mla_eb_mcq_item( array( 'John Smith' ), (string) $i, diverse_publisher( $i ) );
	$batch_ids[]   = 'ME' . str_pad( $i, 2, '0', STR_PAD_LEFT );
}
$batch_result = invoke_normalise( $batch_items, $batch_ids );
check( '[2] normalise() succeeds for a 30-question batch', is_wp_error( $batch_result ), false );
if ( ! is_wp_error( $batch_result ) ) {
	$variants_seen = array_unique( array_column( $batch_result, 'mlaEditedBookMcqVariant' ) );
	check( '[2] a batch of 30 single-editor questions is not all the same variant', count( $variants_seen ) > 1, true );
	foreach ( $batch_result as $candidate ) {
		if ( ! in_array( $candidate['mlaEditedBookMcqVariant'], $known_variants, true ) ) {
			check( '[2] every candidate\'s mlaEditedBookMcqVariant is a known variant', $candidate['mlaEditedBookMcqVariant'], '(unknown)' );
		}
	}
}

// ---------------------------------------------------------------------
// 3. An editor-count-specific bucket (target_count) only ever produces a
// variant compatible with that exact count.
// ---------------------------------------------------------------------
function check_variant_compatible_with_count( $section, $editor_full_names, $target_count ) {
	$items = array();
	$ids   = array();
	for ( $i = 1; $i <= 20; $i++ ) {
		$items[] = mla_eb_mcq_item( $editor_full_names, (string) $i, diverse_publisher( $i ) );
		$ids[]   = 'ME' . str_pad( $i, 2, '0', STR_PAD_LEFT ) . $section;
	}
	$result = invoke_normalise( $items, $ids, $target_count );
	check( "[3] $section: normalise() succeeds", is_wp_error( $result ), false );
	if ( is_wp_error( $result ) ) {
		return;
	}
	$all_compatible = true;
	foreach ( $result as $candidate ) {
		$bounds = Citex_MLA_Edited_Book_Mcq_Variants::variant_editor_requirement( $candidate['mlaEditedBookMcqVariant'] );
		if ( null !== $bounds && ( $target_count < $bounds[0] || $target_count > $bounds[1] ) ) {
			$all_compatible = false;
		}
	}
	check( "[3] $section: every assigned variant is compatible with $target_count editor(s)", $all_compatible, true );
}
check_variant_compatible_with_count( 'two', array( 'Amy Ross', 'Ben Carter' ), 2 );
check_variant_compatible_with_count( 'three', array( 'Amy Ross', 'Ben Carter', 'Kim Lee' ), 3 );

// ---------------------------------------------------------------------
// 4. A record with no exerciseDesign field at all — that field belongs
// only to Journal Article's field-variety mechanism.
// ---------------------------------------------------------------------
if ( ! is_wp_error( $single_result ) ) {
	check( '[4] MLA Edited Book MCQ candidates carry no exerciseDesign field at all', array_key_exists( 'exerciseDesign', $single_result[0] ), false );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
