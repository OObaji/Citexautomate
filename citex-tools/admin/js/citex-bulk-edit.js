/**
 * Citex Tools — real Reference List bulk editor.
 *
 * Published/Draft/Pending/Private use WordPress's native Quick Edit
 * `inline-save` path. "Move to Bin" is different: WordPress trashing has its
 * own lifecycle/metadata, so Citex sends those batches to its authenticated
 * server endpoint, which calls wp_trash_post() for each real Reference List
 * post. After either path completes, Citex runs the server-side sync.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var panel = document.getElementById( 'citex-bulk-status-editor' );
		if ( ! panel || ! window.citexBulkEdit || ! window.citexTools ) {
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

		button.addEventListener( 'click', async function () {
			var ids = 'selected' === scope.value ? selectedPostIds() : filteredIds.slice();
			ids = uniquePositiveIntegers( ids );
			if ( ! ids.length ) {
				setProgress( citexBulkEdit.strings.noSelection );
				return;
			}
			if ( ! citexTools.questionListUrl ) {
				setProgress( 'Reference List URL is not configured.' );
				return;
			}

			var status = statusSelect.value;
			var label = statusSelect.options[ statusSelect.selectedIndex ].text;
			var confirmation;

			if ( 'trash' === status ) {
				confirmation = 'Move ' + ids.length + ' question(s) to the WordPress Bin? They will be removed from the active Reference List but can still be restored from Bin.';
			} else {
				confirmation = citexBulkEdit.strings.confirm
					.replace( '{count}', ids.length )
					.replace( '{status}', label );
			}

			if ( ! window.confirm( confirmation ) ) {
				return;
			}

			setDisabled( true );
			try {
				var summary;

				if ( 'trash' === status ) {
					setProgress( 'Moving real Reference List questions to Bin…' );
					summary = await runServerBatches( ids, status );
				} else {
					setProgress( 'Loading WordPress Quick Edit credentials from the real Reference List…' );
					var nativeContext = await loadNativeQuickEditContext();
					summary = await runNativeUpdates( ids, status, nativeContext );
				}

				if ( summary.failed.length ) {
					var sample = summary.failed.slice( 0, 3 ).map( function ( item ) {
						return '#' + item.postId + ': ' + item.reason;
					} ).join( ' | ' );
					setProgress(
						'WordPress changed ' + summary.updated + ' of ' + ids.length +
						'. Failed: ' + summary.failed.length + '. ' + sample +
						' Synchronising Citex from WordPress…'
					);
				} else {
					setProgress( 'WordPress changed ' + summary.updated + ' of ' + ids.length + '. Synchronising Citex from WordPress…' );
				}

				window.setTimeout( submitServerSync, 350 );
			} catch ( error ) {
				setProgress( citexBulkEdit.strings.failed + ' ' + error.message );
				setDisabled( false );
			}
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

		async function runServerBatches( ids, status ) {
			var batchSize = citexBulkEdit.batchSize || 40;
			var summary = { updated: 0, skipped: 0, failed: [] };

			for ( var offset = 0; offset < ids.length; offset += batchSize ) {
				var batch = ids.slice( offset, offset + batchSize );
				var end = Math.min( offset + batch.length, ids.length );
				setProgress(
					'Moving questions ' + ( offset + 1 ) + '–' + end + ' of ' + ids.length + ' to Bin…'
				);

				var result = await postServerBatch( batch, status );
				summary.updated += result.updated || 0;
				summary.skipped += result.skipped || 0;
				( result.failed || [] ).forEach( function ( failure ) {
					summary.failed.push( failure );
				} );
			}

			return summary;
		}

		function postServerBatch( ids, status ) {
			var body = new URLSearchParams();
			body.set( 'action', citexBulkEdit.action );
			body.set( 'nonce', citexBulkEdit.nonce );
			body.set( 'status', status );
			body.set( 'post_ids', JSON.stringify( ids ) );

			return fetch( citexBulkEdit.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( ( payload && payload.data && payload.data.message ) || 'WordPress trash request failed.' );
				}
				return payload.data;
			} );
		}

		async function loadNativeQuickEditContext() {
			var url = new URL( citexTools.questionListUrl, window.location.origin );
			url.searchParams.delete( 'post_status' );
			url.searchParams.set( 'paged', '1' );
			var response = await fetch( url.href, { credentials: 'same-origin' } );
			if ( ! response.ok ) {
				throw new Error( 'Could not load Reference List (HTTP ' + response.status + ').' );
			}
			var html = await response.text();
			var doc = new DOMParser().parseFromString( html, 'text/html' );
			var nonceInput = doc.querySelector( '#inline-edit input[name="_inline_edit"], input[name="_inline_edit"]' );
			var screenInput = doc.querySelector( '#inline-edit input[name="screen"], input[name="screen"]' );
			var postViewInput = doc.querySelector( '#inline-edit input[name="post_view"], input[name="post_view"]' );
			var postType = url.searchParams.get( 'post_type' ) || '';
			if ( ! postType ) {
				var postTypeInput = doc.querySelector( 'input[name="post_type"]' );
				postType = postTypeInput ? postTypeInput.value : '';
			}

			if ( ! nonceInput || ! nonceInput.value ) {
				throw new Error( 'Could not find WordPress Quick Edit nonce on the Reference List page.' );
			}
			if ( ! postType ) {
				throw new Error( 'Could not determine the Reference List post type.' );
			}

			return {
				nonce: nonceInput.value,
				screen: screenInput ? screenInput.value : ( 'edit-' + postType ),
				postView: postViewInput ? postViewInput.value : 'list',
				postType: postType,
			};
		}

		async function runNativeUpdates( ids, status, context ) {
			var summary = { updated: 0, failed: [] };
			for ( var i = 0; i < ids.length; i++ ) {
				setProgress(
					citexBulkEdit.strings.updating
						.replace( '{from}', i + 1 )
						.replace( '{to}', i + 1 )
						.replace( '{total}', ids.length )
				);
				try {
					await nativeInlineSave( ids[ i ], status, context );
					summary.updated++;
				} catch ( error ) {
					summary.failed.push( { postId: ids[ i ], reason: error.message } );
				}
			}
			return summary;
		}

		function nativeInlineSave( postId, status, context ) {
			var body = new URLSearchParams();
			body.set( 'action', 'inline-save' );
			body.set( '_inline_edit', context.nonce );
			body.set( 'post_ID', String( postId ) );
			body.set( 'post_type', context.postType );
			body.set( '_status', status );
			body.set( 'screen', context.screen );
			body.set( 'post_view', context.postView );
			body.set( 'edit_date', 'true' );

			return fetch( citexTools.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
			} ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.text();
			} ).then( function ( html ) {
				if ( html.indexOf( '<tr' ) === -1 ) {
					var text = html.replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
					throw new Error( text || 'WordPress inline save returned no updated row.' );
				}
				return true;
			} );
		}

		function submitServerSync() {
			var input = document.querySelector( 'input[name="citex_sync_reference_list"]' );
			var form = input ? input.closest( 'form' ) : null;
			if ( ! form ) {
				window.location.reload();
				return;
			}
			form.submit();
		}

		function setDisabled( disabled ) {
			button.disabled = disabled;
			scope.disabled = disabled;
			statusSelect.disabled = disabled;
		}

		function setProgress( message ) {
			if ( progress ) {
				progress.textContent = message;
			}
		}
	} );
} )();
