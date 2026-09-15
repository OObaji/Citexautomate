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
			! in_array( (string) ( $question['source'] ?? '' ), array( 'Harvard', 'MLA', 'APA', 'Chicago', 'MHRA' ), true ) ||
			! in_array( (string) ( $question['group'] ?? '' ), array( 'ReferenceList', 'InTextCitation' ), true ) ||
			! Citex_Reference_Rules::is_known_category( (string) ( $question['category'] ?? '' ) )
		) {
			return self::result(
				'failed',
				array(
					self::error( 'UNSUPPORTED_GENERATED_FORMAT', 'Generated validation currently supports only Harvard / ReferenceList / Book, Edited Book, Journal Article or Website, DragDrop or MCQ, or MLA / ReferenceList / Book, DragDrop or MCQ, or APA / ReferenceList / Book, DragDrop or MCQ, or Chicago / ReferenceList / Book, DragDrop or MCQ, or MHRA / ReferenceList / Book, DragDrop or MCQ, or Harvard/MLA / InTextCitation / any category / DragDrop or MCQ.' ),
				),
				null
			);
		}

		$type = (string) ( $question['type'] ?? '' );
		if ( 'DragDrop' === $type ) {
			// In-text citation is a fundamentally different data shape from
			// every reference-list DragDrop mechanic (no place/publisher/
			// journal fields at all) — see validate_intext_dragdrop()'s own
			// docblock. Dispatched on `group`, not `mcqPattern` (DragDrop
			// candidates carry no mcqPattern field at all).
			if ( 'InTextCitation' === (string) ( $question['group'] ?? '' ) ) {
				return self::validate_intext_dragdrop( $question );
			}
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
			// Book's own fixed 16-variant MCQ catalogue (see
			// Citex_Book_Mcq_Variants) — replaces the original "select the
			// correct reference" mechanic for Book entirely. Every option is
			// Citex-authored, deterministically, from the canonical record —
			// see validate_book_mcq_variant()'s own docblock for why this can
			// check for an EXACT match rather than validate_mcq()'s
			// reference-format sanity checks.
			if ( 'book_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_book_mcq_variant( $question );
			}
			// MLA's own fixed Book MCQ catalogue (see
			// Citex_MLA_Book_Mcq_Variants) — mirrors book_mcq_variant's
			// identical move for MLA's own reference rules (full given name,
			// "et al." for 3+ authors, no place). Routed the same way, on
			// `mcqPattern`.
			if ( 'mla_book_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_mla_book_mcq_variant( $question );
			}
			// APA's own fixed Book MCQ catalogue (see Citex_APA_Book_Mcq_Variants)
			// — mirrors book_mcq_variant's/mla_book_mcq_variant's identical
			// move for APA's own reference rules (initials, "&" joining,
			// full stop after the year). Routed the same way, on `mcqPattern`.
			if ( 'apa_book_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_apa_book_mcq_variant( $question );
			}
			// Chicago (Author-Date)'s own fixed Book MCQ catalogue (see
			// Citex_Chicago_Book_Mcq_Variants) — mirrors book_mcq_variant's/
			// mla_book_mcq_variant's/apa_book_mcq_variant's identical move for
			// Chicago's own reference rules (full given name, comma-before-
			// "and" joining, no parentheses around the year, place kept).
			// Routed the same way, on `mcqPattern`.
			if ( 'chicago_book_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_chicago_book_mcq_variant( $question );
			}
			// Chicago's own Edited Book/Journal Article/Website MCQ
			// catalogues — mirror chicago_book_mcq_variant's/apa's
			// identical move, routed the same way, on `mcqPattern`.
			if ( 'chicago_edited_book_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_chicago_edited_book_mcq_variant( $question );
			}
			if ( 'chicago_journal_article_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_chicago_journal_article_mcq_variant( $question );
			}
			if ( 'chicago_website_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_chicago_website_mcq_variant( $question );
			}
			// MHRA's own fixed Book MCQ catalogue (see
			// Citex_MHRA_Book_Mcq_Variants) — mirrors book_mcq_variant's/
			// mla_book_mcq_variant's/apa_book_mcq_variant's/
			// chicago_book_mcq_variant's identical move for MHRA's own
			// reference rules (only the first author inverted, full given
			// name, place/publisher/year grouped in one parenthesis).
			// Routed the same way, on `mcqPattern`.
			if ( 'mhra_book_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_mhra_book_mcq_variant( $question );
			}
			// APA's own Edited Book/Journal Article/Website MCQ catalogues —
			// mirror apa_book_mcq_variant's identical move, routed the same
			// way, on `mcqPattern`.
			if ( 'apa_edited_book_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_apa_edited_book_mcq_variant( $question );
			}
			if ( 'apa_journal_article_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_apa_journal_article_mcq_variant( $question );
			}
			if ( 'apa_website_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_apa_website_mcq_variant( $question );
			}
			// MLA's own Edited Book/Journal Article/Website MCQ catalogues
			// — mirror mla_book_mcq_variant's identical move, routed the
			// same way, on `mcqPattern`.
			if ( 'mla_edited_book_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_mla_edited_book_mcq_variant( $question );
			}
			if ( 'mla_journal_article_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_mla_journal_article_mcq_variant( $question );
			}
			if ( 'mla_website_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_mla_website_mcq_variant( $question );
			}
			// Website's own fixed MCQ variant catalogue (see
			// Citex_Website_Mcq_Variants) — replaces the original "select
			// the correct reference" mechanic for Website entirely, mirroring
			// Book's own identical move. Routed the same way, on `mcqPattern`.
			if ( 'website_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_website_mcq_variant( $question );
			}
			// In-text citation's own MCQ catalogues (see
			// Citex_Intext_Mcq_Variants / Citex_MLA_Intext_Mcq_Variants) —
			// routed the same way as every other *_mcq_variant mechanic, on
			// `mcqPattern`.
			if ( 'intext_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_intext_mcq_variant( $question );
			}
			if ( 'mla_intext_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_mla_intext_mcq_variant( $question );
			}
			if ( 'apa_intext_mcq_variant' === (string) ( $question['mcqPattern'] ?? '' ) ) {
				return self::validate_apa_intext_mcq_variant( $question );
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

		// HARD RULE, DragDrop-only, Book-only: EXACTLY 3 Question Parts
		// (Citex_Reference_Rules::BOOK_DRAGDROP_MIN_PARTS/MAX_PARTS) — see
		// validate_book_mcq_variant()'s own docblock for why this can go
		// further than a mere plausibility check: every Book DragDrop part
		// and every confusing word is Citex-authored, deterministically,
		// from the record's own canonical fields via
		// Citex_Book_Dragdrop_Parts, so the exact expected
		// {parts, fixedText, confusingWords} can be recomputed from the
		// record and the question's own stored `dragdropPartKeys`
		// selection and compared exactly, rather than merely sanity-checked.
		$source = (string) ( $question['source'] ?? '' );

		if ( Citex_Reference_Rules::CATEGORY_BOOK === $category && 'MLA' === $source ) {
			// MLA's own Book DragDrop block — mirrors Harvard's Book block
			// below exactly (recompute-and-exact-match via
			// Citex_MLA_Book_Dragdrop_Parts from the stored
			// `dragdropPartKeys` selection), just with no `place` field and
			// no explicit part-count bound, matching the same precedent
			// already set by Edited Book/Journal Article/Website (whose own
			// Dragdrop_Parts classes always draw exactly 3 by construction
			// too, so the exact-match check alone already catches any
			// deviation without a separate bound check).
			$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
			if ( empty( $authors ) ) {
				$fallback_surname    = trim( (string) ( $question['authorSurname'] ?? '' ) );
				$fallback_given_name = trim( (string) ( $question['authorGivenName'] ?? '' ) );
				if ( '' !== $fallback_surname || '' !== $fallback_given_name ) {
					$authors = array( array( 'surname' => $fallback_surname, 'givenName' => $fallback_given_name, 'fullName' => (string) ( $question['authorFullName'] ?? '' ) ) );
				}
			}
			$mla_book_title = trim( (string) ( $question['bookTitle'] ?? '' ) );
			if ( ! ( empty( $authors ) && '' === $mla_book_title ) ) {
				$selected_keys = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$mla_fields    = array(
					'year'      => trim( (string) ( $question['year'] ?? '' ) ),
					'title'     => $mla_book_title,
					'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
				);
				$expected_build = ( empty( $selected_keys ) || empty( $authors ) ) ? null : Citex_MLA_Book_Dragdrop_Parts::build( $selected_keys, $authors, $mla_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'MLA_BOOK_DRAGDROP_PARTS_UNKNOWN', 'The MLA Book DragDrop part selection (dragdropPartKeys) is missing, malformed, or names an author index out of range.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'MLA_BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'MLA_BOOK_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'MLA_BOOK_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		} elseif ( Citex_Reference_Rules::CATEGORY_BOOK === $category && 'APA' === $source ) {
			// APA's own Book DragDrop block — mirrors the MLA Book block
			// above exactly (recompute-and-exact-match via
			// Citex_APA_Book_Dragdrop_Parts from the stored
			// `dragdropPartKeys` selection), with the SAME surname/initials
			// field shape Harvard's own Book block below uses (never MLA's
			// surname/givenName) — see Citex_APA_Reference_Rules's own
			// docblock.
			$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
			if ( empty( $authors ) ) {
				$fallback_surname  = trim( (string) ( $question['authorSurname'] ?? '' ) );
				$fallback_initials = trim( (string) ( $question['authorInitials'] ?? '' ) );
				if ( '' !== $fallback_surname || '' !== $fallback_initials ) {
					$authors = array( array( 'surname' => $fallback_surname, 'initials' => $fallback_initials, 'fullName' => (string) ( $question['authorFullName'] ?? '' ) ) );
				}
			}
			$apa_book_title = trim( (string) ( $question['bookTitle'] ?? '' ) );
			if ( ! ( empty( $authors ) && '' === $apa_book_title ) ) {
				$selected_keys = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$apa_fields    = array(
					'year'      => trim( (string) ( $question['year'] ?? '' ) ),
					'title'     => $apa_book_title,
					'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
				);
				$expected_build = ( empty( $selected_keys ) || empty( $authors ) ) ? null : Citex_APA_Book_Dragdrop_Parts::build( $selected_keys, $authors, $apa_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'APA_BOOK_DRAGDROP_PARTS_UNKNOWN', 'The APA Book DragDrop part selection (dragdropPartKeys) is missing, malformed, or names an author index out of range.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'APA_BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'APA_BOOK_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'APA_BOOK_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		} elseif ( Citex_Reference_Rules::CATEGORY_BOOK === $category && 'Chicago' === $source ) {
			// Chicago (Author-Date)'s own Book DragDrop block — mirrors the
			// MLA Book block above exactly (recompute-and-exact-match via
			// Citex_Chicago_Book_Dragdrop_Parts from the stored
			// `dragdropPartKeys` selection), with the SAME surname/givenName
			// field shape MLA's own Book block uses (never Harvard/APA's own
			// surname/initials) but WITH `place` included, like Harvard's own
			// Book block below — see Citex_Chicago_Reference_Rules's own
			// docblock.
			$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
			if ( empty( $authors ) ) {
				$fallback_surname    = trim( (string) ( $question['authorSurname'] ?? '' ) );
				$fallback_given_name = trim( (string) ( $question['authorGivenName'] ?? '' ) );
				if ( '' !== $fallback_surname || '' !== $fallback_given_name ) {
					$authors = array( array( 'surname' => $fallback_surname, 'givenName' => $fallback_given_name, 'fullName' => (string) ( $question['authorFullName'] ?? '' ) ) );
				}
			}
			$chicago_book_title = trim( (string) ( $question['bookTitle'] ?? '' ) );
			if ( ! ( empty( $authors ) && '' === $chicago_book_title ) ) {
				$selected_keys   = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$chicago_fields  = array(
					'year'      => trim( (string) ( $question['year'] ?? '' ) ),
					'title'     => $chicago_book_title,
					'place'     => trim( (string) ( $question['place'] ?? '' ) ),
					'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
				);
				$expected_build = ( empty( $selected_keys ) || empty( $authors ) ) ? null : Citex_Chicago_Book_Dragdrop_Parts::build( $selected_keys, $authors, $chicago_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'CHICAGO_BOOK_DRAGDROP_PARTS_UNKNOWN', 'The Chicago Book DragDrop part selection (dragdropPartKeys) is missing, malformed, or names an author index out of range.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'CHICAGO_BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'CHICAGO_BOOK_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'CHICAGO_BOOK_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		} elseif ( Citex_Reference_Rules::CATEGORY_BOOK === $category && 'MHRA' === $source ) {
			// MHRA's own Book DragDrop block — mirrors the Chicago Book
			// block above exactly (recompute-and-exact-match via
			// Citex_MHRA_Book_Dragdrop_Parts from the stored
			// `dragdropPartKeys` selection), with the SAME surname/givenName
			// field shape MLA's/Chicago's own Book block uses, WITH `place`
			// included — see Citex_MHRA_Reference_Rules's own docblock.
			$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
			if ( empty( $authors ) ) {
				$fallback_surname    = trim( (string) ( $question['authorSurname'] ?? '' ) );
				$fallback_given_name = trim( (string) ( $question['authorGivenName'] ?? '' ) );
				if ( '' !== $fallback_surname || '' !== $fallback_given_name ) {
					$authors = array( array( 'surname' => $fallback_surname, 'givenName' => $fallback_given_name, 'fullName' => (string) ( $question['authorFullName'] ?? '' ) ) );
				}
			}
			$mhra_book_title = trim( (string) ( $question['bookTitle'] ?? '' ) );
			if ( ! ( empty( $authors ) && '' === $mhra_book_title ) ) {
				$selected_keys = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$mhra_fields   = array(
					'title'     => $mhra_book_title,
					'place'     => trim( (string) ( $question['place'] ?? '' ) ),
					'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
					'year'      => trim( (string) ( $question['year'] ?? '' ) ),
				);
				$expected_build = ( empty( $selected_keys ) || empty( $authors ) ) ? null : Citex_MHRA_Book_Dragdrop_Parts::build( $selected_keys, $authors, $mhra_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'MHRA_BOOK_DRAGDROP_PARTS_UNKNOWN', 'The MHRA Book DragDrop part selection (dragdropPartKeys) is missing, malformed, or names an author index out of range.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'MHRA_BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'MHRA_BOOK_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'MHRA_BOOK_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		} elseif ( Citex_Reference_Rules::CATEGORY_BOOK === $category ) {
			$part_count = count( $question_parts );
			if ( $part_count < Citex_Reference_Rules::BOOK_DRAGDROP_MIN_PARTS || $part_count > Citex_Reference_Rules::BOOK_DRAGDROP_MAX_PARTS ) {
				$errors[] = self::error(
					'BOOK_DRAGDROP_PART_COUNT_OUT_OF_RANGE',
					sprintf(
						'Book DragDrop questions must have between %1$d and %2$d Question Parts; %3$d were provided.',
						Citex_Reference_Rules::BOOK_DRAGDROP_MIN_PARTS,
						Citex_Reference_Rules::BOOK_DRAGDROP_MAX_PARTS,
						$part_count
					)
				);
			}

			$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
			if ( empty( $authors ) ) {
				$fallback_surname  = trim( (string) ( $question['authorSurname'] ?? '' ) );
				$fallback_initials = trim( (string) ( $question['authorInitials'] ?? '' ) );
				if ( '' !== $fallback_surname || '' !== $fallback_initials ) {
					$authors = array( array( 'surname' => $fallback_surname, 'initials' => $fallback_initials, 'fullName' => (string) ( $question['authorFullName'] ?? '' ) ) );
				}
			}
			$book_title = trim( (string) ( $question['bookTitle'] ?? '' ) );

			// Records with no canonical author or title data at all (e.g.
			// externally imported, pre-dating this feature) are unaffected —
			// mirrors validate_bibliographic_consistency()'s own identical
			// skip condition; this exact-match check must not retroactively
			// fail data that never carried a canonical record to recompute
			// against in the first place.
			if ( ! ( empty( $authors ) && '' === $book_title ) ) {
				$selected_keys  = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$book_fields    = array(
					'year'      => trim( (string) ( $question['year'] ?? '' ) ),
					'title'     => $book_title,
					'place'     => trim( (string) ( $question['place'] ?? '' ) ),
					'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
				);
				$expected_build = ( empty( $selected_keys ) || empty( $authors ) ) ? null : Citex_Book_Dragdrop_Parts::build( $selected_keys, $authors, $book_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'BOOK_DRAGDROP_PARTS_UNKNOWN', 'The Book DragDrop part selection (dragdropPartKeys) is missing, malformed, or names an author index out of range.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'BOOK_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'BOOK_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		}

		// HARD RULE, DragDrop-only, Edited Book/Journal Article/Website:
		// every Question Part AND every confusing word is now
		// Citex-authored, deterministically, from the record's own
		// canonical fields and its stored `dragdropPartKeys` selection —
		// via the SAME Citex_<Category>_Dragdrop_Parts::build() call used
		// at generation time — so, exactly like Book's own block above, the
		// whole {parts, fixedText, confusingWords} triple can be
		// recomputed from the record alone and compared exactly, rather
		// than merely sanity-checked. Records with no canonical data at
		// all (e.g. externally imported, pre-dating this feature) are
		// unaffected, mirroring Book's own identical skip condition.
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category && 'MLA' === $source ) {
			// MLA's own Edited Book DragDrop block — mirrors the MLA Book
			// block above exactly, via Citex_MLA_Edited_Book_Dragdrop_Parts
			// instead (surname/givenName, no `place`).
			$editors_for_shape = is_array( $question['editors'] ?? null ) ? array_values( $question['editors'] ) : array();
			$eb_title          = trim( (string) ( $question['bookTitle'] ?? '' ) );
			if ( ! ( empty( $editors_for_shape ) && '' === $eb_title ) ) {
				$selected_keys = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$eb_fields     = array(
					'year'      => trim( (string) ( $question['year'] ?? '' ) ),
					'title'     => $eb_title,
					'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
				);
				$expected_build = ( empty( $selected_keys ) || empty( $editors_for_shape ) ) ? null : Citex_MLA_Edited_Book_Dragdrop_Parts::build( $selected_keys, $editors_for_shape, $eb_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'MLA_EDITED_BOOK_DRAGDROP_PARTS_UNKNOWN', 'The MLA Edited Book DragDrop part selection (dragdropPartKeys) is missing, malformed, or names an editor index out of range.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'MLA_EDITED_BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'MLA_EDITED_BOOK_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'MLA_EDITED_BOOK_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		} elseif ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category && 'APA' === $source ) {
			// APA's own Edited Book DragDrop block — mirrors the MLA Edited
			// Book block above exactly, via Citex_APA_Edited_Book_Dragdrop_Parts
			// instead (surname/initials, "(Ed.)"/"(Eds.)" designation, no
			// `place`).
			$editors_for_shape = is_array( $question['editors'] ?? null ) ? array_values( $question['editors'] ) : array();
			$eb_title          = trim( (string) ( $question['bookTitle'] ?? '' ) );
			if ( ! ( empty( $editors_for_shape ) && '' === $eb_title ) ) {
				$selected_keys = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$eb_fields     = array(
					'year'      => trim( (string) ( $question['year'] ?? '' ) ),
					'title'     => $eb_title,
					'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
				);
				$expected_build = ( empty( $selected_keys ) || empty( $editors_for_shape ) ) ? null : Citex_APA_Edited_Book_Dragdrop_Parts::build( $selected_keys, $editors_for_shape, $eb_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'APA_EDITED_BOOK_DRAGDROP_PARTS_UNKNOWN', 'The APA Edited Book DragDrop part selection (dragdropPartKeys) is missing, malformed, or names an editor index out of range.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'APA_EDITED_BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'APA_EDITED_BOOK_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'APA_EDITED_BOOK_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		} elseif ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category && 'Chicago' === $source ) {
			// Chicago's own Edited Book DragDrop block — mirrors the APA
			// Edited Book block above, via
			// Citex_Chicago_Edited_Book_Dragdrop_Parts instead
			// (surname/givenName, "ed"/"eds" designation, WITH `place`
			// included — unlike APA/MLA's own Edited Book, both of which
			// dropped it).
			$editors_for_shape = is_array( $question['editors'] ?? null ) ? array_values( $question['editors'] ) : array();
			$eb_title          = trim( (string) ( $question['bookTitle'] ?? '' ) );
			if ( ! ( empty( $editors_for_shape ) && '' === $eb_title ) ) {
				$selected_keys = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$eb_fields     = array(
					'year'      => trim( (string) ( $question['year'] ?? '' ) ),
					'title'     => $eb_title,
					'place'     => trim( (string) ( $question['place'] ?? '' ) ),
					'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
				);
				$expected_build = ( empty( $selected_keys ) || empty( $editors_for_shape ) ) ? null : Citex_Chicago_Edited_Book_Dragdrop_Parts::build( $selected_keys, $editors_for_shape, $eb_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'CHICAGO_EDITED_BOOK_DRAGDROP_PARTS_UNKNOWN', 'The Chicago Edited Book DragDrop part selection (dragdropPartKeys) is missing, malformed, or names an editor index out of range.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'CHICAGO_EDITED_BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'CHICAGO_EDITED_BOOK_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'CHICAGO_EDITED_BOOK_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		} elseif ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
			$editors_for_shape = is_array( $question['editors'] ?? null ) ? array_values( $question['editors'] ) : array();
			$eb_title          = trim( (string) ( $question['bookTitle'] ?? '' ) );
			if ( ! ( empty( $editors_for_shape ) && '' === $eb_title ) ) {
				$selected_keys = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$eb_fields     = array(
					'year'      => trim( (string) ( $question['year'] ?? '' ) ),
					'title'     => $eb_title,
					'place'     => trim( (string) ( $question['place'] ?? '' ) ),
					'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
				);
				$expected_build = ( empty( $selected_keys ) || empty( $editors_for_shape ) ) ? null : Citex_Edited_Book_Dragdrop_Parts::build( $selected_keys, $editors_for_shape, $eb_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'EDITED_BOOK_DRAGDROP_PARTS_UNKNOWN', 'The Edited Book DragDrop part selection (dragdropPartKeys) is missing, malformed, or names an editor index out of range.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'EDITED_BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'EDITED_BOOK_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'EDITED_BOOK_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		}
		if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category && 'MLA' === $source ) {
			// MLA's own Journal Article DragDrop block — mirrors the Harvard
			// block below exactly, via Citex_MLA_Journal_Article_Dragdrop_Parts
			// instead (surname/givenName, double-quoted article title,
			// "vol."/"no." labels).
			$ja_authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
			$ja_title   = trim( (string) ( $question['articleTitle'] ?? '' ) );
			if ( ! ( empty( $ja_authors ) && '' === $ja_title ) ) {
				$selected_keys = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$ja_fields     = array(
					'articleTitle' => $ja_title,
					'journalTitle' => trim( (string) ( $question['journalTitle'] ?? '' ) ),
					'volume'       => trim( (string) ( $question['volume'] ?? '' ) ),
					'issue'        => trim( (string) ( $question['issue'] ?? '' ) ),
					'year'         => trim( (string) ( $question['year'] ?? '' ) ),
					'pages'        => trim( (string) ( $question['pages'] ?? '' ) ),
				);
				$expected_build = ( empty( $selected_keys ) || empty( $ja_authors ) ) ? null : Citex_MLA_Journal_Article_Dragdrop_Parts::build( $selected_keys, $ja_authors, $ja_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'MLA_JOURNAL_ARTICLE_DRAGDROP_PARTS_UNKNOWN', 'The MLA Journal Article DragDrop part selection (dragdropPartKeys) is missing, malformed, or names an author index out of range.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'MLA_JOURNAL_ARTICLE_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'MLA_JOURNAL_ARTICLE_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'MLA_JOURNAL_ARTICLE_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		} elseif ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category && 'APA' === $source ) {
			// APA's own Journal Article DragDrop block — mirrors the MLA
			// Journal Article block above exactly, via
			// Citex_APA_Journal_Article_Dragdrop_Parts instead (surname/
			// initials, no quotes around the article title, no "pp." prefix).
			$ja_authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
			if ( empty( $ja_authors ) && '' !== trim( (string) ( $question['authorSurname'] ?? '' ) ) ) {
				$ja_authors = array(
					array(
						'surname'  => trim( (string) ( $question['authorSurname'] ?? '' ) ),
						'initials' => trim( (string) ( $question['authorInitials'] ?? '' ) ),
						'fullName' => trim( (string) ( $question['authorFullName'] ?? '' ) ),
					),
				);
			}
			$ja_title = trim( (string) ( $question['articleTitle'] ?? '' ) );
			if ( ! ( empty( $ja_authors ) && '' === $ja_title ) ) {
				$selected_keys = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$ja_fields     = array(
					'year'         => trim( (string) ( $question['year'] ?? '' ) ),
					'articleTitle' => $ja_title,
					'journalTitle' => trim( (string) ( $question['journalTitle'] ?? '' ) ),
					'volume'       => trim( (string) ( $question['volume'] ?? '' ) ),
					'issue'        => trim( (string) ( $question['issue'] ?? '' ) ),
					'pages'        => trim( (string) ( $question['pages'] ?? '' ) ),
				);
				$expected_build = ( empty( $selected_keys ) || empty( $ja_authors ) ) ? null : Citex_APA_Journal_Article_Dragdrop_Parts::build( $selected_keys, $ja_authors, $ja_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'APA_JOURNAL_ARTICLE_DRAGDROP_PARTS_UNKNOWN', 'The APA Journal Article DragDrop part selection (dragdropPartKeys) is missing, malformed, or names an author index out of range.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'APA_JOURNAL_ARTICLE_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'APA_JOURNAL_ARTICLE_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'APA_JOURNAL_ARTICLE_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		} elseif ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category && 'Chicago' === $source ) {
			// Chicago's own Journal Article DragDrop block — mirrors the APA
			// Journal Article block above, via
			// Citex_Chicago_Journal_Article_Dragdrop_Parts instead
			// (surname/givenName, double-quoted article title, a colon
			// before the page range, no "pp." prefix).
			$ja_authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
			if ( empty( $ja_authors ) ) {
				$fallback_surname    = trim( (string) ( $question['authorSurname'] ?? '' ) );
				$fallback_given_name = trim( (string) ( $question['authorGivenName'] ?? '' ) );
				if ( '' !== $fallback_surname || '' !== $fallback_given_name ) {
					$ja_authors = array( array( 'surname' => $fallback_surname, 'givenName' => $fallback_given_name, 'fullName' => (string) ( $question['authorFullName'] ?? '' ) ) );
				}
			}
			$ja_title = trim( (string) ( $question['articleTitle'] ?? '' ) );
			if ( ! ( empty( $ja_authors ) && '' === $ja_title ) ) {
				$selected_keys = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$ja_fields     = array(
					'year'         => trim( (string) ( $question['year'] ?? '' ) ),
					'articleTitle' => $ja_title,
					'journalTitle' => trim( (string) ( $question['journalTitle'] ?? '' ) ),
					'volume'       => trim( (string) ( $question['volume'] ?? '' ) ),
					'issue'        => trim( (string) ( $question['issue'] ?? '' ) ),
					'pages'        => trim( (string) ( $question['pages'] ?? '' ) ),
				);
				$expected_build = ( empty( $selected_keys ) || empty( $ja_authors ) ) ? null : Citex_Chicago_Journal_Article_Dragdrop_Parts::build( $selected_keys, $ja_authors, $ja_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'CHICAGO_JOURNAL_ARTICLE_DRAGDROP_PARTS_UNKNOWN', 'The Chicago Journal Article DragDrop part selection (dragdropPartKeys) is missing, malformed, or names an author index out of range.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'CHICAGO_JOURNAL_ARTICLE_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'CHICAGO_JOURNAL_ARTICLE_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'CHICAGO_JOURNAL_ARTICLE_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		} elseif ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
			$ja_authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
			if ( empty( $ja_authors ) && '' !== trim( (string) ( $question['authorSurname'] ?? '' ) ) ) {
				$ja_authors = array(
					array(
						'surname'  => trim( (string) ( $question['authorSurname'] ?? '' ) ),
						'initials' => trim( (string) ( $question['authorInitials'] ?? '' ) ),
						'fullName' => trim( (string) ( $question['authorFullName'] ?? '' ) ),
					),
				);
			}
			$ja_title = trim( (string) ( $question['articleTitle'] ?? '' ) );
			if ( ! ( empty( $ja_authors ) && '' === $ja_title ) ) {
				$selected_keys = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$ja_fields     = array(
					'year'         => trim( (string) ( $question['year'] ?? '' ) ),
					'articleTitle' => $ja_title,
					'journalTitle' => trim( (string) ( $question['journalTitle'] ?? '' ) ),
					'volume'       => trim( (string) ( $question['volume'] ?? '' ) ),
					'issue'        => trim( (string) ( $question['issue'] ?? '' ) ),
					'pages'        => trim( (string) ( $question['pages'] ?? '' ) ),
				);
				$expected_build = ( empty( $selected_keys ) || empty( $ja_authors ) ) ? null : Citex_Journal_Article_Dragdrop_Parts::build( $selected_keys, $ja_authors, $ja_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'JOURNAL_ARTICLE_DRAGDROP_PARTS_UNKNOWN', 'The Journal Article DragDrop part selection (dragdropPartKeys) is missing, malformed, or names an author index out of range.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'JOURNAL_ARTICLE_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'JOURNAL_ARTICLE_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'JOURNAL_ARTICLE_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		}
		if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category && 'MLA' === $source ) {
			// MLA's own Website DragDrop block — mirrors the Harvard block
			// below exactly, via Citex_MLA_Website_Dragdrop_Parts instead:
			// surname/givenName, no `publisher`, and `year` genuinely
			// optional (no "n.d." convention at all — see
			// Citex_MLA_Website_Dragdrop_Parts's own docblock).
			$web_author_type = (string) ( $question['authorType'] ?? '' );
			$web_authors_arr = is_array( $question['authors'] ?? null ) ? $question['authors'] : array();
			$web_title       = trim( (string) ( $question['pageTitle'] ?? '' ) );
			$has_web_author  = 'individual' === $web_author_type
				? '' !== trim( (string) ( $web_authors_arr[0]['surname'] ?? '' ) )
				: ( 'organisation' === $web_author_type && '' !== trim( (string) ( $question['organisationName'] ?? '' ) ) );
			if ( ! ( ! $has_web_author && '' === $web_title ) ) {
				$web_author = array( 'type' => $web_author_type );
				if ( 'individual' === $web_author_type ) {
					$web_author['fullName']  = trim( (string) ( $web_authors_arr[0]['fullName'] ?? '' ) );
					$web_author['surname']   = trim( (string) ( $web_authors_arr[0]['surname'] ?? '' ) );
					$web_author['givenName'] = trim( (string) ( $web_authors_arr[0]['givenName'] ?? '' ) );
				} else {
					$web_author['name'] = trim( (string) ( $question['organisationName'] ?? '' ) );
				}
				$web_year   = trim( (string) ( $question['year'] ?? '' ) );
				$web_fields = array(
					'year'         => $web_year,
					'title'        => $web_title,
					'url'          => trim( (string) ( $question['url'] ?? '' ) ),
					'accessedDate' => trim( (string) ( $question['accessedDate'] ?? '' ) ),
				);
				$selected_keys  = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$expected_build = ( empty( $selected_keys ) || ! $has_web_author ) ? null : Citex_MLA_Website_Dragdrop_Parts::build( $selected_keys, $web_author, $web_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'MLA_WEBSITE_DRAGDROP_PARTS_UNKNOWN', 'The MLA Website DragDrop part selection (dragdropPartKeys) is missing or malformed.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'MLA_WEBSITE_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'MLA_WEBSITE_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'MLA_WEBSITE_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		} elseif ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category && 'APA' === $source ) {
			// APA's own Website DragDrop block — mirrors the MLA Website
			// block above, via Citex_APA_Website_Dragdrop_Parts instead:
			// surname/initials, no `accessedDate`/`publisher` in the built
			// record, and `year` always present (either digits or the
			// literal "n.d." — see Citex_APA_Website_Dragdrop_Parts's own
			// docblock).
			$web_author_type = (string) ( $question['authorType'] ?? '' );
			$web_authors_arr = is_array( $question['authors'] ?? null ) ? $question['authors'] : array();
			$web_title       = trim( (string) ( $question['pageTitle'] ?? '' ) );
			$has_web_author  = 'individual' === $web_author_type
				? '' !== trim( (string) ( $web_authors_arr[0]['surname'] ?? '' ) )
				: ( 'organisation' === $web_author_type && '' !== trim( (string) ( $question['organisationName'] ?? '' ) ) );
			if ( ! ( ! $has_web_author && '' === $web_title ) ) {
				$web_author = array( 'type' => $web_author_type );
				if ( 'individual' === $web_author_type ) {
					$web_author['fullName'] = trim( (string) ( $web_authors_arr[0]['fullName'] ?? '' ) );
					$web_author['surname']  = trim( (string) ( $web_authors_arr[0]['surname'] ?? '' ) );
					$web_author['initials'] = trim( (string) ( $web_authors_arr[0]['initials'] ?? '' ) );
				} else {
					$web_author['name'] = trim( (string) ( $question['organisationName'] ?? '' ) );
				}
				$web_fields = array(
					'year'  => trim( (string) ( $question['year'] ?? '' ) ),
					'title' => $web_title,
					'url'   => trim( (string) ( $question['url'] ?? '' ) ),
				);
				$selected_keys  = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$expected_build = ( empty( $selected_keys ) || ! $has_web_author ) ? null : Citex_APA_Website_Dragdrop_Parts::build( $selected_keys, $web_author, $web_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'APA_WEBSITE_DRAGDROP_PARTS_UNKNOWN', 'The APA Website DragDrop part selection (dragdropPartKeys) is missing or malformed.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'APA_WEBSITE_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'APA_WEBSITE_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'APA_WEBSITE_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		} elseif ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category && 'Chicago' === $source ) {
			// Chicago's own Website DragDrop block — mirrors the APA Website
			// block above, via Citex_Chicago_Website_Dragdrop_Parts instead:
			// surname/givenName, a double-quoted title, no
			// `accessedDate`/`publisher` in the built record, and `year`
			// always present (either digits or the literal "n.d.").
			$web_author_type = (string) ( $question['authorType'] ?? '' );
			$web_authors_arr = is_array( $question['authors'] ?? null ) ? $question['authors'] : array();
			$web_title       = trim( (string) ( $question['pageTitle'] ?? '' ) );
			$has_web_author  = 'individual' === $web_author_type
				? '' !== trim( (string) ( $web_authors_arr[0]['surname'] ?? '' ) )
				: ( 'organisation' === $web_author_type && '' !== trim( (string) ( $question['organisationName'] ?? '' ) ) );
			if ( ! ( ! $has_web_author && '' === $web_title ) ) {
				$web_author = array( 'type' => $web_author_type );
				if ( 'individual' === $web_author_type ) {
					$web_author['fullName']  = trim( (string) ( $web_authors_arr[0]['fullName'] ?? '' ) );
					$web_author['surname']   = trim( (string) ( $web_authors_arr[0]['surname'] ?? '' ) );
					$web_author['givenName'] = trim( (string) ( $web_authors_arr[0]['givenName'] ?? '' ) );
				} else {
					$web_author['name'] = trim( (string) ( $question['organisationName'] ?? '' ) );
				}
				$web_fields = array(
					'year'  => trim( (string) ( $question['year'] ?? '' ) ),
					'title' => $web_title,
					'url'   => trim( (string) ( $question['url'] ?? '' ) ),
				);
				$selected_keys  = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$expected_build = ( empty( $selected_keys ) || ! $has_web_author ) ? null : Citex_Chicago_Website_Dragdrop_Parts::build( $selected_keys, $web_author, $web_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'CHICAGO_WEBSITE_DRAGDROP_PARTS_UNKNOWN', 'The Chicago Website DragDrop part selection (dragdropPartKeys) is missing or malformed.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'CHICAGO_WEBSITE_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'CHICAGO_WEBSITE_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'CHICAGO_WEBSITE_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		} elseif ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
			$web_author_type = (string) ( $question['authorType'] ?? '' );
			$web_authors_arr = is_array( $question['authors'] ?? null ) ? $question['authors'] : array();
			$web_title       = trim( (string) ( $question['pageTitle'] ?? '' ) );
			$has_web_author  = 'individual' === $web_author_type
				? '' !== trim( (string) ( $web_authors_arr[0]['surname'] ?? '' ) )
				: ( 'organisation' === $web_author_type && '' !== trim( (string) ( $question['organisationName'] ?? '' ) ) );
			if ( ! ( ! $has_web_author && '' === $web_title ) ) {
				$web_author = array( 'type' => $web_author_type );
				if ( 'individual' === $web_author_type ) {
					$web_author['fullName'] = trim( (string) ( $web_authors_arr[0]['fullName'] ?? '' ) );
					$web_author['surname']  = trim( (string) ( $web_authors_arr[0]['surname'] ?? '' ) );
					$web_author['initials'] = trim( (string) ( $web_authors_arr[0]['initials'] ?? '' ) );
				} else {
					$web_author['name'] = trim( (string) ( $question['organisationName'] ?? '' ) );
				}
				$web_fields = array(
					'year'         => trim( (string) ( $question['year'] ?? '' ) ),
					'title'        => $web_title,
					'publisher'    => trim( (string) ( $question['publisher'] ?? '' ) ),
					'url'          => trim( (string) ( $question['url'] ?? '' ) ),
					'accessedDate' => trim( (string) ( $question['accessedDate'] ?? '' ) ),
				);
				$selected_keys  = is_array( $question['dragdropPartKeys'] ?? null ) ? array_values( $question['dragdropPartKeys'] ) : array();
				$expected_build = ( empty( $selected_keys ) || ! $has_web_author ) ? null : Citex_Website_Dragdrop_Parts::build( $selected_keys, $web_author, $web_fields );
				if ( null === $expected_build ) {
					$errors[] = self::error( 'WEBSITE_DRAGDROP_PARTS_UNKNOWN', 'The Website DragDrop part selection (dragdropPartKeys) is missing or malformed.' );
				} else {
					if ( $fixed_text !== $expected_build['fixedText'] ) {
						$errors[] = self::error( 'WEBSITE_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
					}
					if ( $question_parts !== $expected_build['parts'] ) {
						$errors[] = self::error( 'WEBSITE_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
					}
					if ( $confusing !== $expected_build['confusingWords'] ) {
						$errors[] = self::error( 'WEBSITE_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
					}
				}
			}
		}

		$reconstruction = self::reconstruct( $fixed_text, $question_parts );
		if ( is_wp_error( $reconstruction ) ) {
			$errors[] = self::error( $reconstruction->get_error_code(), $reconstruction->get_error_message() );
			return self::result( 'failed', $errors, null );
		}

		// Journal Article's own "exercise design" (see Citex_Reference_Rules::
		// journal_article_dragdrop_shape()'s docblock) — null for every other
		// category, which never reads it. Defaults to 'full_reference' for a
		// Journal Article record with no exerciseDesign field at all (any
		// record predating this feature), preserving its exact prior
		// behaviour.
		$exercise_design = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category
			? (string) ( $question['exerciseDesign'] ?? 'full_reference' )
			: null;

		$reference = $reconstruction['reference'];
		$style     = 'MLA' === $source ? 'mla' : ( 'APA' === $source ? 'apa' : ( 'Chicago' === $source ? 'chicago' : ( 'MHRA' === $source ? 'mhra' : 'harvard' ) ) );
		$errors    = array_merge( $errors, self::validate_reference_format( $reference, $category, $question['place'] ?? null, $question['publisher'] ?? null, self::expected_designation_for( $question, $category ), self::expected_editor_join_for( $question, $category ), $exercise_design, $style ) );

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
		$is_mla     = 'MLA' === (string) ( $question['source'] ?? '' );
		$is_apa     = 'APA' === (string) ( $question['source'] ?? '' );
		$is_chicago = 'Chicago' === (string) ( $question['source'] ?? '' );
		$is_mhra    = 'MHRA' === (string) ( $question['source'] ?? '' );
		if ( Citex_Reference_Rules::CATEGORY_BOOK === $category && $is_mla ) {
			return self::validate_mla_bibliographic_consistency( $question, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_BOOK === $category && $is_apa ) {
			return self::validate_apa_bibliographic_consistency( $question, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_BOOK === $category && $is_chicago ) {
			return self::validate_chicago_bibliographic_consistency( $question, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_BOOK === $category && $is_mhra ) {
			return self::validate_mhra_bibliographic_consistency( $question, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category && $is_mla ) {
			return self::validate_mla_edited_book_consistency( $question, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category && $is_apa ) {
			return self::validate_apa_edited_book_consistency( $question, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category && $is_chicago ) {
			return self::validate_chicago_edited_book_consistency( $question, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
			return self::validate_edited_book_consistency( $question, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category && $is_mla ) {
			return self::validate_mla_journal_article_consistency( $question, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category && $is_apa ) {
			return self::validate_apa_journal_article_consistency( $question, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category && $is_chicago ) {
			return self::validate_chicago_journal_article_consistency( $question, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return self::validate_journal_article_consistency( $question, $question_parts, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category && $is_mla ) {
			return self::validate_mla_website_consistency( $question, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category && $is_apa ) {
			return self::validate_apa_website_consistency( $question, $reference, $check_scenario );
		}
		if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category && $is_chicago ) {
			return self::validate_chicago_website_consistency( $question, $reference, $check_scenario );
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
		// See validate_dragdrop()'s matching comment — null for every
		// category except Journal Article.
		$exercise_design = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category
			? (string) ( $question['exerciseDesign'] ?? 'full_reference' )
			: null;
		$reference    = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $reference ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$errors = array_merge( $errors, self::validate_reference_format( $reference, $category, $place, $publisher, $designation, $editor_join, $exercise_design ) );

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
			// the ambiguity a real MCQ must never contain. SKIPPED for every
			// Journal Article short "identify the correct VALUE" partial
			// design (anything other than 'full_reference'): their whole
			// point is comparing several equally well-FORMATTED candidates
			// (e.g. "Brown, B." vs "Brown, S." — both genuinely valid
			// "Surname, I." shapes) and asking which one is the real,
			// correct VALUE — a same-shape distractor there is the
			// intended, correct kind of distractor, not an ambiguity bug.
			// This check keeps its full meaning for 'full_reference' (and
			// every other category), where a real distractor is expected to
			// contain a genuine Harvard RULE violation, not just a
			// different value.
			$skip_distractor_looks_correct = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category
				&& 'full_reference' !== $exercise_design;
			if ( ! $skip_distractor_looks_correct && empty( self::validate_reference_format( $option_text, $category, $place, $publisher, $designation, $editor_join, $exercise_design ) ) ) {
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
				),
				$exercise_design
			)['parts'];
		} elseif ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
			// Website MCQ always carries mcqPattern === 'website_mcq_variant'
			// (see Citex_AI_V2::normalise_website_mcq_variant_item()) and so
			// is always routed to validate_website_mcq_variant() before ever
			// reaching this generic fallback (see validate()'s dispatch) —
			// this branch exists only so a malformed record with no
			// recognised mcqPattern still validates without crashing.
			// validate_website_consistency() no longer uses $question_parts
			// at all, so there is nothing meaningful to build here.
			$question_parts = array();
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
		$expected_stem = Citex_Reference_Rules::mcq_question_stem( $category, $exercise_design );
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

		// A reference/statement long enough to genuinely BE the answer
		// (not just a common word that must appear in any topically
		// relevant hint) — e.g. MLA Edited Book's own
		// 'designation_singular_plural' MCQ variant has a bare "editor"/
		// "editors" as its entire correct answer, and no hint could ever
		// explain that rule without using the very word it tests. 10
		// characters comfortably exceeds any such bare designation/
		// convention word while staying well below every real reference
		// or error-description answer's own length.
		if ( strlen( trim( (string) $reference ) ) > 10 && self::text_contains( $hint, $reference ) ) {
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
	 * Validates a Book MCQ question built from the user's own fixed
	 * 16-variant catalogue (Citex_Book_Mcq_Variants) — replaces the
	 * original "select the correct reference" mechanic for Book entirely
	 * (Edited Book/Journal Article/Website still use validate_mcq()).
	 *
	 * Unlike validate_mcq()/validate_choose_treatment() (which can only
	 * sanity-check Gemini-authored option/answer text), every option here —
	 * not just the stem/answer — is Citex-authored, deterministically, from
	 * the record's own canonical fields. So rather than checking the
	 * content is merely plausible, this recomputes the exact expected
	 * {stem, wrongOptions, correctAnswer} via Citex_Book_Mcq_Variants::build()
	 * from those same canonical fields and the record's own recorded
	 * `bookMcqVariant`, and requires an exact match against what is stored —
	 * any mismatch means the record was corrupted, hand-edited, or predates
	 * a Citex_Book_Mcq_Variants change, not a Gemini quality problem.
	 */
	private static function validate_book_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$variant = (string) ( $question['bookMcqVariant'] ?? '' );
		$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		if ( empty( $authors ) ) {
			$fallback_surname  = trim( (string) ( $question['authorSurname'] ?? '' ) );
			$fallback_initials = trim( (string) ( $question['authorInitials'] ?? '' ) );
			if ( '' !== $fallback_surname || '' !== $fallback_initials ) {
				$authors = array( array( 'surname' => $fallback_surname, 'initials' => $fallback_initials, 'fullName' => (string) ( $question['authorFullName'] ?? '' ) ) );
			}
		}
		$fields = array(
			'authors'   => $authors,
			'year'      => trim( (string) ( $question['year'] ?? '' ) ),
			'title'     => trim( (string) ( $question['bookTitle'] ?? '' ) ),
			'place'     => trim( (string) ( $question['place'] ?? '' ) ),
			'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
		);
		$expected = empty( $authors ) ? null : Citex_Book_Mcq_Variants::build( $variant, $fields );
		if ( null === $expected ) {
			$errors[] = self::error( 'BOOK_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised Book MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'BOOK_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'BOOK_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'BOOK_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * MLA counterpart to validate_book_mcq_variant() — same exact-match
	 * rationale (every option is Citex-authored, deterministically, from
	 * the canonical record via Citex_MLA_Book_Mcq_Variants, never
	 * Gemini's), reshaped for MLA's own author-name convention: a full
	 * given name (`givenName`), never Harvard's abbreviated `initials`,
	 * and no `place` field at all.
	 */
	private static function validate_mla_book_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$variant = (string) ( $question['mlaBookMcqVariant'] ?? '' );
		$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		if ( empty( $authors ) ) {
			$fallback_surname    = trim( (string) ( $question['authorSurname'] ?? '' ) );
			$fallback_given_name = trim( (string) ( $question['authorGivenName'] ?? '' ) );
			if ( '' !== $fallback_surname || '' !== $fallback_given_name ) {
				$authors = array( array( 'surname' => $fallback_surname, 'givenName' => $fallback_given_name, 'fullName' => (string) ( $question['authorFullName'] ?? '' ) ) );
			}
		}
		$fields = array(
			'authors'   => $authors,
			'year'      => trim( (string) ( $question['year'] ?? '' ) ),
			'title'     => trim( (string) ( $question['bookTitle'] ?? '' ) ),
			'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
		);
		$expected = empty( $authors ) ? null : Citex_MLA_Book_Mcq_Variants::build( $variant, $fields );
		if ( null === $expected ) {
			$errors[] = self::error( 'MLA_BOOK_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised MLA Book MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'MLA_BOOK_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'MLA_BOOK_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'MLA_BOOK_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * APA counterpart to validate_book_mcq_variant()/validate_mla_book_mcq_variant() —
	 * same exact-match rationale (every option is Citex-authored,
	 * deterministically, from the canonical record via
	 * Citex_APA_Book_Mcq_Variants, never Gemini's), reshaped for APA's own
	 * author-name convention: `initials` (the SAME field Harvard's own
	 * validate_book_mcq_variant() uses, never MLA's `givenName`), and no
	 * `place` field at all.
	 */
	private static function validate_apa_book_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$variant = (string) ( $question['apaBookMcqVariant'] ?? '' );
		$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		if ( empty( $authors ) ) {
			$fallback_surname  = trim( (string) ( $question['authorSurname'] ?? '' ) );
			$fallback_initials = trim( (string) ( $question['authorInitials'] ?? '' ) );
			if ( '' !== $fallback_surname || '' !== $fallback_initials ) {
				$authors = array( array( 'surname' => $fallback_surname, 'initials' => $fallback_initials, 'fullName' => (string) ( $question['authorFullName'] ?? '' ) ) );
			}
		}
		$fields = array(
			'authors'   => $authors,
			'year'      => trim( (string) ( $question['year'] ?? '' ) ),
			'title'     => trim( (string) ( $question['bookTitle'] ?? '' ) ),
			'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
		);
		$expected = empty( $authors ) ? null : Citex_APA_Book_Mcq_Variants::build( $variant, $fields );
		if ( null === $expected ) {
			$errors[] = self::error( 'APA_BOOK_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised APA Book MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'APA_BOOK_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'APA_BOOK_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'APA_BOOK_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * Chicago (Author-Date) counterpart to validate_book_mcq_variant()/
	 * validate_mla_book_mcq_variant()/validate_apa_book_mcq_variant() —
	 * same exact-match rationale (every option is Citex-authored,
	 * deterministically, from the canonical record via
	 * Citex_Chicago_Book_Mcq_Variants, never Gemini's), reshaped for
	 * Chicago's own author-name convention: `givenName` (the SAME field
	 * MLA's own validate_mla_book_mcq_variant() uses, never Harvard/APA's
	 * `initials`), WITH a `place` field (unlike MLA/APA).
	 */
	private static function validate_chicago_book_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$variant = (string) ( $question['chicagoBookMcqVariant'] ?? '' );
		$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		if ( empty( $authors ) ) {
			$fallback_surname    = trim( (string) ( $question['authorSurname'] ?? '' ) );
			$fallback_given_name = trim( (string) ( $question['authorGivenName'] ?? '' ) );
			if ( '' !== $fallback_surname || '' !== $fallback_given_name ) {
				$authors = array( array( 'surname' => $fallback_surname, 'givenName' => $fallback_given_name, 'fullName' => (string) ( $question['authorFullName'] ?? '' ) ) );
			}
		}
		$fields = array(
			'authors'   => $authors,
			'year'      => trim( (string) ( $question['year'] ?? '' ) ),
			'title'     => trim( (string) ( $question['bookTitle'] ?? '' ) ),
			'place'     => trim( (string) ( $question['place'] ?? '' ) ),
			'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
		);
		$expected = empty( $authors ) ? null : Citex_Chicago_Book_Mcq_Variants::build( $variant, $fields );
		if ( null === $expected ) {
			$errors[] = self::error( 'CHICAGO_BOOK_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised Chicago Book MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'CHICAGO_BOOK_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'CHICAGO_BOOK_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'CHICAGO_BOOK_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * Chicago counterpart to validate_chicago_book_mcq_variant(), for Edited
	 * Book — same exact-match rationale, via
	 * Citex_Chicago_Edited_Book_Mcq_Variants instead. Field shape mirrors
	 * normalise_chicago_edited_book_mcq_item()'s own output: `editors`
	 * array<{surname, givenName, fullName}>, WITH `place`.
	 */
	private static function validate_chicago_edited_book_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$variant = (string) ( $question['chicagoEditedBookMcqVariant'] ?? '' );
		$editors = is_array( $question['editors'] ?? null ) ? array_values( $question['editors'] ) : array();
		$fields  = array(
			'editors'   => $editors,
			'year'      => trim( (string) ( $question['year'] ?? '' ) ),
			'title'     => trim( (string) ( $question['bookTitle'] ?? '' ) ),
			'place'     => trim( (string) ( $question['place'] ?? '' ) ),
			'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
		);
		$expected = empty( $editors ) ? null : Citex_Chicago_Edited_Book_Mcq_Variants::build( $variant, $fields );
		if ( null === $expected ) {
			$errors[] = self::error( 'CHICAGO_EDITED_BOOK_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised Chicago Edited Book MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'CHICAGO_EDITED_BOOK_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'CHICAGO_EDITED_BOOK_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'CHICAGO_EDITED_BOOK_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * Chicago counterpart to validate_chicago_book_mcq_variant(), for
	 * Journal Article — via Citex_Chicago_Journal_Article_Mcq_Variants
	 * instead.
	 */
	private static function validate_chicago_journal_article_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$variant = (string) ( $question['chicagoJournalArticleMcqVariant'] ?? '' );
		$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		$fields  = array(
			'authors'      => $authors,
			'articleTitle' => trim( (string) ( $question['articleTitle'] ?? '' ) ),
			'journalTitle' => trim( (string) ( $question['journalTitle'] ?? '' ) ),
			'volume'       => trim( (string) ( $question['volume'] ?? '' ) ),
			'issue'        => trim( (string) ( $question['issue'] ?? '' ) ),
			'year'         => trim( (string) ( $question['year'] ?? '' ) ),
			'pages'        => trim( (string) ( $question['pages'] ?? '' ) ),
		);
		$expected = empty( $authors ) ? null : Citex_Chicago_Journal_Article_Mcq_Variants::build( $variant, $fields );
		if ( null === $expected ) {
			$errors[] = self::error( 'CHICAGO_JOURNAL_ARTICLE_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised Chicago Journal Article MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'CHICAGO_JOURNAL_ARTICLE_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'CHICAGO_JOURNAL_ARTICLE_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'CHICAGO_JOURNAL_ARTICLE_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * Chicago counterpart to validate_chicago_book_mcq_variant(), for
	 * Website — via Citex_Chicago_Website_Mcq_Variants instead. Field shape
	 * mirrors normalise_chicago_website_mcq_variant_item()'s own output:
	 * `authorType` plus `authors[0]`/`organisationName`, no `accessedDate`
	 * at all.
	 */
	private static function validate_chicago_website_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$variant     = (string) ( $question['chicagoWebsiteMcqVariant'] ?? '' );
		$author_type = (string) ( $question['authorType'] ?? '' );
		$authors_arr = is_array( $question['authors'] ?? null ) ? $question['authors'] : array();
		$author      = array( 'type' => $author_type );
		if ( 'individual' === $author_type ) {
			$author['fullName']  = trim( (string) ( $authors_arr[0]['fullName'] ?? '' ) );
			$author['surname']   = trim( (string) ( $authors_arr[0]['surname'] ?? '' ) );
			$author['givenName'] = trim( (string) ( $authors_arr[0]['givenName'] ?? '' ) );
		} elseif ( 'organisation' === $author_type ) {
			$author['name'] = trim( (string) ( $question['organisationName'] ?? '' ) );
		}
		$fields = array(
			'author' => $author,
			'year'   => trim( (string) ( $question['year'] ?? '' ) ),
			'title'  => trim( (string) ( $question['pageTitle'] ?? '' ) ),
			'url'    => trim( (string) ( $question['url'] ?? '' ) ),
		);
		$expected = in_array( $author_type, array( 'individual', 'organisation' ), true ) ? Citex_Chicago_Website_Mcq_Variants::build( $variant, $fields ) : null;
		if ( null === $expected ) {
			$errors[] = self::error( 'CHICAGO_WEBSITE_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised or incompatible Chicago Website MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'CHICAGO_WEBSITE_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'CHICAGO_WEBSITE_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'CHICAGO_WEBSITE_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * MHRA counterpart to validate_book_mcq_variant()/validate_chicago_book_mcq_variant() —
	 * same exact-match rationale (every option is Citex-authored,
	 * deterministically, from the canonical record via
	 * Citex_MHRA_Book_Mcq_Variants, never Gemini's), reshaped for MHRA's own
	 * author-name convention: `givenName` (the SAME field MLA's/Chicago's
	 * own validators use, never Harvard/APA's `initials`), WITH a `place`
	 * field.
	 */
	private static function validate_mhra_book_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$variant = (string) ( $question['mhraBookMcqVariant'] ?? '' );
		$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		if ( empty( $authors ) ) {
			$fallback_surname    = trim( (string) ( $question['authorSurname'] ?? '' ) );
			$fallback_given_name = trim( (string) ( $question['authorGivenName'] ?? '' ) );
			if ( '' !== $fallback_surname || '' !== $fallback_given_name ) {
				$authors = array( array( 'surname' => $fallback_surname, 'givenName' => $fallback_given_name, 'fullName' => (string) ( $question['authorFullName'] ?? '' ) ) );
			}
		}
		$fields = array(
			'authors'   => $authors,
			'year'      => trim( (string) ( $question['year'] ?? '' ) ),
			'title'     => trim( (string) ( $question['bookTitle'] ?? '' ) ),
			'place'     => trim( (string) ( $question['place'] ?? '' ) ),
			'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
		);
		$expected = empty( $authors ) ? null : Citex_MHRA_Book_Mcq_Variants::build( $variant, $fields );
		if ( null === $expected ) {
			$errors[] = self::error( 'MHRA_BOOK_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised MHRA Book MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'MHRA_BOOK_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'MHRA_BOOK_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'MHRA_BOOK_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * APA counterpart to validate_apa_book_mcq_variant(), for Edited Book —
	 * same exact-match rationale, via Citex_APA_Edited_Book_Mcq_Variants
	 * instead. Field shape mirrors normalise_apa_edited_book_mcq_item()'s
	 * own output: `editors` array<{surname, initials, fullName}>, no
	 * `place`.
	 */
	private static function validate_apa_edited_book_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$variant = (string) ( $question['apaEditedBookMcqVariant'] ?? '' );
		$editors = is_array( $question['editors'] ?? null ) ? array_values( $question['editors'] ) : array();
		$fields  = array(
			'editors'   => $editors,
			'year'      => trim( (string) ( $question['year'] ?? '' ) ),
			'title'     => trim( (string) ( $question['bookTitle'] ?? '' ) ),
			'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
		);
		$expected = empty( $editors ) ? null : Citex_APA_Edited_Book_Mcq_Variants::build( $variant, $fields );
		if ( null === $expected ) {
			$errors[] = self::error( 'APA_EDITED_BOOK_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised APA Edited Book MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'APA_EDITED_BOOK_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'APA_EDITED_BOOK_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'APA_EDITED_BOOK_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * APA counterpart to validate_apa_book_mcq_variant(), for Journal
	 * Article — via Citex_APA_Journal_Article_Mcq_Variants instead.
	 */
	private static function validate_apa_journal_article_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$variant = (string) ( $question['apaJournalArticleMcqVariant'] ?? '' );
		$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		$fields  = array(
			'authors'      => $authors,
			'articleTitle' => trim( (string) ( $question['articleTitle'] ?? '' ) ),
			'journalTitle' => trim( (string) ( $question['journalTitle'] ?? '' ) ),
			'volume'       => trim( (string) ( $question['volume'] ?? '' ) ),
			'issue'        => trim( (string) ( $question['issue'] ?? '' ) ),
			'year'         => trim( (string) ( $question['year'] ?? '' ) ),
			'pages'        => trim( (string) ( $question['pages'] ?? '' ) ),
		);
		$expected = empty( $authors ) ? null : Citex_APA_Journal_Article_Mcq_Variants::build( $variant, $fields );
		if ( null === $expected ) {
			$errors[] = self::error( 'APA_JOURNAL_ARTICLE_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised APA Journal Article MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'APA_JOURNAL_ARTICLE_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'APA_JOURNAL_ARTICLE_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'APA_JOURNAL_ARTICLE_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * APA counterpart to validate_apa_book_mcq_variant(), for Website — via
	 * Citex_APA_Website_Mcq_Variants instead. Field shape mirrors
	 * normalise_apa_website_mcq_variant_item()'s own output: `authorType`
	 * plus `authors[0]`/`organisationName`, no `accessedDate` at all.
	 */
	private static function validate_apa_website_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$variant     = (string) ( $question['apaWebsiteMcqVariant'] ?? '' );
		$author_type = (string) ( $question['authorType'] ?? '' );
		$authors_arr = is_array( $question['authors'] ?? null ) ? $question['authors'] : array();
		$author      = array( 'type' => $author_type );
		if ( 'individual' === $author_type ) {
			$author['fullName'] = trim( (string) ( $authors_arr[0]['fullName'] ?? '' ) );
			$author['surname']  = trim( (string) ( $authors_arr[0]['surname'] ?? '' ) );
			$author['initials'] = trim( (string) ( $authors_arr[0]['initials'] ?? '' ) );
		} elseif ( 'organisation' === $author_type ) {
			$author['name'] = trim( (string) ( $question['organisationName'] ?? '' ) );
		}
		$fields = array(
			'author' => $author,
			'year'   => trim( (string) ( $question['year'] ?? '' ) ),
			'title'  => trim( (string) ( $question['pageTitle'] ?? '' ) ),
			'url'    => trim( (string) ( $question['url'] ?? '' ) ),
		);
		$expected = in_array( $author_type, array( 'individual', 'organisation' ), true ) ? Citex_APA_Website_Mcq_Variants::build( $variant, $fields ) : null;
		if ( null === $expected ) {
			$errors[] = self::error( 'APA_WEBSITE_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised or incompatible APA Website MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'APA_WEBSITE_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'APA_WEBSITE_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'APA_WEBSITE_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * MLA counterpart to validate_mla_book_mcq_variant(), for Edited Book —
	 * same exact-match rationale, via Citex_MLA_Edited_Book_Mcq_Variants
	 * instead. Field shape mirrors normalise_mla_edited_book_mcq_item()'s
	 * own output: `editors` array<{surname, givenName, fullName}>, no
	 * `place`.
	 */
	private static function validate_mla_edited_book_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$variant = (string) ( $question['mlaEditedBookMcqVariant'] ?? '' );
		$editors = is_array( $question['editors'] ?? null ) ? array_values( $question['editors'] ) : array();
		$fields  = array(
			'editors'   => $editors,
			'year'      => trim( (string) ( $question['year'] ?? '' ) ),
			'title'     => trim( (string) ( $question['bookTitle'] ?? '' ) ),
			'publisher' => trim( (string) ( $question['publisher'] ?? '' ) ),
		);
		$expected = empty( $editors ) ? null : Citex_MLA_Edited_Book_Mcq_Variants::build( $variant, $fields );
		if ( null === $expected ) {
			$errors[] = self::error( 'MLA_EDITED_BOOK_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised MLA Edited Book MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'MLA_EDITED_BOOK_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'MLA_EDITED_BOOK_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'MLA_EDITED_BOOK_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * MLA counterpart to validate_mla_book_mcq_variant(), for Journal
	 * Article — via Citex_MLA_Journal_Article_Mcq_Variants instead.
	 */
	private static function validate_mla_journal_article_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$variant = (string) ( $question['mlaJournalArticleMcqVariant'] ?? '' );
		$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		$fields  = array(
			'authors'      => $authors,
			'articleTitle' => trim( (string) ( $question['articleTitle'] ?? '' ) ),
			'journalTitle' => trim( (string) ( $question['journalTitle'] ?? '' ) ),
			'volume'       => trim( (string) ( $question['volume'] ?? '' ) ),
			'issue'        => trim( (string) ( $question['issue'] ?? '' ) ),
			'year'         => trim( (string) ( $question['year'] ?? '' ) ),
			'pages'        => trim( (string) ( $question['pages'] ?? '' ) ),
		);
		$expected = empty( $authors ) ? null : Citex_MLA_Journal_Article_Mcq_Variants::build( $variant, $fields );
		if ( null === $expected ) {
			$errors[] = self::error( 'MLA_JOURNAL_ARTICLE_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised MLA Journal Article MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'MLA_JOURNAL_ARTICLE_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'MLA_JOURNAL_ARTICLE_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'MLA_JOURNAL_ARTICLE_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * MLA counterpart to validate_mla_book_mcq_variant(), for Website — via
	 * Citex_MLA_Website_Mcq_Variants instead. Field shape mirrors
	 * normalise_mla_website_mcq_variant_item()'s own output: `authorType`
	 * plus `authors[0]`/`organisationName`, no `publisher`.
	 */
	private static function validate_mla_website_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$variant     = (string) ( $question['mlaWebsiteMcqVariant'] ?? '' );
		$author_type = (string) ( $question['authorType'] ?? '' );
		$authors_arr = is_array( $question['authors'] ?? null ) ? $question['authors'] : array();
		$author      = array( 'type' => $author_type );
		if ( 'individual' === $author_type ) {
			$author['fullName']  = trim( (string) ( $authors_arr[0]['fullName'] ?? '' ) );
			$author['surname']   = trim( (string) ( $authors_arr[0]['surname'] ?? '' ) );
			$author['givenName'] = trim( (string) ( $authors_arr[0]['givenName'] ?? '' ) );
		} elseif ( 'organisation' === $author_type ) {
			$author['name'] = trim( (string) ( $question['organisationName'] ?? '' ) );
		}
		$fields = array(
			'author'       => $author,
			'year'         => trim( (string) ( $question['year'] ?? '' ) ),
			'title'        => trim( (string) ( $question['pageTitle'] ?? '' ) ),
			'url'          => trim( (string) ( $question['url'] ?? '' ) ),
			'accessedDate' => trim( (string) ( $question['accessedDate'] ?? '' ) ),
		);
		$expected = in_array( $author_type, array( 'individual', 'organisation' ), true ) ? Citex_MLA_Website_Mcq_Variants::build( $variant, $fields ) : null;
		if ( null === $expected ) {
			$errors[] = self::error( 'MLA_WEBSITE_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised or incompatible MLA Website MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'MLA_WEBSITE_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'MLA_WEBSITE_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'MLA_WEBSITE_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * Recomputes the "who" (already-joined) text and the raw surname list
	 * from an in-text citation record's own canonical person fields —
	 * shared by validate_intext_dragdrop()/validate_intext_mcq_variant()
	 * and their MLA counterparts, since both need the exact same
	 * category-agnostic extraction normalise_intext_item() itself
	 * performs (see class-citex-ai-v2.php).
	 *
	 * Website stores a single individual-or-organisation author (see
	 * normalise_intext_item()'s own docblock); every other category
	 * stores a `authors`/`editors` person list.
	 *
	 * @return array{0: string, 1: string[], 2: bool} [who, surnames, ok] —
	 *         ok is false when the record's canonical person data is
	 *         missing/malformed, in which case who/surnames are meaningless.
	 */
	private static function intext_who_and_surnames( $question, $is_mla, $is_apa = false, $form = '' ) {
		$category = (string) ( $question['category'] ?? '' );
		if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
			$author_type = (string) ( $question['authorType'] ?? '' );
			if ( 'organisation' === $author_type ) {
				$name = trim( (string) ( $question['organisationName'] ?? '' ) );
				if ( '' === $name ) { return array( '', array(), false ); }
				$author_record = array( 'type' => 'organisation', 'name' => $name );
				$surnames      = array( $name );
			} elseif ( 'individual' === $author_type ) {
				$authors_arr = is_array( $question['authors'] ?? null ) ? $question['authors'] : array();
				$surname     = trim( (string) ( $authors_arr[0]['surname'] ?? '' ) );
				if ( '' === $surname ) { return array( '', array(), false ); }
				$author_record = array( 'type' => 'individual', 'surname' => $surname );
				$surnames      = array( $surname );
			} else {
				return array( '', array(), false );
			}
			if ( $is_mla ) {
				$who = Citex_MLA_Intext_Citation_Rules::display_person_or_org( $author_record );
			} elseif ( $is_apa ) {
				$who = Citex_APA_Intext_Citation_Rules::display_person_or_org( $author_record );
			} else {
				$who = Citex_Intext_Citation_Rules::display_person_or_org( $author_record );
			}
			return array( $who, $surnames, true );
		}
		$people_key = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ? 'editors' : 'authors';
		$people     = is_array( $question[ $people_key ] ?? null ) ? array_values( $question[ $people_key ] ) : array();
		if ( empty( $people ) ) { return array( '', array(), false ); }
		$surnames = array();
		foreach ( $people as $person ) {
			$surname = trim( (string) ( $person['surname'] ?? '' ) );
			if ( '' === $surname ) { return array( '', array(), false ); }
			$surnames[] = $surname;
		}
		if ( $is_mla ) {
			$who = Citex_MLA_Intext_Citation_Rules::join_people_intext( $people );
		} elseif ( $is_apa ) {
			$who = Citex_APA_Intext_Citation_Rules::join_people_intext( $people, Citex_APA_Intext_Citation_Rules::joiner_for_form( $form ) );
		} else {
			$who = Citex_Intext_Citation_Rules::join_people_intext( $people );
		}
		return array( $who, $surnames, true );
	}

	private static function apa_intext_full_sentence( $form, $who, $year, $clause, $page, $quote ) {
		if ( Citex_APA_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
			return Citex_APA_Intext_Citation_Rules::narrative_sentence( $who, $year, $clause );
		}
		if ( Citex_APA_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
			return Citex_APA_Intext_Citation_Rules::parenthetical_sentence( $who, $year, $clause );
		}
		return Citex_APA_Intext_Citation_Rules::parenthetical_quote_sentence( $who, $year, $page, $quote );
	}

	private static function intext_full_sentence( $form, $who, $year, $clause, $page, $quote ) {
		if ( Citex_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
			return Citex_Intext_Citation_Rules::narrative_sentence( $who, $year, $clause );
		}
		if ( Citex_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
			return Citex_Intext_Citation_Rules::parenthetical_sentence( $who, $year, $clause );
		}
		return Citex_Intext_Citation_Rules::parenthetical_quote_sentence( $who, $year, $page, $quote );
	}

	private static function mla_intext_full_sentence( $form, $who, $clause, $page, $quote ) {
		if ( Citex_MLA_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
			return Citex_MLA_Intext_Citation_Rules::narrative_sentence( $who, $clause, '' === $page ? null : $page );
		}
		if ( Citex_MLA_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
			return Citex_MLA_Intext_Citation_Rules::parenthetical_sentence( $who, $clause );
		}
		return Citex_MLA_Intext_Citation_Rules::parenthetical_quote_sentence( $who, $page, $quote );
	}

	/**
	 * In-text citation DragDrop — mirrors every other DragDrop validator's
	 * own "recompute from the record's own canonical fields via the SAME
	 * *_Dragdrop_Parts::build() call used at generation time, then
	 * exact-match" pattern, but for the genuinely different in-text data
	 * shape (no place/publisher/journal fields at all — see
	 * Citex_Intext_Dragdrop_Parts's own docblock). Dispatched on `group`
	 * === 'InTextCitation' from validate() itself, and internally on
	 * `source` for Harvard vs MLA.
	 */
	private static function validate_intext_dragdrop( $question ) {
		$errors = array();
		$source = (string) ( $question['source'] ?? '' );
		$is_mla = 'MLA' === $source;
		$is_apa = 'APA' === $source;
		$form   = (string) ( $question['citationForm'] ?? '' );

		$fixed_text     = (string) ( $question['fixedText'] ?? '' );
		$question_parts = is_array( $question['questionParts'] ?? null ) ? array_values( $question['questionParts'] ) : array();
		$confusing      = is_array( $question['confusingWords'] ?? null ) ? array_values( $question['confusingWords'] ) : array();

		if ( '' === trim( $fixed_text ) ) {
			$errors[] = self::error( 'FIXED_TEXT_MISSING', 'Fixed Text is missing.' );
		}
		if ( empty( $question_parts ) ) {
			$errors[] = self::error( 'QUESTION_PARTS_MISSING', 'Question Parts are missing.' );
		}

		list( $who, $surnames, $people_ok ) = self::intext_who_and_surnames( $question, $is_mla, $is_apa, $form );
		if ( ! $people_ok ) {
			$errors[] = self::error( 'INTEXT_PEOPLE_UNKNOWN', 'The in-text citation record is missing its author/editor/organisation data.' );
			return self::result( 'failed', $errors, null );
		}

		$clause = (string) ( $question['clause'] ?? '' );
		$quote  = (string) ( $question['quote'] ?? '' );
		$page   = (string) ( $question['page'] ?? '' );
		$year   = (string) ( $question['year'] ?? '' );

		if ( $is_mla ) {
			$expected_build = Citex_MLA_Intext_Dragdrop_Parts::build( $form, $who, $surnames, $clause, '' === $page ? null : $page, $quote );
		} elseif ( $is_apa ) {
			$expected_build = Citex_APA_Intext_Dragdrop_Parts::build( $form, $who, $surnames, $year, $clause, $page, $quote );
		} else {
			$expected_build = Citex_Intext_Dragdrop_Parts::build( $form, $who, $surnames, $year, $clause, '' === $page ? null : $page, $quote );
		}

		if ( null === $expected_build ) {
			$errors[] = self::error( 'INTEXT_DRAGDROP_PARTS_UNKNOWN', 'The in-text citation DragDrop parts could not be recomputed from the record.' );
		} else {
			if ( $fixed_text !== $expected_build['fixedText'] ) {
				$errors[] = self::error( 'INTEXT_DRAGDROP_FIXED_TEXT_MISMATCH', sprintf( 'Fixed Text must be exactly: "%s".', $expected_build['fixedText'] ) );
			}
			if ( $question_parts !== $expected_build['parts'] ) {
				$errors[] = self::error( 'INTEXT_DRAGDROP_PARTS_MISMATCH', 'Question Parts must be exactly Citex\'s own parts for this selection.' );
			}
			if ( $confusing !== $expected_build['confusingWords'] ) {
				$errors[] = self::error( 'INTEXT_DRAGDROP_CONFUSING_WORDS_MISMATCH', 'Confusing Words must be exactly Citex\'s own wrong chips for this selection.' );
			}
		}

		$reconstruction = self::reconstruct( $fixed_text, $question_parts );
		if ( is_wp_error( $reconstruction ) ) {
			$errors[] = self::error( $reconstruction->get_error_code(), $reconstruction->get_error_message() );
			return self::result( 'failed', $errors, null );
		}
		$reference = $reconstruction['reference'];

		if ( $is_mla ) {
			$expected_reference = self::mla_intext_full_sentence( $form, $who, $clause, $page, $quote );
		} elseif ( $is_apa ) {
			$expected_reference = self::apa_intext_full_sentence( $form, $who, $year, $clause, $page, $quote );
		} else {
			$expected_reference = self::intext_full_sentence( $form, $who, $year, $clause, $page, $quote );
		}
		if ( $reference !== $expected_reference ) {
			$errors[] = self::error( 'INTEXT_RECONSTRUCTED_REFERENCE_MISMATCH', sprintf( 'The reconstructed in-text citation must be exactly: "%s".', $expected_reference ) );
		}
		$expected_stored = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' !== $expected_stored && $expected_stored !== $reference ) {
			$errors[] = self::error( 'RECONSTRUCTED_REFERENCE_MISMATCH', 'The generated expected reference does not match the reference reconstructed from Fixed Text and Question Parts.' );
		}

		$errors = array_merge( $errors, self::validate_answer_leakage( $question ) );

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $reference );
	}

	/**
	 * Harvard in-text citation MCQ — mirrors validate_book_mcq_variant()'s
	 * own exact-match rationale, via Citex_Intext_Mcq_Variants and
	 * intext_who_and_surnames() instead.
	 */
	private static function validate_intext_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		list( $who, $surnames, $people_ok ) = self::intext_who_and_surnames( $question, false );
		if ( ! $people_ok ) {
			$errors[] = self::error( 'INTEXT_PEOPLE_UNKNOWN', 'The in-text citation record is missing its author/editor/organisation data.' );
			return self::result( 'failed', $errors, $correct_answer );
		}
		$variant = (string) ( $question['intextMcqVariant'] ?? '' );
		$fields  = array(
			'form'     => (string) ( $question['citationForm'] ?? '' ),
			'who'      => $who,
			'surnames' => $surnames,
			'year'     => (string) ( $question['year'] ?? '' ),
			'clause'   => (string) ( $question['clause'] ?? '' ),
			'page'     => (string) ( $question['page'] ?? '' ),
			'quote'    => (string) ( $question['quote'] ?? '' ),
		);
		$expected = Citex_Intext_Mcq_Variants::build( $variant, $fields );
		if ( null === $expected ) {
			$errors[] = self::error( 'INTEXT_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised in-text MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'INTEXT_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'INTEXT_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'INTEXT_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * MLA in-text citation MCQ — mirrors validate_intext_mcq_variant()
	 * exactly, via Citex_MLA_Intext_Mcq_Variants instead.
	 */
	private static function validate_mla_intext_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		list( $who, $surnames, $people_ok ) = self::intext_who_and_surnames( $question, true );
		if ( ! $people_ok ) {
			$errors[] = self::error( 'INTEXT_PEOPLE_UNKNOWN', 'The in-text citation record is missing its author/editor/organisation data.' );
			return self::result( 'failed', $errors, $correct_answer );
		}
		$variant = (string) ( $question['mlaIntextMcqVariant'] ?? '' );
		$fields  = array(
			'form'     => (string) ( $question['citationForm'] ?? '' ),
			'who'      => $who,
			'surnames' => $surnames,
			'clause'   => (string) ( $question['clause'] ?? '' ),
			'page'     => (string) ( $question['page'] ?? '' ),
			'quote'    => (string) ( $question['quote'] ?? '' ),
		);
		$expected = Citex_MLA_Intext_Mcq_Variants::build( $variant, $fields );
		if ( null === $expected ) {
			$errors[] = self::error( 'MLA_INTEXT_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised MLA in-text MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'MLA_INTEXT_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'MLA_INTEXT_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'MLA_INTEXT_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * APA in-text citation MCQ — mirrors validate_mla_intext_mcq_variant()'s
	 * own exact-match rationale, via Citex_APA_Intext_Mcq_Variants and
	 * intext_who_and_surnames() instead. `who` is recomputed with the
	 * correct form-dependent joiner ("&" or "and" — see
	 * Citex_APA_Intext_Citation_Rules::joiner_for_form()), never re-decided
	 * by this method.
	 */
	private static function validate_apa_intext_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$form = (string) ( $question['citationForm'] ?? '' );
		list( $who, $surnames, $people_ok ) = self::intext_who_and_surnames( $question, false, true, $form );
		if ( ! $people_ok ) {
			$errors[] = self::error( 'INTEXT_PEOPLE_UNKNOWN', 'The in-text citation record is missing its author/editor/organisation data.' );
			return self::result( 'failed', $errors, $correct_answer );
		}
		$variant = (string) ( $question['apaIntextMcqVariant'] ?? '' );
		$fields  = array(
			'form'     => $form,
			'who'      => $who,
			'surnames' => $surnames,
			'year'     => (string) ( $question['year'] ?? '' ),
			'clause'   => (string) ( $question['clause'] ?? '' ),
			'page'     => (string) ( $question['page'] ?? '' ),
			'quote'    => (string) ( $question['quote'] ?? '' ),
		);
		$expected = Citex_APA_Intext_Mcq_Variants::build( $variant, $fields );
		if ( null === $expected ) {
			$errors[] = self::error( 'APA_INTEXT_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised APA in-text MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'APA_INTEXT_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'APA_INTEXT_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option   = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'APA_INTEXT_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
	}

	/**
	 * Validates a Website MCQ question built from Citex_Website_Mcq_Variants
	 * — replaces the original "select the correct reference" mechanic for
	 * Website entirely, mirroring Book's own identical move (see
	 * validate_book_mcq_variant()'s docblock for the full "why an exact
	 * match, not a plausibility check" rationale — every option here is
	 * Citex-authored, deterministically, from the record's own canonical
	 * fields, not Gemini's).
	 */
	private static function validate_website_mcq_variant( $question ) {
		$errors  = array();
		$options = is_array( $question['options'] ?? null ) ? array_values( $question['options'] ) : array();

		if ( 4 !== count( $options ) ) {
			$errors[] = self::error( 'MCQ_OPTION_COUNT_MISMATCH', sprintf( 'Exactly 4 option slots are required (3 wrong options + 1 blank); %d were provided.', count( $options ) ) );
			return self::result( 'failed', $errors, null );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( '' === trim( (string) $options[ $i ] ) ) {
				$errors[] = self::error( 'MCQ_OPTION_EMPTY', sprintf( 'Option %d is empty; the first 3 options must each hold a wrong option.', $i + 1 ) );
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

		$correct_answer = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $correct_answer ) {
			$errors[] = self::error( 'MCQ_ANSWER_MISSING', 'The correct answer (reconstructedReference) is missing.' );
			return self::result( 'failed', $errors, null );
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_answer ) ) );
		foreach ( $options as $index => $option ) {
			$option_text = trim( (string) $option );
			if ( '' === $option_text ) {
				continue;
			}
			if ( strtolower( trim( preg_replace( '/\s+/', ' ', $option_text ) ) ) === $correct_normal ) {
				$errors[] = self::error(
					'MCQ_OPTION_MATCHES_ANSWER',
					sprintf( 'Option %d duplicates the correct answer — it must appear ONLY in the Answer field, never as an option.', $index + 1 )
				);
			}
		}

		$variant     = (string) ( $question['websiteMcqVariant'] ?? '' );
		$author_type = trim( (string) ( $question['authorType'] ?? '' ) );
		$author      = array( 'type' => $author_type );
		if ( 'individual' === $author_type ) {
			$authors_arr = is_array( $question['authors'] ?? null ) ? $question['authors'] : array();
			$author['surname']  = trim( (string) ( $authors_arr[0]['surname'] ?? '' ) );
			$author['initials'] = trim( (string) ( $authors_arr[0]['initials'] ?? '' ) );
			$author['fullName'] = trim( (string) ( $authors_arr[0]['fullName'] ?? '' ) );
		} elseif ( 'organisation' === $author_type ) {
			$author['name'] = trim( (string) ( $question['organisationName'] ?? '' ) );
		}
		$fields = array(
			'author'       => $author,
			'year'         => trim( (string) ( $question['year'] ?? '' ) ),
			'title'        => trim( (string) ( $question['pageTitle'] ?? '' ) ),
			'publisher'    => trim( (string) ( $question['publisher'] ?? '' ) ),
			'url'          => trim( (string) ( $question['url'] ?? '' ) ),
			'accessedDate' => trim( (string) ( $question['accessedDate'] ?? '' ) ),
		);
		$expected = ( 'individual' === $author_type || 'organisation' === $author_type )
			? Citex_Website_Mcq_Variants::build( $variant, $fields )
			: null;
		if ( null === $expected ) {
			$errors[] = self::error( 'WEBSITE_MCQ_VARIANT_UNKNOWN', sprintf( 'Unrecognised Website MCQ variant: "%s".', $variant ) );
			return self::result( 'failed', $errors, $correct_answer );
		}
		if ( trim( (string) ( $question['scenario'] ?? '' ) ) !== $expected['stem'] ) {
			$errors[] = self::error( 'WEBSITE_MCQ_VARIANT_STEM_MISMATCH', sprintf( 'The question text must be exactly: "%s".', $expected['stem'] ) );
		}
		if ( $correct_answer !== $expected['correctAnswer'] ) {
			$errors[] = self::error( 'WEBSITE_MCQ_VARIANT_ANSWER_MISMATCH', sprintf( 'The Answer field must be exactly Citex\'s own answer for this variant: "%s".', $expected['correctAnswer'] ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$actual_option = trim( (string) ( $options[ $i ] ?? '' ) );
			$expected_option = trim( (string) ( $expected['wrongOptions'][ $i ] ?? '' ) );
			if ( $actual_option !== $expected_option ) {
				$errors[] = self::error( 'WEBSITE_MCQ_VARIANT_OPTION_MISMATCH', sprintf( 'Option %1$d must be exactly Citex\'s own option for this variant: "%2$s".', $i + 1, $expected_option ) );
			}
		}

		if ( '' === trim( (string) ( $question['hint'] ?? '' ) ) ) {
			$errors[] = self::error( 'MCQ_HINT_MISSING', 'Hint is missing.' );
		} else {
			$errors = array_merge( $errors, self::validate_mcq_hint_safety( $question, $correct_answer ) );
		}

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $correct_answer );
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
		// MLA has no "(ed.)"/"(eds)" abbreviation at all (see
		// Citex_MLA_Reference_Rules::join_editors()'s own docblock), and
		// Chicago's own designation is unparenthesised lowercase "ed"/"eds"
		// sitting INSIDE the person segment (see
		// Citex_Chicago_Reference_Rules::designation_for_editor_count()'s
		// own docblock) — neither editor shape carries Harvard's `initials`
		// (both carry `givenName` instead), so this Harvard-only helper must
		// never run against either (it would otherwise emit a PHP warning
		// reading the missing `initials` key several calls downstream, even
		// though the resulting check is a harmless no-op either way).
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK !== $category || in_array( (string) ( $question['source'] ?? '' ), array( 'MLA', 'Chicago' ), true ) ) {
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
		// Same MLA/Chicago guard as expected_designation_for() — both
		// styles' editor records carry `givenName`, not Harvard's
		// `initials`, and each style's own joining rule
		// (Citex_MLA_Reference_Rules::join_people()/
		// Citex_Chicago_Reference_Rules::join_people()) is already fully
		// enforced by the exact-match reconstruction checks elsewhere, so
		// this Harvard-only helper has nothing to add for either and must
		// never read the missing `initials` key.
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK !== $category || in_array( (string) ( $question['source'] ?? '' ), array( 'MLA', 'Chicago' ), true ) ) {
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
	private static function validate_reference_format( $reference, $category = null, $place = null, $publisher = null, $expected_designation = null, $expected_editor_join = null, $exercise_design = null, $style = 'harvard' ) {
		$category = $category ?? Citex_Reference_Rules::CATEGORY_BOOK;
		$errors   = array();

		if ( preg_match( '/\(\s+\d{4}|\d{4}\s+\)/', $reference ) ) {
			$errors[] = self::error( 'YEAR_PARENTHESES_SPACING', 'Publication year should have no spaces inside the parentheses, for example (2019).' );
		}
		// APA is the one style where a full stop immediately after the
		// year's closing parenthesis is CORRECT (see
		// Citex_APA_Reference_Rules's own docblock) — the opposite of
		// Harvard/MLA, where that same full stop is a genuine mistake. This
		// check must never fire for 'apa', or every valid APA reference
		// would be wrongly flagged.
		if ( 'apa' !== $style && preg_match( '/\(\d{4}\)\./', $reference ) ) {
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
		// Journal Article's 'journal_volume_issue' design deliberately
		// reconstructs a FRAGMENT that stops mid-reference ("Journal title,
		// Volume(Issue)", with no full stop before ", pp.Start-End." which
		// follows it in the real reference) — that must never be flagged
		// for lacking a final full
		// stop. See Citex_Reference_Rules::journal_article_design_skips_final_period().
		$skip_final_period_check = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category
			&& Citex_Reference_Rules::journal_article_design_skips_final_period( $exercise_design );
		if ( ! $skip_final_period_check && ! preg_match( '/\.\s*$/', $reference ) ) {
			$errors[] = self::error( 'MISSING_FINAL_PERIOD', 'Missing final full stop.' );
		}

		// Harvard shape for this category — Surname, I. (Year) Title.
		// Place: Publisher. for Book; Editor(s), I. (ed.|eds) (Year) Title.
		// Place: Publisher. for Edited Book. $exercise_design only ever
		// affects Journal Article — every other category ignores it. MLA
		// uses its own shape entirely (Citex_MLA_Reference_Rules' own
		// format_regex(), never Harvard's) — dispatched purely on $style,
		// for ANY category, since Citex_MLA_Reference_Rules::format_regex()
		// already dispatches internally by category itself.
		$is_mla       = 'mla' === $style;
		$is_apa       = 'apa' === $style;
		$is_chicago   = 'chicago' === $style;
		$is_mhra      = 'mhra' === $style;
		$format_regex = $is_mla
			? Citex_MLA_Reference_Rules::format_regex( $category )
			: ( $is_apa ? Citex_APA_Reference_Rules::format_regex( $category ) : ( $is_chicago ? Citex_Chicago_Reference_Rules::format_regex( $category ) : ( $is_mhra ? Citex_MHRA_Reference_Rules::format_regex( $category ) : Citex_Reference_Rules::format_regex( $category, $exercise_design ) ) ) );
		if ( ! preg_match( $format_regex, $reference ) ) {
			if ( $is_mhra ) {
				$code    = 'MHRA_BOOK_FORMAT_MISMATCH';
				$message = 'Citation does not match the MHRA Bibliography Book format.';
			} elseif ( $is_chicago && Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
				$code    = 'CHICAGO_EDITED_BOOK_FORMAT_MISMATCH';
				$message = 'Citation does not match the Chicago (Author-Date) Edited Book format.';
			} elseif ( $is_chicago && Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
				$code    = 'CHICAGO_JOURNAL_ARTICLE_FORMAT_MISMATCH';
				$message = 'Citation does not match the Chicago (Author-Date) Journal Article format.';
			} elseif ( $is_chicago && Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
				$code    = 'CHICAGO_WEBSITE_FORMAT_MISMATCH';
				$message = 'Citation does not match the Chicago (Author-Date) Website format.';
			} elseif ( $is_chicago ) {
				$code    = 'CHICAGO_BOOK_FORMAT_MISMATCH';
				$message = 'Citation does not match the Chicago (Author-Date) Book format.';
			} elseif ( $is_mla && Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
				$code    = 'MLA_EDITED_BOOK_FORMAT_MISMATCH';
				$message = 'Citation does not match the MLA Edited Book format.';
			} elseif ( $is_mla && Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
				$code    = 'MLA_JOURNAL_ARTICLE_FORMAT_MISMATCH';
				$message = 'Citation does not match the MLA Journal Article format.';
			} elseif ( $is_mla && Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
				$code    = 'MLA_WEBSITE_FORMAT_MISMATCH';
				$message = 'Citation does not match the MLA Website format.';
			} elseif ( $is_mla ) {
				$code    = 'MLA_BOOK_FORMAT_MISMATCH';
				$message = 'Citation does not match the MLA Book format.';
			} elseif ( $is_apa && Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
				$code    = 'APA_EDITED_BOOK_FORMAT_MISMATCH';
				$message = 'Citation does not match the APA Edited Book format.';
			} elseif ( $is_apa && Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
				$code    = 'APA_JOURNAL_ARTICLE_FORMAT_MISMATCH';
				$message = 'Citation does not match the APA Journal Article format.';
			} elseif ( $is_apa && Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
				$code    = 'APA_WEBSITE_FORMAT_MISMATCH';
				$message = 'Citation does not match the APA Website format.';
			} elseif ( $is_apa ) {
				$code    = 'APA_BOOK_FORMAT_MISMATCH';
				$message = 'Citation does not match the APA Book format.';
			} elseif ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
				$code    = 'EDITED_BOOK_FORMAT_MISMATCH';
				$message = 'Citation does not match the Harvard Edited Book format.';
			} elseif ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
				$code    = 'JOURNAL_ARTICLE_FORMAT_MISMATCH';
				$message = 'Citation does not match the Harvard Journal Article format.';
			} elseif ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
				$code    = 'WEBSITE_FORMAT_MISMATCH';
				$message = 'Citation does not match the Harvard Website/Web Resource format.';
			} else {
				$code    = 'BOOK_FORMAT_MISMATCH';
				$message = 'Citation does not match the Harvard Book format.';
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
	 * authors and Edited Book editors are joined by the same Harvard
	 * rule and validated the same way): every author's surname/initials must
	 * appear in the reconstructed reference, and (scenario checks only)
	 * every author's surname must appear in the scenario. A record with no
	 * `authors` array falls back to the singular authorSurname/authorInitials
	 * fields (treated as a single author) — this keeps every pre-multi-author
	 * record, and any externally imported record that only ever populated
	 * the singular fields, validating exactly as before. Question Parts are
	 * NOT re-checked here — validate_dragdrop()'s own Book-only block
	 * already recomputes and exact-matches {parts, fixedText,
	 * confusingWords} via Citex_Book_Dragdrop_Parts::build(), which is
	 * strictly stronger than a parts-shape check alone.
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
	 * MLA Book counterpart to validate_bibliographic_consistency() — the
	 * same academic-integrity safety net (scenario must describe the same
	 * book the reference/Question Parts were built from), reshaped for
	 * MLA's own author-name convention: a full given name (`givenName`),
	 * never Harvard's abbreviated `initials`, and no `place` field at all
	 * (MLA dropped place of publication in its 7th edition, so it is never
	 * checked here). Otherwise identical in structure to Book's own check —
	 * every author's surname/given name must appear in the reconstructed
	 * reference, and (scenario checks only) every author's surname must
	 * appear in the scenario.
	 */
	private static function validate_mla_bibliographic_consistency( $question, $reference, $check_scenario = true ) {
		$errors  = array();
		$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		if ( empty( $authors ) ) {
			$fallback_surname    = trim( (string) ( $question['authorSurname'] ?? '' ) );
			$fallback_given_name = trim( (string) ( $question['authorGivenName'] ?? '' ) );
			if ( '' !== $fallback_surname || '' !== $fallback_given_name ) {
				$authors = array( array( 'surname' => $fallback_surname, 'givenName' => $fallback_given_name ) );
			}
		}
		$title = trim( (string) ( $question['bookTitle'] ?? '' ) );

		if ( empty( $authors ) && '' === $title ) {
			return $errors;
		}
		if ( empty( $authors ) ) {
			$errors[] = self::error( 'MLA_BOOK_AUTHORS_MISSING', 'No authors were provided for this MLA Book question.' );
			return $errors;
		}

		$year      = trim( (string) ( $question['year'] ?? '' ) );
		$publisher = trim( (string) ( $question['publisher'] ?? '' ) );

		// With 3+ authors, MLA's own rule replaces every author after the
		// first with "et al." in the reference itself (the OPPOSITE of
		// Harvard's "always list everyone" rule) — so only the first
		// author's surname/given name is ever expected to actually appear
		// in the reconstructed reference text once there are 3 or more.
		// The scenario, however, must still describe every real author
		// regardless of how the reference abbreviates them.
		$author_count = count( $authors );
		foreach ( $authors as $index => $author ) {
			$author_surname    = trim( (string) ( $author['surname'] ?? '' ) );
			$author_given_name = trim( (string) ( $author['givenName'] ?? '' ) );
			$named_in_reference = $author_count < 3 || 0 === $index;
			if ( $named_in_reference && '' !== $author_surname && ! self::text_contains( $reference, $author_surname ) ) {
				$errors[] = self::error( 'MLA_BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', sprintf( 'The reference does not contain author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
			}
			// Only the first author is ever named in full in an MLA
			// reference of 3+ authors ("et al." replaces the rest) — so the
			// given name is only required to appear for the first author.
			if ( 0 === $index && '' !== $author_given_name && ! self::text_contains( $reference, $author_given_name ) ) {
				$errors[] = self::error( 'MLA_BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', sprintf( 'The reference does not contain author %1$d\'s given name: "%2$s".', $index + 1, $author_given_name ) );
			}
			if ( $check_scenario && '' !== $author_surname && ! self::text_contains( (string) ( $question['scenario'] ?? '' ), $author_surname ) ) {
				$errors[] = self::error( 'MLA_BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
			}
		}

		foreach (
			array(
				'year'      => array( $year, 'publication year' ),
				'title'     => array( $title, 'book title' ),
				'publisher' => array( $publisher, 'publisher' ),
			) as $pair
		) {
			list( $value, $label ) = $pair;
			if ( '' !== $value && ! self::text_contains( $reference, $value ) ) {
				$errors[] = self::error( 'MLA_BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', sprintf( 'The reconstructed reference does not contain the canonical %1$s: "%2$s".', $label, $value ) );
			}
		}

		if ( $check_scenario ) {
			$scenario = (string) ( $question['scenario'] ?? '' );
			foreach (
				array(
					'title'     => array( $title, 'book title' ),
					'year'      => array( $year, 'publication year' ),
					'publisher' => array( $publisher, 'publisher' ),
				) as $pair
			) {
				list( $value, $label ) = $pair;
				if ( '' !== $value && ! self::text_contains( $scenario, $value ) ) {
					$errors[] = self::error( 'MLA_BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical %1$s: "%2$s".', $label, $value ) );
				}
			}
		}

		return $errors;
	}

	/**
	 * APA Book counterpart to validate_bibliographic_consistency()/validate_mla_bibliographic_consistency() —
	 * the same academic-integrity safety net, reshaped for APA's own
	 * author-name convention: `initials` (the SAME field/shape Harvard's
	 * own check uses, never MLA's `givenName`), no `place` field at all,
	 * and — unlike MLA — every author is always listed in full in the
	 * reference (APA never uses "et al." at any count this app
	 * generates), so every author's surname/initials must appear, not just
	 * the first.
	 */
	private static function validate_apa_bibliographic_consistency( $question, $reference, $check_scenario = true ) {
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
			$errors[] = self::error( 'APA_BOOK_AUTHORS_MISSING', 'No authors were provided for this APA Book question.' );
			return $errors;
		}

		$year      = trim( (string) ( $question['year'] ?? '' ) );
		$publisher = trim( (string) ( $question['publisher'] ?? '' ) );

		foreach ( $authors as $index => $author ) {
			$author_surname  = trim( (string) ( $author['surname'] ?? '' ) );
			$author_initials = trim( (string) ( $author['initials'] ?? '' ) );
			if ( '' !== $author_surname && ! self::text_contains( $reference, $author_surname ) ) {
				$errors[] = self::error( 'APA_BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', sprintf( 'The reference does not contain author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
			}
			if ( '' !== $author_initials && ! self::text_contains( $reference, $author_initials ) ) {
				$errors[] = self::error( 'APA_BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', sprintf( 'The reference does not contain author %1$d\'s initials: "%2$s".', $index + 1, $author_initials ) );
			}
			if ( $check_scenario && '' !== $author_surname && ! self::text_contains( (string) ( $question['scenario'] ?? '' ), $author_surname ) ) {
				$errors[] = self::error( 'APA_BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
			}
		}

		foreach (
			array(
				'year'      => array( $year, 'publication year' ),
				'title'     => array( $title, 'book title' ),
				'publisher' => array( $publisher, 'publisher' ),
			) as $pair
		) {
			list( $value, $label ) = $pair;
			if ( '' !== $value && ! self::text_contains( $reference, $value ) ) {
				$errors[] = self::error( 'APA_BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', sprintf( 'The reconstructed reference does not contain the canonical %1$s: "%2$s".', $label, $value ) );
			}
		}

		if ( $check_scenario ) {
			$scenario = (string) ( $question['scenario'] ?? '' );
			foreach (
				array(
					'title'     => array( $title, 'book title' ),
					'year'      => array( $year, 'publication year' ),
					'publisher' => array( $publisher, 'publisher' ),
				) as $pair
			) {
				list( $value, $label ) = $pair;
				if ( '' !== $value && ! self::text_contains( $scenario, $value ) ) {
					$errors[] = self::error( 'APA_BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical %1$s: "%2$s".', $label, $value ) );
				}
			}
		}

		return $errors;
	}

	/**
	 * Chicago (Author-Date) Book counterpart to
	 * validate_bibliographic_consistency()/validate_apa_bibliographic_consistency() —
	 * the same academic-integrity safety net, reshaped for Chicago's own
	 * author-name convention: `givenName` (the SAME field/shape MLA's own
	 * check uses, never Harvard/APA's `initials`), WITH a `place` field
	 * (like Harvard, unlike MLA/APA), and — like Harvard/APA, unlike MLA —
	 * every author is always listed in full in the reference (Chicago never
	 * uses "et al." at any count this app generates), so every author's
	 * surname/given name must appear, not just the first.
	 */
	private static function validate_chicago_bibliographic_consistency( $question, $reference, $check_scenario = true ) {
		$errors  = array();
		$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		if ( empty( $authors ) ) {
			$fallback_surname    = trim( (string) ( $question['authorSurname'] ?? '' ) );
			$fallback_given_name = trim( (string) ( $question['authorGivenName'] ?? '' ) );
			if ( '' !== $fallback_surname || '' !== $fallback_given_name ) {
				$authors = array( array( 'surname' => $fallback_surname, 'givenName' => $fallback_given_name ) );
			}
		}
		$title = trim( (string) ( $question['bookTitle'] ?? '' ) );

		if ( empty( $authors ) && '' === $title ) {
			return $errors;
		}
		if ( empty( $authors ) ) {
			$errors[] = self::error( 'CHICAGO_BOOK_AUTHORS_MISSING', 'No authors were provided for this Chicago Book question.' );
			return $errors;
		}

		$year      = trim( (string) ( $question['year'] ?? '' ) );
		$place     = trim( (string) ( $question['place'] ?? '' ) );
		$publisher = trim( (string) ( $question['publisher'] ?? '' ) );

		foreach ( $authors as $index => $author ) {
			$author_surname    = trim( (string) ( $author['surname'] ?? '' ) );
			$author_given_name = trim( (string) ( $author['givenName'] ?? '' ) );
			if ( '' !== $author_surname && ! self::text_contains( $reference, $author_surname ) ) {
				$errors[] = self::error( 'CHICAGO_BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', sprintf( 'The reference does not contain author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
			}
			if ( '' !== $author_given_name && ! self::text_contains( $reference, $author_given_name ) ) {
				$errors[] = self::error( 'CHICAGO_BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', sprintf( 'The reference does not contain author %1$d\'s given name: "%2$s".', $index + 1, $author_given_name ) );
			}
			if ( $check_scenario && '' !== $author_surname && ! self::text_contains( (string) ( $question['scenario'] ?? '' ), $author_surname ) ) {
				$errors[] = self::error( 'CHICAGO_BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
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
				$errors[] = self::error( 'CHICAGO_BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', sprintf( 'The reconstructed reference does not contain the canonical %1$s: "%2$s".', $label, $value ) );
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
					$errors[] = self::error( 'CHICAGO_BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical %1$s: "%2$s".', $label, $value ) );
				}
			}
		}

		return $errors;
	}

	/**
	 * Chicago counterpart to validate_apa_edited_book_consistency() — same
	 * "reconstruct + exact match" pattern, via
	 * Citex_Chicago_Reference_Rules::build_reference() instead
	 * (surname/givenName, "ed"/"eds" designation, WITH `place` included —
	 * unlike APA/MLA's own Edited Book, both of which dropped it).
	 */
	private static function validate_chicago_edited_book_consistency( $question, $reference, $check_scenario = true ) {
		$errors  = array();
		$editors = is_array( $question['editors'] ?? null ) ? array_values( $question['editors'] ) : array();
		$title   = trim( (string) ( $question['bookTitle'] ?? '' ) );

		if ( empty( $editors ) && '' === $title ) {
			return $errors;
		}
		if ( empty( $editors ) ) {
			$errors[] = self::error( 'CHICAGO_EDITED_BOOK_EDITORS_MISSING', 'No editors were provided for this Chicago Edited Book question.' );
			return $errors;
		}

		$year      = trim( (string) ( $question['year'] ?? '' ) );
		$place     = trim( (string) ( $question['place'] ?? '' ) );
		$publisher = trim( (string) ( $question['publisher'] ?? '' ) );

		$fields             = array( 'editors' => $editors, 'year' => $year, 'title' => $title, 'place' => $place, 'publisher' => $publisher );
		$expected_reference = trim( Citex_Chicago_Reference_Rules::build_reference( Citex_Chicago_Reference_Rules::CATEGORY_EDITED_BOOK, $fields ) );
		if ( '' !== $expected_reference && trim( (string) $reference ) !== $expected_reference ) {
			$errors[] = self::error(
				'CHICAGO_EDITED_BOOK_RECONSTRUCTION_MISMATCH',
				sprintf( 'The reference does not match the one independently reconstructed from canonical data: "%s".', $expected_reference )
			);
		}

		if ( $check_scenario ) {
			$scenario = (string) ( $question['scenario'] ?? '' );
			foreach ( $editors as $index => $editor ) {
				$editor_surname = trim( (string) ( $editor['surname'] ?? '' ) );
				if ( '' !== $editor_surname && ! self::text_contains( $scenario, $editor_surname ) ) {
					$errors[] = self::error( 'CHICAGO_EDITED_BOOK_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention editor %1$d\'s surname: "%2$s".', $index + 1, $editor_surname ) );
				}
			}
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
					$errors[] = self::error( 'CHICAGO_EDITED_BOOK_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical %1$s: "%2$s".', $label, $value ) );
				}
			}
		}

		return $errors;
	}

	/**
	 * Chicago counterpart to validate_apa_journal_article_consistency() —
	 * same "reconstruct + exact match" pattern, via
	 * Citex_Chicago_Reference_Rules::build_reference() instead
	 * (surname/givenName, double-quoted article title, a colon before the
	 * page range, no "pp." prefix).
	 */
	private static function validate_chicago_journal_article_consistency( $question, $reference, $check_scenario = true ) {
		$errors        = array();
		$authors       = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		$article_title = trim( (string) ( $question['articleTitle'] ?? '' ) );

		if ( empty( $authors ) && '' === $article_title ) {
			return $errors;
		}
		if ( empty( $authors ) ) {
			$errors[] = self::error( 'CHICAGO_JOURNAL_ARTICLE_AUTHORS_MISSING', 'No authors were provided for this Chicago Journal Article question.' );
			return $errors;
		}

		$journal_title = trim( (string) ( $question['journalTitle'] ?? '' ) );
		$volume        = trim( (string) ( $question['volume'] ?? '' ) );
		$issue         = trim( (string) ( $question['issue'] ?? '' ) );
		$year          = trim( (string) ( $question['year'] ?? '' ) );
		$pages         = trim( (string) ( $question['pages'] ?? '' ) );

		$fields             = array( 'authors' => $authors, 'articleTitle' => $article_title, 'journalTitle' => $journal_title, 'volume' => $volume, 'issue' => $issue, 'year' => $year, 'pages' => $pages );
		$expected_reference = trim( Citex_Chicago_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields ) );
		if ( '' !== $expected_reference && trim( (string) $reference ) !== $expected_reference ) {
			$errors[] = self::error(
				'CHICAGO_JOURNAL_ARTICLE_RECONSTRUCTION_MISMATCH',
				sprintf( 'The reference does not match the one independently reconstructed from canonical data: "%s".', $expected_reference )
			);
		}

		if ( $check_scenario ) {
			$scenario = (string) ( $question['scenario'] ?? '' );
			foreach ( $authors as $index => $author ) {
				$author_surname = trim( (string) ( $author['surname'] ?? '' ) );
				if ( '' !== $author_surname && ! self::text_contains( $scenario, $author_surname ) ) {
					$errors[] = self::error( 'CHICAGO_JOURNAL_ARTICLE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
				}
			}
			foreach (
				array(
					'articleTitle' => array( $article_title, 'article title' ),
					'journalTitle' => array( $journal_title, 'journal title' ),
					'year'         => array( $year, 'publication year' ),
					'volume'       => array( $volume, 'volume' ),
					'issue'        => array( $issue, 'issue' ),
				) as $pair
			) {
				list( $value, $label ) = $pair;
				if ( '' !== $value && ! self::text_contains( $scenario, $value ) ) {
					$errors[] = self::error( 'CHICAGO_JOURNAL_ARTICLE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical %1$s: "%2$s".', $label, $value ) );
				}
			}
			if ( '' !== $pages && ! self::scenario_mentions_page_range( $scenario, $pages ) ) {
				$errors[] = self::error( 'CHICAGO_JOURNAL_ARTICLE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical page range: "%s".', $pages ) );
			}
		}

		return $errors;
	}

	/**
	 * Chicago counterpart to validate_apa_website_consistency() — same
	 * "reconstruct + exact match" pattern, via
	 * Citex_Chicago_Reference_Rules::build_reference() instead:
	 * surname/givenName, `year` always present (a real 4-digit year or
	 * exactly "n.d."), and no accessed date at all.
	 */
	private static function validate_chicago_website_consistency( $question, $reference, $check_scenario = true ) {
		$errors      = array();
		$author_type = trim( (string) ( $question['authorType'] ?? '' ) );

		$author_surname    = '';
		$author_given_name = '';
		$organisation_name = '';
		if ( 'individual' === $author_type ) {
			$authors = is_array( $question['authors'] ?? null ) ? $question['authors'] : array();
			if ( ! empty( $authors ) ) {
				$author_surname    = trim( (string) ( $authors[0]['surname'] ?? '' ) );
				$author_given_name = trim( (string) ( $authors[0]['givenName'] ?? '' ) );
			}
		} elseif ( 'organisation' === $author_type ) {
			$organisation_name = trim( (string) ( $question['organisationName'] ?? '' ) );
		}
		$title = trim( (string) ( $question['pageTitle'] ?? '' ) );

		if ( '' === $author_type && '' === $title ) {
			return $errors;
		}
		if ( 'individual' !== $author_type && 'organisation' !== $author_type ) {
			$errors[] = self::error( 'CHICAGO_WEBSITE_AUTHOR_TYPE_INVALID', 'authorType must be exactly "individual" or "organisation".' );
			return $errors;
		}
		if ( 'individual' === $author_type && ( '' === $author_surname || '' === $author_given_name ) ) {
			$errors[] = self::error( 'CHICAGO_WEBSITE_AUTHOR_MISSING', 'No individual author surname/given name were provided for this Chicago Website question.' );
			return $errors;
		}
		if ( 'organisation' === $author_type && '' === $organisation_name ) {
			$errors[] = self::error( 'CHICAGO_WEBSITE_AUTHOR_MISSING', 'No organisation name was provided for this Chicago Website question.' );
			return $errors;
		}

		$year = trim( (string) ( $question['year'] ?? '' ) );
		$url  = trim( (string) ( $question['url'] ?? '' ) );

		if ( ! preg_match( '/^(?:\d{4}|n\.d\.)$/', $year ) ) {
			$errors[] = self::error( 'CHICAGO_WEBSITE_YEAR_INVALID', 'The year must be either a real 4-digit year or exactly "n.d." when none can be identified.' );
		}
		if ( '' === $url || ! preg_match( '#^https?://\S+$#', $url ) ) {
			$errors[] = self::error( 'CHICAGO_WEBSITE_URL_MALFORMED', 'The URL must be a well-formed http(s) address with no spaces.' );
		}

		$author = array( 'type' => $author_type );
		if ( 'individual' === $author_type ) {
			$author['surname']   = $author_surname;
			$author['givenName'] = $author_given_name;
		} else {
			$author['name'] = $organisation_name;
		}
		$fields = array( 'author' => $author, 'year' => $year, 'title' => $title, 'url' => $url );

		$expected_reference = trim( Citex_Chicago_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_WEBSITE, $fields ) );
		if ( '' !== $expected_reference && trim( (string) $reference ) !== $expected_reference ) {
			$errors[] = self::error(
				'CHICAGO_WEBSITE_RECONSTRUCTION_MISMATCH',
				sprintf( 'The reference does not match the one independently reconstructed from canonical data: "%s".', $expected_reference )
			);
		}

		if ( $check_scenario ) {
			$scenario      = (string) ( $question['scenario'] ?? '' );
			$name_to_check = 'individual' === $author_type ? $author_surname : $organisation_name;
			if ( '' !== $name_to_check && ! self::text_contains( $scenario, $name_to_check ) ) {
				$errors[] = self::error( 'CHICAGO_WEBSITE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical author/organisation name: "%s".', $name_to_check ) );
			}
			if ( '' !== $title && ! self::text_contains( $scenario, $title ) ) {
				$errors[] = self::error( 'CHICAGO_WEBSITE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical page title: "%s".', $title ) );
			}
			if ( '' !== $url && ! self::text_contains( $scenario, $url ) ) {
				$errors[] = self::error( 'CHICAGO_WEBSITE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical url: "%s".', $url ) );
			}
		}

		return $errors;
	}

	/**
	 * MHRA Book counterpart to validate_bibliographic_consistency()/validate_chicago_bibliographic_consistency() —
	 * the same academic-integrity safety net, reshaped for MHRA's own
	 * author-name convention: `givenName` (the SAME field/shape MLA's/
	 * Chicago's own checks use, never Harvard/APA's `initials`), WITH a
	 * `place` field, and — like Harvard/APA/Chicago, unlike MLA — every
	 * author is always listed in full in the reference (MHRA never uses
	 * "et al." at any count this app generates), so every author's surname/
	 * given name must appear, not just the first. text_contains() is a
	 * plain substring check, so it is unaffected by MHRA's own "only the
	 * first author is inverted" word-order rule — a later author's given
	 * name/surname still appear in the reference (just in the opposite
	 * order to the first), and this check never needs to know which.
	 */
	private static function validate_mhra_bibliographic_consistency( $question, $reference, $check_scenario = true ) {
		$errors  = array();
		$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		if ( empty( $authors ) ) {
			$fallback_surname    = trim( (string) ( $question['authorSurname'] ?? '' ) );
			$fallback_given_name = trim( (string) ( $question['authorGivenName'] ?? '' ) );
			if ( '' !== $fallback_surname || '' !== $fallback_given_name ) {
				$authors = array( array( 'surname' => $fallback_surname, 'givenName' => $fallback_given_name ) );
			}
		}
		$title = trim( (string) ( $question['bookTitle'] ?? '' ) );

		if ( empty( $authors ) && '' === $title ) {
			return $errors;
		}
		if ( empty( $authors ) ) {
			$errors[] = self::error( 'MHRA_BOOK_AUTHORS_MISSING', 'No authors were provided for this MHRA Book question.' );
			return $errors;
		}

		$year      = trim( (string) ( $question['year'] ?? '' ) );
		$place     = trim( (string) ( $question['place'] ?? '' ) );
		$publisher = trim( (string) ( $question['publisher'] ?? '' ) );

		foreach ( $authors as $index => $author ) {
			$author_surname    = trim( (string) ( $author['surname'] ?? '' ) );
			$author_given_name = trim( (string) ( $author['givenName'] ?? '' ) );
			if ( '' !== $author_surname && ! self::text_contains( $reference, $author_surname ) ) {
				$errors[] = self::error( 'MHRA_BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', sprintf( 'The reference does not contain author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
			}
			if ( '' !== $author_given_name && ! self::text_contains( $reference, $author_given_name ) ) {
				$errors[] = self::error( 'MHRA_BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', sprintf( 'The reference does not contain author %1$d\'s given name: "%2$s".', $index + 1, $author_given_name ) );
			}
			if ( $check_scenario && '' !== $author_surname && ! self::text_contains( (string) ( $question['scenario'] ?? '' ), $author_surname ) ) {
				$errors[] = self::error( 'MHRA_BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
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
				$errors[] = self::error( 'MHRA_BIBLIOGRAPHIC_CONSISTENCY_REFERENCE_MISMATCH', sprintf( 'The reconstructed reference does not contain the canonical %1$s: "%2$s".', $label, $value ) );
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
					$errors[] = self::error( 'MHRA_BIBLIOGRAPHIC_CONSISTENCY_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical %1$s: "%2$s".', $label, $value ) );
				}
			}
		}

		return $errors;
	}

	/**
	 * APA counterpart to validate_mla_edited_book_consistency() — same
	 * "reconstruct + exact match" pattern, via
	 * Citex_APA_Reference_Rules::build_reference() instead (surname/
	 * initials, "(Ed.)"/"(Eds.)" designation, no `place`).
	 */
	private static function validate_apa_edited_book_consistency( $question, $reference, $check_scenario = true ) {
		$errors  = array();
		$editors = is_array( $question['editors'] ?? null ) ? array_values( $question['editors'] ) : array();
		$title   = trim( (string) ( $question['bookTitle'] ?? '' ) );

		if ( empty( $editors ) && '' === $title ) {
			return $errors;
		}
		if ( empty( $editors ) ) {
			$errors[] = self::error( 'APA_EDITED_BOOK_EDITORS_MISSING', 'No editors were provided for this APA Edited Book question.' );
			return $errors;
		}

		$year      = trim( (string) ( $question['year'] ?? '' ) );
		$publisher = trim( (string) ( $question['publisher'] ?? '' ) );

		$fields             = array( 'editors' => $editors, 'year' => $year, 'title' => $title, 'publisher' => $publisher );
		$expected_reference = trim( Citex_APA_Reference_Rules::build_reference( Citex_APA_Reference_Rules::CATEGORY_EDITED_BOOK, $fields ) );
		if ( '' !== $expected_reference && trim( (string) $reference ) !== $expected_reference ) {
			$errors[] = self::error(
				'APA_EDITED_BOOK_RECONSTRUCTION_MISMATCH',
				sprintf( 'The reference does not match the one independently reconstructed from canonical data: "%s".', $expected_reference )
			);
		}

		if ( $check_scenario ) {
			$scenario = (string) ( $question['scenario'] ?? '' );
			foreach ( $editors as $index => $editor ) {
				$editor_surname = trim( (string) ( $editor['surname'] ?? '' ) );
				if ( '' !== $editor_surname && ! self::text_contains( $scenario, $editor_surname ) ) {
					$errors[] = self::error( 'APA_EDITED_BOOK_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention editor %1$d\'s surname: "%2$s".', $index + 1, $editor_surname ) );
				}
			}
			foreach (
				array(
					'title'     => array( $title, 'book title' ),
					'year'      => array( $year, 'publication year' ),
					'publisher' => array( $publisher, 'publisher' ),
				) as $pair
			) {
				list( $value, $label ) = $pair;
				if ( '' !== $value && ! self::text_contains( $scenario, $value ) ) {
					$errors[] = self::error( 'APA_EDITED_BOOK_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical %1$s: "%2$s".', $label, $value ) );
				}
			}
		}

		return $errors;
	}

	/**
	 * APA counterpart to validate_mla_journal_article_consistency() — same
	 * "reconstruct + exact match" pattern, via
	 * Citex_APA_Reference_Rules::build_reference() instead (no quotes around
	 * the article title, no "pp." prefix before the page range).
	 */
	private static function validate_apa_journal_article_consistency( $question, $reference, $check_scenario = true ) {
		$errors        = array();
		$authors       = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		$article_title = trim( (string) ( $question['articleTitle'] ?? '' ) );

		if ( empty( $authors ) && '' === $article_title ) {
			return $errors;
		}
		if ( empty( $authors ) ) {
			$errors[] = self::error( 'APA_JOURNAL_ARTICLE_AUTHORS_MISSING', 'No authors were provided for this APA Journal Article question.' );
			return $errors;
		}

		$journal_title = trim( (string) ( $question['journalTitle'] ?? '' ) );
		$volume        = trim( (string) ( $question['volume'] ?? '' ) );
		$issue         = trim( (string) ( $question['issue'] ?? '' ) );
		$year          = trim( (string) ( $question['year'] ?? '' ) );
		$pages         = trim( (string) ( $question['pages'] ?? '' ) );

		$fields             = array( 'authors' => $authors, 'articleTitle' => $article_title, 'journalTitle' => $journal_title, 'volume' => $volume, 'issue' => $issue, 'year' => $year, 'pages' => $pages );
		$expected_reference = trim( Citex_APA_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields ) );
		if ( '' !== $expected_reference && trim( (string) $reference ) !== $expected_reference ) {
			$errors[] = self::error(
				'APA_JOURNAL_ARTICLE_RECONSTRUCTION_MISMATCH',
				sprintf( 'The reference does not match the one independently reconstructed from canonical data: "%s".', $expected_reference )
			);
		}

		if ( $check_scenario ) {
			$scenario = (string) ( $question['scenario'] ?? '' );
			foreach ( $authors as $index => $author ) {
				$author_surname = trim( (string) ( $author['surname'] ?? '' ) );
				if ( '' !== $author_surname && ! self::text_contains( $scenario, $author_surname ) ) {
					$errors[] = self::error( 'APA_JOURNAL_ARTICLE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
				}
			}
			foreach (
				array(
					'articleTitle' => array( $article_title, 'article title' ),
					'journalTitle' => array( $journal_title, 'journal title' ),
					'year'         => array( $year, 'publication year' ),
					'volume'       => array( $volume, 'volume' ),
					'issue'        => array( $issue, 'issue' ),
				) as $pair
			) {
				list( $value, $label ) = $pair;
				if ( '' !== $value && ! self::text_contains( $scenario, $value ) ) {
					$errors[] = self::error( 'APA_JOURNAL_ARTICLE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical %1$s: "%2$s".', $label, $value ) );
				}
			}
			if ( '' !== $pages && ! self::scenario_mentions_page_range( $scenario, $pages ) ) {
				$errors[] = self::error( 'APA_JOURNAL_ARTICLE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical page range: "%s".', $pages ) );
			}
		}

		return $errors;
	}

	/**
	 * APA counterpart to validate_mla_website_consistency() — same
	 * "reconstruct + exact match" pattern, via
	 * Citex_APA_Reference_Rules::build_reference() instead: surname/
	 * initials, `year` always present (a real 4-digit year or exactly
	 * "n.d." — same convention as Harvard, never MLA's "omit the segment"),
	 * and no accessed date at all (APA's own Website format has none for a
	 * stable source).
	 */
	private static function validate_apa_website_consistency( $question, $reference, $check_scenario = true ) {
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
		$title = trim( (string) ( $question['pageTitle'] ?? '' ) );

		if ( '' === $author_type && '' === $title ) {
			return $errors;
		}
		if ( 'individual' !== $author_type && 'organisation' !== $author_type ) {
			$errors[] = self::error( 'APA_WEBSITE_AUTHOR_TYPE_INVALID', 'authorType must be exactly "individual" or "organisation".' );
			return $errors;
		}
		if ( 'individual' === $author_type && ( '' === $author_surname || '' === $author_initials ) ) {
			$errors[] = self::error( 'APA_WEBSITE_AUTHOR_MISSING', 'No individual author surname/initials were provided for this APA Website question.' );
			return $errors;
		}
		if ( 'organisation' === $author_type && '' === $organisation_name ) {
			$errors[] = self::error( 'APA_WEBSITE_AUTHOR_MISSING', 'No organisation name was provided for this APA Website question.' );
			return $errors;
		}

		$year = trim( (string) ( $question['year'] ?? '' ) );
		$url  = trim( (string) ( $question['url'] ?? '' ) );

		if ( ! preg_match( '/^(?:\d{4}|n\.d\.)$/', $year ) ) {
			$errors[] = self::error( 'APA_WEBSITE_YEAR_INVALID', 'The year must be either a real 4-digit year or exactly "n.d." when none can be identified.' );
		}
		if ( '' === $url || ! preg_match( '#^https?://\S+$#', $url ) ) {
			$errors[] = self::error( 'APA_WEBSITE_URL_MALFORMED', 'The URL must be a well-formed http(s) address with no spaces.' );
		}

		$author = array( 'type' => $author_type );
		if ( 'individual' === $author_type ) {
			$author['surname']  = $author_surname;
			$author['initials'] = $author_initials;
		} else {
			$author['name'] = $organisation_name;
		}
		$fields = array( 'author' => $author, 'year' => $year, 'title' => $title, 'url' => $url );

		$expected_reference = trim( Citex_APA_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_WEBSITE, $fields ) );
		if ( '' !== $expected_reference && trim( (string) $reference ) !== $expected_reference ) {
			$errors[] = self::error(
				'APA_WEBSITE_RECONSTRUCTION_MISMATCH',
				sprintf( 'The reference does not match the one independently reconstructed from canonical data: "%s".', $expected_reference )
			);
		}

		if ( $check_scenario ) {
			$scenario      = (string) ( $question['scenario'] ?? '' );
			$name_to_check = 'individual' === $author_type ? $author_surname : $organisation_name;
			if ( '' !== $name_to_check && ! self::text_contains( $scenario, $name_to_check ) ) {
				$errors[] = self::error( 'APA_WEBSITE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical author/organisation name: "%s".', $name_to_check ) );
			}
			if ( '' !== $title && ! self::text_contains( $scenario, $title ) ) {
				$errors[] = self::error( 'APA_WEBSITE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical page title: "%s".', $title ) );
			}
			if ( '' !== $url && ! self::text_contains( $scenario, $url ) ) {
				$errors[] = self::error( 'APA_WEBSITE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical url: "%s".', $url ) );
			}
		}

		return $errors;
	}

	/**
	 * MLA counterpart to validate_edited_book_consistency() — independently
	 * reconstructs the expected reference from canonical data via
	 * Citex_MLA_Reference_Rules::build_reference() and requires an exact
	 * match (the same "reconstruct + exact match" pattern
	 * validate_website_consistency() already uses, simpler and stronger
	 * than a per-field text_contains() sweep) — MLA's own "editor"/
	 * "editors, editors." + "et al." rule is already fully encoded inside
	 * that builder, so there is no separate designation/join check needed
	 * here the way Harvard's own method has.
	 */
	private static function validate_mla_edited_book_consistency( $question, $reference, $check_scenario = true ) {
		$errors  = array();
		$editors = is_array( $question['editors'] ?? null ) ? array_values( $question['editors'] ) : array();
		$title   = trim( (string) ( $question['bookTitle'] ?? '' ) );

		if ( empty( $editors ) && '' === $title ) {
			return $errors;
		}
		if ( empty( $editors ) ) {
			$errors[] = self::error( 'MLA_EDITED_BOOK_EDITORS_MISSING', 'No editors were provided for this MLA Edited Book question.' );
			return $errors;
		}

		$year      = trim( (string) ( $question['year'] ?? '' ) );
		$publisher = trim( (string) ( $question['publisher'] ?? '' ) );

		$fields              = array( 'editors' => $editors, 'year' => $year, 'title' => $title, 'publisher' => $publisher );
		$expected_reference  = trim( Citex_MLA_Reference_Rules::build_reference( Citex_MLA_Reference_Rules::CATEGORY_EDITED_BOOK, $fields ) );
		if ( '' !== $expected_reference && trim( (string) $reference ) !== $expected_reference ) {
			$errors[] = self::error(
				'MLA_EDITED_BOOK_RECONSTRUCTION_MISMATCH',
				sprintf( 'The reference does not match the one independently reconstructed from canonical data: "%s".', $expected_reference )
			);
		}

		if ( $check_scenario ) {
			$scenario = (string) ( $question['scenario'] ?? '' );
			foreach ( $editors as $index => $editor ) {
				$editor_surname = trim( (string) ( $editor['surname'] ?? '' ) );
				if ( '' !== $editor_surname && ! self::text_contains( $scenario, $editor_surname ) ) {
					$errors[] = self::error( 'MLA_EDITED_BOOK_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention editor %1$d\'s surname: "%2$s".', $index + 1, $editor_surname ) );
				}
			}
			foreach (
				array(
					'title'     => array( $title, 'book title' ),
					'year'      => array( $year, 'publication year' ),
					'publisher' => array( $publisher, 'publisher' ),
				) as $pair
			) {
				list( $value, $label ) = $pair;
				if ( '' !== $value && ! self::text_contains( $scenario, $value ) ) {
					$errors[] = self::error( 'MLA_EDITED_BOOK_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical %1$s: "%2$s".', $label, $value ) );
				}
			}
		}

		return $errors;
	}

	/**
	 * MLA counterpart to validate_journal_article_consistency() — MLA
	 * Journal Article has no "exercise design"/partial-shape concept at
	 * all (unlike Harvard's own), so this uses the same simpler
	 * "reconstruct + exact match" pattern as
	 * validate_mla_edited_book_consistency() rather than Harvard's
	 * per-design field sweep.
	 */
	private static function validate_mla_journal_article_consistency( $question, $reference, $check_scenario = true ) {
		$errors  = array();
		$authors = is_array( $question['authors'] ?? null ) ? array_values( $question['authors'] ) : array();
		$article_title = trim( (string) ( $question['articleTitle'] ?? '' ) );

		if ( empty( $authors ) && '' === $article_title ) {
			return $errors;
		}
		if ( empty( $authors ) ) {
			$errors[] = self::error( 'MLA_JOURNAL_ARTICLE_AUTHORS_MISSING', 'No authors were provided for this MLA Journal Article question.' );
			return $errors;
		}

		$journal_title = trim( (string) ( $question['journalTitle'] ?? '' ) );
		$volume        = trim( (string) ( $question['volume'] ?? '' ) );
		$issue         = trim( (string) ( $question['issue'] ?? '' ) );
		$year          = trim( (string) ( $question['year'] ?? '' ) );
		$pages         = trim( (string) ( $question['pages'] ?? '' ) );

		$fields = array( 'authors' => $authors, 'articleTitle' => $article_title, 'journalTitle' => $journal_title, 'volume' => $volume, 'issue' => $issue, 'year' => $year, 'pages' => $pages );
		$expected_reference = trim( Citex_MLA_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields ) );
		if ( '' !== $expected_reference && trim( (string) $reference ) !== $expected_reference ) {
			$errors[] = self::error(
				'MLA_JOURNAL_ARTICLE_RECONSTRUCTION_MISMATCH',
				sprintf( 'The reference does not match the one independently reconstructed from canonical data: "%s".', $expected_reference )
			);
		}

		if ( $check_scenario ) {
			$scenario = (string) ( $question['scenario'] ?? '' );
			foreach ( $authors as $index => $author ) {
				$author_surname = trim( (string) ( $author['surname'] ?? '' ) );
				if ( '' !== $author_surname && ! self::text_contains( $scenario, $author_surname ) ) {
					$errors[] = self::error( 'MLA_JOURNAL_ARTICLE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
				}
			}
			foreach (
				array(
					'articleTitle' => array( $article_title, 'article title' ),
					'journalTitle' => array( $journal_title, 'journal title' ),
					'year'         => array( $year, 'publication year' ),
					'volume'       => array( $volume, 'volume' ),
					'issue'        => array( $issue, 'issue' ),
				) as $pair
			) {
				list( $value, $label ) = $pair;
				if ( '' !== $value && ! self::text_contains( $scenario, $value ) ) {
					$errors[] = self::error( 'MLA_JOURNAL_ARTICLE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical %1$s: "%2$s".', $label, $value ) );
				}
			}
			if ( '' !== $pages && ! self::scenario_mentions_page_range( $scenario, $pages ) ) {
				$errors[] = self::error( 'MLA_JOURNAL_ARTICLE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical page range: "%s".', $pages ) );
			}
		}

		return $errors;
	}

	/**
	 * MLA counterpart to validate_website_consistency() — same
	 * "reconstruct + exact match" pattern, adapted for MLA's own rule: no
	 * `publisher` element at all, and `year` genuinely optional (never
	 * "n.d." — an empty value is valid and means the built reference omits
	 * that segment entirely, see Citex_MLA_Reference_Rules::
	 * build_website_reference()'s own docblock).
	 */
	private static function validate_mla_website_consistency( $question, $reference, $check_scenario = true ) {
		$errors      = array();
		$author_type = trim( (string) ( $question['authorType'] ?? '' ) );

		$author_surname    = '';
		$author_given_name = '';
		$organisation_name = '';
		if ( 'individual' === $author_type ) {
			$authors = is_array( $question['authors'] ?? null ) ? $question['authors'] : array();
			if ( ! empty( $authors ) ) {
				$author_surname    = trim( (string) ( $authors[0]['surname'] ?? '' ) );
				$author_given_name = trim( (string) ( $authors[0]['givenName'] ?? '' ) );
			}
		} elseif ( 'organisation' === $author_type ) {
			$organisation_name = trim( (string) ( $question['organisationName'] ?? '' ) );
		}
		$title = trim( (string) ( $question['pageTitle'] ?? '' ) );

		if ( '' === $author_type && '' === $title ) {
			return $errors;
		}
		if ( 'individual' !== $author_type && 'organisation' !== $author_type ) {
			$errors[] = self::error( 'MLA_WEBSITE_AUTHOR_TYPE_INVALID', 'authorType must be exactly "individual" or "organisation".' );
			return $errors;
		}
		if ( 'individual' === $author_type && ( '' === $author_surname || '' === $author_given_name ) ) {
			$errors[] = self::error( 'MLA_WEBSITE_AUTHOR_MISSING', 'No individual author surname/given name were provided for this MLA Website question.' );
			return $errors;
		}
		if ( 'organisation' === $author_type && '' === $organisation_name ) {
			$errors[] = self::error( 'MLA_WEBSITE_AUTHOR_MISSING', 'No organisation name was provided for this MLA Website question.' );
			return $errors;
		}

		$year          = trim( (string) ( $question['year'] ?? '' ) );
		$url           = trim( (string) ( $question['url'] ?? '' ) );
		$accessed_date = trim( (string) ( $question['accessedDate'] ?? '' ) );

		// Real MLA 9 has no "n.d." convention at all — year is either a
		// genuine 4-digit year or completely empty, never any other
		// placeholder text.
		if ( '' !== $year && ! preg_match( '/^\d{4}$/', $year ) ) {
			$errors[] = self::error( 'MLA_WEBSITE_YEAR_INVALID', 'The year must be either a real 4-digit publication/creation year or left completely empty — MLA never uses "n.d." or any other placeholder.' );
		}
		if ( '' === $url || ! preg_match( '#^https?://\S+$#', $url ) ) {
			$errors[] = self::error( 'MLA_WEBSITE_URL_MALFORMED', 'The URL must be a well-formed http(s) address with no spaces.' );
		}
		if ( '' === $accessed_date ) {
			$errors[] = self::error( 'MLA_WEBSITE_ACCESSED_DATE_MISSING', 'The accessed date is missing.' );
		}

		$author = array( 'type' => $author_type );
		if ( 'individual' === $author_type ) {
			$author['surname']   = $author_surname;
			$author['givenName'] = $author_given_name;
		} else {
			$author['name'] = $organisation_name;
		}
		$fields = array( 'author' => $author, 'year' => $year, 'title' => $title, 'url' => $url, 'accessedDate' => $accessed_date );

		$expected_reference = trim( Citex_MLA_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_WEBSITE, $fields ) );
		if ( '' !== $expected_reference && trim( (string) $reference ) !== $expected_reference ) {
			$errors[] = self::error(
				'MLA_WEBSITE_RECONSTRUCTION_MISMATCH',
				sprintf( 'The reference does not match the one independently reconstructed from canonical data: "%s".', $expected_reference )
			);
		}

		if ( $check_scenario ) {
			$scenario = (string) ( $question['scenario'] ?? '' );
			$name_to_check = 'individual' === $author_type ? $author_surname : $organisation_name;
			if ( '' !== $name_to_check && ! self::text_contains( $scenario, $name_to_check ) ) {
				$errors[] = self::error( 'MLA_WEBSITE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical author/organisation name: "%s".', $name_to_check ) );
			}
			if ( '' !== $title && ! self::text_contains( $scenario, $title ) ) {
				$errors[] = self::error( 'MLA_WEBSITE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical page title: "%s".', $title ) );
			}
			if ( '' !== $url && ! self::text_contains( $scenario, $url ) ) {
				$errors[] = self::error( 'MLA_WEBSITE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical url: "%s".', $url ) );
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
	 * publisher concept, and has its own "et al. must never appear" rule
	 * that applies to every author count starting at 1, not just 4+.
	 *
	 * MOBILE SUITABILITY REWORK: a question can now test one of several
	 * "exercise designs" (see Citex_Reference_Rules::
	 * journal_article_dragdrop_shape()'s docblock) — a short, meaningful
	 * component instead of always the full 7-part reference. This method
	 * still ALWAYS requires and independently reconstructs the COMPLETE
	 * canonical reference from the full source data (never weakened,
	 * regardless of design — see $canonical_reference below), then
	 * separately checks the ACTUAL question under test against its own
	 * design-specific expected shape/reference (via
	 * Citex_Reference_Rules::dragdrop_shape()/reconstruct_reference() with
	 * the record's own `exerciseDesign`), and only requires the reference/
	 * scenario "must mention canonical fact X" checks for the fields this
	 * design's OWN reconstructed string actually contains (see
	 * Citex_Reference_Rules::journal_article_design_fields()) — a short
	 * segment like author_format's "Mitchell, S." legitimately does not,
	 * and must never be judged as if it should, contain the article title.
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

		$design = trim( (string) ( $question['exerciseDesign'] ?? 'full_reference' ) );
		$design_fields = Citex_Reference_Rules::journal_article_design_fields( $design );
		if ( null === $design_fields ) {
			$errors[] = self::error( 'JOURNAL_ARTICLE_DESIGN_UNKNOWN', sprintf( 'Unrecognised exercise design "%s".', $design ) );
			return $errors;
		}

		// ALWAYS: the COMPLETE canonical reference, built from the full
		// real source data regardless of which part this exercise actually
		// tests — required to genuinely be well-formed, so validation is
		// never weakened just because a question only shows a fragment of
		// it (item 12's "the system must retain the complete canonical
		// reference internally... do NOT weaken validation").
		$canonical_reference = trim( Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields ) );
		if ( ! empty( self::validate_reference_format( $canonical_reference, Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, null, null, null, null, 'full_reference' ) ) ) {
			$errors[] = self::error(
				'JOURNAL_ARTICLE_CANONICAL_REFERENCE_INVALID',
				sprintf( 'The complete canonical reference built from the full source data is not a well-formed Harvard Journal Article reference: "%s".', $canonical_reference )
			);
		}
		// "et al." must NEVER appear in a Journal Article reference-list
		// entry, for any author count — the one Harvard misconception
		// this category exists to test (see Citex_Reference_Rules::
		// build_reference()'s docblock). Checked against BOTH the complete
		// canonical reference (defence in depth against a "clean-looking"
		// author record that somehow still encodes it) AND the actual
		// reference under test (the one the student is shown/submits,
		// which for the authors-testing designs is where a corrupted
		// Fixed Text/Question Parts pairing would actually surface it).
		if ( preg_match( '/\bet\s*al\.?\b/i', $canonical_reference ) || preg_match( '/\bet\s*al\.?\b/i', (string) $reference ) ) {
			$errors[] = self::error( 'JOURNAL_ARTICLE_ET_AL_USED', 'The reference list entry must never use "et al." — every author must be listed in full.' );
		}

		// THIS question's own design-specific expected shape/reference —
		// MCQ only. DragDrop now always uses Citex_Journal_Article_Dragdrop_Parts
		// (a genuinely different mechanism from the old named-design
		// catalogue dragdrop_shape() still serves here for MCQ), and
		// validate_dragdrop()'s own dedicated block already recomputes and
		// exact-matches Question Parts/Fixed Text/Confusing Words from the
		// record's stored `dragdropPartKeys` — redoing an old-shape-based
		// comparison here would always mismatch (the new system never
		// produces the old design's part set).
		if ( 'DragDrop' !== (string) ( $question['type'] ?? '' ) ) {
			$expected_shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields, $design );
			$expected_parts = array_map( 'trim', $expected_shape['parts'] );
			$actual_parts   = array_map( 'trim', (array) $question_parts );
			if ( $expected_parts !== array_values( $actual_parts ) ) {
				$errors[] = self::error(
					'JOURNAL_ARTICLE_PARTS_MISMATCH',
					'Question Parts do not exactly match the canonical bibliographic record for this exercise design.'
				);
			}

			// Independently reconstruct the expected reference for THIS
			// design and require an exact match — the same "reconstruct from
			// canonical data, never merely string-match" requirement, applied
			// per design instead of always to the full reference.
			$expected_reference = trim( Citex_Reference_Rules::reconstruct_reference( $expected_shape ) );
			if ( '' !== $expected_reference && trim( (string) $reference ) !== $expected_reference ) {
				$errors[] = self::error(
					'JOURNAL_ARTICLE_RECONSTRUCTION_MISMATCH',
					sprintf( 'The reference does not match the one independently reconstructed from canonical data: "%s".', $expected_reference )
				);
			}
		}

		// Author checks — surname/initials well-formedness is data
		// integrity (always checked); WHETHER the reference/scenario must
		// actually contain them depends on whether this design tests the
		// authors field at all.
		$design_tests_authors = in_array( 'authors', $design_fields, true );
		foreach ( $authors as $index => $author ) {
			$author_surname  = trim( (string) ( $author['surname'] ?? '' ) );
			$author_initials = trim( (string) ( $author['initials'] ?? '' ) );
			if ( $design_tests_authors && '' !== $author_surname && ! self::text_contains( $reference, $author_surname ) ) {
				$errors[] = self::error( 'JOURNAL_ARTICLE_REFERENCE_MISMATCH', sprintf( 'The reference does not contain author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
			}
			if ( $design_tests_authors && '' !== $author_initials && ! self::text_contains( $reference, $author_initials ) ) {
				$errors[] = self::error( 'JOURNAL_ARTICLE_REFERENCE_MISMATCH', sprintf( 'The reference does not contain author %1$d\'s initials: "%2$s".', $index + 1, $author_initials ) );
			}
			// Scenario check excludes initials — a natural scenario names the
			// author (e.g. "Sarah Mitchell"), not their initials. Skipped
			// when $check_scenario is false (MCQ), or when this design
			// does not test the authors field at all.
			if ( $check_scenario && $design_tests_authors && '' !== $author_surname && ! self::text_contains( (string) ( $question['scenario'] ?? '' ), $author_surname ) ) {
				$errors[] = self::error( 'JOURNAL_ARTICLE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention author %1$d\'s surname: "%2$s".', $index + 1, $author_surname ) );
			}
		}

		foreach (
			array(
				'year'         => array( $year, 'publication year' ),
				'articleTitle' => array( $article_title, 'article title' ),
				'journalTitle' => array( $journal_title, 'journal title' ),
				'volume'       => array( $volume, 'volume' ),
				'issue'        => array( $issue, 'issue' ),
				// The reconstructed reference renders the page range with a
				// typographic en dash (see Citex_Reference_Rules::
				// format_page_range()), never the plain-hyphen form the
				// canonical `pages` field itself is stored as — check against
				// the same rendered form the reference actually contains.
				'pages'        => array( Citex_Reference_Rules::format_page_range( $pages ), 'page range' ),
			) as $key => $pair
		) {
			if ( ! in_array( $key, $design_fields, true ) ) {
				continue;
			}
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
				) as $key => $pair
			) {
				if ( ! in_array( $key, $design_fields, true ) ) {
					continue;
				}
				list( $value, $label ) = $pair;
				if ( '' !== $value && ! self::text_contains( $scenario, $value ) ) {
					$errors[] = self::error( 'JOURNAL_ARTICLE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical %1$s: "%2$s".', $label, $value ) );
				}
			}
			// Pages gets its own check: the prompt asks Gemini to phrase the
			// scenario naturally as "pages 27 to 35", never the reference's
			// own hyphenated "27-35" form (which would nudge the student
			// toward the exact reference-list punctuation) — so a literal
			// text_contains() against the hyphenated canonical value would
			// reject every correctly-written scenario. Accept either the
			// literal hyphenated form OR both endpoint numbers appearing
			// (in either order, since natural phrasing states them in
			// ascending order regardless of how the canonical value is
			// written) — see self::scenario_mentions_page_range()'s docblock.
			if ( in_array( 'pages', $design_fields, true ) && '' !== $pages && ! self::scenario_mentions_page_range( $scenario, $pages ) ) {
				$errors[] = self::error( 'JOURNAL_ARTICLE_SCENARIO_MISMATCH', sprintf( 'The scenario does not mention the canonical page range: "%s".', $pages ) );
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
		$title = trim( (string) ( $question['pageTitle'] ?? '' ) );

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

		// Question Parts for DragDrop are validated separately, by
		// validate_dragdrop()'s own dedicated block, which recomputes and
		// exact-matches Question Parts/Fixed Text/Confusing Words from the
		// record's stored `dragdropPartKeys` via Citex_Website_Dragdrop_Parts.
		// This method is only ever reached from validate_dragdrop() (Website
		// MCQ is fully handled by validate_website_mcq_variant() before
		// reaching here), so $question_parts needs no further check here.

		// Independently reconstruct the expected reference from canonical
		// data (see this method's docblock) and require an exact match.
		$expected_reference = trim( Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_WEBSITE, $fields ) );
		if ( '' !== $expected_reference && trim( (string) $reference ) !== $expected_reference ) {
			$errors[] = self::error(
				'WEBSITE_RECONSTRUCTION_MISMATCH',
				sprintf( 'The reference does not match the one independently reconstructed from canonical data: "%s".', $expected_reference )
			);
		}

		// Canonical facts must appear in the reference itself. Publisher is
		// deliberately excluded here — this format has no publisher element
		// at all (see Citex_Reference_Rules::build_website_reference()), so
		// the record's own `publisher` field, even when present, is never
		// expected to appear in the reference text.
		$author_display = Citex_Reference_Rules::format_website_author( $author );
		foreach (
			array(
				'author' => array( $author_display, 'author/organisation' ),
				'title'  => array( $title, 'page/document title' ),
				'url'    => array( $url, 'URL' ),
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
	 * Whether $scenario mentions the canonical page range $pages (e.g.
	 * "27-35") in EITHER of the two forms a well-written scenario can
	 * legitimately use:
	 * - the literal hyphenated form itself ("27-35"), or
	 * - both endpoint numbers stated separately ("pages 27 to 35") — the
	 *   natural phrasing Citex_AI_V2's own Journal Article prompt asks
	 *   Gemini to use, specifically so the scenario never shows the
	 *   reference's own "pp.27-35" punctuation directly.
	 * A bare text_contains() against the hyphenated form alone would
	 * reject every scenario that correctly followed that natural-phrasing
	 * instruction, so this check is pages-specific. Returns true (skips
	 * the check) for a page range that is not two hyphen-separated
	 * numbers — malformed page-range data is caught elsewhere (reference
	 * format validation), not here.
	 */
	private static function scenario_mentions_page_range( $scenario, $pages ) {
		if ( self::text_contains( $scenario, $pages ) ) {
			return true;
		}
		if ( 1 !== preg_match( '/^\s*(\d+)\s*-\s*(\d+)\s*$/', (string) $pages, $matches ) ) {
			return true;
		}
		return self::text_contains( $scenario, $matches[1] ) && self::text_contains( $scenario, $matches[2] );
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
