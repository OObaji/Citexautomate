/**
 * Tests for admin/js/citex-validator.js's routing/dispatch/persistence
 * pipeline (Phase 3). Repo-level only, run with plain
 * `node tests/validator-behavior.test.js` — not shipped in citex-tools.zip.
 *
 * This file covers routing, the save pipeline, and the never-writes-to-
 * WordPress structural guarantee (acceptance items #7 and #9). The actual
 * Harvard/ReferenceList/Book/DragDrop rule content — reconstruction,
 * punctuation checks, Book-format check, field extraction — is now
 * implemented (ported from recovered QA Checker v0.3 details) and is
 * tested separately and in depth in
 * tests/harvard-book-dragdrop-rules.test.js.
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

// Regression guard, updated now the real rule engine exists: a routed
// Book/DragDrop question whose fields cannot be extracted (this file's
// mock DOMParser returns a document with no ACF markup at all) must FAIL
// honestly with a real, diagnosable error — never silently PASS just
// because nothing could be read. This is the still-live half of "do not
// produce false validation results"; the never-fabricate-PASS guarantee
// now applies to missing/unextractable data rather than to an
// unimplemented rule engine (see tests/harvard-book-dragdrop-rules.test.js
// for the full rule-content coverage, including the passing case).
async function testMissingFieldsNeverProduceAFalsePass() {
	const question = { source: 'Harvard', group: 'ReferenceList', category: 'Book', type: 'DragDrop', questionId: 'BK99', editUrl: 'http://example.test/wp-admin/post.php?post=999&action=edit' };
	const result = await CitexValidator.runValidatorFor( question );
	check( 'a routed question with no extractable ACF fields fails (never passes)', result.status, 'failed' );
	check( 'the result carries the routed validator id (proves the routed function actually ran)', result.validator, 'harvard-reference-list-book-dragdrop' );
	check( 'reports a real, diagnosable error code, not a generic one', result.errors[ 0 ].code, 'FIXED_TEXT_MISSING' );
	console.log( 'PASS: no false PASS when fields cannot be extracted, even with the real rule engine running' );
}

// Regression guard for the exact bug class already found once while fixing
// this: if VALIDATORS' key ever drifts from ROUTES.id again (e.g. someone
// hand-edits one but not the other), routing still succeeds but the
// function lookup fails. That must surface as a clearly labeled "registry
// mismatch" reason, not silently collapse into the same message as "rule
// engine pending" or "not routed". Exercised by loading a deliberately
// corrupted copy of the module (VALIDATORS keyed wrong) under its own
// global, independent of the real (correct) CitexValidator loaded above.
async function testRegistryMismatchIsDetected() {
	const corruptedSource = validatorSource.replace(
		"VALIDATORS[ ROUTES.id ] = validateHarvardBookDragdrop;",
		"VALIDATORS[ 'deliberately-wrong-key-for-test' ] = validateHarvardBookDragdrop;"
	);
	assert.notStrictEqual( corruptedSource, validatorSource, 'the corruption string actually matched something to replace' );

	global.window = {};
	// eslint-disable-next-line no-eval
	eval( corruptedSource );
	const CorruptedValidator = global.window.CitexValidator;
	global.window = { CitexValidator: CitexValidator }; // restore for any later use

	const question = { source: 'Harvard', group: 'ReferenceList', category: 'Book', type: 'DragDrop', questionId: 'BK01' };
	const result = await CorruptedValidator.runValidatorFor( question );

	check( 'a registry mismatch still reports status unsupported (never a false pass/fail)', result.status, 'unsupported' );
	check( 'a registry mismatch still records which id was routed to', result.validator, 'harvard-reference-list-book-dragdrop' );
	assert.ok( /registry mismatch/.test( result.reason ), 'reason explicitly names it a registry mismatch: "' + result.reason + '"' );
	console.log( 'PASS: registry mismatch is detected and labeled distinctly: "' + result.reason + '"' );
}

async function main() {
	testExactReportedCase();
	await testUnsupportedRouting();
	await testMissingFieldsNeverProduceAFalsePass();
	await testRegistryMismatchIsDetected();
	console.log( '\nAll implemented Phase 3 validator tests passed.' );
}

main().catch( function ( error ) {
	console.error( 'FAIL: ' + error.message );
	process.exit( 1 );
} );
