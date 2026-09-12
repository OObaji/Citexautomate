<?php
/**
 * Regression tests for the BIBLIOGRAPHIC_CONSISTENCY validation layer in
 * Citex_Generated_Validator, added after discovering that a generated Book /
 * DragDrop question's scenario could describe one real book while its
 * Question Parts/Fixed Text were built from a different one — both
 * internally self-consistent with each other, so the pre-existing checks
 * (placeholder reconstruction, Harvard punctuation, Book format, distractor
 * separation) never caught it.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-bibliographic-consistency.test.php` — not
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

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-dragdrop-parts.php';
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
 * The canonical Cottrell record from the bug report:
 * Cottrell, S. (2019) Critical Thinking Skills. London: Red Globe Press.
 */
function canonical_question( $overrides = array() ) {
	$authors = array( array( 'surname' => 'Cottrell', 'initials' => 'S.', 'fullName' => 'Stella Cottrell' ) );
	$fields  = array( 'year' => '2019', 'title' => 'Critical Thinking Skills', 'place' => 'London', 'publisher' => 'Red Globe Press' );
	// Explicit keys (surname, initials, year), not select_parts()'s own
	// seeded random pick — this fixture needs a fixed, predictable 3-part
	// shape so the below sections' Question-Parts overrides stay meaningful
	// (a mismatched surname/year) regardless of which fields the real
	// per-question seeding would otherwise have drawn. Title is never
	// drawable at all (see Citex_Book_Dragdrop_Parts's own docblock).
	$keys  = array( 'author_0_surname', 'author_0_initials', 'year' );
	$built = Citex_Book_Dragdrop_Parts::build( $keys, $authors, $fields );
	$base = array(
		'source'                 => 'Harvard',
		'group'                  => 'ReferenceList',
		'category'               => 'Book',
		'type'                   => 'DragDrop',
		'authorSurname'          => 'Cottrell',
		'authorInitials'         => 'S.',
		'authorFullName'         => 'Stella Cottrell',
		'year'                   => '2019',
		'bookTitle'              => 'Critical Thinking Skills',
		'place'                  => 'London',
		'publisher'              => 'Red Globe Press',
		'scenario'               => 'You are referencing a book titled Critical Thinking Skills by Stella Cottrell, published in London by Red Globe Press in 2019.',
		'dragdropPartKeys'       => $keys,
		'fixedText'              => $built['fixedText'],
		'questionParts'          => $built['parts'],
		'confusingWords'         => $built['confusingWords'],
		'reconstructedReference' => 'Cottrell, S. (2019) Critical Thinking Skills. London: Red Globe Press.',
	);
	return array_merge( $base, $overrides );
}

// ---------------------------------------------------------------------
// 1. The exact reported bug: scenario describes the real Cottrell book,
// but Question Parts (and the reference reconstructed from them) belong
// to a different, unrelated record. Structurally this reconstructs to a
// perfectly valid Book reference — only BIBLIOGRAPHIC_CONSISTENCY catches it.
// ---------------------------------------------------------------------
$bug_repro = canonical_question(
	array(
		'questionParts'          => array( 'Cottrell', 'M.', '2016' ),
		'reconstructedReference' => 'Cottrell, M. (2016) Critical Thinking Skills. London: Red Globe Press.',
	)
);
$result = Citex_Generated_Validator::validate( $bug_repro );
check( '[bug repro] mismatched Question Parts vs. scenario must FAIL', $result['status'], 'failed' );
check( '[bug repro] reports BOOK_DRAGDROP_PARTS_MISMATCH', has_error_code( $result, 'book_dragdrop_parts_mismatch' ), true );
check( '[bug repro] reports BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH (initials/year/title absent from reference)', has_error_code( $result, 'bibliographic_consistency_reference_mismatch' ), true );

// ---------------------------------------------------------------------
// 2. Correct scenario + correct Question Parts → PASS.
// ---------------------------------------------------------------------
$good = Citex_Generated_Validator::validate( canonical_question() );
check( '[consistent record] a fully consistent question PASSES', $good['status'], 'passed' );
check( '[consistent record] no errors reported', $good['errors'], array() );
check( '[consistent record] reconstructed reference matches the canonical reference', $good['reconstructedReference'], 'Cottrell, S. (2019) Critical Thinking Skills. London: Red Globe Press.' );

// ---------------------------------------------------------------------
// 3. Correct Question Parts + wrong scenario YEAR → FAIL.
// ---------------------------------------------------------------------
$wrong_year_scenario = Citex_Generated_Validator::validate(
	canonical_question( array( 'scenario' => 'You are referencing a book titled Critical Thinking Skills by Stella Cottrell, published in London by Red Globe Press in 2016.' ) )
);
check( '[scenario year wrong] status is failed', $wrong_year_scenario['status'], 'failed' );
check( '[scenario year wrong] reports BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH', has_error_code( $wrong_year_scenario, 'bibliographic_consistency_scenario_mismatch' ), true );

