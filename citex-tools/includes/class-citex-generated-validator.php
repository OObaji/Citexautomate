<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates generated Citex records before they are allowed to populate the
 * real WordPress Reference List.
 *
 * This deliberately validates the generated structured data directly rather
 * than pretending it is already a WordPress post. The rules mirror the Book /
 * DragDrop checks used by the live validator: placeholder reconstruction,
 * year punctuation/spacing, punctuation spacing, colon spacing, final full
 * stop, Book shape, and distractor separation.
 */
class Citex_Generated_Validator {

	/**
	 * Validate one pending generated question.
	 *
	 * @param array $question Pending generator record.
	 * @return array Structured result.
	 */
	public static function validate( $question ) {
		if (
			'Harvard' !== (string) ( $question['source'] ?? '' ) ||
			'ReferenceList' !== (string) ( $question['group'] ?? '' ) ||
			! Citex_Reference_Rules::is_known_category( (string) ( $question['category'] ?? '' ) )
		) {
			return self::result(
				'failed',
				array(
					self::error( 'UNSUPPORTED_GENERATED_FORMAT', 'Generated validation currently supports only Harvard / ReferenceList / Book, Edited Book, Journal Article or Website, DragDrop or MCQ.' ),
				),
				null
			);
		}

		$type = (string) ( $question['type'] ?? '' );
		if ( 'DragDrop' === $type ) {
			return self::validate_dragdrop( $question );
		}
		if ( 'MCQ' === $type ) {
			// The "Identify the error" MCQ mechanic has a fundamentally
			// different option shape (plain-English error descriptions, not
			// Harvard reference strings) — see normalise_identify_error_item()
			// in class-citex-ai-v2.php — so it is routed to its own
			// validator rather than forcing validate_mcq()'s
			// reference-format checks onto text that was never meant to be
			// a reference. Dispatched on the candidate's own `mcqPattern`
			// field (set only by that normaliser), not on blueprint, since
			// blueprint is optional/empty for any caller outside the
			// dynamic-generation framework.
			if ( 'identify_error' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_identify_error( $question );
			}
			// "Choose the correct rule/treatment": pure rule knowledge, no
			// bibliographic record at all — see normalise_choose_treatment_item()
			// in class-citex-ai-v2.php. Routed the same way as identify_error,
			// on `mcqPattern`.
			if ( 'choose_treatment' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_choose_treatment( $question );
			}
			return self::validate_mcq( $question );
		}

		return self::result(
			'failed',
			array(
				self::error( 'UNSUPPORTED_GENERATED_FORMAT', 'Generated validation currently supports only Harvard / ReferenceList / Book / DragDrop or MCQ.' ),
			),
			null
		);
	}

	private static function validate_dragdrop( $question ) {
		$errors   = array();
		$category = (string) ( $question['category'] ?? Citex_Reference_Rules::CATEGORY_BOOK );

		$fixed_text     = (string) ( $question['fixedText'] ?? '' );
		$question_parts = is_array( $question['questionParts'] ?? null ) ? array_values( $question['questionParts'] ) : array();
		$confusing      = is_array( $question['confusingWords'] ?? null ) ? array_values( $question['confusingWords'] ) : array();

		if ( '' === trim( $fixed_text ) ) {
			$errors[] = self::error( 'FIXED_TEXT_MISSING', 'Fixed Text is missing.' );
		}
		if ( empty( $question_parts ) ) {
			$errors[] = self::error( 'QUESTION_PARTS_MISSING', 'Question Parts are missing.' );
		}

		$reconstruction = self::reconstruct( $fixed_text, $question_parts );
		if ( is_wp_error( $reconstruction ) ) {
			$errors[] = self::error( $reconstruction->get_error_code(), $reconstruction->get_error_message() );
			return self::result( 'failed', $errors, null );
		}

		$reference = $reconstruction['reference'];
		$errors    = array_merge( $errors, self::validate_reference_format( $reference, $category, $question['place'] ?? null, $question['publisher'] ?? null, self::expected_designation_for( $question, $category ), self::expected_editor_join_for( $question, $category ) ) );

		$correct_lower = array_map(
			function ( $value ) {
				return strtolower( trim( (string) $value ) );
			},
			$question_parts
		);
		$seen_confusing = array();
		foreach ( $confusing as $word ) {
			$normal = strtolower( trim( (string) $word ) );
			if ( '' === $normal ) {
				continue;
			}
			if ( in_array( $normal, $correct_lower, true ) ) {
				$errors[] = self::error( 'DISTRACTOR_MATCHES_CORRECT_PART', 'A confusing word duplicates a correct draggable Question Part: ' . (string) $word );
			}
			if ( isset( $seen_confusing[ $normal ] ) ) {
				$errors[] = self::error( 'DUPLICATE_DISTRACTOR', 'A confusing word is duplicated: ' . (string) $word );
			}
			$seen_confusing[ $normal ] = true;
		}

		$expected = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' !== $expected && $expected !== $reference ) {
			$errors[] = self::error( 'RECONSTRUCTED_REFERENCE_MISMATCH', 'The generated expected reference does not match the reference reconstructed from Fixed Text and Question Parts.' );
		}

