/**
 * Citex Tools — question validator.
 *
 * Architecture: WordPress Questions -> Citex Scanner -> Question Index ->
 * Citex Validator -> Validation Results. This module is the "Citex
 * Validator" step: given an indexed question (from citexTools.validator.questions,
 * localized from the Phase 2 scan), it routes to a validator id, fetches
 * that question's WordPress edit screen (same-origin, authenticated as the
 * logged-in admin — same approach the Phase 2 scanner and the original
 * DevTools QA Checker use), runs the routed validator, and POSTs the
 * structured result back to be persisted. Only ever issues GET requests
 * against WordPress; the only POST is to Citex's own admin-ajax endpoint
 * that stores the result. No question post is ever written to.
 *
 * ============================================================================
 * STATUS: routing + fetch/save pipeline implemented; Harvard/ReferenceList/
 * Book/DragDrop rule engine NOT YET PORTED from the existing QA Checker
 * prototype (its source was not supplied — see the docblock in
 * includes/validators/class-citex-harvard-book-dragdrop-validator.php).
 * validateHarvardBookDragdrop() below therefore always returns
 * status: 'unsupported' with an honest reason, never a fabricated pass or
 * fail. FIELD_MAP mirrors the PHP class of the same name and is the single
 * place the real ACF/form selectors go once known.
 * ============================================================================
 */
