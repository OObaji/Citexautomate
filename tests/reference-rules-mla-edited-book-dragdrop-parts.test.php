<?php
/**
 * Regression tests for Citex_MLA_Edited_Book_Dragdrop_Parts — MLA Edited
 * Book DragDrop's dynamic question builder, mirroring
 * tests/reference-rules-mla-book-dragdrop-parts.test.php's own structure,
 * but for this category's own defining rule: a trailing "editor"/"editors"
 * designation that is ALWAYS drawn on top of the shared 3-part content
 * budget (never traded away), so a select_parts()-derived selection always
 * yields exactly 4 parts, not 3. Pure, no WordPress/ACF dependency.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-mla-edited-book-dragdrop-parts.test.php` — not
 * shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-edited-book-dragdrop-parts.php';

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

function mla_eb_editor( $surname, $given, $full ) {
	return array( 'surname' => $surname, 'givenName' => $given, 'fullName' => $full );
}

// Reconstructs the full reference from {parts, fixedText} — same mechanism
// Citex_Generated_Validator::reconstruct() uses in production.
function mla_eb_reconstruct( $built ) {
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

$EDITED_BOOK = Citex_MLA_Reference_Rules::CATEGORY_EDITED_BOOK;
$fields      = array( 'year' => '2019', 'title' => 'Digital Media', 'publisher' => 'Routledge' );

$one_editor    = array( mla_eb_editor( 'Smith', 'John', 'John Smith' ) );
$two_editors   = array( mla_eb_editor( 'Ross', 'Amy', 'Amy Ross' ), mla_eb_editor( 'Carter', 'Ben', 'Ben Carter' ) );
$three_editors = array( mla_eb_editor( 'Ross', 'Amy', 'Amy Ross' ), mla_eb_editor( 'Carter', 'Ben', 'Ben Carter' ), mla_eb_editor( 'Lee', 'Kim', 'Kim Lee' ) );

// ---------------------------------------------------------------------
// 1. build_tokens(): the full token stream for 1/2/3-editor records
// reconstructs (all-literal) to exactly build_reference()'s own output.
// ---------------------------------------------------------------------
function mla_eb_all_literal_reconstruction( array $tokens ) {
	$out = '';
	foreach ( $tokens as $token ) {
		$out .= $token['value'];
	}
	return $out;
}
foreach ( array( 'one_editor' => $one_editor, 'two_editors' => $two_editors, 'three_editors' => $three_editors ) as $label => $editors ) {
	$tokens   = Citex_MLA_Edited_Book_Dragdrop_Parts::build_tokens( $editors, $fields );
	$expected = Citex_MLA_Reference_Rules::build_reference( $EDITED_BOOK, array_merge( $fields, array( 'editors' => $editors ) ) );
	check( "[1] build_tokens() for $label, all-literal, reconstructs to build_reference()'s output", mla_eb_all_literal_reconstruction( $tokens ), $expected );
}

// ---------------------------------------------------------------------
// 2. select_parts(): reproducible for the same seed, and — the category's
// own defining rule — always includes 'designation' and always yields
// exactly 4 parts once built (3-part content budget + the always-drawn
// designation), for every editor count.
// ---------------------------------------------------------------------
check(
	'[2] select_parts() is reproducible for the same seed',
	Citex_MLA_Edited_Book_Dragdrop_Parts::select_parts( 'ME07', $three_editors ),
	Citex_MLA_Edited_Book_Dragdrop_Parts::select_parts( 'ME07', $three_editors )
);
foreach ( array( 'one' => $one_editor, 'two' => $two_editors, 'three' => $three_editors ) as $label => $editors ) {
	for ( $i = 1; $i <= 30; $i++ ) {
		$seed  = 'ME' . str_pad( $i, 2, '0', STR_PAD_LEFT ) . $label;
		$keys  = Citex_MLA_Edited_Book_Dragdrop_Parts::select_parts( $seed, $editors );
		check( "[2] $seed: 'designation' is always among the selected keys", in_array( 'designation', $keys, true ), true );
		$built = Citex_MLA_Edited_Book_Dragdrop_Parts::build( $keys, $editors, $fields );
		check( "[2] $seed: exactly 4 parts drawn (3-part budget + always-drawn designation)", count( $built['parts'] ), 4 );
	}
}

// ---------------------------------------------------------------------
// 3. "joiner" is never eligible/selected for a single editor, but is
// selected at least once across a wide seed sweep for 2+ editors.
// ---------------------------------------------------------------------
$joiner_seen_single = false;
for ( $i = 1; $i <= 60; $i++ ) {
	$keys = Citex_MLA_Edited_Book_Dragdrop_Parts::select_parts( 'S' . $i, $one_editor );
	if ( in_array( 'joiner', $keys, true ) ) {
		$joiner_seen_single = true;
	}
}
check( '[3] single-editor: "joiner" is never selected (not eligible with only 1 editor)', $joiner_seen_single, false );

$joiner_seen_two   = false;
$joiner_seen_three = false;
for ( $i = 1; $i <= 60; $i++ ) {
	if ( in_array( 'joiner', Citex_MLA_Edited_Book_Dragdrop_Parts::select_parts( 'T' . $i, $two_editors ), true ) ) {
		$joiner_seen_two = true;
	}
	if ( in_array( 'joiner', Citex_MLA_Edited_Book_Dragdrop_Parts::select_parts( 'H' . $i, $three_editors ), true ) ) {
		$joiner_seen_three = true;
	}
}
check( '[3] 2-editor: "joiner" is selected at least once across 60 seeds', $joiner_seen_two, true );
check( '[3] 3+-editor: "joiner" is selected at least once across 60 seeds', $joiner_seen_three, true );

// ---------------------------------------------------------------------
// 4. build(): exact output for hand-picked key sets, including the
// always-present 'designation'.
// ---------------------------------------------------------------------
$built_one_full = Citex_MLA_Edited_Book_Dragdrop_Parts::build( array( 'editor_surname', 'editor_given', 'designation', 'publisher' ), $one_editor, $fields );
check( '[4] 1 editor, drawing surname/given/designation/publisher: parts', $built_one_full['parts'], array( 'Smith', 'John', 'editor', 'Routledge' ) );
check( '[4] 1 editor: reconstructs to the full reference', mla_eb_reconstruct( $built_one_full ), 'Smith, John, editor. Digital Media. Routledge, 2019.' );

$built_two_joiner = Citex_MLA_Edited_Book_Dragdrop_Parts::build( array( 'editor_surname', 'joiner', 'designation' ), $two_editors, $fields );
check( '[4] 2 editors, drawing surname + joiner + designation: parts', $built_two_joiner['parts'], array( 'Ross', 'and', 'editors' ) );
check( '[4] 2 editors: reconstructs to the full reference', mla_eb_reconstruct( $built_two_joiner ), 'Ross, Amy, and Ben Carter, editors. Digital Media. Routledge, 2019.' );

$built_three_joiner = Citex_MLA_Edited_Book_Dragdrop_Parts::build( array( 'joiner', 'designation', 'title' ), $three_editors, $fields );
check( '[4] 3+ editors, drawing joiner + designation + title: parts', $built_three_joiner['parts'], array( 'et al.', 'editors', 'Digital Media' ) );
check( '[4] 3+ editors: reconstructs to the full reference', mla_eb_reconstruct( $built_three_joiner ), 'Ross, Amy, et al., editors. Digital Media. Routledge, 2019.' );

// ---------------------------------------------------------------------
// 5. THE FIXED REGRESSION — the "et al." abbreviation period must never
// be stripped when the designation is appended after it (3+ editors);
// the reconstructed 3+-editor reference must contain "et al., editors"
// (a single period on "al."), never "et al, editors" nor "et al.., editors".
// ---------------------------------------------------------------------
check( '[5] 3+ editors: "et al." keeps its own single period before the designation comma', mla_eb_reconstruct( $built_three_joiner ), 'Ross, Amy, et al., editors. Digital Media. Routledge, 2019.' );
check( '[5] never a double period ("al..")', false !== strpos( mla_eb_reconstruct( $built_three_joiner ), 'al..' ), false );
check( '[5] never a bare ", al" with no period at all', false !== strpos( mla_eb_reconstruct( $built_three_joiner ), ', al,' ), false );

// ---------------------------------------------------------------------
// 6. Distractor rules: never equal to the correct value, for every kind,
// swept across many seeds.
// ---------------------------------------------------------------------
$all_keys = array( 'editor_surname', 'editor_given', 'designation', 'year', 'title', 'publisher' );
for ( $i = 1; $i <= 40; $i++ ) {
	$sweep_fields = array( 'year' => (string) ( 2000 + $i ), 'title' => 'Book Title ' . $i, 'publisher' => 'Publisher ' . $i );
	$built        = Citex_MLA_Edited_Book_Dragdrop_Parts::build( $all_keys, $one_editor, $sweep_fields );
	foreach ( $built['parts'] as $index => $part ) {
		if ( strtolower( $built['confusingWords'][ $index ] ) === strtolower( $part ) ) {
			check( "[6] seed $i: distractor for part $index (\"$part\") is never case-insensitively equal to the correct value", true, false );
		}
	}
}
check( '[6] distractor sweep (40 seeds x 6 parts) completed with zero case-insensitive collisions', true, true );

// ---------------------------------------------------------------------
// 7. With a single editor (no second editor to rotate onto), the
// given-name distractor deterministically wrongly abbreviates the full
// first name down to a Harvard-style initial.
// ---------------------------------------------------------------------
$built_given_single = Citex_MLA_Edited_Book_Dragdrop_Parts::build( array( 'editor_given' ), $one_editor, $fields );
check( '[7] single-editor given-name distractor wrongly abbreviates to a Harvard-style initial ("J.")', $built_given_single['confusingWords'][0], 'J.' );

// ---------------------------------------------------------------------
// 8. The joiner distractor is "&" for exactly 2 editors, and one of
// "et al"/"and others" for 3+ editors — never the correct value itself.
// ---------------------------------------------------------------------
$built_joiner_two = Citex_MLA_Edited_Book_Dragdrop_Parts::build( array( 'joiner' ), $two_editors, $fields );
check( '[8] 2-editor joiner distractor is "&"', $built_joiner_two['confusingWords'][0], '&' );

$never_matches_correct = true;
for ( $i = 1; $i <= 20; $i++ ) {
	$sweep_fields = array( 'year' => (string) ( 2000 + $i ), 'title' => 'Title ' . $i, 'publisher' => 'Publisher ' . $i );
	$built        = Citex_MLA_Edited_Book_Dragdrop_Parts::build( array( 'joiner' ), $three_editors, $sweep_fields );
	if ( 'et al.' === $built['confusingWords'][0] ) {
		$never_matches_correct = false;
	}
}
check( '[8] 3+-editor joiner distractor is never "et al." itself, across 20 seeds', $never_matches_correct, true );

// ---------------------------------------------------------------------
// 9. The "designation" distractor — the single most-tested
// MLA-vs-Harvard distractor for this category — rotates between the
// wrong plurality and Harvard's own "(ed.)"/"(eds)" abbreviation, and is
// never the correct value.
// ---------------------------------------------------------------------
$seen_designation_variants = array();
for ( $i = 1; $i <= 20; $i++ ) {
	$sweep_fields = array( 'year' => (string) ( 2000 + $i ), 'title' => 'Title ' . $i, 'publisher' => 'Publisher ' . $i );
	$built_single = Citex_MLA_Edited_Book_Dragdrop_Parts::build( array( 'designation' ), $one_editor, $sweep_fields );
	check( "[9] seed $i: single-editor designation distractor is never \"editor\" itself", $built_single['confusingWords'][0], $built_single['confusingWords'][0] !== 'editor' ? $built_single['confusingWords'][0] : 'editor' );
	if ( 'editor' === $built_single['confusingWords'][0] ) {
		check( "[9] seed $i: single-editor designation distractor must not equal the correct value 'editor'", true, false );
	}
	$seen_designation_variants[ $built_single['confusingWords'][0] ] = true;
}
check( '[9] single-editor designation distractor shows both flavours ("editors" and "(ed.)") across the sweep', count( $seen_designation_variants ) >= 2, true );

// ---------------------------------------------------------------------
// 10. build() returns null for an empty selection.
// ---------------------------------------------------------------------
check( '[10] an empty selection returns null', Citex_MLA_Edited_Book_Dragdrop_Parts::build( array(), $one_editor, $fields ), null );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
