<?php
/**
 * Regression tests for Citex_MHRA_Reference_Rules's Phase 2 extension —
 * Edited Book, Journal Article, Website — mirroring
 * reference-rules-chicago-phase2.test.php's own shape, but for MHRA's own
 * rules: full given names always, only the FIRST person inverted (natural
 * word order after), "ed."/"eds" reused directly from
 * Citex_Reference_Rules::designation_for_editor_count() for Edited Book,
 * single-quoted article title with volume and issue combined into one
 * "Volume.Issue" token and the year in its own parenthesis for Journal
 * Article, and angle brackets around the URL with square brackets around
 * the accessed date and NO publication year at all for Website.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-mhra-phase2.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mhra-reference-rules.php';

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
$EDITED_BOOK   = Citex_MHRA_Reference_Rules::CATEGORY_EDITED_BOOK;
$one_editor    = array( person( 'Ross', 'Amy' ) );
$two_editors   = array( person( 'Ross', 'Amy' ), person( 'Carter', 'Ben' ) );
$three_editors = array( person( 'Ross', 'Amy' ), person( 'Carter', 'Ben' ), person( 'Lee', 'Kim' ) );

check(
	'1 editor: "Surname, GivenName, ed., Title (Place: Publisher, Year)."',
	Citex_MHRA_Reference_Rules::build_reference( $EDITED_BOOK, array( 'editors' => $one_editor, 'title' => 'Urban Planning Today', 'place' => 'London', 'publisher' => 'Routledge', 'year' => '2019' ) ),
	'Ross, Amy, ed., Urban Planning Today (London: Routledge, 2019).'
);
check(
	'2 editors: only first inverted, natural word order after, "eds"',
	Citex_MHRA_Reference_Rules::build_reference( $EDITED_BOOK, array( 'editors' => $two_editors, 'title' => 'Digital Culture', 'place' => 'London', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, Amy, and Ben Carter, eds, Digital Culture (London: Routledge, 2021).'
);
check(
	'3 editors: comma-separated, "and" before last, "eds"',
	Citex_MHRA_Reference_Rules::build_reference( $EDITED_BOOK, array( 'editors' => $three_editors, 'title' => 'Digital Culture', 'place' => 'London', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, Amy, Ben Carter, and Kim Lee, eds, Digital Culture (London: Routledge, 2021).'
);
check( 'designation_for_editor_count(1) is "ed."', Citex_Reference_Rules::designation_for_editor_count( 1 ), 'ed.' );
check( 'designation_for_editor_count(2) is "eds"', Citex_Reference_Rules::designation_for_editor_count( 2 ), 'eds' );
check( 'id_prefix uses "HE" for MHRA Edited Book', Citex_MHRA_Reference_Rules::id_prefix( $EDITED_BOOK ), 'HE' );

$eb_regex = Citex_MHRA_Reference_Rules::format_regex( $EDITED_BOOK );
check( '1-editor reference matches format_regex', 1 === preg_match( $eb_regex, 'Ross, Amy, ed., Urban Planning Today (London: Routledge, 2019).' ), true );
check( '2-editor reference matches format_regex', 1 === preg_match( $eb_regex, 'Ross, Amy, and Ben Carter, eds, Digital Culture (London: Routledge, 2021).' ), true );
check( 'an APA-shaped ("(Eds.)") edited-book reference does NOT match', 1 === preg_match( $eb_regex, 'Ross, A., & Carter, B. (Eds.). (2021). Digital culture. Routledge.' ), false );
check( 'mcq_question_stem mentions "edited book"', false !== stripos( Citex_MHRA_Reference_Rules::mcq_question_stem( $EDITED_BOOK ), 'edited book' ), true );

// ---------------------------------------------------------------------
// Journal Article.
// ---------------------------------------------------------------------
$JOURNAL   = Citex_MHRA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;
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
	'Journal Article: single-quoted title, combined "Volume.Issue" token, year in its own parenthesis after it',
	Citex_MHRA_Reference_Rules::build_reference( $JOURNAL, $ja_fields ),
	"Smith, John, 'Climate Change and Policy', Journal of Environmental Studies, 12.3 (2020), 45–60."
);
check( 'id_prefix uses "HJ" for MHRA Journal Article', Citex_MHRA_Reference_Rules::id_prefix( $JOURNAL ), 'HJ' );

$ja_regex = Citex_MHRA_Reference_Rules::format_regex( $JOURNAL );
check( 'valid JA reference matches format_regex', 1 === preg_match( $ja_regex, Citex_MHRA_Reference_Rules::build_reference( $JOURNAL, $ja_fields ) ), true );
check( 'a not-combined volume/issue (Harvard-style) reference does NOT match', 1 === preg_match( $ja_regex, "Smith, John, 'Climate Change and Policy', Journal of Environmental Studies, 12(3) (2020), 45–60." ), false );
check( 'a double-quoted (Chicago-style) article title does NOT match', 1 === preg_match( $ja_regex, 'Smith, John, "Climate Change and Policy", Journal of Environmental Studies, 12.3 (2020), 45–60.' ), false );
check( 'mcq_hint mentions "Volume.Issue"', false !== stripos( Citex_MHRA_Reference_Rules::mcq_hint( $JOURNAL ), 'Volume.Issue' ), true );

// ---------------------------------------------------------------------
// Website.
// ---------------------------------------------------------------------
$WEBSITE = Citex_MHRA_Reference_Rules::CATEGORY_WEBSITE;
check(
	'Website (individual): single-quoted title, angle brackets around URL, square brackets around accessed date, NO publication year at all',
	Citex_MHRA_Reference_Rules::build_reference( $WEBSITE, array( 'author' => array( 'type' => 'individual', 'surname' => 'Smith', 'givenName' => 'John' ), 'title' => 'Understanding Climate Policy', 'url' => 'https://example.com', 'accessedDate' => '5 May 2023' ) ),
	"Smith, John, 'Understanding Climate Policy', <https://example.com> [accessed 5 May 2023]."
);
check(
	'Website (organisation): organisation name rendered as given',
	Citex_MHRA_Reference_Rules::build_reference( $WEBSITE, array( 'author' => array( 'type' => 'organisation', 'name' => 'World Health Organisation' ), 'title' => 'Global Health Statistics', 'url' => 'https://who.int', 'accessedDate' => '14 June 2022' ) ),
	"World Health Organisation, 'Global Health Statistics', <https://who.int> [accessed 14 June 2022]."
);
check( 'id_prefix uses "HW" for MHRA Website', Citex_MHRA_Reference_Rules::id_prefix( $WEBSITE ), 'HW' );

$web_regex = Citex_MHRA_Reference_Rules::format_regex( $WEBSITE );
check( 'individual website reference matches format_regex', 1 === preg_match( $web_regex, "Smith, John, 'Understanding Climate Policy', <https://example.com> [accessed 5 May 2023]." ), true );
check( 'organisation website reference matches format_regex', 1 === preg_match( $web_regex, "World Health Organisation, 'Global Health Statistics', <https://who.int> [accessed 14 June 2022]." ), true );
check( 'a reference with the URL missing its angle brackets does NOT match', 1 === preg_match( $web_regex, "Smith, John, 'Understanding Climate Policy', https://example.com [accessed 5 May 2023]." ), false );
check( 'mcq_hint mentions "accessed"', false !== stripos( Citex_MHRA_Reference_Rules::mcq_hint( $WEBSITE ), 'accessed' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