// ---------------------------------------------------------------------
// 4. Correct Question Parts + wrong scenario TITLE → FAIL.
// ---------------------------------------------------------------------
$wrong_title_scenario = Citex_Generated_Validator::validate(
	canonical_question( array( 'scenario' => 'You are referencing a book titled Skills for Success by Stella Cottrell, published in London by Red Globe Press in 2019.' ) )
);
check( '[scenario title wrong] status is failed', $wrong_title_scenario['status'], 'failed' );
check( '[scenario title wrong] reports BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH', has_error_code( $wrong_title_scenario, 'bibliographic_consistency_scenario_mismatch' ), true );

// ---------------------------------------------------------------------
// 5. Correct Question Parts + wrong scenario PUBLISHER → FAIL.
// ---------------------------------------------------------------------
$wrong_publisher_scenario = Citex_Generated_Validator::validate(
	canonical_question( array( 'scenario' => 'You are referencing a book titled Critical Thinking Skills by Stella Cottrell, published in London by Oxford University Press in 2019.' ) )
);
check( '[scenario publisher wrong] status is failed', $wrong_publisher_scenario['status'], 'failed' );
check( '[scenario publisher wrong] reports BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH', has_error_code( $wrong_publisher_scenario, 'bibliographic_consistency_scenario_mismatch' ), true );

// ---------------------------------------------------------------------
// 6. Correct Question Parts + wrong scenario PLACE → FAIL.
// ---------------------------------------------------------------------
$wrong_place_scenario = Citex_Generated_Validator::validate(
	canonical_question( array( 'scenario' => 'You are referencing a book titled Critical Thinking Skills by Stella Cottrell, published in Oxford by Red Globe Press in 2019.' ) )
);
check( '[scenario place wrong] status is failed', $wrong_place_scenario['status'], 'failed' );
check( '[scenario place wrong] reports BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH', has_error_code( $wrong_place_scenario, 'bibliographic_consistency_scenario_mismatch' ), true );

// ---------------------------------------------------------------------
// 7. Wrong Question Parts + correct scenario → FAIL.
// ---------------------------------------------------------------------
$wrong_parts = Citex_Generated_Validator::validate(
	canonical_question(
		array(
			'questionParts'          => array( 'Smith', 'J.', '2019' ),
			'reconstructedReference' => 'Smith, J. (2019) Critical Thinking Skills. London: Red Globe Press.',
		)
	)
);
check( '[question parts wrong] status is failed', $wrong_parts['status'], 'failed' );
check( '[question parts wrong] reports BOOK_DRAGDROP_PARTS_MISMATCH', has_error_code( $wrong_parts, 'book_dragdrop_parts_mismatch' ), true );
check( '[question parts wrong] reports BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH (wrong surname in reference)', has_error_code( $wrong_parts, 'bibliographic_consistency_reference_mismatch' ), true );

// ---------------------------------------------------------------------
// 8. Wrong reconstructed reference → FAIL (pre-existing check, still intact).
// ---------------------------------------------------------------------
$wrong_reference = Citex_Generated_Validator::validate(
	canonical_question( array( 'reconstructedReference' => 'Cottrell, S. (2019) A Totally Different Title. London: Red Globe Press.' ) )
);
check( '[reconstructed reference wrong] status is failed', $wrong_reference['status'], 'failed' );
check( '[reconstructed reference wrong] reports RECONSTRUCTED_REFERENCE_MISMATCH', has_error_code( $wrong_reference, 'reconstructed_reference_mismatch' ), true );

// ---------------------------------------------------------------------
// 9. Correct canonical record + bad distractor → FAIL (pre-existing check,
// still intact — a distractor duplicating a correct Question Part).
// ---------------------------------------------------------------------
$bad_distractor = Citex_Generated_Validator::validate(
	canonical_question( array( 'confusingWords' => array( '2019', 'Manchester', 'Brown' ) ) )
);
check( '[bad distractor] status is failed', $bad_distractor['status'], 'failed' );
check( '[bad distractor] reports DISTRACTOR_MATCHES_CORRECT_PART', has_error_code( $bad_distractor, 'distractor_matches_correct_part' ), true );
check( '[bad distractor] bibliographic consistency itself still reports no error (isolates the failure to the distractor)', has_error_code( $bad_distractor, 'bibliographic_consistency_parts_mismatch' ), false );

// ---------------------------------------------------------------------
// 10. Records with no canonical fields at all (e.g. externally imported,
// pre-dating this feature) are unaffected — BIBLIOGRAPHIC_CONSISTENCY must
// not retroactively fail data that never carried a canonical record.
// ---------------------------------------------------------------------
$no_canonical = Citex_Generated_Validator::validate(
	array(
		'source'                 => 'Harvard',
		'group'                  => 'ReferenceList',
		'category'               => 'Book',
		'type'                   => 'DragDrop',
		'fixedText'              => '|, || (||) Example Book. London: Example Publisher.',
		'questionParts'          => array( 'Smith', 'J.', '2020' ),
		'confusingWords'         => array( '2018', 'Manchester', 'Brown' ),
		'reconstructedReference' => 'Smith, J. (2020) Example Book. London: Example Publisher.',
	)
);
check( '[no canonical record] a record without authorSurname/bookTitle is unaffected by the new check', $no_canonical['status'], 'passed' );

