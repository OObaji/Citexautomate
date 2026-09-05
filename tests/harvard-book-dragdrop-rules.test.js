/**
 * Tests for the Harvard/ReferenceList/Book/DragDrop rule engine
 * (admin/js/citex-validator.js), ported from recovered QA Checker v0.3
 * details. Repo-level only, run with plain `node
 * tests/harvard-book-dragdrop-rules.test.js` — not shipped in
 * citex-tools.zip.
 *
 * Two kinds of coverage:
 *  - reconstructReference() and the three content checks: pure string
 *    logic, tested with full confidence against the brief's own worked
 *    examples.
 *  - extractQuestionFields(): tested against a hand-built fixture using
 *    ACF's standard `.acf-field[data-key]` / `.acf-row` markup. This
 *    proves the extraction *logic* is internally correct; it does NOT
 *    prove it matches this specific site's real HTML (not verifiable
 *    without live access — see the module docblock). Confirm against the
 *    Validation page's Details diagnostics on the real site before trusting.
 */
'use strict';

const assert = require( 'assert' );
const fs = require( 'fs' );
const path = require( 'path' );

global.window = {};
global.fetch = async () => ( { ok: true, text: async () => '<html><body></body></html>' } );
global.DOMParser = function () {
	this.parseFromString = function () {
		return { body: fakeElement( 'BODY', {}, [] ) };
	};
};
global.citexTools = { ajaxUrl: 'http://example.test/wp-admin/admin-ajax.php', validator: { nonce: 'x', saveResultAction: 'x' } };

const validatorSource = fs.readFileSync(
	path.join( __dirname, '..', 'citex-tools', 'admin', 'js', 'citex-validator.js' ),
	'utf8'
);
// eslint-disable-next-line no-eval
eval( validatorSource );
const CitexValidator = global.window.CitexValidator;

let failures = 0;

function check( description, actual, expected ) {
	try {
		assert.deepStrictEqual( actual, expected );
		console.log( 'PASS: ' + description );
	} catch ( e ) {
		failures++;
		console.log( 'FAIL: ' + description + ' — expected ' + JSON.stringify( expected ) + ', got ' + JSON.stringify( actual ) );
	}
}

function ok( description, condition ) {
	if ( condition ) {
		console.log( 'PASS: ' + description );
	} else {
		failures++;
		console.log( 'FAIL: ' + description );
	}
}

/* ---- Minimal fake DOM element, matching just what citex-validator.js's
 * findAll()/findFirst() traversal needs: children, tagName, getAttribute,
 * className, value, textContent. ---- */
function fakeElement( tagName, attrs, children, value ) {
	attrs = attrs || {};
	children = children || [];
	return {
		tagName: tagName,
		className: attrs['class'] || '',
		classList: {
			contains: function ( cls ) {
				return ( ' ' + ( attrs['class'] || '' ) + ' ' ).indexOf( ' ' + cls + ' ' ) !== -1;
			},
		},
		getAttribute: function ( name ) {
			return Object.prototype.hasOwnProperty.call( attrs, name ) ? attrs[ name ] : null;
		},
		children: children,
		value: value || '',
		textContent: value || children.map( function ( c ) { return c.textContent || c.value || ''; } ).join( '' ),
	};
}

function acfTextField( key, value ) {
	return fakeElement( 'DIV', { class: 'acf-field', 'data-key': key }, [
		fakeElement( 'TEXTAREA', {}, [], value ),
	] );
}

function acfRepeaterField( key, values ) {
	var rows = values.map( function ( v ) {
		return fakeElement( 'DIV', { class: 'acf-row' }, [ fakeElement( 'INPUT', { type: 'text' }, [], v ) ] );
	} );
	// ACF also renders a hidden template row for new entries — must be excluded.
	rows.push( fakeElement( 'DIV', { class: 'acf-row acf-clone' }, [ fakeElement( 'INPUT', { type: 'text' }, [], 'TEMPLATE — must be ignored' ) ] ) );
	return fakeElement( 'DIV', { class: 'acf-field', 'data-key': key }, rows );
}

function buildQuestionDoc( fixedText, questionParts, confusingWords ) {
	var body = fakeElement( 'BODY', {}, [
		acfTextField( CitexValidator.FIELD_MAP.fixedText, fixedText ),
		acfRepeaterField( CitexValidator.FIELD_MAP.questionParts, questionParts ),
		acfRepeaterField( CitexValidator.FIELD_MAP.confusingWords, confusingWords || [] ),
	] );
	return { body: body };
}

