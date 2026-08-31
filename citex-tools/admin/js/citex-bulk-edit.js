/**
 * Citex Tools — real Reference List bulk status editor.
 *
 * v0.8 stops using Citex's own wp_update_post endpoint. Instead it fetches
 * the configured Reference List screen, extracts WordPress's own Quick Edit
 * nonce/screen metadata, and calls the native `inline-save` AJAX action for
 * every selected Reference List post. This is intentionally the same save
 * path WordPress uses when Quick Edit/Bulk Edit works manually.
 *
 * After the writes finish, Citex re-scans and stores the Reference List so
 * the Question Bank immediately mirrors Published/Draft counts and statuses.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var panel = document.getElementById( 'citex-bulk-status-editor' );
		if ( ! panel || ! window.citexBulkEdit || ! window.citexTools || ! window.CitexScanner ) {
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
			var confirmation = citexBulkEdit.strings.confirm
				.replace( '{count}', ids.length )
				.replace( '{status}', label );
			if ( ! window.confirm( confirmation ) ) {
				return;
			}

			setDisabled( true );
			try {
				setProgress( 'Loading WordPress Quick Edit credentials from the real Reference List…' );
				var nativeContext = await loadNativeQuickEditContext();
				var summary = await runNativeUpdates( ids, status, nativeContext );

				if ( summary.failed.length ) {
					var sample = summary.failed.slice( 0, 3 ).map( function ( item ) {
						return '#' + item.postId + ': ' + item.reason;
					} ).join( ' | ' );
					setProgress(
						'WordPress updated ' + summary.updated + ' of ' + ids.length +
						'. Failed: ' + summary.failed.length + '. ' + sample +
						' Re-syncing Reference List…'
					);
				} else {
					setProgress( 'WordPress updated ' + summary.updated + ' of ' + ids.length + '. Re-syncing the real Reference List…' );
				}

				await rescanAndSave();
				setProgress(
					citexBulkEdit.strings.complete
						.replace( '{updated}', summary.updated )
						.replace( '{skipped}', 0 )
						.replace( '{failed}', summary.failed.length )
				);
				window.setTimeout( function () { window.location.reload(); }, 900 );
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

		/**
		 * Pull WordPress's real inline-edit nonce and screen metadata from the
		 * configured Reference List page. If this cannot be found, we stop rather
		 * than claiming an update succeeded.
		 */
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

		async function rescanAndSave() {
			var report = await CitexScanner.scan( citexTools.questionListUrl );
			var body = new URLSearchParams();
			body.set( 'action', citexTools.saveScanAction );
			body.set( 'nonce', citexTools.nonce );
			body.set( 'scan', JSON.stringify( report ) );
			var response = await fetch( citexTools.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} );
			var payload = await response.json();
			if ( ! payload || ! payload.success ) {
				throw new Error( 'WordPress was updated but Citex could not save the refreshed Reference List scan.' );
			}
			return payload.data;
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
