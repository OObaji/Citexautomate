<?php
/**
 * Regression tests for Citex_AI_V2::generate_questions()'s 'random'
 * exercise_design default for Edited Book: $exercise_design defaults to
 * 'random' (instead of the inert 'full_reference' sentinel this category
 * never read before), which normalise_edited_book_item() interprets as
 * "pick one of Citex_Reference_Rules::edited_book_dragdrop_designs(),
 * seeded per QUESTION" — so a batch of several questions does not all draw
 * the exact same fields (per the user's own request: test place/publisher
 * sometimes, not year every time).
 *
 * Book no longer has an equivalent "exercise_design opt-in" concept at
 * all — its DragDrop shape is always built dynamically, per question, by
 * Citex_Book_Dragdrop_Parts (see tests/ai-v2-book-dragdrop-wiring.test.php
 * for that coverage), independent of any batch-level design/exercise_design
 * value.
 *
 * Crucially, every EXISTING test/caller that never passes $exercise_design
 * at all keeps normalise_edited_book_item()'s own default
 * ('full_reference'), which is NOT one of Edited Book's design ids, so
 * dragdrop_shape() falls through to the exact original baseline shape —
 * these tests exist to prove the 'random' opt-in is genuinely additive, not
 * a change to that default.
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-field-variety-wiring.test.php` — not shipped in
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

function invoke_normalise( $questions, $ids, $difficulty, $exercises, $type, $category, $exercise_design ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions, $ids, $difficulty, $exercises, $type, $category, null, '', '', $exercise_design );
}

// Place/publisher pools with coprime sizes, so cycling through them by
// index never repeats the exact same (place, publisher) PAIR within any
// batch this file builds (up to 30 items) — Citex_AI_V2::normalise()'s
// place/publisher diversity check (see check_place_publisher_diversity())
// would otherwise correctly reject these hand-written fixtures for being
// exactly as repetitive as the real bug it exists to catch.
function diverse_place( $i ) {
	$places = array( 'Cambridge', 'Oxford', 'London', 'Manchester', 'New York', 'Boston', 'Sydney', 'Paris', 'Berlin', 'Toronto' );
	return $places[ $i % count( $places ) ];
}
function diverse_publisher( $i ) {
	$publishers = array( 'Polity', 'Pearson', 'SAGE', 'Wiley', 'Springer', 'Oxford University Press', 'Bloomsbury' );
	return $publishers[ $i % count( $publishers ) ];
}

function make_book_item( $suffix, $place = 'Cambridge', $publisher = 'Polity' ) {
	return array(
		'scenario'        => "You are referencing a book titled Book $suffix by Clara Vance, published in $place by $publisher in 2019.",
		'authorFullNames' => array( 'Clara Vance' ),
		'year'            => '2019',
		'bookTitle'       => "Book $suffix",
		'place'           => $place,
		'publisher'       => $publisher,
	);
}

function make_edited_book_item( $suffix, $place = 'Cambridge', $publisher = 'Polity' ) {
	return array(
		'scenario'        => "You are referencing an edited book titled Book $suffix, edited by Clara Vance, published in $place by $publisher in 2019.",
		'editorFullNames' => array( 'Clara Vance' ),
		'year'            => '2019',
		'bookTitle'       => "Book $suffix",
		'place'           => $place,
		'publisher'       => $publisher,
		'confusingWords'  => array( '2018', 'London', 'Brown' ),
	);
}

// ---------------------------------------------------------------------
// 1/2. Edited Book DragDrop no longer has an "exercise_design" concept at
// all (Citex_Edited_Book_Dragdrop_Parts always forces the drawn editor +
// designation, plus one seeded-random extra field, per question) — the
// $exercise_design argument passed to normalise() below is simply ignored
// for DragDrop now (only MCQ still reads it). Verify instead: no
// exerciseDesign field on the candidate, the designation is always drawn,
// and a batch of many questions is NOT all identical.
// ---------------------------------------------------------------------
$eb_ids = array_map( function ( $i ) { return 'EB' . str_pad( $i, 2, '0', STR_PAD_LEFT ); }, range( 1, 30 ) );
$eb_items = array_map( function ( $i ) { return make_edited_book_item( $i, diverse_place( $i ), diverse_publisher( $i ) ); }, range( 1, 30 ) );
$eb_random = invoke_normalise( $eb_items, $eb_ids, 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_EDITED_BOOK, 'random' );
check( '[1] Edited Book: normalise() succeeds', is_wp_error( $eb_random ), false );
if ( ! is_wp_error( $eb_random ) ) {
	foreach ( $eb_random as $candidate ) {
		check( '[1] no exerciseDesign field at all: ' . $candidate['questionId'], array_key_exists( 'exerciseDesign', $candidate ), false );
		check( '[2] every candidate still draws the designation as its own Question Part', in_array( 'ed.', $candidate['questionParts'], true ), true );
	}
	$eb_selections_seen = array_unique( array_map( function ( $c ) { return implode( ',', $c['dragdropPartKeys'] ); }, $eb_random ) );
	check( '[2] a batch of 30 Edited Book questions is not all the same selection', count( $eb_selections_seen ) > 1, true );
}

// ---------------------------------------------------------------------
// 3. Book DragDrop ignores exercise_design entirely — its shape always
// comes from Citex_Book_Dragdrop_Parts, per question, regardless of what
// (if anything) is passed here. No exerciseDesign field at all on the
// candidate (that field belongs only to Edited Book/Journal
// Article/Website's own batch-level design concept).
// ---------------------------------------------------------------------
$book_ids   = array_map( function ( $i ) { return 'BK' . str_pad( $i, 2, '0', STR_PAD_LEFT ); }, range( 1, 5 ) );
$book_items = array_map( function ( $i ) { return make_book_item( $i, diverse_place( $i ), diverse_publisher( $i ) ); }, range( 1, 5 ) );
$book_result = invoke_normalise( $book_items, $book_ids, 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_BOOK, 'random' );
check( '[3] Book: normalise() succeeds regardless of exercise_design', is_wp_error( $book_result ), false );
if ( ! is_wp_error( $book_result ) ) {
	foreach ( $book_result as $candidate ) {
		check( '[3] Book candidate carries no exerciseDesign field at all: ' . $candidate['questionId'], array_key_exists( 'exerciseDesign', $candidate ), false );
	}
}

// ---------------------------------------------------------------------
// 4. MCQ never reads exercise_design for either category — options/answer
// are built via Citex_Book_Mcq_Variants::build()/build_reference()
// directly, unaffected either way.
// ---------------------------------------------------------------------
$mcq_item = make_book_item( 'MCQ' );
$mcq_result = invoke_normalise( array( $mcq_item ), array( 'BK99' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 'random' );
check( '[4] MCQ succeeds even with exercise_design "random"', is_wp_error( $mcq_result ), false );
if ( ! is_wp_error( $mcq_result ) ) {
	check( '[4] MCQ candidate carries no exerciseDesign field at all (never read for MCQ)', array_key_exists( 'exerciseDesign', $mcq_result[0] ), false );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
