<?php
/**
 * Unit tests for Citex_Intext_Citation_Rules — Harvard's in-text citation
 * rule, genuinely distinct from Citex_Reference_Rules' own reference-list
 * rule: "et al." IS used in-text (at 4+ people), unlike the reference list
 * which never abbreviates. Category-agnostic — these tests exercise the
 * class directly with hand-built person lists, the same way
 * reference-rules-*.test.php files test Citex_Reference_Rules without
 * routing through a specific category.
 *
 * Repo-level only, run with plain
 * `php tests/reference-rules-intext-citation.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-intext-citation-rules.php';

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
// 1. forms() — the 3 citation forms.
// ---------------------------------------------------------------------
check( '[1] forms() returns exactly narrative/parenthetical/parenthetical_quote', Citex_Intext_Citation_Rules::forms(), array( 'narrative', 'parenthetical', 'parenthetical_quote' ) );

// ---------------------------------------------------------------------
// 2. join_people_intext() — "et al." at 4+, distinct from the
// reference-list's own "never" rule.
// ---------------------------------------------------------------------
check( '[2] 1 person', Citex_Intext_Citation_Rules::join_people_intext( array( person( 'Ross' ) ) ), 'Ross' );
check( '[2] 2 people', Citex_Intext_Citation_Rules::join_people_intext( array( person( 'Ross' ), person( 'Carter' ) ) ), 'Ross and Carter' );
check( '[2] 3 people', Citex_Intext_Citation_Rules::join_people_intext( array( person( 'Ross' ), person( 'Carter' ), person( 'Lee' ) ) ), 'Ross, Carter and Lee' );
check( '[2] 4 people uses "et al."', Citex_Intext_Citation_Rules::join_people_intext( array( person( 'Ross' ), person( 'Carter' ), person( 'Lee' ), person( 'Kaur' ) ) ), 'Ross et al.' );
check( '[2] 5 people still uses "et al." off the FIRST surname only', Citex_Intext_Citation_Rules::join_people_intext( array( person( 'Ross' ), person( 'Carter' ), person( 'Lee' ), person( 'Kaur' ), person( 'Patel' ) ) ), 'Ross et al.' );

// ---------------------------------------------------------------------
// 3. wrong_join() — the shared distractor helper, mistake shape varies by
// real person count.
// ---------------------------------------------------------------------
check( '[3] 1 person: wrongly adds "et al."', Citex_Intext_Citation_Rules::wrong_join( array( 'Ross' ), 'Ross' ), 'Ross et al.' );
check( '[3] 2 people: "&" instead of "and"', Citex_Intext_Citation_Rules::wrong_join( array( 'Ross', 'Carter' ), 'Ross and Carter' ), 'Ross & Carter' );
check( '[3] 3 people: "&" instead of "and"', Citex_Intext_Citation_Rules::wrong_join( array( 'Ross', 'Carter', 'Lee' ), 'Ross, Carter and Lee' ), 'Ross, Carter & Lee' );
check( '[3] 4+ people: lists everyone instead of "et al."', Citex_Intext_Citation_Rules::wrong_join( array( 'Ross', 'Carter', 'Lee', 'Kaur' ), 'Ross et al.' ), 'Ross, Carter, Lee and Kaur' );

// ---------------------------------------------------------------------
// 4. display_person_or_org() — Website's single individual-or-organisation
// abstraction.
// ---------------------------------------------------------------------
check( '[4] individual author shows the surname only', Citex_Intext_Citation_Rules::display_person_or_org( array( 'type' => 'individual', 'surname' => 'Ross', 'name' => 'Amy Ross' ) ), 'Ross' );
check( '[4] organisation author shows the organisation name as-is', Citex_Intext_Citation_Rules::display_person_or_org( array( 'type' => 'organisation', 'name' => 'WHO' ) ), 'WHO' );

// ---------------------------------------------------------------------
// 5. Full sentences per form — the exact-match target every DragDrop/MCQ
// class reconstructs against.
// ---------------------------------------------------------------------
check(
	'[5] narrative sentence: "Who (Year) clause."',
	Citex_Intext_Citation_Rules::narrative_sentence( 'Ross', '2015', 'argues that point matters' ),
	'Ross (2015) argues that point matters.'
);
check(
	'[5] narrative sentence strips a trailing period already on the clause',
	Citex_Intext_Citation_Rules::narrative_sentence( 'Ross', '2015', 'argues that point matters.' ),
	'Ross (2015) argues that point matters.'
);
check(
	'[5] parenthetical sentence: "clause (Who, Year)."',
	Citex_Intext_Citation_Rules::parenthetical_sentence( 'Ross', '2015', 'point matters' ),
	'point matters (Ross, 2015).'
);
check(
	'[5] parenthetical_quote sentence: quote + "(Who, Year, p. Page)."',
	Citex_Intext_Citation_Rules::parenthetical_quote_sentence( 'Ross', '2015', '69', 'a short quote' ),
	'"a short quote" (Ross, 2015, p. 69).'
);

// ---------------------------------------------------------------------
// 6. MCQ stems/hints exist and vary per form (never a blank string).
// ---------------------------------------------------------------------
foreach ( Citex_Intext_Citation_Rules::forms() as $form ) {
	check( "[6] mcq_question_stem() is non-empty for form \"$form\"", '' !== Citex_Intext_Citation_Rules::mcq_question_stem( $form ), true );
	check( "[6] mcq_hint() is non-empty for form \"$form\"", '' !== Citex_Intext_Citation_Rules::mcq_hint( $form ), true );
}
check( '[6] identify_error_hint() is non-empty', '' !== Citex_Intext_Citation_Rules::identify_error_hint( 'narrative' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
