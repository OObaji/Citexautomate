/**
 * Citex Tools — question-bank scanner.
 *
 * Ported from the working Chrome DevTools script supplied for Phase 2.
 * Same parsing logic (title split on "|", countBy behaviour), but the
 * page-list URL is passed in instead of read from window.location.href,
 * since Citex runs on admin.php?page=citex, not the WordPress
 * question-list screen itself. Runs authenticated, same-origin fetch
 * requests against that configured URL — read-only, no writes to any
 * WordPress post.
 */
window.CitexScanner = ( function () {
	'use strict';

	function clean( value ) {
		return ( value || '' ).trim();
	}

	/**
	 * Some legacy WordPress titles prefix the source with "Question
	 * title:" (e.g. "Question title: Harvard" instead of "Harvard").
	 * Stripping it here — before it becomes parts[0]/source — keeps
	 * those records grouped under the same source as the rest instead
	 * of splitting off a separate "Question title: Harvard" bucket.
	 */
	var LEGACY_SOURCE_PREFIX = /^question\s+title\s*:\s*/i;

	function stripLegacySourcePrefix( value ) {
		return {
			value: clean( value.replace( LEGACY_SOURCE_PREFIX, '' ) ),
			hadPrefix: LEGACY_SOURCE_PREFIX.test( value ),
		};
	}

	function countBy( items, getter ) {
		var counts = {};

		items.forEach( function ( item ) {
			var key = getter( item ) || '(blank)';
			counts[ key ] = ( counts[ key ] || 0 ) + 1;
		} );

		return Object.keys( counts )
			.map( function ( name ) {
				return { name: name, count: counts[ name ] };
			} )
			.sort( function ( a, b ) {
				return b.count - a.count;
			} );
	}

	function parseTitle( title ) {
		var parts = title
			.split( '|' )
			.map( clean )
			.filter( Boolean );

		var normalisedSource = stripLegacySourcePrefix( parts[0] || '' );

		return {
			original: title,
			source: normalisedSource.value,
			group: parts[1] || '',
			category: parts[2] || '',
			type: parts[3] || '',
			questionId: parts[4] || '',
			parts: parts,
			legacySourcePrefix: normalisedSource.hadPrefix,
		};
	}

	/**
	 * Extracts the `post` query parameter (the WordPress post ID) from an
	 * edit-post URL such as post.php?post=123&action=edit. Never throws —
	 * a post lacking a parseable ID just gets wpPostId: null.
	 */
	function extractPostId( editUrl ) {
		try {
			var url = new URL( editUrl, window.location.origin );
			var id = url.searchParams.get( 'post' );
			return id ? parseInt( id, 10 ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function combinationKey( q ) {
		return [ q.source, q.group, q.category, q.type ].filter( Boolean ).join( ' | ' );
	}

	function harvestFromDoc( doc, results ) {
		var titleLinks = doc.querySelectorAll( '#the-list a.row-title' );

		titleLinks.forEach( function ( link ) {
			var title = clean( link.textContent );
			if ( ! title ) {
				return;
			}

			var parsed = parseTitle( title );
			var editUrl = link.getAttribute( 'href' ) || '';

			parsed.editUrl = editUrl;
			parsed.wpPostId = extractPostId( editUrl );

			results.push( parsed );
		} );
	}

	/**
	 * Scans every page of the configured WordPress question-list URL and
	 * returns a structured report. Read-only — only ever issues GET
	 * requests.
	 *
	 * @param {string} baseUrl The configured Question List URL.
	 * @param {function(number, number): void} [onProgress] Called with (page, totalPages) before each page fetch.
	 * @return {Promise<object>}
	 */
	async function scan( baseUrl, onProgress ) {
		if ( ! baseUrl ) {
			throw new Error( 'No question list URL configured.' );
		}

		var firstUrl = new URL( baseUrl, window.location.origin );
		firstUrl.searchParams.set( 'paged', 1 );

		if ( onProgress ) {
			onProgress( 1, 1 );
		}

		var firstResponse = await fetch( firstUrl.href, { credentials: 'same-origin' } );
		if ( ! firstResponse.ok ) {
			throw new Error( 'Could not load the question list (HTTP ' + firstResponse.status + ').' );
		}

		var firstHtml = await firstResponse.text();
		var firstDoc = new DOMParser().parseFromString( firstHtml, 'text/html' );

		var totalPagesElement = firstDoc.querySelector( '.tablenav-pages .total-pages' );
		var totalPages = totalPagesElement ? Number( totalPagesElement.textContent.trim() ) || 1 : 1;

		var questions = [];
		harvestFromDoc( firstDoc, questions );

		for ( var page = 2; page <= totalPages; page++ ) {
			if ( onProgress ) {
				onProgress( page, totalPages );
			}

			var pageUrl = new URL( baseUrl, window.location.origin );
			pageUrl.searchParams.set( 'paged', page );

			var response = await fetch( pageUrl.href, { credentials: 'same-origin' } );
			if ( ! response.ok ) {
				throw new Error( 'Could not load page ' + page + ' of the question list (HTTP ' + response.status + ').' );
			}

			var html = await response.text();
			var doc = new DOMParser().parseFromString( html, 'text/html' );
			harvestFromDoc( doc, questions );
		}

		var harvard = questions.filter( function ( q ) {
			return q.source.toLowerCase().indexOf( 'harvard' ) !== -1;
		} );

		return {
			scannedAt: new Date().toISOString(),
			questionListUrl: baseUrl,
			total: questions.length,
			harvardTotal: harvard.length,
			questions: questions,
			breakdowns: {
				sources: countBy( questions, function ( q ) { return q.source; } ),
				groups: countBy( questions, function ( q ) { return q.group; } ),
				categories: countBy( questions, function ( q ) { return q.category; } ),
				types: countBy( questions, function ( q ) { return q.type; } ),
				combinations: countBy( questions, combinationKey ),
			},
		};
	}

	return {
		scan: scan,
		parseTitle: parseTitle,
		countBy: countBy,
	};
} )();
