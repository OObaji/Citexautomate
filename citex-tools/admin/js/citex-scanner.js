/**
 * Citex Tools — question-bank scanner.
 *
 * Reads the configured WordPress Reference List screen exactly as the
 * administrator sees it. Besides title metadata, Citex now captures the real
 * WordPress post status for every row and the status-tab counts (All,
 * Published, Drafts, Bin, etc.). This keeps the Question Bank aligned with the
 * native Reference List instead of treating every indexed record as an
 * undifferentiated question.
 */
window.CitexScanner = ( function () {
	'use strict';

	function clean( value ) {
		return ( value || '' ).trim();
	}

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
			.map( function ( name ) { return { name: name, count: counts[ name ] }; } )
			.sort( function ( a, b ) { return b.count - a.count; } );
	}

	function parseTitle( title ) {
		var parts = title.split( '|' ).map( clean ).filter( Boolean );
		var normalisedSource = stripLegacySourcePrefix( parts[ 0 ] || '' );
		return {
			original: title,
			source: normalisedSource.value,
			group: parts[ 1 ] || '',
			category: parts[ 2 ] || '',
			type: parts[ 3 ] || '',
			questionId: parts[ 4 ] || '',
			parts: parts,
			legacySourcePrefix: normalisedSource.hadPrefix,
		};
	}

	function extractPostId( editUrl ) {
		try {
			var url = new URL( editUrl, window.location.origin );
			var id = url.searchParams.get( 'post' );
			return id ? parseInt( id, 10 ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function normalisePostStatus( status ) {
		status = clean( status ).toLowerCase();
		var allowed = [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ];
		return allowed.indexOf( status ) !== -1 ? status : '';
	}

	/**
	 * WordPress list-table rows include status classes such as status-draft
	 * and status-publish. The hidden inline-edit row data also carries
	 * `._status`, so use that as a secondary source when available.
	 */
	function extractRowStatus( row, postId, doc ) {
		if ( postId ) {
			var inline = doc.querySelector( '#inline_' + postId + ' ._status' );
			if ( inline ) {
				var inlineStatus = normalisePostStatus( inline.textContent );
				if ( inlineStatus ) {
					return inlineStatus;
				}
			}

		if ( row && row.classList ) {
			var statuses = [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ];
			for ( var i = 0; i < statuses.length; i++ ) {
				if ( row.classList.contains( 'status-' + statuses[ i ] ) ) {
					return statuses[ i ];
				}
			}
		}

		return '';
	}

	/**
	 * Mirror the native Reference List status tabs. WordPress uses query
	 * strings such as post_status=draft/trash; the All/Published tab can vary
	 * by installation, so fall back to the visible label as well.
	 */
	function extractStatusCounts( doc ) {
		var counts = {};
		doc.querySelectorAll( '.subsubsub a' ).forEach( function ( link ) {
			var countEl = link.querySelector( '.count' );
			var countText = countEl ? countEl.textContent : link.textContent;
			var match = countText.match( /(\d[\d,]*)/ );
			if ( ! match ) {
				return;
			}
			var count = parseInt( match[ 1 ].replace( /,/g, '' ), 10 );
			var status = '';
			try {
				var url = new URL( link.getAttribute( 'href' ) || '', window.location.origin );
				status = normalisePostStatus( url.searchParams.get( 'post_status' ) || '' );
			} catch ( e ) {}

			var label = clean( link.textContent.replace( /\([^)]*\)/g, '' ) ).toLowerCase();
			if ( ! status ) {
				if ( label.indexOf( 'published' ) !== -1 ) {
					status = 'publish';
				} else if ( label.indexOf( 'draft' ) !== -1 ) {
					status = 'draft';
				} else if ( label.indexOf( 'pending' ) !== -1 ) {
					status = 'pending';
				} else if ( label.indexOf( 'private' ) !== -1 ) {
					status = 'private';
				} else if ( label.indexOf( 'bin' ) !== -1 || label.indexOf( 'trash' ) !== -1 ) {
					status = 'trash';
				} else if ( label.indexOf( 'all' ) !== -1 ) {
					status = 'all';
				}
			}

			if ( status ) {
				counts[ status ] = count;
			}
		} );
		return counts;
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
			var postId = extractPostId( editUrl );
			var row = link.closest( 'tr' );
			parsed.editUrl = editUrl;
			parsed.wpPostId = postId;
			parsed.postStatus = extractRowStatus( row, postId, doc );
			results.push( parsed );
		} );
	}

	async function scan( baseUrl, onProgress ) {
		if ( ! baseUrl ) {
			throw new Error( 'No question list URL configured.' );
		}

		var firstUrl = new URL( baseUrl, window.location.origin );
		firstUrl.searchParams.delete( 'post_status' );
		firstUrl.searchParams.set( 'paged', 1 );
		if ( onProgress ) {
			onProgress( 1, 1 );
		}

		var firstResponse = await fetch( firstUrl.href, { credentials: 'same-origin' } );
		if ( ! firstResponse.ok ) {
			throw new Error( 'Could not load the Reference List (HTTP ' + firstResponse.status + ').' );
		}
		var firstHtml = await firstResponse.text();
		var firstDoc = new DOMParser().parseFromString( firstHtml, 'text/html' );
		var totalPagesElement = firstDoc.querySelector( '.tablenav-pages .total-pages' );
		var totalPages = totalPagesElement ? Number( totalPagesElement.textContent.trim() ) || 1 : 1;
		var statusCounts = extractStatusCounts( firstDoc );
		var questions = [];
		harvestFromDoc( firstDoc, questions );

		for ( var page = 2; page <= totalPages; page++ ) {
			if ( onProgress ) {
				onProgress( page, totalPages );
			}
			var pageUrl = new URL( baseUrl, window.location.origin );
			pageUrl.searchParams.delete( 'post_status' );
			pageUrl.searchParams.set( 'paged', page );
			var response = await fetch( pageUrl.href, { credentials: 'same-origin' } );
			if ( ! response.ok ) {
				throw new Error( 'Could not load page ' + page + ' of the Reference List (HTTP ' + response.status + ').' );
			}
			var html = await response.text();
			var doc = new DOMParser().parseFromString( html, 'text/html' );
			harvestFromDoc( doc, questions );
		}

		var harvard = questions.filter( function ( q ) {
			return q.source.toLowerCase().indexOf( 'harvard' ) !== -1;
		} );

		// WordPress's All count excludes Bin/Trash. When the native tab count
		// could not be parsed, the indexed row count is the correct fallback.
		if ( typeof statusCounts.all !== 'number' ) {
			statusCounts.all = questions.length;
		}

		return {
			scannedAt: new Date().toISOString(),
			questionListUrl: baseUrl,
			total: questions.length,
			harvardTotal: harvard.length,
			statusCounts: statusCounts,
			questions: questions,
			breakdowns: {
				sources: countBy( questions, function ( q ) { return q.source; } ),
				groups: countBy( questions, function ( q ) { return q.group; } ),
				categories: countBy( questions, function ( q ) { return q.category; } ),
				types: countBy( questions, function ( q ) { return q.type; } ),
				postStatuses: countBy( questions, function ( q ) { return q.postStatus; } ),
				combinations: countBy( questions, combinationKey ),
			},
		};
	}

	return {
		scan: scan,
		parseTitle: parseTitle,
		countBy: countBy,
		extractStatusCounts: extractStatusCounts,
	};
} )();
