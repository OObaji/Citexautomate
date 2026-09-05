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
// 3. dragdrop_shape() branches on author count: ONE author keeps the
// original 4-part shape (surname, initials, year, title as 4 SEPARATE
// draggable parts) — every existing single-author question is completely
// unaffected. TWO OR MORE authors use a 3-part shape (the whole joined
// author list as ONE draggable part, year, title).
// ---------------------------------------------------------------------
$shape_one = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_BOOK, array_merge( $base_fields, array( 'authors' => $one ) ) );
check( '[3] one author: exactly 4 parts (unchanged shape)', $shape_one['parts'], array( 'Smith', 'J.', '2020', 'Understanding digital culture' ) );
check( '[3] one author: fixedText is the original 4-placeholder template', $shape_one['fixedText'], '|, || (||) ||. London: SAGE Publications.' );

$shape_three = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_BOOK, array_merge( $base_fields, array( 'authors' => $three ) ) );
check( '[3] three authors: exactly 3 parts (only the first author individually, year, title)', $shape_three['parts'], array( 'Smith, J.', '2020', 'Understanding digital culture' ) );
check( '[3] three authors: fixedText folds the 2nd and 3rd authors in as a correct literal continuation', $shape_three['fixedText'], '||, Jones, P. and Brown, T. (||) ||. London: SAGE Publications.' );

// The reconstructed reference from EITHER shape must exactly match
// build_reference()'s own output — DragDrop and the correct MCQ answer can
// never silently disagree.
function reconstruct_from_shape( $shape ) {
	$fixed = $shape['fixedText'];
	$parts = $shape['parts'];
	$reference = '';
	$part_index = 0;
	$length = strlen( $fixed );
	for ( $i = 0; $i < $length; $i++ ) {
		if ( '|' !== $fixed[ $i ] ) {
			$reference .= $fixed[ $i ];
			continue;
		}
		if ( $i + 1 < $length && '|' === $fixed[ $i + 1 ] ) {
			$reference .= (string) $parts[ $part_index++ ];
			$i++;
			continue;
		}
		$reference .= (string) $parts[ $part_index++ ];
	}
	return trim( $reference );
}
check(
	'[3] one-author shape reconstructs to exactly build_reference()\'s output',
	reconstruct_from_shape( $shape_one ),
	Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_BOOK, array_merge( $base_fields, array( 'authors' => $one ) ) )
);
check(
	'[3] three-author shape reconstructs to exactly build_reference()\'s output',
	reconstruct_from_shape( $shape_three ),
	Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_BOOK, array_merge( $base_fields, array( 'authors' => $three ) ) )
);

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
