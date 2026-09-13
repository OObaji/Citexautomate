<?php
/**
 * Regression tests for Citex_MLA_Reference_Rules's Journal Article support
 * — `Author. "Article Title." Journal Title, vol. V, no. I, Year, pp.
 * X–Y.`: double quotation marks (never Harvard's single quotes) around the
 * article title, with the period INSIDE the closing quote; "vol."/"no."
 * labels (never Harvard's bare "V(I)" shorthand); the year sits after the
 * issue with no parentheses at all. Pure, no WordPress/ACF dependency.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-mla-journal-article.test.php` — not shipped
 * in citex-tools.zip.
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

$JOURNAL_ARTICLE = Citex_MLA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;

function author( $sn, $gn ) {
	return array( 'surname' => $sn, 'givenName' => $gn, 'fullName' => "$gn $sn" );
}
$one   = array( author( 'Ross', 'Amy' ) );
$two   = array( author( 'Ross', 'Amy' ), author( 'Carter', 'Ben' ) );
$three = array( author( 'Ross', 'Amy' ), author( 'Carter', 'Ben' ), author( 'Lee', 'Kim' ) );

$base_fields = array( 'articleTitle' => 'A Study of X', 'journalTitle' => 'Journal of Y', 'volume' => '12', 'issue' => '3', 'year' => '2021', 'pages' => '45-60' );

// ---------------------------------------------------------------------
// 1 author.
// ---------------------------------------------------------------------
check(
	'1 author matches the confirmed MLA Journal Article structure',
	Citex_MLA_Reference_Rules::build_reference( $JOURNAL_ARTICLE, array_merge( $base_fields, array( 'authors' => $one ) ) ),
	'Ross, Amy. "A Study of X." Journal of Y, vol. 12, no. 3, 2021, pp. 45–60.'
);

// ---------------------------------------------------------------------
// 2 authors, 3+ authors — same join_people() rule as Book.
// ---------------------------------------------------------------------
check(
	'2 authors: only the first inverted, joined by ", and "',
	Citex_MLA_Reference_Rules::build_reference( $JOURNAL_ARTICLE, array_merge( $base_fields, array( 'authors' => $two ) ) ),
	'Ross, Amy, and Ben Carter. "A Study of X." Journal of Y, vol. 12, no. 3, 2021, pp. 45–60.'
);
check(
	'3+ authors: "et al." replaces every author after the first',
	Citex_MLA_Reference_Rules::build_reference( $JOURNAL_ARTICLE, array_merge( $base_fields, array( 'authors' => $three ) ) ),
	'Ross, Amy, et al. "A Study of X." Journal of Y, vol. 12, no. 3, 2021, pp. 45–60.'
);

// ---------------------------------------------------------------------
// Title is double-quoted, with the period INSIDE the closing quote —
// never Harvard's own single-quote + trailing comma shape.
// ---------------------------------------------------------------------
$built = Citex_MLA_Reference_Rules::build_reference( $JOURNAL_ARTICLE, array_merge( $base_fields, array( 'authors' => $one ) ) );
check( 'the article title is wrapped in double quotes', false !== strpos( $built, '"A Study of X."' ), true );
check( 'no single-quote curly marks appear anywhere (never Harvard\'s own style)', false !== strpos( $built, '‘' ), false );
check( 'the reference never contains a parenthesised year (unlike Harvard)', 1 === preg_match( '/\(\d{4}\)/', $built ), false );
check( '"vol." and "no." labels are both present', false !== strpos( $built, 'vol. 12' ) && false !== strpos( $built, 'no. 3' ), true );

// ---------------------------------------------------------------------
// Page range uses the same en-dash rendering as Harvard's
// format_page_range() — reused directly, not reimplemented.
// ---------------------------------------------------------------------
check( 'the page range renders with an en dash ("45–60")', false !== strpos( $built, '45–60' ), true );

// ---------------------------------------------------------------------
// format_regex(): accepts the built shape; rejects a Harvard-shaped
// journal article reference.
// ---------------------------------------------------------------------
$regex = Citex_MLA_Reference_Rules::format_regex( $JOURNAL_ARTICLE );
check( '1-author reference matches format_regex', 1 === preg_match( $regex, $built ), true );
check(
	'a Harvard-shaped journal article reference does NOT match',
	1 === preg_match( $regex, 'Ross, A. (2021) ‘A Study of X’, Journal of Y, 12(3), pp. 45–60.' ),
	false
);

// ---------------------------------------------------------------------
// id_prefix(): "MJ" — an "M" prefixed onto Harvard's own "JA".
// ---------------------------------------------------------------------
check( 'id_prefix uses "MJ" for MLA Journal Article', Citex_MLA_Reference_Rules::id_prefix( $JOURNAL_ARTICLE ), 'MJ' );

// ---------------------------------------------------------------------
// mcq_question_stem()/mcq_hint()/identify_error_hint() are Journal-
// Article-specific wording.
// ---------------------------------------------------------------------
check( 'mcq_question_stem mentions "journal article"', false !== stripos( Citex_MLA_Reference_Rules::mcq_question_stem( $JOURNAL_ARTICLE ), 'journal article' ), true );
check( 'mcq_hint mentions "vol."', false !== strpos( Citex_MLA_Reference_Rules::mcq_hint( $JOURNAL_ARTICLE ), 'vol.' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