		$errors = array_merge( $errors, self::validate_consistency( $question, $question_parts, $reference, $category ) );
		$errors = array_merge( $errors, self::validate_answer_leakage( $question ) );

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $reference );
	}

	/**
	 * Dispatches to the category-appropriate bibliographic-consistency
	 * check — Book has a single author, Edited Book has one or more
	 * editors, so the two need different field shapes (see
	 * validate_edited_book_consistency()). Every other category-specific
	 * difference (format regex, DragDrop piece construction) is already
	 * isolated to Citex_Reference_Rules; this is the one check that could
	 * not be made category-agnostic, because "who wrote this" is shaped
	 * differently per category.
	 *
	 * Journal Article gets its own dedicated check
	 * (validate_journal_article_consistency()) rather than reusing Book's —
	 * it has no place/publisher concept, a different DragDrop shape, and its
	 * own "never et al." rule, so folding it into the Book branch would mean
	 * silently relying on Book-shaped assumptions for a genuinely different
	 * category.
	 *
	 * $check_scenario defaults to true — DragDrop's scenario still must
	 * describe the specific book (unchanged). MCQ passes false: its
	 * question text is now Citex's own fixed, category-generic stem (see
	 * Citex_Reference_Rules::mcq_question_stem()), which deliberately never
	 * mentions the book's title/author/year/etc, so that portion of the
	 * consistency check does not apply to it — the reference-must-contain-
	 * the-facts checks (1-10 in validate_bibliographic_consistency(), the
	 * equivalent block in validate_edited_book_consistency()) still run for
	 * MCQ exactly as before; only the scenario-text block is skipped.
	 */
	private static function validate_consistency( $question, $question_parts, $reference, $category, $check_scenario = true ) {
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
			return self::validate_edited_book_consistency( $question, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return self::validate_journal_article_consistency( $question, $question_parts, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
			return self::validate_website_consistency( $question, $question_parts, $reference, $check_scenario );
		}
		return self::validate_bibliographic_consistency( $question, $question_parts, $reference, $check_scenario );
	}

	/**
	 * MCQ counterpart to validate_dragdrop(): exactly 4 option slots — the
	 * first 3 holding distinct distractors, the 4th always blank — and the
	 * SAME Harvard-format/bibliographic-consistency/answer-leakage checks
	 * already used for DragDrop applied to the correct answer's own text
	 * (reconstructedReference). Citex constructs that answer itself (see
	 * Citex_AI_V2::normalise_mcq_item()), so it must satisfy exactly the
	 * same format rules DragDrop's reconstructed reference does. The
	 * correct answer is never placed into, or duplicated into, any option
	 * slot — it lives ONLY in the Answer field (see write_mcq_acf_values()
	 * in class-citex-populator.php).
	 */
	private static function validate_mcq( $question ) {
		$errors   = array();
		$category = (string) ( $question['category'] ?? Citex_Reference_Rules::CATEGORY_BOOK );
		$options  = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 distractors + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}

		// Option 1-3 hold the 3 distractors; Option 4 is ALWAYS left blank.
		// The correct answer lives only in the Answer field
		// (reconstructedReference, below) — it must never be placed into, or
		// duplicated into, any option slot. This is the direct fix for a
		// real reported bug: placing the correct reference into one of the
		// 4 option slots AND into the Answer field made the student app
		// render the two copies as separate, simultaneously-"selected"
		// choices.
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a distractor.', $i + 1 ) );
			}
		}
		if ( '' !== trim( (string) $options[3] ) ) {
			$errors[] = self::error( 'MCQ_FOURTH_OPTION_NOT_BLANK', 'Option 4 must be left blank — the correct answer belongs only in the Answer field, never duplicated into an option.' );
		}

		$seen = array();
		foreach ( $options as $index => $option ) {
			$normal = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $option ) ) );
			if ( '' === $normal ) {
				continue;
			}
			if ( isset( $seen[ $normal ] ) ) {
				$errors[] = self::error( 'MCQ_DUPLICATE_OPTION', sprintf( 'Option %d duplicates another option.', $index + 1 ) );
			}
			$seen[ $normal ] = true;
		}

		$place        = $question['place'] ?? null;
		$publisher    = $question['publisher'] ?? null;
		$designation  = self::expected_designation_for( $question, $category );
		$editor_join  = self::expected_editor_join_for( $question, $category );
		$reference    = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $reference ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$errors = array_merge( $errors, self::validate_reference_format( $reference, $category, $place, $publisher, $designation, $editor_join ) );

		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $reference ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			// The correct answer must never appear as an option — it
			// belongs only in the Answer field.
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — the answer must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
				continue;
			}
			// No distractor may itself look like a fully valid Harvard
			// reference — that would be a second plausible answer, exactly
			// the ambiguity a real MCQ must never contain.
			if ( empty( self::validate_reference_format( $option_text, $category, $place, $publisher, $designation, $editor_join ) ) ) {
				$errors[] = self::error(
					'MCQ_DISTRACTOR_LOOKS_CORRECT',
					sprintf( 'Option %d passes every Harvard format rule too — this creates a second plausible answer.', $index + 1 )
				);
			}
		}

		// MCQ has no real draggable Question Parts of its own — this is only
		// ever fed into validate_bibliographic_consistency()'s
		// PARTS_MISMATCH check (Edited Book's consistency check ignores it
		// entirely), so it is built via the exact same
		// Citex_Reference_Rules::dragdrop_shape() call that check compares
		// against, for whatever author count this question actually has
		// (falling back to the singular authorSurname/authorInitials fields
		// for a record with no `authors` array) — trivially self-consistent
		// by construction, exactly as the old hardcoded 4-tuple was for a
		// single author, but correct for 2+ authors too.
		$authors_for_parts = is_array( $question['authors'] ?? null ) && ! empty( $question['authors'] )
			? $question['authors']
			: array( array( 'surname' => trim( (string) ( $question['authorSurname'] ?? '' ) ), 'initials' => trim( (string) ( $question['authorInitials'] ?? '' ) ) ) );
		// Journal Article's dragdrop_shape() expects a different field shape
		// (articleTitle/journalTitle/volume/issue/pages — there is no
		// place/publisher for this category), so it is built separately
		// rather than forcing it through the Book/Edited Book field names.
		if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
			$question_parts = Citex_Reference_Rules::dragdrop_shape(
				$category,
				array(
					'authors'      => $authors_for_parts,
					'year'         => (string) ( $question['year'] ?? '' ),
					'articleTitle' => (string) ( $question['articleTitle'] ?? '' ),
					'journalTitle' => (string) ( $question['journalTitle'] ?? '' ),
					'volume'       => (string) ( $question['volume'] ?? '' ),
					'issue'        => (string) ( $question['issue'] ?? '' ),
					'pages'        => (string) ( $question['pages'] ?? '' ),
				)
			)['parts'];
		} elseif ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
			// Website's dragdrop_shape() expects a single {type, surname,
			// initials, name} author struct, not an array of people — built
			// defensively from the record's own authorType/authors/
			// organisationName fields so a malformed or mis-categorised
			// record fails validation cleanly instead of crashing.
			$author_type = (string) ( $question['authorType'] ?? '' );
			$author      = array( 'type' => $author_type );
			if ( 'individual' === $author_type ) {
				$author['surname']  = trim( (string) ( $authors_for_parts[0]['surname'] ?? '' ) );
				$author['initials'] = trim( (string) ( $authors_for_parts[0]['initials'] ?? '' ) );
			} else {
				$author['name'] = trim( (string) ( $question['organisationName'] ?? '' ) );
			}
			$question_parts = Citex_Reference_Rules::dragdrop_shape(
				$category,
				array(
					'author'       => $author,
					'year'         => (string) ( $question['year'] ?? '' ),
					'title'        => (string) ( $question['title'] ?? '' ),
					'publisher'    => (string) ( $question['publisher'] ?? '' ),
					'url'          => (string) ( $question['url'] ?? '' ),
					'accessedDate' => (string) ( $question['accessedDate'] ?? '' ),
				)
			)['parts'];
		} else {
			$question_parts = Citex_Reference_Rules::dragdrop_shape(
				$category,
				array(
					'authors'   => $authors_for_parts,
					'editors'   => is_array( $question['editors'] ?? null ) ? $question['editors'] : array(),
					'year'      => (string) ( $question['year'] ?? '' ),
					'title'     => (string) ( $question['bookTitle'] ?? '' ),
					'place'     => (string) ( $question['place'] ?? '' ),
					'publisher' => (string) ( $question['publisher'] ?? '' ),
				)
			)['parts'];
		}
		// $check_scenario = false: MCQ's question text is Citex's own fixed,
		// category-generic stem (see Citex_Reference_Rules::mcq_question_stem()),
		// checked below instead via MCQ_QUESTION_STEM_MISMATCH — the
		// reference-must-contain-the-facts checks still run unchanged.
		$errors = array_merge( $errors, self::validate_consistency( $question, $question_parts, $reference, $category, false ) );
		$errors = array_merge( $errors, self::validate_answer_leakage( $question ) );

		// MCQ's question text must be exactly Citex's own fixed,
		// category-specific stem — never a per-book description (that would
		// re-open the exact leakage class removed by taking scenario-writing
		// away from Gemini for MCQ) and never anything else Gemini might
		// have supplied (Gemini is not even asked for a scenario anymore —
		// see schema_mcq()/schema_edited_book_mcq() — so this also catches
		// any stray value slipping through some other path).
		$expected_stem = Citex_Reference_Rules::mcq_question_stem( $category );
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected_stem ) {
			$errors[] = self::error(
				'MCQ_QUESTION_STEM_MISMATCH',
				sprintf( 'The MCQ question text must be exactly: "%s".', $expected_stem )
			);
		}

		// The hint is written into the real "Hint" field on population (see
		// class-citex-populator.php's FIELD_HINT) — a missing one is a
		// structural gap the same way a missing Fixed Text is for DragDrop.
		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $reference ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $reference );
	}

	/**
	 * The Hint field is shown to the student BEFORE they answer, so — unlike
	 * the (never-written-to-WordPress) answerExplanation, which is allowed
	 * to reveal the answer because nothing currently shows it before
	 * submission — it must never name or point at a specific option, and
	 * must never reproduce the correct reference's own text. Citex authors
	 * the hint deterministically from a fixed, category-generic clue (see
	 * Citex_Reference_Rules::mcq_hint()) that structurally cannot fail
	 * these checks, but they run regardless — the same "construct it
	 * correctly AND validate it independently" pattern used everywhere else
	 * in this class (e.g. MCQ_DISTRACTOR_LOOKS_CORRECT re-checking options
	 * Citex itself assembled).
	 */
	private static function validate_mcq_hint_safety( $question, $reference ) {
		$errors = array();
		$hint   = (string) ( $question['hint'] ?? '' );

		if ( preg_match( '/\b[A-D]\s+is\s+correct\b/i', $hint )
			|| preg_match( '/\bthe\s+correct\s+(option|answer)\s+is\b/i', $hint )
			|| preg_match( '/\bthe\s+answer\s+is\b/i', $hint )
			|| preg_match( '/\boption\s+(?:[1-4]|[A-D])\b/i', $hint )
		) {
			$errors[] = self::error(
				'MCQ_HINT_REVEALS_ANSWER',
				'The hint names or points directly at a specific option (e.g. a letter/number plus "is correct", or "the answer is...") — a hint must help the student reason about the rule without identifying which option is correct.'
			);
		}

		if ( '' !== trim( (string) $reference ) && self::text_contains( $hint, $reference ) ) {
			$errors[] = self::error(
				'MCQ_HINT_REPRODUCES_ANSWER',
				'The hint reproduces the full correct reference text, which reveals the answer directly.'
			);
		}

		return $errors;
	}

	/**
	 * "Identify the error" MCQ counterpart to validate_mcq(): same 4-option
	 * shape and "never duplicate the answer into an option" rule, but the
	 * options are plain-English error DESCRIPTIONS, not Harvard reference
	 * strings — so none of validate_mcq()'s Harvard-format-per-option
	 * checks apply to them. What this validates instead: the reference
	 * SHOWN to the student (brokenReference) must genuinely fail Harvard
	 * format validation (it would defeat the question if it were actually
	 * correct) while still containing every canonical bibliographic fact —
	 * the ONLY thing wrong with it should be the one deliberate mistake
	 * named in the answer, never a substituted fact. Reuses
	 * validate_mcq_hint_safety() unchanged: it already only compares the
	 * hint against "the answer text", which works identically whether that
	 * text is a full reference or an error description.
	 */
	private static function validate_identify_error( $question ) {
		$errors   = array();
		$category = (string) ( $question['category'] ?? Citex_Reference_Rules::CATEGORY_BOOK );
		$options  = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong descriptions + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong description.', $i + 1 ) );
			}
		}
		if ( '' !== trim( (string) $options[3] ) ) {
			$errors[] = self::error( 'MCQ_FOURTH_OPTION_NOT_BLANK', 'Option 4 must be left blank — the true description belongs only in the Answer field, never duplicated into an option.' );
		}

		$seen = array();
		foreach ( $options as $index => $option ) {
			$normal = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $option ) ) );
			if ( '' === $normal ) {
				continue;
			}
			if ( isset( $seen[ $normal ] ) ) {
				$errors[] = self::error( 'MCQ_DUPLICATE_OPTION', sprintf( 'Option %d duplicates another option.', $index + 1 ) );
			}
			$seen[ $normal ] = true;
		}

		$true_description = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $true_description ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The true error description (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$true_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $true_description ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $true_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the true description — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$broken_reference = trim( (string) ( $question['brokenReference'] ?? '' ) );
		if ( '' === $broken_reference ) {
			$errors[] = self::error( 'IDENTIFY_ERROR_BROKEN_REFERENCE_MISSING', 'The broken reference shown to the student is missing.' );
			return self::result( 'failed', $errors, null );
		}
		// Pass the full check set (place/publisher/designation/editor-join)
		// — the same one validate_mcq() gives every distractor — so a
		// mistake only those specific checks can see (a place/publisher
		// swap, or a wrong editor designation) is not missed here, which
		// would otherwise let validate_reference_format() come back empty
		// and wrongly flag a genuinely broken reference as "not broken".
		$place       = $question['place'] ?? null;
		$publisher   = $question['publisher'] ?? null;
		$designation = self::expected_designation_for( $question, $category );
		$editor_join = self::expected_editor_join_for( $question, $category );
		if ( empty( self::validate_reference_format( $broken_reference, $category, $place, $publisher, $designation, $editor_join ) ) ) {
			$errors[] = self::error(
				'IDENTIFY_ERROR_REFERENCE_NOT_BROKEN',
				'The reference shown to the student passes every Harvard format rule — it must contain the one deliberate mistake named in the answer, or this is not a valid "identify the error" question.'
			);
		}

		$people_key = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ? 'editors' : 'authors';
		$people     = is_array( $question[ $people_key ] ?? null ) ? $question[ $people_key ] : array();
		foreach ( $people as $index => $person ) {
			$surname  = trim( (string) ( $person['surname'] ?? '' ) );
			$initials = trim( (string) ( $person['initials'] ?? '' ) );
			if ( '' !== $surname && ! self::text_contains( $broken_reference, $surname ) ) {
				$errors[] = self::error( 'IDENTIFY_ERROR_REFERENCE_MISMATCH', sprintf( 'The broken reference does not contain person %1$d\'s surname: "%2$s".', $index + 1, $surname ) );
			}
			if ( '' !== $initials && ! self::text_contains( $broken_reference, $initials ) ) {
				$errors[] = self::error( 'IDENTIFY_ERROR_REFERENCE_MISMATCH', sprintf( 'The broken reference does not contain person %1$d\'s initials: "%2$s".', $index + 1, $initials ) );
			}
		}
		foreach (
			array(
				'year'      => array( trim( (string) ( $question['year'] ?? '' ) ), 'publication year' ),
				'title'     => array( trim( (string) ( $question['bookTitle'] ?? '' ) ), 'book title' ),
				'place'     => array( trim( (string) ( $question['place'] ?? '' ) ), 'place of publication' ),
				'publisher' => array( trim( (string) ( $question['publisher'] ?? '' ) ), 'publisher' ),
			) as $pair
		) {
			list( $value, $label ) = $pair;
			if ( '' !== $value && ! self::text_contains( $broken_reference, $value ) ) {
				$errors[] = self::error( 'IDENTIFY_ERROR_REFERENCE_MISMATCH', sprintf( 'The broken reference does not contain the canonical %1$s: "%2$s".', $label, $value ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $true_description ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $true_description );
	}

	/**
	 * "Choose the correct rule/treatment" MCQ counterpart to validate_mcq():
	 * same 4-option shape and "never duplicate the answer into an option"
	 * rule, but there is no bibliographic record at all here — this
	 * question tests pure rule knowledge (see normalise_choose_treatment_item()
	 * in class-citex-ai-v2.php), so none of validate_mcq()'s reference-
	 * format or bibliographic-consistency checks apply. What this
	 * validates instead: the scenario and the answer must exactly match
	 * Citex_Reference_Rules::treatment_question()'s own fixed stem/
	 * correctStatement for this question's `treatmentBucket` — the same
	 * "Citex authors it, then independently re-checks what was actually
	 * written" pattern MCQ_QUESTION_STEM_MISMATCH already uses for
	 * select_correct.
	 */
	private static function validate_choose_treatment( $question ) {
		$errors   = array();
		$category = (string) ( $question['category'] ?? Citex_Reference_Rules::CATEGORY_BOOK );
		$options  = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong statements + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong statement.', $i + 1 ) );
			}
		}
		if ( '' !== trim( (string) $options[3] ) ) {
			$errors[] = self::error( 'MCQ_FOURTH_OPTION_NOT_BLANK', 'Option 4 must be left blank — the true statement belongs only in the Answer field, never duplicated into an option.' );
		}

		$seen = array();
		foreach ( $options as $index => $option ) {
			$normal = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $option ) ) );
			if ( '' === $normal ) {
				continue;
			}
			if ( isset( $seen[ $normal ] ) ) {
				$errors[] = self::error( 'MCQ_DUPLICATE_OPTION', sprintf( 'Option %d duplicates another option.', $index + 1 ) );
			}
			$seen[ $normal ] = true;
		}

		$true_statement = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $true_statement ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The true rule statement (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$true_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $true_statement ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $true_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the true statement — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$bucket_id = (string) ( $question['treatmentBucket'] ?? '' );
		$expected  = Citex_Reference_Rules::treatment_question( $category, $bucket_id );
		if ( null === $expected ) {
			$errors[] = self::error( 'TREATMENT_BUCKET_UNKNOWN', sprintf( 'Unrecognised choose-treatment bucket: "%s".', $bucket_id ) );
			return self::result( 'failed', $errors, $true_statement );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'TREATMENT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $true_statement !== $expected['correctStatement'] ) {
			$errors[] = self::error( 'TREATMENT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own true statement for this bucket: "%s".', $expected['correctStatement'] ) );
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $true_statement ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $true_statement );
	}

	/**
	 * The designation ("ed." or "eds", without parentheses) this Edited
	 * Book question's real editor count requires — null for Book (the
	 * concept doesn't apply) or when no editors are present at all. Shared
	 * by validate_dragdrop() and validate_mcq() so both compute it the same
	 * way, from Citex_Reference_Rules::designation_for_editor_count() (the
	 * same single source of truth Citex_AI_V2 uses to build the correct
	 * option/reconstruction in the first place).
	 */
	private static function expected_designation_for( $question, $category ) {
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK !== $category ) {
			return null;
		}
		$editors = is_array( $question['editors'] ?? null ) ? $question['editors'] : array();
		if ( empty( $editors ) ) {
			return null;
		}
		return Citex_Reference_Rules::designation_for_editor_count( count( $editors ) );
	}

	/**
	 * The correctly-joined multi-editor string ("Smith, J. and Jones, A.")
	 * this question's real editors require — null for Book, or for an
	 * Edited Book question with fewer than 2 editors (the "and" join only
	 * applies once there is more than one name to join). Mirrors
	 * expected_designation_for(): both feed validate_reference_format() the
	 * one fact it needs to detect a distractor whose mistake the generic
	 * shape regex structurally cannot see.
	 */
	private static function expected_editor_join_for( $question, $category ) {
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK !== $category ) {
			return null;
		}
		$editors = is_array( $question['editors'] ?? null ) ? $question['editors'] : array();
		if ( count( $editors ) < 2 ) {
			return null;
		}
		return Citex_Reference_Rules::join_editors( $editors );
	}

	/**
	 * The Harvard reference-format checks shared by DragDrop's reconstructed
	 * reference and MCQ's correct option, for every category — punctuation
	 * and spacing rules identical regardless of category. The one
	 * category-specific piece — the overall shape (does it actually look
	 * like a Book vs an Edited Book reference) — is delegated to
	 * Citex_Reference_Rules::format_regex(), the pluggable layer new
	 * categories provide instead of this method growing a new branch each
	 * time.
	 */
	private static function validate_reference_format( $reference, $category = null, $place = null, $publisher = null, $expected_designation = null, $expected_editor_join = null ) {
		$category = $category ?? Citex_Reference_Rules::CATEGORY_BOOK;
		$errors   = array();

		if ( preg_match( '/\(\s+\d{4}|\d{4}\s+\)/', $reference ) ) {
			$errors[] = self::error( 'YEAR_PARENTHESES_SPACING', 'Publication year should have no spaces inside the parentheses, for example (2019).' );
		}
		if ( preg_match( '/\(\d{4}\)\./', $reference ) ) {
			$errors[] = self::error( 'YEAR_TRAILING_PERIOD', 'Unwanted full stop after publication year.' );
		}
		if ( preg_match( '/\s+[,.;:]/', $reference ) ) {
			$errors[] = self::error( 'SPACE_BEFORE_PUNCTUATION', 'Remove extra spaces before punctuation marks in the completed reference.' );
		}
		// Excludes a colon that is part of a URL scheme ("http://",
		// "https://") — Website references legitimately contain one inside
		// the <URL> segment (e.g. "Available from: <https://example.com>"),
		// and that colon is never the "Place: Publisher" one this check
		// exists to catch.
		if ( preg_match( '/:(?!\/\/)\S/', $reference ) ) {
			$errors[] = self::error( 'MISSING_SPACE_AFTER_COLON', 'A space is required after the colon between place of publication and publisher.' );
		}
		if ( ! preg_match( '/\.\s*$/', $reference ) ) {
			$errors[] = self::error( 'MISSING_FINAL_PERIOD', 'Missing final full stop.' );
		}

		// Liverpool Hope shape for this category — Surname, I. (Year) Title.
		// Place: Publisher. for Book; Editor(s), I. (ed.|eds) (Year) Title.
		// Place: Publisher. for Edited Book.
		if ( ! preg_match( Citex_Reference_Rules::format_regex( $category ), $reference ) ) {
			if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
				$code    = 'EDITED_BOOK_FORMAT_MISMATCH';
				$message = 'Citation does not match the Liverpool Hope Edited Book format.';
			} elseif ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
				$code    = 'JOURNAL_ARTICLE_FORMAT_MISMATCH';
				$message = 'Citation does not match the Liverpool Hope Journal Article format.';
			} elseif ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
				$code    = 'WEBSITE_FORMAT_MISMATCH';
				$message = 'Citation does not match the Liverpool Hope Website/Web Resource format.';
			} else {
				$code    = 'BOOK_FORMAT_MISMATCH';
				$message = 'Citation does not match the Liverpool Hope Book format.';
			}
			$errors[] = self::error( $code, $message );
		}

		// Place/publisher ORDER — "Place: Publisher.", never the reverse.
		// The shape regex above cannot tell a place from a publisher; any
		// non-empty "X: Y." satisfies it equally whichever way round X and Y
		// are. That blind spot is exactly why an MCQ distractor built by
		// swapping place and publisher (a real, common Harvard mistake — see
		// Citex_Reference_Rules::mcq_distractor_patterns()) used to slip past
		// every check above and get flagged as a second "fully valid"
		// option. When the record's real place/publisher are known, check
		// the literal ordering directly instead of trusting the generic
		// shape: this can only ever fire on the SWAPPED pairing, so it never
		// flags the correct option (Citex always builds it in the right
		// order) and never weakens any check above — it only closes a gap
		// those checks structurally cannot see.
		if ( null !== $place && null !== $publisher ) {
			$place_trim     = trim( (string) $place );
			$publisher_trim = trim( (string) $publisher );
			if ( '' !== $place_trim && '' !== $publisher_trim ) {
				$correct_order = $place_trim . ': ' . $publisher_trim;
				$swapped_order = $publisher_trim . ': ' . $place_trim;
				if ( ! self::text_contains( $reference, $correct_order ) && self::text_contains( $reference, $swapped_order ) ) {
					$errors[] = self::error(
						'PLACE_PUBLISHER_ORDER_MISMATCH',
						'Place of publication and publisher appear to be swapped — Harvard requires "Place: Publisher.", not "Publisher: Place.".'
					);
				}
			}
		}

		// Edited Book designation vs. editor count — the exact same blind
		// spot as place/publisher above, and arguably the more important
		// one: the shape regex accepts EITHER "(ed.)" or "(eds)" as valid on
		// their own, so it cannot tell whether the designation actually
		// matches THIS question's editor count. A distractor using the
		// wrong designation for the stated editor count (a real, explicitly
		// required distractor pattern — "must not accidentally use (ed.)
		// for a book with multiple editors") used to slip past every check
		// above unnoticed. When the record's real editor count is known,
		// check directly: this only ever fires on the WRONG designation, so
		// it never flags the correct option (Citex always builds it with
		// the right one) and never weakens EDITED_BOOK_DESIGNATION_MISMATCH's
		// existing meaning — it only applies that same rule to every option,
		// not just the one marked correct.
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category && null !== $expected_designation && '' !== trim( (string) $expected_designation ) ) {
			$expected = trim( (string) $expected_designation );
			$wrong    = 'ed.' === $expected ? 'eds' : 'ed.';
			if ( ! self::text_contains( $reference, '(' . $expected . ')' ) && self::text_contains( $reference, '(' . $wrong . ')' ) ) {
				$errors[] = self::error(
					'EDITED_BOOK_DESIGNATION_MISMATCH',
					sprintf( 'The reference uses "(%1$s)" instead of the "(%2$s)" required for this question\'s editor count.', $wrong, $expected )
				);
			}
		}

		// Multi-editor joining — "Smith, J. and Jones, A.", never a comma
		// throughout. Same blind spot again: the shape regex's leading `.+`
		// swallows the whole editor-name segment however it is punctuated,
		// so a distractor that replaces " and " with ", " is otherwise
		// indistinguishable from correct. Only checked when we know the
		// exact correct join AND the comma-joined variant would actually
		// differ from it (i.e. there genuinely is an "and" to omit).
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category && null !== $expected_editor_join ) {
			$correct_join = trim( (string) $expected_editor_join );
			if ( '' !== $correct_join && false !== strpos( $correct_join, ' and ' ) ) {
				$comma_joined = str_replace( ' and ', ', ', $correct_join );
				if ( $comma_joined !== $correct_join && ! self::text_contains( $reference, $correct_join ) && self::text_contains( $reference, $comma_joined ) ) {
					$errors[] = self::error(
						'EDITED_BOOK_EDITOR_JOIN_MISMATCH',
						'Two or more editors must be joined with "and" before the last name (e.g. "Smith, J. and Jones, A."), not a comma throughout.'
					);
				}
			}
		}

		return $errors;
	}

	/**
	 * ANSWER_LEAKAGE — a generated scenario must give the student enough
	 * bibliographic information to CONSTRUCT the Harvard reference, never
	 * enough to simply copy an answer that is already spelled out. A real
	 * example this was written to catch: "...by Alan Bryman (initials A.),
	 * published in 2012..." — the parenthetical hands the student one of
	 * the four draggable Question Parts directly.
	 *
	 * This is deliberately NOT "does the scenario contain the surname" —
	 * that is required, correct, and already checked by
	 * validate_bibliographic_consistency() (a full name like "Alan Bryman"
	 * naturally contains "Bryman"). The distinction this method enforces is
	 * between natural bibliographic information and an answer being
	 * explicitly labelled or handed over pre-formatted:
	 *
	 * 1. The words "initial"/"initials" or "surname" never belong in a
	 *    natural scenario at all — their presence means an answer is being
	 *    named as an answer, not just given as information.
	 * 2. An abbreviated or completed Harvard citation embedded in the
	 *    scenario (e.g. "Bryman, A." or "Bryman, A. (2012) ...") hands the
	 *    student the exact answer format, checked against the canonical
	 *    surname specifically so this cannot misfire on unrelated text.
	 * 3. The literal initials value appearing as its own token (e.g. "use
	 *    A." or "(A.)"), not merely as part of a longer word.
	 *
	 * Runs for every Book/DragDrop question (validate() has already
	 * confirmed the format above) regardless of whether a canonical record
	 * is present — the word-ban checks are meaningful either way; the
	 * citation/initials-value checks simply no-op without a canonical
	 * surname/initials to check against.
	 */
	private static function validate_answer_leakage( $question ) {
		$errors   = array();
		$scenario = (string) ( $question['scenario'] ?? '' );
		if ( '' === trim( $scenario ) ) {
			return $errors;
		}

		if ( preg_match( '/\binitials?\b/i', $scenario ) ) {
			$errors[] = self::error(
				'ANSWER_LEAKAGE_INITIALS_WORD',
				'The scenario uses the word "initial"/"initials", which explicitly labels a draggable answer. State the author\'s full name instead and let the student derive the initials.'
			);
		}
		if ( preg_match( '/\bsurname\b/i', $scenario ) ) {
			$errors[] = self::error(
				'ANSWER_LEAKAGE_SURNAME_WORD',
				'The scenario uses the word "surname", which explicitly labels a draggable answer. State the author\'s full name instead.'
			);
		}

		// One or more people — Book: $question['authors'] (falling back to
		// the singular authorSurname/authorInitials for any record that
		// never carries the array), Edited Book: $question['editors'] —
		// every one of them is checked identically, since a leak of ANY
		// author/editor's abbreviated citation or initials value is just as
		// much an answer leak.
		$people = array();
		if ( ! empty( $question['authors'] ) && is_array( $question['authors'] ) ) {
			foreach ( $question['authors'] as $author ) {
				$people[] = array(
					'surname'  => trim( (string) ( $author['surname'] ?? '' ) ),
					'initials' => trim( (string) ( $author['initials'] ?? '' ) ),
				);
			}
		} else {
			$surname  = trim( (string) ( $question['authorSurname'] ?? '' ) );
			$initials = trim( (string) ( $question['authorInitials'] ?? '' ) );
			if ( '' !== $surname || '' !== $initials ) {
				$people[] = array( 'surname' => $surname, 'initials' => $initials );
			}
		}
		foreach ( (array) ( $question['editors'] ?? array() ) as $editor ) {
			$people[] = array(
				'surname'  => trim( (string) ( $editor['surname'] ?? '' ) ),
				'initials' => trim( (string) ( $editor['initials'] ?? '' ) ),
			);
		}

		foreach ( $people as $person ) {
			if ( '' !== $person['surname'] && preg_match( '/' . preg_quote( $person['surname'], '/' ) . '\s*,\s*[A-Z]\.(?:\s?[A-Z]\.)*/u', $scenario ) ) {
				$errors[] = self::error(
					'ANSWER_LEAKAGE_ABBREVIATED_CITATION',
					'The scenario contains an abbreviated or completed Harvard citation (e.g. "Surname, I."), which hands the student the answer directly.'
				);
			}

			if ( '' !== $person['initials'] && preg_match( '/(?<![A-Za-z.])' . preg_quote( $person['initials'], '/' ) . '(?![A-Za-z])/u', $scenario ) ) {
				$errors[] = self::error(
					'ANSWER_LEAKAGE_INITIALS_VALUE',
					sprintf( 'The scenario contains the literal initials value "%s" as a standalone token, revealing a draggable answer directly.', $person['initials'] )
				);
			}
		}

		// Edited Book only: the editor designation itself ("(ed.)"/"(eds)")
		// is a Question Part / MCQ answer-defining token, exactly like
		// initials are for Book — it must never already appear in the
		// scenario.
		if ( preg_match( '/\(\s*eds?\.?\s*\)/i', $scenario ) ) {
			$errors[] = self::error(
				'ANSWER_LEAKAGE_DESIGNATION_VALUE',
				'The scenario already shows "(ed.)"/"(eds)", revealing the editor-designation answer directly.'
			);
		}

		return $errors;
	}

	/**
	 * BIBLIOGRAPHIC_CONSISTENCY — the safety net for the academic-integrity
	 * bug where a generated question's scenario described one real book while
	 * its Question Parts/Fixed Text were built from a different one (both
	 * internally self-consistent, so earlier checks never caught it). This
	 * only runs when the pending record actually carries at least one
	 * canonical author or a book title — Citex-generated questions always
	 * do (see Citex_AI_V2::normalise()); externally imported records that
	 * never captured one are unaffected, so nothing that previously passed
	 * import validation is weakened.
	 *
	 * Reshaped for one-or-more authors (mirrors
	 * validate_edited_book_consistency()'s per-editor loop exactly — Book
	 * authors and Edited Book editors are joined by the same Liverpool Hope
	 * rule and validated the same way): every author's surname/initials must
	 * appear in the reconstructed reference, and (scenario checks only)
	 * every author's surname must appear in the scenario. A record with no
	 * `authors` array falls back to the singular authorSurname/authorInitials
	 * fields (treated as a single author) — this keeps every pre-multi-author
	 * record, and any externally imported record that only ever populated
	 * the singular fields, validating exactly as before. Question Parts must
	 * exactly match Citex_Reference_Rules::dragdrop_shape()'s own output for
	 * these same canonical authors — reusing that method (rather than
	 * re-deriving its author-count branching here) is what keeps this check
	 * correct for any author count instead of assuming a fixed 4-part shape.
	 */
	private static function validate_bibliographic_consistency( $question, $question_parts, $reference, $check_scenario = true ) {
		$errors  = array();
		$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		if ( empty( $authors ) ) {
			$fallback_surname  = trim( (string) ( $question['authorSurname'] ?? '' ) );
			$fallback_initials = trim( (string) ( $question['authorInitials'] ?? '' ) );
			if ( '' !== $fallback_surname || '' !== $fallback_initials ) {
				$authors = array( array( 'surname' => $fallback_surname, 'initials' => $fallback_initials ) );
			}
		}
		$title = trim( (string) ( $question['bookTitle'] ?? '' ) );

		if ( empty( $authors ) && '' === $title ) {
			return $errors;
		}
		if ( empty( $authors ) ) {
			$errors[] = self::error( 'BOOK_AUTHORS_MISSING', 'No authors were provided for this Book question.' );
			return $errors;
		}

		$year      = trim( (string) ( $question['year'] ?? '' ) );
		$place     = trim( (string) ( $question['place'] ?? '' ) );
		$publisher = trim( (string) ( $question['publisher'] ?? '' ) );

		// Question Parts must be EXACTLY the shape Citex_Reference_Rules::
		// dragdrop_shape() would build for these canonical authors — reusing
		// that same method (rather than re-deriving the branching logic
		// here) is what makes this check meaningful for any author count: 4
		// parts (surname, initials, year, title) for one author, or 3 parts
		// (joined author list, year, title) for two or more.
		$expected_shape = Citex_Reference_Rules::dragdrop_shape(
			Citex_Reference_Rules::CATEGORY_BOOK,
			array( 'authors' => $authors, 'year' => $year, 'title' => $title, 'place' => $place, 'publisher' => $publisher )
		);
		$expected_parts = array_map( 'trim', $expected_shape['parts'] );
		$actual_parts   = array_map( 'trim', (array) $question_parts );
		if ( $expected_parts !== array_values( $actual_parts ) ) {
			$errors[] = self::error(
				'BIBLIOGRAPHIC_CONSISTENCY_PARTS_MISMATCH',
				'Question Parts do not exactly match the canonical bibliographic record (author(s), year, title) for this author count.'
			);
		}

		foreach ( $authors as $index => $author ) {
			$author_surname  = trim( (string) ( $author['surname'] ?? '' ) );
			$author_initials = trim( (string) ( $author['initials'] ?? '' ) );
			if ( '' !== $author_surname && ! self::text_contains( $reference, $author_surname ) ) {
				$errors[] = self::error( 'BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', sprintf( 'The reference does not contain author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
			}
			if ( '' !== $author_initials && ! self::text_contains( $reference, $author_initials ) ) {
				$errors[] = self::error( 'BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', sprintf( 'The reference does not contain author %1$d\'s initials: "%2$s".', $index + 1, $author_initials ) );
			}
			// Scenario check: author initials are deliberately excluded — a
			// natural scenario names the author (e.g. "Stella Cottrell"),
			// not their initials, so checking initials against the scenario
			// text would reject genuinely correct scenarios. Skipped when
			// $check_scenario is false (MCQ): its scenario is Citex's own
			// fixed, category-generic question stem, which by design names
			// no book-specific fact at all.
			if ( $check_scenario && '' !== $author_surname && ! self::text_contains( (string) ( $question['scenario'] ?? '' ), $author_surname ) ) {
				$errors[] = self::error( 'BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
			}
		}

		foreach (
			array(
				'year'      => array( $year, 'publication year' ),
				'title'     => array( $title, 'book title' ),
				'place'     => array( $place, 'place of publication' ),
				'publisher' => array( $publisher, 'publisher' ),
			) as $pair
		) {
			list( $value, $label ) = $pair;
			if ( '' !== $value && ! self::text_contains( $reference, $value ) ) {
				$errors[] = self::error( 'BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', sprintf( 'The reconstructed reference does not contain the canonical %1$s: "%2$s".', $label, $value ) );
			}
		}

		if ( $check_scenario ) {
			$scenario = (string) ( $question['scenario'] ?? '' );
			foreach (
				array(
					'title'     => array( $title, 'book title' ),
					'year'      => array( $year, 'publication year' ),
					'place'     => array( $place, 'place of publication' ),
					'publisher' => array( $publisher, 'publisher' ),
				) as $pair
			) {
				list( $value, $label ) = $pair;
				if ( '' !== $value && ! self::text_contains( $scenario, $value ) ) {
					$errors[] = self::error( 'BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical %1$s: "%2$s".', $label, $value ) );
				}
			}
		}

		return $errors;
	}

	/**
	 * Edited Book counterpart to validate_bibliographic_consistency() — the
	 * same academic-integrity safety net (scenario must describe the same
	 * source the reference/Question Parts were built from), reshaped for
	 * one-or-more editors instead of a single author. Also independently
	 * confirms the editor DESIGNATION used in the reference actually
	 * matches the editor count — "must not accidentally use (ed.) for a
	 * book with multiple editors", explicitly required and tested.
	 */
	private static function validate_edited_book_consistency( $question, $reference, $check_scenario = true ) {
		$errors  = array();
		$editors = is_array( $question['editors'] ?? null ) ? array_values( $question['editors'] ) : array();
		$title   = trim( (string) ( $question['bookTitle'] ?? '' ) );

		if ( empty( $editors ) && '' === $title ) {
			return $errors;
		}

		if ( empty( $editors ) ) {
			$errors[] = self::error( 'EDITED_BOOK_EDITORS_MISSING', 'No editors were provided for this Edited Book question.' );
			return $errors;
		}

		$year      = trim( (string) ( $question['year'] ?? '' ) );
		$place     = trim( (string) ( $question['place'] ?? '' ) );
		$publisher = trim( (string) ( $question['publisher'] ?? '' ) );

		$expected_designation = Citex_Reference_Rules::designation_for_editor_count( count( $editors ) );
		if ( ! self::text_contains( $reference, '(' . $expected_designation . ')' ) ) {
			$errors[] = self::error(
				'EDITED_BOOK_DESIGNATION_MISMATCH',
				sprintf(
					'The reference does not contain "(%1$s)", the designation required for %2$d editor(s) — it must never use "(ed.)" for multiple editors or "(eds)" for a single editor.',
					$expected_designation,
					count( $editors )
				)
			);
		}

		foreach ( $editors as $index => $editor ) {
			$editor_surname  = trim( (string) ( $editor['surname'] ?? '' ) );
			$editor_initials = trim( (string) ( $editor['initials'] ?? '' ) );
			if ( '' !== $editor_surname && ! self::text_contains( $reference, $editor_surname ) ) {
				$errors[] = self::error( 'EDITED_BOOK_REFERENCE_MISMATCH', sprintf( 'The reference does not contain editor %1$d\'s surname: "%2$s".', $index + 1, $editor_surname ) );
			}
			if ( '' !== $editor_initials && ! self::text_contains( $reference, $editor_initials ) ) {
				$errors[] = self::error( 'EDITED_BOOK_REFERENCE_MISMATCH', sprintf( 'The reference does not contain editor %1$d\'s initials: "%2$s".', $index + 1, $editor_initials ) );
			}
			if ( $check_scenario && '' !== $editor_surname && ! self::text_contains( (string) ( $question['scenario'] ?? '' ), $editor_surname ) ) {
				$errors[] = self::error( 'EDITED_BOOK_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention editor %1$d\'s surname: "%2$s".', $index + 1, $editor_surname ) );
			}
		}

		foreach (
			array(
				'year'      => array( $year, 'publication year' ),
				'title'     => array( $title, 'book title' ),
				'place'     => array( $place, 'place of publication' ),
				'publisher' => array( $publisher, 'publisher' ),
			) as $pair
		) {
			list( $value, $label ) = $pair;
			if ( '' !== $value && ! self::text_contains( $reference, $value ) ) {
				$errors[] = self::error( 'EDITED_BOOK_REFERENCE_MISMATCH', sprintf( 'The reference does not contain the canonical %1$s: "%2$s".', $label, $value ) );
			}
		}

		// Scenario check (11's counterpart): title, year, place, publisher —
		// editor names are already checked per-editor above; initials are
		// excluded here for the same reason Book excludes them (a natural
		// scenario names the editor, not their initials).
		//
		// Skipped when $check_scenario is false (MCQ) — see the matching
		// comment in validate_bibliographic_consistency() for why: MCQ's
		// scenario is now Citex's own fixed, category-generic stem that by
		// design names no book-specific fact.
		if ( $check_scenario ) {
			$scenario = (string) ( $question['scenario'] ?? '' );
			foreach (
				array(
					'title'     => array( $title, 'book title' ),
					'year'      => array( $year, 'publication year' ),
					'place'     => array( $place, 'place of publication' ),
					'publisher' => array( $publisher, 'publisher' ),
				) as $pair
			) {
				list( $value, $label ) = $pair;
				if ( '' !== $value && ! self::text_contains( $scenario, $value ) ) {
					$errors[] = self::error( 'EDITED_BOOK_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical %1$s: "%2$s".', $label, $value ) );
				}
			}
		}

		return $errors;
	}

	/**
	 * Journal Article's OWN dedicated consistency check — deliberately not a
	 * call into validate_bibliographic_consistency() (Book) or
	 * validate_edited_book_consistency(): this category has no place/
	 * publisher concept, always uses the constant 7-part DragDrop shape (see
	 * Citex_Reference_Rules::journal_article_dragdrop_shape()) regardless of
	 * author count, and has its own "et al. must never appear" rule that
	 * applies to every author count starting at 1, not just 4+.
	 *
	 * Per the requirement that this validator "reconstruct the expected
	 * reference from canonical data rather than merely checking whether a
	 * generated string 'looks right'": this independently rebuilds the
	 * correct reference from the canonical authors/year/articleTitle/
	 * journalTitle/volume/issue/pages via
	 * Citex_Reference_Rules::build_reference() — the exact same construction
	 * Citex_AI_V2's normaliser used — and requires an EXACT match against
	 * the reference under test (JOURNAL_ARTICLE_RECONSTRUCTION_MISMATCH),
	 * rather than only checking that individual facts merely appear
	 * somewhere in the string.
	 */
	private static function validate_journal_article_consistency( $question, $question_parts, $reference, $check_scenario = true ) {
		$errors  = array();
		$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		if ( empty( $authors ) ) {
			$fallback_surname  = trim( (string) ( $question['authorSurname'] ?? '' ) );
			$fallback_initials = trim( (string) ( $question['authorInitials'] ?? '' ) );
			if ( '' !== $fallback_surname || '' !== $fallback_initials ) {
				$authors = array( array( 'surname' => $fallback_surname, 'initials' => $fallback_initials ) );
			}
		}
		$article_title = trim( (string) ( $question['articleTitle'] ?? '' ) );
		$journal_title = trim( (string) ( $question['journalTitle'] ?? '' ) );

		if ( empty( $authors ) && '' === $article_title ) {
			return $errors;
		}
		if ( empty( $authors ) ) {
			$errors[] = self::error( 'JOURNAL_ARTICLE_AUTHORS_MISSING', 'No authors were provided for this Journal Article question.' );
			return $errors;
		}

		$year   = trim( (string) ( $question['year'] ?? '' ) );
		$volume = trim( (string) ( $question['volume'] ?? '' ) );
		$issue  = trim( (string) ( $question['issue'] ?? '' ) );
		$pages  = trim( (string) ( $question['pages'] ?? '' ) );

		$fields = array(
			'authors'      => $authors,
			'year'         => $year,
			'articleTitle' => $article_title,
			'journalTitle' => $journal_title,
			'volume'       => $volume,
			'issue'        => $issue,
			'pages'        => $pages,
		);

		// Question Parts must be EXACTLY the constant 7-part shape
		// journal_article_dragdrop_shape() would build for these canonical
		// authors, for ANY author count — unlike Book, there is no
		// single-author special case for this category.
		$expected_shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields );
		$expected_parts = array_map( 'trim', $expected_shape['parts'] );
		$actual_parts   = array_map( 'trim', (array) $question_parts );
		if ( $expected_parts !== array_values( $actual_parts ) ) {
			$errors[] = self::error(
				'JOURNAL_ARTICLE_PARTS_MISMATCH',
				'Question Parts do not exactly match the canonical bibliographic record (author(s), year, article title, journal title, volume, issue, pages).'
			);
		}

		// Independently reconstruct the expected reference from canonical
		// data (see this method's docblock) and require an exact match.
		$expected_reference = trim( Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields ) );
		if ( '' !== $expected_reference && trim( (string) $reference ) !== $expected_reference ) {
			$errors[] = self::error(
				'JOURNAL_ARTICLE_RECONSTRUCTION_MISMATCH',
				sprintf( 'The reference does not match the one independently reconstructed from canonical data: "%s".', $expected_reference )
			);
		}

		foreach ( $authors as $index => $author ) {
			$author_surname  = trim( (string) ( $author['surname'] ?? '' ) );
			$author_initials = trim( (string) ( $author['initials'] ?? '' ) );
			if ( '' !== $author_surname && ! self::text_contains( $reference, $author_surname ) ) {
				$errors[] = self::error( 'JOURNAL_ARTICLE_REFERENCE_MISMATCH', sprintf( 'The reference does not contain author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
			}
			if ( '' !== $author_initials && ! self::text_contains( $reference, $author_initials ) ) {
				$errors[] = self::error( 'JOURNAL_ARTICLE_REFERENCE_MISMATCH', sprintf( 'The reference does not contain author %1$d\'s initials: "%2$s".', $index + 1, $author_initials ) );
			}
			// Scenario check excludes initials — a natural scenario names the
			// author (e.g. "Sarah Mitchell"), not their initials. Skipped
			// when $check_scenario is false (MCQ): its scenario is Citex's
			// own fixed, category-generic stem.
			if ( $check_scenario && '' !== $author_surname && ! self::text_contains( (string) ( $question['scenario'] ?? '' ), $author_surname ) ) {
				$errors[] = self::error( 'JOURNAL_ARTICLE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
			}
		}

		// "et al." must NEVER appear in a Journal Article reference-list
		// entry, for any author count — the one Liverpool Hope misconception
		// this category exists to test (see Citex_Reference_Rules::
		// build_reference()'s docblock).
		if ( preg_match( '/\bet\s*al\.?\b/i', (string) $reference ) ) {
			$errors[] = self::error( 'JOURNAL_ARTICLE_ET_AL_USED', 'The reference list entry must never use "et al." — every author must be listed in full.' );
		}

		foreach (
			array(
				'year'         => array( $year, 'publication year' ),
				'articleTitle' => array( $article_title, 'article title' ),
				'journalTitle' => array( $journal_title, 'journal title' ),
				'volume'       => array( $volume, 'volume' ),
				'issue'        => array( $issue, 'issue' ),
				'pages'        => array( $pages, 'page range' ),
			) as $pair
		) {
			list( $value, $label ) = $pair;
			if ( '' !== $value && ! self::text_contains( $reference, $value ) ) {
				$errors[] = self::error( 'JOURNAL_ARTICLE_REFERENCE_MISMATCH', sprintf( 'The reconstructed reference does not contain the canonical %1$s: "%2$s".', $label, $value ) );
			}
		}

		if ( $check_scenario ) {
			$scenario = (string) ( $question['scenario'] ?? '' );
			foreach (
				array(
					'articleTitle' => array( $article_title, 'article title' ),
					'journalTitle' => array( $journal_title, 'journal title' ),
					'year'         => array( $year, 'publication year' ),
					'volume'       => array( $volume, 'volume' ),
					'issue'        => array( $issue, 'issue' ),
					'pages'        => array( $pages, 'page range' ),
				) as $pair
			) {
				list( $value, $label ) = $pair;
				if ( '' !== $value && ! self::text_contains( $scenario, $value ) ) {
					$errors[] = self::error( 'JOURNAL_ARTICLE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical %1$s: "%2$s".', $label, $value ) );
				}
			}
		}

		return $errors;
	}

	/**
	 * Website's OWN dedicated consistency check — deliberately not a call
	 * into any other category's consistency method: this category has no
	 * multi-person joining concept (only ONE author-or-organisation), a
	 * year-or-"n.d." field instead of a plain year, no place at all, and a
	 * URL/accessed-date pair no other category has. Item 18's italics
	 * requirement is a plain-text-field concern: this build's stored
	 * representation for `title` (like every other category's title/name
	 * fields) is plain text with no inline markup at all — Citex applies
	 * italics only in whatever rendering layer displays the completed
	 * reference to a human, exactly as it always has for the site's other
	 * title fields (see class-citex-populator.php's field-writing, which
	 * never carries HTML) — so this validator correctly treats `title` as
	 * a plain string and must never fail merely because it contains no
	 * markup.
	 *
	 * Per the requirement that this validator "reconstruct the expected
	 * answer from canonical source data rather than merely perform loose
	 * string matching": this independently rebuilds the correct reference
	 * from the canonical author/year/title/publisher/url/accessedDate via
	 * Citex_Reference_Rules::build_reference() — the exact same
	 * construction Citex_AI_V2's normaliser used — and requires an EXACT
	 * match against the reference under test (WEBSITE_RECONSTRUCTION_MISMATCH).
	 */
	private static function validate_website_consistency( $question, $question_parts, $reference, $check_scenario = true ) {
		$errors      = array();
		$author_type = trim( (string) ( $question['authorType'] ?? '' ) );

		$author_surname    = '';
		$author_initials   = '';
		$organisation_name = '';
		if ( 'individual' === $author_type ) {
			$authors = is_array( $question['authors'] ?? null ) ? $question['authors'] : array();
			if ( ! empty( $authors ) ) {
				$author_surname  = trim( (string) ( $authors[0]['surname'] ?? '' ) );
				$author_initials = trim( (string) ( $authors[0]['initials'] ?? '' ) );
			}
		} elseif ( 'organisation' === $author_type ) {
			$organisation_name = trim( (string) ( $question['organisationName'] ?? '' ) );
		}
		$title = trim( (string) ( $question['title'] ?? '' ) );

		if ( '' === $author_type && '' === $title ) {
			return $errors;
		}
		if ( 'individual' !== $author_type && 'organisation' !== $author_type ) {
			$errors[] = self::error( 'WEBSITE_AUTHOR_TYPE_INVALID', 'authorType must be exactly "individual" or "organisation".' );
			return $errors;
		}
		if ( 'individual' === $author_type && ( '' === $author_surname || '' === $author_initials ) ) {
			$errors[] = self::error( 'WEBSITE_AUTHOR_MISSING', 'No individual author surname/initials were provided for this Website question.' );
			return $errors;
		}
		if ( 'organisation' === $author_type && '' === $organisation_name ) {
			$errors[] = self::error( 'WEBSITE_AUTHOR_MISSING', 'No organisation name was provided for this Website question.' );
			return $errors;
		}

		$year          = trim( (string) ( $question['year'] ?? '' ) );
		$publisher     = trim( (string) ( $question['publisher'] ?? '' ) );
		$url           = trim( (string) ( $question['url'] ?? '' ) );
		$accessed_date = trim( (string) ( $question['accessedDate'] ?? '' ) );

		// Year must be exactly a 4-digit year or the literal "n.d." — never
		// a guessed year, and never any other placeholder text (see
		// Citex_Reference_Rules::build_website_reference()'s docblock).
		if ( '' === $year || ! preg_match( '/^(?:\d{4}|n\.d\.)$/', $year ) ) {
			$errors[] = self::error( 'WEBSITE_YEAR_INVALID', 'The year must be exactly a 4-digit publication/creation year, or the literal "n.d." when no date can be identified.' );
		}
		if ( '' === $url || ! preg_match( '#^https?://\S+$#', $url ) ) {
			$errors[] = self::error( 'WEBSITE_URL_MALFORMED', 'The URL must be a well-formed http(s) address with no spaces.' );
		}
		if ( '' === $accessed_date ) {
			$errors[] = self::error( 'WEBSITE_ACCESSED_DATE_MISSING', 'The accessed date is missing.' );
		}

		$author = array( 'type' => $author_type );
		if ( 'individual' === $author_type ) {
			$author['surname']  = $author_surname;
			$author['initials'] = $author_initials;
		} else {
			$author['name'] = $organisation_name;
		}
		$fields = array(
			'author'       => $author,
			'year'         => $year,
			'title'        => $title,
			'publisher'    => $publisher,
			'url'          => $url,
			'accessedDate' => $accessed_date,
		);

		// Question Parts must be EXACTLY the shape website_dragdrop_shape()
		// would build for this canonical author-or-organisation.
		$expected_shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_WEBSITE, $fields );
		$expected_parts = array_map( 'trim', $expected_shape['parts'] );
		$actual_parts   = array_map( 'trim', (array) $question_parts );
		if ( $expected_parts !== array_values( $actual_parts ) ) {
			$errors[] = self::error(
				'WEBSITE_PARTS_MISMATCH',
				'Question Parts do not exactly match the canonical bibliographic record (author/organisation, year/n.d., title, publisher, URL, accessed date).'
			);
		}

		// Independently reconstruct the expected reference from canonical
		// data (see this method's docblock) and require an exact match.
		$expected_reference = trim( Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_WEBSITE, $fields ) );
		if ( '' !== $expected_reference && trim( (string) $reference ) !== $expected_reference ) {
			$errors[] = self::error(
				'WEBSITE_RECONSTRUCTION_MISMATCH',
				sprintf( 'The reference does not match the one independently reconstructed from canonical data: "%s".', $expected_reference )
			);
		}

		// Canonical facts must appear in the reference itself.
		$author_display = Citex_Reference_Rules::format_website_author( $author );
		foreach (
			array(
				'author'    => array( $author_display, 'author/organisation' ),
				'title'     => array( $title, 'page/document title' ),
				'publisher' => array( $publisher, 'publisher' ),
				'url'       => array( $url, 'URL' ),
			) as $pair
		) {
			list( $value, $label ) = $pair;
			if ( '' !== $value && ! self::text_contains( $reference, $value ) ) {
				$errors[] = self::error( 'WEBSITE_REFERENCE_MISMATCH', sprintf( 'The reference does not contain the canonical %1$s: "%2$s".', $label, $value ) );
			}
		}

		if ( $check_scenario ) {
			$scenario = (string) ( $question['scenario'] ?? '' );
			foreach (
				array(
					'title'     => array( $title, 'page/document title' ),
					'publisher' => array( $publisher, 'publisher' ),
					'url'       => array( $url, 'URL' ),
				) as $pair
			) {
				list( $value, $label ) = $pair;
				if ( '' !== $value && ! self::text_contains( $scenario, $value ) ) {
					$errors[] = self::error( 'WEBSITE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical %1$s: "%2$s".', $label, $value ) );
				}
			}
			if ( 'organisation' === $author_type && '' !== $organisation_name && ! self::text_contains( $scenario, $organisation_name ) ) {
				$errors[] = self::error( 'WEBSITE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical organisation: "%s".', $organisation_name ) );
			}
			if ( 'individual' === $author_type && '' !== $author_surname && ! self::text_contains( $scenario, $author_surname ) ) {
				$errors[] = self::error( 'WEBSITE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the author\'s surname: "%s".', $author_surname ) );
			}

			// Answer leakage specific to this category: the scenario must
			// never explicitly instruct "(n.d.)"/"no date"/"undated" — the
			// student must recognise the missing date and derive "(n.d.)"
			// themselves (see the user's own worked leakage example).
			if ( preg_match( '/\bn\.d\.\b/i', $scenario ) || preg_match( '/\bno\s+date\b/i', $scenario ) || preg_match( '/\bundated\b/i', $scenario ) ) {
				$errors[] = self::error( 'WEBSITE_ANSWER_LEAKAGE_ND', 'The scenario states "(n.d.)"/"no date"/"undated" directly — the student must recognise the missing date and derive "(n.d.)" themselves.' );
			}
		}

		return $errors;
	}

	/**
	 * Case-insensitive substring containment, not exact string matching —
	 * natural-language scenario phrasing varies ("You are referencing..." vs
	 * "You are creating a reference for..."), so each canonical fact only
	 * needs to appear somewhere in the text, not match it word-for-word.
	 */
	private static function text_contains( $haystack, $needle ) {
		$haystack = self::normalise_for_match( $haystack );
		$needle   = self::normalise_for_match( $needle );
		return '' !== $needle && false !== mb_stripos( $haystack, $needle );
	}

	private static function normalise_for_match( $value ) {
		$value = (string) $value;
		$value = str_replace( array( "\xE2\x80\x99", "\xE2\x80\x98" ), "'", $value ); // curly quotes -> straight
		$value = preg_replace( '/\s+/', ' ', $value );
		return trim( (string) $value );
	}

	/**
	 * Parse the confirmed Citex DragDrop grammar:
	 * - a single | may represent the first slot;
	 * - || represents internal slots;
	 * - a final single | may be followed only by fixed punctuation/whitespace.
	 */
	private static function reconstruct( $fixed_text, $parts ) {
		$fixed_text = (string) $fixed_text;
		$tokens     = array();
		$length     = strlen( $fixed_text );
		$i          = 0;

		while ( $i < $length ) {
			if ( '|' !== $fixed_text[ $i ] ) {
				$tokens[] = array( 'type' => 'text', 'value' => $fixed_text[ $i ] );
				$i++;
				continue;
			}

			if ( $i + 1 < $length && '|' === $fixed_text[ $i + 1 ] ) {
				$tokens[] = array( 'type' => 'slot', 'value' => '||' );
				$i += 2;
				continue;
			}

			$before = substr( $fixed_text, 0, $i );
			$after  = substr( $fixed_text, $i + 1 );
			$is_first = '' === trim( $before );
			$is_final = 1 === preg_match( '/^[\s\.,;:!?\-–—]*$/u', $after );

			if ( ! $is_first && ! $is_final ) {
				return new WP_Error( 'MALFORMED_PLACEHOLDER_ENCODING', 'Fixed Text contains a single "|" in an internal position; internal draggable placeholders must use "||".' );
			}

			$tokens[] = array( 'type' => 'slot', 'value' => '|' );
			$i++;
		}

		$slot_count = 0;
		foreach ( $tokens as $token ) {
			if ( 'slot' === $token['type'] ) {
				$slot_count++;
			}
		}

		if ( $slot_count !== count( $parts ) ) {
			return new WP_Error(
				'PLACEHOLDER_COUNT_MISMATCH',
				sprintf( 'Fixed Text contains %d draggable placeholder(s), but there are %d Question Part(s).', $slot_count, count( $parts ) )
			);
		}

		$reference = '';
		$part_index = 0;
		foreach ( $tokens as $token ) {
			if ( 'slot' === $token['type'] ) {
				$reference .= (string) $parts[ $part_index++ ];
			} else {
				$reference .= $token['value'];
			}
		}

		return array(
			'reference'       => trim( $reference ),
			'placeholderCount'=> $slot_count,
		);
	}

	private static function error( $code, $message ) {
		return array(
			'code'    => sanitize_key( $code ),
			'message' => (string) $message,
		);
	}

	private static function result( $status, $errors, $reference ) {
		return array(
			'status'                 => $status,
			'errors'                 => array_values( $errors ),
			'reconstructedReference' => $reference,
			'validatedAt'            => gmdate( 'c' ),
		);
	}
}
