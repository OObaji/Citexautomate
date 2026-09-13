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
	'author_year_volume_pages' => array( 'Bennett, S', '2022', '14' ),
	'author_year_issue'        => array( 'Bennett, S', '2022', '4' ),
	'author_year_journal'      => array( 'Bennett, S', '2022', 'Cities' ),
	'volume_issue_pages'       => array( '14', '4', '43–50' ),
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
// 2b. CRITICAL — a real reported bug: a multi-author article (routinely
// 3-6+ authors in the sciences) used to draw the WHOLE joined author list
// as one chip, producing a genuinely unusable, multi-line drag chip on
// mobile. The author-testing designs now draw only the FIRST author
// individually — exactly the same "one short chip, the rest folded into
// fixedText" technique already used for Book's author and Edited Book's
// editor — so the draggable chip stays short regardless of author count,
// while the reconstructed reference still names every author, correctly
// joined.
// =======================================================================
$ja_six_authors = array(
	author( 'Evans', 'C.' ), author( 'Scott', 'L.' ), author( 'Patel', 'M.' ),
	author( 'Brooks', 'O.' ), author( 'Flores', 'N.' ), author( 'Wright', 'T.' ),
);
$ja_six_shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, array_merge( $ja_fields, array( 'authors' => $ja_six_authors ) ), 'author_year_volume_pages' );
check( '[2b] a 6-author record still draws only the FIRST author as the chip', $ja_six_shape['parts'][0], 'Evans, C.' );
check_true( '[2b] the draggable author chip stays short regardless of author count', mb_strlen( $ja_six_shape['parts'][0] ) < 20 );
check(
	'[2b] the reconstructed reference still correctly joins all 6 authors, with "and" before the last',
	Citex_Reference_Rules::reconstruct_reference( $ja_six_shape ),
	'Evans, C., Scott, L., Patel, M., Brooks, O., Flores, N. and Wright, T. (2020) 12, pp. 45–52.'
);
check_true( '[2b] "et al." never appears in the reconstruction (Harvard reference-list rule always lists every author)', false === stripos( Citex_Reference_Rules::reconstruct_reference( $ja_six_shape ), 'et al' ) );

// Note: Website's DragDrop confusingWords are now covered exclusively by
// tests/reference-rules-website-dragdrop-parts.test.php, via
// Citex_Website_Dragdrop_Parts — the old fixed named-design catalogue
// (website_dragdrop_designs()) and its dragdrop_shape() branch have been
// removed entirely (this format also has no publisher element at all).

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

// Website's equivalent sweep now lives in
// tests/reference-rules-website-dragdrop-parts.test.php (section 5), via
// Citex_Website_Dragdrop_Parts — the old fixed named-design catalogue used
// here has been removed entirely.

// =======================================================================
// 5. Recomputation is stable: calling dragdrop_shape() twice with the
// exact same inputs always produces the exact same confusingWords — the
// property Citex_Generated_Validator's exact-match checks depend on.
// =======================================================================
$shape_a = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array_merge( $eb_fields, array( 'editors' => $eb_editors_one ) ), 'editor_designation_title' );
$shape_b = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array_merge( $eb_fields, array( 'editors' => $eb_editors_one ) ), 'editor_designation_title' );
check( '[5] Edited Book: recomputing from identical inputs reproduces identical confusingWords', $shape_a['confusingWords'], $shape_b['confusingWords'] );

// =======================================================================
// 6. combined_person_distractor()'s three same-person mistake flavours —
// given-name-spelled-out, missing-full-stop, and the newer
// surname/initials ORDER SWAP (e.g. "L., Cole" instead of "Cole, L.") —
// are all genuinely reachable across a seed sweep, and none of them is
// ever identical to the correct value.
// =======================================================================
$cp_value    = 'Cole, L.';
$cp_fullname = 'Liam Cole';
$cp_surname  = 'Cole';
$cp_seen_swap        = false;
$cp_seen_given_name   = false;
$cp_seen_missing_stop = false;
$cp_never_equals_value = true;
for ( $i = 0; $i < 60; $i++ ) {
	$out = Citex_Reference_Rules::combined_person_distractor( $cp_value, null, $cp_fullname, $cp_surname, 'combined-person-seed-' . $i );
	if ( $out === $cp_value ) {
		$cp_never_equals_value = false;
	}
	if ( 'L., Cole' === $out ) {
		$cp_seen_swap = true;
	}
	if ( 'Cole, Liam' === $out ) {
		$cp_seen_given_name = true;
	}
	if ( 'Cole, L' === $out ) {
		$cp_seen_missing_stop = true;
	}
}
check( '[6] combined_person_distractor(): the surname/initials order-swap flavour ("L., Cole") is reachable', $cp_seen_swap, true );
check( '[6] combined_person_distractor(): the given-name-spelled-out flavour ("Cole, Liam") is reachable', $cp_seen_given_name, true );
check( '[6] combined_person_distractor(): the missing-full-stop flavour ("Cole, L") is reachable', $cp_seen_missing_stop, true );
check( '[6] combined_person_distractor(): never equals the correct value across 60 seeds', $cp_never_equals_value, true );
check( '[6] surname/initials order-swap: exact expected output for a known seed', Citex_Reference_Rules::combined_person_distractor( $cp_value, null, $cp_fullname, $cp_surname, 'test-seed-1' ), 'L., Cole' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
