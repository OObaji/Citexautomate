<?php
/**
 * Regression tests for the "SHARED DETERMINISTIC DISTRACTOR PRIMITIVES"
 * section of Citex_Reference_Rules — the Citex-authored, deterministic
 * confusingWords generators that replaced Gemini-authored distractors for
 * Edited Book, Journal Article and Website DragDrop questions (Book already
 * had its own equivalent via Citex_Book_Dragdrop_Parts — see
 * tests/reference-rules-book-dragdrop-parts.test.php).
 *
 * Hand-verified expected values below were captured by running the actual
 * deterministic generators once and reading their real output — exactly
 * the same "recompute and hardcode" pattern already used throughout this
 * test suite (e.g. Citex_Book_Mcq_Variants's own tests) — so a future
 * accidental change to the seeding scheme or a distractor flavour is
 * caught here even though the values are not independently "correct" in
 * any absolute sense, only stable.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-distractors.test.php` — not shipped in
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
function check_true( $description, $actual ) {
	check( $description, $actual, true );
}

function editor( $surname, $initials, $full_name ) {
	return array( 'surname' => $surname, 'initials' => $initials, 'fullName' => $full_name );
}
function author( $surname, $initials, $full_name = '' ) {
	return array( 'surname' => $surname, 'initials' => $initials, 'fullName' => $full_name );
}

// =======================================================================
// 1. Edited Book: hand-verified confusingWords for every design, 1 editor.
// =======================================================================
$eb_editors_one = array( editor( 'Vance', 'C.', 'Chris Vance' ) );
$eb_editors_two = array( editor( 'Vance', 'C.', 'Chris Vance' ), editor( 'Shaw', 'D.', 'Dana Shaw' ) );
$eb_fields      = array( 'year' => '2019', 'title' => 'Urban Ecology', 'place' => 'Cambridge', 'publisher' => 'Polity' );

$eb_expected = array(
	'editor_designation_year'      => array( 'Vance, C', 'ed', '2021' ),
	'editor_designation_title'     => array( 'Vance, C', 'ed', 'Urban Ecology (2019)' ),
	'editor_designation_place'     => array( 'Vance, C', 'ed', 'Amsterdam' ),
	'editor_designation_publisher' => array( 'Vance, C', 'ed', 'Springer' ),
	'editor_split_designation'     => array( 'Chris', 'C', 'ed' ),
);
foreach ( $eb_expected as $design => $expected_confusing ) {
	$shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array_merge( $eb_fields, array( 'editors' => $eb_editors_one ) ), $design );
	check( "[1] Edited Book \"$design\" (1 editor) confusingWords", $shape['confusingWords'], $expected_confusing );
}
$eb_two_expected = array(
	'editor_designation_year'  => array( 'Shaw, D.', 'eds.', '2021' ),
	'editor_split_designation' => array( 'Shaw', 'D.', 'eds.' ),
);
foreach ( $eb_two_expected as $design => $expected_confusing ) {
	$shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array_merge( $eb_fields, array( 'editors' => $eb_editors_two ) ), $design );
	check( "[1] Edited Book \"$design\" (2 editors) confusingWords", $shape['confusingWords'], $expected_confusing );
}

// =======================================================================
// 2. Journal Article: hand-verified confusingWords for every design, 1 author.
// =======================================================================
$ja_authors_one = array( author( 'Bennett', 'S.' ) );
$ja_fields      = array( 'year' => '2020', 'articleTitle' => 'A Study', 'journalTitle' => 'Journal of Studies', 'volume' => '12', 'issue' => '3', 'pages' => '45-52' );

$ja_expected = array(
	'author_year_volume_pages' => array( 'Bennett, S. et al.', '2022', '14' ),
	'author_year_issue'        => array( 'Bennett, S. et al.', '2022', '4' ),
	'author_year_journal'      => array( 'Bennett, S. et al.', '2022', 'Cities' ),
	'volume_issue_pages'       => array( '14', '4', '43-50' ),
	'journal_volume_issue'     => array( 'Cities', '14', '4' ),
	'year_volume_issue_pages'  => array( '2022', '14', '4' ),
);
foreach ( $ja_expected as $design => $expected_confusing ) {
	$shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, array_merge( $ja_fields, array( 'authors' => $ja_authors_one ) ), $design );
	check( "[2] Journal Article \"$design\" (1 author) confusingWords", $shape['confusingWords'], $expected_confusing );
}
// MCQ-only designs return no confusingWords at all (never read for MCQ).
$mcq_only_shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, array_merge( $ja_fields, array( 'authors' => $ja_authors_one ) ), 'full_reference' );
check( '[2] Journal Article "full_reference" (MCQ-only) has empty confusingWords', $mcq_only_shape['confusingWords'], array() );
$author_only_shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, array_merge( $ja_fields, array( 'authors' => $ja_authors_one ) ), 'author_only' );
check( '[2] Journal Article "author_only" (MCQ-only) has empty confusingWords', $author_only_shape['confusingWords'], array() );

// =======================================================================
// 3. Website: hand-verified confusingWords, individual (dated) and
// organisation (undated).
// =======================================================================
$web_individual      = array( 'type' => 'individual', 'surname' => 'Mitchell', 'initials' => 'S.', 'fullName' => 'Sarah Mitchell' );
$web_org             = array( 'type' => 'organisation', 'name' => 'University of Leeds' );
$web_fields_dated     = array( 'year' => '2024', 'title' => 'Study skills guide', 'publisher' => 'University of Leeds', 'url' => 'https://www.leeds.ac.uk/study-skills', 'accessedDate' => '3 September 2026' );
$web_fields_undated   = array( 'year' => 'n.d.', 'title' => 'About us', 'publisher' => 'University of Leeds', 'url' => 'https://www.leeds.ac.uk/about', 'accessedDate' => '3 September 2026' );

$web_expected = array(
	'author_year_title'      => array( 'Mitchell, S', '2042', 'Study skills guide.' ),
	'author_year_publisher'  => array( 'Mitchell, S', '2042', 'Harvard University' ),
	'title_publisher_url'    => array( 'Study skills guide.', 'Harvard University', 'http://www.leeds.ac.uk/study-skills' ),
	'year_publisher_accessed' => array( '2042', 'Harvard University', 'September 3, 2026' ),
);
foreach ( $web_expected as $design => $expected_confusing ) {
	$shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_WEBSITE, array_merge( $web_fields_dated, array( 'author' => $web_individual ) ), $design );
	check( "[3] Website \"$design\" (individual, dated) confusingWords", $shape['confusingWords'], $expected_confusing );
}
$web_org_shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_WEBSITE, array_merge( $web_fields_undated, array( 'author' => $web_org ) ), 'author_year_publisher' );
check( '[3] Website "author_year_publisher" (organisation, undated) confusingWords', $web_org_shape['confusingWords'], array( 'Public Health England', '2019', 'World Bank' ) );

// =======================================================================
// 4. Property sweep: across many seeds/records, no distractor ever equals
// its own corresponding correct Question Part (the single most important
// invariant — a distractor that matches the answer isn't a distractor at
// all), for every category and every real DragDrop-eligible design.
// =======================================================================
$violations = 0;
$eb_designs = Citex_Reference_Rules::edited_book_dragdrop_designs();
$given_names = array( 'Alice', 'Ben', 'Cara', 'Dev', 'Ella' );
$surnames    = array( 'Smith', 'Jones', 'Lee', 'Brown', 'Green' );
for ( $seed = 1; $seed <= 40; $seed++ ) {
	$n = 1 + ( $seed % 2 );
	$editors = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$editors[] = editor( $surnames[ $i ], substr( $surnames[ $i ], 0, 1 ) . '.', $given_names[ $i ] . ' ' . $surnames[ $i ] );
	}
	$fields = array( 'editors' => $editors, 'year' => (string) ( 2000 + $seed ), 'title' => "Title $seed", 'place' => 'City' . $seed, 'publisher' => 'Publisher' . $seed );
	foreach ( $eb_designs as $design ) {
		$shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $fields, $design );
		foreach ( $shape['parts'] as $i => $part ) {
			if ( strtolower( trim( (string) $part ) ) === strtolower( trim( (string) $shape['confusingWords'][ $i ] ) ) ) {
				$violations++;
			}
		}
	}
}
check( '[4] Edited Book: zero distractor-matches-correct-part violations across 40 seeds x 5 designs', $violations, 0 );

$violations = 0;
$ja_designs = Citex_Reference_Rules::journal_article_dragdrop_designs();
for ( $seed = 1; $seed <= 40; $seed++ ) {
	$n = 1 + ( $seed % 3 );
	$authors = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$authors[] = author( $surnames[ $i ], substr( $surnames[ $i ], 0, 1 ) . '.' );
	}
	$fields = array( 'authors' => $authors, 'year' => (string) ( 2000 + $seed ), 'articleTitle' => "Article $seed", 'journalTitle' => "Journal $seed", 'volume' => (string) ( 1 + $seed % 20 ), 'issue' => (string) ( 1 + $seed % 4 ), 'pages' => ( 10 + $seed ) . '-' . ( 20 + $seed ) );
	foreach ( $ja_designs as $design ) {
		$shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields, $design );
		foreach ( $shape['parts'] as $i => $part ) {
			if ( strtolower( trim( (string) $part ) ) === strtolower( trim( (string) $shape['confusingWords'][ $i ] ) ) ) {
				$violations++;
			}
		}
	}
}
check( '[4] Journal Article: zero distractor-matches-correct-part violations across 40 seeds x 6 designs', $violations, 0 );

$violations = 0;
$web_designs = Citex_Reference_Rules::website_dragdrop_designs();
for ( $seed = 1; $seed <= 40; $seed++ ) {
	$is_org = 0 === ( $seed % 2 );
	$author = $is_org
		? array( 'type' => 'organisation', 'name' => "Organisation $seed" )
		: array( 'type' => 'individual', 'surname' => $surnames[ $seed % 5 ], 'initials' => 'X.', 'fullName' => 'Firstname ' . $surnames[ $seed % 5 ] );
	$fields = array(
		'author'       => $author,
		'year'         => 0 === ( $seed % 5 ) ? 'n.d.' : (string) ( 2000 + $seed ),
		'title'        => "Page $seed",
		'publisher'    => "Publisher $seed",
		'url'          => "https://example$seed.com/page",
		'accessedDate' => '5 March 2024',
	);
	foreach ( $web_designs as $design ) {
		$shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_WEBSITE, $fields, $design );
		foreach ( $shape['parts'] as $i => $part ) {
			if ( strtolower( trim( (string) $part ) ) === strtolower( trim( (string) $shape['confusingWords'][ $i ] ) ) ) {
				$violations++;
			}
		}
	}
}
check( '[4] Website: zero distractor-matches-correct-part violations across 40 seeds x 4 designs', $violations, 0 );

// =======================================================================
// 5. Recomputation is stable: calling dragdrop_shape() twice with the
// exact same inputs always produces the exact same confusingWords — the
// property Citex_Generated_Validator's exact-match checks depend on.
// =======================================================================
$shape_a = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array_merge( $eb_fields, array( 'editors' => $eb_editors_one ) ), 'editor_designation_title' );
$shape_b = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array_merge( $eb_fields, array( 'editors' => $eb_editors_one ) ), 'editor_designation_title' );
check( '[5] Edited Book: recomputing from identical inputs reproduces identical confusingWords', $shape_a['confusingWords'], $shape_b['confusingWords'] );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
