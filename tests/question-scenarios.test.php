<?php
/**
 * Regression tests for Citex_Question_Scenarios — the per-category scenario
 * catalogue the dynamic question-generation framework selects from. Pure,
 * no WordPress/ACF dependency at all.
 *
 * Repo-level only, run with plain
 * `php tests/question-scenarios.test.php` — not shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-question-scenarios.php';

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
// 1. Book MCQ catalogue: exactly the four author-count buckets, each
// carrying the ruleTested the user specified.
// ---------------------------------------------------------------------
$book_mcq = Citex_Question_Scenarios::catalog( Citex_Reference_Rules::CATEGORY_BOOK, 'MCQ' );
check( '[1] Book MCQ has exactly 4 scenarios', count( $book_mcq ), 4 );
$book_mcq_ids = array_column( $book_mcq, 'id' );
check( '[1] Book MCQ scenario ids are the 4 author-count buckets', $book_mcq_ids, array( 'one_author', 'two_authors', 'three_authors', 'four_or_more_authors' ) );
foreach ( $book_mcq as $entry ) {
	check( '[1] every Book MCQ scenario is tagged questionType MCQ: ' . $entry['id'], $entry['questionType'], 'MCQ' );
}
$book_rule_by_id = array_combine( array_column( $book_mcq, 'id' ), array_column( $book_mcq, 'ruleTested' ) );
check( '[1] one_author -> author_formatting', $book_rule_by_id['one_author'], 'author_formatting' );
check( '[1] two_authors -> author_joining', $book_rule_by_id['two_authors'], 'author_joining' );
check( '[1] three_authors -> author_joining', $book_rule_by_id['three_authors'], 'author_joining' );
check( '[1] four_or_more_authors -> reference_list_all_authors', $book_rule_by_id['four_or_more_authors'], 'reference_list_all_authors' );

// ---------------------------------------------------------------------
// 2. Book DragDrop catalogue: same 4 scenario ids, tagged questionType
// DragDrop instead — the catalogue is type-specific but scenario-id-stable
// across types (an admin comparing MCQ and DragDrop coverage sees the same
// scenario names).
// ---------------------------------------------------------------------
$book_dragdrop = Citex_Question_Scenarios::catalog( Citex_Reference_Rules::CATEGORY_BOOK, 'DragDrop' );
check( '[2] Book DragDrop has the same 4 scenario ids as MCQ', array_column( $book_dragdrop, 'id' ), $book_mcq_ids );
foreach ( $book_dragdrop as $entry ) {
	check( '[2] every Book DragDrop scenario is tagged questionType DragDrop: ' . $entry['id'], $entry['questionType'], 'DragDrop' );
}

// ---------------------------------------------------------------------
// 3. Edited Book catalogue: 3 editor-count buckets, matching the rule
// engine's already-supported 1/2/3+ editor range (join_editors() already
// handles 3+; only the AI-v2 prompt cap needed lifting).
// ---------------------------------------------------------------------
$edited_book_mcq = Citex_Question_Scenarios::catalog( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, 'MCQ' );
check( '[3] Edited Book has exactly 3 scenarios', count( $edited_book_mcq ), 3 );
check( '[3] Edited Book scenario ids are the 3 editor-count buckets', array_column( $edited_book_mcq, 'id' ), array( 'one_editor', 'two_editors', 'three_or_more_editors' ) );
$edited_rule_by_id = array_combine( array_column( $edited_book_mcq, 'id' ), array_column( $edited_book_mcq, 'ruleTested' ) );
check( '[3] one_editor -> editor_designation', $edited_rule_by_id['one_editor'], 'editor_designation' );
check( '[3] two_editors -> editor_designation', $edited_rule_by_id['two_editors'], 'editor_designation' );
check( '[3] three_or_more_editors -> editor_joining', $edited_rule_by_id['three_or_more_editors'], 'editor_joining' );

// ---------------------------------------------------------------------
// 4. targetCounts: exact-count buckets carry a single value; the "N or
// more" bucket carries several real counts to vary across, never just one.
// ---------------------------------------------------------------------
$by_id = array_combine( array_column( $book_mcq, 'id' ), $book_mcq );
check( '[4] one_author targets exactly [1]', $by_id['one_author']['targetCounts'], array( 1 ) );
check( '[4] two_authors targets exactly [2]', $by_id['two_authors']['targetCounts'], array( 2 ) );
check( '[4] four_or_more_authors targets more than one real count', count( $by_id['four_or_more_authors']['targetCounts'] ) > 1, true );
foreach ( $by_id['four_or_more_authors']['targetCounts'] as $count ) {
	check( '[4] every four_or_more_authors target count is >= 4: ' . $count, $count >= 4, true );
}

// ---------------------------------------------------------------------
// 5. find() looks up one scenario by id; returns null for an unknown id.
// ---------------------------------------------------------------------
$found = Citex_Question_Scenarios::find( Citex_Reference_Rules::CATEGORY_BOOK, 'MCQ', 'two_authors' );
check( '[5] find() returns the matching entry', null === $found ? null : $found['id'], 'two_authors' );
check( '[5] find() returns null for an unknown scenario id', Citex_Question_Scenarios::find( Citex_Reference_Rules::CATEGORY_BOOK, 'MCQ', 'nonexistent' ), null );

// ---------------------------------------------------------------------
// 6. target_count_for(): a single-value bucket always returns that value;
// a multi-value bucket is deterministic per seed (same seed -> same
// count, reproducible for tests) but genuinely varies across different
// seeds — it must not always collapse to the same count.
// ---------------------------------------------------------------------
check( '[6] single-value bucket always returns its one count', Citex_Question_Scenarios::target_count_for( $by_id['two_authors'], 'BK01' ), 2 );
check( '[6] single-value bucket ignores the seed entirely', Citex_Question_Scenarios::target_count_for( $by_id['two_authors'], 'ANY-SEED-AT-ALL' ), 2 );

$seen_counts = array();
foreach ( array( 'BK01', 'BK02', 'BK03', 'BK04', 'BK05', 'BK06', 'BK07', 'BK08', 'BK09', 'BK10' ) as $seed ) {
	$count = Citex_Question_Scenarios::target_count_for( $by_id['four_or_more_authors'], $seed );
	check( '[6] target_count_for(four_or_more_authors, ' . $seed . ') is one of the bucket\'s own counts', in_array( $count, $by_id['four_or_more_authors']['targetCounts'], true ), true );
	$seen_counts[ $count ] = true;
}
check( '[6] across 10 different seeds, more than one distinct count was chosen', count( $seen_counts ) > 1, true );
check( '[6] the same seed always reproduces the same count', Citex_Question_Scenarios::target_count_for( $by_id['four_or_more_authors'], 'BK01' ), Citex_Question_Scenarios::target_count_for( $by_id['four_or_more_authors'], 'BK01' ) );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
