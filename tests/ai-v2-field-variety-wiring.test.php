<?php
/**
 * Regression tests for Citex_AI_V2::generate_questions()'s new default for
 * Book/Edited Book: $exercise_design defaults to 'random' (instead of the
 * inert 'full_reference' sentinel these two categories never read before),
 * which normalise_dragdrop_item()/normalise_edited_book_item() interpret as
 * "pick one of Citex_Reference_Rules::book_dragdrop_designs()/
 * edited_book_dragdrop_designs(), seeded per QUESTION" — so a batch of
 * several questions does not all draw the exact same fields (per the user's
 * own request: test place/publisher sometimes, not year every time).
 *
 * Crucially, every EXISTING test/caller that never passes $exercise_design
 * at all keeps normalise_dragdrop_item()/normalise_edited_book_item()'s own
 * default ('full_reference'), which is NOT one of either category's design
 * ids, so dragdrop_shape() falls through to the exact original baseline
 * shape — these tests exist to prove the 'random' opt-in is genuinely
 * additive, not a change to that default.
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

function make_book_item( $suffix ) {
	return array(
		'scenario'        => "You are referencing a book titled Book $suffix by Clara Vance, published in Cambridge by Polity in 2019.",
		'authorFullNames' => array( 'Clara Vance' ),
		'year'            => '2019',
		'bookTitle'       => "Book $suffix",
		'place'           => 'Cambridge',
		'publisher'       => 'Polity',
		'confusingWords'  => array( '2018', 'London', 'Brown' ),
	);
}

function make_edited_book_item( $suffix ) {
	return array(
		'scenario'        => "You are referencing an edited book titled Book $suffix, edited by Clara Vance, published in Cambridge by Polity in 2019.",
		'editorFullNames' => array( 'Clara Vance' ),
		'year'            => '2019',
		'bookTitle'       => "Book $suffix",
		'place'           => 'Cambridge',
		'publisher'       => 'Polity',
		'confusingWords'  => array( '2018', 'London', 'Brown' ),
	);
}

// ---------------------------------------------------------------------
// 1. Without opting in (the default 'full_reference' every pre-existing
// caller/test relies on), Book/Edited Book DragDrop questions are
// completely unaffected — always the exact original baseline shape.
// ---------------------------------------------------------------------
$book_ids = array_map( function ( $i ) { return 'BK' . str_pad( $i, 2, '0', STR_PAD_LEFT ); }, range( 1, 30 ) );
$book_items = array_map( function ( $i ) { return make_book_item( $i ); }, range( 1, 30 ) );
$unaffected_result = invoke_normalise( $book_items, $book_ids, 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_BOOK, 'full_reference' );
check( '[1] normalise() succeeds with the default exercise_design', is_wp_error( $unaffected_result ), false );
if ( ! is_wp_error( $unaffected_result ) ) {
	$all_baseline = true;
	foreach ( $unaffected_result as $candidate ) {
		if ( 'full_reference' !== $candidate['exerciseDesign'] || array( 'Vance', 'C.', '2019', $candidate['bookTitle'] ) !== $candidate['questionParts'] ) {
			$all_baseline = false;
		}
	}
	check( '[1] every question keeps the exact original baseline shape (no opt-in)', $all_baseline, true );
}

// ---------------------------------------------------------------------
// 2. With 'random' (generate_questions()'s real production default for
// this category), a batch of many Book questions is NOT all identical —
// some test year, some test place, some test publisher, per the user's
// explicit "make every question different" request.
// ---------------------------------------------------------------------
$random_result = invoke_normalise( $book_items, $book_ids, 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_BOOK, 'random' );
check( '[2] normalise() succeeds with exercise_design "random"', is_wp_error( $random_result ), false );
if ( ! is_wp_error( $random_result ) ) {
	$designs_seen = array();
	$known_designs = Citex_Reference_Rules::book_dragdrop_designs();
	foreach ( $random_result as $candidate ) {
		check( '[2] every candidate\'s exerciseDesign is one of the known Book designs', in_array( $candidate['exerciseDesign'], $known_designs, true ), true );
		check( '[2] every candidate has exactly 3 or 4 Question Parts', count( $candidate['questionParts'] ) >= 3 && count( $candidate['questionParts'] ) <= 4, true );
		check( '[2] every candidate\'s reconstructedReference still names the real book/author/year/place/publisher', $candidate['reconstructedReference'], 'Vance, C. (2019) ' . $candidate['bookTitle'] . '. Cambridge: Polity.' );
		$designs_seen[ $candidate['exerciseDesign'] ] = true;
	}
	check( '[2] a batch of 30 "random" questions is not all the same design', count( $designs_seen ) > 1, true );
	// Deterministic per-id: matches Citex_Reference_Rules::book_dragdrop_design_for()
	// directly, proving the seed really is the question's own id.
	check(
		'[2] each question\'s picked design matches book_dragdrop_design_for() for its own id',
		$random_result[0]['exerciseDesign'],
		Citex_Reference_Rules::book_dragdrop_design_for( 'BK01' )
	);
}

// ---------------------------------------------------------------------
// 3. Same coverage for Edited Book.
// ---------------------------------------------------------------------
$eb_ids = array_map( function ( $i ) { return 'EB' . str_pad( $i, 2, '0', STR_PAD_LEFT ); }, range( 1, 30 ) );
$eb_items = array_map( function ( $i ) { return make_edited_book_item( $i ); }, range( 1, 30 ) );
$eb_unaffected = invoke_normalise( $eb_items, $eb_ids, 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_EDITED_BOOK, 'full_reference' );
check( '[3] Edited Book: normalise() succeeds with the default exercise_design', is_wp_error( $eb_unaffected ), false );
if ( ! is_wp_error( $eb_unaffected ) ) {
	check( '[3] Edited Book: unaffected by default keeps the original baseline shape', $eb_unaffected[0]['questionParts'], array( 'Vance, C.', 'ed.', '2019', $eb_unaffected[0]['bookTitle'] ) );
}

$eb_random = invoke_normalise( $eb_items, $eb_ids, 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_EDITED_BOOK, 'random' );
check( '[3] Edited Book: normalise() succeeds with exercise_design "random"', is_wp_error( $eb_random ), false );
if ( ! is_wp_error( $eb_random ) ) {
	$eb_designs_seen = array();
	$known_eb_designs = Citex_Reference_Rules::edited_book_dragdrop_designs();
	foreach ( $eb_random as $candidate ) {
		check( '[3] every candidate\'s exerciseDesign is one of the known Edited Book designs', in_array( $candidate['exerciseDesign'], $known_eb_designs, true ), true );
		check( '[3] every candidate still draws the designation as its own Question Part', in_array( 'ed.', $candidate['questionParts'], true ), true );
	}
	$eb_designs_seen = array_unique( array_column( $eb_random, 'exerciseDesign' ) );
	check( '[3] a batch of 30 "random" Edited Book questions is not all the same design', count( $eb_designs_seen ) > 1, true );
}

// ---------------------------------------------------------------------
// 4. MCQ never reads exercise_design for either category — options/answer
// are built via build_reference() directly, unaffected either way.
// ---------------------------------------------------------------------
$mcq_item = make_book_item( 'MCQ' );
$mcq_item['distractors'] = array(
	array( 'reference' => 'Vance, C. (2018) A Different Book. Cambridge: Polity.', 'errorReason' => 'Wrong year.' ),
	array( 'reference' => 'Vance, C. (2019) Book MCQ. London: Polity.', 'errorReason' => 'Wrong place.' ),
	array( 'reference' => 'Vance, C. (2019) Book MCQ. Cambridge: Routledge.', 'errorReason' => 'Wrong publisher.' ),
);
$mcq_result = invoke_normalise( array( $mcq_item ), array( 'BK99' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_BOOK, 'random' );
check( '[4] MCQ succeeds even with exercise_design "random"', is_wp_error( $mcq_result ), false );
if ( ! is_wp_error( $mcq_result ) ) {
	check( '[4] MCQ candidate carries no exerciseDesign field at all (never read for MCQ)', array_key_exists( 'exerciseDesign', $mcq_result[0] ), false );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
