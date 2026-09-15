<?php
/**
 * Regression tests for Citex_MLA_Book_Mcq_Variants — MLA Book's own curated
 * 8-variant MCQ catalogue, mirroring tests/reference-rules-book-mcq-variants
 * -style coverage (variant_for() coverage, author-count gating, exact-match
 * output for a few hand-picked variants, and the "never case-insensitively
 * duplicate the correct answer" invariant swept across every variant and
 * author count). Pure, no WordPress/ACF dependency.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-mla-book-mcq-variants.test.php` — not shipped
 * in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-book-mcq-variants.php';

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

function normal_text( $value ) {
	return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $value ) ) );
}

$one   = array( array( 'surname' => 'Smith', 'givenName' => 'John', 'fullName' => 'John Smith' ) );
$two   = array( array( 'surname' => 'Ross', 'givenName' => 'Amy', 'fullName' => 'Amy Ross' ), array( 'surname' => 'Carter', 'givenName' => 'Ben', 'fullName' => 'Ben Carter' ) );
$three = array(
	array( 'surname' => 'Ross', 'givenName' => 'Amy', 'fullName' => 'Amy Ross' ),
	array( 'surname' => 'Carter', 'givenName' => 'Ben', 'fullName' => 'Ben Carter' ),
	array( 'surname' => 'Lee', 'givenName' => 'Kim', 'fullName' => 'Kim Lee' ),
);

// ---------------------------------------------------------------------
// 1. variants(): exactly the curated 8, and independent-answer-variant
// registration is exactly "reference_structure" (skips duplicate
// detection the same way Book/Website's own static variants do).
// ---------------------------------------------------------------------
check( '[1] exactly 8 curated variants', count( Citex_MLA_Book_Mcq_Variants::variants() ), 8 );
check(
	'[1] mla_book_independent_answer_variants() is exactly "reference_structure"',
	Citex_MLA_Book_Mcq_Variants::mla_book_independent_answer_variants(),
	array( 'reference_structure' )
);

// ---------------------------------------------------------------------
// 2. variant_author_requirement(): "two_author_joining" needs exactly 2;
// "et_al_convention" needs 3+; every other variant has no requirement.
// ---------------------------------------------------------------------
check( '[2] two_author_joining requires exactly [2,2]', Citex_MLA_Book_Mcq_Variants::variant_author_requirement( 'two_author_joining' ), array( 2, 2 ) );
check( '[2] et_al_convention requires [3, PHP_INT_MAX]', Citex_MLA_Book_Mcq_Variants::variant_author_requirement( 'et_al_convention' ), array( 3, PHP_INT_MAX ) );
check( '[2] complete_reference has no author-count requirement', Citex_MLA_Book_Mcq_Variants::variant_author_requirement( 'complete_reference' ), null );

// ---------------------------------------------------------------------
// 3. variant_for(): across a wide seed sweep, every author-count-eligible
// variant is reached at least once, for 1/2/3 authors.
// ---------------------------------------------------------------------
foreach ( array( 1, 2, 3 ) as $count ) {
	$seen = array_fill_keys( Citex_MLA_Book_Mcq_Variants::variants(), false );
	for ( $i = 1; $i <= 300; $i++ ) {
		$seen[ Citex_MLA_Book_Mcq_Variants::variant_for( "S$i", $count ) ] = true;
	}
	$missing = array();
	foreach ( $seen as $variant => $hit ) {
		$bounds   = Citex_MLA_Book_Mcq_Variants::variant_author_requirement( $variant );
		$eligible = null === $bounds || ( $count >= $bounds[0] && $count <= $bounds[1] );
		if ( $eligible && ! $hit ) {
			$missing[] = $variant;
		}
	}
	check( "[3] author count $count: every eligible variant is reached across 300 seeds", $missing, array() );
}

// ---------------------------------------------------------------------
// 4. build(): exact output for a handful of hand-picked variants.
// ---------------------------------------------------------------------
$one_fields = array( 'authors' => $one, 'title' => 'The Great Adventure', 'publisher' => 'Penguin', 'year' => '2020' );
$built_name_format = Citex_MLA_Book_Mcq_Variants::build( 'author_name_format', $one_fields );
check( '[4] author_name_format correctAnswer', $built_name_format['correctAnswer'], 'Smith, John' );
check( '[4] author_name_format wrong options include the un-inverted full name', in_array( 'John Smith', $built_name_format['wrongOptions'], true ), true );
check( '[4] author_name_format wrong options include a Harvard-style initial mistake', in_array( 'Smith, J..', $built_name_format['wrongOptions'], true ), true );

$two_fields = array( 'authors' => $two, 'title' => 'Digital Culture', 'publisher' => 'Routledge', 'year' => '2021' );
$built_two_joining = Citex_MLA_Book_Mcq_Variants::build( 'two_author_joining', $two_fields );
check( '[4] two_author_joining correctAnswer keeps the comma before "and" even at exactly 2', $built_two_joining['correctAnswer'], 'Ross, Amy, and Ben Carter.' );

$three_fields = array( 'authors' => $three, 'title' => 'Digital Culture', 'publisher' => 'Routledge', 'year' => '2021' );
$built_et_al = Citex_MLA_Book_Mcq_Variants::build( 'et_al_convention', $three_fields );
check( '[4] et_al_convention correctAnswer', $built_et_al['correctAnswer'], 'Ross, Amy, et al.' );
check(
	'[4] et_al_convention wrong options include the Harvard-style "list everyone" mistake',
	in_array( 'Ross, Amy, Carter, Ben and Lee, Kim.', $built_et_al['wrongOptions'], true ),
	true
);

$built_structure = Citex_MLA_Book_Mcq_Variants::build( 'reference_structure', $one_fields );
check( '[4] reference_structure correctAnswer', $built_structure['correctAnswer'], 'Author → Title → Publisher → Year' );

check( '[4] an unrecognised variant returns null', Citex_MLA_Book_Mcq_Variants::build( 'not_a_real_variant', $one_fields ), null );

// ---------------------------------------------------------------------
// 5. Every variant, for every author-count-eligible case: exactly 3 wrong
// options, no duplicates among them, and none case-insensitively equal
// to the correct answer — swept across 1/2/3-author fixtures.
// ---------------------------------------------------------------------
$cases = array(
	'one'   => array( $one, $one_fields ),
	'two'   => array( $two, $two_fields ),
	'three' => array( $three, $three_fields ),
);
foreach ( $cases as $label => $case ) {
	list( $authors, $fields ) = $case;
	$count = count( $authors );
	foreach ( Citex_MLA_Book_Mcq_Variants::variants() as $variant ) {
		$bounds = Citex_MLA_Book_Mcq_Variants::variant_author_requirement( $variant );
		if ( null !== $bounds && ( $count < $bounds[0] || $count > $bounds[1] ) ) {
			continue;
		}
		$built = Citex_MLA_Book_Mcq_Variants::build( $variant, $fields );
		check( "[5] $label/$variant: exactly 3 wrong options", count( $built['wrongOptions'] ), 3 );
		check( "[5] $label/$variant: no duplicate wrong options", count( array_unique( array_map( 'normal_text', $built['wrongOptions'] ) ) ), 3 );
		$correct_normal = normal_text( $built['correctAnswer'] );
		foreach ( $built['wrongOptions'] as $index => $option ) {
			if ( normal_text( $option ) === $correct_normal ) {
				check( "[5] $label/$variant: wrong option $index is never case-insensitively equal to the correct answer", true, false );
			}
		}
	}
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
