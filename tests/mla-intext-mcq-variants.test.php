<?php
/**
 * Unit tests for Citex_MLA_Intext_Mcq_Variants — MLA's in-text citation MCQ
 * catalogue. Critical distinctions under test vs. the Harvard class:
 * 'year_wrongly_included' exists as a distractor kind at all (MLA never
 * shows a year — Harvard has no equivalent mistake, since Harvard DOES use
 * one), and 'page_punctuation' tests the "no comma before the page"
 * MLA-vs-Harvard distinction directly.
 *
 * Repo-level only, run with plain
 * `php tests/mla-intext-mcq-variants.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-intext-citation-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-intext-mcq-variants.php';

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
// 1. Catalogue shape — same 4-variant structure as Harvard.
// ---------------------------------------------------------------------
check( '[1] 4 variants', count( Citex_MLA_Intext_Mcq_Variants::variants() ), 4 );
check( '[1] "page_punctuation" is restricted to parenthetical_quote only', Citex_MLA_Intext_Mcq_Variants::variant_form_requirement( 'page_punctuation' ), array( 'parenthetical_quote' ) );
check( '[1] "join_convention" requires 2+ people', Citex_MLA_Intext_Mcq_Variants::variant_author_requirement( 'join_convention' ), array( 2, PHP_INT_MAX ) );

// ---------------------------------------------------------------------
// 2. 'correct_format' — correctAnswer always matches full_sentence(),
// contains no year.
// ---------------------------------------------------------------------
$fields = array(
	'form'     => 'narrative',
	'who'      => 'Ross',
	'surnames' => array( 'Ross' ),
	'clause'   => 'argues that point matters',
	'page'     => '18',
);
$result = Citex_MLA_Intext_Mcq_Variants::build( 'correct_format', $fields );
check( '[2] correctAnswer matches narrative_sentence() exactly', $result['correctAnswer'], Citex_MLA_Intext_Citation_Rules::narrative_sentence( 'Ross', 'argues that point matters', '18' ) );
check( '[2] exactly 3 wrong options', count( $result['wrongOptions'] ), 3 );
check( '[2] correctAnswer contains no 4-digit year in parentheses', (bool) preg_match( '/\(\d{4}\)/', $result['correctAnswer'] ), false );

// ---------------------------------------------------------------------
// 3. 'page_punctuation' — the two biggest MLA-vs-Harvard page distinctions:
// a comma-inserted wrong option, and a "p." wrongly added wrong option;
// the correct answer has neither.
// ---------------------------------------------------------------------
$quote_fields = array(
	'form'     => 'parenthetical_quote',
	'who'      => 'Ross',
	'surnames' => array( 'Ross' ),
	'clause'   => '',
	'page'     => '22',
	'quote'    => 'a short quote',
);
$pp = Citex_MLA_Intext_Mcq_Variants::build( 'page_punctuation', $quote_fields );
check( '[3] correctAnswer has no comma before the page', false !== strpos( $pp['correctAnswer'], 'Ross 22' ), true );
check( '[3] one wrong option wrongly inserts a comma (the Harvard mistake)', in_array( '"a short quote" (Ross, 22).', $pp['wrongOptions'], true ), true );
check( '[3] one wrong option wrongly adds "p." (the Harvard mistake)', in_array( '"a short quote" (Ross p. 22).', $pp['wrongOptions'], true ), true );

// ---------------------------------------------------------------------
// 4. 'page_punctuation' returns null for a narrative record (mismatch).
// ---------------------------------------------------------------------
check( '[4] page_punctuation for a narrative record returns null', Citex_MLA_Intext_Mcq_Variants::build( 'page_punctuation', $fields ), null );

// ---------------------------------------------------------------------
// 5. 'identify_the_error' — 'year_wrongly_included' must be reachable as a
// TRUE statement (the MLA-specific mistake Harvard's own catalogue has no
// equivalent for), and the correct statement is never duplicated among the
// wrong options.
// ---------------------------------------------------------------------
$seen_year_wrongly_included = false;
for ( $salt = 0; $salt < 50; $salt++ ) {
	$f = array(
		'form'     => 'parenthetical_quote',
		'who'      => 'Ross' . $salt,
		'surnames' => array( 'Ross' . $salt ),
		'clause'   => '',
		'page'     => (string) ( 10 + $salt ),
		'quote'    => 'a short quote ' . $salt,
	);
	$r = Citex_MLA_Intext_Mcq_Variants::build( 'identify_the_error', $f );
	if ( 'A publication year is wrongly included — MLA in-text citations never show the year.' === $r['correctAnswer'] ) {
		$seen_year_wrongly_included = true;
	}
	if ( in_array( $r['correctAnswer'], $r['wrongOptions'], true ) ) {
		check( "[5] correctAnswer never duplicated in wrongOptions (salt=$salt)", true, false );
	}
	check( "[5] exactly 3 wrong options (salt=$salt, no fewer even though the quote form has only 4 total kinds)", count( $r['wrongOptions'] ), 3 );
}
check( '[5] "year_wrongly_included" is reachable as identify_the_error\'s TRUE statement across seeds', $seen_year_wrongly_included, true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
