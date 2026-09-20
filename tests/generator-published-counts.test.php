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
 * count.
 *
 * BOTH counts are additionally SCOPED TO THE SELECTED QUESTION FOCUS — a
 * second real reported bug fixed after that: "Harvard (400)" used to mean
 * Reference List and In-Text Citation combined into one number, so
 * selecting In-Text Citation with genuinely zero In-Text questions still
 * showed Reference List's own large total, and Auto-Generate's own
 * "already at target" check compared its target against that same
 * cross-group total and refused to run a single batch even though the
 * SELECTED group had nothing published yet.
 *
 * Citex_Generator::render() now builds $combined_counts, keyed
 * style_key => group_key => category_key => count, and $style_counts,
 * keyed style_key => group_key => count, from
 * Citex_Scanner::filter_by_style() (factored out of Citex_Dashboard::render()'s
 * own per-style filter so both draw from one rule), the NEW
 * Citex_Scanner::filter_by_group() (an exact, case-insensitive match on
 * the `group` field — 'ReferenceList'/'InTextCitation', the only two
 * literal values this codebase ever writes there), and
 * Citex_Scanner::compute_breakdowns()'s existing 'categories' rows,
 * combined across BOTH real destinations (Reference List + Citations) via
 * Citex_Scanner::merge_scans(), matching the Dashboard's own "combined"
 * convention (combined across destinations, never across style or group).
 * The view renders the Category/Referencing Style dropdowns' INITIAL
 * counts against $default_style_key/$default_group_key (the first
 * Referencing Style/Question Focus, matching the browser's own default
 * selection with no `selected` attribute set), and ships both maps as
 * JSON on the page (.citex-auto-generate's own data-published-counts and
 * data-style-counts attributes) so admin/js/citex-admin.js's own
 * wireCategoryStyleCounts() can re-label every option the instant
 * Referencing Style OR Question Focus changes, without a page reload.
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
// 2. Citex_Scanner::filter_by_group() — an EXACT, case-insensitive match
// on `group`, never a substring match (unlike filter_by_style() — a
// group is always one of exactly two literal strings, so an exact match
// is correct and never under/over-matches).
// ---------------------------------------------------------------------
$reference_list_only = Citex_Scanner::filter_by_group( $questions, 'ReferenceList' );
$intext_only          = Citex_Scanner::filter_by_group( $questions, 'InTextCitation' );

check( '[2] filter_by_group() finds exactly the 3 ReferenceList questions', count( $reference_list_only ), 3 );
check( '[2] filter_by_group() finds exactly the 1 InTextCitation question', count( $intext_only ), 1 );
check( '[2] a ReferenceList question never leaks into the InTextCitation filter', in_array( 'BK01', array_column( $intext_only, 'questionId' ), true ), false );
check( '[2] filter_by_group() is case-insensitive', count( Citex_Scanner::filter_by_group( $questions, 'referencelist' ) ), 3 );
check( '[2] a group value with no matching questions counts 0, not an error', count( Citex_Scanner::filter_by_group( $questions, 'Nonexistent' ) ), 0 );

// ---------------------------------------------------------------------
// 3. Category counts are SCOPED TO ONE STYLE'S OWN questions, THEN to one
// GROUP within that style — the fix for BOTH reported bugs (a total
// across every style OR across every group, mixed together, is never
// what $combined_counts[ style ][ group ][ category ] should be). Filter
// to one style FIRST (Citex_Scanner::filter_by_style()), THEN to one
// group (Citex_Scanner::filter_by_group()), THEN break down by category
// (Citex_Scanner::compute_breakdowns()'s own 'categories' rows) — never
// skipping the group filter, and never on the unfiltered $questions —
// matching how Citex_Generator::render() builds $combined_counts.
// ---------------------------------------------------------------------
$harvard_reflist_breakdowns = Citex_Scanner::compute_breakdowns(
	Citex_Scanner::filter_by_group( Citex_Scanner::filter_by_style( $questions, 'Harvard' ), 'ReferenceList' )
);
$harvard_intext_breakdowns = Citex_Scanner::compute_breakdowns(
	Citex_Scanner::filter_by_group( Citex_Scanner::filter_by_style( $questions, 'Harvard' ), 'InTextCitation' )
);

