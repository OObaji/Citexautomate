/**
 * TEMPORARY: Multi-Style Batch Populate.
 *
 * A real requested feature, explicitly temporary: the admin has newer
 * Referencing Styles (e.g. Chicago, MHRA) still needing Reference List
 * coverage across all 4 categories and wants to queue that up overnight
 * rather than running the existing single Style+Category Auto-Generate
 * by hand once per combination. Ticks any number of Referencing Styles,
 * takes ONE "target per category" number, and repeats the SAME per-batch
 * AJAX action the existing Auto-Generate feature uses (citex-admin.js's
 * own wireAutoGenerate() — 20 questions generated AND published per
 * batch, exactly like a manual "Generate & Publish" click) for every
 * ticked-style × category combination, one combination at a time, until
 * EACH ONE reaches the target.
 *
 * Deliberately a separate, self-contained file rather than a refactor of
 * the existing (already shipped, already tested) wireAutoGenerate() — the
 * admin said this is a temporary tool to be deleted once the backfill is
 * done, so keeping it fully independent means removing it later is just:
 * delete this file, its enqueue line in class-citex-admin.php, and the
 * "Multi-Style Batch Populate" section in admin/views/generate.php —
 * nothing else changes, and the tested Auto-Generate feature is never
 * touched by that removal.
 *
 * A dedicated Question Focus select (Reference List / In-Text Citation)
 * lets one run target either — every ticked style now supports both.
 * Difficulty and Question Type are read from the same fields the rest of
 * the Generate page uses, at the moment Start is clicked.
 *
 * Unlike the single-combination Auto-Generate loop (which fully stops the
 * instant 3 batches in a row publish nothing new), a stall, a safety-limit
 * hit, or a failed batch here logs a warning and moves on to the NEXT
 * combination instead of halting the whole overnight run — one
 * problematic Style+Category combination should not block the rest from
 * completing while the admin is asleep.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		wireMultiStyleBatch();
	} );

	function postToAjax( fields ) {
		var body = new URLSearchParams();
		Object.keys( fields ).forEach( function ( key ) {
			body.set( key, fields[ key ] );
		} );
		return fetch( citexTools.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function setText( element, text ) {
		if ( element ) {
			element.textContent = text;
		}
	}

	// A local copy of citex-admin.js's own playCitexChime() — duplicated
	// rather than shared so this whole feature stays deletable as one
	// self-contained file (see this file's own docblock).
	function playChime() {
		try {
			var AudioContextClass = window.AudioContext || window.webkitAudioContext;
			if ( ! AudioContextClass ) {
				return;
			}
			var ctx = new AudioContextClass();
			[ 660, 880 ].forEach( function ( frequency, index ) {
				var oscillator = ctx.createOscillator();
				var gain = ctx.createGain();
				var startTime = ctx.currentTime + index * 0.16;
				oscillator.type = 'sine';
				oscillator.frequency.value = frequency;
				gain.gain.setValueAtTime( 0.0001, startTime );
				gain.gain.exponentialRampToValueAtTime( 0.2, startTime + 0.02 );
				gain.gain.exponentialRampToValueAtTime( 0.0001, startTime + 0.18 );
				oscillator.connect( gain );
				gain.connect( ctx.destination );
				oscillator.start( startTime );
				oscillator.stop( startTime + 0.2 );
			} );
		} catch ( e ) {
			// Autoplay blocked or Web Audio unsupported — nothing to do.
		}
	}

	// The SAME style+group+category published-count map the existing
	// Auto-Generate feature reads (see Citex_Generator::render()'s own
	// $combined_counts, JSON-encoded onto .citex-auto-generate's own
	// data-published-counts attribute) — reused here rather than
	// re-fetched, so a combination's baseline is exactly what the rest of
	// the page already shows.
	function readPublishedCounts() {
		var container = document.querySelector( '.citex-auto-generate' );
		if ( ! container ) {
			return {};
		}
		try {
			return JSON.parse( container.getAttribute( 'data-published-counts' ) || '{}' ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function optionLabel( opt ) {
		return opt.textContent.replace( /\s*\([\d,]+\)\s*$/, '' );
	}

	function wireMultiStyleBatch() {
		var container       = document.querySelector( '.citex-multi-style-batch' );
		var styleSelect      = document.getElementById( 'citex_referencing_style' );
		var categorySelect   = document.getElementById( 'citex_category' );
		var difficultySelect = document.getElementById( 'citex_difficulty' );
		var typeSelect       = document.getElementById( 'citex_question_type' );
		if ( ! container || ! styleSelect || ! categorySelect || ! window.citexTools || ! citexTools.generator ) {
			return;
		}

		var stylesContainer = document.getElementById( 'citex-multi-style-batch-styles' );
		var groupSelect     = document.getElementById( 'citex_multi_style_batch_group' );
		var targetInput     = document.getElementById( 'citex_multi_style_batch_target' );
		var startButton     = document.getElementById( 'citex-multi-style-batch-start' );
		var stopButton      = document.getElementById( 'citex-multi-style-batch-stop' );
		var status          = document.getElementById( 'citex-multi-style-batch-status' );
		var log             = document.getElementById( 'citex-multi-style-batch-log' );
		if ( ! stylesContainer || ! groupSelect || ! targetInput || ! startButton || ! stopButton ) {
			return;
		}

		// One checkbox per Referencing Style, built from the SAME <select>
		// the rest of the page uses — never a second, separately maintained
		// style list that could drift out of sync with it.
		Array.prototype.forEach.call( styleSelect.options, function ( opt ) {
			var wrapper = document.createElement( 'label' );
			wrapper.style.display     = 'inline-flex';
			wrapper.style.alignItems  = 'center';
			wrapper.style.gap         = '4px';
			var checkbox = document.createElement( 'input' );
			checkbox.type  = 'checkbox';
			checkbox.value = opt.value;
			wrapper.appendChild( checkbox );
			wrapper.appendChild( document.createTextNode( optionLabel( opt ) ) );
			stylesContainer.appendChild( wrapper );
		} );

		// The 4 categories, in the same order the Category <select> already
		// lists them — never re-derived or reordered here.
		var categories = Array.prototype.map.call( categorySelect.options, function ( opt ) {
			return { key: opt.value, label: optionLabel( opt ) };
		} );

		// Matches the existing Auto-Generate feature's own per-batch cap
		// (Citex_Generator::auto_generate_batch_body()'s own clamp) — 20
		// questions generated AND published per batch, never more.
		var BATCH_CAP             = 20;
		var MAX_BATCHES_PER_COMBO = 50;

		var stopRequested = false;

		function logLine( text ) {
			if ( ! log ) {
				return;
			}
			var item = document.createElement( 'li' );
			item.textContent = text;
			log.appendChild( item );
			log.scrollTop = log.scrollHeight;
		}

		// Runs one Style+Category combination to its own target, in 20-at-
		// a-time batches — the same shape as citex-admin.js's own
		// wireAutoGenerate()/runNextBatch(), just resolving a Promise
		// instead of driving its own Start/Stop buttons, so runAll() below
		// can await one combination before starting the next. $groupKey is
		// read once from the Question Focus select when Start is clicked
		// (see startButton's own listener below) and applies to every
		// combination in this run.
		function runCombo( combo, target, groupKey ) {
			var publishedCounts = readPublishedCounts();
			var baseline = ( publishedCounts[ combo.styleKey ] && publishedCounts[ combo.styleKey ][ groupKey ] && publishedCounts[ combo.styleKey ][ groupKey ][ combo.categoryKey ] ) || 0;

			return new Promise( function ( resolve ) {
				var createdTotal     = 0;
				var batchesRun       = 0;
				var noProgressStreak = 0;

				function nextBatch() {
					if ( stopRequested ) {
						resolve( { outcome: 'stopped', total: baseline + createdTotal } );
						return;
					}
					var totalSoFar = baseline + createdTotal;
					var remaining  = target - totalSoFar;
					if ( remaining <= 0 ) {
						resolve( { outcome: 'reached', total: totalSoFar } );
						return;
					}
					if ( batchesRun >= MAX_BATCHES_PER_COMBO ) {
						resolve( { outcome: 'batchLimit', total: totalSoFar } );
						return;
					}

					batchesRun++;
					var batchQuantity = Math.min( BATCH_CAP, remaining );
					setText( status, combo.styleLabel + ' — ' + combo.categoryLabel + ': batch ' + batchesRun + '… (' + totalSoFar + '/' + target + ')' );

					postToAjax( {
						action:                   citexTools.generator.autoGenerateAction,
						nonce:                    citexTools.generator.nonce,
						citex_referencing_style:  combo.styleKey,
						citex_category:           combo.categoryKey,
						citex_difficulty:         difficultySelect ? difficultySelect.value : 'hard',
						citex_question_group:     groupKey,
						citex_question_type:      typeSelect ? typeSelect.value : 'mixed',
						citex_quantity:           batchQuantity,
					} )
						.then( async function ( result ) {
							if ( stopRequested ) {
								resolve( { outcome: 'stopped', total: baseline + createdTotal } );
								return;
							}
							if ( ! result || ! result.success ) {
								resolve( { outcome: 'failed', total: baseline + createdTotal, message: ( result && result.data && result.data.message ) || 'unknown error' } );
								return;
							}
							var data    = result.data || {};
							var created = data.createdCount || 0;
							createdTotal += created;
							logLine( combo.styleLabel + ' — ' + combo.categoryLabel + ': +' + created + ' published (' + ( baseline + createdTotal ) + '/' + target + ').' );

							// The exact same automatic DragDrop force-update
							// every other publish path in this plugin does
							// (see admin/js/citex-force-update.js's own
							// docblock) — never MCQ.
							var dragdropIds = data.dragdropCreatedPostIds || [];
							if ( dragdropIds.length && window.CitexForceUpdate ) {
								logLine( 'Force-updating ' + dragdropIds.length + ' DragDrop question(s)…' );
								var forceSummary = await CitexForceUpdate.forceRealUpdateBatch( dragdropIds, function () {} );
								logLine(
									'Force-updated ' + forceSummary.succeeded + '/' + dragdropIds.length + '.' +
									( forceSummary.failed.length ? ' Failed: ' + forceSummary.failed.length + '.' : '' )
								);
							}

							noProgressStreak = created > 0 ? 0 : noProgressStreak + 1;
							if ( noProgressStreak >= 3 ) {
								resolve( { outcome: 'stalled', total: baseline + createdTotal } );
								return;
							}

							window.setTimeout( nextBatch, 500 );
						} )
						.catch( function ( error ) {
							resolve( { outcome: 'failed', total: baseline + createdTotal, message: error.message } );
						} );
				}

				nextBatch();
			} );
		}

		// Runs every combination ONE AT A TIME (never in parallel — the
		// same real-server-load reasoning as citex-force-update.js's own
		// sequential batch runner), continuing past a stalled/failed/
		// limit-hit combination rather than aborting the whole run, so one
		// bad combination cannot block the rest while the admin is asleep.
		async function runAll( combos, target, groupKey ) {
			var completed = 0;
			for ( var i = 0; i < combos.length; i++ ) {
				if ( stopRequested ) {
					logLine( 'Stopped by you before finishing every combination.' );
					break;
				}
				var combo = combos[ i ];
				logLine( '— Starting ' + combo.styleLabel + ' — ' + combo.categoryLabel + ' (target ' + target + ') —' );
				var result = await runCombo( combo, target, groupKey );
				if ( 'reached' === result.outcome ) {
					logLine( '✓ ' + combo.styleLabel + ' — ' + combo.categoryLabel + ' done: ' + result.total + '/' + target + ' published.' );
					completed++;
				} else if ( 'stopped' === result.outcome ) {
					logLine( 'Stopped — ' + combo.styleLabel + ' — ' + combo.categoryLabel + ' at ' + result.total + '/' + target + '.' );
					break;
				} else if ( 'stalled' === result.outcome ) {
					logLine( '⚠ ' + combo.styleLabel + ' — ' + combo.categoryLabel + ' stalled at ' + result.total + '/' + target + ' (3 batches published nothing new) — moving on.' );
				} else if ( 'batchLimit' === result.outcome ) {
					logLine( '⚠ ' + combo.styleLabel + ' — ' + combo.categoryLabel + ' hit the safety batch limit at ' + result.total + '/' + target + ' — moving on.' );
				} else if ( 'failed' === result.outcome ) {
					logLine( '⚠ ' + combo.styleLabel + ' — ' + combo.categoryLabel + ' batch failed: ' + result.message + ' — moving on.' );
				}
			}
			return completed;
		}

		startButton.addEventListener( 'click', function () {
			var selectedStyles = Array.prototype.filter.call(
				stylesContainer.querySelectorAll( 'input[type="checkbox"]' ),
				function ( checkbox ) {
					return checkbox.checked;
				}
			).map( function ( checkbox ) {
				return { key: checkbox.value, label: optionLabel( styleSelect.querySelector( 'option[value="' + checkbox.value + '"]' ) ) };
			} );

			if ( ! selectedStyles.length ) {
				setText( status, 'Tick at least one Referencing Style first.' );
				return;
			}

			var target = parseInt( targetInput.value, 10 ) || 0;
			if ( target <= 0 ) {
				setText( status, 'Enter a target per category first.' );
				return;
			}

			var groupKey = groupSelect.value;

			var combos = [];
			selectedStyles.forEach( function ( style ) {
				categories.forEach( function ( category ) {
					combos.push( { styleKey: style.key, styleLabel: style.label, categoryKey: category.key, categoryLabel: category.label } );
				} );
			} );

			stopRequested = false;
			if ( log ) {
				log.innerHTML = '';
			}
			startButton.disabled     = true;
			stopButton.style.display = '';
			setText( status, 'Running ' + combos.length + ' combination(s) (' + selectedStyles.length + ' style(s) × ' + categories.length + ' categories), target ' + target + ' each…' );

			runAll( combos, target, groupKey ).then( function ( completed ) {
				startButton.disabled     = false;
				stopButton.style.display = 'none';
				setText( status, '✓ Done — ' + completed + '/' + combos.length + ' combination(s) reached target ' + target + '.' );
				playChime();
			} );
		} );

		stopButton.addEventListener( 'click', function () {
			stopRequested = true;
			setText( status, 'Stopping after the current batch…' );
		} );
	}
} )();
