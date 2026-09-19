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
	} );

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
} )();
