/**
 * Citex Tools — admin JS.
 * Loaded only on Citex admin screens. No build step, no dependencies.
 * Currently just wires up the "select all" checkbox on the Question
 * Bank table; future modules (validation, population) can hook into
 * their own page markup here without touching this file's structure.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var selectAll = document.getElementById( 'citex-select-all' );

		if ( ! selectAll ) {
			return;
		}

		selectAll.addEventListener( 'change', function () {
			var rows = document.querySelectorAll( '.citex-row-select' );
			rows.forEach( function ( checkbox ) {
				checkbox.checked = selectAll.checked;
			} );
		} );
	} );
} )();
