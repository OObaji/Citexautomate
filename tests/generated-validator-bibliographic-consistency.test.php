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
	$base = array(
		'source'                 => 'Harvard',
		'group'                  => 'ReferenceList',
		'category'               => 'Book',
		'type'                   => 'DragDrop',
		'authorSurname'          => 'Cottrell',
		'authorInitials'         => 'S.',
		'year'                   => '2019',
		'bookTitle'              => 'Critical Thinking Skills',
		'place'                  => 'London',
		'publisher'              => 'Red Globe Press',
		'scenario'               => 'You are referencing a book titled Critical Thinking Skills by Stella Cottrell, published in London by Red Globe Press in 2019.',
		'fixedText'              => '|, || (||) ||. London: Red Globe Press.',
		'questionParts'          => array( 'Cottrell', 'S.', '2019', 'Critical Thinking Skills' ),
		'confusingWords'         => array( '2016', 'Manchester', 'Brown' ),
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
		'questionParts'          => array( 'Cottrell', 'M.', '2016', 'Skills for Success' ),
		'reconstructedReference' => 'Cottrell, M. (2016) Skills for Success. London: Red Globe Press.',
	)
);
$result = Citex_Generated_Validator::validate( $bug_repro );
check( '[bug repro] mismatched Question Parts vs. scenario must FAIL', $result['status'], 'failed' );
check( '[bug repro] reports BIBLIOGRAPHIC_CONSISTENCY_PARTS_MISMATCH', has_error_code( $result, 'bibliographic_consistency_parts_mismatch' ), true );
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
			'questionParts'          => array( 'Smith', 'J.', '2019', 'Critical Thinking Skills' ),
			'reconstructedReference' => 'Smith, J. (2019) Critical Thinking Skills. London: Red Globe Press.',
		)
	)
);
check( '[question parts wrong] status is failed', $wrong_parts['status'], 'failed' );
check( '[question parts wrong] reports BIBLIOGRAPHIC_CONSISTENCY_PARTS_MISMATCH', has_error_code( $wrong_parts, 'bibliographic_consistency_parts_mismatch' ), true );
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
		'fixedText'              => '|, || (||) ||. London: Example Publisher.',
		'questionParts'          => array( 'Smith', 'J.', '2020', 'Example Book' ),
		'confusingWords'         => array( '2018', 'Manchester', 'Brown' ),
		'reconstructedReference' => 'Smith, J. (2020) Example Book. London: Example Publisher.',
	)
);
check( '[no canonical record] a record without authorSurname/bookTitle is unaffected by the new check', $no_canonical['status'], 'passed' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
