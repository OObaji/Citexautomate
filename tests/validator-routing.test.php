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

if ( $failures > 0 ) {
	echo "\n$failures test(s) FAILED.\n";
	exit( 1 );
}

echo "\nAll validator-routing tests passed.\n";
