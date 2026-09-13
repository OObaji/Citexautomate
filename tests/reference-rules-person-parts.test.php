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

// Note: Website's DragDrop shape is now built exclusively by
// Citex_Website_Dragdrop_Parts (see tests/reference-rules-website-dragdrop-parts.test.php)
// — the old fixed named-design catalogue (website_dragdrop_designs()) and
// its dragdrop_shape() branch have been removed entirely.

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
