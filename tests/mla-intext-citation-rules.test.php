<?php
/**
 * Unit tests for Citex_MLA_Intext_Citation_Rules — MLA's in-text citation
 * rule: author-page, NO year at all in-text (unlike Harvard's author-year),
 * and "et al." at 3+ (matching MLA's own reference-list threshold, unlike
 * Harvard's split 4+ in-text / never-in-reference-list rule).
 *
 * Repo-level only, run with plain
 * `php tests/mla-intext-citation-rules.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-intext-citation-rules.php';

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

function person( $surname ) {
	return array( 'surname' => $surname );
}

// ---------------------------------------------------------------------
// 1. forms().
// ---------------------------------------------------------------------
check( '[1] forms() returns exactly narrative/parenthetical/parenthetical_quote', Citex_MLA_Intext_Citation_Rules::forms(), array( 'narrative', 'parenthetical', 'parenthetical_quote' ) );

// ---------------------------------------------------------------------
// 2. join_people_intext() — "et al." at 3+ (MLA's own threshold), no comma
// before "and" for 2 people.
// ---------------------------------------------------------------------
check( '[2] 1 person', Citex_MLA_Intext_Citation_Rules::join_people_intext( array( person( 'Ross' ) ) ), 'Ross' );
check( '[2] 2 people: no comma before "and"', Citex_MLA_Intext_Citation_Rules::join_people_intext( array( person( 'Ross' ), person( 'Carter' ) ) ), 'Ross and Carter' );
check( '[2] 3 people uses "et al." (MLA\'s own reference-list threshold)', Citex_MLA_Intext_Citation_Rules::join_people_intext( array( person( 'Ross' ), person( 'Carter' ), person( 'Lee' ) ) ), 'Ross et al.' );
check( '[2] 4 people also uses "et al." off the first surname only', Citex_MLA_Intext_Citation_Rules::join_people_intext( array( person( 'Ross' ), person( 'Carter' ), person( 'Lee' ), person( 'Kaur' ) ) ), 'Ross et al.' );

// ---------------------------------------------------------------------
// 3. display_person_or_org().
// ---------------------------------------------------------------------
check( '[3] individual author shows the surname only', Citex_MLA_Intext_Citation_Rules::display_person_or_org( array( 'type' => 'individual', 'surname' => 'Ross' ) ), 'Ross' );
check( '[3] organisation author shows the organisation name as-is', Citex_MLA_Intext_Citation_Rules::display_person_or_org( array( 'type' => 'organisation', 'name' => 'WHO' ) ), 'WHO' );

// ---------------------------------------------------------------------
// 4. Full sentences — CRITICALLY, no year appears anywhere.
// ---------------------------------------------------------------------
check(
	'[4] narrative WITH a page: "Who clause (Page)."',
	Citex_MLA_Intext_Citation_Rules::narrative_sentence( 'Ross', 'argues that point matters', '18' ),
	'Ross argues that point matters (18).'
);
check(
	'[4] narrative WITHOUT a page (unpaginated source): "Who clause."',
	Citex_MLA_Intext_Citation_Rules::narrative_sentence( 'Ross', 'argues that point matters', null ),
	'Ross argues that point matters.'
);
check( '[4] no narrative output anywhere contains a 4-digit year in parentheses', (bool) preg_match( '/\(\d{4}\)/', Citex_MLA_Intext_Citation_Rules::narrative_sentence( 'Ross', 'argues that point matters', '18' ) ), false );
check(
	'[4] parenthetical (unpaginated): "clause (Who)."',
	Citex_MLA_Intext_Citation_Rules::parenthetical_sentence( 'Ross', 'point matters' ),
	'point matters (Ross).'
);
check(
	'[4] parenthetical_quote: NO comma between who and page — "quote" (Who Page).',
	Citex_MLA_Intext_Citation_Rules::parenthetical_quote_sentence( 'Ross', '22', 'a short quote' ),
	'"a short quote" (Ross 22).'
);
check( '[4] parenthetical_quote never contains a comma before the page', strpos( Citex_MLA_Intext_Citation_Rules::parenthetical_quote_sentence( 'Ross', '22', 'a short quote' ), ', 22' ), false );

// ---------------------------------------------------------------------
// 5. wrong_join() — MLA-specific mistake shapes.
// ---------------------------------------------------------------------
check( '[5] 1 person: wrongly adds "et al."', Citex_MLA_Intext_Citation_Rules::wrong_join( array( 'Ross' ), 'Ross' ), 'Ross et al.' );
check( '[5] 2 people: comma wrongly inserted before "and" (the reference-list rule bleeding in)', Citex_MLA_Intext_Citation_Rules::wrong_join( array( 'Ross', 'Carter' ), 'Ross and Carter' ), 'Ross, and Carter' );
check( '[5] 3+ people: lists everyone instead of "et al."', Citex_MLA_Intext_Citation_Rules::wrong_join( array( 'Ross', 'Carter', 'Lee' ), 'Ross et al.' ), 'Ross, Carter and Lee' );

// ---------------------------------------------------------------------
// 6. MCQ stems/hints are non-empty and mention MLA's own distinguishing
// rule (no year) somewhere in the hint text.
// ---------------------------------------------------------------------
foreach ( Citex_MLA_Intext_Citation_Rules::forms() as $form ) {
	check( "[6] mcq_question_stem() is non-empty for form \"$form\"", '' !== Citex_MLA_Intext_Citation_Rules::mcq_question_stem( $form ), true );
	check( "[6] mcq_hint() mentions \"year\" for form \"$form\"", false !== strpos( strtolower( Citex_MLA_Intext_Citation_Rules::mcq_hint( $form ) ), 'year' ), true );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
