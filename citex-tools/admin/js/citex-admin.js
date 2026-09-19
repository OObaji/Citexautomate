/**
 * Citex Tools — admin JS.
 * Loaded only on Citex admin screens. No build step, no dependencies.
 * Wires the Question Bank "select all" checkbox, the Question List URL
 * settings form, the Scan Question Bank flow, and the Validation page /
 * Questions page validate controls — the actual scan and validate logic
 * live in admin/js/citex-scanner.js and admin/js/citex-validator.js,
 * loaded as dependencies.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		wireSelectAll();
		wireScannerSettings();
		wireScanButtons();
		wireDetectCitations();
		wireValidation();
		wireActionToast();
		playActionToneIfNeeded();
		wireCategoryStyleCounts();
		wireAutoGenerate();
	} );

	/**
	 * The Style+Category published-count map server-rendered onto
	 * .citex-auto-generate's own data-published-counts attribute (see
	 * Citex_Generator::render()'s own docblock on $combined_counts) —
	 * shared by wireCategoryStyleCounts() (re-labels the Category
	 * dropdown's own bracketed counts whenever Referencing Style changes)
	 * and wireAutoGenerate() (the Auto-Generate feature's own baseline),
	 * so both read the exact same numbers rather than parsing the
	 * attribute twice. Returns {} (never null) if the container or
	 * attribute is missing/unparsable, so callers never need their own
	 * null check.
	 */
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

	/**
	 * A real reported bug: the Category dropdown's own bracketed count
	 * used to be a single total across EVERY Referencing Style combined
	 * (e.g. "Book (200)" even with MLA selected, mixing in every other
	 * style's own Book count too) — genuinely misleading once more than
	 * one style has real coverage. Re-labels each Category option to that
	 * SPECIFIC style's own count instead, the moment Referencing Style is
	 * changed (and once on page load, since the server already rendered
	 * the initial options against the default-selected style — see
	 * Citex_Generator::render()'s own $default_style_key).
	 */
	function wireCategoryStyleCounts() {
		var styleSelect    = document.getElementById( 'citex_referencing_style' );
		var categorySelect = document.getElementById( 'citex_category' );
		if ( ! styleSelect || ! categorySelect ) {
			return;
		}

		var publishedCounts = readPublishedCounts();

		// Each option's own base label (without a trailing " (N)") is
		// captured once up front, so re-labelling repeatedly on every
		// style change never compounds onto an already-relabelled string.
		var baseLabels = Array.prototype.map.call( categorySelect.options, function ( opt ) {
			return opt.textContent.replace( /\s*\([\d,]+\)\s*$/, '' );
		} );

		function sync() {
			var counts = publishedCounts[ styleSelect.value ] || {};
			Array.prototype.forEach.call( categorySelect.options, function ( opt, index ) {
				var count = counts[ opt.value ] || 0;
				opt.textContent = baseLabels[ index ] + ' (' + count.toLocaleString() + ')';
			} );
		}

		styleSelect.addEventListener( 'change', sync );
		sync();
	}

	/**
	 * Auto-dismiss the Citex action toast (server-rendered by
	 * Citex_Admin::render_notice()) after a few seconds. The notice stays
	 * manually dismissible the whole time via WordPress's own "×" button
	 * (wp-admin's common.js already wires that up for any .is-dismissible
	 * notice), this only adds the auto-disappear behaviour on top.
	 */
	function wireActionToast() {
		var toast = document.querySelector( '.citex-action-notice' );

		if ( ! toast ) {
			return;
		}

		window.setTimeout( function () {
			toast.classList.add( 'citex-toast-hide' );
			window.setTimeout( function () {
				if ( toast.parentNode ) {
					toast.parentNode.removeChild( toast );
				}
			}, 250 );
		}, 6000 );
	}

	/**
	 * Play a short chime the moment the page comes back with a Citex
	 * action notice (see wireActionToast() above) — in particular
	 * Generate/Generate & Publish, which can take long enough for an admin
	 * to switch tabs while it runs. Best-effort only: some browsers block
	 * audio that isn't tied to a very recent user gesture (form submission
	 * itself usually counts, but not always), so this is wrapped so a
	 * blocked/unsupported play() never affects the rest of the page — the
	 * visual toast still shows either way.
	 */
	function playActionToneIfNeeded() {
		if ( ! document.querySelector( '.citex-action-notice' ) ) {
			return;
		}

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

	function wireSelectAll() {
		var selectAll = document.getElementById( 'citex-select-all' );

		if ( ! selectAll ) {
			return;
		}

		selectAll.addEventListener( 'change', function () {
			document.querySelectorAll( '.citex-row-select' ).forEach( function ( checkbox ) {
				checkbox.checked = selectAll.checked;
			} );
		} );
	}

	/**
	 * Per-target config for the two independently scannable real WordPress
	 * lists — Reference List and Citations (a genuinely separate post
	 * type; see Citex_Scanner::target_for_group()'s own PHP-side docblock)
	 * — keyed by each element's own `data-target` attribute, so the
	 * settings-form/scan-button wiring below runs once, generically, for
	 * both rather than being duplicated per target.
	 */
	function targetConfig( target ) {
		if ( 'citations' === target ) {
			return {
				urlKey: 'citationsListUrl',
				noUrlString: 'noCitationsUrl',
				inputId: 'citex_citations_list_url',
			};
		}
		return {
			urlKey: 'questionListUrl',
			noUrlString: 'noUrl',
			inputId: 'citex_question_list_url',
		};
	}

	function wireScannerSettings() {
		var forms = document.querySelectorAll( '.citex-scanner-settings-form' );

		if ( ! forms.length || ! window.citexTools ) {
			return;
		}

		forms.forEach( function ( form ) {
			var target = form.getAttribute( 'data-target' ) || 'reference';
			var config = targetConfig( target );

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				var input = document.getElementById( config.inputId );
				var status = form.querySelector( '.citex-settings-status' );
				var button = form.querySelector( 'button[type="submit"]' );

				if ( ! input ) {
					return;
				}

				button.disabled = true;
				setText( status, citexTools.strings.savingSettings );

				postToAjax( {
					action: citexTools.saveSettingsAction,
					nonce: citexTools.nonce,
					target: target,
					question_list_url: input.value,
				} )
					.then( function ( result ) {
						if ( result && result.success ) {
							citexTools[ config.urlKey ] = result.data.questionListUrl;
							setText( status, citexTools.strings.settingsSaved );
							toggleScanButtons( target, !! citexTools[ config.urlKey ] );
						} else {
							setText( status, citexTools.strings.settingsFailed );
						}
					} )
					.catch( function () {
						setText( status, citexTools.strings.settingsFailed );
					} )
					.finally( function () {
						button.disabled = false;
					} );
			} );
		} );
	}

	/**
	 * "Detect Automatically" — Citex runs as a local WordPress plugin (see
	 * Citex_Scanner::detect_citations_post_type()'s own PHP-side docblock),
	 * so it can look through the site's OWN registered post types itself
	 * rather than making the admin copy a URL out of the browser's address
	 * bar. Fills the Citations URL field and saves it in one click on
	 * success; on failure (no matching post type found), leaves the field
	 * for manual entry.
	 */
	function wireDetectCitations() {
		var button = document.getElementById( 'citex-detect-citations-btn' );

		if ( ! button || ! window.citexTools ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var form = button.closest( 'form' );
			var input = document.getElementById( 'citex_citations_list_url' );
			var status = form ? form.querySelector( '.citex-settings-status' ) : null;

			button.disabled = true;
			setText( status, citexTools.strings.detecting );

			postToAjax( {
				action: citexTools.detectCitationsAction,
				nonce: citexTools.nonce,
			} )
				.then( function ( result ) {
					if ( result && result.success ) {
						if ( input ) {
							input.value = result.data.questionListUrl;
						}
						citexTools.citationsListUrl = result.data.questionListUrl;
						setText( status, citexTools.strings.detected.replace( '%s', result.data.label ) );
						toggleScanButtons( 'citations', true );
					} else {
						setText( status, citexTools.strings.detectFailed + ' ' + ( ( result && result.data && result.data.message ) || '' ) );
					}
				} )
				.catch( function () {
					setText( status, citexTools.strings.detectFailed );
				} )
				.finally( function () {
					button.disabled = false;
				} );
		} );
	}

	function wireScanButtons() {
		var buttons = document.querySelectorAll( '.citex-scan-btn' );

		if ( ! buttons.length || ! window.citexTools || ! window.CitexScanner ) {
			return;
		}

		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				runScan( button.getAttribute( 'data-target' ) || 'reference' );
			} );
		} );
	}

	function runScan( target ) {
		var config = targetConfig( target );
		var status = document.querySelector( '.citex-scan-status[data-target="' + target + '"]' );
		var url = citexTools[ config.urlKey ];

		if ( ! url ) {
			setText( status, citexTools.strings[ config.noUrlString ] );
			return;
		}

		toggleScanButtons( target, false );

		CitexScanner.scan( url, function ( page, totalPages ) {
			setText( status, citexTools.strings.scanningPage.replace( '{page}', page ).replace( '{total}', totalPages ) );
		} )
			.then( function ( report ) {
				setText( status, citexTools.strings.scanComplete.replace( '{total}', report.total ) );
				return saveScan( report, target );
			} )
			.then( function () {
				window.setTimeout( function () {
					window.location.reload();
				}, 700 );
			} )
			.catch( function ( error ) {
				toggleScanButtons( target, !! url );
				setText( status, citexTools.strings.scanFailed + ' ' + error.message );
			} );
	}

	function saveScan( report, target ) {
		return postToAjax( {
			action: citexTools.saveScanAction,
			nonce: citexTools.nonce,
			target: target,
			scan: JSON.stringify( report ),
		} ).then( function ( result ) {
			if ( ! result || ! result.success ) {
				throw new Error( ( result && result.data && result.data.message ) || 'Could not save the scan.' );
			}
			return result;
		} );
	}

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

	function toggleScanButtons( target, enabled ) {
		document.querySelectorAll( '.citex-scan-btn[data-target="' + target + '"]' ).forEach( function ( btn ) {
			btn.disabled = ! enabled;
		} );
	}

	function setText( element, text ) {
		if ( element ) {
			element.textContent = text;
		}
	}

	/* ---- Validation (Validation page + Questions page) ---- */

	function wireValidation() {
		if ( ! window.citexTools || ! citexTools.validator || ! window.CitexValidator ) {
			return;
		}

		var questionsByKey = {};
		( citexTools.validator.questions || [] ).forEach( function ( q ) {
			questionsByKey[ q.key ] = q;
		} );

		wireBulkValidate( 'citex-validate-all', function () {
			return ( citexTools.validator.questions || [] ).filter( function ( q ) {
				return !! q.validatorId;
			} );
		} );

		wireBulkValidate( 'citex-validate-selected', function () {
			var selected = [];
			document.querySelectorAll( '.citex-row-select:checked' ).forEach( function ( checkbox ) {
				var question = questionsByKey[ checkbox.getAttribute( 'data-key' ) ];
				if ( question ) {
					selected.push( question );
				}
			} );
			return selected;
		} );

		document.addEventListener( 'click', function ( event ) {
			var validateBtn = event.target.closest( '.citex-validate-btn' );
			if ( validateBtn ) {
				runSingleValidate( validateBtn, questionsByKey[ validateBtn.getAttribute( 'data-key' ) ] );
				return;
			}

			var toggleBtn = event.target.closest( '.citex-toggle-details' );
			if ( toggleBtn ) {
				var detailRow = document.querySelector( '.citex-detail-row[data-key="' + cssEscape( toggleBtn.getAttribute( 'data-key' ) ) + '"]' );
				if ( detailRow ) {
					detailRow.hidden = ! detailRow.hidden;
				}
			}
		} );
	}

	function cssEscape( value ) {
		return window.CSS && CSS.escape ? CSS.escape( value ) : value.replace( /["\\]/g, '\\$&' );
	}

	function wireBulkValidate( buttonId, getQuestions ) {
		var button = document.getElementById( buttonId );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var questions = getQuestions();
			var status = document.querySelector( '.citex-validate-status' );

			if ( ! questions.length ) {
				setText( status, citexTools.validator.strings.noSelection );
				return;
			}

			toggleValidateButtons( false );

			CitexValidator.validateSequence( questions, function ( index, total, question ) {
				setText(
					status,
					citexTools.validator.strings.validating
						.replace( '{index}', index )
						.replace( '{total}', total )
						.replace( '{questionId}', question.questionId || question.title )
				);
			} )
				.then( function ( summary ) {
					setText(
						status,
						citexTools.validator.strings.validateComplete
							.replace( '{passed}', summary.passed )
							.replace( '{failed}', summary.failed )
							.replace( '{warnings}', summary.warnings )
							.replace( '{unsupported}', summary.unsupported )
					);
					window.setTimeout( function () {
						window.location.reload();
					}, 900 );
				} )
				.catch( function ( error ) {
					toggleValidateButtons( true );
					setText( status, citexTools.validator.strings.validateFailed + ' ' + error.message );
				} );
		} );
	}

	function runSingleValidate( button, question ) {
		if ( ! question ) {
			return;
		}

		var status = document.querySelector( '.citex-validate-status' );
		button.disabled = true;

		CitexValidator.validateOne( question )
			.then( function () {
				window.location.reload();
			} )
			.catch( function ( error ) {
				button.disabled = false;
				setText( status, citexTools.validator.strings.validateFailed + ' ' + error.message );
			} );
	}

	function toggleValidateButtons( enabled ) {
		document.querySelectorAll( '.citex-validate-btn, #citex-validate-all, #citex-validate-selected' ).forEach( function ( btn ) {
			btn.disabled = ! enabled;
		} );
	}

	/* ---- Auto-Generate (Generate Questions page) ---- */

	/**
	 * "I want about 100 questions total, I can only generate 20 at a
	 * time, so I want the site to generate 20, populate, and repeat until
	 * it reaches 100" — a real requested feature. Drives
	 * Citex_Generator::ajax_auto_generate_batch() in a loop, one small
	 * batch (capped the same 20 a manual "Generate & Publish" click uses)
	 * per request, using the SAME Referencing Style/Category/Difficulty/
	 * Question Focus/Question Type fields the manual button reads — never
	 * a second, separate set of controls to keep in sync.
	 *
	 * Every batch is its own independent AJAX request — never one long-
	 * running server-side loop — so closing the tab or reloading the page
	 * simply stops the JS loop; whatever was already published in earlier
	 * batches stays published, and Start can just be clicked again.
	 *
	 * The baseline (how many are already published for the selected
	 * Style + Category) comes from data-published-counts, server-rendered
	 * from the last scan (see Citex_Generator::render()'s own docblock on
	 * $combined_counts) — not a live re-query before/after every batch.
	 */
	function wireAutoGenerate() {
		var container = document.querySelector( '.citex-auto-generate' );
		if ( ! container || ! window.citexTools || ! citexTools.generator ) {
			return;
		}

		var publishedCounts = readPublishedCounts();

		var startButton = document.getElementById( 'citex-auto-generate-start' );
		var stopButton  = document.getElementById( 'citex-auto-generate-stop' );
		var targetInput = document.getElementById( 'citex_auto_generate_target' );
		var status      = document.getElementById( 'citex-auto-generate-status' );
		var log         = document.getElementById( 'citex-auto-generate-log' );

		var styleSelect       = document.getElementById( 'citex_referencing_style' );
		var categorySelect    = document.getElementById( 'citex_category' );
		var difficultySelect  = document.getElementById( 'citex_difficulty' );
		var groupSelect       = document.getElementById( 'citex_question_group' );
		var typeSelect        = document.getElementById( 'citex_question_type' );

		if ( ! startButton || ! stopButton || ! targetInput || ! styleSelect || ! categorySelect ) {
			return;
		}

		// Matches Citex_Generator::auto_generate_batch_body()'s own
		// server-side clamp — requesting more than this per batch would
		// just be clamped down anyway, silently.
		var BATCH_CAP = 20;
		// A hard ceiling on consecutive batches in one run, independent of
		// the target total, so a runaway loop (e.g. a mistyped target of
		// 100000) cannot hammer the server indefinitely — the admin can
		// simply click Start again to continue from wherever it stopped.
		var MAX_BATCHES = 50;

		var running          = false;
		var batchesRun       = 0;
		var createdTotal     = 0;
		var baseline         = 0;
		var target           = 0;
		var noProgressStreak = 0;

		function logLine( text ) {
			if ( ! log ) {
				return;
			}
			var item = document.createElement( 'li' );
			item.textContent = text;
			log.appendChild( item );
			log.scrollTop = log.scrollHeight;
		}

		function finish( message ) {
			running = false;
			startButton.disabled = false;
			stopButton.style.display = 'none';
			setText( status, message );
		}

		function runNextBatch() {
			if ( ! running ) {
				return;
			}
			var totalSoFar = baseline + createdTotal;
			var remaining  = target - totalSoFar;
			if ( remaining <= 0 ) {
				finish( citexTools.generator.strings.targetReached.replace( '{total}', totalSoFar ).replace( '{target}', target ) );
				return;
			}
			if ( batchesRun >= MAX_BATCHES ) {
				finish( citexTools.generator.strings.batchLimit.replace( '{batches}', MAX_BATCHES ).replace( '{total}', totalSoFar ).replace( '{target}', target ) );
				return;
			}

			batchesRun++;
			var batchQuantity = Math.min( BATCH_CAP, remaining );
			setText( status, 'Running batch ' + batchesRun + '… (' + totalSoFar + '/' + target + ')' );

			postToAjax( {
				action: citexTools.generator.autoGenerateAction,
				nonce: citexTools.generator.nonce,
				citex_referencing_style: styleSelect.value,
				citex_category: categorySelect.value,
				citex_difficulty: difficultySelect ? difficultySelect.value : 'hard',
				citex_question_group: groupSelect ? groupSelect.value : 'referencelist',
				citex_question_type: typeSelect ? typeSelect.value : 'mixed',
				citex_quantity: batchQuantity,
			} )
				.then( function ( result ) {
					if ( ! running ) {
						return;
					}
					if ( ! result || ! result.success ) {
						finish( citexTools.generator.strings.batchFailed.replace( '{batch}', batchesRun ).replace( '{message}', ( result && result.data && result.data.message ) || 'unknown error' ) );
						return;
					}
					var data    = result.data || {};
					var created = data.createdCount || 0;
					createdTotal += created;
					logLine(
						citexTools.generator.strings.batchDone
							.replace( '{batch}', batchesRun )
							.replace( '{created}', created )
							.replace( '{total}', baseline + createdTotal )
							.replace( '{target}', target )
					);

					noProgressStreak = created > 0 ? 0 : noProgressStreak + 1;
					if ( noProgressStreak >= 3 ) {
						finish( citexTools.generator.strings.noProgress );
						return;
					}

					window.setTimeout( runNextBatch, 500 );
				} )
				.catch( function ( error ) {
					finish( citexTools.generator.strings.batchFailed.replace( '{batch}', batchesRun ).replace( '{message}', error.message ) );
				} );
		}

		startButton.addEventListener( 'click', function () {
			var styleKey    = styleSelect.value;
			var categoryKey = categorySelect.value;
			baseline = ( publishedCounts[ styleKey ] && publishedCounts[ styleKey ][ categoryKey ] ) || 0;
			target   = parseInt( targetInput.value, 10 ) || 0;

			if ( target <= baseline ) {
				setText( status, citexTools.generator.strings.alreadyAtTarget );
				return;
			}

			running           = true;
			batchesRun        = 0;
			createdTotal      = 0;
			noProgressStreak  = 0;
			if ( log ) {
				log.innerHTML = '';
			}
			startButton.disabled     = true;
			stopButton.style.display = '';
			runNextBatch();
		} );

		stopButton.addEventListener( 'click', function () {
			finish( citexTools.generator.strings.stopped.replace( '{total}', baseline + createdTotal ).replace( '{target}', target ) );
		} );
	}
} )();
