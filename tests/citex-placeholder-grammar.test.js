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

// Live BK02 — the decisive regression case.
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

console.log( 'All confirmed Citex placeholder-grammar tests passed.' );
