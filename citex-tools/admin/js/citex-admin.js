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
		wireValidation();
		wireActionToast();
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

	function wireScannerSettings() {
		var form = document.getElementById( 'citex-scanner-settings-form' );

		if ( ! form || ! window.citexTools ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var input = document.getElementById( 'citex_question_list_url' );
			var status = document.getElementById( 'citex-settings-status' );
			var button = form.querySelector( 'button[type="submit"]' );

			if ( ! input ) {
				return;
			}

			button.disabled = true;
			setText( status, citexTools.strings.savingSettings );

			postToAjax( {
				action: citexTools.saveSettingsAction,
				nonce: citexTools.nonce,
				question_list_url: input.value,
			} )
				.then( function ( result ) {
					if ( result && result.success ) {
						citexTools.questionListUrl = result.data.questionListUrl;
						setText( status, citexTools.strings.settingsSaved );
						toggleScanButtons( !! citexTools.questionListUrl );
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
	}

	function wireScanButtons() {
		var buttons = document.querySelectorAll( '.citex-scan-btn' );

		if ( ! buttons.length || ! window.citexTools || ! window.CitexScanner ) {
			return;
		}

		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				runScan();
			} );
		} );
	}

	function runScan() {
		var status = document.querySelector( '.citex-scan-status' );

		if ( ! citexTools.questionListUrl ) {
			setText( status, citexTools.strings.noUrl );
			return;
		}

		toggleScanButtons( false );

		CitexScanner.scan( citexTools.questionListUrl, function ( page, totalPages ) {
			setText( status, citexTools.strings.scanningPage.replace( '{page}', page ).replace( '{total}', totalPages ) );
		} )
			.then( function ( report ) {
				setText( status, citexTools.strings.scanComplete.replace( '{total}', report.total ) );
				return saveScan( report );
			} )
			.then( function () {
				window.setTimeout( function () {
					window.location.reload();
				}, 700 );
			} )
			.catch( function ( error ) {
				toggleScanButtons( !! citexTools.questionListUrl );
				setText( status, citexTools.strings.scanFailed + ' ' + error.message );
			} );
	}

	function saveScan( report ) {
		return postToAjax( {
			action: citexTools.saveScanAction,
			nonce: citexTools.nonce,
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

	function toggleScanButtons( enabled ) {
		document.querySelectorAll( '.citex-scan-btn' ).forEach( function ( btn ) {
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
