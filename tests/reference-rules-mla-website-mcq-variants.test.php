<?php
/**
 * Regression tests for Citex_MLA_Website_Mcq_Variants — MLA Website's own
 * curated 7-variant MCQ catalogue, mirroring
 * tests/reference-rules-mla-book-mcq-variants.test.php's own structure,
 * but for this category's own axes of variation: dated vs undated (never
 * Harvard's own "n.d." convention) and individual vs organisation author
 * (no multi-author concept at all). Pure, no WordPress/ACF dependency.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-mla-website-mcq-variants.test.php` — not
 * shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-website-mcq-variants.php';

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

$individual   = array( 'type' => 'individual', 'surname' => 'Ross', 'givenName' => 'Amy', 'fullName' => 'Amy Ross' );
$organisation = array( 'type' => 'organisation', 'name' => 'WHO' );

// ---------------------------------------------------------------------
// 1. variants(): exactly the curated 7.
// ---------------------------------------------------------------------
check( '[1] exactly 7 curated variants', count( Citex_MLA_Website_Mcq_Variants::variants() ), 7 );

// ---------------------------------------------------------------------
// 2. variant_requires_individual(): only "author_name_format" requires a
// named individual; every other variant works for either author type.
// ---------------------------------------------------------------------
check( '[2] author_name_format requires an individual author', Citex_MLA_Website_Mcq_Variants::variant_requires_individual( 'author_name_format' ), true );
check( '[2] complete_reference does not require an individual author', Citex_MLA_Website_Mcq_Variants::variant_requires_individual( 'complete_reference' ), false );

// ---------------------------------------------------------------------
// 3. variant_for(): across a wide seed sweep, every eligible variant is
// reached at least once, for both individual and organisation authors;
// build() returns null for author_name_format when given an organisation.
// ---------------------------------------------------------------------
foreach ( array( 'individual' => true, 'organisation' => false ) as $label => $is_individual ) {
	$seen = array_fill_keys( Citex_MLA_Website_Mcq_Variants::variants(), false );
	for ( $i = 1; $i <= 300; $i++ ) {
		$seen[ Citex_MLA_Website_Mcq_Variants::variant_for( "S$i", $is_individual ) ] = true;
	}
	$missing = array();
	foreach ( $seen as $variant => $hit ) {
		$eligible = $is_individual || ! Citex_MLA_Website_Mcq_Variants::variant_requires_individual( $variant );
		if ( $eligible && ! $hit ) {
			$missing[] = $variant;
		}
	}
	check( "[3] $label: every eligible variant is reached across 300 seeds", $missing, array() );
	if ( ! $is_individual ) {
		check( '[3] organisation: author_name_format is never assigned across 300 seeds', $seen['author_name_format'], false );
	}
}

$org_fields = array( 'author' => $organisation, 'title' => 'Home', 'year' => '2020', 'url' => 'https://who.example.com', 'accessedDate' => '3 May 2023' );
check( '[3] build() returns null for author_name_format given an organisation author', Citex_MLA_Website_Mcq_Variants::build( 'author_name_format', $org_fields ), null );

// ---------------------------------------------------------------------
// 4. build(): exact output for a handful of hand-picked variants.
// ---------------------------------------------------------------------
$dated_ind_fields = array( 'author' => $individual, 'title' => 'Home Page', 'year' => '2020', 'url' => 'https://example.com', 'accessedDate' => '3 May 2023' );
$built_name_format = Citex_MLA_Website_Mcq_Variants::build( 'author_name_format', $dated_ind_fields );
check( '[4] author_name_format correctAnswer', $built_name_format['correctAnswer'], 'Ross, Amy' );
check( '[4] author_name_format wrong options include the un-inverted full name', in_array( 'Amy Ross', $built_name_format['wrongOptions'], true ), true );

$built_title = Citex_MLA_Website_Mcq_Variants::build( 'title_quotation', $dated_ind_fields );
check( '[4] title_quotation correctAnswer uses double quotes with the period INSIDE', $built_title['correctAnswer'], '"Home Page."' );

$built_undated = Citex_MLA_Website_Mcq_Variants::build( 'undated_source', $dated_ind_fields );
check( '[4] undated_source correctAnswer never contains "n.d."', false !== strpos( $built_undated['correctAnswer'], 'n.d.' ), false );
foreach ( $built_undated['wrongOptions'] as $wrong ) {
	if ( false === strpos( $wrong, 'n.d.' ) ) {
		check( '[4] undated_source: at least one wrong option shows the (wrong) "n.d." convention', true, true );
	}
}

$built_accessed = Citex_MLA_Website_Mcq_Variants::build( 'accessed_date_placement', $dated_ind_fields );
check( '[4] accessed_date_placement correctAnswer', $built_accessed['correctAnswer'], 'Accessed 3 May 2023.' );
check( '[4] accessed_date_placement wrong options never bare-case-collide with the correct answer', in_array( 'accessed 3 May 2023.', $built_accessed['wrongOptions'], true ), false );

check( '[4] an unrecognised variant returns null', Citex_MLA_Website_Mcq_Variants::build( 'not_a_real_variant', $dated_ind_fields ), null );

// ---------------------------------------------------------------------
// 5. Every variant, for both dated and undated, individual and
// organisation (when eligible): exactly 3 wrong options, no duplicates
// among them, and none case-insensitively equal to the correct answer.
// ---------------------------------------------------------------------
$undated_ind_fields = array( 'author' => $individual, 'title' => 'Home Page', 'year' => '', 'url' => 'https://example.com', 'accessedDate' => '3 May 2023' );
$dated_org_fields   = array( 'author' => $organisation, 'title' => 'Facts', 'year' => '2019', 'url' => 'https://who.example.com', 'accessedDate' => '14 Jan 2024' );
$undated_org_fields = array( 'author' => $organisation, 'title' => 'Facts', 'year' => '', 'url' => 'https://who.example.com', 'accessedDate' => '14 Jan 2024' );
$cases = array(
	'dated_individual'   => array( true, $dated_ind_fields ),
	'undated_individual' => array( true, $undated_ind_fields ),
	'dated_organisation'   => array( false, $dated_org_fields ),
	'undated_organisation' => array( false, $undated_org_fields ),
);
foreach ( $cases as $label => $case ) {
	list( $is_individual, $fields ) = $case;
	foreach ( Citex_MLA_Website_Mcq_Variants::variants() as $variant ) {
		if ( Citex_MLA_Website_Mcq_Variants::variant_requires_individual( $variant ) && ! $is_individual ) {
			continue;
		}
		$built = Citex_MLA_Website_Mcq_Variants::build( $variant, $fields );
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
