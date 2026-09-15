<?php
/**
 * Regression tests for Citex_Reference_Rules' Website/Web Resource support
 * — the confirmed website/webpage format: Author/Organisation (Year|n.d.)
 * Title. Available at: URL (Accessed: Day Month Year). Unlike every other
 * category, there is only ONE author-or-organisation (no multi-person
 * joining rule), and there is no publisher element in the reference at all
 * (see build_website_reference()'s own docblock — the record's own
 * `publisher` field, when present, is simply never rendered). Pure, no
 * WordPress/ACF dependency, so this file needs no stub environment.
 *
 * Repo-level only, run with plain `php tests/reference-rules-website.test.php`
 * — not shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';

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

$WR = Citex_Reference_Rules::CATEGORY_WEBSITE;

$individual = array( 'type' => 'individual', 'surname' => 'Mitchell', 'initials' => 'S.' );
$organisation = array( 'type' => 'organisation', 'name' => 'University of Leeds' );

// ---------------------------------------------------------------------
// 1 & 3. Individual author, dated webpage — matches the confirmed
// Liverpool Hope structure exactly.
// ---------------------------------------------------------------------
$dated_fields = array( 'author' => $individual, 'year' => '2024', 'title' => 'Study skills guide', 'publisher' => 'University of Leeds', 'url' => 'https://www.leeds.ac.uk/study-skills', 'accessedDate' => '2 September 2026' );
check(
	'[1][3] individual author, dated webpage matches the confirmed structure',
	Citex_Reference_Rules::build_reference( $WR, $dated_fields ),
	'Mitchell, S. (2024) Study skills guide. Available at: https://www.leeds.ac.uk/study-skills (Accessed: 2 September 2026).'
);

// ---------------------------------------------------------------------
// 2 & 4. Organisation author, undated webpage ("n.d.") — the organisation's
// name is rendered exactly as given, never comma-inverted.
// ---------------------------------------------------------------------
$undated_fields = array( 'author' => $organisation, 'year' => 'n.d.', 'title' => 'About us', 'publisher' => 'University of Leeds', 'url' => 'https://www.leeds.ac.uk/about', 'accessedDate' => '2 September 2026' );
check(
	'[2][4] organisation author, undated ("n.d.") webpage matches the confirmed structure',
	Citex_Reference_Rules::build_reference( $WR, $undated_fields ),
	'University of Leeds (n.d.) About us. Available at: https://www.leeds.ac.uk/about (Accessed: 2 September 2026).'
);

// ---------------------------------------------------------------------
// 5. A PDF document downloaded from a website uses the IDENTICAL Liverpool
// Hope structure (confirmed by the official BIS example) — no separate
// data model or format branch is needed for "webpage vs PDF"; it is purely
// a real-source content choice.
// ---------------------------------------------------------------------
$pdf_fields = array(
	'author'       => array( 'type' => 'organisation', 'name' => 'Department for Business Innovation and Skills' ),
	'year'         => '2009',
	'title'        => 'Higher ambitions: the future of universities in a knowledge economy',
	'publisher'    => 'Department for Business Innovation and Skills',
	'url'          => 'https://www.gov.uk/government/publications/higher-ambitions',
	'accessedDate' => '20 May 2025',
);
check(
	'[5] a PDF document reference uses the identical structure as a webpage',
	Citex_Reference_Rules::build_reference( $WR, $pdf_fields ),
	'Department for Business Innovation and Skills (2009) Higher ambitions: the future of universities in a knowledge economy. Available at: https://www.gov.uk/government/publications/higher-ambitions (Accessed: 20 May 2025).'
);

// ---------------------------------------------------------------------
// format_regex(): correctly accepts both good forms, and correctly rejects
// each of the required distractor shapes.
// ---------------------------------------------------------------------
$regex = Citex_Reference_Rules::format_regex( $WR );
$good  = 'Mitchell, S. (2024) Study skills guide. Available at: https://www.leeds.ac.uk/study-skills (Accessed: 2 September 2026).';
check( '[6][8][10][12][14][19] a fully correct reference matches', 1 === preg_match( $regex, $good ), true );
check( '[2] a fully correct organisation/undated reference matches', 1 === preg_match( $regex, 'University of Leeds (n.d.) About us. Available at: https://www.leeds.ac.uk/about (Accessed: 2 September 2026).' ), true );
check( '[7] "Available from:" instead of "Available at:" does NOT match', 1 === preg_match( $regex, 'Mitchell, S. (2024) Study skills guide. Available from: https://www.leeds.ac.uk/study-skills (Accessed: 2 September 2026).' ), false );
check( '[11] "Available at" missing its colon does NOT match', 1 === preg_match( $regex, 'Mitchell, S. (2024) Study skills guide. Available at https://www.leeds.ac.uk/study-skills (Accessed: 2 September 2026).' ), false );
check( '[13] a missing "(Accessed: date)" element does NOT match', 1 === preg_match( $regex, 'Mitchell, S. (2024) Study skills guide. Available at: https://www.leeds.ac.uk/study-skills.' ), false );
check( '[15] the accessed date missing its parentheses does NOT match', 1 === preg_match( $regex, 'Mitchell, S. (2024) Study skills guide. Available at: https://www.leeds.ac.uk/study-skills Accessed: 2 September 2026.' ), false );
check( 'a guessed/invalid year shape ("circa 2024") does NOT match', 1 === preg_match( $regex, 'Mitchell, S. (circa 2024) Study skills guide. Available at: https://www.leeds.ac.uk/study-skills (Accessed: 2 September 2026).' ), false );
check( '[19] a missing final full stop does NOT match', 1 === preg_match( $regex, 'Mitchell, S. (2024) Study skills guide. Available at: https://www.leeds.ac.uk/study-skills (Accessed: 2 September 2026)' ), false );

// ---------------------------------------------------------------------
// id_prefix(), mcq_question_stem(), mcq_hint() are all Website-specific,
// never silently falling back to Book's.
// ---------------------------------------------------------------------
check( '[26] id_prefix uses "WR" for Website', Citex_Reference_Rules::id_prefix( $WR ), 'WR' );
check( 'id_prefix: Book unaffected ("BK")', Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_BOOK ), 'BK' );
check( 'id_prefix: Journal Article unaffected ("JA")', Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE ), 'JA' );
check( 'mcq_question_stem mentions "website"', false !== stripos( Citex_Reference_Rules::mcq_question_stem( $WR ), 'website' ), true );
check( 'mcq_hint mentions individual/organisation and "n.d."', false !== stripos( Citex_Reference_Rules::mcq_hint( $WR ), 'organisation' ) && false !== strpos( Citex_Reference_Rules::mcq_hint( $WR ), 'n.d.' ), true );

// ---------------------------------------------------------------------
// [25] The distractor-pattern catalogue names Website-specific mistakes.
// ---------------------------------------------------------------------
$patterns = Citex_Reference_Rules::mcq_distractor_patterns( $WR );
$joined   = implode( ' ', $patterns );
check( 'the catalogue mentions "Available at:"', false !== strpos( $joined, 'Available at:' ), true );
check( 'the catalogue mentions "Accessed"', false !== strpos( $joined, 'Accessed' ), true );
check( 'the catalogue mentions "n.d."', false !== stripos( $joined, 'n.d.' ), true );
check( 'the catalogue no longer mentions "[online]"', false !== strpos( $joined, '[online]' ), false );

// ---------------------------------------------------------------------
// [30][31] categories()/is_known_category(): Website is now a first-class
// category alongside Book/Edited Book/Journal Article, without disturbing
// any of them.
// ---------------------------------------------------------------------
check( '[30] Book is still known', Citex_Reference_Rules::is_known_category( 'Book' ), true );
check( '[31] Journal Article is still known', Citex_Reference_Rules::is_known_category( 'Journal Article' ), true );
check( 'Website is now a known category', Citex_Reference_Rules::is_known_category( 'Website' ), true );
check( 'categories() lists exactly 4 categories', count( Citex_Reference_Rules::categories() ), 4 );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