/* ============================================================
 * reconstructReference() — the brief's own worked example
 * ============================================================ */

(function testReconstructionWorkedExample() {
	const result = CitexValidator.reconstructReference(
		'|, | (|) Social Media Studies. |: Sage',
		[ 'Smith', 'J.', '2022', 'London' ]
	);
	check( 'reconstruction matches the brief\'s worked example exactly', result.reference, 'Smith, J. (2022) Social Media Studies. London: Sage' );
	check( 'placeholder count is 4', result.placeholderCount, 4 );
	check( 'no reconstruction error', result.error, null );
})();

(function testFixedTextNeverRequiredInQuestionParts() {
	// "Social Media Studies." is fixed text and must flow through untouched
	// without ever being checked against — or required to be present in —
	// Question Parts. This is the exact false-positive the brief says a
	// prior checker version wrongly introduced.
	const result = CitexValidator.reconstructReference(
		'|, | (|) Social Media Studies. |: Sage',
		[ 'Smith', 'J.', '2022', 'London' ] // none of these is "Social Media Studies."
	);
	ok( 'fixed text "Social Media Studies." appears in the output', result.reference.indexOf( 'Social Media Studies.' ) !== -1 );
	ok( 'reconstruction succeeds even though fixed text has no matching Question Part', null === result.error );
})();

(function testStructuralErrors() {
	check( 'empty fixed text -> FIXED_TEXT_MISSING', CitexValidator.reconstructReference( '', [ 'a' ] ).error, 'FIXED_TEXT_MISSING' );
	check( 'empty question parts -> QUESTION_PARTS_MISSING', CitexValidator.reconstructReference( 'a | b', [] ).error, 'QUESTION_PARTS_MISSING' );
	check(
		'placeholder count mismatch -> PLACEHOLDER_COUNT_MISMATCH',
		CitexValidator.reconstructReference( 'a | b | c', [ 'x' ] ).error, // 2 placeholders, 1 part
		'PLACEHOLDER_COUNT_MISMATCH'
	);
})();

/* ============================================================
 * Content checks — the brief's own punctuation examples
 * ============================================================ */

(function testYearTrailingPeriod() {
	ok( '"(2019)." is flagged as YEAR_TRAILING_PERIOD', null !== CitexValidator.checkYearTrailingPeriod( 'Lopez, M. (2019). Global Health. Oxford: Oxford University Press.' ) );
	check(
		'code is YEAR_TRAILING_PERIOD',
		CitexValidator.checkYearTrailingPeriod( 'Lopez, M. (2019). Global Health. Oxford: Oxford University Press.' ).code,
		'YEAR_TRAILING_PERIOD'
	);
	ok( '"(2019)" (no trailing period) is NOT flagged', null === CitexValidator.checkYearTrailingPeriod( 'Lopez, M. (2019) Global Health. Oxford: Oxford University Press.' ) );
})();

(function testMissingFinalPeriod() {
	ok( 'reference ending in "." passes', null === CitexValidator.checkMissingFinalPeriod( 'Lopez, M. (2019) Global Health. Oxford: Oxford University Press.' ) );
	ok( 'reference NOT ending in "." is flagged', null !== CitexValidator.checkMissingFinalPeriod( 'Smith, J. (2022) Social Media Studies. London: Sage' ) );
	check(
		'code is MISSING_FINAL_PERIOD',
		CitexValidator.checkMissingFinalPeriod( 'Smith, J. (2022) Social Media Studies. London: Sage' ).code,
		'MISSING_FINAL_PERIOD'
	);
})();

(function testBookFormat() {
	ok( 'the brief\'s own valid example passes the format check', null === CitexValidator.checkBookFormat( 'Lopez, M. (2019) Global Health. Oxford: Oxford University Press.' ) );
	ok( 'no year in parentheses is flagged', null !== CitexValidator.checkBookFormat( 'Lopez, M. Global Health. Oxford: Oxford University Press.' ) );
	ok( 'no "Place: Publisher" separator is flagged', null !== CitexValidator.checkBookFormat( 'Lopez, M. (2019) Global Health Oxford Oxford University Press.' ) );
})();

/* ============================================================
 * End-to-end validateHarvardBookDragdrop() against a hand-built
 * ACF-shaped document
 * ============================================================ */

