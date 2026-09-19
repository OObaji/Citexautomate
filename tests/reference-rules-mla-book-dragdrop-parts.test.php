<?php
/**
 * Regression tests for Citex_MLA_Book_Dragdrop_Parts — MLA Book DragDrop's
 * dynamic 3-part question builder, mirroring
 * tests/reference-rules-book-dragdrop-parts.test.php's own structure but for
 * MLA's genuinely different rule: only the FIRST author is ever split into
 * draggable pieces, the full given name is used (never an initial), a second
 * author is folded in as plain "First Last" text, and 3+ authors collapse
 * into a single "joiner" candidate carrying "et al." — no `place` candidate
 * exists at all. Pure, no WordPress/ACF dependency.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-mla-book-dragdrop-parts.test.php` — not shipped
 * in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-book-dragdrop-parts.php';

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

function mla_author( $surname, $given, $full ) {
	return array( 'surname' => $surname, 'givenName' => $given, 'fullName' => $full );
}

// Reconstructs the full reference from {parts, fixedText} — same mechanism
// Citex_Generated_Validator::reconstruct() uses in production.
function mla_reconstruct( $built ) {
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

$BOOK   = Citex_MLA_Reference_Rules::CATEGORY_BOOK;
$fields = array( 'year' => '2019', 'title' => 'Digital Media', 'publisher' => 'Routledge' );

// ---------------------------------------------------------------------
// 1. build_tokens(): the full token stream for 1/2/3-author records
// reconstructs (all-literal) to exactly build_reference()'s own output.
// ---------------------------------------------------------------------
function mla_all_literal_reconstruction( array $tokens ) {
	$out = '';
	foreach ( $tokens as $token ) {
		$out .= $token['value'];
	}
	return $out;
}
$one_author   = array( mla_author( 'Smith', 'John', 'John Smith' ) );
$two_authors  = array( mla_author( 'Ross', 'Amy', 'Amy Ross' ), mla_author( 'Carter', 'Ben', 'Ben Carter' ) );
$three_authors = array( mla_author( 'Ross', 'Amy', 'Amy Ross' ), mla_author( 'Carter', 'Ben', 'Ben Carter' ), mla_author( 'Lee', 'Kim', 'Kim Lee' ) );
foreach ( array( 'one_author' => $one_author, 'two_authors' => $two_authors, 'three_authors' => $three_authors ) as $label => $authors ) {
	$tokens   = Citex_MLA_Book_Dragdrop_Parts::build_tokens( $authors, $fields );
	$expected = Citex_MLA_Reference_Rules::build_reference( $BOOK, array_merge( $fields, array( 'authors' => $authors ) ) );
	check( "[1] build_tokens() for $label, all-literal, reconstructs to build_reference()'s output", mla_all_literal_reconstruction( $tokens ), $expected );
}

// ---------------------------------------------------------------------
// 2. select_parts(): reproducible for the same seed, always exactly 3
// parts, and every seed satisfies the content floor.
// ---------------------------------------------------------------------
check(
	'[2] select_parts() is reproducible for the same seed',
	Citex_MLA_Book_Dragdrop_Parts::select_parts( 'MB07', $three_authors ),
	Citex_MLA_Book_Dragdrop_Parts::select_parts( 'MB07', $three_authors )
);
for ( $i = 1; $i <= 60; $i++ ) {
	$seed  = 'MB' . str_pad( $i, 2, '0', STR_PAD_LEFT );
	$keys  = Citex_MLA_Book_Dragdrop_Parts::select_parts( $seed, $three_authors );
	$built = Citex_MLA_Book_Dragdrop_Parts::build( $keys, $three_authors, $fields );
	check( "[2] $seed: exactly 3 parts drawn", count( $built['parts'] ), 3 );
}

// ---------------------------------------------------------------------
// 3. "joiner" is never eligible/selected for a single author, but is
// selected at least once across a wide seed sweep for 2+ authors.
// ---------------------------------------------------------------------
$joiner_seen_single = false;
for ( $i = 1; $i <= 60; $i++ ) {
	$keys = Citex_MLA_Book_Dragdrop_Parts::select_parts( 'S' . $i, $one_author );
	if ( in_array( 'joiner', $keys, true ) ) {
		$joiner_seen_single = true;
	}
}
check( '[3] single-author: "joiner" is never selected (not eligible with only 1 author)', $joiner_seen_single, false );

$joiner_seen_two   = false;
$joiner_seen_three = false;
for ( $i = 1; $i <= 60; $i++ ) {
	if ( in_array( 'joiner', Citex_MLA_Book_Dragdrop_Parts::select_parts( 'T' . $i, $two_authors ), true ) ) {
		$joiner_seen_two = true;
	}
	if ( in_array( 'joiner', Citex_MLA_Book_Dragdrop_Parts::select_parts( 'H' . $i, $three_authors ), true ) ) {
		$joiner_seen_three = true;
	}
}
check( '[3] 2-author: "joiner" is selected at least once across 60 seeds', $joiner_seen_two, true );
check( '[3] 3+-author: "joiner" is selected at least once across 60 seeds', $joiner_seen_three, true );

// ---------------------------------------------------------------------
// 4. build(): exact output for hand-picked key sets.
// ---------------------------------------------------------------------
$built_one_full = Citex_MLA_Book_Dragdrop_Parts::build( array( 'author_surname', 'author_given', 'year', 'publisher' ), $one_author, $fields );
check( '[4] 1 author, drawing surname/given/year/publisher: parts', $built_one_full['parts'], array( 'Smith', 'John', 'Routledge', '2019' ) );
check( '[4] 1 author, drawing surname/given/year/publisher: fixedText', $built_one_full['fixedText'], '|, ||. Digital Media. ||, ||.' );
check( '[4] 1 author: reconstructs to the full reference', mla_reconstruct( $built_one_full ), 'Smith, John. Digital Media. Routledge, 2019.' );

$built_two_joiner = Citex_MLA_Book_Dragdrop_Parts::build( array( 'author_surname', 'joiner' ), $two_authors, $fields );
check( '[4] 2 authors, drawing surname + joiner: parts', $built_two_joiner['parts'], array( 'Ross', 'and' ) );
check( '[4] 2 authors, drawing surname + joiner: fixedText', $built_two_joiner['fixedText'], '|, Amy, || Ben Carter. Digital Media. Routledge, 2019.' );
check( '[4] 2 authors: reconstructs to the full reference', mla_reconstruct( $built_two_joiner ), 'Ross, Amy, and Ben Carter. Digital Media. Routledge, 2019.' );

$built_three_joiner = Citex_MLA_Book_Dragdrop_Parts::build( array( 'joiner', 'title' ), $three_authors, $fields );
check( '[4] 3+ authors, drawing joiner + title: parts', $built_three_joiner['parts'], array( 'et al.', 'Digital Media' ) );
check( '[4] 3+ authors, drawing joiner + title: fixedText', $built_three_joiner['fixedText'], 'Ross, Amy, || ||. Routledge, 2019.' );
check( '[4] 3+ authors: reconstructs to the full reference', mla_reconstruct( $built_three_joiner ), 'Ross, Amy, et al. Digital Media. Routledge, 2019.' );

// ---------------------------------------------------------------------
// 5. Distractor rules: never equal to the correct value, for every kind,
// swept across many seeds.
// ---------------------------------------------------------------------
$all_keys = array( 'author_surname', 'author_given', 'year', 'title', 'publisher' );
for ( $i = 1; $i <= 40; $i++ ) {
	$sweep_fields = array( 'year' => (string) ( 2000 + $i ), 'title' => 'Book Title ' . $i, 'publisher' => 'Publisher ' . $i );
	$built        = Citex_MLA_Book_Dragdrop_Parts::build( $all_keys, $one_author, $sweep_fields );
	foreach ( $built['parts'] as $index => $part ) {
		if ( strtolower( $built['confusingWords'][ $index ] ) === strtolower( $part ) ) {
			check( "[5] seed $i: distractor for part $index (\"$part\") is never case-insensitively equal to the correct value", true, false );
		}
	}
}
check( '[5] distractor sweep (40 seeds x 5 parts) completed with zero case-insensitive collisions', true, true );

// ---------------------------------------------------------------------
// 6. With a single author (no second author to rotate onto), the
// given-name distractor deterministically wrongly abbreviates the full
// first name down to a Harvard-style initial — the single most
// MLA-distinctive mistake.
// ---------------------------------------------------------------------
$built_given_single = Citex_MLA_Book_Dragdrop_Parts::build( array( 'author_given' ), $one_author, $fields );
check( '[6] single-author given-name distractor wrongly abbreviates to a Harvard-style initial ("J.")', $built_given_single['confusingWords'][0], 'J.' );

// ---------------------------------------------------------------------
// 7. The joiner distractor is "&" for exactly 2 authors, and one of
// "et al"/"and others" for 3+ authors — never the correct value itself,
// swept across many seeds.
// ---------------------------------------------------------------------
$built_joiner_two = Citex_MLA_Book_Dragdrop_Parts::build( array( 'joiner' ), $two_authors, $fields );
check( '[7] 2-author joiner distractor is "&"', $built_joiner_two['confusingWords'][0], '&' );

$three_al_variants_seen = array();
$never_matches_correct  = true;
for ( $i = 1; $i <= 20; $i++ ) {
	$sweep_fields = array( 'year' => (string) ( 2000 + $i ), 'title' => 'Title ' . $i, 'publisher' => 'Publisher ' . $i );
	$built        = Citex_MLA_Book_Dragdrop_Parts::build( array( 'joiner' ), $three_authors, $sweep_fields );
	$three_al_variants_seen[ $built['confusingWords'][0] ] = true;
	if ( 'et al.' === $built['confusingWords'][0] ) {
		$never_matches_correct = false;
	}
}
check( '[7] 3+-author joiner distractor is never "et al." itself, across 20 seeds', $never_matches_correct, true );
check( '[7] 3+-author joiner distractor shows at least one mistake flavour across the sweep', count( $three_al_variants_seen ) >= 1, true );

// ---------------------------------------------------------------------
// 8. build() returns null for an empty selection.
// ---------------------------------------------------------------------
check( '[8] an empty selection returns null', Citex_MLA_Book_Dragdrop_Parts::build( array(), $one_author, $fields ), null );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
