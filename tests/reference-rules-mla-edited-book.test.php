<?php
/**
 * Regression tests for Citex_MLA_Reference_Rules's Edited Book support —
 * `Editor(s), editor|editors. Title. Publisher, Year.`, mirroring MLA
 * Book's own author-joining rule (full given name, "et al." at 3+) but
 * with a trailing "editor"/"editors" designation instead of Harvard's own
 * "(ed.)"/"(eds)" abbreviation. Pure, no WordPress/ACF dependency.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-mla-edited-book.test.php` — not shipped in
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

$EDITED_BOOK = Citex_MLA_Reference_Rules::CATEGORY_EDITED_BOOK;

function editor( $sn, $gn ) {
	return array( 'surname' => $sn, 'givenName' => $gn, 'fullName' => "$gn $sn" );
}
$one   = array( editor( 'Ross', 'Amy' ) );
$two   = array( editor( 'Ross', 'Amy' ), editor( 'Carter', 'Ben' ) );
$three = array( editor( 'Ross', 'Amy' ), editor( 'Carter', 'Ben' ), editor( 'Lee', 'Kim' ) );
$four  = array_merge( $three, array( editor( 'Kaur', 'Jo' ) ) );

// ---------------------------------------------------------------------
// 1 editor: singular "editor".
// ---------------------------------------------------------------------
check(
	'1 editor: "Last, First, editor. Title. Publisher, Year."',
	Citex_MLA_Reference_Rules::build_reference( $EDITED_BOOK, array( 'editors' => $one, 'title' => 'Digital Culture', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, Amy, editor. Digital Culture. Routledge, 2021.'
);

// ---------------------------------------------------------------------
// 2 editors: only the first is inverted, plural "editors".
// ---------------------------------------------------------------------
check(
	'2 editors: only the first inverted, plural "editors"',
	Citex_MLA_Reference_Rules::build_reference( $EDITED_BOOK, array( 'editors' => $two, 'title' => 'Digital Culture', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, Amy, and Ben Carter, editors. Digital Culture. Routledge, 2021.'
);

// ---------------------------------------------------------------------
// 3+ editors: "et al." replaces every editor after the first — and its
// own abbreviation period must survive, never collapsed to a bare "al"
// by the designation-appending step (the exact bug found and fixed while
// building this).
// ---------------------------------------------------------------------
check(
	'3 editors: "et al., editors." — the abbreviation period survives',
	Citex_MLA_Reference_Rules::build_reference( $EDITED_BOOK, array( 'editors' => $three, 'title' => 'Digital Culture', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, Amy, et al., editors. Digital Culture. Routledge, 2021.'
);
check(
	'4 editors: same "et al., editors." shape as exactly 3',
	Citex_MLA_Reference_Rules::build_reference( $EDITED_BOOK, array( 'editors' => $four, 'title' => 'Digital Culture', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, Amy, et al., editors. Digital Culture. Routledge, 2021.'
);
check( 'join_editors() 3+ never collapses "et al." to a bare "al"', false !== strpos( Citex_MLA_Reference_Rules::join_editors( $three ), 'et al.' ), true );
check( 'join_editors() 3+ never contains a bare ", al" (the exact regression)', false !== strpos( Citex_MLA_Reference_Rules::join_editors( $three ), ', al' ), false );

// ---------------------------------------------------------------------
// join_editors() directly.
// ---------------------------------------------------------------------
check( 'join_editors(): 1 editor', Citex_MLA_Reference_Rules::join_editors( $one ), 'Ross, Amy, editor.' );
check( 'join_editors(): 2 editors', Citex_MLA_Reference_Rules::join_editors( $two ), 'Ross, Amy, and Ben Carter, editors.' );
check( 'join_editors(): 3+ editors', Citex_MLA_Reference_Rules::join_editors( $three ), 'Ross, Amy, et al., editors.' );

// ---------------------------------------------------------------------
// format_regex(): accepts all 3 shapes; rejects a Harvard-shaped
// reference.
// ---------------------------------------------------------------------
$regex = Citex_MLA_Reference_Rules::format_regex( $EDITED_BOOK );
check( '1-editor reference matches format_regex', 1 === preg_match( $regex, 'Ross, Amy, editor. Digital Culture. Routledge, 2021.' ), true );
check( '2-editor reference matches format_regex', 1 === preg_match( $regex, 'Ross, Amy, and Ben Carter, editors. Digital Culture. Routledge, 2021.' ), true );
check( '3+ editor reference matches format_regex', 1 === preg_match( $regex, 'Ross, Amy, et al., editors. Digital Culture. Routledge, 2021.' ), true );
check( 'a Harvard-shaped edited-book reference does NOT match', 1 === preg_match( $regex, 'Ross, A. (ed.) (2021) Digital Culture. London: Routledge.' ), false );

// ---------------------------------------------------------------------
// id_prefix(): "ME" — an "M" prefixed onto Harvard's own "ED", distinct
// from every other category/style combination.
// ---------------------------------------------------------------------
check( 'id_prefix uses "ME" for MLA Edited Book', Citex_MLA_Reference_Rules::id_prefix( $EDITED_BOOK ), 'ME' );
check( 'distinct from MLA Book\'s own "MB"', Citex_MLA_Reference_Rules::id_prefix( $EDITED_BOOK ) === Citex_MLA_Reference_Rules::id_prefix( Citex_MLA_Reference_Rules::CATEGORY_BOOK ), false );

// ---------------------------------------------------------------------
// mcq_question_stem()/mcq_hint()/identify_error_hint() are Edited-Book-
// specific wording, never silently reusing Book's own text.
// ---------------------------------------------------------------------
check( 'mcq_question_stem mentions "edited book"', false !== stripos( Citex_MLA_Reference_Rules::mcq_question_stem( $EDITED_BOOK ), 'edited book' ), true );
check( 'mcq_hint mentions "editor"', false !== stripos( Citex_MLA_Reference_Rules::mcq_hint( $EDITED_BOOK ), 'editor' ), true );
check( 'identify_error_hint mentions "editor"', false !== stripos( Citex_MLA_Reference_Rules::identify_error_hint( $EDITED_BOOK ), 'editor' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
