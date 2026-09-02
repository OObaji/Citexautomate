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
					self::error( 'UNSUPPORTED_GENERATED_FORMAT', 'Generated validation currently supports only Harvard / ReferenceList / Book or Edited Book, DragDrop or MCQ.' ),
				),
				null
			);
		}

		$type = (string) ( $question['type'] ?? '' );
		if ( 'DragDrop' === $type ) {
			return self::validate_dragdrop( $question );
		}
		if ( 'MCQ' === $type ) {
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
		$errors    = array_merge( $errors, self::validate_reference_format( $reference, $category ) );

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
	 */
	private static function validate_consistency( $question, $question_parts, $reference, $category ) {
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
			return self::validate_edited_book_consistency( $question, $reference );
		}
		return self::validate_bibliographic_consistency( $question, $question_parts, $reference );
	}

	/**
	 * MCQ counterpart to validate_dragdrop(): exactly 4 non-empty, mutually
	 * distinct options, a correctOptionIndex identifying one of them, and the
	 * SAME Harvard-format/bibliographic-consistency/answer-leakage checks
	 * already used for DragDrop applied to the correct option's own text —
	 * Citex constructs that option itself (see Citex_AI_V2::normalise_mcq_item()),
	 * so it must satisfy exactly the same format rules DragDrop's
	 * reconstructed reference does.
	 */
	private static function validate_mcq( $question ) {
		$errors   = array();
		$category = (string) ( $question['category'] ?? Citex_Reference_Rules::CATEGORY_BOOK );
		$options  = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 options are required; %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		foreach ( $options as $index => $option ) {
			if ( '' === trim( (string) $option ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty.', $index + 1 ) );
			}
		}

		$correct_index = isset( $question['correctOptionIndex'] ) ? (int) $question['correctOptionIndex'] : -1;
		if ( $correct_index < 0 || $correct_index > 3 ) {
			$errors[] = self::error( 'MCQ_CORRECT_INDEX_INVALID', 'correctOptionIndex must identify one of the 4 options (0-3).' );
			return self::result( 'failed', $errors, null );
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

		$reference = trim( (string) $options[ $correct_index ] );
		$errors    = array_merge( $errors, self::validate_reference_format( $reference, $category ) );

		// Exactly one option may look correct. A distractor that happens to
		// pass every Harvard format rule too (different wording, but still
		// structurally valid) is a second "reasonably correct" answer —
		// precisely the ambiguity a real MCQ must never contain.
		foreach ( $options as $index => $option ) {
			if ( $index === $correct_index ) {
				continue;
			}
			if ( empty( self::validate_reference_format( trim( (string) $option ), $category ) ) ) {
				$errors[] = self::error(
					'MCQ_DISTRACTOR_LOOKS_CORRECT',
					sprintf( 'Option %d is not marked correct but passes every Harvard format rule too — this creates a second plausible answer.', $index + 1 )
				);
			}
		}

		$expected = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' !== $expected && $expected !== $reference ) {
			$errors[] = self::error( 'RECONSTRUCTED_REFERENCE_MISMATCH', 'The correct option does not match the canonical reconstructed reference.' );
		}

		$question_parts = array(
			trim( (string) ( $question['authorSurname'] ?? '' ) ),
			trim( (string) ( $question['authorInitials'] ?? '' ) ),
			trim( (string) ( $question['year'] ?? '' ) ),
			trim( (string) ( $question['bookTitle'] ?? '' ) ),
		);
		$errors = array_merge( $errors, self::validate_consistency( $question, $question_parts, $reference, $category ) );
		$errors = array_merge( $errors, self::validate_answer_leakage( $question ) );

		// The explanation is written into the real "Hint" field on
		// population (see class-citex-populator.php's FIELD_HINT) — a
		// missing one is a structural gap the same way a missing Fixed Text
		// is for DragDrop.
		if ( '' === trim( (string) ( $question['explanation'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_EXPLANATION_MISSING', 'Explanation is missing.' );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $reference );
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
	private static function validate_reference_format( $reference, $category = null ) {
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
		if ( preg_match( '/:\S/', $reference ) ) {
			$errors[] = self::error( 'MISSING_SPACE_AFTER_COLON', 'A space is required after the colon between place of publication and publisher.' );
		}
		if ( ! preg_match( '/\.\s*$/', $reference ) ) {
			$errors[] = self::error( 'MISSING_FINAL_PERIOD', 'Missing final full stop.' );
		}

		// Liverpool Hope shape for this category — Surname, I. (Year) Title.
		// Place: Publisher. for Book; Editor(s), I. (ed.|eds) (Year) Title.
		// Place: Publisher. for Edited Book.
		if ( ! preg_match( Citex_Reference_Rules::format_regex( $category ), $reference ) ) {
			$code    = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ? 'EDITED_BOOK_FORMAT_MISMATCH' : 'BOOK_FORMAT_MISMATCH';
			$message = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category
				? 'Citation does not match the Liverpool Hope Edited Book format.'
				: 'Citation does not match the Liverpool Hope Book format.';
			$errors[] = self::error( $code, $message );
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

		// One person (Book: authorSurname/authorInitials) or several
		// (Edited Book: $question['editors']) — every one of them is
		// checked identically, since a leak of ANY editor's abbreviated
		// citation or initials value is just as much an answer leak.
		$people = array();
		$surname  = trim( (string) ( $question['authorSurname'] ?? '' ) );
		$initials = trim( (string) ( $question['authorInitials'] ?? '' ) );
		if ( '' !== $surname || '' !== $initials ) {
			$people[] = array( 'surname' => $surname, 'initials' => $initials );
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
	 * only runs when the pending record actually carries a canonical
	 * bibliographic record (authorSurname/bookTitle) — Citex-generated
	 * questions always do (see Citex_AI_V2::normalise()); externally
	 * imported records that never captured one are unaffected, so nothing
	 * that previously passed import validation is weakened.
	 *
	 * A generated record's Question Parts and Fixed Text are themselves now
	 * *constructed* from this same canonical record (Citex is the sole
	 * source of truth for both — see Citex_AI_V2::normalise()), so checks
	 * 1-10 below are a deliberate, defensive second confirmation rather than
	 * the only line of defence. Check 11 (the scenario) is the one novel
	 * gap: nothing else in this class, or in Citex_AI_V2, ever inspects the
	 * scenario's own wording against the bibliographic facts.
	 */
	private static function validate_bibliographic_consistency( $question, $question_parts, $reference ) {
		$errors = array();

		$canonical = array(
			'authorSurname'  => trim( (string) ( $question['authorSurname'] ?? '' ) ),
			'authorInitials' => trim( (string) ( $question['authorInitials'] ?? '' ) ),
			'year'           => trim( (string) ( $question['year'] ?? '' ) ),
			'bookTitle'      => trim( (string) ( $question['bookTitle'] ?? '' ) ),
			'place'          => trim( (string) ( $question['place'] ?? '' ) ),
			'publisher'      => trim( (string) ( $question['publisher'] ?? '' ) ),
		);

		if ( '' === $canonical['authorSurname'] && '' === $canonical['bookTitle'] ) {
			return $errors;
		}

		// 1-4: Question Parts must be exactly [surname, initials, year, title].
		$parts_padded  = array_slice( array_pad( array_map( 'trim', $question_parts ), 4, '' ), 0, 4 );
		$expected_parts = array( $canonical['authorSurname'], $canonical['authorInitials'], $canonical['year'], $canonical['bookTitle'] );
		if ( $expected_parts !== $parts_padded ) {
			$errors[] = self::error(
				'BIBLIOGRAPHIC_CONSISTENCY_PARTS_MISMATCH',
				'Question Parts do not exactly match the canonical bibliographic record (surname, initials, year, title).'
			);
		}

		// 5-10: the reconstructed reference must contain every canonical fact.
		foreach (
			array(
				'authorSurname'  => 'author surname',
				'authorInitials' => 'author initials',
				'year'           => 'publication year',
				'bookTitle'      => 'book title',
				'place'          => 'place of publication',
				'publisher'      => 'publisher',
			) as $field => $label
		) {
			if ( '' !== $canonical[ $field ] && ! self::text_contains( $reference, $canonical[ $field ] ) ) {
				$errors[] = self::error(
					'BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH',
					sprintf( 'The reconstructed reference does not contain the canonical %s: "%s".', $label, $canonical[ $field ] )
				);
			}
		}

		// 11: the scenario must identify the same bibliographic record. Author
		// initials are deliberately excluded here: a natural scenario names the
		// author (e.g. "Stella Cottrell"), not their initials, so checking
		// initials against the scenario text would reject genuinely correct
		// scenarios.
		$scenario = (string) ( $question['scenario'] ?? '' );
		foreach (
			array(
				'bookTitle'     => 'book title',
				'authorSurname' => 'author surname',
				'year'          => 'publication year',
				'place'         => 'place of publication',
				'publisher'     => 'publisher',
			) as $field => $label
		) {
			if ( '' !== $canonical[ $field ] && ! self::text_contains( $scenario, $canonical[ $field ] ) ) {
				$errors[] = self::error(
					'BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH',
					sprintf( 'The scenario does not mention the canonical %s: "%s".', $label, $canonical[ $field ] )
				);
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
	private static function validate_edited_book_consistency( $question, $reference ) {
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
			if ( '' !== $editor_surname && ! self::text_contains( (string) ( $question['scenario'] ?? '' ), $editor_surname ) ) {
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