// ---------------------------------------------------------------------
// 11. Regression for the real reported bug this check was built for,
// re-targeted at the current mechanism: a Book DragDrop candidate NOT
// using a single fixed baseline shape must still validate correctly,
// judged against its OWN recorded `dragdropPartKeys` selection (see
// Citex_Book_Dragdrop_Parts) rather than one hardcoded shape.
// ---------------------------------------------------------------------
$three_authors = array(
	array( 'surname' => 'Bennett', 'initials' => 'L.', 'fullName' => 'Lucas Bennett' ),
	array( 'surname' => 'Harper', 'initials' => 'C.', 'fullName' => 'Chloe Harper' ),
	array( 'surname' => 'Foster', 'initials' => 'F.', 'fullName' => 'Felix Foster' ),
);
$urban_fields = array( 'year' => '2021', 'title' => 'Urban Design', 'place' => 'London', 'publisher' => 'Routledge' );
$urban_keys   = Citex_Book_Dragdrop_Parts::select_parts( 'BK-URBAN', $three_authors );
$urban_built  = Citex_Book_Dragdrop_Parts::build( $urban_keys, $three_authors, $urban_fields );
$variety_design_question = array(
	'source'                 => 'Harvard',
	'group'                  => 'ReferenceList',
	'category'               => 'Book',
	'type'                   => 'DragDrop',
	'scenario'               => 'You are referencing a book titled Urban Design by Lucas Bennett, Chloe Harper and Felix Foster, published in 2021 by Routledge in London.',
	'authors'                => $three_authors,
	'year'                   => '2021',
	'bookTitle'              => 'Urban Design',
	'place'                  => 'London',
	'publisher'              => 'Routledge',
	'dragdropPartKeys'       => $urban_keys,
	'fixedText'              => $urban_built['fixedText'],
	'questionParts'          => $urban_built['parts'],
	'confusingWords'         => $urban_built['confusingWords'],
	'reconstructedReference' => 'Bennett, L., Harper, C. and Foster, F. (2021) Urban Design. London: Routledge.',
);
$variety_result = Citex_Generated_Validator::validate( $variety_design_question );
check( '[part-selection] a correctly-built dynamic-selection question PASSES (not judged against a single fixed baseline shape)', $variety_result['status'], 'passed' );
check( '[part-selection] no BOOK_DRAGDROP_PARTS_MISMATCH', has_error_code( $variety_result, 'book_dragdrop_parts_mismatch' ), false );

// The SAME Question Parts, but with no dragdropPartKeys field at all (an
// older record predating this feature) — this correctly fails, since
// there is nothing to recompute the expected parts/fixedText from.
$missing_keys_question = $variety_design_question;
unset( $missing_keys_question['dragdropPartKeys'] );
$missing_keys_result = Citex_Generated_Validator::validate( $missing_keys_question );
check( '[part-selection] the identical Question Parts WITHOUT dragdropPartKeys correctly FAILS', $missing_keys_result['status'], 'failed' );
check( '[part-selection] reports BOOK_DRAGDROP_PARTS_UNKNOWN when no selection is recorded', has_error_code( $missing_keys_result, 'book_dragdrop_parts_unknown' ), true );

// A DIFFERENT valid selection (fewer parts) for the same record also
// passes — proving the check adapts to whichever selection was actually
// recorded, not a single hardcoded shape.
$alt_keys     = array( 'year', 'place', 'publisher' );
$alt_built    = Citex_Book_Dragdrop_Parts::build( $alt_keys, $three_authors, $urban_fields );
$alt_question = $variety_design_question;
$alt_question['dragdropPartKeys'] = $alt_keys;
$alt_question['fixedText']        = $alt_built['fixedText'];
$alt_question['questionParts']    = $alt_built['parts'];
$alt_question['confusingWords']   = $alt_built['confusingWords'];
check( '[part-selection] a different, smaller valid selection for the same record also PASSES', Citex_Generated_Validator::validate( $alt_question )['status'], 'passed' );

// Question Parts tampered to no longer match the recorded selection
// correctly FAILS.
$tampered_question = $variety_design_question;
$tampered_question['questionParts'][0] = 'Wrong';
$tampered_result = Citex_Generated_Validator::validate( $tampered_question );
check( '[part-selection] tampered Question Parts not matching the recorded selection correctly FAILS', $tampered_result['status'], 'failed' );
check( '[part-selection] reports BOOK_DRAGDROP_PARTS_MISMATCH for tampered parts', has_error_code( $tampered_result, 'book_dragdrop_parts_mismatch' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
