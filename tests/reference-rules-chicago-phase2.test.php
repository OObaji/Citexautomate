<?php
/**
 * Regression tests for Citex_Chicago_Reference_Rules's Phase 2 extension —
 * Edited Book, Journal Article, Website — mirroring
 * reference-rules-apa-phase2.test.php's own shape, but for Chicago's own
 * rules: full given names (never initials), a comma before "and" even at
 * exactly two people, "ed"/"eds" (lowercase, unparenthesised, INSIDE the
 * person segment) for Edited Book, double-quoted article title with a
 * colon before the page range and no "pp." prefix for Journal Article, and
 * a double-quoted title with no "Available at:"/accessed date at all for
 * Website — place of publication IS kept throughout, unlike APA/MLA.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-chicago-phase2.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-chicago-reference-rules.php';

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

function person( $sn, $gn ) {
	return array( 'surname' => $sn, 'givenName' => $gn );
}

// ---------------------------------------------------------------------
// Edited Book.
// ---------------------------------------------------------------------
$EDITED_BOOK   = Citex_Chicago_Reference_Rules::CATEGORY_EDITED_BOOK;
$one_editor    = array( person( 'Ross', 'Amy' ) );
$two_editors   = array( person( 'Ross', 'Amy' ), person( 'Carter', 'Ben' ) );
$three_editors = array( person( 'Ross', 'Amy' ), person( 'Carter', 'Ben' ), person( 'Lee', 'Kim' ) );

check(
	'1 editor: "Surname, GivenName, ed. Year. Title. Place: Publisher."',
	Citex_Chicago_Reference_Rules::build_reference( $EDITED_BOOK, array( 'editors' => $one_editor, 'title' => 'Urban Planning Today', 'place' => 'London', 'publisher' => 'Routledge', 'year' => '2019' ) ),
	'Ross, Amy, ed. 2019. Urban Planning Today. London: Routledge.'
);
check(
	'2 editors: comma before "and", "eds"',
	Citex_Chicago_Reference_Rules::build_reference( $EDITED_BOOK, array( 'editors' => $two_editors, 'title' => 'Digital Culture', 'place' => 'London', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, Amy, and Carter, Ben, eds. 2021. Digital Culture. London: Routledge.'
);
check(
	'3 editors: comma-separated, "and" before last, "eds"',
	Citex_Chicago_Reference_Rules::build_reference( $EDITED_BOOK, array( 'editors' => $three_editors, 'title' => 'Digital Culture', 'place' => 'London', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, Amy, Carter, Ben, and Lee, Kim, eds. 2021. Digital Culture. London: Routledge.'
);
check( 'designation_for_editor_count(1) is "ed"', Citex_Chicago_Reference_Rules::designation_for_editor_count( 1 ), 'ed' );
check( 'designation_for_editor_count(2) is "eds"', Citex_Chicago_Reference_Rules::designation_for_editor_count( 2 ), 'eds' );
check( 'id_prefix uses "CE" for Chicago Edited Book', Citex_Chicago_Reference_Rules::id_prefix( $EDITED_BOOK ), 'CE' );

$eb_regex = Citex_Chicago_Reference_Rules::format_regex( $EDITED_BOOK );
check( '1-editor reference matches format_regex', 1 === preg_match( $eb_regex, 'Ross, Amy, ed. 2019. Urban Planning Today. London: Routledge.' ), true );
check( '2-editor reference matches format_regex', 1 === preg_match( $eb_regex, 'Ross, Amy, and Carter, Ben, eds. 2021. Digital Culture. London: Routledge.' ), true );
check( 'an APA-shaped ("(Eds.)") edited-book reference does NOT match', 1 === preg_match( $eb_regex, 'Ross, A., & Carter, B. (Eds.). (2021). Digital culture. Routledge.' ), false );
check( 'mcq_question_stem mentions "edited book"', false !== stripos( Citex_Chicago_Reference_Rules::mcq_question_stem( $EDITED_BOOK ), 'edited book' ), true );

// ---------------------------------------------------------------------
// Journal Article.
// ---------------------------------------------------------------------
$JOURNAL   = Citex_Chicago_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;
$ja_fields = array(
	'authors'      => array( person( 'Smith', 'John' ) ),
	'year'         => '2020',
	'articleTitle' => 'Climate Change and Policy',
	'journalTitle' => 'Journal of Environmental Studies',
	'volume'       => '12',
	'issue'        => '3',
	'pages'        => '45-60',
);
check(
	'Journal Article: double-quoted title, space before parenthesised issue, colon before pages, NO "pp." prefix',
	Citex_Chicago_Reference_Rules::build_reference( $JOURNAL, $ja_fields ),
	'Smith, John. 2020. "Climate Change and Policy." Journal of Environmental Studies 12 (3): 45–60.'
);
check( 'id_prefix uses "CJ" for Chicago Journal Article', Citex_Chicago_Reference_Rules::id_prefix( $JOURNAL ), 'CJ' );

$ja_regex = Citex_Chicago_Reference_Rules::format_regex( $JOURNAL );
check( 'valid JA reference matches format_regex', 1 === preg_match( $ja_regex, Citex_Chicago_Reference_Rules::build_reference( $JOURNAL, $ja_fields ) ), true );
check( 'a "pp."-prefixed (Harvard-style) reference does NOT match', 1 === preg_match( $ja_regex, 'Smith, John. 2020. "Climate Change and Policy." Journal of Environmental Studies 12 (3): pp. 45–60.' ), false );
check( 'an unquoted (APA-style) article title does NOT match', 1 === preg_match( $ja_regex, 'Smith, John. 2020. Climate Change and Policy. Journal of Environmental Studies 12 (3): 45–60.' ), false );
check( 'mcq_hint mentions "pp."', false !== stripos( Citex_Chicago_Reference_Rules::mcq_hint( $JOURNAL ), 'pp.' ), true );

// ---------------------------------------------------------------------
// Website.
// ---------------------------------------------------------------------
$WEBSITE = Citex_Chicago_Reference_Rules::CATEGORY_WEBSITE;
check(
	'Website (individual, dated): double-quoted title, no "Available at:" label',
	Citex_Chicago_Reference_Rules::build_reference( $WEBSITE, array( 'author' => array( 'type' => 'individual', 'surname' => 'Smith', 'givenName' => 'John' ), 'year' => '2020', 'title' => 'Understanding Climate Policy', 'url' => 'https://example.com' ) ),
	'Smith, John 2020. "Understanding Climate Policy." https://example.com.'
);
check(
	'Website (organisation, undated): "n.d." with no extra period',
	Citex_Chicago_Reference_Rules::build_reference( $WEBSITE, array( 'author' => array( 'type' => 'organisation', 'name' => 'World Health Organization' ), 'year' => 'n.d.', 'title' => 'Global Health Statistics', 'url' => 'https://who.int' ) ),
	'World Health Organization n.d. "Global Health Statistics." https://who.int.'
);
check( 'id_prefix uses "CW" for Chicago Website', Citex_Chicago_Reference_Rules::id_prefix( $WEBSITE ), 'CW' );

$web_regex = Citex_Chicago_Reference_Rules::format_regex( $WEBSITE );
check( 'dated website reference matches format_regex', 1 === preg_match( $web_regex, 'Smith, John 2020. "Understanding Climate Policy." https://example.com.' ), true );
check( 'undated ("n.d.") website reference matches format_regex', 1 === preg_match( $web_regex, 'World Health Organization n.d. "Global Health Statistics." https://who.int.' ), true );
check( 'a reference with a wrongly-added "Available at:" label does NOT match', 1 === preg_match( $web_regex, 'Smith, John 2020. "Understanding Climate Policy." Available at: https://example.com.' ), false );
check( 'mcq_hint mentions "Available at"', false !== stripos( Citex_Chicago_Reference_Rules::mcq_hint( $WEBSITE ), 'Available at' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
