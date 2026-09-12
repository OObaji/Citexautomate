<?php
/**
 * Regression tests for this sprint's core mechanism:
 * Citex_Reference_Rules::person_parts() (breaks a list of authors/editors
 * into up to $max individual draggable Question Parts, folding any
 * remainder into a correctly-joined literal continuation) and its
 * fixedText-building counterpart name_template(), plus
 * Citex_Reference_Rules::part_suitability()'s new word-count cap and
 * Website's new 3-4-part field-subset DragDrop designs (replacing the old
 * fixed 6-part shape).
 *
 * The property under test throughout part 1: for ANY split point $max,
 * concatenating drawn[0] . joiners[0] . drawn[1] . ... . $overflow must
 * reproduce EXACTLY what join_people() returns for the WHOLE list — this
 * is what lets DragDrop show individual name parts (never one joined
 * chunk) while the reconstructed reference still lists every person in
 * full, never "et al.".
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-person-parts.test.php` — not shipped in
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

function person( $surname, $initials ) {
	return array( 'surname' => $surname, 'initials' => $initials );
}

$pool = array(
	person( 'Smith', 'J.' ),
	person( 'Jones', 'A.' ),
	person( 'Lee', 'K.' ),
	person( 'Brown', 'D.' ),
	person( 'Green', 'S.' ),
	person( 'White', 'P.' ),
);

// =======================================================================
// 1. For every count (1-6) and every max (1-3), person_parts()'s
// drawn+joiners+overflow reconstructs EXACTLY what join_people() returns
// for the whole list.
// =======================================================================
foreach ( array( 1, 2, 3 ) as $max ) {
	for ( $count = 1; $count <= 6; $count++ ) {
		$people   = array_slice( $pool, 0, $count );
		$expected = Citex_Reference_Rules::join_people( $people );
		list( $drawn, $joiners, $overflow ) = Citex_Reference_Rules::person_parts( $people, $max );
		$rebuilt = '';
		foreach ( $drawn as $index => $part ) {
			$rebuilt .= $part;
			if ( isset( $joiners[ $index ] ) ) {
				$rebuilt .= $joiners[ $index ];
			}
		}
		$rebuilt .= $overflow;
		check( "[1] max=$max, count=$count: drawn+joiners+overflow reproduces join_people()", $rebuilt, $expected );
		check_true( "[1] max=$max, count=$count: drawn never exceeds max", count( $drawn ) <= $max );
		check( "[1] max=$max, count=$count: drawn count matches min(count, max)", count( $drawn ), min( $count, $max ) );
	}
}

// =======================================================================
// 2. name_template() places exactly one "||" per drawn part, with each
// joiner as literal text between consecutive tokens.
// =======================================================================
list( $drawn2, $joiners2 ) = Citex_Reference_Rules::person_parts( array_slice( $pool, 0, 2 ), 2 );
check( '[2] name_template() for 2 drawn parts (no overflow)', Citex_Reference_Rules::name_template( $drawn2, $joiners2 ), '| and ||' );

list( $drawn3, $joiners3 ) = Citex_Reference_Rules::person_parts( array_slice( $pool, 0, 4 ), 2 );
check( '[2] name_template() for 2 drawn parts (with overflow — comma, not "and")', Citex_Reference_Rules::name_template( $drawn3, $joiners3 ), '|, ||' );

// =======================================================================
// 3. part_suitability()'s word-count cap — a part under ~20 words is
// fine; one over is rejected (structurally distinct from the character
// cap: many short words can exceed the word cap while staying well under
// the character cap).
// =======================================================================
$short_words = 'A Concise Title About Referencing';
check( '[3] a short, few-word part is suitable', Citex_Reference_Rules::part_suitability( array( $short_words ) ), null );

$many_short_words = implode( ' ', array_fill( 0, 25, 'A' ) ); // 25 one-letter words, 49 chars total — well under the 70-char cap, so only the word-count check can trigger here.
$reason = Citex_Reference_Rules::part_suitability( array( $many_short_words ) );
check_true( '[3] a part with more than ~20 words is rejected even if individually short words', null !== $reason );
check_true( '[3] the rejection reason names "words"', null !== $reason && false !== strpos( $reason, 'words' ) );

// =======================================================================
// 4. Website's new field-subset DragDrop designs (replacing the old
// fixed 6-part shape) each produce exactly 3-4 parts, and every design
// still reconstructs the SAME complete, correct 6-field reference.
// =======================================================================
$website_fields = array(
	'author'       => array( 'type' => 'individual', 'surname' => 'Mitchell', 'initials' => 'S.' ),
	'year'         => '2024',
	'title'        => 'Study skills guide',
	'publisher'    => 'University of Leeds',
	'url'          => 'https://www.leeds.ac.uk/study-skills',
	'accessedDate' => '3 September 2026',
);
$expected_full_reference = 'Mitchell, S. (2024) Study skills guide [online]. University of Leeds. Available from: <https://www.leeds.ac.uk/study-skills> [accessed 3 September 2026].';

foreach ( Citex_Reference_Rules::website_dragdrop_designs() as $design ) {
	$shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_WEBSITE, $website_fields, $design );
	check_true( "[4] $design: exactly 3 or 4 draggable parts", in_array( count( $shape['parts'] ), array( 3, 4 ), true ) );
	check( "[4] $design: still reconstructs the complete, correct full reference", Citex_Reference_Rules::reconstruct_reference( $shape ), $expected_full_reference );
}
check( '[4] website_dragdrop_designs() excludes full_reference (MCQ-only, 6 parts)', in_array( 'full_reference', Citex_Reference_Rules::website_dragdrop_designs(), true ), false );

$full_shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_WEBSITE, $website_fields, 'full_reference' );
check( '[4] full_reference (MCQ-only) still produces all 6 parts', count( $full_shape['parts'] ), 6 );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
