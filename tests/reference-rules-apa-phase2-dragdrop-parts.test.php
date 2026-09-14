<?php
/**
 * Regression tests for Citex_APA_Edited_Book_Dragdrop_Parts /
 * Citex_APA_Journal_Article_Dragdrop_Parts / Citex_APA_Website_Dragdrop_Parts
 * — proves, for each, that reconstruct(build(select_parts(...))) exactly
 * reproduces Citex_APA_Reference_Rules::build_reference()'s own output,
 * across editor/author counts, and that the reconstruction always matches
 * the category's own format_regex().
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-apa-phase2-dragdrop-parts.test.php` — not
 * shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-edited-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-journal-article-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-website-dragdrop-parts.php';

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

function reconstruct( $built ) {
	$fixed = $built['fixedText'];
	$parts = $built['parts'];
	$result = '';
	$index = 0;
	$len = strlen( $fixed );
	for ( $i = 0; $i < $len; $i++ ) {
		if ( '|' !== $fixed[ $i ] ) {
			$result .= $fixed[ $i ];
			continue;
		}
		if ( $i + 1 < $len && '|' === $fixed[ $i + 1 ] ) {
			$result .= (string) ( $parts[ $index++ ] ?? '' );
			$i++;
			continue;
		}
		$result .= (string) ( $parts[ $index++ ] ?? '' );
	}
	return trim( $result );
}

function person( $sn, $in ) {
	return array( 'surname' => $sn, 'initials' => $in );
}

// ---------------------------------------------------------------------
// Edited Book — 1, 2, 3 editors.
// ---------------------------------------------------------------------
$eb_sets = array(
	1 => array( person( 'Ross', 'A.' ) ),
	2 => array( person( 'Ross', 'A.' ), person( 'Carter', 'B.' ) ),
	3 => array( person( 'Ross', 'A.' ), person( 'Carter', 'B.' ), person( 'Lee', 'K.' ) ),
);
$eb_fields = array( 'year' => '2021', 'title' => 'Digital culture', 'publisher' => 'Routledge' );
foreach ( $eb_sets as $n => $editors ) {
	$keys = Citex_APA_Edited_Book_Dragdrop_Parts::select_parts( 'AE0' . $n, $editors );
	$built = Citex_APA_Edited_Book_Dragdrop_Parts::build( $keys, $editors, $eb_fields );
	$recon = reconstruct( $built );
	$expected = Citex_APA_Reference_Rules::build_reference( Citex_APA_Reference_Rules::CATEGORY_EDITED_BOOK, array_merge( $eb_fields, array( 'editors' => $editors ) ) );
	check( "Edited Book n={$n}: reconstruction matches build_reference()", $recon, $expected );
	check( "Edited Book n={$n}: reconstruction matches format_regex", 1 === preg_match( Citex_APA_Reference_Rules::format_regex( Citex_APA_Reference_Rules::CATEGORY_EDITED_BOOK ), $recon ), true );
	check( "Edited Book n={$n}: 'designation' key is always drawn", in_array( 'designation', $keys, true ), true );
}

// ---------------------------------------------------------------------
// Journal Article — 1, 2, 3 authors.
// ---------------------------------------------------------------------
$ja_sets = array(
	1 => array( person( 'Smith', 'J.' ) ),
	2 => array( person( 'Smith', 'J.' ), person( 'Doe', 'A.' ) ),
	3 => array( person( 'Smith', 'J.' ), person( 'Doe', 'A.' ), person( 'Lee', 'K.' ) ),
);
$ja_fields = array( 'articleTitle' => 'Climate change and policy', 'journalTitle' => 'Journal of Environmental Studies', 'volume' => '12', 'issue' => '3', 'year' => '2020', 'pages' => '45-60' );
foreach ( $ja_sets as $n => $authors ) {
	$keys = Citex_APA_Journal_Article_Dragdrop_Parts::select_parts( 'AJ0' . $n, $authors );
	$built = Citex_APA_Journal_Article_Dragdrop_Parts::build( $keys, $authors, $ja_fields );
	$recon = reconstruct( $built );
	$expected = Citex_APA_Reference_Rules::build_reference( Citex_APA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, array_merge( $ja_fields, array( 'authors' => $authors ) ) );
	check( "Journal Article n={$n}: reconstruction matches build_reference()", $recon, $expected );
	check( "Journal Article n={$n}: reconstruction matches format_regex", 1 === preg_match( Citex_APA_Reference_Rules::format_regex( Citex_APA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE ), $recon ), true );
}

// ---------------------------------------------------------------------
// Website — individual (dated) + organisation (n.d.).
// ---------------------------------------------------------------------
$web_sets = array(
	'individual_dated' => array( array( 'type' => 'individual', 'surname' => 'Smith', 'initials' => 'J.' ), array( 'year' => '2020', 'title' => 'Understanding climate policy', 'url' => 'https://example.com' ) ),
	'organisation_nd'   => array( array( 'type' => 'organisation', 'name' => 'World Health Organization' ), array( 'year' => 'n.d.', 'title' => 'Global health statistics', 'url' => 'https://who.int' ) ),
);
foreach ( $web_sets as $label => $pair ) {
	list( $author, $fields ) = $pair;
	$keys = Citex_APA_Website_Dragdrop_Parts::select_parts( 'AW01' );
	$built = Citex_APA_Website_Dragdrop_Parts::build( $keys, $author, $fields );
	$recon = reconstruct( $built );
	$expected = Citex_APA_Reference_Rules::build_reference( Citex_APA_Reference_Rules::CATEGORY_WEBSITE, array_merge( $fields, array( 'author' => $author ) ) );
	check( "Website {$label}: reconstruction matches build_reference()", $recon, $expected );
	check( "Website {$label}: reconstruction matches format_regex", 1 === preg_match( Citex_APA_Reference_Rules::format_regex( Citex_APA_Reference_Rules::CATEGORY_WEBSITE ), $recon ), true );
	check( "Website {$label}: 'url' is never among the drawn keys", in_array( 'url', $keys, true ), false );
}

// ---------------------------------------------------------------------
// CRITICAL — a re-supplied `dragdropPartKeys` selection (as the validator
// does) reproduces the identical build, never re-randomising.
// ---------------------------------------------------------------------
$keys_a = Citex_APA_Edited_Book_Dragdrop_Parts::select_parts( 'AE99', $eb_sets[2] );
$built_a = Citex_APA_Edited_Book_Dragdrop_Parts::build( $keys_a, $eb_sets[2], $eb_fields );
$built_b = Citex_APA_Edited_Book_Dragdrop_Parts::build( $keys_a, $eb_sets[2], $eb_fields );
check( 'build() is deterministic given the same selected keys', $built_a, $built_b );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
