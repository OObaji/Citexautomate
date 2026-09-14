<?php
/**
 * Regression tests for Citex_APA_Reference_Rules's Phase 2 extension —
 * Edited Book, Journal Article, Website — mirroring
 * reference-rules-mla-edited-book.test.php's own shape, but for APA's own
 * rules: initials (never a full given name), "&" joining with a comma
 * before it even at exactly two people, a full stop immediately after the
 * year's closing parenthesis, "(Ed.)"/"(Eds.)" for Edited Book, no quotes/
 * no "pp." prefix for Journal Article, and no "Available at:"/accessed
 * date at all for Website.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-apa-phase2.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-reference-rules.php';

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

function person( $sn, $in ) {
	return array( 'surname' => $sn, 'initials' => $in );
}

// ---------------------------------------------------------------------
// Edited Book.
// ---------------------------------------------------------------------
$EDITED_BOOK = Citex_APA_Reference_Rules::CATEGORY_EDITED_BOOK;
$one_editor  = array( person( 'Ross', 'A.' ) );
$two_editors = array( person( 'Ross', 'A.' ), person( 'Carter', 'B.' ) );
$three_editors = array( person( 'Ross', 'A.' ), person( 'Carter', 'B.' ), person( 'Lee', 'K.' ) );

check(
	'1 editor: "Surname, F. (Ed.). (Year). Title. Publisher."',
	Citex_APA_Reference_Rules::build_reference( $EDITED_BOOK, array( 'editors' => $one_editor, 'title' => 'Urban planning today', 'publisher' => 'Routledge', 'year' => '2019' ) ),
	'Ross, A. (Ed.). (2019). Urban planning today. Routledge.'
);
check(
	'2 editors: "&" preceded by a comma, "(Eds.)"',
	Citex_APA_Reference_Rules::build_reference( $EDITED_BOOK, array( 'editors' => $two_editors, 'title' => 'Digital culture', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, A., & Carter, B. (Eds.). (2021). Digital culture. Routledge.'
);
check(
	'3 editors: comma-separated, "&" before last, "(Eds.)"',
	Citex_APA_Reference_Rules::build_reference( $EDITED_BOOK, array( 'editors' => $three_editors, 'title' => 'Digital culture', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, A., Carter, B., & Lee, K. (Eds.). (2021). Digital culture. Routledge.'
);
check( 'designation_for_editor_count(1) is "Ed."', Citex_APA_Reference_Rules::designation_for_editor_count( 1 ), 'Ed.' );
check( 'designation_for_editor_count(2) is "Eds."', Citex_APA_Reference_Rules::designation_for_editor_count( 2 ), 'Eds.' );
check( 'id_prefix uses "AE" for APA Edited Book', Citex_APA_Reference_Rules::id_prefix( $EDITED_BOOK ), 'AE' );

$eb_regex = Citex_APA_Reference_Rules::format_regex( $EDITED_BOOK );
check( '1-editor reference matches format_regex', 1 === preg_match( $eb_regex, 'Ross, A. (Ed.). (2019). Urban planning today. Routledge.' ), true );
check( '2-editor reference matches format_regex', 1 === preg_match( $eb_regex, 'Ross, A., & Carter, B. (Eds.). (2021). Digital culture. Routledge.' ), true );
check( 'MLA-shaped edited-book reference does NOT match', 1 === preg_match( $eb_regex, 'Ross, Amy, editor. Digital Culture. Routledge, 2021.' ), false );
check( 'mcq_question_stem mentions "edited book"', false !== stripos( Citex_APA_Reference_Rules::mcq_question_stem( $EDITED_BOOK ), 'edited book' ), true );

// ---------------------------------------------------------------------
// Journal Article.
// ---------------------------------------------------------------------
$JOURNAL = Citex_APA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;
$ja_fields = array(
	'authors'      => array( person( 'Smith', 'J.' ) ),
	'year'         => '2020',
	'articleTitle' => 'Climate change and policy',
	'journalTitle' => 'Journal of Environmental Studies',
	'volume'       => '12',
	'issue'        => '3',
	'pages'        => '45-60',
);
check(
	'Journal Article: no quotes, bare Volume(Issue), en dash pages, NO "pp." prefix',
	Citex_APA_Reference_Rules::build_reference( $JOURNAL, $ja_fields ),
	'Smith, J. (2020). Climate change and policy. Journal of Environmental Studies, 12(3), 45–60.'
);
check( 'id_prefix uses "AJ" for APA Journal Article', Citex_APA_Reference_Rules::id_prefix( $JOURNAL ), 'AJ' );

$ja_regex = Citex_APA_Reference_Rules::format_regex( $JOURNAL );
check( 'valid JA reference matches format_regex', 1 === preg_match( $ja_regex, Citex_APA_Reference_Rules::build_reference( $JOURNAL, $ja_fields ) ), true );
check( 'a "pp."-prefixed (Harvard-style) reference does NOT match', 1 === preg_match( $ja_regex, 'Smith, J. (2020). Climate change and policy. Journal of Environmental Studies, 12(3), pp. 45–60.' ), false );
check( 'a quoted (Harvard/MLA-style) article title does NOT match', 1 === preg_match( $ja_regex, "Smith, J. (2020). 'Climate change and policy', Journal of Environmental Studies, 12(3), 45–60." ), false );
check( 'mcq_hint mentions "pp."', false !== stripos( Citex_APA_Reference_Rules::mcq_hint( $JOURNAL ), 'pp.' ), true );

// ---------------------------------------------------------------------
// Website.
// ---------------------------------------------------------------------
$WEBSITE = Citex_APA_Reference_Rules::CATEGORY_WEBSITE;
check(
	'Website (individual, dated): no "Available at:" label',
	Citex_APA_Reference_Rules::build_reference( $WEBSITE, array( 'author' => array( 'type' => 'individual', 'surname' => 'Smith', 'initials' => 'J.' ), 'year' => '2020', 'title' => 'Understanding climate policy', 'url' => 'https://example.com' ) ),
	'Smith, J. (2020). Understanding climate policy. https://example.com.'
);
check(
	'Website (organisation, undated): "(n.d.)"',
	Citex_APA_Reference_Rules::build_reference( $WEBSITE, array( 'author' => array( 'type' => 'organisation', 'name' => 'World Health Organization' ), 'year' => 'n.d.', 'title' => 'Global health statistics', 'url' => 'https://who.int' ) ),
	'World Health Organization (n.d.). Global health statistics. https://who.int.'
);
check( 'id_prefix uses "AW" for APA Website', Citex_APA_Reference_Rules::id_prefix( $WEBSITE ), 'AW' );

$web_regex = Citex_APA_Reference_Rules::format_regex( $WEBSITE );
check( 'dated website reference matches format_regex', 1 === preg_match( $web_regex, 'Smith, J. (2020). Understanding climate policy. https://example.com.' ), true );
check( 'a reference with a wrongly-added "Available at:" label does NOT match', 1 === preg_match( $web_regex, 'Smith, J. (2020). Understanding climate policy. Available at: https://example.com.' ), false );
check( 'mcq_hint mentions "Available at"', false !== stripos( Citex_APA_Reference_Rules::mcq_hint( $WEBSITE ), 'Available at' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
