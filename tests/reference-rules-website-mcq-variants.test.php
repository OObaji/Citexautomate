<?php
/**
 * Pure-logic regression tests for Citex_Website_Mcq_Variants — no
 * WordPress/ACF stubs needed, since the class is pure and static (see its
 * own docblock). Covers variant selection, per-variant build() output for
 * both author types, and the deterministic distractor rules never
 * accidentally equalling the correct value — the same coverage style as
 * tests/reference-rules-book-dragdrop-parts.test.php.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-website-mcq-variants.test.php` — not shipped
 * in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-website-mcq-variants.php';

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

$individual = array( 'type' => 'individual', 'surname' => 'Ross', 'initials' => 'T.', 'fullName' => 'Tom Ross' );
$organisation = array( 'type' => 'organisation', 'name' => 'British Council' );
$fields_ind = array( 'author' => $individual, 'year' => '2022', 'title' => 'Study guide', 'publisher' => 'SAGE', 'url' => 'https://www.sage.com', 'accessedDate' => '12 September 2026' );
$fields_org = array( 'author' => $organisation, 'year' => 'n.d.', 'title' => 'About us', 'publisher' => 'BBC', 'url' => 'https://www.bbc.co.uk', 'accessedDate' => '12 September 2026' );

$variants = Citex_Website_Mcq_Variants::variants();

// ---------------------------------------------------------------------
// 1. variant_for() is reproducible for the same seed, and only ever
// returns a known variant id.
// ---------------------------------------------------------------------
check( '[1] variant_for() is reproducible for the same seed', Citex_Website_Mcq_Variants::variant_for( 'WR07' ), Citex_Website_Mcq_Variants::variant_for( 'WR07' ) );
$all_known = true;
for ( $i = 1; $i <= 60; $i++ ) {
	if ( ! in_array( Citex_Website_Mcq_Variants::variant_for( 'WR' . $i ), $variants, true ) ) {
		$all_known = false;
	}
}
check( '[1] variant_for() only ever returns a known variant across 60 seeds', $all_known, true );

// ---------------------------------------------------------------------
// 2. Genuine variety across a wide seed sweep — every variant is drawn at
// least once (the whole point of this catalogue).
// ---------------------------------------------------------------------
$seen = array_fill_keys( $variants, false );
for ( $i = 1; $i <= 300; $i++ ) {
	$seen[ Citex_Website_Mcq_Variants::variant_for( 'V' . $i ) ] = true;
}
check( '[2] every variant is drawn at least once across 300 seeds', array_filter( $seen ), $seen );

// ---------------------------------------------------------------------
// 3. build() returns exactly {stem, wrongOptions (3), correctAnswer} for
// every variant, for both author types, and never returns null for a
// known variant.
// ---------------------------------------------------------------------
foreach ( $variants as $variant ) {
	$built_ind = Citex_Website_Mcq_Variants::build( $variant, $fields_ind );
	check( "[3] $variant: build() for an individual author is non-null", null !== $built_ind, true );
	if ( null !== $built_ind ) {
		check( "[3] $variant (individual): has a non-empty stem", '' !== trim( $built_ind['stem'] ), true );
		check( "[3] $variant (individual): has exactly 3 wrong options", count( $built_ind['wrongOptions'] ), 3 );
		check( "[3] $variant (individual): has a non-empty correct answer", '' !== trim( $built_ind['correctAnswer'] ), true );
		check( "[3] $variant (individual): correct answer never equals any wrong option", in_array( $built_ind['correctAnswer'], $built_ind['wrongOptions'], true ), false );
		check( "[3] $variant (individual): wrong options are mutually distinct", count( array_unique( $built_ind['wrongOptions'] ) ), 3 );
	}
	$built_org = Citex_Website_Mcq_Variants::build( $variant, $fields_org );
	check( "[3] $variant: build() for an organisation author is non-null", null !== $built_org, true );
	if ( null !== $built_org ) {
		check( "[3] $variant (organisation): correct answer never equals any wrong option", in_array( $built_org['correctAnswer'], $built_org['wrongOptions'], true ), false );
		check( "[3] $variant (organisation): wrong options are mutually distinct", count( array_unique( $built_org['wrongOptions'] ) ), 3 );
	}
}

// ---------------------------------------------------------------------
// 4. An unrecognised variant id returns null.
// ---------------------------------------------------------------------
check( '[4] an unrecognised variant id returns null', Citex_Website_Mcq_Variants::build( 'nonexistent_variant', $fields_ind ), null );

// ---------------------------------------------------------------------
// 5. build() is deterministic: calling it twice with identical inputs
// reproduces identical output — the property the exact-match validator
// depends on.
// ---------------------------------------------------------------------
$a = Citex_Website_Mcq_Variants::build( 'complete_reference', $fields_ind );
$b = Citex_Website_Mcq_Variants::build( 'complete_reference', $fields_ind );
check( '[5] build() is deterministic for identical inputs', $a, $b );

// ---------------------------------------------------------------------
// 6. complete_reference: hand-verified exact output for a known record.
// ---------------------------------------------------------------------
$complete = Citex_Website_Mcq_Variants::build( 'complete_reference', $fields_ind );
check( '[6] complete_reference: correct answer', $complete['correctAnswer'], 'Ross, T. (2022) Study guide [online]. SAGE. Available from: <https://www.sage.com> [accessed 12 September 2026].' );
check( '[6] complete_reference: stem', $complete['stem'], 'Which option is the correctly formatted Harvard website reference?' );

// ---------------------------------------------------------------------
// 7. reference_structure: fully static — identical output regardless of
// the record's own fields (see website_independent_answer_variants()).
// ---------------------------------------------------------------------
$structure_ind = Citex_Website_Mcq_Variants::build( 'reference_structure', $fields_ind );
$structure_org = Citex_Website_Mcq_Variants::build( 'reference_structure', $fields_org );
check( '[7] reference_structure: identical output regardless of the record', $structure_ind, $structure_org );
check( '[7] reference_structure: is listed as an independent-answer variant', in_array( 'reference_structure', Citex_Website_Mcq_Variants::website_independent_answer_variants(), true ), true );

// ---------------------------------------------------------------------
// 8. author_or_organisation_format: individual branch produces the
// surname/initials order-swap distractor explicitly requested (e.g.
// "T., Ross" instead of "Ross, T."), and the organisation branch produces
// a comma-inverted distractor (treating the org name as a person's name).
// ---------------------------------------------------------------------
$author_format_ind = Citex_Website_Mcq_Variants::build( 'author_or_organisation_format', $fields_ind );
check( '[8] individual: correct answer is "Ross, T."', $author_format_ind['correctAnswer'], 'Ross, T.' );
check( '[8] individual: the order-swap distractor "T., Ross" is present', in_array( 'T., Ross', $author_format_ind['wrongOptions'], true ), true );

$author_format_org = Citex_Website_Mcq_Variants::build( 'author_or_organisation_format', $fields_org );
check( '[8] organisation: correct answer is the plain name', $author_format_org['correctAnswer'], 'British Council' );
check( '[8] organisation: the comma-inverted distractor "Council, British" is present', in_array( 'Council, British', $author_format_org['wrongOptions'], true ), true );

// ---------------------------------------------------------------------
// 8b. CRITICAL — a real reported bug: an ALL-CAPS distractor is
// case-insensitively IDENTICAL to the correct answer, so
// Citex_Generated_Validator's (case-insensitive) MCQ_OPTION_MATCHES_ANSWER
// check always flagged it as a duplicate of the answer, failing the whole
// question every time. None of the organisation distractors may ever be a
// pure case transformation of the correct name — every option must differ
// by more than case, for a range of organisation names including a
// SINGLE-WORD one (where a naive "reverse word order" distractor would
// also collapse to the same string as the correct answer).
// ---------------------------------------------------------------------
foreach ( array( 'Health Action', 'British Council', 'NASA', 'WHO' ) as $org_name ) {
	$built = Citex_Website_Mcq_Variants::build( 'author_or_organisation_format', array(
		'author' => array( 'type' => 'organisation', 'name' => $org_name ),
		'year' => '2020', 'title' => 'Report', 'publisher' => 'WHO', 'url' => 'https://www.who.int', 'accessedDate' => '12 September 2026',
	) );
	foreach ( $built['wrongOptions'] as $index => $option ) {
		check(
			"[8b] \"$org_name\": distractor $index is not merely a case transformation of the correct answer",
			strtolower( trim( preg_replace( '/\s+/', ' ', $option ) ) ) === strtolower( trim( preg_replace( '/\s+/', ' ', $built['correctAnswer'] ) ) ),
			false
		);
	}
}

// ---------------------------------------------------------------------
// 9. identify_the_error: the stem embeds a reference with exactly one
// structural mistake, and the correct answer is a statement (not a
// reference string).
// ---------------------------------------------------------------------
$identify = Citex_Website_Mcq_Variants::build( 'identify_the_error', $fields_ind );
check( '[9] the correct answer is a plain-English statement, not a reference', false !== strpos( $identify['correctAnswer'], 'Ross, T.' ), false );
check( '[9] the stem contains a broken reference for this record', false !== strpos( $identify['stem'], 'Ross, T.' ), true );

// ---------------------------------------------------------------------
// 10. CRITICAL — not_a_correct_reference: the requested variant. Its
// correctAnswer is the ONE flawed reference; its 3 wrongOptions are
// genuinely, independently valid Harvard website references for
// different sources (never the same as the canonical record's own
// correctly-formatted reference).
// ---------------------------------------------------------------------
$not_correct = Citex_Website_Mcq_Variants::build( 'not_a_correct_reference', $fields_ind );
check( '[10] stem matches the requested wording exactly', $not_correct['stem'], 'Which of the following is NOT a correct Harvard reference for a website?' );
check( '[10] the canonical record\'s own correct reference is never one of the "valid" options', in_array( Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_WEBSITE, $fields_ind ), $not_correct['wrongOptions'], true ), false );
foreach ( $not_correct['wrongOptions'] as $index => $option ) {
	check( "[10] valid option $index matches the full Harvard website format", 1 === preg_match( Citex_Reference_Rules::format_regex( Citex_Reference_Rules::CATEGORY_WEBSITE ), $option ), true );
}
check( '[10] the flawed correctAnswer does NOT match the full Harvard website format', 1 === preg_match( Citex_Reference_Rules::format_regex( Citex_Reference_Rules::CATEGORY_WEBSITE ), $not_correct['correctAnswer'] ), false );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
