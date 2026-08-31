'use strict';

const assert = require( 'assert' );
const fs = require( 'fs' );
const path = require( 'path' );

global.window = {};
global.fetch = async () => ( { ok: true, text: async () => '<html><body></body></html>', json: async () => ( { success: true, data: { result: {} } } ) } );
global.DOMParser = function () { this.parseFromString = function () { return { body: { children: [] } }; }; };
global.citexTools = { ajaxUrl: '', validator: { saveResultAction: '', nonce: '' } };

const base = fs.readFileSync( path.join( __dirname, '..', 'citex-tools', 'admin', 'js', 'citex-validator.js' ), 'utf8' );
const adapter = fs.readFileSync( path.join( __dirname, '..', 'citex-tools', 'admin', 'js', 'citex-validator-site-adapter.js' ), 'utf8' );
// eslint-disable-next-line no-eval
eval( base );
// eslint-disable-next-line no-eval
eval( adapter );

const validator = global.window.CitexValidator;

function reconstruct( fixedText, parts ) {
	return validator.reconstructReference( fixedText, parts );
}

// Live BK02 — the decisive placeholder regression case.
let result = reconstruct(
	'|, || (||) ||. Oxford: Oxford University Press.',
	[ 'Lopez', 'M.', '2019', 'Global Health' ]
);
assert.strictEqual( result.placeholderCount, 4 );
assert.strictEqual( result.error, null );
assert.strictEqual( result.reference, 'Lopez, M. (2019) Global Health. Oxford: Oxford University Press.' );

// Single pipe at the beginning is one placeholder.
result = reconstruct( '| — fixed text', [ 'START' ] );
assert.strictEqual( result.placeholderCount, 1 );
assert.strictEqual( result.reference, 'START — fixed text' );

// Single pipe at the end is one placeholder.
result = reconstruct( 'fixed text — |', [ 'END' ] );
assert.strictEqual( result.placeholderCount, 1 );
assert.strictEqual( result.reference, 'fixed text — END' );

// Double pipe internally is one placeholder.
result = reconstruct( 'before || after', [ 'MIDDLE' ] );
assert.strictEqual( result.placeholderCount, 1 );
assert.strictEqual( result.reference, 'before MIDDLE after' );

// Position rules can be combined.
result = reconstruct( '| / || / |', [ 'A', 'B', 'C' ] );
assert.strictEqual( result.placeholderCount, 3 );
assert.strictEqual( result.reference, 'A / B / C' );

// A single internal pipe is invalid rather than silently treated as a slot.
result = reconstruct( 'before | after', [ 'X' ] );
assert.strictEqual( result.error, 'MALFORMED_PLACEHOLDER_ENCODING' );

// Placeholder count must still match the number of Question Parts.
result = reconstruct( '|, || after', [ 'A' ] );
assert.strictEqual( result.placeholderCount, 2 );
assert.strictEqual( result.error, 'PLACEHOLDER_COUNT_MISMATCH' );

// Live BK03: the year exists, but spacing inside the parentheses is wrong.
const bk03 = 'Smith, J. ( 2018 ) Modern Economics. New York:Pearson.';
assert.ok( validator.findLooseYear( bk03 ), 'BK03 year must be recognised as present' );
assert.strictEqual( validator.checkBookFormat( bk03 ), null, 'BK03 must not be misreported as missing its year' );
assert.strictEqual( validator.checkYearParenthesesSpacing( bk03 ).code, 'YEAR_PARENTHESES_SPACING' );
assert.strictEqual( validator.checkColonSpacing( bk03 ).code, 'MISSING_SPACE_AFTER_COLON' );

const wellSpaced = 'Smith, J. (2018) Modern Economics. New York: Pearson.';
assert.strictEqual( validator.checkYearParenthesesSpacing( wellSpaced ), null );
assert.strictEqual( validator.checkColonSpacing( wellSpaced ), null );
assert.strictEqual( validator.checkBookFormat( wellSpaced ), null );

console.log( 'All confirmed Citex placeholder and live Book diagnostic tests passed.' );
