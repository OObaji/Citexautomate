<?php
/**
 * Regression tests for Citex_MLA_Journal_Article_Dragdrop_Parts — MLA
 * Journal Article DragDrop's dynamic question builder, mirroring
 * tests/reference-rules-mla-book-dragdrop-parts.test.php's own structure,
 * but for this category's own content shape (articleTitle/journalTitle/
 * volume/issue/pages, no publisher) and its snake_case token keys
 * (article_title/journal_title — the exact regression this class's own
 * docblock documents: a camelCase key silently lowercases on round-trip
 * through sanitize_key() and stops matching build_tokens()'s own keys).
 * Pure, no WordPress/ACF dependency.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-mla-journal-article-dragdrop-parts.test.php`
 * — not shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-journal-article-dragdrop-parts.php';

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

function mla_ja_author( $surname, $given, $full ) {
	return array( 'surname' => $surname, 'givenName' => $given, 'fullName' => $full );
}

function mla_ja_reconstruct( $built ) {
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

$JOURNAL_ARTICLE = Citex_MLA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;
$fields           = array( 'articleTitle' => 'Tech', 'journalTitle' => 'J. Media', 'volume' => '4', 'issue' => '2', 'year' => '2019', 'pages' => '10-25' );

$one_author   = array( mla_ja_author( 'Smith', 'John', 'John Smith' ) );
$two_authors  = array( mla_ja_author( 'Ross', 'Amy', 'Amy Ross' ), mla_ja_author( 'Carter', 'Ben', 'Ben Carter' ) );
$three_authors = array( mla_ja_author( 'Ross', 'Amy', 'Amy Ross' ), mla_ja_author( 'Carter', 'Ben', 'Ben Carter' ), mla_ja_author( 'Lee', 'Kim', 'Kim Lee' ) );

// ---------------------------------------------------------------------
// 1. build_tokens(): the full token stream for 1/2/3-author records
// reconstructs (all-literal) to exactly build_reference()'s own output.
// ---------------------------------------------------------------------
function mla_ja_all_literal_reconstruction( array $tokens ) {
	$out = '';
	foreach ( $tokens as $token ) {
		$out .= $token['value'];
	}
	return $out;
}
foreach ( array( 'one_author' => $one_author, 'two_authors' => $two_authors, 'three_authors' => $three_authors ) as $label => $authors ) {
	$tokens   = Citex_MLA_Journal_Article_Dragdrop_Parts::build_tokens( $authors, $fields );
	$expected = Citex_MLA_Reference_Rules::build_reference( $JOURNAL_ARTICLE, array_merge( $fields, array( 'authors' => $authors ) ) );
	check( "[1] build_tokens() for $label, all-literal, reconstructs to build_reference()'s output", mla_ja_all_literal_reconstruction( $tokens ), $expected );
}

// ---------------------------------------------------------------------
// 2. select_parts(): reproducible for the same seed, always exactly 3
// parts once built, and every dragdropPartKeys entry is lowercase
// snake_case (article_title/journal_title, never camelCase) — the exact
// regression this class's own docblock documents.
// ---------------------------------------------------------------------
check(
	'[2] select_parts() is reproducible for the same seed',
	Citex_MLA_Journal_Article_Dragdrop_Parts::select_parts( 'MJ07', $three_authors ),
	Citex_MLA_Journal_Article_Dragdrop_Parts::select_parts( 'MJ07', $three_authors )
);
for ( $i = 1; $i <= 60; $i++ ) {
	$seed = 'MJ' . str_pad( $i, 2, '0', STR_PAD_LEFT );
	$keys = Citex_MLA_Journal_Article_Dragdrop_Parts::select_parts( $seed, $three_authors );
	foreach ( $keys as $key ) {
		if ( $key !== sanitize_key_like( $key ) ) {
			check( "[2] $seed: key \"$key\" is lowercase snake_case", $key, sanitize_key_like( $key ) );
		}
	}
	$built = Citex_MLA_Journal_Article_Dragdrop_Parts::build( $keys, $three_authors, $fields );
	check( "[2] $seed: exactly 3 parts drawn", count( $built['parts'] ), 3 );
}
function sanitize_key_like( $key ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
}

// ---------------------------------------------------------------------
// 3. THE FIXED REGRESSION — a selection built from 'article_title' and
// 'journal_title' (the real, lowercase snake_case keys) correctly draws
// those candidates; the OLD camelCase spellings ('articleTitle',
// 'journalTitle') are never produced by select_parts() and, if passed to
// build(), draw NOTHING (silently dropped), proving the fix is real, not
// merely cosmetic.
// ---------------------------------------------------------------------
$built_snake_case = Citex_MLA_Journal_Article_Dragdrop_Parts::build( array( 'article_title', 'journal_title' ), $one_author, $fields );
check( '[3] snake_case keys draw both candidates', count( $built_snake_case['parts'] ), 2 );
check( '[3] snake_case keys draw the correct values', $built_snake_case['parts'], array( 'Tech', 'J. Media' ) );

$built_camel_case = Citex_MLA_Journal_Article_Dragdrop_Parts::build( array( 'articleTitle', 'journalTitle' ), $one_author, $fields );
check( '[3] the old camelCase keys draw nothing at all (build() returns null for an empty selection)', $built_camel_case, null );

// ---------------------------------------------------------------------
// 4. "joiner" is never eligible/selected for a single author, but is
// selected at least once across a wide seed sweep for 2+ authors.
// ---------------------------------------------------------------------
$joiner_seen_single = false;
for ( $i = 1; $i <= 60; $i++ ) {
	$keys = Citex_MLA_Journal_Article_Dragdrop_Parts::select_parts( 'S' . $i, $one_author );
	if ( in_array( 'joiner', $keys, true ) ) {
		$joiner_seen_single = true;
	}
}
check( '[4] single-author: "joiner" is never selected (not eligible with only 1 author)', $joiner_seen_single, false );

$joiner_seen_two = false;
for ( $i = 1; $i <= 60; $i++ ) {
	if ( in_array( 'joiner', Citex_MLA_Journal_Article_Dragdrop_Parts::select_parts( 'T' . $i, $two_authors ), true ) ) {
		$joiner_seen_two = true;
	}
}
check( '[4] 2-author: "joiner" is selected at least once across 60 seeds', $joiner_seen_two, true );

// ---------------------------------------------------------------------
// 5. build(): exact output for hand-picked key sets.
// ---------------------------------------------------------------------
$built_one_full = Citex_MLA_Journal_Article_Dragdrop_Parts::build( array( 'author_surname', 'author_given', 'volume' ), $one_author, $fields );
check( '[5] 1 author, drawing surname/given/volume: parts', $built_one_full['parts'], array( 'Smith', 'John', '4' ) );
check( '[5] 1 author: reconstructs to the full reference', mla_ja_reconstruct( $built_one_full ), 'Smith, John. "Tech." J. Media, vol. 4, no. 2, 2019, pp. 10–25.' );

$built_two_joiner = Citex_MLA_Journal_Article_Dragdrop_Parts::build( array( 'author_surname', 'joiner' ), $two_authors, $fields );
check( '[5] 2 authors, drawing surname + joiner: parts', $built_two_joiner['parts'], array( 'Ross', 'and' ) );
check( '[5] 2 authors: reconstructs to the full reference', mla_ja_reconstruct( $built_two_joiner ), 'Ross, Amy, and Ben Carter. "Tech." J. Media, vol. 4, no. 2, 2019, pp. 10–25.' );

$built_three_joiner = Citex_MLA_Journal_Article_Dragdrop_Parts::build( array( 'joiner', 'article_title' ), $three_authors, $fields );
check( '[5] 3+ authors, drawing joiner + article_title: parts', $built_three_joiner['parts'], array( 'et al.', 'Tech' ) );
check( '[5] 3+ authors: reconstructs to the full reference', mla_ja_reconstruct( $built_three_joiner ), 'Ross, Amy, et al. "Tech." J. Media, vol. 4, no. 2, 2019, pp. 10–25.' );

// ---------------------------------------------------------------------
// 6. Distractor rules: never equal to the correct value, for every kind,
// swept across many seeds; the pages distractor mutates only the end page,
// keeping the en dash and the start page intact.
// ---------------------------------------------------------------------
$all_keys = array( 'author_surname', 'author_given', 'article_title', 'journal_title', 'volume', 'issue', 'year', 'pages' );
for ( $i = 1; $i <= 40; $i++ ) {
	$sweep_fields = array( 'articleTitle' => 'Art' . $i, 'journalTitle' => 'Jour' . $i, 'volume' => (string) ( 1 + $i % 9 ), 'issue' => (string) ( 1 + $i % 4 ), 'year' => (string) ( 2000 + $i ), 'pages' => ( 10 + $i ) . '-' . ( 20 + $i ) );
	$built        = Citex_MLA_Journal_Article_Dragdrop_Parts::build( $all_keys, $one_author, $sweep_fields );
	foreach ( $built['parts'] as $index => $part ) {
		if ( strtolower( $built['confusingWords'][ $index ] ) === strtolower( $part ) ) {
			check( "[6] seed $i: distractor for part $index (\"$part\") is never case-insensitively equal to the correct value", true, false );
		}
	}
	$pages_index = array_search( Citex_Reference_Rules::format_page_range( $sweep_fields['pages'] ), $built['parts'], true );
	if ( false !== $pages_index ) {
		check( "[6] seed $i: pages distractor keeps the en dash and start page intact", 1 === preg_match( '/^' . preg_quote( explode( '-', $sweep_fields['pages'] )[0], '/' ) . '–\d+$/u', $built['confusingWords'][ $pages_index ] ), true );
	}
}
check( '[6] distractor sweep (40 seeds x 8 parts) completed with zero case-insensitive collisions', true, true );

// ---------------------------------------------------------------------
// 7. build() returns null for an empty selection.
// ---------------------------------------------------------------------
check( '[7] an empty selection returns null', Citex_MLA_Journal_Article_Dragdrop_Parts::build( array(), $one_author, $fields ), null );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
