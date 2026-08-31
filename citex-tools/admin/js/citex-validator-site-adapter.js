/**
 * Citex Tools — live-site DragDrop reconstruction adapter.
 *
 * Confirmed Citex Fixed Text placeholder grammar:
 *   - `|`  = one draggable placeholder ONLY when it is at the beginning
 *            or the end of Fixed Text.
 *   - `||` = one draggable placeholder at any internal position.
 *
 * Example from live BK02:
 *   Fixed Text:     |, || (||) ||. Oxford: Oxford University Press.
 *   Question Parts: Lopez / M. / 2019 / Global Health
 *   Reconstructed:  Lopez, M. (2019) Global Health. Oxford: Oxford University Press.
 *
 * Read-only: this adapter only GETs the WordPress edit screen and POSTs the
 * validation result to Citex's own result store. It never updates a question.
 */
( function () {
	'use strict';

	var base = window.CitexValidator;
	if ( ! base ) {
		return;
	}

	function structuralError( code, questionParts, reconstruction ) {
		if ( 'FIXED_TEXT_MISSING' === code ) {
			return { code: code, message: 'Fixed Text field is empty or could not be found.' };
		}
		if ( 'QUESTION_PARTS_MISSING' === code ) {
			return { code: code, message: 'Question Parts field is empty or could not be found.' };
		}
		if ( 'MALFORMED_PLACEHOLDER_ENCODING' === code ) {
			return {
				code: code,
				message: 'Fixed Text contains a single "|" in an internal position. Internal draggable placeholders must use "||"; a single "|" is only valid at the beginning or end.',
			};
		}
		return {
			code: 'PLACEHOLDER_COUNT_MISMATCH',
			message: 'The number of draggable placeholders in Fixed Text (' + reconstruction.placeholderCount +
				') does not match the number of Question Parts (' + questionParts.length + ').',
		};
	}

	/**
	 * Find placeholders using Citex's confirmed positional grammar.
	 *
	 * A placeholder token is:
	 *   - one pipe at string position 0;
	 *   - one pipe at the final string position; or
	 *   - two consecutive pipes anywhere internally.
	 *
	 * A lone internal pipe is malformed and is deliberately not counted as a
	 * draggable slot. This prevents the old bug where seven raw pipe characters
	 * in BK02 were incorrectly treated as seven Question Parts.
	 */
	function findPlaceholderTokens( fixedText ) {
		var tokens = [];
		var malformed = [];
		var i = 0;

		while ( i < fixedText.length ) {
			if ( 0 === i && '|' === fixedText.charAt( i ) ) {
				tokens.push( { start: i, length: 1, kind: 'beginning' } );
				i += 1;
				continue;
			}

			if ( i === fixedText.length - 1 && '|' === fixedText.charAt( i ) ) {
				tokens.push( { start: i, length: 1, kind: 'end' } );
				i += 1;
				continue;
			}

			if ( '||' === fixedText.slice( i, i + 2 ) ) {
				tokens.push( { start: i, length: 2, kind: 'internal' } );
				i += 2;
				continue;
			}

			if ( '|' === fixedText.charAt( i ) ) {
				malformed.push( i );
			}
			i += 1;
		}

		return { tokens: tokens, malformed: malformed };
	}

	function reconstructReference( fixedText, questionParts ) {
		if ( ! fixedText ) {
			return { reference: null, placeholderCount: 0, error: 'FIXED_TEXT_MISSING' };
		}
		if ( ! questionParts || ! questionParts.length ) {
			return { reference: null, placeholderCount: 0, error: 'QUESTION_PARTS_MISSING' };
		}

		var parsed = findPlaceholderTokens( fixedText );
		var tokens = parsed.tokens;

		if ( parsed.malformed.length ) {
			return {
				reference: null,
				placeholderCount: tokens.length,
				error: 'MALFORMED_PLACEHOLDER_ENCODING',
			};
		}

		if ( tokens.length !== questionParts.length ) {
			return {
				reference: null,
				placeholderCount: tokens.length,
				error: 'PLACEHOLDER_COUNT_MISMATCH',
			};
		}

		var reference = '';
		var cursor = 0;

		tokens.forEach( function ( token, index ) {
			reference += fixedText.slice( cursor, token.start );
			reference += questionParts[ index ];
			cursor = token.start + token.length;
		} );
		reference += fixedText.slice( cursor );

		return {
			reference: reference,
			placeholderCount: tokens.length,
			error: null,
		};
	}

	/* ---- Live Book-format checks ----
	 *
	 * The first BK03 run exposed a diagnostic flaw in the v0.5 base checker:
	 * a reconstructed year such as "( 2018 )" was reported as "no publication
	 * year found in parentheses". The year plainly exists; the real defect is
	 * spacing inside the parentheses. These checks distinguish presence from
	 * formatting so Citex reports the actual problem instead of a misleading
	 * BOOK_FORMAT_MISMATCH.
	 */
	function findLooseYear( reference ) {
		return /\(\s*(\d{4})\s*\)/.exec( reference );
	}

	function checkYearTrailingPeriod( reference ) {
		if ( /\(\s*\d{4}\s*\)\./.test( reference ) ) {
			return { code: 'YEAR_TRAILING_PERIOD', message: 'Unwanted full stop after publication year.' };
		}
		return null;
	}

	function checkYearParenthesesSpacing( reference ) {
		var match = findLooseYear( reference );
		if ( match && match[ 0 ] !== '(' + match[ 1 ] + ')' ) {
			return {
				code: 'YEAR_PARENTHESES_SPACING',
				message: 'Publication year should be formatted without spaces inside the parentheses, for example (2018).',
			};
		}
		return null;
	}

	function checkColonSpacing( reference ) {
		var yearMatch = findLooseYear( reference );
		var searchFrom = yearMatch ? yearMatch.index + yearMatch[ 0 ].length : 0;
		var tail = reference.slice( searchFrom );
		if ( /:\S/.test( tail ) ) {
			return {
				code: 'MISSING_SPACE_AFTER_COLON',
				message: 'A space is required after the colon between the place of publication and publisher.',
			};
		}
		return null;
	}

	function checkBookFormat( reference ) {
		var yearMatch = findLooseYear( reference );
		if ( ! yearMatch ) {
			return {
				code: 'BOOK_FORMAT_MISMATCH',
				message: 'Citation does not match the Liverpool Hope Book format (no four-digit publication year found in parentheses).',
			};
		}

		var afterYear = reference.slice( yearMatch.index + yearMatch[ 0 ].length );
		if ( ! /:\s*\S/.test( afterYear ) ) {
			return {
				code: 'BOOK_FORMAT_MISMATCH',
				message: 'Citation does not match the Liverpool Hope Book format (no "Place: Publisher" separator found after the year).',
			};
		}
		return null;
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
				errors: [ structuralError( reconstruction.error, extraction.questionParts, reconstruction ) ],
				warnings: [],
				diagnostics: diagnostics,
			};
		}

		var errors = [];
		[
			checkYearTrailingPeriod,
			checkYearParenthesesSpacing,
			checkColonSpacing,
			base.checkMissingFinalPeriod,
			checkBookFormat,
		].forEach( function ( check ) {
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
		findPlaceholderTokens: findPlaceholderTokens,
		reconstructReference: reconstructReference,
		findLooseYear: findLooseYear,
		checkYearTrailingPeriod: checkYearTrailingPeriod,
		checkYearParenthesesSpacing: checkYearParenthesesSpacing,
		checkColonSpacing: checkColonSpacing,
		checkBookFormat: checkBookFormat,
		validateHarvardBookDragdrop: validateHarvardBookDragdrop,
		runValidatorFor: runValidatorFor,
		validateOne: validateOne,
		validateSequence: validateSequence,
	} );
} )();
