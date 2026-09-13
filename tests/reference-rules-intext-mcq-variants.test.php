<?php
/**
 * Unit tests for Citex_Intext_Mcq_Variants — Harvard's in-text citation MCQ
 * catalogue (4 variants, gated by citation form and author count). The
 * critical case: 'identify_the_error' for the narrative form must be able
 * to surface 'author_inside_parens' — the exact screenshot-2-adjacent
 * mistake (author trapped inside the parentheses) as a WRONG statement to
 * reject, never as the correct answer.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-intext-mcq-variants.test.php` — not shipped
 * in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-intext-citation-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-intext-mcq-variants.php';

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
// 1. Catalogue shape.
// ---------------------------------------------------------------------
check( '[1] 4 variants', count( Citex_Intext_Mcq_Variants::variants() ), 4 );
check( '[1] "page_punctuation" is restricted to parenthetical_quote only', Citex_Intext_Mcq_Variants::variant_form_requirement( 'page_punctuation' ), array( 'parenthetical_quote' ) );
check( '[1] "correct_format" has no form restriction', Citex_Intext_Mcq_Variants::variant_form_requirement( 'correct_format' ), null );
check( '[1] "join_convention" requires 2+ people', Citex_Intext_Mcq_Variants::variant_author_requirement( 'join_convention' ), array( 2, PHP_INT_MAX ) );

// ---------------------------------------------------------------------
// 2. variant_for() gating — a single-author narrative record can never be
// assigned "join_convention" or "page_punctuation".
// ---------------------------------------------------------------------
for ( $seed = 1; $seed <= 30; $seed++ ) {
	$variant = Citex_Intext_Mcq_Variants::variant_for( $seed, 'narrative', 1 );
	if ( in_array( $variant, array( 'join_convention', 'page_punctuation' ), true ) ) {
		check( "[2] seed=$seed: single-author narrative never assigned \"$variant\"", $variant, 'correct_format or identify_the_error' );
	}
}
check( '[2] (no violations above means every assignment for 30 seeds was form/count-compatible)', true, true );

// ---------------------------------------------------------------------
// 3. build() for 'correct_format' — the correct answer is always
// Citex_Intext_Citation_Rules's own full sentence.
// ---------------------------------------------------------------------
$fields = array(
	'form'     => 'narrative',
	'who'      => 'Ross',
	'surnames' => array( 'Ross' ),
	'year'     => '2015',
	'clause'   => 'argues that point matters',
);
$result = Citex_Intext_Mcq_Variants::build( 'correct_format', $fields );
check( '[3] correctAnswer matches narrative_sentence() exactly', $result['correctAnswer'], Citex_Intext_Citation_Rules::narrative_sentence( 'Ross', '2015', 'argues that point matters' ) );
check( '[3] exactly 3 wrong options', count( $result['wrongOptions'] ), 3 );
check( '[3] no wrong option equals the correct answer', in_array( $result['correctAnswer'], $result['wrongOptions'], true ), false );

// ---------------------------------------------------------------------
// 4. 'page_punctuation' returns null for a non-quote form (a variant/form
// mismatch), never a malformed result.
// ---------------------------------------------------------------------
check( '[4] page_punctuation for a narrative record returns null (mismatch)', Citex_Intext_Mcq_Variants::build( 'page_punctuation', $fields ), null );

// ---------------------------------------------------------------------
// 5. 'identify_the_error' — the screenshot-1 regression as an explicit
// wrong option: for narrative records, "author_inside_parens" must be one
// of the possible error kinds tested (via pick_error_kind()'s seed space),
// and whichever kind is picked, the "correct" answer is always the
// TRUE description of that mistake, never the broken sentence itself.
// ---------------------------------------------------------------------
$seen_author_inside_parens = false;
for ( $seed_salt = 0; $seed_salt < 50; $seed_salt++ ) {
	$narrative_fields = array(
		'form'     => 'narrative',
		'who'      => 'Ross' . $seed_salt,
		'surnames' => array( 'Ross' . $seed_salt ),
		'year'     => (string) ( 2000 + $seed_salt ),
		'clause'   => 'argues that point ' . $seed_salt . ' matters',
	);
	$r = Citex_Intext_Mcq_Variants::build( 'identify_the_error', $narrative_fields );
	if ( 'The author\'s surname is placed inside the parentheses instead of before them.' === $r['correctAnswer'] ) {
		$seen_author_inside_parens = true;
	}
	if ( in_array( $r['correctAnswer'], $r['wrongOptions'], true ) ) {
		check( "[5] correctAnswer never duplicated in wrongOptions (salt=$seed_salt)", true, false );
	}
}
check( '[5] "author_inside_parens" (the screenshot-1 mistake) is reachable as identify_the_error\'s TRUE statement across seeds', $seen_author_inside_parens, true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
