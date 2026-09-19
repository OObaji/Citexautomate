<?php
/**
 * Regression tests for Citex_MHRA_Edited_Book_Mcq_Variants /
 * Citex_MHRA_Journal_Article_Mcq_Variants / Citex_MHRA_Website_Mcq_Variants
 * — proves every variant, at every eligible editor/author count and both
 * Website author types, produces a stem, 3 distinct wrong options, and a
 * correct answer with no duplicate (case-insensitive) among all 4.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-mhra-phase2-mcq-variants.test.php` — not
 * shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-edited-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-journal-article-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-website-mcq-variants.php';

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

function check_variant( $label, $result ) {
	global $failures;
	if ( null === $result ) {
		echo "FAIL: $label (null result)\n";
		$failures++;
		return;
	}
	$all       = array_merge( array( $result['correctAnswer'] ), $result['wrongOptions'] );
	$lower     = array_map( function ( $s ) { return strtolower( trim( (string) $s ) ); }, $all );
	$unique_ok = count( array_unique( $lower ) ) === count( $lower );
	$count_ok  = 3 === count( $result['wrongOptions'] );
	check( "$label: 3 distinct wrong options, no duplicate of the answer", $unique_ok && $count_ok, true );
}

function person( $sn, $gn, $full ) {
	return array( 'surname' => $sn, 'givenName' => $gn, 'fullName' => $full );
}

// ---------------------------------------------------------------------
// Edited Book.
// ---------------------------------------------------------------------
$eb_sets = array(
	1 => array( person( 'Ross', 'Amy', 'Amy Ross' ) ),
	2 => array( person( 'Ross', 'Amy', 'Amy Ross' ), person( 'Carter', 'Ben', 'Ben Carter' ) ),
	3 => array( person( 'Ross', 'Amy', 'Amy Ross' ), person( 'Carter', 'Ben', 'Ben Carter' ), person( 'Lee', 'Kim', 'Kim Lee' ) ),
);
foreach ( $eb_sets as $n => $editors ) {
	$fields = array( 'editors' => $editors, 'title' => 'Urban Planning Today', 'place' => 'London', 'publisher' => 'Routledge', 'year' => '2019' );
	foreach ( Citex_MHRA_Edited_Book_Mcq_Variants::variants() as $variant ) {
		$bounds = Citex_MHRA_Edited_Book_Mcq_Variants::variant_editor_requirement( $variant );
		if ( null !== $bounds && ( $n < $bounds[0] || $n > $bounds[1] ) ) {
			continue;
		}
		check_variant( "EditedBook n=$n $variant", Citex_MHRA_Edited_Book_Mcq_Variants::build( $variant, $fields ) );
	}
}
check( 'mhra_edited_book_independent_answer_variants() names designation_singular_plural', Citex_MHRA_Edited_Book_Mcq_Variants::mhra_edited_book_independent_answer_variants(), array( 'designation_singular_plural' ) );

// ---------------------------------------------------------------------
// Journal Article.
// ---------------------------------------------------------------------
$ja_sets = array(
	1 => array( person( 'Smith', 'John', 'John Smith' ) ),
	2 => array( person( 'Smith', 'John', 'John Smith' ), person( 'Ross', 'Amy', 'Amy Ross' ) ),
	3 => array( person( 'Smith', 'John', 'John Smith' ), person( 'Ross', 'Amy', 'Amy Ross' ), person( 'Lee', 'Kim', 'Kim Lee' ) ),
);
foreach ( $ja_sets as $n => $authors ) {
	$fields = array( 'authors' => $authors, 'year' => '2020', 'articleTitle' => 'Climate Change and Policy', 'journalTitle' => 'Journal of Environmental Studies', 'volume' => '12', 'issue' => '3', 'pages' => '45-60' );
	foreach ( Citex_MHRA_Journal_Article_Mcq_Variants::variants() as $variant ) {
		$bounds = Citex_MHRA_Journal_Article_Mcq_Variants::variant_author_requirement( $variant );
		if ( null !== $bounds && ( $n < $bounds[0] || $n > $bounds[1] ) ) {
			continue;
		}
		check_variant( "JournalArticle n=$n $variant", Citex_MHRA_Journal_Article_Mcq_Variants::build( $variant, $fields ) );
	}
}
check( 'mhra_journal_article_independent_answer_variants() names reference_structure', Citex_MHRA_Journal_Article_Mcq_Variants::mhra_journal_article_independent_answer_variants(), array( 'reference_structure' ) );

// ---------------------------------------------------------------------
// Website — individual + organisation. No `year` field at all — only
// `accessedDate` — see Citex_MHRA_Reference_Rules's own docblock.
// ---------------------------------------------------------------------
$web_sets = array(
	'individual'   => array( 'author' => array( 'type' => 'individual', 'surname' => 'Smith', 'givenName' => 'John', 'fullName' => 'John Smith' ), 'title' => 'Understanding Climate Policy', 'url' => 'https://example.com', 'accessedDate' => '5 May 2023' ),
	'organisation' => array( 'author' => array( 'type' => 'organisation', 'name' => 'World Health Organisation' ), 'title' => 'Global Health Statistics', 'url' => 'https://who.int', 'accessedDate' => '14 June 2022' ),
);
foreach ( $web_sets as $label => $fields ) {
	foreach ( Citex_MHRA_Website_Mcq_Variants::variants() as $variant ) {
		check_variant( "Website $label $variant", Citex_MHRA_Website_Mcq_Variants::build( $variant, $fields ) );
	}
}
check( 'mhra_website_independent_answer_variants() names reference_structure', Citex_MHRA_Website_Mcq_Variants::mhra_website_independent_answer_variants(), array( 'reference_structure' ) );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
