<?php
/**
 * Regression tests for Citex_MHRA_Reference_Rules — MHRA (11th edition)'s
 * own Book Bibliography reference shape: `Surname, First, Title of the
 * Work (Place: Publisher, Year).`, the FULL given name (never an initial),
 * only the FIRST author inverted (every author after keeps natural word
 * order), every author always listed in full ("et al." never used at any
 * count this app generates), a comma before "and" even at exactly two
 * authors, and place, publisher AND year all sitting together inside ONE
 * parenthesis. Pure, no WordPress/ACF dependency, so this file needs no
 * stub environment.
 *
 * Repo-level only, run with plain `php tests/reference-rules-mhra-book.test.php`
 * — not shipped in citex-tools.zip.
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

$BOOK = Citex_MHRA_Reference_Rules::CATEGORY_BOOK;

$one   = array( array( 'surname' => 'Smith', 'givenName' => 'John', 'fullName' => 'John Smith' ) );
$two   = array( array( 'surname' => 'Smith', 'givenName' => 'John', 'fullName' => 'John Smith' ), array( 'surname' => 'Ross', 'givenName' => 'Amy', 'fullName' => 'Amy Ross' ) );
$three = array(
	array( 'surname' => 'Smith', 'givenName' => 'John', 'fullName' => 'John Smith' ),
	array( 'surname' => 'Ross', 'givenName' => 'Amy', 'fullName' => 'Amy Ross' ),
	array( 'surname' => 'Carter', 'givenName' => 'Ben', 'fullName' => 'Ben Carter' ),
);

// ---------------------------------------------------------------------
// 1 author: "Surname, First, Title (Place: Publisher, Year)."
// ---------------------------------------------------------------------
check(
	'1 author matches the MHRA Book structure',
	Citex_MHRA_Reference_Rules::build_reference( $BOOK, array( 'authors' => $one, 'title' => 'Life among the Giants', 'place' => 'New York', 'publisher' => 'Penguin', 'year' => '2020' ) ),
	'Smith, John, Life among the Giants (New York: Penguin, 2020).'
);

// ---------------------------------------------------------------------
// 2 authors: only the first is inverted; the second keeps natural word
// order; comma before "and" even at exactly 2.
// ---------------------------------------------------------------------
check(
	'2 authors: only the first inverted, second in natural word order',
	Citex_MHRA_Reference_Rules::build_reference( $BOOK, array( 'authors' => $two, 'title' => 'Digital Culture', 'place' => 'London', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Smith, John, and Amy Ross, Digital Culture (London: Routledge, 2021).'
);

// ---------------------------------------------------------------------
// 3+ authors: every author after the first stays in natural word order,
// every author listed in full, "and" before the last — never "et al.".
// ---------------------------------------------------------------------
check(
	'3+ authors: only the first inverted, everyone else natural order, "and" before the last',
	Citex_MHRA_Reference_Rules::build_reference( $BOOK, array( 'authors' => $three, 'title' => 'Digital Culture', 'place' => 'London', 'publisher' => 'Routledge', 'year' => '2021' ) ),
	'Smith, John, Amy Ross, and Ben Carter, Digital Culture (London: Routledge, 2021).'
);
check( 'the 3+ author reference never contains "et al."', false !== stripos( Citex_MHRA_Reference_Rules::build_reference( $BOOK, array( 'authors' => $three, 'title' => 'Digital Culture', 'place' => 'London', 'publisher' => 'Routledge', 'year' => '2021' ) ), 'et al' ), false );

// ---------------------------------------------------------------------
// join_people() directly, matching the three example shapes above.
// ---------------------------------------------------------------------
check( 'join_people(): 1 author', Citex_MHRA_Reference_Rules::join_people( $one ), 'Smith, John' );
check( 'join_people(): 2 authors', Citex_MHRA_Reference_Rules::join_people( $two ), 'Smith, John, and Amy Ross' );
check( 'join_people(): 3+ authors', Citex_MHRA_Reference_Rules::join_people( $three ), 'Smith, John, Amy Ross, and Ben Carter' );

// ---------------------------------------------------------------------
// Place, publisher AND year all sit together inside ONE parenthesis — the
// single most MHRA-distinctive structural rule, unlike Chicago's own Book
// format, which keeps the year in its own separate segment.
// ---------------------------------------------------------------------
$built = Citex_MHRA_Reference_Rules::build_reference( $BOOK, array( 'authors' => $one, 'title' => 'Life among the Giants', 'place' => 'New York', 'publisher' => 'Penguin', 'year' => '2020' ) );
check( 'place, publisher and year sit together inside one parenthesis', false !== strpos( $built, '(New York: Penguin, 2020)' ), true );
check( 'there is only one opening parenthesis in the reference', substr_count( $built, '(' ), 1 );
check( 'there is only one closing parenthesis in the reference', substr_count( $built, ')' ), 1 );

// ---------------------------------------------------------------------
// format_regex(): correctly accepts each of the three author-count
// shapes, and correctly rejects a Chicago-shaped reference (year outside
// the parenthesis) and an MLA/APA-shaped one.
// ---------------------------------------------------------------------
$regex = Citex_MHRA_Reference_Rules::format_regex( $BOOK );
check( '1-author reference matches format_regex', 1 === preg_match( $regex, 'Smith, John, Life among the Giants (New York: Penguin, 2020).' ), true );
check( '2-author reference matches format_regex', 1 === preg_match( $regex, 'Smith, John, and Amy Ross, Digital Culture (London: Routledge, 2021).' ), true );
check( '3+ author reference matches format_regex', 1 === preg_match( $regex, 'Smith, John, Amy Ross, and Ben Carter, Digital Culture (London: Routledge, 2021).' ), true );
check( 'a Chicago-shaped reference (year outside the parenthesis) does NOT match', 1 === preg_match( $regex, 'Smith, John. 2020. Life among the Giants. New York: Penguin.' ), false );
check( 'an APA-shaped reference (parenthesised year alone) does NOT match', 1 === preg_match( $regex, 'Smith, J. (2020). Life among the giants. Penguin.' ), false );
check( 'a missing final full stop does NOT match', 1 === preg_match( $regex, 'Smith, John, Life among the Giants (New York: Penguin, 2020)' ), false );

// ---------------------------------------------------------------------
// id_prefix(): MHRA Book uses its own "HB" prefix, distinct from Harvard's
// "BK", MLA's "MB", APA's "AB" and Chicago's "CB" — never colliding on the
// same pending-queue ID space.
// ---------------------------------------------------------------------
check( 'id_prefix uses "HB" for MHRA Book', Citex_MHRA_Reference_Rules::id_prefix( $BOOK ), 'HB' );
check( 'Harvard\'s own Book id_prefix is unaffected ("BK")', Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_BOOK ), 'BK' );

// ---------------------------------------------------------------------
// mcq_question_stem()/mcq_hint()/identify_error_hint() are MHRA-specific
// wording, never silently reusing another style's own text.
// ---------------------------------------------------------------------
check( 'mcq_question_stem mentions "MHRA"', false !== stripos( Citex_MHRA_Reference_Rules::mcq_question_stem( $BOOK ), 'MHRA' ), true );
check( 'mcq_hint mentions "inverted"', false !== stripos( Citex_MHRA_Reference_Rules::mcq_hint( $BOOK ), 'inverted' ), true );
check( 'identify_error_hint mentions "parentheses"', false !== stripos( Citex_MHRA_Reference_Rules::identify_error_hint( $BOOK ), 'parentheses' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
