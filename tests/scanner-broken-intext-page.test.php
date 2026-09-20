<?php
/**
 * Regression tests for Citex_Scanner::is_intext_dragdrop_missing_page() —
 * detects an already-published In-Text Citation DragDrop post whose visible
 * scenario text never mentions the page number the student is asked to
 * drag in. A real reported bug (fixed for all NEWLY generated questions in
 * Citex_AI_V2::intext_dragdrop_stem()): the scenario sentence never said
 * what page to use, for any style's parenthetical_quote form and MLA's own
 * narrative-with-page form. This detects already-published posts from
 * before that fix, using only their own real, stored fixedText/scenario/
 * questionParts content — never raw generation facts, which don't survive
 * publication.
 *
 * Real fixedText strings are produced by actually calling the real
 * Dragdrop_Parts::build() classes (not hand-transcribed), so the detector
 * is tested against the exact shapes production code really emits.
 *
 * Pure, no WordPress dependency beyond the ABSPATH constant define — every
 * class here is a pure, static, unit-testable class.
 *
 * Repo-level only, run with plain
 * `php tests/scanner-broken-intext-page.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-scanner.php';
require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-intext-citation-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-intext-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-intext-citation-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-intext-dragdrop-parts.php';

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
// 1. Harvard-shaped parenthetical_quote — a real build() call. The bug:
// scenario never states the page ("34" here). Detected as broken.
// ---------------------------------------------------------------------
$harvard_quote = Citex_Intext_Dragdrop_Parts::build(
	Citex_Intext_Citation_Rules::FORM_PARENTHETICAL_QUOTE,
	'Smith',
	array( 'Smith' ),
	'2020',
	'',
	'34',
	'a striking claim'
);
check( '[1] Harvard quote-form fixedText opens with a literal quote mark', false !== strpos( $harvard_quote['fixedText'], '"' ), true );
check(
	'[1] broken: scenario never mentions the page (34) — detected as missing',
	Citex_Scanner::is_intext_dragdrop_missing_page(
		$harvard_quote['fixedText'],
		'Complete the parenthetical in-text citation for a direct quotation from the Book Example Title by Smith, published in 2020.',
		$harvard_quote['parts']
	),
	true
);
check(
	'[1] fixed: scenario now mentions the page (34) — not detected as missing',
	Citex_Scanner::is_intext_dragdrop_missing_page(
		$harvard_quote['fixedText'],
		'Complete the parenthetical in-text citation for a direct quotation from the Book Example Title by Smith, published in 2020, found on page 34.',
		$harvard_quote['parts']
	),
	false
);

// ---------------------------------------------------------------------
// 2. MLA narrative-with-page — a real build() call (MLA has no year, so
// this is the "who...(page)." shape unique to MLA).
// ---------------------------------------------------------------------
$mla_narrative_with_page = Citex_MLA_Intext_Dragdrop_Parts::build(
	Citex_MLA_Intext_Citation_Rules::FORM_NARRATIVE,
	'Smith',
	array( 'Smith' ),
	'argues this point effectively',
	'34'
);
check( '[2] MLA narrative-with-page fixedText has no quote mark', false !== strpos( $mla_narrative_with_page['fixedText'], '"' ), false );
check( '[2] MLA narrative-with-page has exactly 2 draggable parts (who, page)', count( $mla_narrative_with_page['parts'] ), 2 );
check(
	'[2] broken: scenario never mentions the page (34) — detected as missing',
	Citex_Scanner::is_intext_dragdrop_missing_page(
		$mla_narrative_with_page['fixedText'],
		'Complete the narrative in-text citation for a paraphrase from the Book Example Title by Smith.',
		$mla_narrative_with_page['parts']
	),
	true
);
check(
	'[2] fixed: scenario now mentions the page (34) — not detected as missing',
	Citex_Scanner::is_intext_dragdrop_missing_page(
		$mla_narrative_with_page['fixedText'],
		'Complete the narrative in-text citation for a paraphrase from the Book Example Title by Smith, found on page 34.',
		$mla_narrative_with_page['parts']
	),
	false
);

// ---------------------------------------------------------------------
// 3. MLA quote form (no year, but still has a page) — a real build() call.
// ---------------------------------------------------------------------
$mla_quote = Citex_MLA_Intext_Dragdrop_Parts::build(
	Citex_MLA_Intext_Citation_Rules::FORM_PARENTHETICAL_QUOTE,
	'Smith',
	array( 'Smith' ),
	'',
	'34',
	'a striking claim'
);
check(
	'[3] MLA quote-form broken: scenario never mentions the page (34)',
	Citex_Scanner::is_intext_dragdrop_missing_page(
		$mla_quote['fixedText'],
		'Complete the parenthetical in-text citation for a direct quotation from the Book Example Title by Smith.',
		$mla_quote['parts']
	),
	true
);
check(
	'[3] MLA quote-form fixed: scenario now mentions the page (34)',
	Citex_Scanner::is_intext_dragdrop_missing_page(
		$mla_quote['fixedText'],
		'Complete the parenthetical in-text citation for a direct quotation from the Book Example Title by Smith, found on page 34.',
		$mla_quote['parts']
	),
	false
);

// ---------------------------------------------------------------------
// 4. Forms with NO page blank at all must never be flagged, whatever the
// scenario text says — real build() calls for Harvard narrative and
// parenthetical (no quote), and MLA's own parenthetical (who only, no
// page) and narrative-without-page.
// ---------------------------------------------------------------------
$harvard_narrative = Citex_Intext_Dragdrop_Parts::build( Citex_Intext_Citation_Rules::FORM_NARRATIVE, 'Smith', array( 'Smith' ), '2020', 'argues this point' );
check(
	'[4] Harvard narrative (no page concept) is never flagged',
	Citex_Scanner::is_intext_dragdrop_missing_page( $harvard_narrative['fixedText'], 'Some scenario mentioning nothing about pages.', $harvard_narrative['parts'] ),
	false
);

$harvard_parenthetical = Citex_Intext_Dragdrop_Parts::build( Citex_Intext_Citation_Rules::FORM_PARENTHETICAL, 'Smith', array( 'Smith' ), '2020', 'a claim holds' );
check(
	'[4] Harvard parenthetical (who+year only, no page) is never flagged',
	Citex_Scanner::is_intext_dragdrop_missing_page( $harvard_parenthetical['fixedText'], 'Some scenario mentioning nothing about pages.', $harvard_parenthetical['parts'] ),
	false
);

$mla_parenthetical_no_page = Citex_MLA_Intext_Dragdrop_Parts::build( Citex_MLA_Intext_Citation_Rules::FORM_PARENTHETICAL, 'Smith', array( 'Smith' ), 'a claim holds' );
check( '[4] MLA parenthetical (who only) has exactly 1 draggable part', count( $mla_parenthetical_no_page['parts'] ), 1 );
check(
	'[4] MLA parenthetical (who only, no page) is never flagged — the false-positive this detector must rule out',
	Citex_Scanner::is_intext_dragdrop_missing_page( $mla_parenthetical_no_page['fixedText'], 'Some scenario mentioning nothing about pages.', $mla_parenthetical_no_page['parts'] ),
	false
);

$mla_narrative_no_page = Citex_MLA_Intext_Dragdrop_Parts::build( Citex_MLA_Intext_Citation_Rules::FORM_NARRATIVE, 'Smith', array( 'Smith' ), 'argues this point', null );
check(
	'[4] MLA narrative with no page supplied at all is never flagged',
	Citex_Scanner::is_intext_dragdrop_missing_page( $mla_narrative_no_page['fixedText'], 'Some scenario mentioning nothing about pages.', $mla_narrative_no_page['parts'] ),
	false
);

// ---------------------------------------------------------------------
// 5. Edge cases: empty questionParts, empty page value, empty fixedText —
// none of these should ever be treated as "broken" (nothing to check).
// ---------------------------------------------------------------------
check( '[5] empty questionParts is never flagged', Citex_Scanner::is_intext_dragdrop_missing_page( '"quote" (|, |, p. |).', 'Some scenario.', array() ), false );
check( '[5] empty fixedText is never flagged', Citex_Scanner::is_intext_dragdrop_missing_page( '', 'Some scenario.', array( 'Smith', '2020', '34' ) ), false );
check( '[5] a last part that is blank/whitespace is never flagged', Citex_Scanner::is_intext_dragdrop_missing_page( $harvard_quote['fixedText'], 'Some scenario.', array( 'Smith', '2020', '  ' ) ), false );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