async function testEndToEndValidExample() {
	// Reconstructs to exactly the brief's own valid example.
	const doc = buildQuestionDoc(
		'|, | (|) Global Health. |: Oxford University Press.',
		[ 'Lopez', 'M.', '2019', 'Oxford' ],
		[ 'Global Warming' ]
	);
	const question = { questionId: 'BK-TEST-VALID', source: 'Harvard', group: 'ReferenceList', category: 'Book', type: 'DragDrop' };
	const result = await CitexValidator.validateHarvardBookDragdrop( question, doc );

	check( 'end-to-end reconstructed reference', result.reconstructedReference, 'Lopez, M. (2019) Global Health. Oxford: Oxford University Press.' );
	check( 'end-to-end status is passed', result.status, 'passed' );
	check( 'end-to-end has zero errors', result.errors, [] );
	ok( 'diagnostics report ruleEngineExecuted: true', true === result.diagnostics.ruleEngineExecuted );
	check( 'diagnostics captured the extracted Question Parts', result.diagnostics.questionParts, [ 'Lopez', 'M.', '2019', 'Oxford' ] );
	check( 'diagnostics captured the extracted Confusing Words separately', result.diagnostics.confusingWords, [ 'Global Warming' ] );
}

async function testEndToEndYearTrailingPeriod() {
	const doc = buildQuestionDoc(
		'|, | (|). Global Health. |: Oxford University Press.',
		[ 'Lopez', 'M.', '2019', 'Oxford' ]
	);
	const question = { questionId: 'BK-TEST-YEAR', source: 'Harvard', group: 'ReferenceList', category: 'Book', type: 'DragDrop' };
	const result = await CitexValidator.validateHarvardBookDragdrop( question, doc );

	check( 'status is failed', result.status, 'failed' );
	check( 'exactly one error', result.errors.length, 1 );
	check( 'error code is YEAR_TRAILING_PERIOD', result.errors[ 0 ].code, 'YEAR_TRAILING_PERIOD' );
	check( 'error message matches the brief exactly', result.errors[ 0 ].message, 'Unwanted full stop after publication year.' );
}

async function testEndToEndMissingFieldsFailsHonestly() {
	// No ACF fields present at all — must FAIL with a real diagnosable
	// error, never silently PASS and never a fabricated other status.
	const doc = { body: fakeElement( 'BODY', {}, [] ) };
	const question = { questionId: 'BK-TEST-EMPTY', source: 'Harvard', group: 'ReferenceList', category: 'Book', type: 'DragDrop' };
	const result = await CitexValidator.validateHarvardBookDragdrop( question, doc );

	check( 'status is failed (never passed) when fields cannot be found', result.status, 'failed' );
	check( 'error code is FIXED_TEXT_MISSING', result.errors[ 0 ].code, 'FIXED_TEXT_MISSING' );
	ok( 'diagnostics show the field was not found', false === result.diagnostics.fixedTextFound );
	ok( 'diagnostics still report ruleEngineExecuted: true (it ran, it just found nothing)', true === result.diagnostics.ruleEngineExecuted );
}

async function testPlaceholderCountMismatchEndToEnd() {
	const doc = buildQuestionDoc( '|, | (|) Global Health. |: Oxford University Press.', [ 'Lopez', 'M.', '2019' ] ); // 4 placeholders, 3 parts
	const question = { questionId: 'BK-TEST-MISMATCH', source: 'Harvard', group: 'ReferenceList', category: 'Book', type: 'DragDrop' };
	const result = await CitexValidator.validateHarvardBookDragdrop( question, doc );

	check( 'status is failed', result.status, 'failed' );
	check( 'error code is PLACEHOLDER_COUNT_MISMATCH', result.errors[ 0 ].code, 'PLACEHOLDER_COUNT_MISMATCH' );
	check( 'reconstructedReference is null (could not be produced)', result.reconstructedReference, null );
}

async function main() {
	await testEndToEndValidExample();
	await testEndToEndYearTrailingPeriod();
	await testEndToEndMissingFieldsFailsHonestly();
	await testPlaceholderCountMismatchEndToEnd();

	if ( failures > 0 ) {
		console.log( '\n' + failures + ' test(s) FAILED.' );
		process.exit( 1 );
	}
	console.log( '\nAll Harvard Book/DragDrop rule-engine tests passed.' );
}

main();
