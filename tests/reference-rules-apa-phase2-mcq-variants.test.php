<?php
/**
 * Regression tests for Citex_APA_Edited_Book_Mcq_Variants /
 * Citex_APA_Journal_Article_Mcq_Variants / Citex_APA_Website_Mcq_Variants —
 * proves every variant, at every eligible editor/author count and both
 * Website author types, produces a stem, 3 distinct wrong options, and a
 * correct answer with no duplicate (case-insensitive) among all 4.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-apa-phase2-mcq-variants.test.php` — not
 * shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-edited-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-journal-article-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-website-mcq-variants.php';

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
	$all = array_merge( array( $result['correctAnswer'] ), $result['wrongOptions'] );
	$lower = array_map( function ( $s ) { return strtolower( trim( (string) $s ) ); }, $all );
	$unique_ok = count( array_unique( $lower ) ) === count( $lower );
	$count_ok  = 3 === count( $result['wrongOptions'] );
	check( "$label: 3 distinct wrong options, no duplicate of the answer", $unique_ok && $count_ok, true );
}

function person( $sn, $in, $full ) {
	return array( 'surname' => $sn, 'initials' => $in, 'fullName' => $full );
}

// ---------------------------------------------------------------------
// Edited Book.
// ---------------------------------------------------------------------
$eb_sets = array(
	1 => array( person( 'Ross', 'A.', 'Amy Ross' ) ),
	2 => array( person( 'Ross', 'A.', 'Amy Ross' ), person( 'Carter', 'B.', 'Ben Carter' ) ),
	3 => array( person( 'Ross', 'A.', 'Amy Ross' ), person( 'Carter', 'B.', 'Ben Carter' ), person( 'Lee', 'K.', 'Kim Lee' ) ),
);
foreach ( $eb_sets as $n => $editors ) {
	$fields = array( 'editors' => $editors, 'title' => 'Urban planning today', 'publisher' => 'Routledge', 'year' => '2019' );
	foreach ( Citex_APA_Edited_Book_Mcq_Variants::variants() as $variant ) {
		$bounds = Citex_APA_Edited_Book_Mcq_Variants::variant_editor_requirement( $variant );
		if ( null !== $bounds && ( $n < $bounds[0] || $n > $bounds[1] ) ) {
			continue;
		}
		check_variant( "EditedBook n=$n $variant", Citex_APA_Edited_Book_Mcq_Variants::build( $variant, $fields ) );
	}
}
check( 'apa_edited_book_independent_answer_variants() names designation_singular_plural', Citex_APA_Edited_Book_Mcq_Variants::apa_edited_book_independent_answer_variants(), array( 'designation_singular_plural' ) );

// ---------------------------------------------------------------------
// Journal Article.
// ---------------------------------------------------------------------
$ja_sets = array(
	1 => array( person( 'Smith', 'J.', 'John Smith' ) ),
	2 => array( person( 'Smith', 'J.', 'John Smith' ), person( 'Doe', 'A.', 'Alice Doe' ) ),
	3 => array( person( 'Smith', 'J.', 'John Smith' ), person( 'Doe', 'A.', 'Alice Doe' ), person( 'Lee', 'K.', 'Kim Lee' ) ),
);
foreach ( $ja_sets as $n => $authors ) {
	$fields = array( 'authors' => $authors, 'year' => '2020', 'articleTitle' => 'Climate change and policy', 'journalTitle' => 'Journal of Environmental Studies', 'volume' => '12', 'issue' => '3', 'pages' => '45-60' );
	foreach ( Citex_APA_Journal_Article_Mcq_Variants::variants() as $variant ) {
		$bounds = Citex_APA_Journal_Article_Mcq_Variants::variant_author_requirement( $variant );
		if ( null !== $bounds && ( $n < $bounds[0] || $n > $bounds[1] ) ) {
			continue;
		}
		check_variant( "JournalArticle n=$n $variant", Citex_APA_Journal_Article_Mcq_Variants::build( $variant, $fields ) );
	}
}
check( 'apa_journal_article_independent_answer_variants() names reference_structure', Citex_APA_Journal_Article_Mcq_Variants::apa_journal_article_independent_answer_variants(), array( 'reference_structure' ) );

// ---------------------------------------------------------------------
// Website — individual + organisation.
// ---------------------------------------------------------------------
$web_sets = array(
	'individual' => array( 'author' => array( 'type' => 'individual', 'surname' => 'Smith', 'initials' => 'J.', 'fullName' => 'John Smith' ), 'year' => '2020', 'title' => 'Understanding climate policy', 'url' => 'https://example.com' ),
	'organisation' => array( 'author' => array( 'type' => 'organisation', 'name' => 'World Health Organization' ), 'year' => 'n.d.', 'title' => 'Global health statistics', 'url' => 'https://who.int' ),
);
foreach ( $web_sets as $label => $fields ) {
	foreach ( Citex_APA_Website_Mcq_Variants::variants() as $variant ) {
		check_variant( "Website $label $variant", Citex_APA_Website_Mcq_Variants::build( $variant, $fields ) );
	}
}
check( 'apa_website_independent_answer_variants() names reference_structure', Citex_APA_Website_Mcq_Variants::apa_website_independent_answer_variants(), array( 'reference_structure' ) );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
