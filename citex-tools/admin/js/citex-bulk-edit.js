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

		wireClearQuestionBank();
		wireTrashBrokenIntextPage();
		wireForceRealUpdate();

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

		/**
		 * "Clear Question Bank" — moves every indexed Reference List post to
		 * Bin, regardless of the filtered/selected scope above. Reuses the
		 * same authenticated server batching (runServerBatches/postServerBatch)
		 * as the "Move to Bin" status option, rather than duplicating it.
		 */
		function wireClearQuestionBank() {
			var clearButton = document.getElementById( 'citex-clear-question-bank' );
			var clearPanel = document.getElementById( 'citex-clear-question-bank-panel' );
			var clearProgress = document.getElementById( 'citex-clear-question-bank-progress' );
			if ( ! clearButton || ! clearPanel ) {
				return;
			}

			var allIds = [];
			try {
				allIds = JSON.parse( clearPanel.getAttribute( 'data-all-post-ids' ) || '[]' );
			} catch ( error ) {
				allIds = [];
			}

			clearButton.addEventListener( 'click', async function () {
				var ids = uniquePositiveIntegers( allIds );
				if ( ! ids.length ) {
					setClearProgress( 'No indexed questions to clear.' );
					return;
				}
				if ( ! citexTools.questionListUrl ) {
					setClearProgress( 'Reference List URL is not configured.' );
					return;
				}
				if ( ! window.confirm( 'Move ALL ' + ids.length + ' Reference List question(s) to the WordPress Bin? This clears the entire Question Bank, regardless of any filter. They can still be restored from Bin afterwards.' ) ) {
					return;
				}

				clearButton.disabled = true;
				try {
					setClearProgress( 'Moving all Reference List questions to Bin…' );
					var summary = await runServerBatches( ids, 'trash' );
					if ( summary.failed.length ) {
						var sample = summary.failed.slice( 0, 3 ).map( function ( item ) {
							return '#' + item.postId + ': ' + item.reason;
						} ).join( ' | ' );
						setClearProgress(
							'WordPress cleared ' + summary.updated + ' of ' + ids.length +
							'. Failed: ' + summary.failed.length + '. ' + sample +
							' Synchronising Citex from WordPress…'
						);
					} else {
						setClearProgress( 'WordPress cleared ' + summary.updated + ' of ' + ids.length + '. Synchronising Citex from WordPress…' );
					}
					window.setTimeout( submitServerSync, 350 );
				} catch ( error ) {
					setClearProgress( citexBulkEdit.strings.failed + ' ' + error.message );
					clearButton.disabled = false;
				}
			} );

			function setClearProgress( message ) {
				if ( clearProgress ) {
					clearProgress.textContent = message;
				}
			}
		}

		/**
		 * "Broken In-Text Citation Questions — missing page reference" ->
		 * "Move All to Bin". A real reported bug: some already-published
		 * In-Text Citation DragDrop questions ask the student to drag in a
		 * page number that their own scenario text never states (fixed for
		 * all newly generated questions server-side — see
		 * Citex_Scanner::is_intext_dragdrop_missing_page()'s own docblock).
		 * This trashes the already-detected broken posts so the user can
		 * re-run Generate/Auto-Generate to populate corrected replacements.
		 * Reuses the same authenticated server batching
		 * (runServerBatches/postServerBatch) as "Clear Question Bank" and
		 * the "Move to Bin" status option, rather than duplicating it.
		 */
		function wireTrashBrokenIntextPage() {
			var trashButton = document.getElementById( 'citex-trash-broken-intext-page' );
			var trashPanel = document.getElementById( 'citex-broken-intext-page-panel' );
			var trashProgress = document.getElementById( 'citex-trash-broken-intext-page-progress' );
			if ( ! trashButton || ! trashPanel ) {
				return;
			}

			var allIds = [];
			try {
				allIds = JSON.parse( trashPanel.getAttribute( 'data-all-post-ids' ) || '[]' );
			} catch ( error ) {
				allIds = [];
			}

			trashButton.addEventListener( 'click', async function () {
				var ids = uniquePositiveIntegers( allIds );
				if ( ! ids.length ) {
					setTrashProgress( 'No broken questions to move.' );
					return;
				}
				if ( ! window.confirm( 'Move ' + ids.length + ' broken In-Text Citation question(s) to the WordPress Bin? They can still be restored from Bin afterwards — re-run Generate/Auto-Generate for the same style, category and In-Text Citation focus afterwards to populate corrected replacements.' ) ) {
					return;
				}

				trashButton.disabled = true;
				try {
					setTrashProgress( 'Moving broken In-Text Citation questions to Bin…' );
					var summary = await runServerBatches( ids, 'trash' );
					if ( summary.failed.length ) {
						var sample = summary.failed.slice( 0, 3 ).map( function ( item ) {
							return '#' + item.postId + ': ' + item.reason;
						} ).join( ' | ' );
						setTrashProgress(
							'WordPress moved ' + summary.updated + ' of ' + ids.length +
							'. Failed: ' + summary.failed.length + '. ' + sample +
							' Synchronising Citex from WordPress…'
						);
					} else {
						setTrashProgress( 'WordPress moved ' + summary.updated + ' of ' + ids.length + '. Synchronising Citex from WordPress…' );
					}
					window.setTimeout( submitServerSync, 350 );
				} catch ( error ) {
					setTrashProgress( citexBulkEdit.strings.failed + ' ' + error.message );
					trashButton.disabled = false;
				}
			} );

			function setTrashProgress( message ) {
				if ( trashProgress ) {
					trashProgress.textContent = message;
				}
			}
		}

		/**
		 * "Bulk Force Real Update" — a real reported problem: a freshly
		 * populated question does not show up in the site's separate
		 * student app until an admin manually opens it in wp-admin and
		 * clicks the real "Update" button, and re-running the same
		 * WordPress/ACF save functions in code (Citex_Populator::finalize_question(),
		 * the per-row "Finalise" button) was CONFIRMED not to fix it — the
		 * live investigation behind that (see class-citex-populator.php's
		 * own class docblock) showed the app's real trigger is neither
		 * wp_update_post() nor acf/save_post, both of which finalize_question()
		 * already fires. Since the true mechanism is still unidentified,
		 * this instead automates the ACTUAL manual fix: load each
		 * question's real edit screen in a hidden iframe and click its
		 * real "Update" submit button, exactly as a human would, so
		 * whatever the app actually keys off of — however undocumented —
		 * happens for real, for many questions in a row, without anyone
		 * opening each one by hand.
		 *
		 * Never touches any field: the iframe just submits the SAME edit
		 * form WordPress already renders, unmodified, so this changes
		 * nothing content-wise, only re-triggers the save. One question at
		 * a time (never in parallel), since each is a full page load in the
		 * browser, not a lightweight API call — a large batch takes real
		 * time and needs this tab to stay open.
		 */
		function wireForceRealUpdate() {
			var panel = document.getElementById( 'citex-bulk-real-update' );
			if ( ! panel || ! window.citexTools || ! citexTools.adminUrl || ! window.CitexForceUpdate ) {
				return;
			}

			var button = document.getElementById( 'citex-apply-real-update' );
			var scope = document.getElementById( 'citex-real-update-scope' );
			var progress = document.getElementById( 'citex-real-update-progress' );
			var filteredIds = [];

			try {
				filteredIds = JSON.parse( panel.getAttribute( 'data-filtered-post-ids' ) || '[]' );
			} catch ( error ) {
				filteredIds = [];
			}

			if ( ! button || ! scope ) {
				return;
			}

			button.addEventListener( 'click', async function () {
				var ids = 'selected' === scope.value ? selectedPostIds() : filteredIds.slice();
				ids = uniquePositiveIntegers( ids );
				if ( ! ids.length ) {
					setRealUpdateProgress( 'Select at least one question, or switch scope to "All filtered".' );
					return;
				}
				if ( ! window.confirm(
					'Open and re-save ' + ids.length + ' question(s) in the background by automatically clicking each one\'s real "Update" button? ' +
					'This does not change any content, status, or field — it only repeats the exact save WordPress already runs when you click Update by hand. ' +
					'This can take a while for a large batch and needs this browser tab to stay open until it finishes.'
				) ) {
					return;
				}

				button.disabled = true;
				scope.disabled = true;

				var summary = await CitexForceUpdate.forceRealUpdateBatch( ids, function ( index, total, postId ) {
					setRealUpdateProgress( 'Updating ' + ( index + 1 ) + ' of ' + total + ' (post #' + postId + ')…' );
				} );

				if ( summary.failed.length ) {
					var sample = summary.failed.slice( 0, 3 ).map( function ( item ) {
						return '#' + item.postId + ': ' + item.reason;
					} ).join( ' | ' );
					setRealUpdateProgress(
						'Done. Force-updated ' + summary.succeeded + ' of ' + ids.length + '. Failed: ' + summary.failed.length + '. ' + sample +
						( summary.failed.length === ids.length ? ' If every one failed immediately, a security plugin (e.g. Wordfence) may be blocking the background page loads this relies on.' : '' )
					);
				} else {
					setRealUpdateProgress( 'Done. Force-updated ' + summary.succeeded + ' of ' + ids.length + '. Check the student app to confirm they now show up.' );
				}

				button.disabled = false;
				scope.disabled = false;
			} );

			function setRealUpdateProgress( message ) {
				if ( progress ) {
					progress.textContent = message;
				}
			}
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
