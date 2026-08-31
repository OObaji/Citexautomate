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
 * STATUS: Harvard/ReferenceList/Book/DragDrop rule engine v1 IMPLEMENTED,
 * ported from recovered details of the original QA Checker v0.3 (not
 * invented — see includes/validators/class-citex-harvard-book-dragdrop-validator.php
 * for exactly what was ported). Two genuinely distinct kinds of logic below:
 *
 *  - reconstructReference() and the three rule-check functions are pure
 *    string logic with no DOM dependency, given exact algorithmic specs
 *    (including a worked example used to verify reconstructReference()) —
 *    these are implemented with full confidence and unit-tested in
 *    tests/harvard-book-dragdrop-rules.test.js.
 *  - extractQuestionFields() and its helpers read the three confirmed ACF
 *    field keys (FIELD_MAP) out of the fetched edit-screen HTML using ACF's
 *    standard, version-spanning DOM convention (`.acf-field[data-key]`,
 *    `.acf-row` for repeaters) — this is the one part that could not be
 *    confirmed against a real page (no live network access from this
 *    environment) and needs verification against the Details diagnostics
 *    on an actual site before being trusted.
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
	 * ACF field keys, confirmed from the original QA Checker v0.3 (recovered
	 * by the user, not guessed). Mirrors Citex_Harvard_Book_Dragdrop_Validator::FIELD_MAP
	 * in PHP; keep the two in sync.
	 */
	var FIELD_MAP = {
		fixedText: 'field_59c2476bc859f',
		questionParts: 'field_59c2476bc81b7',
		confusingWords: 'field_59c2476bc83ab',
	};

	/* ---- DOM extraction helpers (ACF's standard field markup) ---- */

	/**
	 * Depth-first search under `root` for every element matching `predicate`.
	 * Deliberately not querySelector: works identically against a real
	 * parsed Document and against a hand-built test fixture that only
	 * implements `children`/`tagName`/`getAttribute`/`className`, so the
	 * exact same traversal code is what's unit-tested.
	 */
	function findAll( root, predicate ) {
		var results = [];

		function walk( node ) {
			if ( ! node ) {
				return;
			}
			var children = node.children || [];
			for ( var i = 0; i < children.length; i++ ) {
				var child = children[ i ];
				if ( predicate( child ) ) {
					results.push( child );
				}
				walk( child );
			}
		}

		walk( root );
		return results;
	}

	function findFirst( root, predicate ) {
		var all = findAll( root, predicate );
		return all.length ? all[ 0 ] : null;
	}

	function hasClass( el, className ) {
		if ( ! el ) {
			return false;
		}
		if ( el.classList && 'function' === typeof el.classList.contains ) {
			return el.classList.contains( className );
		}
		var cls = el.className || ( el.getAttribute && el.getAttribute( 'class' ) ) || '';
		return ( ' ' + cls + ' ' ).indexOf( ' ' + className + ' ' ) !== -1;
	}

	function isTextInput( el ) {
		if ( ! el || ! el.tagName ) {
			return false;
		}
		var tag = el.tagName.toUpperCase();
		if ( 'TEXTAREA' === tag ) {
			return true;
		}
		if ( 'INPUT' === tag ) {
			var type = el.getAttribute ? el.getAttribute( 'type' ) : null;
			return ! type || 'text' === type;
		}
		return false;
	}

	/**
	 * Finds the ACF field container for a given field key. ACF wraps every
	 * field, of any type, in `.acf-field[data-key="<key>"]` on the classic
	 * post-edit screen — this convention is unchanged across ACF versions,
	 * unlike the inner markup (which varies by field type), so it's the one
	 * safe fixed point to search from.
	 */
	function getAcfFieldContainer( doc, fieldKey ) {
		var root = doc.body || doc;
		return findFirst( root, function ( el ) {
			return hasClass( el, 'acf-field' ) && el.getAttribute && el.getAttribute( 'data-key' ) === fieldKey;
		} );
	}

	/**
	 * Extracts a single text value from a field container: prefers an
	 * actual input/textarea's value (a live, editable field), falls back to
	 * the container's visible text (e.g. a read-only/message field type).
	 */
	function extractFieldValue( container ) {
		if ( ! container ) {
			return { value: '', strategy: 'not-found' };
		}

		var input = findFirst( container, isTextInput );
		if ( input ) {
			return { value: ( input.value || '' ).trim(), strategy: 'input-value' };
		}

		var text = ( container.textContent || '' ).trim();
		return { value: text, strategy: text ? 'text-content-fallback' : 'empty' };
	}

	/**
	 * Extracts an ordered list of text values from a field container.
	 * Question Parts / Confusing Words are ordered lists, which ACF
	 * normally stores as a Repeater field (`.acf-row` per row — excluding
	 * ACF's hidden `.acf-row.acf-clone` template row). Falls back to
	 * treating a single text/textarea field as one value per line, in case
	 * the real field turns out not to be a repeater.
	 */
	function extractFieldList( container ) {
		if ( ! container ) {
			return { values: [], strategy: 'not-found' };
		}

		var rows = findAll( container, function ( el ) {
			return hasClass( el, 'acf-row' ) && ! hasClass( el, 'acf-clone' );
		} );

		if ( rows.length ) {
			var values = rows
				.map( function ( row ) { return extractFieldValue( row ).value; } )
				.filter( function ( v ) { return '' !== v; } );
			return { values: values, strategy: 'repeater-rows' };
		}

		var single = extractFieldValue( container );
		if ( single.value ) {
			var lines = single.value
				.split( /\r?\n/ )
				.map( function ( l ) { return l.trim(); } )
				.filter( Boolean );
			return { values: lines, strategy: 'newline-split-fallback' };
		}

		return { values: [], strategy: 'empty' };
	}

	/**
	 * Reads all three FIELD_MAP fields out of a fetched edit-screen
	 * document. Every outcome (found/not-found, which extraction strategy
	 * fired) is reported, not just the final values — this is what the
	 * Validation page's Details diagnostics show, so a wrong assumption
	 * about this site's real markup is immediately visible rather than
	 * silently producing an empty or wrong result.
	 */
	function extractQuestionFields( doc ) {
		var fixedTextContainer = getAcfFieldContainer( doc, FIELD_MAP.fixedText );
		var questionPartsContainer = getAcfFieldContainer( doc, FIELD_MAP.questionParts );
		var confusingWordsContainer = getAcfFieldContainer( doc, FIELD_MAP.confusingWords );

		var fixedTextResult = extractFieldValue( fixedTextContainer );
		var questionPartsResult = extractFieldList( questionPartsContainer );
		var confusingWordsResult = extractFieldList( confusingWordsContainer );

		return {
			fixedText: fixedTextResult.value,
			fixedTextFound: !! fixedTextContainer,
			fixedTextStrategy: fixedTextResult.strategy,
			questionParts: questionPartsResult.values,
			questionPartsFound: !! questionPartsContainer,
			questionPartsStrategy: questionPartsResult.strategy,
			confusingWords: confusingWordsResult.values,
			confusingWordsFound: !! confusingWordsContainer,
			confusingWordsStrategy: confusingWordsResult.strategy,
		};
	}

	/* ---- Reconstruction (pure — no DOM) ---- */

	/**
	 * Reconstructs the completed reference from Fixed Text and Question
	 * Parts: Fixed Text's "|" characters mark draggable positions; each is
	 * replaced, in order, by the next Question Part. Fixed text itself is
	 * never checked against Question Parts — only the *count* of
	 * placeholders is, which is exactly what prevents the DragDrop
	 * false-positive this brief calls out (fixed text like "Social Media
	 * Studies." is carried through untouched, never required to appear in
	 * Question Parts).
	 *
	 * Verified against the brief's worked example:
	 *   fixedText: '|, | (|) Social Media Studies. |: Sage'
	 *   questionParts: ['Smith', 'J.', '2022', 'London']
	 *   -> 'Smith, J. (2022) Social Media Studies. London: Sage'
	 *
	 * @param {string} fixedText
	 * @param {string[]} questionParts
	 * @return {{reference: (string|null), placeholderCount: number, error: (string|null)}}
	 */
	function reconstructReference( fixedText, questionParts ) {
		if ( ! fixedText ) {
			return { reference: null, placeholderCount: 0, error: 'FIXED_TEXT_MISSING' };
		}

		var segments = fixedText.split( '|' );
		var placeholderCount = segments.length - 1;

		if ( ! questionParts || ! questionParts.length ) {
			return { reference: null, placeholderCount: placeholderCount, error: 'QUESTION_PARTS_MISSING' };
		}

		if ( placeholderCount !== questionParts.length ) {
			return { reference: null, placeholderCount: placeholderCount, error: 'PLACEHOLDER_COUNT_MISMATCH' };
		}

		var reference = segments[ 0 ];
		for ( var i = 0; i < questionParts.length; i++ ) {
			reference += questionParts[ i ] + segments[ i + 1 ];
		}

		return { reference: reference, placeholderCount: placeholderCount, error: null };
	}

	var STRUCTURAL_ERROR_MESSAGES = {
		FIXED_TEXT_MISSING: function () {
			return { code: 'FIXED_TEXT_MISSING', message: 'Fixed Text field is empty or could not be found.' };
		},
		QUESTION_PARTS_MISSING: function () {
			return { code: 'QUESTION_PARTS_MISSING', message: 'Question Parts field is empty or could not be found.' };
		},
		PLACEHOLDER_COUNT_MISMATCH: function ( extraction, reconstruction ) {
			return {
				code: 'PLACEHOLDER_COUNT_MISMATCH',
				message: 'The number of "|" placeholders in Fixed Text (' + reconstruction.placeholderCount +
					') does not match the number of Question Parts (' + extraction.questionParts.length + ').',
			};
		},
	};

	/* ---- Content checks (pure — operate on the reconstructed reference) ---- */

	/**
	 * "(2019)." is wrong; "(2019)" is right — a full stop must not
	 * immediately follow the closing parenthesis of the publication year.
	 */
	function checkYearTrailingPeriod( reference ) {
		if ( /\(\d{4}\)\./.test( reference ) ) {
			return { code: 'YEAR_TRAILING_PERIOD', message: 'Unwanted full stop after publication year.' };
		}
		return null;
	}

	/** The completed reference must terminate with a full stop. */
	function checkMissingFinalPeriod( reference ) {
		if ( ! /\.\s*$/.test( reference ) ) {
			return { code: 'MISSING_FINAL_PERIOD', message: 'Missing final full stop.' };
		}
		return null;
	}

	/**
	 * Liverpool Hope Book structure: "Author (Year) Title. Place: Publisher."
	 * Checked as two structural landmarks in the right order — a
	 * parenthesized year, followed later by a "Place: Publisher" colon
	 * separator — deliberately loose on period placement, since that's
	 * already covered precisely by the two checks above; this only catches
	 * a citation that doesn't have the right shape at all (e.g. no year, or
	 * no place/publisher separator).
	 */
	function checkBookFormat( reference ) {
		var yearMatch = /\(\d{4}\)/.exec( reference );
		if ( ! yearMatch ) {
			return { code: 'BOOK_FORMAT_MISMATCH', message: 'Citation does not match the Liverpool Hope Book format (no publication year found in parentheses).' };
		}

		var afterYear = reference.slice( yearMatch.index + yearMatch[ 0 ].length );
		if ( ! /:\s*\S/.test( afterYear ) ) {
			return { code: 'BOOK_FORMAT_MISMATCH', message: 'Citation does not match the Liverpool Hope Book format (no "Place: Publisher" separator found after the year).' };
		}

		return null;
	}

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
	 * Harvard / ReferenceList / Book / DragDrop validator (v1, ported from
	 * recovered QA Checker v0.3 details — see the module docblock).
	 *
	 * 1. Extract Fixed Text / Question Parts / Confusing Words from the
	 *    fetched edit screen.
	 * 2. Reconstruct the reference (Fixed Text's "|" positions filled in
	 *    order by Question Parts — fixed text is never itself checked
	 *    against Question Parts, only the placeholder *count* is, which is
	 *    exactly the DragDrop false-positive fix this brief calls out).
	 * 3. If reconstruction fails structurally, report that and stop.
	 * 4. Otherwise run the three known content checks against the
	 *    reconstructed reference and report passed/failed accordingly.
	 *
	 * @param {object} question Localized question record.
	 * @param {Document} doc Parsed WordPress edit-screen document.
	 * @return {Promise<object>}
	 */
	async function validateHarvardBookDragdrop( question, doc ) {
		var extraction = extractQuestionFields( doc );
		var reconstruction = reconstructReference( extraction.fixedText, extraction.questionParts );

		var diagnostics = {
			fixedText: extraction.fixedText,
			fixedTextFound: extraction.fixedTextFound,
			fixedTextStrategy: extraction.fixedTextStrategy,
			questionParts: extraction.questionParts,
			questionPartsFound: extraction.questionPartsFound,
			questionPartsStrategy: extraction.questionPartsStrategy,
			confusingWords: extraction.confusingWords,
			confusingWordsFound: extraction.confusingWordsFound,
			confusingWordsStrategy: extraction.confusingWordsStrategy,
			placeholderCount: reconstruction.placeholderCount,
			ruleEngineExecuted: true,
		};

		if ( reconstruction.error ) {
			return {
				status: 'failed',
				reason: 'Could not reconstruct the reference — see errors.',
				reconstructedReference: null,
				errors: [ STRUCTURAL_ERROR_MESSAGES[ reconstruction.error ]( extraction, reconstruction ) ],
				warnings: [],
				diagnostics: diagnostics,
			};
		}

		var errors = [];
		var yearError = checkYearTrailingPeriod( reconstruction.reference );
		if ( yearError ) {
			errors.push( yearError );
		}
		var finalPeriodError = checkMissingFinalPeriod( reconstruction.reference );
		if ( finalPeriodError ) {
			errors.push( finalPeriodError );
		}
		var formatError = checkBookFormat( reconstruction.reference );
		if ( formatError ) {
			errors.push( formatError );
		}

		return {
			status: errors.length ? 'failed' : 'passed',
			reason: errors.length ? ( errors.length + ' issue(s) found.' ) : 'All Harvard Book DragDrop checks passed.',
			reconstructedReference: reconstruction.reference,
			errors: errors,
			warnings: [],
			diagnostics: diagnostics,
		};
	}

	var VALIDATORS = {};
	VALIDATORS[ ROUTES.id ] = validateHarvardBookDragdrop;

	function unsupportedResult( question, reason ) {
		return { status: 'unsupported', reason: reason, errors: [], warnings: [], diagnostics: { ruleEngineExecuted: false } };
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
			reconstructedReference: outcome.reconstructedReference || null,
			errors: outcome.errors || [],
			warnings: outcome.warnings || [],
			diagnostics: outcome.diagnostics || { ruleEngineExecuted: false },
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
		// Exposed for testing (tests/harvard-book-dragdrop-rules.test.js) —
		// pure functions and DOM-extraction helpers with no side effects.
		reconstructReference: reconstructReference,
		checkYearTrailingPeriod: checkYearTrailingPeriod,
		checkMissingFinalPeriod: checkMissingFinalPeriod,
		checkBookFormat: checkBookFormat,
		extractQuestionFields: extractQuestionFields,
		getAcfFieldContainer: getAcfFieldContainer,
		validateHarvardBookDragdrop: validateHarvardBookDragdrop,
	};
} )();
