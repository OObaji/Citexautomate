<?php
/**
 * Regression tests for Citex_Reference_Rules' Journal Article support —
 * Liverpool Hope's confirmed format: Author surname(s), initial(s). (Year)
 * Article title. Journal title, Volume(Issue), pp.xx-xx. ALL authors are
 * always listed in full (1, 2, 3, 4, 5+ — no upper cutoff, and unlike Book
 * this is tracked as FIVE distinct buckets, not four), comma-separated with
 * a final "and" before the last author, and "et al." is NEVER used. Unlike
 * Book/Edited Book, there is no place/publisher concept, and the DragDrop
 * shape is a CONSTANT 7 parts for every author count (never a single-author
 * special case). Pure, no WordPress/ACF dependency, so this file needs no
 * stub environment.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-journal-article.test.php` — not shipped in
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

$one   = array( author( 'Mitchell', 'S.' ) );
$two   = array( author( 'Mitchell', 'S.' ), author( 'Evans', 'D.' ) );
$three = array( author( 'Mitchell', 'S.' ), author( 'Evans', 'D.' ), author( 'Brown', 'T.' ) );
$four  = array( author( 'Mitchell', 'S.' ), author( 'Evans', 'D.' ), author( 'Brown', 'T.' ), author( 'Williams', 'R.' ) );
$five  = array_merge( $four, array( author( 'Davies', 'K.' ) ) );

$base_fields = array(
	'year'         => '2010',
	'articleTitle' => 'A brief guide to Harvard referencing',
	'journalTitle' => 'The British Journal of Referencing',
	'volume'       => '12',
	'issue'        => '2',
	'pages'        => '27-35',
);

$category = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;

// ---------------------------------------------------------------------
// 1-5. build_reference() matches the confirmed Liverpool Hope example
// exactly, for 1/2/3/4/5 authors — the user's own worked example structure.
// ---------------------------------------------------------------------
check(
	'[1] one author matches the confirmed Liverpool Hope example',
	Citex_Reference_Rules::build_reference( $category, array_merge( $base_fields, array( 'authors' => $one ) ) ),
	'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), pp.27-35.'
);
check(
	'[2] two authors: joined with "and"',
	Citex_Reference_Rules::build_reference( $category, array_merge( $base_fields, array( 'authors' => $two ) ) ),
	'Mitchell, S. and Evans, D. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), pp.27-35.'
);
check(
	'[3] three authors: commas then a final "and"',
	Citex_Reference_Rules::build_reference( $category, array_merge( $base_fields, array( 'authors' => $three ) ) ),
	'Mitchell, S., Evans, D. and Brown, T. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), pp.27-35.'
);
check(
	'[4] four authors: still every author, no et-al cutoff',
	Citex_Reference_Rules::build_reference( $category, array_merge( $base_fields, array( 'authors' => $four ) ) ),
	'Mitchell, S., Evans, D., Brown, T. and Williams, R. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), pp.27-35.'
);
check(
	'[5] five authors: still every author listed in full',
	Citex_Reference_Rules::build_reference( $category, array_merge( $base_fields, array( 'authors' => $five ) ) ),
	'Mitchell, S., Evans, D., Brown, T., Williams, R. and Davies, K. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), pp.27-35.'
);
check(
	'[9] "et al." never appears for any author count, including 5',
	false !== strpos( Citex_Reference_Rules::build_reference( $category, array_merge( $base_fields, array( 'authors' => $five ) ) ), 'et al' ),
	false
);

// ---------------------------------------------------------------------
// 17. dragdrop_shape(): a CONSTANT 7-part shape for ANY author count — the
// joined author list is always ONE draggable part, even for a single
// author (unlike Book, which special-cases one author into 4 parts).
// ---------------------------------------------------------------------
$shape_one = Citex_Reference_Rules::dragdrop_shape( $category, array_merge( $base_fields, array( 'authors' => $one ) ) );
check( '[17] one author: exactly 7 parts, author list as ONE part (not split into surname/initials)', $shape_one['parts'], array( 'Mitchell, S.', '2010', 'A brief guide to Harvard referencing', 'The British Journal of Referencing', '12', '2', '27-35' ) );
check( '[17] fixedText matches the confirmed Liverpool Hope grammar', $shape_one['fixedText'], '| (||) ||. ||, ||(||), pp.||.' );

$shape_five = Citex_Reference_Rules::dragdrop_shape( $category, array_merge( $base_fields, array( 'authors' => $five ) ) );
check( '[17] five authors: still exactly 7 parts (constant shape)', count( $shape_five['parts'] ), 7 );
check( '[17] five authors: fixedText is unchanged regardless of author count', $shape_five['fixedText'], $shape_one['fixedText'] );

// Reconstruct via the same |/|| grammar and confirm it matches
// build_reference()'s own output exactly — DragDrop and MCQ can never
// silently disagree.
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
	'[17] one-author shape reconstructs to exactly build_reference()\'s output',
	reconstruct_from_shape( $shape_one ),
	Citex_Reference_Rules::build_reference( $category, array_merge( $base_fields, array( 'authors' => $one ) ) )
);
check(
	'[17] five-author shape reconstructs to exactly build_reference()\'s output',
	reconstruct_from_shape( $shape_five ),
	Citex_Reference_Rules::build_reference( $category, array_merge( $base_fields, array( 'authors' => $five ) ) )
);

// ---------------------------------------------------------------------
// 9 & 16. format_regex(): accepts any correctly-joined author count,
// rejects "et al.", rejects "&" joining, rejects a comma-joined-throughout
// author list, and rejects a missing "pp." prefix.
// ---------------------------------------------------------------------
$ja_regex = Citex_Reference_Rules::format_regex( $category );
$good_one = 'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), pp.27-35.';
$good_two = 'Mitchell, S. and Evans, D. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), pp.27-35.';
check( '[format] 1 author matches', 1 === preg_match( $ja_regex, $good_one ), true );
check( '[format] 2 authors match', 1 === preg_match( $ja_regex, $good_two ), true );
check( '[9] "et al." does NOT match — never valid in the reference list', 1 === preg_match( $ja_regex, 'Mitchell et al. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), pp.27-35.' ), false );
check( '[format] "&" joining does NOT match', 1 === preg_match( $ja_regex, 'Mitchell, S. & Evans, D. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), pp.27-35.' ), false );
check( '[format] comma-joined throughout with no final "and" does NOT match', 1 === preg_match( $ja_regex, 'Mitchell, S., Evans, D. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), pp.27-35.' ), false );
check( '[16] missing "pp." prefix does NOT match', 1 === preg_match( $ja_regex, 'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), 27-35.' ), false );
check( '[format] "p." instead of "pp." does NOT match', 1 === preg_match( $ja_regex, 'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing, 12(2), p.27-35.' ), false );
check( '[format] missing comma after journal title does NOT match', 1 === preg_match( $ja_regex, 'Mitchell, S. (2010) A brief guide to Harvard referencing. The British Journal of Referencing 12(2), pp.27-35.' ), false );

// ---------------------------------------------------------------------
// id_prefix(), mcq_question_stem(), mcq_hint() are all category-specific
// (JA / journal-article-specific wording), never silently falling back to
// Book's.
// ---------------------------------------------------------------------
check( '[id_prefix] Journal Article uses "JA"', Citex_Reference_Rules::id_prefix( $category ), 'JA' );
check( '[id_prefix] Book is unaffected ("BK")', Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_BOOK ), 'BK' );
check( '[id_prefix] Edited Book is unaffected ("ED")', Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_EDITED_BOOK ), 'ED' );
check( '[mcq_question_stem] mentions "journal article"', false !== stripos( Citex_Reference_Rules::mcq_question_stem( $category ), 'journal article' ), true );
check( '[mcq_hint] mentions volume/issue', false !== stripos( Citex_Reference_Rules::mcq_hint( $category ), 'volume' ), true );

// ---------------------------------------------------------------------
// 18. The distractor-pattern catalogue names journal-article-specific
// mistakes (volume/issue, page range/"pp." prefix) as well as the shared
// author-joining/"et al." mistakes.
// ---------------------------------------------------------------------
$ja_patterns = Citex_Reference_Rules::mcq_distractor_patterns( $category );
$joined = implode( ' ', $ja_patterns );
check( '[18] the catalogue mentions "et al."', false !== stripos( $joined, 'et al' ), true );
check( '[18] the catalogue mentions "pp."', false !== strpos( $joined, 'pp.' ), true );
check( '[18] the catalogue mentions volume/issue', false !== stripos( $joined, 'volume' ) && false !== stripos( $joined, 'issue' ), true );
check( '[18] the catalogue mentions page range', false !== stripos( $joined, 'page range' ), true );

// ---------------------------------------------------------------------
// categories()/is_known_category(): Journal Article is now a first-class
// category alongside Book/Edited Book, without disturbing either.
// ---------------------------------------------------------------------
check( '[categories] Journal Article is a known category', Citex_Reference_Rules::is_known_category( 'Journal Article' ), true );
check( '[categories] Book is still known', Citex_Reference_Rules::is_known_category( 'Book' ), true );
check( '[categories] Edited Book is still known', Citex_Reference_Rules::is_known_category( 'Edited Book' ), true );
check( '[categories] categories() lists exactly 3 categories', count( Citex_Reference_Rules::categories() ), 3 );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
