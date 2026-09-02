<?php
/**
 * Regression tests for Citex_Reference_Rules — the pluggable category-rules
 * layer added for Edited Books, alongside Book. Pure, no WordPress/ACF
 * dependency at all, so this file needs no stub environment.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-edited-book.test.php` — not shipped in
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

// ---------------------------------------------------------------------
// 1. Single-editor reference matches the exact example from the spec.
// ---------------------------------------------------------------------
$one_editor = array(
	'editors'   => array( array( 'surname' => 'Smith', 'initials' => 'J.' ) ),
	'year'      => '2022',
	'title'     => 'Digital media and society',
	'place'     => 'London',
	'publisher' => 'SAGE Publications',
);
check(
	'[1] one editor uses "(ed.)" and matches the spec\'s exact example',
	Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $one_editor ),
	'Smith, J. (ed.) (2022) Digital media and society. London: SAGE Publications.'
);

// ---------------------------------------------------------------------
// 2. Two-editor reference uses "(eds)" and "and"-joins the editors —
// matches the spec's exact example.
// ---------------------------------------------------------------------
$two_editors = array(
	'editors'   => array(
		array( 'surname' => 'Smith', 'initials' => 'J.' ),
		array( 'surname' => 'Jones', 'initials' => 'A.' ),
	),
	'year'      => '2022',
	'title'     => 'Digital media and society',
	'place'     => 'London',
	'publisher' => 'SAGE Publications',
);
check(
	'[2] two editors use "(eds)" and are "and"-joined, matching the spec\'s exact example',
	Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $two_editors ),
	'Smith, J. and Jones, A. (eds) (2022) Digital media and society. London: SAGE Publications.'
);

// ---------------------------------------------------------------------
// 3. designation_for_editor_count(): "(ed.)" is never used for 2+ editors,
// "(eds)" is never used for exactly 1 — explicitly required by the spec
// ("must not accidentally use (ed.) for a book with multiple editors —
// this should be explicitly tested").
// ---------------------------------------------------------------------
check( '[3] 1 editor -> ed.', Citex_Reference_Rules::designation_for_editor_count( 1 ), 'ed.' );
check( '[3] 2 editors -> eds', Citex_Reference_Rules::designation_for_editor_count( 2 ), 'eds' );
check( '[3] 3 editors -> eds', Citex_Reference_Rules::designation_for_editor_count( 3 ), 'eds' );

// ---------------------------------------------------------------------
// 4. join_editors(): three-or-more editors use Harvard's standard
// comma-list-with-final-"and" joining.
// ---------------------------------------------------------------------
$three_editors = array(
	array( 'surname' => 'Smith', 'initials' => 'J.' ),
	array( 'surname' => 'Jones', 'initials' => 'A.' ),
	array( 'surname' => 'Lee', 'initials' => 'K.' ),
);
check( '[4] three editors are comma-joined with a final "and"', Citex_Reference_Rules::join_editors( $three_editors ), 'Smith, J., Jones, A. and Lee, K.' );

// ---------------------------------------------------------------------
// 5. Book's own reference construction (single-author case) is untouched
// by adding Edited Book / multi-author support — see
// reference-rules-book-authors.test.php for the multi-author cases.
// ---------------------------------------------------------------------
$book_fields = array( 'authors' => array( array( 'surname' => 'Bryman', 'initials' => 'A.' ) ), 'year' => '2012', 'title' => 'Social Research Methods', 'place' => 'Oxford', 'publisher' => 'Oxford University Press' );
check(
	'[5] Book reference construction is unaffected',
	Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_BOOK, $book_fields ),
	'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.'
);

// ---------------------------------------------------------------------
// 6. DragDrop shape: exactly 4 draggable parts (editors-joined,
// designation, year, title), place/publisher fixed — same piece COUNT as
// Book, matching the plugin's existing convention that place/publisher are
// never draggable for any category.
// ---------------------------------------------------------------------
$shape_one = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $one_editor );
check( '[6] one-editor DragDrop shape has exactly 4 parts', count( $shape_one['parts'] ), 4 );
check( '[6] one-editor DragDrop parts are [editorsJoined, designation, year, title]', $shape_one['parts'], array( 'Smith, J.', 'ed.', '2022', 'Digital media and society' ) );
check( '[6] one-editor fixedText bakes in place/publisher, matches the reconstruction rules', $shape_one['fixedText'], '| (||) (||) ||. London: SAGE Publications.' );

$shape_two = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $two_editors );
check( '[6] two-editor DragDrop shape uses "eds"', $shape_two['parts'][1], 'eds' );
check( '[6] two-editor DragDrop shape joins both editors into the first part', $shape_two['parts'][0], 'Smith, J. and Jones, A.' );

// ---------------------------------------------------------------------
// 7. The DragDrop shape reconstructs (by literal pipe substitution,
// mirroring how Citex_Generated_Validator::reconstruct() and the live
// student app both read Fixed Text/Question Parts) to exactly the same
// string build_reference() produces — the two must never disagree.
// ---------------------------------------------------------------------
function reconstruct_from_shape( $shape ) {
	$fixed = $shape['fixedText'];
	$parts = $shape['parts'];
	$result = '';
	$partIndex = 0;
	$i = 0;
	$len = strlen( $fixed );
	while ( $i < $len ) {
		if ( '|' === $fixed[ $i ] ) {
			if ( $i + 1 < $len && '|' === $fixed[ $i + 1 ] ) {
				$result .= $parts[ $partIndex++ ];
				$i += 2;
				continue;
			}
			$result .= $parts[ $partIndex++ ];
			$i++;
			continue;
		}
		$result .= $fixed[ $i ];
		$i++;
	}
	return $result;
}
check( '[7] one-editor shape reconstructs to exactly build_reference()\'s output', reconstruct_from_shape( $shape_one ), Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $one_editor ) );
check( '[7] two-editor shape reconstructs to exactly build_reference()\'s output', reconstruct_from_shape( $shape_two ), Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $two_editors ) );

// ---------------------------------------------------------------------
// 8. format_regex(): the correct references pass; common mistakes from the
// spec (missing "(ed.)", "(eds.)" with a stray period, "(ed)" missing its
// period) fail.
// ---------------------------------------------------------------------
$regex = Citex_Reference_Rules::format_regex( Citex_Reference_Rules::CATEGORY_EDITED_BOOK );
check( '[8] the correct one-editor reference matches the Edited Book format regex', 1 === preg_match( $regex, 'Smith, J. (ed.) (2022) Digital media and society. London: SAGE Publications.' ), true );
check( '[8] the correct two-editor reference matches the Edited Book format regex', 1 === preg_match( $regex, 'Smith, J. and Jones, A. (eds) (2022) Digital media and society. London: SAGE Publications.' ), true );
check( '[8] a reference with the editor designation missing entirely does NOT match', 1 === preg_match( $regex, 'Smith, J. (2022) Digital media and society. London: SAGE Publications.' ), false );
check( '[8] "(eds.)" with a stray trailing period does NOT match', 1 === preg_match( $regex, 'Smith, J. (eds.) (2022) Digital media and society. London: SAGE Publications.' ), false );
check( '[8] "(ed)" missing its period does NOT match', 1 === preg_match( $regex, 'Smith, J. (ed) (2022) Digital media and society. London: SAGE Publications.' ), false );

// A Book-shaped reference (no designation at all) must not accidentally
// satisfy the Edited Book regex either — the two categories must stay
// genuinely distinguishable.
check( '[8] a plain Book-format reference does not satisfy the Edited Book regex', 1 === preg_match( $regex, 'Bryman, A. (2012) Social Research Methods. Oxford: Oxford University Press.' ), false );

// And the reverse: an Edited Book reference must not satisfy Book's regex.
$book_regex = Citex_Reference_Rules::format_regex( Citex_Reference_Rules::CATEGORY_BOOK );
check( '[8] an Edited Book reference does not satisfy the plain Book format regex either', 1 === preg_match( $book_regex, 'Smith, J. (ed.) (2022) Digital media and society. London: SAGE Publications.' ), false );

// ---------------------------------------------------------------------
// 9. mcq_distractor_patterns() — the category-specific "common mistakes"
// catalogue Citex_AI_V2's MCQ prompts build distractor instructions from.
// Every category must supply a non-empty list of distinct, non-empty
// pattern descriptions, and Book vs Edited Book must differ (each
// category's patterns are its own, not a shared generic list).
// ---------------------------------------------------------------------
$book_patterns = Citex_Reference_Rules::mcq_distractor_patterns( Citex_Reference_Rules::CATEGORY_BOOK );
$edited_book_patterns = Citex_Reference_Rules::mcq_distractor_patterns( Citex_Reference_Rules::CATEGORY_EDITED_BOOK );
check( '[9] Book has at least one distractor pattern', count( $book_patterns ) > 0, true );
check( '[9] Edited Book has at least one distractor pattern', count( $edited_book_patterns ) > 0, true );
check( '[9] every Book pattern is a non-empty string', count( array_filter( $book_patterns, function ( $p ) { return is_string( $p ) && '' !== trim( $p ); } ) ), count( $book_patterns ) );
check( '[9] every Edited Book pattern is a non-empty string', count( array_filter( $edited_book_patterns, function ( $p ) { return is_string( $p ) && '' !== trim( $p ); } ) ), count( $edited_book_patterns ) );
check( '[9] Book and Edited Book have their own distinct pattern lists', $book_patterns === $edited_book_patterns, false );
check( '[9] Edited Book patterns specifically mention the designation rule this category tests', count( array_filter( $edited_book_patterns, function ( $p ) { return false !== stripos( $p, 'ed.' ) || false !== stripos( $p, 'eds' ); } ) ) > 0, true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
