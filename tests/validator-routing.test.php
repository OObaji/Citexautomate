<?php
/**
 * PHP-side routing test for Citex_Validator::resolve_validator_id().
 * Repo-level only, run with plain `php tests/validator-routing.test.php`
 * — not shipped in citex-tools.zip. Needs no WordPress stubs: routing is
 * pure PHP with no WP function calls.
 *
 * Covers the debugging report (live site, 200 indexed questions): BK01
 * has exactly {source: Harvard, group: ReferenceList, category: Book,
 * type: DragDrop} and was showing as Unsupported instead of routing.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/validators/class-citex-harvard-book-dragdrop-validator.php';
require __DIR__ . '/../citex-tools/includes/class-citex-validator.php';

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

// The literal reported case.
$bk01 = array( 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Book', 'type' => 'DragDrop', 'questionId' => 'BK01' );
check(
	'BK01 {Harvard, ReferenceList, Book, DragDrop} routes to harvard-reference-list-book-dragdrop',
	Citex_Validator::resolve_validator_id( $bk01 ),
	'harvard-reference-list-book-dragdrop'
);

// Whitespace/casing noise a scraped admin-list page could plausibly
// introduce (debug checklist item #2: does the router normalize trim/casing).
$messy = array( 'source' => ' harvard', 'group' => 'ReferenceList ', 'category' => 'book', 'type' => "DragDrop\xc2\xa0", 'questionId' => 'BK01' );
check(
	'routing tolerates whitespace/casing/NBSP noise around the same values',
	Citex_Validator::resolve_validator_id( $messy ),
	'harvard-reference-list-book-dragdrop'
);

// A genuinely different combination must still be unsupported (never mark
// everything as routed just because normalization got more lenient).
$unsupported = array( 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Website', 'type' => 'MCQ', 'questionId' => 'WB01' );
check(
	'a genuinely unsupported combination still routes to null',
	Citex_Validator::resolve_validator_id( $unsupported ),
	null
);

// The validator class itself must exist and be a real (if not yet
// implemented) routing target — not silently missing.
check( 'Citex_Harvard_Book_Dragdrop_Validator class exists', class_exists( 'Citex_Harvard_Book_Dragdrop_Validator' ), true );
check( 'its ID matches what routing returns', Citex_Harvard_Book_Dragdrop_Validator::ID, 'harvard-reference-list-book-dragdrop' );

// The full diagnostic breakdown (what the Validation page's Details panel
// now shows live) for the exact reported BK01 metadata.
$diagnosis = Citex_Validator::diagnose_routing( $bk01 );
check( 'diagnosis: source field matches', $diagnosis['fields']['source']['match'], true );
check( 'diagnosis: group field matches', $diagnosis['fields']['group']['match'], true );
check( 'diagnosis: category field matches', $diagnosis['fields']['category']['match'], true );
check( 'diagnosis: type field matches', $diagnosis['fields']['type']['match'], true );
check( 'diagnosis: questionId carried through', $diagnosis['questionId'], 'BK01' );
check( 'diagnosis: expectedValidatorKey', $diagnosis['expectedValidatorKey'], 'harvard-reference-list-book-dragdrop' );
check( 'diagnosis: selectedValidatorKey', $diagnosis['selectedValidatorKey'], 'harvard-reference-list-book-dragdrop' );
check( 'diagnosis: routingResult', $diagnosis['routingResult'], 'routed' );
check( 'diagnosis: validatorExists', $diagnosis['validatorExists'], true );
check( 'diagnosis: validatorImplemented (honestly false — rule engine still pending)', $diagnosis['validatorImplemented'], false );

// diagnose_routing() and resolve_validator_id() must never diverge — the
// latter is a thin wrapper around the former by construction, but assert
// it directly so a future refactor that breaks that can't pass silently.
check(
	'resolve_validator_id() and diagnose_routing()[selectedValidatorKey] agree for BK01',
	Citex_Validator::resolve_validator_id( $bk01 ),
	$diagnosis['selectedValidatorKey']
);

// The diagnosis for a genuinely unsupported record must name which
// field(s) caused it, not just say "unsupported".
$wb01_diagnosis = Citex_Validator::diagnose_routing( $unsupported );
check( 'diagnosis: unsupported record routingResult', $wb01_diagnosis['routingResult'], 'unsupported' );
check( 'diagnosis: unsupported record selectedValidatorKey is null', $wb01_diagnosis['selectedValidatorKey'], null );
if ( false === strpos( $wb01_diagnosis['reason'], 'category' ) || false === strpos( $wb01_diagnosis['reason'], 'type' ) ) {
	echo "FAIL: unsupported reason should name the mismatched fields (category, type); got: " . $wb01_diagnosis['reason'] . "\n";
	$failures++;
} else {
	echo "PASS: unsupported reason names the mismatched fields: \"" . $wb01_diagnosis['reason'] . "\"\n";
}

if ( $failures > 0 ) {
	echo "\n$failures test(s) FAILED.\n";
	exit( 1 );
}

echo "\nAll validator-routing tests passed.\n";
