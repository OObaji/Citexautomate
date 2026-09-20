/**
 * Citex Tools — shared "click the real Update button in the background".
 *
 * A real reported problem: a freshly populated question (DragDrop in
 * particular) does not show up in the site's separate student app until
 * an admin manually opens it in wp-admin and clicks the real "Update"
 * button — and re-running the same WordPress/ACF save FUNCTIONS in code
 * (Citex_Populator::finalize_question(), already run automatically at
 * population time and again via the per-row "Finalise" button) was
 * CONFIRMED by live testing not to fix it. Since the app's true trigger
 * is still unidentified, this instead automates the ACTUAL manual fix:
 * load the post's real edit screen in a hidden iframe and click its real
 * "Update" submit button, exactly as a human would.
 *
 * Extracted into its own file (rather than living only inside
 * citex-bulk-edit.js's own Questions-page bulk panel) so BOTH that panel
 * AND admin/js/citex-admin.js's own automatic after-publish trigger on
 * the Generate Questions page (see wireAutoForceUpdate() there) can drive
 * it without duplicating this iframe/timeout logic. Exposed as
 * window.CitexForceUpdate.forceRealUpdate — loaded before both callers
 * (see class-citex-admin.php's own enqueue_assets()).
 */
( function () {
	'use strict';

	/**
	 * Loads one post's real wp-admin edit screen in a hidden iframe and
	 * clicks its real "Update"/"Publish" submit button (WordPress core's
	 * own, stable `#publish` — the classic editor's Publish metabox,
	 * unchanged since WordPress 2.7) — never a synthetic form post built
	 * by hand, so the browser goes through the exact same request
	 * WordPress itself renders and expects. Resolves once the resulting
	 * save has fully loaded (the second navigation inside the iframe,
	 * after WordPress's own POST-redirect-GET); rejects with a specific
	 * reason otherwise (can't load the screen, can't find the button, or
	 * a timeout) rather than hanging forever on one bad post.
	 *
	 * @param {number} postId
	 * @return {Promise<void>}
	 */
	function forceRealUpdate( postId ) {
		return new Promise( function ( resolve, reject ) {
			if ( ! window.citexTools || ! citexTools.adminUrl ) {
				reject( new Error( 'citexTools.adminUrl is not available.' ) );
				return;
			}

			var TIMEOUT_MS = 20000;
			var loadCount = 0;
			var timeoutId = null;
			var iframe = document.createElement( 'iframe' );
			iframe.style.display = 'none';

			function cleanup() {
				if ( timeoutId ) {
					window.clearTimeout( timeoutId );
				}
				iframe.removeEventListener( 'load', onLoad );
				if ( iframe.parentNode ) {
					iframe.parentNode.removeChild( iframe );
				}
			}

			function armTimeout( message ) {
				timeoutId = window.setTimeout( function () {
					cleanup();
					reject( new Error( message ) );
				}, TIMEOUT_MS );
			}

			function onLoad() {
				loadCount++;
				if ( timeoutId ) {
					window.clearTimeout( timeoutId );
				}

				if ( 1 === loadCount ) {
					var doc;
					try {
						doc = iframe.contentDocument || ( iframe.contentWindow && iframe.contentWindow.document );
					} catch ( error ) {
						cleanup();
						reject( new Error( 'Could not access the edit screen (blocked by browser security, or the page failed to load).' ) );
						return;
					}
					var updateButton = doc && doc.getElementById( 'publish' );
					if ( ! updateButton ) {
						cleanup();
						reject( new Error( 'Could not find the real Update button on the edit screen — this post may not exist, you may not have permission to edit it, or the edit screen\'s layout is not what this expects.' ) );
						return;
					}
					armTimeout( 'Timed out waiting for the Update click to finish saving.' );
					updateButton.click();
				} else {
					cleanup();
					resolve();
				}
			}

			iframe.addEventListener( 'load', onLoad );
			armTimeout( 'Timed out loading the edit screen.' );
			iframe.src = citexTools.adminUrl + 'post.php?post=' + postId + '&action=edit';
			document.body.appendChild( iframe );
		} );
	}

	/**
	 * Runs forceRealUpdate() over a list of post IDs strictly ONE AT A
	 * TIME (never in parallel — each is a real page load, not a
	 * lightweight API call), reporting progress via the given callback.
	 * Never throws: a failed post is recorded and the loop continues,
	 * exactly like every other bulk action in this codebase (one bad
	 * item must never abort the rest of the batch).
	 *
	 * @param {number[]} postIds
	 * @param {function(number, number, number):void} [onProgress] (index, total, postId) called before each attempt.
	 * @return {Promise<{succeeded:number, failed:Array<{postId:number, reason:string}>}>}
	 */
	async function forceRealUpdateBatch( postIds, onProgress ) {
		var succeeded = 0;
		var failed = [];
		for ( var i = 0; i < postIds.length; i++ ) {
			if ( onProgress ) {
				onProgress( i, postIds.length, postIds[ i ] );
			}
			try {
				await forceRealUpdate( postIds[ i ] );
				succeeded++;
			} catch ( error ) {
				failed.push( { postId: postIds[ i ], reason: error.message } );
			}
		}
		return { succeeded: succeeded, failed: failed };
	}

	window.CitexForceUpdate = {
		forceRealUpdate: forceRealUpdate,
		forceRealUpdateBatch: forceRealUpdateBatch,
	};
} )();
