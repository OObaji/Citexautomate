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
// 1. Book MCQ catalogue: only the four author-count buckets now —
// identify_error/choose_treatment_* were removed for Book entirely (per
// explicit user request), replaced by the user's own fixed 16-variant
// catalogue (Citex_Book_Mcq_Variants), selected per question directly by
// Citex_AI_V2::normalise_book_mcq_variant_item() from the author count
// these same 4 buckets already assign — not via an mcq-only scenario
// entry. identify_error/choose_treatment_* remain fully intact for
// Edited Book (see section 3 below).
// ---------------------------------------------------------------------
$book_mcq = Citex_Question_Scenarios::catalog( Citex_Reference_Rules::CATEGORY_BOOK, 'MCQ' );
check( '[1] Book MCQ has exactly 4 scenarios (only the 4 count buckets; no identify_error/choose_treatment)', count( $book_mcq ), 4 );
$book_mcq_ids = array_column( $book_mcq, 'id' );
check(
	'[1] Book MCQ scenario ids are exactly the 4 author-count buckets',
	$book_mcq_ids,
	array( 'one_author', 'two_authors', 'three_authors', 'four_or_more_authors' )
);
foreach ( $book_mcq as $entry ) {
	check( '[1] every Book MCQ scenario is tagged questionType MCQ: ' . $entry['id'], $entry['questionType'], 'MCQ' );
}
$book_rule_by_id = array_combine( array_column( $book_mcq, 'id' ), array_column( $book_mcq, 'ruleTested' ) );
check( '[1] one_author -> author_formatting', $book_rule_by_id['one_author'], 'author_formatting' );
check( '[1] two_authors -> author_joining', $book_rule_by_id['two_authors'], 'author_joining' );
check( '[1] three_authors -> author_joining', $book_rule_by_id['three_authors'], 'author_joining' );
check( '[1] four_or_more_authors -> reference_list_all_authors', $book_rule_by_id['four_or_more_authors'], 'reference_list_all_authors' );

// ---------------------------------------------------------------------
// 2. Book DragDrop catalogue: only the 4 count-bucket scenario ids —
// "identify_error" is MCQ-only (DragDrop only ever constructs a complete
// reference; there is no DragDrop equivalent of "spot the error in this
// shown reference").
// ---------------------------------------------------------------------
$book_dragdrop = Citex_Question_Scenarios::catalog( Citex_Reference_Rules::CATEGORY_BOOK, 'DragDrop' );
check( '[2] Book DragDrop has only the 4 count-bucket scenarios', array_column( $book_dragdrop, 'id' ), array( 'one_author', 'two_authors', 'three_authors', 'four_or_more_authors' ) );
foreach ( $book_dragdrop as $entry ) {
	check( '[2] every Book DragDrop scenario is tagged questionType DragDrop: ' . $entry['id'], $entry['questionType'], 'DragDrop' );
}

// ---------------------------------------------------------------------
// 3. Edited Book catalogue: 3 editor-count buckets, matching the rule
// engine's already-supported 1/2/3+ editor range (join_editors() already
// handles 3+; only the AI-v2 prompt cap needed lifting) — plus
// identify_error and 2 choose_treatment buckets for MCQ only.
// ---------------------------------------------------------------------
$edited_book_mcq = Citex_Question_Scenarios::catalog( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, 'MCQ' );
check( '[3] Edited Book MCQ has exactly 6 scenarios (3 count buckets + identify_error + 2 choose_treatment)', count( $edited_book_mcq ), 6 );
check(
	'[3] Edited Book MCQ scenario ids are the 3 editor-count buckets plus identify_error plus 2 choose_treatment buckets',
	array_column( $edited_book_mcq, 'id' ),
	array( 'one_editor', 'two_editors', 'three_or_more_editors', 'identify_error', 'choose_treatment_two_editors', 'choose_treatment_three_or_more_editors' )
);
$edited_rule_by_id = array_combine( array_column( $edited_book_mcq, 'id' ), array_column( $edited_book_mcq, 'ruleTested' ) );
check( '[3] one_editor -> editor_designation', $edited_rule_by_id['one_editor'], 'editor_designation' );
check( '[3] two_editors -> editor_designation', $edited_rule_by_id['two_editors'], 'editor_designation' );
check( '[3] three_or_more_editors -> editor_joining', $edited_rule_by_id['three_or_more_editors'], 'editor_joining' );
check( '[3] identify_error -> error_identification', $edited_rule_by_id['identify_error'], 'error_identification' );
check( '[3] choose_treatment_two_editors -> editor_designation', $edited_rule_by_id['choose_treatment_two_editors'], 'editor_designation' );
check( '[3] choose_treatment_three_or_more_editors -> editor_joining', $edited_rule_by_id['choose_treatment_three_or_more_editors'], 'editor_joining' );

$edited_book_dragdrop = Citex_Question_Scenarios::catalog( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, 'DragDrop' );
check( '[3] Edited Book DragDrop has only the 3 count-bucket scenarios (no identify_error)', array_column( $edited_book_dragdrop, 'id' ), array( 'one_editor', 'two_editors', 'three_or_more_editors' ) );

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
