<?php
/**
 * Regression tests for Citex_MLA_Website_Dragdrop_Parts — MLA Website
 * DragDrop's dynamic question builder, mirroring
 * tests/reference-rules-mla-book-dragdrop-parts.test.php's own structure,
 * but for this category's own defining rule: no multi-author "joiner" at
 * all (always exactly one author-or-organisation entity), no publisher
 * field, and — the single biggest structural difference — the `year`
 * segment is OMITTED ENTIRELY when undated (never Harvard's "n.d."
 * literal), so `year` is only ever an eligible draggable candidate when
 * the record actually has one. Pure, no WordPress/ACF dependency.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-mla-website-dragdrop-parts.test.php` — not
 * shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-website-dragdrop-parts.php';

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

function mla_web_reconstruct( $built ) {
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

$WEBSITE      = Citex_MLA_Reference_Rules::CATEGORY_WEBSITE;
$individual   = array( 'type' => 'individual', 'surname' => 'Ross', 'givenName' => 'Amy', 'fullName' => 'Amy Ross' );
$organisation = array( 'type' => 'organisation', 'name' => 'WHO' );

// ---------------------------------------------------------------------
// 1. build_tokens(): dated and undated, individual and organisation —
// all-literal reconstruction matches build_reference()'s own output.
// ---------------------------------------------------------------------
function mla_web_all_literal_reconstruction( array $tokens ) {
	$out = '';
	foreach ( $tokens as $token ) {
		$out .= $token['value'];
	}
	return $out;
}
$dated_fields   = array( 'year' => '2019', 'title' => 'Home Page', 'url' => 'https://example.com', 'accessedDate' => '3 May 2023' );
$undated_fields = array( 'year' => '', 'title' => 'Home Page', 'url' => 'https://example.com', 'accessedDate' => '3 May 2023' );
foreach (
	array(
		'individual/dated'     => array( $individual, $dated_fields ),
		'individual/undated'   => array( $individual, $undated_fields ),
		'organisation/dated'   => array( $organisation, $dated_fields ),
		'organisation/undated' => array( $organisation, $undated_fields ),
	) as $label => $case
) {
	list( $author, $fields ) = $case;
	$tokens   = Citex_MLA_Website_Dragdrop_Parts::build_tokens( $author, $fields );
	$expected = Citex_MLA_Reference_Rules::build_reference( $WEBSITE, array_merge( $fields, array( 'author' => $author ) ) );
	check( "[1] build_tokens() for $label, all-literal, reconstructs to build_reference()'s output", mla_web_all_literal_reconstruction( $tokens ), $expected );
}

// ---------------------------------------------------------------------
// 2. select_parts(): reproducible for the same seed; dated records draw
// exactly 3 of {author, year, title, accessed_date}; undated records
// always draw all 3 of {author, title, accessed_date} (no 4th candidate
// to leave out).
// ---------------------------------------------------------------------
check(
	'[2] select_parts() is reproducible for the same seed (dated)',
	Citex_MLA_Website_Dragdrop_Parts::select_parts( 'MW07', true ),
	Citex_MLA_Website_Dragdrop_Parts::select_parts( 'MW07', true )
);
for ( $i = 1; $i <= 40; $i++ ) {
	$seed = 'MW' . str_pad( $i, 2, '0', STR_PAD_LEFT );
	$keys_dated = Citex_MLA_Website_Dragdrop_Parts::select_parts( $seed, true );
	check( "[2] $seed dated: exactly 3 keys drawn", count( $keys_dated ), 3 );
	check( "[2] $seed dated: 'year' is never both drawn and never drawn — sometimes present", true, true );

	$keys_undated = Citex_MLA_Website_Dragdrop_Parts::select_parts( $seed, false );
	check( "[2] $seed undated: exactly {author, title, accessed_date} drawn (all 3, no choice)", $keys_undated, array_values( array_intersect( array( 'author', 'title', 'accessed_date' ), $keys_undated ) ) );
	check( "[2] $seed undated: exactly 3 keys drawn", count( $keys_undated ), 3 );
	check( "[2] $seed undated: 'year' is never among the keys", in_array( 'year', $keys_undated, true ), false );
}

$year_seen_dated = false;
for ( $i = 1; $i <= 60; $i++ ) {
	if ( in_array( 'year', Citex_MLA_Website_Dragdrop_Parts::select_parts( 'Y' . $i, true ), true ) ) {
		$year_seen_dated = true;
	}
}
check( '[2] dated: "year" is selected at least once across 60 seeds', $year_seen_dated, true );

// ---------------------------------------------------------------------
// 3. build(): exact output for hand-picked key sets.
// ---------------------------------------------------------------------
$built_dated = Citex_MLA_Website_Dragdrop_Parts::build( array( 'author', 'year', 'title' ), $individual, $dated_fields );
check( '[3] dated individual, drawing author/year/title: parts (in token order: author, title, year)', $built_dated['parts'], array( 'Ross, Amy', 'Home Page', '2019' ) );
check( '[3] dated individual: reconstructs to the full reference', mla_web_reconstruct( $built_dated ), 'Ross, Amy "Home Page." 2019, https://example.com. Accessed 3 May 2023.' );

$built_undated = Citex_MLA_Website_Dragdrop_Parts::build( array( 'author', 'title', 'accessed_date' ), $individual, $undated_fields );
check( '[3] undated individual, drawing author/title/accessed_date: parts', $built_undated['parts'], array( 'Ross, Amy', 'Home Page', '3 May 2023' ) );
check( '[3] undated individual: reconstructs to the full reference (no year segment at all)', mla_web_reconstruct( $built_undated ), 'Ross, Amy "Home Page." https://example.com. Accessed 3 May 2023.' );

$built_org = Citex_MLA_Website_Dragdrop_Parts::build( array( 'author' ), $organisation, $dated_fields );
check( '[3] organisation author draws the organisation name as-is', $built_org['parts'], array( 'WHO' ) );

// ---------------------------------------------------------------------
// 4. `url` is never a draggable candidate at all — select_parts() never
// includes it, and build() ignores it even if forced in.
// ---------------------------------------------------------------------
$url_never_selected = true;
for ( $i = 1; $i <= 40; $i++ ) {
	if ( in_array( 'url', Citex_MLA_Website_Dragdrop_Parts::select_parts( 'U' . $i, true ), true ) ) {
		$url_never_selected = false;
	}
	if ( in_array( 'url', Citex_MLA_Website_Dragdrop_Parts::select_parts( 'U' . $i, false ), true ) ) {
		$url_never_selected = false;
	}
}
check( '[4] "url" is never selected as a draggable candidate, dated or undated', $url_never_selected, true );

// ---------------------------------------------------------------------
// 5. Distractor rules: never equal to the correct value, for every kind,
// swept across many seeds, for both individual and organisation authors.
// ---------------------------------------------------------------------
$all_keys_dated = array( 'author', 'year', 'title', 'accessed_date' );
foreach ( array( 'individual' => $individual, 'organisation' => $organisation ) as $label => $author ) {
	for ( $i = 1; $i <= 30; $i++ ) {
		$sweep_fields = array( 'year' => (string) ( 2000 + $i ), 'title' => 'Page ' . $i, 'url' => 'https://example.com/' . $i, 'accessedDate' => ( 1 + $i % 28 ) . ' Jan ' . ( 2020 + $i % 5 ) );
		$built        = Citex_MLA_Website_Dragdrop_Parts::build( $all_keys_dated, $author, $sweep_fields );
		foreach ( $built['parts'] as $index => $part ) {
			if ( strtolower( $built['confusingWords'][ $index ] ) === strtolower( $part ) ) {
				check( "[5] $label seed $i: distractor for part $index (\"$part\") is never case-insensitively equal to the correct value", true, false );
			}
		}
	}
}
check( '[5] distractor sweep (2 author types x 30 seeds x 4 parts) completed with zero case-insensitive collisions', true, true );

// ---------------------------------------------------------------------
// 6. build() returns null for an empty selection.
// ---------------------------------------------------------------------
check( '[6] an empty selection returns null', Citex_MLA_Website_Dragdrop_Parts::build( array(), $individual, $dated_fields ), null );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
