<?php
/**
 * Regression tests for Citex_AI_V2's Edited Book generation path — added as
 * the second Reference Category, alongside Book, via the pluggable
 * Citex_Reference_Rules layer. Mirrors ai-v2-mcq-construction.test.php's
 * "Citex, not Gemini, is the authority" principle: Gemini supplies
 * editorFullNames (one or two full names) plus the shared canonical fields,
 * but Citex alone derives each editor's surname/initials, decides the
 * "(ed.)" vs "(eds)" designation from the editor count, and constructs the
 * reference/Question Parts/Fixed Text/MCQ correct option — the same
 * construction Citex_Reference_Rules::build_reference()/dragdrop_shape()
 * also drive inside Citex_Generated_Validator, so generation and
 * validation can never silently disagree about what "correct" looks like.
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-edited-book-construction.test.php` — not shipped in
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
function get_option( $key, $default = null ) {
	return $default;
}

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-generated-validator.php';
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

function invoke_normalise( $questions, $ids, $difficulty, $exercises = array(), $type = 'DragDrop', $category = null ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions, $ids, $difficulty, $exercises, $type, $category );
}

function one_editor_dragdrop_item( $overrides = array() ) {
	return array_merge(
		array(
			'scenario'        => 'You are referencing a book edited by Vincent Miller, titled Understanding digital culture, published in 2020 by SAGE Publications in London.',
			'editorFullNames' => array( 'Vincent Miller' ),
			'year'            => '2020',
			'bookTitle'       => 'Understanding digital culture',
			'place'           => 'London',
			'publisher'       => 'SAGE Publications',
			'confusingWords'  => array( 'author', 'editor', '2019' ),
		),
		$overrides
	);
}

function one_editor_mcq_item( $overrides = array() ) {
	return array_merge(
		array(
			'scenario'            => 'You are referencing a book edited by Vincent Miller, titled Understanding digital culture, published in 2020 by SAGE Publications in London.',
			'editorFullNames'     => array( 'Vincent Miller' ),
			'year'                => '2020',
			'bookTitle'           => 'Understanding digital culture',
			'place'               => 'London',
			'publisher'           => 'SAGE Publications',
			'incorrectReferences' => array(
				'Miller, V. (2020) Understanding digital culture. London: SAGE Publications.',
				'Miller, V. (editor) (2020) Understanding digital culture. London: SAGE Publications.',
				'Miller, V. (2020) (ed.) Understanding digital culture. London: SAGE Publications.',
			),
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A valid one-editor DragDrop item produces a correctly-shaped Edited
// Book candidate: category, 4 Question Parts (editor joined, designation,
// year, title), Fixed Text with the designation slot, and the exact
// reconstructed reference from the spec's own worked example.
// ---------------------------------------------------------------------
$result = invoke_normalise( array( one_editor_dragdrop_item() ), array( 'EB01' ), 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_EDITED_BOOK );
check( '[1] normalise() succeeds for a valid one-editor Edited Book DragDrop item', is_wp_error( $result ), false );
if ( ! is_wp_error( $result ) ) {
	$candidate = $result[0];
	check( '[1] candidate category is Edited Book', $candidate['category'], 'Edited Book' );
	check( '[1] candidate type is DragDrop', $candidate['type'], 'DragDrop' );
	check( '[1] candidate title names Edited Book', $candidate['title'], 'Harvard | ReferenceList | Edited Book | DragDrop | EB01' );
	check( '[1] editors array carries the derived surname/initials', $candidate['editors'], array( array( 'fullName' => 'Vincent Miller', 'surname' => 'Miller', 'initials' => 'V.' ) ) );
	check( '[1] exactly 4 Question Parts', count( $candidate['questionParts'] ), 4 );
	check( '[1] Question Parts are [editor joined, designation, year, title]', $candidate['questionParts'], array( 'Miller, V.', 'ed.', '2020', 'Understanding digital culture' ) );
	check( '[1] Fixed Text uses the 3-slot designation shape', $candidate['fixedText'], '| (||) (||) ||. London: SAGE Publications.' );
	check( '[1] reconstructedReference matches the spec\'s one-editor worked example', $candidate['reconstructedReference'], 'Miller, V. (ed.) (2020) Understanding digital culture. London: SAGE Publications.' );
	check( '[1] validation passed (pre-queue quality gate)', $candidate['validationStatus'], 'passed' );
}

// ---------------------------------------------------------------------
// 2. A valid one-editor MCQ item: Citex builds the single correct option
// (never trusting Gemini's incorrectReferences), using the correct "(ed.)"
// designation for a single editor.
// ---------------------------------------------------------------------
$mcq_result = invoke_normalise( array( one_editor_mcq_item() ), array( 'EB02' ), 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_EDITED_BOOK );
check( '[2] normalise() succeeds for a valid one-editor Edited Book MCQ item', is_wp_error( $mcq_result ), false );
if ( ! is_wp_error( $mcq_result ) ) {
	$mcq_candidate = $mcq_result[0];
	check( '[2] candidate category is Edited Book', $mcq_candidate['category'], 'Edited Book' );
	check( '[2] candidate type is MCQ', $mcq_candidate['type'], 'MCQ' );
	check( '[2] exactly 4 options', count( $mcq_candidate['options'] ), 4 );
	check( '[2] the correct option is Citex\'s own construction with the correct designation', $mcq_candidate['options'][ $mcq_candidate['correctOptionIndex'] ], 'Miller, V. (ed.) (2020) Understanding digital culture. London: SAGE Publications.' );
	check( '[2] reconstructedReference matches the correct option', $mcq_candidate['reconstructedReference'], $mcq_candidate['options'][ $mcq_candidate['correctOptionIndex'] ] );
	check( '[2] an explanation is generated', '' !== trim( $mcq_candidate['explanation'] ), true );
}

// ---------------------------------------------------------------------
// 3. CRITICAL — two editors must produce "(eds)", never "(ed.)". This is
// the exact rule the user flagged as needing explicit testing.
// ---------------------------------------------------------------------
$two_editor_item = one_editor_dragdrop_item( array(
	'scenario'        => 'You are referencing a book edited by John Smith and Amy Jones, titled Understanding digital culture, published in 2020 by SAGE Publications in London.',
	'editorFullNames' => array( 'John Smith', 'Amy Jones' ),
) );
$two_editor_result = invoke_normalise( array( $two_editor_item ), array( 'EB03' ), 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_EDITED_BOOK );
check( '[3] normalise() succeeds for a valid two-editor Edited Book DragDrop item', is_wp_error( $two_editor_result ), false );
if ( ! is_wp_error( $two_editor_result ) ) {
	$two_editor_candidate = $two_editor_result[0];
	check( '[3] two editors are joined with "and" and use "(eds)", never "(ed.)"', $two_editor_candidate['questionParts'], array( 'Smith, J. and Jones, A.', 'eds', '2020', 'Understanding digital culture' ) );
	check( '[3] reconstructedReference uses "(eds)" for two editors', $two_editor_candidate['reconstructedReference'], 'Smith, J. and Jones, A. (eds) (2020) Understanding digital culture. London: SAGE Publications.' );
	check( '[3] editors array carries both editors in order', $two_editor_candidate['editors'], array(
		array( 'fullName' => 'John Smith', 'surname' => 'Smith', 'initials' => 'J.' ),
		array( 'fullName' => 'Amy Jones', 'surname' => 'Jones', 'initials' => 'A.' ),
	) );
}

// ---------------------------------------------------------------------
// 4. Editor-count validation: zero editors or more than two are rejected
// before any reference construction is attempted.
// ---------------------------------------------------------------------
$zero_editors = invoke_normalise( array( one_editor_dragdrop_item( array( 'editorFullNames' => array() ) ) ), array( 'EB01' ), 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_EDITED_BOOK );
check( '[4] zero editors is rejected', is_wp_error( $zero_editors ), true );
check( '[4] error code identifies the editor-count problem', is_wp_error( $zero_editors ) ? $zero_editors->get_error_code() : null, 'citex_ai_bad_editor_count' );

$three_editors = invoke_normalise( array( one_editor_dragdrop_item( array( 'editorFullNames' => array( 'John Smith', 'Amy Jones', 'Tom Lee' ) ) ) ), array( 'EB01' ), 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_EDITED_BOOK );
check( '[4] three editors is rejected', is_wp_error( $three_editors ), true );
check( '[4] error code identifies the editor-count problem for 3 editors too', is_wp_error( $three_editors ) ? $three_editors->get_error_code() : null, 'citex_ai_bad_editor_count' );

// ---------------------------------------------------------------------
// 5. A malformed editor name (no given name, so no initials can be
// derived) is rejected — the same derive_author_parts() guard Book uses,
// applied per editor.
// ---------------------------------------------------------------------
$bad_editor_name = invoke_normalise( array( one_editor_dragdrop_item( array( 'editorFullNames' => array( 'Miller' ) ) ) ), array( 'EB01' ), 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_EDITED_BOOK );
check( '[5] a surname-only editor name is rejected', is_wp_error( $bad_editor_name ), true );
check( '[5] error code identifies the incomplete name', is_wp_error( $bad_editor_name ) ? $bad_editor_name->get_error_code() : null, 'citex_ai_missing_field' );

// ---------------------------------------------------------------------
// 6. Missing bibliographic data (place) is rejected — the same shared
// field extraction/validation used by Book also gates Edited Book.
// ---------------------------------------------------------------------
$missing_place = invoke_normalise( array( one_editor_dragdrop_item( array( 'place' => '' ) ) ), array( 'EB01' ), 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_EDITED_BOOK );
check( '[6] a missing bibliographic field (place) is rejected', is_wp_error( $missing_place ), true );
check( '[6] error code identifies the missing field', is_wp_error( $missing_place ) ? $missing_place->get_error_code() : null, 'citex_ai_missing_field' );

// ---------------------------------------------------------------------
// 7. Exercise assignment (Citex-owned, by slot index) applies identically
// to Edited Book candidates — Gemini's schema has no exercise field either.
// ---------------------------------------------------------------------
$with_exercise = invoke_normalise( array( one_editor_dragdrop_item() ), array( 'EB01' ), 'medium', array( 'Exercise 2' ), 'DragDrop', Citex_Reference_Rules::CATEGORY_EDITED_BOOK );
check( '[7] Edited Book candidates are stamped with their pre-assigned exercise', is_wp_error( $with_exercise ) ? null : $with_exercise[0]['exercise'], 'Exercise 2' );

// ---------------------------------------------------------------------
// 8. A leaked scenario (showing "(ed.)" directly) is rejected by the
// pre-queue quality gate for Edited Book too.
// ---------------------------------------------------------------------
$leaked = invoke_normalise(
	array( one_editor_dragdrop_item( array( 'scenario' => 'You are referencing a book edited by Vincent Miller (ed.), titled Understanding digital culture, published in 2020 by SAGE Publications in London.' ) ) ),
	array( 'EB01' ),
	'medium',
	array(),
	'DragDrop',
	Citex_Reference_Rules::CATEGORY_EDITED_BOOK
);
check( '[8] a scenario leaking "(ed.)" is rejected by the pre-queue quality gate', is_wp_error( $leaked ), true );
check( '[8] error code identifies the quality-gate rejection', is_wp_error( $leaked ) ? $leaked->get_error_code() : null, 'citex_ai_validator_rejected' );

// ---------------------------------------------------------------------
// 9. Sanity check: omitting $category (or passing null) still produces a
// Book candidate — the new parameter does not change any existing caller
// that never passes it.
// ---------------------------------------------------------------------
$book_item = array(
	'scenario'       => 'You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.',
	'authorFullName' => 'Alan Bryman',
	'year'           => '2012',
	'bookTitle'      => 'Social Research Methods',
	'place'          => 'Oxford',
	'publisher'      => 'Oxford University Press',
	'confusingWords' => array( '2015', 'Manchester', 'Brown' ),
);
$book_result = invoke_normalise( array( $book_item ), array( 'BK99' ), 'medium' );
check( '[9] omitting $category still produces a Book candidate', is_wp_error( $book_result ) ? null : $book_result[0]['category'], 'Book' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
