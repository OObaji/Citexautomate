<?php
/**
 * Regression tests for Citex_APA_Reference_Rules — APA's own Book reference
 * shape: `Surname, F. (Year). Title of the work. Publisher.`, initials
 * (never a full given name), two or more authors joined with "&" preceded
 * by a comma even at exactly two, every author always listed in full
 * ("et al." never used at any count this app generates), a full stop
 * immediately after the year's closing parenthesis, and no place of
 * publication at all. Pure, no WordPress/ACF dependency, so this file
 * needs no stub environment.
 *
 * Repo-level only, run with plain `php tests/reference-rules-apa-book.test.php`
 * — not shipped in citex-tools.zip.
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

$BOOK = Citex_APA_Reference_Rules::CATEGORY_BOOK;

$one   = array( array( 'surname' => 'Smith', 'initials' => 'J.', 'fullName' => 'John Smith' ) );
$two   = array( array( 'surname' => 'Ross', 'initials' => 'A.', 'fullName' => 'Amy Ross' ), array( 'surname' => 'Carter', 'initials' => 'B.', 'fullName' => 'Ben Carter' ) );
$three = array(
	array( 'surname' => 'Ross', 'initials' => 'A.', 'fullName' => 'Amy Ross' ),
	array( 'surname' => 'Carter', 'initials' => 'B.', 'fullName' => 'Ben Carter' ),
	array( 'surname' => 'Lee', 'initials' => 'K.', 'fullName' => 'Kim Lee' ),
);

// ---------------------------------------------------------------------
// 1 author: "Surname, F. (Year). Title. Publisher."
// ---------------------------------------------------------------------
check(
	'1 author matches the APA Book structure',
	Citex_APA_Reference_Rules::build_reference( $BOOK, array( 'authors' => $one, 'title' => 'Life among the giants', 'publisher' => 'Penguin', 'year' => '2020' ) ),
	'Smith, J. (2020). Life among the giants. Penguin.'
);

// ---------------------------------------------------------------------
// 2 authors: joined with "&", comma before it even at exactly 2 — unlike
// Harvard's plain "and" with no comma there.
// ---------------------------------------------------------------------
check(
	'2 authors: joined with ", & "',
	Citex_APA_Reference_Rules::build_reference( $BOOK, array( 'authors' => $two, 'title' => 'Digital culture', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, A., & Carter, B. (2021). Digital culture. Routledge.'
);

// ---------------------------------------------------------------------
// 3+ authors: every author is always listed in full, "&" before the
// last — the OPPOSITE of MLA's "et al. from 3+" rule, matching Harvard's
// "always list everyone" rule.
// ---------------------------------------------------------------------
check(
	'3+ authors: every author listed in full, "&" before the last',
	Citex_APA_Reference_Rules::build_reference( $BOOK, array( 'authors' => $three, 'title' => 'Digital culture', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Ross, A., Carter, B., & Lee, K. (2021). Digital culture. Routledge.'
);
check( 'the 3+ author reference never contains "et al."', false !== stripos( Citex_APA_Reference_Rules::build_reference( $BOOK, array( 'authors' => $three, 'title' => 'Digital culture', 'publisher' => 'Routledge', 'year' => '2021' ) ), 'et al' ), false );

// ---------------------------------------------------------------------
// join_people() directly, matching the three example shapes above.
// ---------------------------------------------------------------------
check( 'join_people(): 1 author', Citex_APA_Reference_Rules::join_people( $one ), 'Smith, J.' );
check( 'join_people(): 2 authors', Citex_APA_Reference_Rules::join_people( $two ), 'Ross, A., & Carter, B.' );
check( 'join_people(): 3+ authors', Citex_APA_Reference_Rules::join_people( $three ), 'Ross, A., Carter, B., & Lee, K.' );

// ---------------------------------------------------------------------
// A full stop sits immediately after the year's closing parenthesis —
// the single most APA-distinctive structural rule, absent from Harvard's
// own Book format.
// ---------------------------------------------------------------------
$built = Citex_APA_Reference_Rules::build_reference( $BOOK, array( 'authors' => $one, 'title' => 'Life among the giants', 'publisher' => 'Penguin', 'year' => '2020' ) );
check( 'a full stop follows the year\'s closing parenthesis', false !== strpos( $built, '(2020).' ), true );
check( 'the reference never contains a colon (no "Place: Publisher" shape)', false !== strpos( $built, ':' ), false );

// ---------------------------------------------------------------------
// format_regex(): correctly accepts each of the three author-count
// shapes, and correctly rejects a Harvard-shaped reference (no period
// after the year) and an MLA-shaped one (year at the end, no parens).
// ---------------------------------------------------------------------
$regex = Citex_APA_Reference_Rules::format_regex( $BOOK );
check( '1-author reference matches format_regex', 1 === preg_match( $regex, 'Smith, J. (2020). Life among the giants. Penguin.' ), true );
check( '2-author reference matches format_regex', 1 === preg_match( $regex, 'Ross, A., & Carter, B. (2021). Digital culture. Routledge.' ), true );
check( '3+ author reference matches format_regex', 1 === preg_match( $regex, 'Ross, A., Carter, B., & Lee, K. (2021). Digital culture. Routledge.' ), true );
check( 'a Harvard-shaped reference (no period after year) does NOT match', 1 === preg_match( $regex, 'Smith, J. (2020) Life among the giants. Penguin.' ), false );
check( 'an MLA-shaped reference (year at the end, no parens) does NOT match', 1 === preg_match( $regex, 'Smith, John. Life among the giants. Penguin, 2020.' ), false );
check( 'a "&" with no comma before it does NOT match', 1 === preg_match( $regex, 'Ross, A. & Carter, B. (2021). Digital culture. Routledge.' ), false );
check( 'a plain "and" instead of "&" does NOT match', 1 === preg_match( $regex, 'Ross, A., and Carter, B. (2021). Digital culture. Routledge.' ), false );
check( 'a missing final full stop does NOT match', 1 === preg_match( $regex, 'Smith, J. (2020). Life among the giants. Penguin' ), false );

// ---------------------------------------------------------------------
// id_prefix(): APA Book uses its own "AB" prefix, distinct from Harvard's
// "BK" and MLA's "MB" — never colliding on the same pending-queue ID
// space.
// ---------------------------------------------------------------------
check( 'id_prefix uses "AB" for APA Book', Citex_APA_Reference_Rules::id_prefix( $BOOK ), 'AB' );
check( 'Harvard\'s own Book id_prefix is unaffected ("BK")', Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_BOOK ), 'BK' );

// ---------------------------------------------------------------------
// mcq_hint()/identify_error_hint() are APA-specific wording, never
// silently reusing Harvard's or MLA's own text. mcq_question_stem()
// deliberately never names the style at all — the student already knows
// which style they selected before generating the question.
// ---------------------------------------------------------------------
check( 'mcq_question_stem does not mention "APA"', false !== stripos( Citex_APA_Reference_Rules::mcq_question_stem( $BOOK ), 'APA' ), false );
check( 'mcq_hint mentions "&"', false !== strpos( Citex_APA_Reference_Rules::mcq_hint( $BOOK ), '&' ), true );
check( 'identify_error_hint mentions "initials"', false !== stripos( Citex_APA_Reference_Rules::identify_error_hint( $BOOK ), 'initials' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
