/**
 * Citex Tools — bulk WordPress status editor.
 *
 * Lets an administrator apply one native WordPress post status to every
 * question matching the current Citex filters, not just the 20 rows visible
 * on the current page. Requests are chunked so 200+ posts can be changed
 * without relying on one long PHP request.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var panel = document.getElementById( 'citex-bulk-status-editor' );
		if ( ! panel || ! window.citexBulkEdit ) {
			return;
		}

		var button = document.getElementById( 'citex-apply-bulk-status' );
		var scope = document.getElementById( 'citex-bulk-scope' );
		var statusSelect = document.getElementById( 'citex-bulk-status' );
		var progress = document.getElementById( 'citex-bulk-status-progress' );
		var filteredIds = [];

		try {
			filteredIds = JSON.parse( panel.getAttribute( 'data-filtered-post-ids' ) || '[]' );
		} catch ( error ) {
			filteredIds = [];
		}

		if ( ! button || ! scope || ! statusSelect ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var ids = 'selected' === scope.value ? selectedPostIds() : filteredIds.slice();
			ids = uniquePositiveIntegers( ids );

			if ( ! ids.length ) {
				setProgress( citexBulkEdit.strings.noSelection );
				return;
			}

			var status = statusSelect.value;
			var label = statusSelect.options[ statusSelect.selectedIndex ].text;
			var confirmation = citexBulkEdit.strings.confirm
				.replace( '{count}', ids.length )
				.replace( '{status}', label );

			if ( ! window.confirm( confirmation ) ) {
				return;
			}

			button.disabled = true;
			scope.disabled = true;
			statusSelect.disabled = true;

			runBatches( ids, status )
				.then( function ( summary ) {
					setProgress(
						citexBulkEdit.strings.complete
							.replace( '{updated}', summary.updated )
							.replace( '{skipped}', summary.skipped )
							.replace( '{failed}', summary.failed )
					);
					window.setTimeout( function () {
						window.location.reload();
					}, 1200 );
				} )
				.catch( function ( error ) {
					setProgress( citexBulkEdit.strings.failed + ' ' + error.message );
					button.disabled = false;
					scope.disabled = false;
					statusSelect.disabled = false;
				} );
		} );

		function selectedPostIds() {
			var ids = [];
			document.querySelectorAll( '.citex-row-select:checked[data-post-id]' ).forEach( function ( checkbox ) {
				ids.push( checkbox.getAttribute( 'data-post-id' ) );
			} );
			return ids;
		}

		function uniquePositiveIntegers( ids ) {
			var seen = {};
			var out = [];
			ids.forEach( function ( value ) {
				var id = parseInt( value, 10 );
				if ( id > 0 && ! seen[ id ] ) {
					seen[ id ] = true;
					out.push( id );
				}
			} );
			return out;
		}

		async function runBatches( ids, status ) {
			var batchSize = citexBulkEdit.batchSize || 40;
			var summary = { updated: 0, skipped: 0, failed: 0 };

			for ( var offset = 0; offset < ids.length; offset += batchSize ) {
				var batch = ids.slice( offset, offset + batchSize );
				var end = Math.min( offset + batch.length, ids.length );
				setProgress(
					citexBulkEdit.strings.updating
						.replace( '{from}', offset + 1 )
						.replace( '{to}', end )
						.replace( '{total}', ids.length )
				);

				var result = await postBatch( batch, status );
				summary.updated += result.updated || 0;
				summary.skipped += result.skipped || 0;
				summary.failed += ( result.failed || [] ).length;
			}

			return summary;
		}

		function postBatch( ids, status ) {
			var body = new URLSearchParams();
			body.set( 'action', citexBulkEdit.action );
			body.set( 'nonce', citexBulkEdit.nonce );
			body.set( 'status', status );
			body.set( 'post_ids', JSON.stringify( ids ) );

			return fetch( citexBulkEdit.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( ( payload && payload.data && payload.data.message ) || 'Bulk update request failed.' );
				}
				return payload.data;
			} );
		}

		function setProgress( message ) {
			if ( progress ) {
				progress.textContent = message;
			}
		}
	} );
} )();
