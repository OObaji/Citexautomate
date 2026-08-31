/**
 * Citex Tools — admin JS.
 * Loaded only on Citex admin screens. No build step, no dependencies.
 * Wires the Question Bank "select all" checkbox, the Question List URL
 * settings form, and the Scan Question Bank flow — the actual scan
 * logic lives in admin/js/citex-scanner.js, loaded as a dependency.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		wireSelectAll();
		wireScannerSettings();
		wireScanButtons();
	} );

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
} )();
