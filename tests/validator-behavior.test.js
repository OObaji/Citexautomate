/**
 * Tests for admin/js/citex-validator.js (Phase 3). Repo-level only, run
 * with plain `node tests/validator-behavior.test.js` — not shipped in
 * citex-tools.zip. Covers what's honestly testable given the routing +
 * fetch/save pipeline is implemented but the Harvard/ReferenceList/Book/
 * DragDrop rule engine itself is not yet ported (see the docblocks in
 * admin/js/citex-validator.js and
 * includes/validators/class-citex-harvard-book-dragdrop-validator.php).
 *
 * Covers acceptance-test items #7 and #9 from the Phase 3 brief directly;
 * #1-6 (the actual rule content: valid Book/DragDrop passes, trailing
 * year period detected, missing final period detected, bad Book format
 * detected, missing draggable part detected, fixed text not required as
 * a draggable part) cannot be honestly tested until the real QA Checker
 * rules are supplied — see the "PENDING" section at the bottom rather
 * than fabricated assertions for those.
 */
'use strict';

const assert = require( 'assert' );
const fs = require( 'fs' );
const path = require( 'path' );

const validatorPath = path.join( __dirname, '..', 'citex-tools', 'admin', 'js', 'citex-validator.js' );
const validatorSource = fs.readFileSync( validatorPath, 'utf8' );

function check( description, actual, expected ) {
	assert.deepStrictEqual( actual, expected, description + ': expected ' + JSON.stringify( expected ) + ', got ' + JSON.stringify( actual ) );
	console.log( 'PASS: ' + description );
}