$harvard_reflist_category_counts = array();
foreach ( $harvard_reflist_breakdowns['categories'] as $row ) {
	$harvard_reflist_category_counts[ $row['name'] ] = $row['count'];
}
$harvard_intext_category_counts = array();
foreach ( $harvard_intext_breakdowns['categories'] as $row ) {
	$harvard_intext_category_counts[ $row['name'] ] = $row['count'];
}

check( "[3] Harvard's own ReferenceList Book category count is 2, never mixed with any other style or group", $harvard_reflist_category_counts['Book'] ?? null, 2 );
check( "[3] Harvard's own ReferenceList breakdown never counts its OWN InTextCitation Website question", isset( $harvard_reflist_category_counts['Website'] ), false );
check( "[3] Harvard's own InTextCitation Website category count is 1, scoped away from ReferenceList", $harvard_intext_category_counts['Website'] ?? null, 1 );
check( "[3] Harvard's own InTextCitation breakdown never counts its OWN ReferenceList Book questions", isset( $harvard_intext_category_counts['Book'] ), false );
check( '[3] a category with no questions yet for that style+group is simply absent from the map (render() must default it to 0)', isset( $harvard_intext_category_counts['Edited Book'] ), false );

// ---------------------------------------------------------------------
// 4. merge_scans() combines Reference List + Citations before counting
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
check( '[4] merge_scans() combines both destinations before the style filter runs', count( Citex_Scanner::filter_by_style( $merged['questions'], 'Harvard' ) ), 2 );
check( '[4] the merged ReferenceList-only slice still finds only the Reference List question', count( Citex_Scanner::filter_by_group( Citex_Scanner::filter_by_style( $merged['questions'], 'Harvard' ), 'ReferenceList' ) ), 1 );
check( '[4] the merged InTextCitation-only slice still finds only the Citations question', count( Citex_Scanner::filter_by_group( Citex_Scanner::filter_by_style( $merged['questions'], 'Harvard' ), 'InTextCitation' ) ), 1 );

// ---------------------------------------------------------------------
// 5. Never scanned at all (both targets null) — merge_scans() returns
// null, and render() must still be able to default every count to 0
// rather than fatal on a null array access.
// ---------------------------------------------------------------------
$never_scanned = Citex_Scanner::merge_scans( array( null, null ) );
check( '[5] merge_scans() returns null when neither target has ever been scanned', $never_scanned, null );

// ---------------------------------------------------------------------
// 6. Source wiring: Citex_Generator::render() actually builds
// $style_counts (style => group => count) and $combined_counts
// (style => group => category => count) from
// merge_scans()+filter_by_style()+filter_by_group()+compute_breakdowns(),
// the view prints them in brackets next to each option (both dropdowns'
// own INITIAL render scoped to $default_style_key/$default_group_key,
// never a cross-style or cross-group total), and admin/js/citex-admin.js
// re-labels both dropdowns client-side whenever Referencing Style OR
// Question Focus changes.
// ---------------------------------------------------------------------
$generator_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-generator.php' );
check(
	'[6] render() builds per-style question lists via Citex_Scanner::filter_by_style()',
	false !== strpos( $generator_source, 'Citex_Scanner::filter_by_style( $published_questions, $style_label )' ),
	true
);
check(
	'[6] render() further scopes each style\'s questions to one Question Focus via Citex_Scanner::filter_by_group()',
	false !== strpos( $generator_source, 'Citex_Scanner::filter_by_group( $style_questions, $group_field_values[ $group_key ] )' ),
	true
);
check(
	'[6] render() combines both destinations via Citex_Scanner::merge_scans()',
	false !== strpos( $generator_source, 'Citex_Scanner::merge_scans( array( $reference_scan_fresh, $citations_scan_fresh ) )' ),
	true
);
check(
	'[6] render() syncs each target FRESH (never a stale cached scan) — a real reported bug: counts did not update after generating/publishing',
	false !== strpos( $generator_source, "Citex_Scanner::sync_from_wordpress( 'reference' )" ) && false !== strpos( $generator_source, "Citex_Scanner::sync_from_wordpress( 'citations' )" ),
	true
);
check(
	'[6] render() falls back to the cached last scan only when a fresh sync cannot run',
	false !== strpos( $generator_source, 'if ( is_wp_error( $reference_scan_fresh ) ) {' ) && false !== strpos( $generator_source, "Citex_Scanner::get_last_scan( 'reference' );" ),
	true
);
check(
	'[6] render() builds $combined_counts one level deeper than category alone — style => group => category',
	false !== strpos( $generator_source, "\$combined_counts[ \$style_key ][ \$group_key ][ \$category_key ] = \$group_category_counts_by_name[ \$category_label ] ?? 0;" ),
	true
);
check(
	'[6] render() builds $style_counts as style => group => count, not a flat style-only total',
	false !== strpos( $generator_source, '$style_counts[ $style_key ][ $group_key ] = count( $group_questions );' ),
	true
);
check(
	'[6] render() derives $default_style_key from the Referencing Style map\'s own first key',
	false !== strpos( $generator_source, "\$default_style_key = array_key_first( \$referencing_styles );" ),
	true
);
check(
	'[6] render() derives $default_group_key from the Question Focus map\'s own first key',
	false !== strpos( $generator_source, "\$default_group_key = array_key_first( \$question_groups );" ),
	true
);

