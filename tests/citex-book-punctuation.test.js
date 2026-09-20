'use strict';

const assert = require( 'assert' );
const fs = require( 'fs' );
const path = require( 'path' );

global.window = {};
global.fetch = async () => ( { ok: true, text: async () => '<html><body></body></html>', json: async () => ( { success: true, data: { result: {} } } ) } );
global.DOMParser = function () { this.parseFromString = function () { return { body: { children: [] } }; }; };
global.citexTools = { ajaxUrl: '', validator: { saveResultAction: '', nonce: '' } };

// eslint-disable-next-line no-eval
eval( fs.readFileSync( path.join( __dirname, '..', 'citex-tools', 'admin', 'js', 'citex-validator.js' ), 'utf8' ) );
// eslint-disable-next-line no-eval
eval( fs.readFileSync( path.join( __dirname, '..', 'citex-tools', 'admin', 'js', 'citex-validator-site-adapter.js' ), 'utf8' ) );

const validator = global.window.CitexValidator;

assert.strictEqual(
	validator.checkSpaceBeforePunctuation( 'Lopez, M. (2019) Global Health. Oxford: Oxford University Press.' ),
	null
);

let issue = validator.checkSpaceBeforePunctuation( 'Lopez , M. (2019) Global Health. Oxford: Oxford University Press.' );
assert.strictEqual( issue.code, 'SPACE_BEFORE_PUNCTUATION' );

issue = validator.checkSpaceBeforePunctuation( 'Lopez, M. (2019) Global Health . Oxford: Oxford University Press.' );
assert.strictEqual( issue.code, 'SPACE_BEFORE_PUNCTUATION' );

// BK02 live reconstruction currently contains both this error and year spacing.
issue = validator.checkSpaceBeforePunctuation( 'Lopez , M. ( 2019 ) Global Health . Oxford: Oxford University Press.' );
assert.strictEqual( issue.code, 'SPACE_BEFORE_PUNCTUATION' );
assert.strictEqual(
	validator.checkYearParenthesesSpacing( 'Lopez , M. ( 2019 ) Global Health . Oxford: Oxford University Press.' ).code,
	'YEAR_PARENTHESES_SPACING'
);

console.log( 'All Citex Book punctuation regression tests passed.' );
