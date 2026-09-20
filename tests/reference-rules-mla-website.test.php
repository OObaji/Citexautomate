<?php
/**
 * Regression tests for Citex_MLA_Reference_Rules's Website support —
 * `Author/Org. "Page Title." [Year,] URL. Accessed Day Month Year.`: the
 * page title is double-quoted (never Harvard's own unquoted title); a full
 * given name (never an initial) for a named individual author; and,
 * critically, real MLA 9 style has NO "n.d." convention at all — when no
 * year can be identified, the year segment is simply omitted and the
 * citation relies on the Accessed date alone. Pure, no WordPress/ACF
 * dependency.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-mla-website.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-reference-rules.php';

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

$WEBSITE = Citex_MLA_Reference_Rules::CATEGORY_WEBSITE;

$individual   = array( 'type' => 'individual', 'surname' => 'Ross', 'givenName' => 'Amy', 'fullName' => 'Amy Ross' );
$organisation = array( 'type' => 'organisation', 'name' => 'WHO' );

// ---------------------------------------------------------------------
// Named individual author, with a year — the full given name is used,
// never an initial (unlike Harvard's own format_website_author()).
// ---------------------------------------------------------------------
check(
	'individual author, dated: "Surname, GivenName \"Title.\" Year, URL. Accessed Date."',
	Citex_MLA_Reference_Rules::build_reference( $WEBSITE, array( 'author' => $individual, 'title' => 'Page Title', 'year' => '2020', 'url' => 'https://example.com', 'accessedDate' => '12 June 2024' ) ),
	'Ross, Amy "Page Title." 2020, https://example.com. Accessed 12 June 2024.'
);

// ---------------------------------------------------------------------
// Organisation author, no year at all — the year segment is OMITTED
// entirely (no literal "n.d." anywhere), relying on the Accessed date.
// ---------------------------------------------------------------------
$undated = Citex_MLA_Reference_Rules::build_reference( $WEBSITE, array( 'author' => $organisation, 'title' => 'Page Title', 'year' => '', 'url' => 'https://example.com', 'accessedDate' => '12 June 2024' ) );
check(
	'organisation author, undated: "Org \"Title.\" URL. Accessed Date." — no year segment at all',
	$undated,
	'WHO "Page Title." https://example.com. Accessed 12 June 2024.'
);
check( 'the undated reference never contains the literal "n.d." (a Harvard-only convention)', false !== strpos( $undated, 'n.d.' ), false );

// ---------------------------------------------------------------------
// format_website_author() directly.
// ---------------------------------------------------------------------
check( 'format_website_author(): individual uses the full given name, never an initial', Citex_MLA_Reference_Rules::format_website_author( $individual ), 'Ross, Amy' );
check( 'format_website_author(): organisation is rendered as-is', Citex_MLA_Reference_Rules::format_website_author( $organisation ), 'WHO' );

// ---------------------------------------------------------------------
// The page title is double-quoted; the "Accessed" literal is present.
// ---------------------------------------------------------------------
$built = Citex_MLA_Reference_Rules::build_reference( $WEBSITE, array( 'author' => $individual, 'title' => 'Page Title', 'year' => '2020', 'url' => 'https://example.com', 'accessedDate' => '12 June 2024' ) );
check( 'the page title is wrapped in double quotes', false !== strpos( $built, '"Page Title."' ), true );
check( 'the "Accessed" literal is present', false !== strpos( $built, 'Accessed 12 June 2024.' ), true );
check( 'the reference never uses Harvard\'s own "Available at:" wording', false !== strpos( $built, 'Available at:' ), false );

// ---------------------------------------------------------------------
// format_regex(): accepts both the dated and undated shapes; rejects a
// Harvard-shaped website reference.
// ---------------------------------------------------------------------
$regex = Citex_MLA_Reference_Rules::format_regex( $WEBSITE );
check( 'dated reference matches format_regex', 1 === preg_match( $regex, $built ), true );
check( 'undated reference matches format_regex', 1 === preg_match( $regex, $undated ), true );
check(
	'a Harvard-shaped website reference does NOT match',
	1 === preg_match( $regex, 'Ross, A. (2020) Page Title. Available at: https://example.com (Accessed: 12 June 2024).' ),
	false
);

// ---------------------------------------------------------------------
// id_prefix(): "MW" — an "M" prefixed onto Harvard's own "WR".
// ---------------------------------------------------------------------
check( 'id_prefix uses "MW" for MLA Website', Citex_MLA_Reference_Rules::id_prefix( $WEBSITE ), 'MW' );

// ---------------------------------------------------------------------
// mcq_question_stem()/mcq_hint()/identify_error_hint() are Website-
// specific wording.
// ---------------------------------------------------------------------
check( 'mcq_question_stem mentions "webpage"', false !== stripos( Citex_MLA_Reference_Rules::mcq_question_stem( $WEBSITE ), 'webpage' ), true );
check( 'mcq_hint mentions "organisation"', false !== stripos( Citex_MLA_Reference_Rules::mcq_hint( $WEBSITE ), 'organisation' ), true );
check( 'identify_error_hint mentions "n.d."', false !== strpos( Citex_MLA_Reference_Rules::identify_error_hint( $WEBSITE ), 'n.d.' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
