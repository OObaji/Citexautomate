<?php
/**
 * Regression tests for Citex_Reference_Rules' multi-author Book support —
 * Liverpool Hope's confirmed reference-list rule: every author is always
 * listed in full (1, 2, 3, 4+ — no upper cutoff), comma-separated with a
 * final "and" before the last author, and "et al." is NEVER used in a
 * reference-list entry ("et al." is Liverpool Hope's separate, unrelated
 * in-text-citation convention, which Citex does not generate). Pure, no
 * WordPress/ACF dependency at all, so this file needs no stub environment.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-book-authors.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';

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

function author( $surname, $initials ) {
	return array( 'surname' => $surname, 'initials' => $initials );
}

$one   = array( author( 'Smith', 'J.' ) );
$two   = array( author( 'Smith', 'J.' ), author( 'Jones', 'P.' ) );
$three = array( author( 'Smith', 'J.' ), author( 'Jones', 'P.' ), author( 'Brown', 'T.' ) );
$four  = array( author( 'Smith', 'J.' ), author( 'Jones', 'P.' ), author( 'Brown', 'T.' ), author( 'Williams', 'R.' ) );
$six   = array_merge( $four, array( author( 'Davies', 'K.' ), author( 'Evans', 'M.' ) ) );

// ---------------------------------------------------------------------
// 1. join_people() joins 1/2/3/4/6 authors exactly the way join_editors()
// already joins editors — same algorithm, shared code.
// ---------------------------------------------------------------------
check( '[1] one author', Citex_Reference_Rules::join_people( $one ), 'Smith, J.' );
check( '[1] two authors joined with "and"', Citex_Reference_Rules::join_people( $two ), 'Smith, J. and Jones, P.' );
check( '[1] three authors: commas then a final "and"', Citex_Reference_Rules::join_people( $three ), 'Smith, J., Jones, P. and Brown, T.' );
check( '[1] four authors: still every author, commas then a final "and" — no et-al cutoff', Citex_Reference_Rules::join_people( $four ), 'Smith, J., Jones, P., Brown, T. and Williams, R.' );
check( '[1] six authors: still every author listed in full', Citex_Reference_Rules::join_people( $six ), 'Smith, J., Jones, P., Brown, T., Williams, R., Davies, K. and Evans, M.' );

// ---------------------------------------------------------------------
// 2. build_reference() for Book — matches the user's own confirmed
// Liverpool Hope examples exactly, for 1/2/3/4 authors.
// ---------------------------------------------------------------------
$base_fields = array( 'year' => '2020', 'title' => 'Understanding digital culture', 'place' => 'London', 'publisher' => 'SAGE Publications' );
check(
	'[2] 1 author matches the confirmed Liverpool Hope example',
	Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_BOOK, array_merge( $base_fields, array( 'authors' => $one ) ) ),
	'Smith, J. (2020) Understanding digital culture. London: SAGE Publications.'
);
check(
	'[2] 2 authors matches the confirmed Liverpool Hope example',
	Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_BOOK, array_merge( $base_fields, array( 'authors' => $two ) ) ),
	'Smith, J. and Jones, P. (2020) Understanding digital culture. London: SAGE Publications.'
);
check(
	'[2] 3 authors matches the confirmed Liverpool Hope example',
	Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_BOOK, array_merge( $base_fields, array( 'authors' => $three ) ) ),
	'Smith, J., Jones, P. and Brown, T. (2020) Understanding digital culture. London: SAGE Publications.'
);
check(
	'[2] 4+ authors matches the confirmed Liverpool Hope example — ALL authors, no "et al."',
	Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_BOOK, array_merge( $base_fields, array( 'authors' => $four ) ) ),
	'Smith, J., Jones, P., Brown, T. and Williams, R. (2020) Understanding digital culture. London: SAGE Publications.'
);
check(
	'[2] "et al." never appears for any author count, including 6',
	false !== strpos( Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_BOOK, array_merge( $base_fields, array( 'authors' => $six ) ) ), 'et al' ),
	false
);

// ---------------------------------------------------------------------
// 3. Book's DragDrop shape (which of these authors gets drawn as parts,
// and how) is no longer built by Citex_Reference_Rules::dragdrop_shape()
// at all — see tests/reference-rules-book-dragdrop-parts.test.php for
// Citex_Book_Dragdrop_Parts's own dedicated coverage of that dynamic,
// per-question mechanism.
// ---------------------------------------------------------------------

// ---------------------------------------------------------------------
// 4. format_regex() for Book: a real repeating group, not `.+` — accepts
// any author count joined correctly, rejects "et al.", rejects "&"
// joining, and CRITICALLY rejects comma-joining all the way through with
// no "and" before the final author (a real Harvard style violation the
// regex must not silently accept as "looks correct").
// ---------------------------------------------------------------------
$book_regex = Citex_Reference_Rules::format_regex( Citex_Reference_Rules::CATEGORY_BOOK );
check( '[4] 1 author matches', 1 === preg_match( $book_regex, 'Smith, J. (2020) Understanding digital culture. London: SAGE Publications.' ), true );
check( '[4] 2 authors match', 1 === preg_match( $book_regex, 'Smith, J. and Jones, P. (2020) Understanding digital culture. London: SAGE Publications.' ), true );
check( '[4] 3 authors match', 1 === preg_match( $book_regex, 'Smith, J., Jones, P. and Brown, T. (2020) Understanding digital culture. London: SAGE Publications.' ), true );
check( '[4] 4 authors match', 1 === preg_match( $book_regex, 'Smith, J., Jones, P., Brown, T. and Williams, R. (2020) Understanding digital culture. London: SAGE Publications.' ), true );
check( '[4] "et al." does NOT match — never valid in the reference list', 1 === preg_match( $book_regex, 'Smith et al. (2020) Understanding digital culture. London: SAGE Publications.' ), false );
check( '[4] "&" joining does NOT match', 1 === preg_match( $book_regex, 'Smith, J. & Jones, P. (2020) Understanding digital culture. London: SAGE Publications.' ), false );
check( '[4] comma-joined throughout with no final "and" (2 authors) does NOT match', 1 === preg_match( $book_regex, 'Smith, J., Jones, P. (2020) Understanding digital culture. London: SAGE Publications.' ), false );
check( '[4] comma-joined throughout with no final "and" (3 authors) does NOT match', 1 === preg_match( $book_regex, 'Smith, J., Jones, P., Brown, T. (2020) Understanding digital culture. London: SAGE Publications.' ), false );

// ---------------------------------------------------------------------
// 5. The distractor-pattern catalogue explicitly names multi-author
// mistakes, including the "et al. in the reference list" confusion this
// scenario exists to test (see the user's own worked example).
// ---------------------------------------------------------------------
$book_patterns = Citex_Reference_Rules::mcq_distractor_patterns( Citex_Reference_Rules::CATEGORY_BOOK );
$joined_patterns = implode( ' ', $book_patterns );
check( '[5] the catalogue mentions "et al."', false !== stripos( $joined_patterns, 'et al' ), true );
check( '[5] the catalogue mentions joining with "&"', false !== strpos( $joined_patterns, '&' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