// ---- Structural check (#9: validation never modifies a WordPress post) ----
// The only network calls in this module must be:
//  (a) a plain fetch(editUrl, {credentials}) with no method — defaults to GET
//  (b) a fetch(citexTools.ajaxUrl, {method:'POST', ...}) that posts a
//      VALIDATION RESULT to Citex's own admin-ajax action, never to a
//      WordPress post-edit endpoint (post.php) or with a WP save action.
(function testNeverWritesToWordPress() {
	const postCalls = validatorSource.match( /method:\s*['"]POST['"][^}]*\}/gs ) || [];
	assert.strictEqual( postCalls.length, 1, 'exactly one POST call exists in citex-validator.js' );
	assert.ok( /citexTools\.ajaxUrl/.test( validatorSource ), 'the POST call targets citexTools.ajaxUrl (Citex\'s own AJAX endpoint)' );
	assert.ok( ! /post\.php/.test( validatorSource ), 'no reference to WordPress\'s post.php (the post-save endpoint) anywhere in the file' );
	assert.ok( ! /action=edit/.test( validatorSource ), 'no hard-coded edit-and-save action string in the file' );
	assert.ok( ! /submit\(\)/.test( validatorSource ), 'no form.submit() call anywhere in the file' );
	console.log( 'PASS: structural check — citex-validator.js never issues a write request to a WordPress post (#9)' );
} )();

// ---- Behavioral checks (need the module loaded) ----
global.window = {};
global.fetch = async function ( url, options ) {
	if ( options && options.method === 'POST' ) {
		return { ok: true, json: async () => ( { success: true, data: { key: 'stub', result: {} } } ) };
	}
	return { ok: true, text: async () => '<html><body>stub edit screen</body></html>' };
};
global.DOMParser = function () {
	this.parseFromString = function ( html ) {
		return { html: html };
	};
};
global.citexTools = {
	ajaxUrl: 'http://example.test/wp-admin/admin-ajax.php',
	validator: { nonce: 'stub-nonce', saveResultAction: 'citex_save_validation_result' },
};

// eslint-disable-next-line no-eval
eval( validatorSource );
const CitexValidator = global.window.CitexValidator;

// Debugging report (live site, 200 indexed questions): BK01 has exactly
// {source: Harvard, group: ReferenceList, category: Book, type: DragDrop}
// and was showing as Unsupported instead of routing. This is the literal
// case from that report, asserted directly against the router.
function testExactReportedCase() {
	const bk01 = { source: 'Harvard', group: 'ReferenceList', category: 'Book', type: 'DragDrop', questionId: 'BK01' };
	const routed = CitexValidator.resolveValidatorId( bk01 );

	check( 'BK01 {Harvard, ReferenceList, Book, DragDrop} routes to harvard-reference-list-book-dragdrop', routed, 'harvard-reference-list-book-dragdrop' );
	assert.notStrictEqual( routed, null, 'BK01 must NOT route to null/unsupported' );
	console.log( 'PASS: BK01 does not route to unsupported' );

	// Whitespace/casing that a scraped admin-list page could plausibly
	// introduce must not silently break routing (Debug checklist item #2).
	const messy = { source: ' harvard', group: 'ReferenceList ', category: 'book ', type: 'DRAGDROP', questionId: 'BK01' };
	check( 'routing tolerates whitespace/casing noise around the same values', CitexValidator.resolveValidatorId( messy ), 'harvard-reference-list-book-dragdrop' );
}

async function testUnsupportedRouting() {
	const supported = { source: 'Harvard', group: 'ReferenceList', category: 'Book', type: 'DragDrop', questionId: 'BK02' };
	const unsupported = { source: 'Harvard', group: 'ReferenceList', category: 'Website', type: 'MCQ', questionId: 'WB01' };

	check( 'supported combination routes to harvard-reference-list-book-dragdrop', CitexValidator.resolveValidatorId( supported ), 'harvard-reference-list-book-dragdrop' );
	check( 'unsupported combination routes to null', CitexValidator.resolveValidatorId( unsupported ), null );

	// #7: Unsupported category/type returns Unsupported (never Passed or Failed).
	const result = await CitexValidator.runValidatorFor( unsupported );
	check( 'unsupported combination produces status "unsupported", not passed/failed', result.status, 'unsupported' );
	assert.ok( result.reason && result.reason.length > 0, 'unsupported result carries a human-readable reason' );
	console.log( 'PASS: unsupported result carries a reason: "' + result.reason + '"' );
}

// Regression guard: until the real rule engine is ported, the routed
// Harvard/ReferenceList/Book/DragDrop validator must NEVER report passed
// or failed for any input — only unsupported. This directly enforces the
// brief's "do not produce false validation results for formats that have
// not yet been implemented" for the one combination that IS routed.
async function testNoFalseResultsYet() {
	const question = { source: 'Harvard', group: 'ReferenceList', category: 'Book', type: 'DragDrop', questionId: 'BK99', editUrl: 'http://example.test/wp-admin/post.php?post=999&action=edit' };
	const result = await CitexValidator.runValidatorFor( question );
	check( 'routed Book/DragDrop question currently always reports unsupported (rule engine pending)', result.status, 'unsupported' );
	// This specifically catches a real bug found while fixing this: if the
	// VALIDATORS registry key ever drifts out of sync with ROUTES.id, the
	// routed validator silently fails to be called and the sequence loop's
	// catch-all also reports 'unsupported' — indistinguishable from this
	// honest, intentional stub result unless `validator` is checked too.
	check( 'the result carries the routed validator id (proves the routed function actually ran, not a silent lookup failure)', result.validator, 'harvard-reference-list-book-dragdrop' );
	check( 'no errors are fabricated', result.errors, [] );
	check( 'no warnings are fabricated', result.warnings, [] );
	console.log( 'PASS: no false pass/fail possible before the real rule engine is ported' );
}

async function main() {
	testExactReportedCase();
	await testUnsupportedRouting();
	await testNoFalseResultsYet();
	console.log( '\nAll implemented Phase 3 validator tests passed.' );
}

main().catch( function ( error ) {
	console.error( 'FAIL: ' + error.message );
	process.exit( 1 );
} );

/* ============================================================================
 * PENDING — cannot be implemented until the existing QA Checker's source
 * (ACF field selectors, expected Liverpool Hope Harvard Book reference
 * format, exact rule logic, and the DragDrop fixed-text-vs-draggable-part
 * distinction) is supplied. Writing these now would mean inventing the
 * exact rules the Phase 3 brief says not to invent. Once supplied, these
 * become real assertions against CitexValidator.runValidatorFor():
 *
 * 1. A known-valid Harvard/ReferenceList/Book/DragDrop question -> status 'passed'.
 * 2. A publication-year trailing full stop -> an error with code YEAR_TRAILING_PERIOD.
 * 3. A missing final full stop -> an error with code MISSING_FINAL_PERIOD.
 * 4. An incorrect Book reference format -> a format-mismatch error.
 * 5. A missing required draggable Question Part -> a missing-part error.
 * 6. Fixed scenario text is NOT flagged as a missing draggable part
 *    (the DragDrop false-positive fix — see the Phase 3 brief).
 * ==========================================================================*/
