/**
 * Citex Tools — live-site DragDrop reconstruction adapter.
 *
 * The live WordPress data proved that Fixed Text is stored in two related
 * encodings:
 *
 * 1) Marker encoding (already supported by v0.5.0):
 *      |, | (|) Title. |: Publisher.
 *
 * 2) Empty-segment encoding (observed on real BK02):
 *      |, || (||) ||. Oxford: Oxford University Press.
 *
 * Splitting encoding (2) on "|" produces four empty segments; those empty
 * segments are the four draggable slots. The seven pipe characters are
 * delimiters, not seven draggable placeholders. This adapter preserves the
 * original validator and field extraction but replaces reconstruction with a
 * dual-mode implementation grounded in the live BK02 data.
 *
 * Read-only: it only GETs the edit screen and POSTs the validation result to
 * Citex's own admin-ajax result store. It never updates a WordPress question.
 */
( function () {
	'use strict';

	var base = window.CitexValidator;
	if ( ! base ) {
		return;
	}

	function structuralError( code, fixedText, questionParts, reconstruction ) {
		if ( 'FIXED_TEXT_MISSING' === code ) {
			return { code: code, message: 'Fixed Text field is empty or could not be found.' };
		}
		if ( 'QUESTION_PARTS_MISSING' === code ) {
			return { code: code, message: 'Question Parts field is empty or could not be found.' };
		}
		return {
			code: 'PLACEHOLDER_COUNT_MISMATCH',
			message: 'Could not map the Fixed Text drag slots to the Question Parts. Detected ' +
				reconstruction.placeholderCount + ' draggable slot(s), ' + reconstruction.rawPipeCount +
				' pipe delimiter(s), and ' + questionParts.length + ' Question Part(s).',
		};
	}

	/**
	 * Reconstruct a DragDrop reference using either of the two confirmed
	 * Fixed Text encodings.
	 *
	 * Empty-segment mode is tried first because it is what the live site uses:
	 *   '|, || (||) ||. Oxford: Oxford University Press.'
	 * split('|') => ['', ', ', '', ' (', '', ') ', '', '. Oxford: ...']
	 * The four empty segments are replaced by the four Question Parts.
	 *
	 * If that pattern is not present, fall back to the older marker mode where
	 * every single pipe is itself one draggable placeholder.
	 */
	function reconstructReference( fixedText, questionParts ) {
		if ( ! fixedText ) {
			return { reference: null, placeholderCount: 0, rawPipeCount: 0, mode: null, error: 'FIXED_TEXT_MISSING' };
		}
		if ( ! questionParts || ! questionParts.length ) {
			return {
				reference: null,
				placeholderCount: 0,
				rawPipeCount: ( fixedText.match( /\|/g ) || [] ).length,
				mode: null,
				error: 'QUESTION_PARTS_MISSING',
			};
		}

		var segments = fixedText.split( '|' );
		var rawPipeCount = segments.length - 1;
		var slotIndexes = [];

		segments.forEach( function ( segment, index ) {
			if ( '' === segment.trim() ) {
				slotIndexes.push( index );
			}
		} );

		// Live Citex encoding: empty segments between pipe delimiters are slots.
		if ( slotIndexes.length === questionParts.length ) {
			var slot = 0;
			var liveReference = segments.map( function ( segment ) {
				if ( '' === segment.trim() && slot < questionParts.length ) {
					return questionParts[ slot++ ];
				}
				return segment;
			} ).join( '' );

			return {
				reference: liveReference,
				placeholderCount: slotIndexes.length,
				rawPipeCount: rawPipeCount,
				mode: 'empty-segment-slots',
				error: null,
			};
		}

		// Original v0.5 marker encoding: each pipe itself is one slot.
		if ( rawPipeCount === questionParts.length ) {
			var reference = segments[ 0 ];
			for ( var i = 0; i < questionParts.length; i++ ) {
				reference += questionParts[ i ] + segments[ i + 1 ];
			}
			return {
				reference: reference,
				placeholderCount: rawPipeCount,
				rawPipeCount: rawPipeCount,
				mode: 'pipe-markers',
				error: null,
			};
		}

		return {
			reference: null,
			placeholderCount: slotIndexes.length,
			rawPipeCount: rawPipeCount,
			mode: null,
			error: 'PLACEHOLDER_COUNT_MISMATCH',
		};
	}

	async function validateHarvardBookDragdrop( question, doc ) {
		var extraction = base.extractQuestionFields( doc );
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
				errors: [ structuralError( reconstruction.error, extraction.fixedText, extraction.questionParts, reconstruction ) ],
				warnings: [],
				diagnostics: diagnostics,
			};
		}

		var errors = [];
		var checks = [
			base.checkYearTrailingPeriod,
			base.checkMissingFinalPeriod,
			base.checkBookFormat,
		];
		checks.forEach( function ( check ) {
			var issue = check( reconstruction.reference );
			if ( issue ) {
				errors.push( issue );
			}
		} );

		return {
			status: errors.length ? 'failed' : 'passed',
			reason: errors.length ? ( errors.length + ' issue(s) found.' ) : 'All Harvard Book DragDrop checks passed.',
			reconstructedReference: reconstruction.reference,
			errors: errors,
			warnings: [],
			diagnostics: diagnostics,
		};
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

	async function runValidatorFor( question ) {
		var validatorId = base.resolveValidatorId( question );
		if ( validatorId !== base.ROUTES.id ) {
			return base.runValidatorFor( question );
		}

		var doc;
		try {
			doc = await base.fetchQuestionDocument( question.editUrl );
		} catch ( error ) {
			return finalizeResult( question, validatorId, {
				status: 'unsupported',
				reason: 'Could not load the question for validation: ' + error.message,
				errors: [],
				warnings: [],
				diagnostics: { ruleEngineExecuted: false },
			} );
		}

		return finalizeResult( question, validatorId, await validateHarvardBookDragdrop( question, doc ) );
	}

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

	async function validateOne( question ) {
		return saveResult( question.key, await runValidatorFor( question ) );
	}

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
			if ( 'warning' === result.status ) {
				summary.warnings++;
			} else if ( Object.prototype.hasOwnProperty.call( summary, result.status ) ) {
				summary[ result.status ]++;
			}
		}
		return summary;
	}

	window.CitexValidator = Object.assign( {}, base, {
		reconstructReference: reconstructReference,
		validateHarvardBookDragdrop: validateHarvardBookDragdrop,
		runValidatorFor: runValidatorFor,
		validateOne: validateOne,
		validateSequence: validateSequence,
	} );
} )();
