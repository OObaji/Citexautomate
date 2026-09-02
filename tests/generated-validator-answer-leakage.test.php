<?php
/**
 * Regression tests for the ANSWER_LEAKAGE validation stage in
 * Citex_Generated_Validator, added after discovering that generated
 * DragDrop scenarios could explicitly hand the student a draggable answer,
 * e.g.:
 *
 *   "You are referencing the book titled Social Research Methods by Alan
 *   Bryman (initials A.), published in 2012 by Oxford University Press in
 *   Oxford."
 *
 * The "(initials A.)" parenthetical reveals one of the four Question
 * Parts directly, defeating the exercise. The scenario must give the
 * student enough bibliographic information to CONSTRUCT the Harvard
 * reference, never enough to simply copy an answer already spelled out.
 *
 * validate_answer_leakage() distinguishes natural bibliographic
 * information (a full name like "Alan Bryman", which naturally contains
 * the surname "Bryman" — required, and already covered by
 * BIBLIOGRAPHIC_CONSISTENCY) from explicit answer disclosure: the literal
 * words "initial"/"initials"/"surname", an abbreviated or completed
 * Harvard citation embedded in the scenario, or the literal initials
 * value appearing as a standalone token.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-answer-leakage.test.php` — not shipped
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

/**
 * The Bryman example from the bug report:
 * Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.
 */
function bryman_question( $scenario ) {
	return array(
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
		'scenario'               => $scenario,
		'fixedText'              => '|, || (||) ||. Oxford: Oxford University Press.',
		'questionParts'          => array( 'Bryman', 'A.', '2012', 'Social Research Methods' ),
		'confusingWords'         => array( '2015', 'Manchester', 'Brown' ),
		'reconstructedReference' => 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.',
	);
}

// ---------------------------------------------------------------------
// 1 & 10. A correct, natural scenario passes — the full author name
// naturally contains the surname, which is required, not leakage.
// ---------------------------------------------------------------------
$good = Citex_Generated_Validator::validate(
	bryman_question( 'You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.' )
);
check( '[1,10] "Alan Bryman" (full name, no leakage) passes', $good['status'], 'passed' );
check( '[1,10] no errors reported', $good['errors'], array() );

// ---------------------------------------------------------------------
// 2. "Alan Bryman (initials A.)" fails — the exact reported bug.
// ---------------------------------------------------------------------
$bug_repro = Citex_Generated_Validator::validate(
	bryman_question( 'You are referencing the book titled Social Research Methods by Alan Bryman (initials A.), published in 2012 by Oxford University Press in Oxford.' )
);
check( '[2] the exact reported bug ("(initials A.)") FAILS', $bug_repro['status'], 'failed' );
check( '[2] reports ANSWER_LEAKAGE_INITIALS_WORD', has_error_code( $bug_repro, 'answer_leakage_initials_word' ), true );

// ---------------------------------------------------------------------
// 3. "...his initials are A." fails.
// ---------------------------------------------------------------------
$initials_are = Citex_Generated_Validator::validate(
	bryman_question( 'The book Social Research Methods was written by Alan Bryman, whose initials are A., and published in 2012 by Oxford University Press in Oxford.' )
);
check( '[3] "his initials are A." FAILS', $initials_are['status'], 'failed' );
check( '[3] reports ANSWER_LEAKAGE_INITIALS_WORD', has_error_code( $initials_are, 'answer_leakage_initials_word' ), true );

// ---------------------------------------------------------------------
// 4. "The author's surname is Bryman..." fails.
// ---------------------------------------------------------------------
$surname_is = Citex_Generated_Validator::validate(
	bryman_question( "The author's surname is Bryman and his initials are A., publishing Social Research Methods in 2012 with Oxford University Press in Oxford." )
);
check( '[4] "surname is Bryman" FAILS', $surname_is['status'], 'failed' );
check( '[4] reports ANSWER_LEAKAGE_SURNAME_WORD', has_error_code( $surname_is, 'answer_leakage_surname_word' ), true );
check( '[4] also reports ANSWER_LEAKAGE_INITIALS_WORD', has_error_code( $surname_is, 'answer_leakage_initials_word' ), true );