window.CitexValidator = ( function () {
	'use strict';

	/**
	 * Routing metadata this validator claims — must match a scanned
	 * question's parsed title exactly (see citex-scanner.js). Mirrors
	 * Citex_Harvard_Book_Dragdrop_Validator::ROUTES in PHP; keep in sync.
	 */
	var ROUTES = {
		id: 'harvard-reference-list-book-dragdrop',
		source: 'Harvard',
		group: 'ReferenceList',
		category: 'Book',
		type: 'DragDrop',
	};

	/**
	 * ACF/question edit-form field selectors. PENDING — see the module
	 * docblock and includes/validators/class-citex-harvard-book-dragdrop-validator.php.
	 * Left empty rather than guessed.
	 */
	var FIELD_MAP = {};

	/**
	 * Normalizes a value for the routing comparison only: collapses any
	 * whitespace run (including a non-breaking space, which a scraped
	 * admin-list page can leave behind) to a single space, trims, and
	 * lowercases. Never touches what's displayed elsewhere — only makes
	 * this comparison tolerant of a whitespace/case difference that would
	 * otherwise silently route a real, supported question to "unsupported".
	 * Mirrors Citex_Validator::normalize_route_value() in PHP.
	 */
	function normalizeRouteValue( value ) {
		return ( value || '' )
			.replace( / /g, ' ' )
			.replace( /\s+/g, ' ' )
			.trim()
			.toLowerCase();
	}

	/**
	 * Routes a question to a validator id, or null (unsupported). The
	 * single place new combinations (Book MCQ, EditedBook, Website,
	 * Journal, JournalArticle, ...) get added later.
	 */
	function resolveValidatorId( question ) {
		if (
			normalizeRouteValue( question.source ) === normalizeRouteValue( ROUTES.source ) &&
			normalizeRouteValue( question.group ) === normalizeRouteValue( ROUTES.group ) &&
			normalizeRouteValue( question.category ) === normalizeRouteValue( ROUTES.category ) &&
			normalizeRouteValue( question.type ) === normalizeRouteValue( ROUTES.type )
		) {
			return ROUTES.id;
		}

		return null;
	}

	/**
	 * Fetches a question's WordPress edit screen (read-only GET, same
	 * origin, authenticated via the browser's existing session cookie —
	 * never submits the form) and returns it as a parsed Document, ready
	 * for a validator to read fields out of once FIELD_MAP is populated.
	 */
	async function fetchQuestionDocument( editUrl ) {
		if ( ! editUrl ) {
			throw new Error( 'No WordPress edit URL for this question.' );
		}

		var response = await fetch( editUrl, { credentials: 'same-origin' } );

		if ( ! response.ok ) {
			throw new Error( 'Could not load the question (HTTP ' + response.status + ').' );
		}

		var html = await response.text();
		return new DOMParser().parseFromString( html, 'text/html' );
	}

	/**
	 * Harvard / ReferenceList / Book / DragDrop validator.
	 *
	 * NOT YET IMPLEMENTED. The existing QA Checker prototype this must
	 * reuse — its ACF field reads, its expected Liverpool Hope Harvard
	 * Book reference reconstruction, its scenario/question-parts/
	 * distractor checks, its punctuation checks, and its fix distinguishing
	 * fixed scenario text from draggable Question Parts — was not supplied.
	 * Inventing any of that here would violate the Phase 3 brief's explicit
	 * "do not invent new rules" / "do not guess field values" requirements
	 * and risks reporting false pass/fail against real academic content.
	 * So this always returns 'unsupported' with a clear reason instead.
	 *
	 * @param {object} question Localized question record.
	 * @param {Document} doc Parsed WordPress edit-screen document (fetched, unused for now).
	 * @return {Promise<{status: string, reason: string, errors: object[], warnings: object[]}>}
	 */
	// eslint-disable-next-line no-unused-vars
	async function validateHarvardBookDragdrop( question, doc ) {
		return {
			status: 'unsupported',
			reason:
				'Harvard / ReferenceList / Book / DragDrop is routed but its rule engine has not been ' +
				'ported from the existing QA Checker yet (FIELD_MAP is still empty — see this file and ' +
				'includes/validators/class-citex-harvard-book-dragdrop-validator.php).',
			errors: [],
			warnings: [],
		};
	}

	var VALIDATORS = {};
	VALIDATORS[ ROUTES.id ] = validateHarvardBookDragdrop;

	function unsupportedResult( question, reason ) {
		return { status: 'unsupported', reason: reason, errors: [], warnings: [] };
	}

	/**
	 * Runs the routed validator (or produces an honest "unsupported"
	 * outcome) for one question, without saving it.
	 */
	async function runValidatorFor( question ) {
		var validatorId = resolveValidatorId( question );

		if ( ! validatorId ) {
			return finalizeResult( question, null, unsupportedResult(
				question,
				'Not routed — no validator matches {source: "' + question.source + '", group: "' + question.group +
					'", category: "' + question.category + '", type: "' + question.type + '"}. Expected {source: "' +
					ROUTES.source + '", group: "' + ROUTES.group + '", category: "' + ROUTES.category + '", type: "' + ROUTES.type + '"}.'
			) );
		}

		var validatorFn = VALIDATORS[ validatorId ];

		if ( 'function' !== typeof validatorFn ) {
			// Distinct from "not routed": routing succeeded (validatorId is
			// set) but no function is registered for that id. This is a
			// registry/plugin bug, not an unimplemented question format —
			// keep it diagnosably separate rather than folding it into the
			// same generic "no validator implemented" message above.
			return finalizeResult( question, validatorId, unsupportedResult(
				question,
				'Routed to "' + validatorId + '" but no validator function is registered for that id in VALIDATORS — registry mismatch (plugin bug, not an unimplemented format).'
			) );
		}

		var doc;
		try {
			doc = await fetchQuestionDocument( question.editUrl );
		} catch ( error ) {
			return finalizeResult( question, validatorId, unsupportedResult( question, 'Routed to "' + validatorId + '"; could not load the question for validation: ' + error.message ) );
		}

		var outcome = await validatorFn( question, doc );
		return finalizeResult( question, validatorId, outcome );
	}

	function finalizeResult( question, validatorId, outcome ) {
		return {
			questionId: question.questionId || '',
			wpPostId: question.wpPostId || null,
			title: question.title || '',
			validator: validatorId || '',
			status: outcome.status,
			reason: outcome.reason || '',
			errors: outcome.errors || [],
			warnings: outcome.warnings || [],
			validatedAt: new Date().toISOString(),
		};
	}

	/**
	 * Persists one validation result via Citex's admin-ajax endpoint.
	 * The server recomputes the canonical validator id itself rather than
	 * trusting this payload, so this is not a place a caller can force a
	 * false pass/fail into storage.
	 */
	async function saveResult( key, result ) {
		var body = new URLSearchParams();
		body.set( 'action', citexTools.validator.saveResultAction );
		body.set( 'nonce', citexTools.validator.nonce );
		body.set( 'key', key );
		body.set( 'result', JSON.stringify( result ) );

		var response = await fetch( citexTools.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} );

		var data = await response.json();

		if ( ! data || ! data.success ) {
			throw new Error( ( data && data.data && data.data.message ) || 'Could not save the validation result.' );
		}

		return data.data.result;
	}

	/**
	 * Validates one question and persists the result.
	 */
	async function validateOne( question ) {
		var result = await runValidatorFor( question );
		return saveResult( question.key, result );
	}

	/**
	 * Validates a list of questions sequentially — one request in flight
	 * at a time, never in parallel — calling onProgress(index, total,
	 * question) before each so the UI can show "Validating X of Y...".
	 * Returns a Passed/Failed/Warnings/Unsupported summary.
	 */
	async function validateSequence( questions, onProgress ) {
		var summary = { passed: 0, failed: 0, warnings: 0, unsupported: 0 };

		for ( var i = 0; i < questions.length; i++ ) {
			var question = questions[ i ];

			if ( onProgress ) {
				onProgress( i + 1, questions.length, question );
			}

			var result;
			try {
				result = await validateOne( question );
			} catch ( error ) {
				result = { status: 'unsupported' };
			}

			if ( Object.prototype.hasOwnProperty.call( summary, result.status ) ) {
				summary[ result.status ]++;
			} else if ( 'passed' === result.status ) {
				summary.passed++;
			}
		}

		return summary;
	}

	return {
		resolveValidatorId: resolveValidatorId,
		fetchQuestionDocument: fetchQuestionDocument,
		runValidatorFor: runValidatorFor,
		validateOne: validateOne,
		validateSequence: validateSequence,
		FIELD_MAP: FIELD_MAP,
		ROUTES: ROUTES,
	};
} )();
