<?php
/**
 * Regression tests for Citex_Generated_Validator's Edited Book support —
 * added as the second Reference Category, alongside Book, via the
 * Citex_Reference_Rules pluggable layer. Covers both question types
 * (DragDrop and MCQ) for both one-editor and multi-editor references, and
 * the designation ("(ed.)" vs "(eds)") correctness this category exists to
 * test.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-edited-book.test.php` — not shipped in
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

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-generated-validator.php';

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
function has_error_code( $result, $code ) {
	foreach ( $result['errors'] as $error ) {
		if ( $code === $error['code'] ) {
			return true;
		}
	}
	return false;
}

function one_editor() {
	return array( array( 'surname' => 'Smith', 'initials' => 'J.' ) );
}
function two_editors() {
	return array(
		array( 'surname' => 'Smith', 'initials' => 'J.' ),
		array( 'surname' => 'Jones', 'initials' => 'A.' ),
	);
}

function edited_book_dragdrop_question( $overrides = array() ) {
	$editors = $overrides['editors'] ?? one_editor();
	unset( $overrides['editors'] );
	$reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array(
		'editors'   => $editors,
		'year'      => '2022',
		'title'     => 'Digital media and society',
		'place'     => 'London',
		'publisher' => 'SAGE Publications',
	) );
	$shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array(
		'editors'   => $editors,
		'year'      => '2022',
		'title'     => 'Digital media and society',
		'place'     => 'London',
		'publisher' => 'SAGE Publications',
	) );
	$scenario_names = implode( ' and ', array_column( $editors, 'surname' ) );
	return array_merge(
		array(
			'source'                 => 'Harvard',
			'group'                  => 'ReferenceList',
			'category'               => 'Edited Book',
			'type'                   => 'DragDrop',
			'editors'                => $editors,
			'year'                   => '2022',
			'bookTitle'              => 'Digital media and society',
			'place'                  => 'London',
			'publisher'              => 'SAGE Publications',
			'scenario'               => "You are referencing a book edited by {$scenario_names}, titled Digital media and society, published in 2022 by SAGE Publications in London.",
			'fixedText'              => $shape['fixedText'],
			'questionParts'          => $shape['parts'],
			'confusingWords'         => array( 'author', 'editor', '2019' ),
			'reconstructedReference' => $reference,
		),
		$overrides
	);
}

function edited_book_mcq_question( $overrides = array() ) {
	$editors = $overrides['editors'] ?? one_editor();
	unset( $overrides['editors'] );
	$reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array(
		'editors'   => $editors,
		'year'      => '2022',
		'title'     => 'Digital media and society',
		'place'     => 'London',
		'publisher' => 'SAGE Publications',
	) );
	$scenario_names = implode( ' and ', array_column( $editors, 'surname' ) );
	$options = array(
		'Smith, J. (2022) Digital media and society. London: SAGE Publications.', // missing designation
		$reference,
		'Smith, J. (editor) Digital media and society. London: SAGE Publications (2022).', // wrong designation word + year placement
		'Smith, J. (2022) (ed.) Digital media and society. SAGE Publications: London.', // ed./year swapped + place/publisher swapped
	);
	return array_merge(
		array(
			'source'                 => 'Harvard',
			'group'                  => 'ReferenceList',
			'category'               => 'Edited Book',
			'type'                   => 'MCQ',
			'editors'                => $editors,
			'year'                   => '2022',
			'bookTitle'              => 'Digital media and society',
			'place'                  => 'London',
			'publisher'              => 'SAGE Publications',
			'scenario'               => "You are referencing a book edited by {$scenario_names}, titled Digital media and society, published in 2022 by SAGE Publications in London.",
			'options'                => $options,
			'correctOptionIndex'     => 1,
			'reconstructedReference' => $reference,
			'explanation'            => 'B is correct because it follows the required Harvard Edited Book reference structure.',
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A correct, well-formed one-editor DragDrop question passes — matches
// the spec's own worked example (Smith, J. (ed.) (2022) ...).
// ---------------------------------------------------------------------
$good_dragdrop = Citex_Generated_Validator::validate( edited_book_dragdrop_question() );
check( '[1] a correct one-editor Edited Book DragDrop question passes', $good_dragdrop['status'], 'passed' );
check( '[1] no errors reported', $good_dragdrop['errors'], array() );
check( '[1] reconstructedReference matches the spec\'s exact example', $good_dragdrop['reconstructedReference'], 'Smith, J. (ed.) (2022) Digital media and society. London: SAGE Publications.' );

// ---------------------------------------------------------------------
// 2. A correct, well-formed one-editor MCQ question passes.
// ---------------------------------------------------------------------
$good_mcq = Citex_Generated_Validator::validate( edited_book_mcq_question() );
check( '[2] a correct one-editor Edited Book MCQ question passes', $good_mcq['status'], 'passed' );
check( '[2] no errors reported', $good_mcq['errors'], array() );

// ---------------------------------------------------------------------
// 3. Two editors: "(eds)" used correctly passes.
// ---------------------------------------------------------------------
$good_two_editors = Citex_Generated_Validator::validate( edited_book_dragdrop_question( array( 'editors' => two_editors() ) ) );
check( '[3] a correct two-editor DragDrop question passes', $good_two_editors['status'], 'passed' );
check( '[3] reconstructedReference matches the spec\'s two-editor example', $good_two_editors['reconstructedReference'], 'Smith, J. and Jones, A. (eds) (2022) Digital media and society. London: SAGE Publications.' );

// ---------------------------------------------------------------------
// 4. CRITICAL — "(ed.)" must never be used for multiple editors, and
// "(eds)" must never be used for a single editor. Explicitly tested per
// the spec's requirement.
// ---------------------------------------------------------------------
$wrong_designation_for_two = Citex_Generated_Validator::validate(
	edited_book_dragdrop_question( array(
		'editors'       => two_editors(),
		'questionParts' => array( 'Smith, J. and Jones, A.', 'ed.', '2022', 'Digital media and society' ), // WRONG: "ed." for 2 editors
	) )
);
check( '[4] using "(ed.)" for two editors FAILS', $wrong_designation_for_two['status'], 'failed' );
check( '[4] reports EDITED_BOOK_DESIGNATION_MISMATCH', has_error_code( $wrong_designation_for_two, 'edited_book_designation_mismatch' ), true );

$wrong_designation_for_one = Citex_Generated_Validator::validate(
	edited_book_dragdrop_question( array(
		'questionParts' => array( 'Smith, J.', 'eds', '2022', 'Digital media and society' ), // WRONG: "eds" for 1 editor
	) )
);
check( '[4] using "(eds)" for one editor FAILS', $wrong_designation_for_one['status'], 'failed' );
check( '[4] reports EDITED_BOOK_DESIGNATION_MISMATCH', has_error_code( $wrong_designation_for_one, 'edited_book_designation_mismatch' ), true );

// ---------------------------------------------------------------------
// 5. A reference missing the designation entirely (plain Book format)
// fails the Edited Book shape regex.
// ---------------------------------------------------------------------
$missing_designation = Citex_Generated_Validator::validate(
	edited_book_dragdrop_question( array(
		'fixedText'     => '|, || (||) ||. London: SAGE Publications.', // Book's own fixedText shape, no designation slot at all
		'questionParts' => array( 'Smith', 'J.', '2022', 'Digital media and society' ),
	) )
);
check( '[5] a reference with no editor designation at all fails the Edited Book format', $missing_designation['status'], 'failed' );
check( '[5] reports EDITED_BOOK_FORMAT_MISMATCH', has_error_code( $missing_designation, 'edited_book_format_mismatch' ), true );

// ---------------------------------------------------------------------
// 6. MCQ: a distractor that also happens to look correct (well-formed
// Edited Book shape) fails — reused MCQ_DISTRACTOR_LOOKS_CORRECT.
// ---------------------------------------------------------------------
$two_correct_looking = Citex_Generated_Validator::validate(
	edited_book_mcq_question( array(
		'options' => array(
			'Smith, J. (2022) Digital media and society. London: SAGE Publications.',
			Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array( 'editors' => one_editor(), 'year' => '2022', 'title' => 'Digital media and society', 'place' => 'London', 'publisher' => 'SAGE Publications' ) ),
			'Jones, A. (ed.) (2019) A totally different book. Manchester: Routledge.', // wrong book, but well-formed Edited Book shape too
			'Smith, J. (2022) (ed.) Digital media and society. SAGE Publications: London.',
		),
		'correctOptionIndex' => 1,
	) )
);
check( '[6] a well-formed-but-wrong-book Edited Book distractor fails (second plausible answer)', $two_correct_looking['status'], 'failed' );
check( '[6] reports MCQ_DISTRACTOR_LOOKS_CORRECT', has_error_code( $two_correct_looking, 'mcq_distractor_looks_correct' ), true );

// ---------------------------------------------------------------------
// 6b. CRITICAL — the reported bug (Question BK21) for Edited Book too: a
// distractor that swaps place and publisher, keeping the same book and
// editor(s), must be recognised as genuinely wrong (not a second plausible
// answer) once Citex knows this question's real place/publisher.
// ---------------------------------------------------------------------
$eb_swapped_distractor = Citex_Generated_Validator::validate(
	edited_book_mcq_question( array(
		'options' => array(
			'Smith, J. (ed.) (2022) Digital media and society. SAGE Publications: London.', // place/publisher swapped
			Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array( 'editors' => one_editor(), 'year' => '2022', 'title' => 'Digital media and society', 'place' => 'London', 'publisher' => 'SAGE Publications' ) ), // correct
			'Smith, J. (2022) Digital media and society. London: SAGE Publications.', // missing designation — genuinely malformed
			'Smith, J. (editor) (2022) Digital media and society. London: SAGE Publications.', // wrong designation word — genuinely malformed
		),
		'correctOptionIndex' => 1,
	) )
);
check( '[6b] a place/publisher-swapped Edited Book distractor no longer creates false ambiguity — the question PASSES', $eb_swapped_distractor['status'], 'passed' );
check( '[6b] no errors reported', $eb_swapped_distractor['errors'], array() );

// ---------------------------------------------------------------------
// 6c. CRITICAL — the category's headline distractor pattern: a distractor
// using the WRONG designation for this question's actual editor count
// (e.g. "(eds)" for a one-editor question) is itself a perfectly
// well-formed Edited Book reference by shape alone, so it used to also
// trigger MCQ_DISTRACTOR_LOOKS_CORRECT — exactly the reported bug, and the
// single most important distractor type this category exists to test
// ("must not accidentally use (ed.) for a book with multiple editors").
// Once Citex knows the real editor count, this must be recognised as
// genuinely wrong, not a second plausible answer.
// ---------------------------------------------------------------------
$eb_wrong_designation_distractor = Citex_Generated_Validator::validate(
	edited_book_mcq_question( array(
		'options' => array(
			'Smith, J. (eds) (2022) Digital media and society. London: SAGE Publications.', // WRONG designation for 1 editor — but otherwise well-formed
			Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array( 'editors' => one_editor(), 'year' => '2022', 'title' => 'Digital media and society', 'place' => 'London', 'publisher' => 'SAGE Publications' ) ), // correct
			'Smith, J. (2022) Digital media and society. London: SAGE Publications.', // missing designation — genuinely malformed
			'Smith, J. (editor) (2022) Digital media and society. London: SAGE Publications.', // wrong word — genuinely malformed
		),
		'correctOptionIndex' => 1,
	) )
);
check( '[6c] a wrong-designation-for-editor-count distractor no longer creates false ambiguity — the question PASSES', $eb_wrong_designation_distractor['status'], 'passed' );
check( '[6c] no errors reported', $eb_wrong_designation_distractor['errors'], array() );

// Sanity check the rule actually fires: the same wrong-designation
// reference, if it were mistakenly marked correct, must itself fail.
$eb_wrong_designation_as_correct = Citex_Generated_Validator::validate(
	edited_book_mcq_question( array(
		'options'            => array( 'x', 'Smith, J. (eds) (2022) Digital media and society. London: SAGE Publications.', 'y', 'z' ),
		'correctOptionIndex' => 1,
	) )
);
check( '[6c] the same reference fails when marked correct, proving the check genuinely fires', $eb_wrong_designation_as_correct['status'], 'failed' );
check( '[6c] reports EDITED_BOOK_DESIGNATION_MISMATCH', has_error_code( $eb_wrong_designation_as_correct, 'edited_book_designation_mismatch' ), true );

// ---------------------------------------------------------------------
// 6d. A third catalogued Edited Book distractor pattern with the same
// blind spot: two editors joined with a comma throughout ("Smith, J.,
// Jones, A.") instead of "and" before the last name. Structurally
// indistinguishable from correct by shape alone, so it too used to
// trigger false ambiguity — now recognised once Citex knows the real
// editor list.
// ---------------------------------------------------------------------
$eb_comma_joined_distractor = Citex_Generated_Validator::validate(
	edited_book_mcq_question( array(
		'editors' => two_editors(),
		'options' => array(
			'Smith, J., Jones, A. (eds) (2022) Digital media and society. London: SAGE Publications.', // comma instead of "and"
			Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array( 'editors' => two_editors(), 'year' => '2022', 'title' => 'Digital media and society', 'place' => 'London', 'publisher' => 'SAGE Publications' ) ), // correct
			'Smith, J. and Jones, A. (2022) Digital media and society. London: SAGE Publications.', // missing designation — genuinely malformed
			'Smith, J. and Jones, A. (editors) (2022) Digital media and society. London: SAGE Publications.', // wrong word — genuinely malformed
		),
		'correctOptionIndex' => 1,
	) )
);
check( '[6d] a comma-joined-editors distractor no longer creates false ambiguity — the question PASSES', $eb_comma_joined_distractor['status'], 'passed' );
check( '[6d] no errors reported', $eb_comma_joined_distractor['errors'], array() );

// Sanity check the rule actually fires.
$eb_comma_joined_as_correct = Citex_Generated_Validator::validate(
	edited_book_mcq_question( array(
		'editors'            => two_editors(),
		'options'            => array( 'x', 'Smith, J., Jones, A. (eds) (2022) Digital media and society. London: SAGE Publications.', 'y', 'z' ),
		'correctOptionIndex' => 1,
	) )
);
check( '[6d] the same reference fails when marked correct, proving the check genuinely fires', $eb_comma_joined_as_correct['status'], 'failed' );
check( '[6d] reports EDITED_BOOK_EDITOR_JOIN_MISMATCH', has_error_code( $eb_comma_joined_as_correct, 'edited_book_editor_join_mismatch' ), true );

// ---------------------------------------------------------------------
// 7. Answer leakage: a scenario that already shows "(ed.)"/"(eds)" leaks
// the designation answer directly.
// ---------------------------------------------------------------------
$leaked_designation = Citex_Generated_Validator::validate(
	edited_book_dragdrop_question( array(
		'scenario' => 'You are referencing a book edited by Smith (ed.), titled Digital media and society, published in 2022 by SAGE Publications in London.',
	) )
);
check( '[7] a scenario that already shows "(ed.)" FAILS (leaks the designation answer)', $leaked_designation['status'], 'failed' );
check( '[7] reports ANSWER_LEAKAGE_DESIGNATION_VALUE', has_error_code( $leaked_designation, 'answer_leakage_designation_value' ), true );

// ---------------------------------------------------------------------
// 8. Answer leakage still catches an abbreviated citation for EACH editor
// in a multi-editor question, not just the first.
// ---------------------------------------------------------------------
$leaked_second_editor = Citex_Generated_Validator::validate(
	edited_book_dragdrop_question( array(
		'editors'  => two_editors(),
		'scenario' => 'You are referencing a book edited by Smith and Jones, A., titled Digital media and society, published in 2022 by SAGE Publications in London.',
	) )
);
check( '[8] leaking the SECOND editor\'s abbreviated citation still FAILS', $leaked_second_editor['status'], 'failed' );
check( '[8] reports ANSWER_LEAKAGE_ABBREVIATED_CITATION', has_error_code( $leaked_second_editor, 'answer_leakage_abbreviated_citation' ), true );

// ---------------------------------------------------------------------
// 9. A scenario that omits one editor's surname fails (bibliographic
// consistency, generalised to multiple editors).
// ---------------------------------------------------------------------
$missing_editor_in_scenario = Citex_Generated_Validator::validate(
	edited_book_dragdrop_question( array(
		'editors'  => two_editors(),
		'scenario' => 'You are referencing a book edited by Smith, titled Digital media and society, published in 2022 by SAGE Publications in London.', // Jones never mentioned
	) )
);
check( '[9] a scenario omitting the second editor FAILS', $missing_editor_in_scenario['status'], 'failed' );
check( '[9] reports EDITED_BOOK_SCENARIO_MISMATCH', has_error_code( $missing_editor_in_scenario, 'edited_book_scenario_mismatch' ), true );

// ---------------------------------------------------------------------
// 10. Book itself is completely unaffected by Edited Book support.
// ---------------------------------------------------------------------
$book_still_works = Citex_Generated_Validator::validate(
	array(
		'source'                 => 'Harvard',
		'group'                  => 'ReferenceList',
		'category'               => 'Book',
		'type'                   => 'DragDrop',
		'authorSurname'          => 'Bryman',
		'authorInitials'         => 'A.',
		'year'                   => '2012',
		'bookTitle'              => 'Social Research Methods',
		'place'                  => 'Oxford',
		'publisher'              => 'Oxford University Press',
		'scenario'               => 'You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.',
		'fixedText'              => '|, || (||) ||. Oxford: Oxford University Press.',
		'questionParts'          => array( 'Bryman', 'A.', '2012', 'Social Research Methods' ),
		'confusingWords'         => array( '2015', 'Manchester', 'Brown' ),
		'reconstructedReference' => 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.',
	)
);
check( '[10] Book validation is completely unaffected by Edited Book support', $book_still_works['status'], 'passed' );

// ---------------------------------------------------------------------
// 11. An unrecognised category still fails UNSUPPORTED_GENERATED_FORMAT —
// adding Edited Book does not loosen this gate for anything else.
// ---------------------------------------------------------------------
$unknown_category = Citex_Generated_Validator::validate( edited_book_dragdrop_question( array( 'category' => 'Website' ) ) );
check( '[11] an unrecognised category still fails UNSUPPORTED_GENERATED_FORMAT', $unknown_category['status'], 'failed' );
check( '[11] reports UNSUPPORTED_GENERATED_FORMAT', has_error_code( $unknown_category, 'unsupported_generated_format' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