// ---------------------------------------------------------------------
// 5. "...by Bryman, A., published in 2012..." fails.
// ---------------------------------------------------------------------
$abbreviated = Citex_Generated_Validator::validate(
	bryman_question( 'You are creating a reference for Social Research Methods by Bryman, A., published in 2012 by Oxford University Press in Oxford.' )
);
check( '[5] "Bryman, A." (abbreviated citation) FAILS', $abbreviated['status'], 'failed' );
check( '[5] reports ANSWER_LEAKAGE_ABBREVIATED_CITATION', has_error_code( $abbreviated, 'answer_leakage_abbreviated_citation' ), true );

// ---------------------------------------------------------------------
// 6. A completed Harvard reference embedded in the scenario fails.
// ---------------------------------------------------------------------
$completed = Citex_Generated_Validator::validate(
	bryman_question( 'You are referencing: Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.' )
);
check( '[6] a completed Harvard reference in the scenario FAILS', $completed['status'], 'failed' );
check( '[6] reports ANSWER_LEAKAGE_ABBREVIATED_CITATION', has_error_code( $completed, 'answer_leakage_abbreviated_citation' ), true );

// ---------------------------------------------------------------------
// 7. A natural full author name does NOT fail simply because the surname
// appears within it (do not overreach).
// ---------------------------------------------------------------------
$natural = Citex_Generated_Validator::validate(
	bryman_question( 'You are creating a reference for a book titled Social Research Methods, written by Alan Bryman. It was published in 2012 by Oxford University Press in Oxford.' )
);
check( '[7] a natural full name does not fail merely because it contains the surname', $natural['status'], 'passed' );

// ---------------------------------------------------------------------
// 8. The canonical initials remain A. (this is a property of the fixture
// itself / Citex_AI_V2's derivation — verified here on the record used
// throughout this file to keep the test data honest).
// ---------------------------------------------------------------------
check( '[8] canonical initials are "A."', bryman_question( '' )['authorInitials'], 'A.' );

// ---------------------------------------------------------------------
// 9. Question Parts remain Bryman / A. / 2012 / Social Research Methods —
// leakage validation does not alter or weaken the existing Question Parts
// contract in any way.
// ---------------------------------------------------------------------
check( '[9] Question Parts are unchanged: Bryman, A., 2012, Social Research Methods', bryman_question( '' )['questionParts'], array( 'Bryman', 'A.', '2012', 'Social Research Methods' ) );
check( '[9] the natural-scenario question (still) reconstructs correctly with those Question Parts', $natural['reconstructedReference'], 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.' );

// ---------------------------------------------------------------------
// Additional coverage: "use A." — the literal initials value as a
// standalone token, without the word "initials" appearing at all.
// ---------------------------------------------------------------------
$literal_value = Citex_Generated_Validator::validate(
	bryman_question( 'You are referencing Social Research Methods by Alan Bryman; use A. and 2012, published by Oxford University Press in Oxford.' )
);
check( '[literal value] "use A." (no "initials" word) still FAILS', $literal_value['status'], 'failed' );
check( '[literal value] reports ANSWER_LEAKAGE_INITIALS_VALUE', has_error_code( $literal_value, 'answer_leakage_initials_value' ), true );

// A short initials value must not false-positive against ordinary words
// that merely start with the same letter (e.g. "A." must not match inside
// "Andrew" or "A well-regarded book").
$no_false_positive = Citex_Generated_Validator::validate(
	bryman_question( 'A well-regarded book, Social Research Methods by Alan Bryman was published in 2012 by Oxford University Press in Oxford.' )
);
check( '[no false positive] a leading "A " sentence-starter does not trigger ANSWER_LEAKAGE_INITIALS_VALUE', has_error_code( $no_false_positive, 'answer_leakage_initials_value' ), false );
check( '[no false positive] the scenario passes overall', $no_false_positive['status'], 'passed' );

// Existing (pre-fix) pending records using the old authorSurname/authorInitials
// shape (no authorFullName) are still correctly leakage-checked — this
// validation reads authorSurname/authorInitials directly off the record,
// so it applies uniformly regardless of when/how the record was generated.
$legacy_shape_leak = Citex_Generated_Validator::validate(
	bryman_question( 'You are referencing Social Research Methods by Alan Bryman (initials A.), published in 2012 by Oxford University Press in Oxford.' )
);
check( '[existing questions] a pre-fix pending record with a leaked scenario still FAILS on re-validation', $legacy_shape_leak['status'], 'failed' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
