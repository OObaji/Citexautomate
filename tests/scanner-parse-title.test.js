/**
 * Standalone test for CitexScanner.parseTitle's legacy "Question title:"
 * source-prefix normalisation. Not shipped in citex-tools.zip — this is
 * a repo-level check, run with plain `node tests/scanner-parse-title.test.js`
 * (no dependencies, no build step).
 */
'use strict';

const assert = require( 'assert' );
const fs = require( 'fs' );
const path = require( 'path' );

global.window = { location: { origin: 'http://example.test' } };

const scannerSource = fs.readFileSync(
	path.join( __dirname, '..', 'citex-tools', 'admin', 'js', 'citex-scanner.js' ),
	'utf8'
);
// eslint-disable-next-line no-eval
eval( scannerSource );

const CitexScanner = global.window.CitexScanner;

function check( description, actual, expected ) {
	assert.strictEqual( actual, expected, description + ': expected ' + JSON.stringify( expected ) + ', got ' + JSON.stringify( actual ) );
	console.log( 'PASS: ' + description + ' = ' + JSON.stringify( actual ) );
}

// The exact case reported: a legacy "Question title:" prefix on the source.
const title = 'Question title: Harvard | ReferenceList | Journal | MCQ | JR01';
const parsed = CitexScanner.parseTitle( title );

check( 'original', parsed.original, title );
check( 'source', parsed.source, 'Harvard' );
check( 'group', parsed.group, 'ReferenceList' );
check( 'category', parsed.category, 'Journal' );
check( 'type', parsed.type, 'MCQ' );
check( 'questionId', parsed.questionId, 'JR01' );
check( 'legacySourcePrefix', parsed.legacySourcePrefix, true );

// A plain (already-clean) title must be unaffected.
const plain = CitexScanner.parseTitle( 'Harvard | Books | Single Author | Spot the Error | BK01' );
check( 'plain title source', plain.source, 'Harvard' );
check( 'plain title legacySourcePrefix', plain.legacySourcePrefix, false );

// Case-insensitivity and optional whitespace around the prefix.
check(
	'lowercase, no space after colon',
	CitexScanner.parseTitle( 'question title:Harvard | Books | Single Author | Spot the Error | BK02' ).source,
	'Harvard'
);
check(
	'uppercase, extra space before colon',
	CitexScanner.parseTitle( 'QUESTION TITLE : Harvard | Books | Single Author | Spot the Error | BK03' ).source,
	'Harvard'
);

// Regression: the 176 + 25 split from the live scan report must fully merge.
const mixed = [];
for ( let i = 0; i < 176; i++ ) {
	mixed.push( CitexScanner.parseTitle( 'Harvard | Books | Single Author | Spot the Error | BK' + i ) );
}
for ( let i = 0; i < 25; i++ ) {
	mixed.push( CitexScanner.parseTitle( 'Question title: Harvard | Books | Single Author | Spot the Error | QT' + i ) );
}
const sources = CitexScanner.countBy( mixed, function ( q ) { return q.source; } );
const harvardTotal = mixed.filter( function ( q ) { return q.source.toLowerCase().indexOf( 'harvard' ) !== -1; } ).length;

check( 'merged source buckets', sources.length, 1 );
check( 'merged source name', sources[0].name, 'Harvard' );
check( 'merged source count', sources[0].count, 201 );
check( 'merged harvard total', harvardTotal, 201 );

console.log( '\nAll scanner-parse-title tests passed.' );
