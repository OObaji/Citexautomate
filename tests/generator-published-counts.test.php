<?php
/**
 * Regression tests for the Generate Questions page's published-question
 * counts — a real requested feature: each Referencing Style dropdown
 * option shows how many of that style are already published in brackets,
 * e.g. "Harvard (50)", so the admin can see coverage before generating
 * more.
 *
 * The Category dropdown's own counts are SCOPED TO THE SELECTED STYLE —
 * a real reported bug fixed after shipping: selecting MLA still showed
 * "Book (200)", a total across every style combined, not MLA's own Book
 * count. Citex_Generator::render() now builds $combined_counts, keyed
 * style_key => category_key => count, from Citex_Scanner::filter_by_style()
 * (factored out of Citex_Dashboard::render()'s own per-style filter so
 * both draw from one rule) and Citex_Scanner::compute_breakdowns()'s
 * existing 'categories' rows, combined across BOTH real destinations
 * (Reference List + Citations) via Citex_Scanner::merge_scans(), matching
 * the Dashboard's own "combined" convention. The view renders the
 * Category dropdown's INITIAL counts against $default_style_key (the
 * first Referencing Style, matching the browser's own default selection
 * with no `selected` attribute set), and ships the whole $combined_counts
 * map as JSON on the page (.citex-auto-generate's own
 * data-published-counts attribute) so admin/js/citex-admin.js's own
 * wireCategoryStyleCounts() can re-label every Category option the
 * instant Referencing Style changes, without a page reload.
 *
 * render() itself is tightly coupled to the WordPress request cycle (a
 * `require` of the view template, wp_nonce_field(), etc.), so — mirroring
 * this codebase's established pattern for that position (see
 * dashboard-style-breakdown.test.php) — this file exercises the underlying,
 * pure Citex_Scanner logic standalone and verifies render()'s/the JS's own
 * source wiring directly.
 *
 * Repo-level only, run with plain
 * `php tests/generator-published-counts.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

require __DIR__ . '/../citex-tools/includes/class-citex-scanner.php';

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

function count_question( $source, $group, $category, $type, $question_id ) {
	return array(
		'source'     => $source,
		'group'      => $group,
		'category'   => $category,
		'type'       => $type,
		'questionId' => $question_id,
	);
}

// ---------------------------------------------------------------------
// 1. Citex_Scanner::filter_by_style() — a case-insensitive substring
// match on `source`, the same rule Citex_Dashboard::render() has always
// used for its own $style_breakdowns (now factored out here so both stay
// in sync — see class-citex-scanner.php's own docblock on the method).
// ---------------------------------------------------------------------
$questions = array(
	count_question( 'Harvard', 'ReferenceList', 'Book', 'DragDrop', 'BK01' ),
	count_question( 'Harvard', 'ReferenceList', 'Book', 'MCQ', 'BK02' ),
	count_question( 'Harvard', 'InTextCitation', 'Website', 'MCQ', 'IW01' ),
	count_question( 'MLA', 'ReferenceList', 'Journal Article', 'DragDrop', 'MJ01' ),
);

$harvard_questions = Citex_Scanner::filter_by_style( $questions, 'Harvard' );
$mla_questions     = Citex_Scanner::filter_by_style( $questions, 'MLA' );
$apa_questions     = Citex_Scanner::filter_by_style( $questions, 'APA 7th' );

check( '[1] Harvard style count matches its 3 own questions across both destinations', count( $harvard_questions ), 3 );
check( '[1] MLA style count matches its 1 own question', count( $mla_questions ), 1 );
check( '[1] a style with no questions yet counts 0, not an error', count( $apa_questions ), 0 );
check( '[1] a Harvard question never leaks into the MLA count', in_array( 'BK01', array_column( $mla_questions, 'questionId' ), true ), false );

// ---------------------------------------------------------------------
// 2. Category counts are SCOPED TO ONE STYLE'S OWN questions — the fix
// for the reported bug (a total across every style, mixed together, is
// never what $combined_counts[ style ][ category ] should be). Filter to
// one style FIRST (Citex_Scanner::filter_by_style()), THEN break down by
// category (Citex_Scanner::compute_breakdowns()'s own 'categories' rows)
// — never the other way around, and never on the unfiltered $questions —
// matching how Citex_Generator::render() builds $combined_counts.
// ---------------------------------------------------------------------
$harvard_only_breakdowns = Citex_Scanner::compute_breakdowns( Citex_Scanner::filter_by_style( $questions, 'Harvard' ) );
$mla_only_breakdowns      = Citex_Scanner::compute_breakdowns( Citex_Scanner::filter_by_style( $questions, 'MLA' ) );

$harvard_category_counts = array();
foreach ( $harvard_only_breakdowns['categories'] as $row ) {
	$harvard_category_counts[ $row['name'] ] = $row['count'];
}
$mla_category_counts = array();
foreach ( $mla_only_breakdowns['categories'] as $row ) {
	$mla_category_counts[ $row['name'] ] = $row['count'];
}

check( "[2] Harvard's own Book category count is 2, never mixed with any other style", $harvard_category_counts['Book'] ?? null, 2 );
check( "[2] Harvard's own Website category count is 1", $harvard_category_counts['Website'] ?? null, 1 );
check( "[2] Harvard's own breakdown never counts MLA's Journal Article question", isset( $harvard_category_counts['Journal Article'] ), false );
check( "[2] MLA's own Journal Article category count is 1, scoped to MLA only", $mla_category_counts['Journal Article'] ?? null, 1 );
check( "[2] MLA's own breakdown never counts Harvard's Book questions", isset( $mla_category_counts['Book'] ), false );
check( '[2] a category with no questions yet for that style is simply absent from the map (render() must default it to 0)', isset( $harvard_category_counts['Edited Book'] ), false );

// ---------------------------------------------------------------------
// 3. merge_scans() combines Reference List + Citations before counting
// (never Reference List alone) — the same combined-destination rule the
// Dashboard's own totals use, so an In-Text Citation question generated
// under a style still counts toward that style's published total shown
// on the Generate page.
// ---------------------------------------------------------------------
$reference_scan = array(
	'scannedAt' => '2020-01-01T00:00:00+00:00',
	'questions' => array( count_question( 'Harvard', 'ReferenceList', 'Book', 'DragDrop', 'BK01' ) ),
);
$citations_scan = array(
	'scannedAt' => '2020-01-01T00:00:00+00:00',
	'questions' => array( count_question( 'Harvard', 'InTextCitation', 'Website', 'MCQ', 'IW01' ) ),
);
$merged = Citex_Scanner::merge_scans( array( $reference_scan, $citations_scan ) );
check( '[3] merge_scans() combines both destinations before the style filter runs', count( Citex_Scanner::filter_by_style( $merged['questions'], 'Harvard' ) ), 2 );

// ---------------------------------------------------------------------
// 4. Never scanned at all (both targets null) — merge_scans() returns
// null, and render() must still be able to default every count to 0
// rather than fatal on a null array access.
// ---------------------------------------------------------------------
$never_scanned = Citex_Scanner::merge_scans( array( null, null ) );
check( '[4] merge_scans() returns null when neither target has ever been scanned', $never_scanned, null );

// ---------------------------------------------------------------------
// 5. Source wiring: Citex_Generator::render() actually builds
// $style_counts (style-only) and $combined_counts (style => category)
// from merge_scans()+filter_by_style()+compute_breakdowns(), the view
// prints them in brackets next to each option (Category's own INITIAL
// render scoped to $default_style_key, never a cross-style total), and
// admin/js/citex-admin.js re-labels the Category dropdown client-side
// whenever Referencing Style changes.
// ---------------------------------------------------------------------
$generator_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-generator.php' );
check(
	'[5] render() builds $style_counts via Citex_Scanner::filter_by_style()',
	false !== strpos( $generator_source, 'Citex_Scanner::filter_by_style( $published_questions, $style_label )' ),
	true
);
check(
	'[5] render() combines both destinations via Citex_Scanner::merge_scans()',
	false !== strpos( $generator_source, "Citex_Scanner::merge_scans( array( Citex_Scanner::get_last_scan( 'reference' ), Citex_Scanner::get_last_scan( 'citations' ) ) )" ),
	true
);
check(
	'[5] render() builds $combined_counts by filtering to one style BEFORE breaking down by category',
	false !== strpos( $generator_source, "\$combined_counts[ \$style_key ][ \$category_key ] = \$style_category_counts_by_name[ \$category_label ] ?? 0;" ),
	true
);
check(
	'[5] render() derives $default_style_key from the Referencing Style map\'s own first key',
	false !== strpos( $generator_source, "array_key_first( \$referencing_styles )" ),
	true
);

$generate_view_source = file_get_contents( __DIR__ . '/../citex-tools/admin/views/generate.php' );
check(
	'[5] the Referencing Style option prints its bracketed count',
	false !== strpos( $generate_view_source, '$style_counts[ $value ] ?? 0' ),
	true
);
check(
	'[5] the Category option\'s INITIAL count is scoped to $default_style_key, never a cross-style total',
	false !== strpos( $generate_view_source, '$combined_counts[ $default_style_key ][ $value ] ?? 0' ),
	true
);
check(
	'[5] the whole $combined_counts map ships as JSON for client-side re-labelling',
	false !== strpos( $generate_view_source, 'data-published-counts="<?php echo esc_attr( wp_json_encode( $combined_counts ) ); ?>"' ),
	true
);

$admin_js_source = file_get_contents( __DIR__ . '/../citex-tools/admin/js/citex-admin.js' );
check(
	'[5] citex-admin.js re-labels the Category dropdown on Referencing Style change',
	false !== strpos( $admin_js_source, 'function wireCategoryStyleCounts()' ) && false !== strpos( $admin_js_source, "styleSelect.addEventListener( 'change', sync )" ),
	true
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
