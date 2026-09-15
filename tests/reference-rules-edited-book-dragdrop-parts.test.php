<?php
/**
 * Regression tests for Citex_Edited_Book_Dragdrop_Parts — the dynamic
 * exactly-3-part Edited Book DragDrop question builder that replaced the
 * fixed named-design catalogue (Citex_Reference_Rules::edited_book_dragdrop_shape_variant()
 * and friends, still used by MCQ, untouched). Pure, no WordPress/ACF
 * dependency.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-edited-book-dragdrop-parts.test.php` — not
 * shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-edited-book-dragdrop-parts.php';

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

function eb_editor( $surname, $initials, $full ) {
	return array( 'surname' => $surname, 'initials' => $initials, 'fullName' => $full );
}

function eb_reconstruct( $built ) {
	$fixed = $built['fixedText'];
	$parts = $built['parts'];
	$out   = '';
	$index = 0;
	$len   = strlen( $fixed );
	for ( $i = 0; $i < $len; $i++ ) {
		if ( '|' !== $fixed[ $i ] ) {
			$out .= $fixed[ $i ];
			continue;
		}
		if ( $i + 1 < $len && '|' === $fixed[ $i + 1 ] ) {
			$out .= (string) $parts[ $index++ ];
			$i++;
			continue;
		}
		$out .= (string) $parts[ $index++ ];
	}
	return $out;
}

$fields = array( 'year' => '2020', 'title' => 'Modern Sociology', 'place' => 'London', 'publisher' => 'Routledge' );

// ---------------------------------------------------------------------
// 1. build_tokens(): all-literal reconstruction matches build_reference()'s
// own output, for 1/2/3-editor records.
// ---------------------------------------------------------------------
function eb_all_literal( array $tokens ) {
	$out = '';
	foreach ( $tokens as $token ) {
		$out .= $token['value'];
	}
	return $out;
}
$pool = array(
	eb_editor( 'Smith', 'J.', 'John Smith' ),
	eb_editor( 'Brown', 'K.', 'Kate Brown' ),
	eb_editor( 'Adams', 'P.', 'Peter Adams' ),
);
foreach ( array( 1, 2, 3 ) as $count ) {
	$editors  = array_slice( $pool, 0, $count );
	$tokens   = Citex_Edited_Book_Dragdrop_Parts::build_tokens( $editors, $fields, 0 );
	$expected = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array_merge( $fields, array( 'editors' => $editors ) ) );
	check( "[1] build_tokens() for $count editor(s), all-literal, reconstructs to build_reference()'s output", eb_all_literal( $tokens ), $expected );
}

// ---------------------------------------------------------------------
// 2. select_parts(): reproducible for the same seed; always exactly 3 keys;
// ALWAYS includes the drawn editor + designation (never traded away).
// ---------------------------------------------------------------------
$two_editors = array( eb_editor( 'Smith', 'J.', 'John Smith' ), eb_editor( 'Brown', 'K.', 'Kate Brown' ) );
check(
	'[2] select_parts() is reproducible for the same seed',
	Citex_Edited_Book_Dragdrop_Parts::select_parts( 'EB07', $two_editors ),
	Citex_Edited_Book_Dragdrop_Parts::select_parts( 'EB07', $two_editors )
);
$always_three          = true;
$designation_always_in = true;
$editor_always_in      = true;
for ( $i = 1; $i <= 60; $i++ ) {
	$keys = Citex_Edited_Book_Dragdrop_Parts::select_parts( 'EB' . $i, $two_editors );
	if ( 3 !== count( $keys ) ) {
		$always_three = false;
	}
	if ( ! in_array( 'designation', $keys, true ) ) {
		$designation_always_in = false;
	}
	$has_editor = false;
	foreach ( $keys as $key ) {
		if ( 1 === preg_match( '/^editor_\d+$/', $key ) ) {
			$has_editor = true;
		}
	}
	if ( ! $has_editor ) {
		$editor_always_in = false;
	}
}
check( '[2] select_parts() always returns exactly 3 keys across 60 seeds', $always_three, true );
check( '[2] "designation" is ALWAYS drawn — this category\'s never-traded-away rule', $designation_always_in, true );
check( '[2] the drawn editor is ALWAYS drawn alongside it', $editor_always_in, true );

// ---------------------------------------------------------------------
// 3. "and" eligibility: never for a single editor; sometimes for 2+.
// ---------------------------------------------------------------------
$single_editor  = array( eb_editor( 'Smith', 'J.', 'John Smith' ) );
$and_ever_single = false;
for ( $i = 1; $i <= 60; $i++ ) {
	$keys = Citex_Edited_Book_Dragdrop_Parts::select_parts( 'S' . $i, $single_editor );
	if ( in_array( 'and', $keys, true ) ) {
		$and_ever_single = true;
	}
}
check( '[3] single-editor: "and" is never selected (not eligible with only 1 editor)', $and_ever_single, false );

$and_ever_multi = false;
for ( $i = 1; $i <= 60; $i++ ) {
	$keys = Citex_Edited_Book_Dragdrop_Parts::select_parts( 'M' . $i, $two_editors );
	if ( in_array( 'and', $keys, true ) ) {
		$and_ever_multi = true;
	}
}
check( '[3] two-editor: "and" is selected at least once across 60 seeds', $and_ever_multi, true );

// ---------------------------------------------------------------------
// 4. build(): exact output for hand-picked key sets.
// ---------------------------------------------------------------------
$built = Citex_Edited_Book_Dragdrop_Parts::build( array( 'editor_0', 'designation', 'place' ), $two_editors, $fields );
check( '[4] drawing editor/designation/place: parts (designation value is the bare "eds" — its surrounding parens are literal)', $built['parts'], array( 'Smith, J.', 'eds', 'London' ) );
check( '[4] drawing editor/designation/place: fixedText', $built['fixedText'], '| and Brown, K. (||) (2020) Modern Sociology. ||: Routledge.' );
check( '[4] reconstructs to the full reference', eb_reconstruct( $built ), 'Smith, J. and Brown, K. (eds) (2020) Modern Sociology. London: Routledge.' );

$built_single = Citex_Edited_Book_Dragdrop_Parts::build( array( 'editor_0', 'designation', 'year' ), $single_editor, $fields );
check( '[4] single editor: designation is "ed."', $built_single['parts'][1], 'ed.' );
check( '[4] single editor: reconstructs to the full reference', eb_reconstruct( $built_single ), 'Smith, J. (ed.) (2020) Modern Sociology. London: Routledge.' );

// Parts/confusingWords are ordered by REFERENCE position, not selection
// order: "and" (inside the editor list) precedes "designation" (after the
// year's opening paren) in the actual reference — so with editor_0 also
// drawn, the order here is [editor_0, and, designation].
$built_and = Citex_Edited_Book_Dragdrop_Parts::build( array( 'editor_0', 'designation', 'and' ), $two_editors, $fields );
check( '[4] drawing "and": parts are in reference order', $built_and['parts'], array( 'Smith, J.', 'and', 'eds' ) );
check( '[4] drawing "and": its own confusingWords entry is "&"', $built_and['confusingWords'][1], '&' );
check( '[4] drawing "and": reconstructs to the full reference', eb_reconstruct( $built_and ), 'Smith, J. and Brown, K. (eds) (2020) Modern Sociology. London: Routledge.' );

// ---------------------------------------------------------------------
// 5. Distractor rules: never equal to the correct value.
// ---------------------------------------------------------------------
foreach ( array( array( 'editor_0', 'designation', 'year' ), array( 'editor_0', 'designation', 'title' ), array( 'editor_0', 'designation', 'place' ), array( 'editor_0', 'designation', 'publisher' ) ) as $keys ) {
	$b = Citex_Edited_Book_Dragdrop_Parts::build( $keys, $two_editors, $fields );
	foreach ( $b['parts'] as $index => $part ) {
		check( "[5] distractor for part $index (\"$part\") is never equal to the correct value", $b['confusingWords'][ $index ] === $part, false );
	}
}

// ---------------------------------------------------------------------
// 6. build() returns null for an out-of-range editor index, or an empty
// selection.
// ---------------------------------------------------------------------
check( '[6] an out-of-range editor index returns null', Citex_Edited_Book_Dragdrop_Parts::build( array( 'editor_5' ), $single_editor, $fields ), null );
check( '[6] an empty selection returns null', Citex_Edited_Book_Dragdrop_Parts::build( array(), $single_editor, $fields ), null );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
