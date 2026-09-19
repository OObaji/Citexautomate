<?php
/**
 * Regression tests for Citex_MLA_Reference_Rules — MLA's own Book reference
 * shape: `Author. Title. Publisher, Year.`, full given name (never an
 * initial), the second of exactly 2 authors joined by "and" in "First Last"
 * order, "et al." replacing every author after the first once there are 3
 * or more, and no place of publication at all (dropped by MLA in its 7th
 * edition). Pure, no WordPress/ACF dependency, so this file needs no stub
 * environment.
 *
 * Repo-level only, run with plain `php tests/reference-rules-mla-book.test.php`
 * — not shipped in citex-tools.zip.
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

$BOOK = Citex_MLA_Reference_Rules::CATEGORY_BOOK;

$one   = array( array( 'surname' => 'Smith', 'givenName' => 'John', 'fullName' => 'John Smith' ) );
$two   = array( array( 'surname' => 'Ross', 'givenName' => 'Amy', 'fullName' => 'Amy Ross' ), array( 'surname' => 'Carter', 'givenName' => 'Ben', 'fullName' => 'Ben Carter' ) );
$three = array(
	array( 'surname' => 'Ross', 'givenName' => 'Amy', 'fullName' => 'Amy Ross' ),
	array( 'surname' => 'Carter', 'givenName' => 'Ben', 'fullName' => 'Ben Carter' ),
	array( 'surname' => 'Lee', 'givenName' => 'Kim', 'fullName' => 'Kim Lee' ),
);

// ---------------------------------------------------------------------
// 1 author: "Last, First. Title. Publisher, Year."
// ---------------------------------------------------------------------
check(
	'1 author matches the confirmed MLA Book structure',
	Citex_MLA_Reference_Rules::build_reference( $BOOK, array( 'authors' => $one, 'title' => 'The Great Adventure', 'publisher' => 'Penguin', 'year' => '2020' ) ),
	'Smith, John. The Great Adventure. Penguin, 2020.'
);

// ---------------------------------------------------------------------
// 2 authors: only the first is inverted; the second is "First Last",
// joined by "and" — a comma before "and" even at exactly 2 (unlike
// Harvard, which never uses a comma before "and").
// ---------------------------------------------------------------------
check(
	'2 authors: only the first is inverted, joined by ", and "',
	Citex_MLA_Reference_Rules::build_reference( $BOOK, array( 'authors' => $two, 'title' => 'Digital Culture', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, Amy, and Ben Carter. Digital Culture. Routledge, 2021.'
);

// ---------------------------------------------------------------------
// 3+ authors: "et al." replaces every author after the first — the
// OPPOSITE of Harvard's "always list every author, never et al." rule.
// ---------------------------------------------------------------------
check(
	'3+ authors: "et al." replaces every author after the first',
	Citex_MLA_Reference_Rules::build_reference( $BOOK, array( 'authors' => $three, 'title' => 'Digital Culture', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, Amy, et al. Digital Culture. Routledge, 2021.'
);

// ---------------------------------------------------------------------
// join_people() directly, matching the three example shapes above.
// ---------------------------------------------------------------------
check( 'join_people(): 1 author', Citex_MLA_Reference_Rules::join_people( $one ), 'Smith, John.' );
check( 'join_people(): 2 authors', Citex_MLA_Reference_Rules::join_people( $two ), 'Ross, Amy, and Ben Carter.' );
check( 'join_people(): 3+ authors', Citex_MLA_Reference_Rules::join_people( $three ), 'Ross, Amy, et al.' );

// ---------------------------------------------------------------------
// No place of publication at all — publisher and year are simply
// comma-separated, with no "Place:" prefix anywhere in the output.
// ---------------------------------------------------------------------
$built = Citex_MLA_Reference_Rules::build_reference( $BOOK, array( 'authors' => $one, 'title' => 'The Great Adventure', 'publisher' => 'Penguin', 'year' => '2020' ) );
check( 'the reference never contains a colon (no "Place: Publisher" shape)', false !== strpos( $built, ':' ), false );

// ---------------------------------------------------------------------
// format_regex(): correctly accepts each of the three author-count
// shapes, and correctly rejects a Harvard-shaped reference (parenthetical
// year, initials).
// ---------------------------------------------------------------------
$regex = Citex_MLA_Reference_Rules::format_regex( $BOOK );
check( '1-author reference matches format_regex', 1 === preg_match( $regex, 'Smith, John. The Great Adventure. Penguin, 2020.' ), true );
check( '2-author reference matches format_regex', 1 === preg_match( $regex, 'Ross, Amy, and Ben Carter. Digital Culture. Routledge, 2021.' ), true );
check( '3+ author ("et al.") reference matches format_regex', 1 === preg_match( $regex, 'Ross, Amy, et al. Digital Culture. Routledge, 2021.' ), true );
check( 'a Harvard-shaped reference (parenthetical year, initials) does NOT match', 1 === preg_match( $regex, 'Smith, J. (2020) The Great Adventure. London: Penguin.' ), false );
check( 'a missing final full stop does NOT match', 1 === preg_match( $regex, 'Smith, John. The Great Adventure. Penguin, 2020' ), false );

// ---------------------------------------------------------------------
// id_prefix(): MLA Book uses its own "MB" prefix, distinct from Harvard's
// own "BK" — never colliding on the same pending-queue ID space.
// ---------------------------------------------------------------------
check( 'id_prefix uses "MB" for MLA Book', Citex_MLA_Reference_Rules::id_prefix( $BOOK ), 'MB' );
check( 'Harvard\'s own Book id_prefix is unaffected ("BK")', Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_BOOK ), 'BK' );

// ---------------------------------------------------------------------
// mcq_hint()/identify_error_hint() are MLA-specific wording, never
// silently reusing Harvard's own text. mcq_question_stem() deliberately
// never names the style at all — the student already knows which style
// they selected before generating the question.
// ---------------------------------------------------------------------
check( 'mcq_question_stem does not mention "MLA"', false !== stripos( Citex_MLA_Reference_Rules::mcq_question_stem( $BOOK ), 'MLA' ), false );
check( 'mcq_hint mentions "et al."', false !== strpos( Citex_MLA_Reference_Rules::mcq_hint( $BOOK ), 'et al.' ), true );
check( 'identify_error_hint mentions "first author"', false !== stripos( Citex_MLA_Reference_Rules::identify_error_hint( $BOOK ), 'first author' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
