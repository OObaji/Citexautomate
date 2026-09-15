<?php
/**
 * Regression tests for Citex_AI_V2's MHRA Book DragDrop generation path —
 * mirrors tests/ai-v2-chicago-book-dragdrop-wiring.test.php's own
 * structure, but for MHRA's own rule (Citex_MHRA_Book_Dragdrop_Parts): a
 * `place` field IS carried, a full given name (`givenName`, the SAME shape
 * MLA's/Chicago's own wiring uses, never Harvard/APA's `initials`), only
 * the FIRST author is inverted (every author after stays in natural word
 * order), every author always listed in full ("and" before the last,
 * comma before it even at exactly 2, never "et al."), and any of the
 * authors can be the one split into draggable pieces.
 *
 * Gemini supplies ONLY the canonical book record (authorFullNames/year/
 * bookTitle/place/publisher) and a non-leaking scenario — no questionParts,
 * no fixedText, no confusingWords. normalise_mhra_book_dragdrop_item()
 * picks a selection per QUESTION (seeded by that question's own id) and
 * builds the entire question deterministically via
 * Citex_MHRA_Book_Dragdrop_Parts::select_parts()/build().
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-mhra-book-dragdrop-wiring.test.php` — not shipped in
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
require __DIR__ . '/../citex-tools/includes/class-citex-apa-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-chicago-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-chicago-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-chicago-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-book-mcq-variants.php';
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
	return $reflection->invoke( null, $questions, $ids, 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_BOOK, $target_count, '', '', 'full_reference', 'mhra' );
}

function mhra_dragdrop_item( $author_names, $suffix, $place = 'London', $publisher = 'Routledge' ) {
	return array(
		'scenario'        => "You are referencing a book titled Book $suffix by " . implode( ' and ', $author_names ) . ", published in 2019 by $publisher in $place.",
		'authorFullNames' => $author_names,
		'year'            => '2019',
		'bookTitle'       => "Book $suffix",
		'place'           => $place,
		'publisher'       => $publisher,
	);
}

// Deliberately different-length pools (7 places, 6 publishers — coprime,
// LCM 42) so the (place, publisher) PAIR never repeats within a
// 30-question batch — see ai-v2-chicago-book-dragdrop-wiring.test.php's
// own identical docblock for why.
function diverse_place( $i ) {
	$places = array( 'London', 'New York', 'Oxford', 'Toronto', 'Sydney', 'Berlin', 'Cape Town' );
	return $places[ $i % count( $places ) ];
}
function diverse_publisher( $i ) {
	$publishers = array( 'Routledge', 'Pearson', 'SAGE', 'Wiley', 'Springer', 'Bloomsbury' );
	return $publishers[ $i % count( $publishers ) ];
}

// ---------------------------------------------------------------------
// 1. A single-author question is fully Citex-authored: source/category, a
// `place` field IS carried, author carries givenName (not initials),
// dragdropPartKeys/questionParts/fixedText/confusingWords exactly match
// Citex_MHRA_Book_Dragdrop_Parts::build()'s own recomputation, and it
// round-trips through Citex_Generated_Validator::validate().
// ---------------------------------------------------------------------
$single_item   = mhra_dragdrop_item( array( 'John Smith' ), 'One', 'London', 'Routledge' );
$single_result = invoke_normalise( array( $single_item ), array( 'HB01' ) );
check( '[1] normalise() succeeds for a single-author MHRA Book DragDrop item', is_wp_error( $single_result ), false );
if ( ! is_wp_error( $single_result ) ) {
	$candidate = $single_result[0];
	check( '[1] source is MHRA', $candidate['source'], 'MHRA' );
	check( '[1] category is Book', $candidate['category'], 'Book' );
	check( '[1] a place field IS carried', $candidate['place'], 'London' );
	check( '[1] author carries givenName, not initials', $candidate['authors'][0]['givenName'], 'John' );
	check( '[1] author does not carry an initials field', array_key_exists( 'initials', $candidate['authors'][0] ), false );
	check( '[1] dragdropPartKeys is a non-empty array', ! empty( $candidate['dragdropPartKeys'] ), true );
	check( '[1] Question Parts count is exactly 3', count( $candidate['questionParts'] ), 3 );
	check( '[1] confusingWords count matches questionParts count', count( $candidate['confusingWords'] ), count( $candidate['questionParts'] ) );

	$expected_authors = array( array( 'surname' => 'Smith', 'givenName' => 'John', 'fullName' => 'John Smith' ) );
	$expected_fields  = array( 'title' => 'Book One', 'place' => 'London', 'publisher' => 'Routledge', 'year' => '2019' );
	$expected_built   = Citex_MHRA_Book_Dragdrop_Parts::build( $candidate['dragdropPartKeys'], $expected_authors, $expected_fields );
	check( '[1] Question Parts are exactly Citex_MHRA_Book_Dragdrop_Parts::build()\'s own output', $candidate['questionParts'], $expected_built['parts'] );
	check( '[1] Fixed Text is exactly Citex_MHRA_Book_Dragdrop_Parts::build()\'s own output', $candidate['fixedText'], $expected_built['fixedText'] );
	check( '[1] confusingWords is exactly Citex_MHRA_Book_Dragdrop_Parts::build()\'s own output', $candidate['confusingWords'], $expected_built['confusingWords'] );
	check( '[1] reconstructedReference matches build_reference()', $candidate['reconstructedReference'], 'Smith, John, Book One (London: Routledge, 2019).' );

	$validated = Citex_Generated_Validator::validate( $candidate );
	check( '[1] the candidate passes Citex_Generated_Validator::validate()', $validated['status'], 'passed' );
}

// ---------------------------------------------------------------------
// 2. A large batch of single-author questions (varied place/publisher) is
// not all the same selection — genuine per-question variety.
// ---------------------------------------------------------------------
$batch_items = array();
$batch_ids   = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$batch_items[] = mhra_dragdrop_item( array( 'John Smith' ), (string) $i, diverse_place( $i ), diverse_publisher( $i ) );
	$batch_ids[]   = 'HB' . str_pad( $i, 2, '0', STR_PAD_LEFT );
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
// 3. Multi-author records (2, 3, 4 authors — only the first inverted,
// every author always listed in full, joined with "and") generate and
// validate correctly too — the "and" candidate is exercised across a
// spread of seeds for each count.
// ---------------------------------------------------------------------
function check_multi_author( $section, $author_full_names ) {
	$items = array();
	$ids   = array();
	for ( $i = 1; $i <= 20; $i++ ) {
		$items[] = mhra_dragdrop_item( $author_full_names, $section . $i, diverse_place( $i ), diverse_publisher( $i ) );
		$ids[]   = 'HB' . str_pad( $i, 2, '0', STR_PAD_LEFT ) . $section;
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
check_multi_author( 'two', array( 'John Smith', 'Amy Ross' ) );
check_multi_author( 'three', array( 'John Smith', 'Amy Ross', 'Ben Carter' ) );
check_multi_author( 'four', array( 'John Smith', 'Amy Ross', 'Ben Carter', 'Kim Lee' ) );

// ---------------------------------------------------------------------
// 4. HARD ENFORCEMENT (never gated behind QUALITY_GATE_ENABLED): a batch
// that reuses the exact same place/publisher combination on every question
// is rejected outright.
// ---------------------------------------------------------------------
$repetitive_items = array();
$repetitive_ids   = array();
for ( $i = 1; $i <= 10; $i++ ) {
	$repetitive_items[] = mhra_dragdrop_item( array( 'John Smith' ), 'R' . $i, 'London', 'Routledge' );
	$repetitive_ids[]   = 'HBR' . $i;
}
$repetitive_result = invoke_normalise( $repetitive_items, $repetitive_ids );
check( '[4] a batch reusing the exact same place/publisher pair on every question is rejected', is_wp_error( $repetitive_result ), true );

$genuinely_diverse_items = array();
$genuinely_diverse_ids   = array();
for ( $i = 1; $i <= 10; $i++ ) {
	$genuinely_diverse_items[] = mhra_dragdrop_item( array( 'John Smith' ), 'V' . $i, diverse_place( $i ), diverse_publisher( $i ) );
	$genuinely_diverse_ids[]   = 'HBV' . $i;
}
$genuinely_diverse_result = invoke_normalise( $genuinely_diverse_items, $genuinely_diverse_ids );
check( '[4] a genuinely diverse batch is NOT rejected', is_wp_error( $genuinely_diverse_result ), false );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
