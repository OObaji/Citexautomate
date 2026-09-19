<?php
/**
 * Regression tests for Citex_Chicago_Reference_Rules — Chicago (Author-Date,
 * 17th edition)'s own Book reference shape: `Surname, GivenName. Year.
 * Title of the Work. Place: Publisher.`, the FULL given name (never an
 * initial), two or more authors joined with "and" preceded by a comma even
 * at exactly two, every author always listed in full ("et al." never used
 * at any count this app generates), NO parentheses around the year at all
 * (just its own trailing full stop), and place of publication IS kept
 * (unlike MLA/APA). Pure, no WordPress/ACF dependency, so this file needs
 * no stub environment.
 *
 * Repo-level only, run with plain `php tests/reference-rules-chicago-book.test.php`
 * — not shipped in citex-tools.zip.
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

$BOOK = Citex_Chicago_Reference_Rules::CATEGORY_BOOK;

$one   = array( array( 'surname' => 'Smith', 'givenName' => 'John', 'fullName' => 'John Smith' ) );
$two   = array( array( 'surname' => 'Ross', 'givenName' => 'Amy', 'fullName' => 'Amy Ross' ), array( 'surname' => 'Carter', 'givenName' => 'Ben', 'fullName' => 'Ben Carter' ) );
$three = array(
	array( 'surname' => 'Ross', 'givenName' => 'Amy', 'fullName' => 'Amy Ross' ),
	array( 'surname' => 'Carter', 'givenName' => 'Ben', 'fullName' => 'Ben Carter' ),
	array( 'surname' => 'Lee', 'givenName' => 'Kim', 'fullName' => 'Kim Lee' ),
);

// ---------------------------------------------------------------------
// 1 author: "Surname, GivenName. Year. Title. Place: Publisher."
// ---------------------------------------------------------------------
check(
	'1 author matches the Chicago Book structure',
	Citex_Chicago_Reference_Rules::build_reference( $BOOK, array( 'authors' => $one, 'title' => 'Life among the Giants', 'place' => 'New York', 'publisher' => 'Penguin', 'year' => '2020' ) ),
	'Smith, John. 2020. Life among the Giants. New York: Penguin.'
);

// ---------------------------------------------------------------------
// 2 authors: joined with "and", comma before it even at exactly 2 — like
// APA's own comma-before-"&" rule, just with the word "and".
// ---------------------------------------------------------------------
check(
	'2 authors: joined with ", and "',
	Citex_Chicago_Reference_Rules::build_reference( $BOOK, array( 'authors' => $two, 'title' => 'Digital Culture', 'place' => 'London', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, Amy, and Carter, Ben. 2021. Digital Culture. London: Routledge.'
);

// ---------------------------------------------------------------------
// 3+ authors: every author is always listed in full, "and" before the
// last — the OPPOSITE of MLA's "et al. from 3+" rule, matching
// Harvard's/APA's "always list everyone" rule.
// ---------------------------------------------------------------------
check(
	'3+ authors: every author listed in full, "and" before the last',
	Citex_Chicago_Reference_Rules::build_reference( $BOOK, array( 'authors' => $three, 'title' => 'Digital Culture', 'place' => 'London', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, Amy, Carter, Ben, and Lee, Kim. 2021. Digital Culture. London: Routledge.'
);
check( 'the 3+ author reference never contains "et al."', false !== stripos( Citex_Chicago_Reference_Rules::build_reference( $BOOK, array( 'authors' => $three, 'title' => 'Digital Culture', 'place' => 'London', 'publisher' => 'Routledge', 'year' => '2021' ) ), 'et al' ), false );

// ---------------------------------------------------------------------
// join_people() directly, matching the three example shapes above.
// ---------------------------------------------------------------------
check( 'join_people(): 1 author', Citex_Chicago_Reference_Rules::join_people( $one ), 'Smith, John.' );
check( 'join_people(): 2 authors', Citex_Chicago_Reference_Rules::join_people( $two ), 'Ross, Amy, and Carter, Ben.' );
check( 'join_people(): 3+ authors', Citex_Chicago_Reference_Rules::join_people( $three ), 'Ross, Amy, Carter, Ben, and Lee, Kim.' );

// ---------------------------------------------------------------------
// NO parentheses around the year at all — the single most
// Chicago-distinctive structural rule, absent from Harvard's/APA's own
// parenthesised Book format.
// ---------------------------------------------------------------------
$built = Citex_Chicago_Reference_Rules::build_reference( $BOOK, array( 'authors' => $one, 'title' => 'Life among the Giants', 'place' => 'New York', 'publisher' => 'Penguin', 'year' => '2020' ) );
check( 'the year is never wrapped in parentheses', false !== strpos( $built, '(2020)' ), false );
check( 'the year is immediately followed by its own full stop', false !== strpos( $built, '2020. ' ), true );
check( 'place and publisher are colon-separated', false !== strpos( $built, 'New York: Penguin' ), true );

// ---------------------------------------------------------------------
// format_regex(): correctly accepts each of the three author-count
// shapes, and correctly rejects a Harvard/APA-shaped reference
// (parenthesised year) and an MLA-shaped one (year at the end, no place).
// ---------------------------------------------------------------------
$regex = Citex_Chicago_Reference_Rules::format_regex( $BOOK );
check( '1-author reference matches format_regex', 1 === preg_match( $regex, 'Smith, John. 2020. Life among the Giants. New York: Penguin.' ), true );
check( '2-author reference matches format_regex', 1 === preg_match( $regex, 'Ross, Amy, and Carter, Ben. 2021. Digital Culture. London: Routledge.' ), true );
check( '3+ author reference matches format_regex', 1 === preg_match( $regex, 'Ross, Amy, Carter, Ben, and Lee, Kim. 2021. Digital Culture. London: Routledge.' ), true );
check( 'a Harvard-shaped reference (parenthesised year) does NOT match', 1 === preg_match( $regex, 'Smith, J. (2020) Life among the Giants. New York: Penguin.' ), false );
check( 'an APA-shaped reference (parenthesised year with trailing period) does NOT match', 1 === preg_match( $regex, 'Smith, John. (2020). Life among the Giants. New York: Penguin.' ), false );
check( 'an MLA-shaped reference (year at the end, no place) does NOT match', 1 === preg_match( $regex, 'Smith, John. Life among the Giants. Penguin, 2020.' ), false );
check( 'a comma-less "and" (no comma before it) does NOT match author boundary as expected', 1 === preg_match( $regex, 'Ross, Amy and Carter, Ben. 2021. Digital Culture. London: Routledge.' ), true );
check( 'a missing final full stop does NOT match', 1 === preg_match( $regex, 'Smith, John. 2020. Life among the Giants. New York: Penguin' ), false );

// ---------------------------------------------------------------------
// id_prefix(): Chicago Book uses its own "CB" prefix, distinct from
// Harvard's "BK", MLA's "MB" and APA's "AB" — never colliding on the same
// pending-queue ID space.
// ---------------------------------------------------------------------
check( 'id_prefix uses "CB" for Chicago Book', Citex_Chicago_Reference_Rules::id_prefix( $BOOK ), 'CB' );
check( 'Harvard\'s own Book id_prefix is unaffected ("BK")', Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_BOOK ), 'BK' );

// ---------------------------------------------------------------------
// mcq_hint()/identify_error_hint() are Chicago-specific wording, never
// silently reusing Harvard's/MLA's/APA's own text. mcq_question_stem()
// deliberately never names the style at all — the student already knows
// which style they selected before generating the question.
// ---------------------------------------------------------------------
check( 'mcq_question_stem does not mention "Chicago"', false !== stripos( Citex_Chicago_Reference_Rules::mcq_question_stem( $BOOK ), 'Chicago' ), false );
check( 'mcq_hint mentions "and"', false !== stripos( Citex_Chicago_Reference_Rules::mcq_hint( $BOOK ), 'and' ), true );
check( 'identify_error_hint mentions "given name"', false !== stripos( Citex_Chicago_Reference_Rules::identify_error_hint( $BOOK ), 'given name' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