$generate_view_source = file_get_contents( __DIR__ . '/../citex-tools/admin/views/generate.php' );
check(
	'[6] the Referencing Style option prints its bracketed count scoped to $default_group_key',
	false !== strpos( $generate_view_source, '$style_counts[ $value ][ $default_group_key ] ?? 0' ),
	true
);
check(
	'[6] the Category option\'s INITIAL count is scoped to $default_style_key AND $default_group_key, never a cross-style/cross-group total',
	false !== strpos( $generate_view_source, '$combined_counts[ $default_style_key ][ $default_group_key ][ $value ] ?? 0' ),
	true
);
check(
	'[6] the whole $combined_counts map ships as JSON for client-side re-labelling',
	false !== strpos( $generate_view_source, 'data-published-counts="<?php echo esc_attr( wp_json_encode( $combined_counts ) ); ?>"' ),
	true
);
check(
	'[6] the whole $style_counts map ALSO ships as JSON, for the Referencing Style dropdown\'s own re-labelling',
	false !== strpos( $generate_view_source, 'data-style-counts="<?php echo esc_attr( wp_json_encode( $style_counts ) ); ?>"' ),
	true
);

$admin_js_source = file_get_contents( __DIR__ . '/../citex-tools/admin/js/citex-admin.js' );
check(
	'[6] citex-admin.js reads the new data-style-counts attribute',
	false !== strpos( $admin_js_source, 'function readStyleCounts()' ) && false !== strpos( $admin_js_source, "data-style-counts" ),
	true
);
check(
	'[6] citex-admin.js re-labels the Category dropdown scoped to BOTH style and group',
	false !== strpos( $admin_js_source, 'function wireCategoryStyleCounts()' ) && false !== strpos( $admin_js_source, 'publishedCounts[ styleSelect.value ] && publishedCounts[ styleSelect.value ][ group ]' ),
	true
);
check(
	'[6] citex-admin.js re-labels the Referencing Style dropdown itself, not just Category',
	false !== strpos( $admin_js_source, 'styleCounts[ opt.value ] && styleCounts[ opt.value ][ group ]' ),
	true
);
check(
	'[6] citex-admin.js re-syncs on Question Focus change too, not just Referencing Style change',
	false !== strpos( $admin_js_source, "groupSelect.addEventListener( 'change', sync )" ),
	true
);
check(
	'[6] Auto-Generate\'s own baseline lookup includes the selected Question Focus, not just style+category',
	false !== strpos( $admin_js_source, 'publishedCounts[ styleKey ] && publishedCounts[ styleKey ][ groupKey ] && publishedCounts[ styleKey ][ groupKey ][ categoryKey ]' ),
	true
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
