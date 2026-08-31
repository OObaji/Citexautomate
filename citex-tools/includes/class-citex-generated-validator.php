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
		$errors = array();

		if (
			'Harvard' !== (string) ( $question['source'] ?? '' ) ||
			'ReferenceList' !== (string) ( $question['group'] ?? '' ) ||
			'Book' !== (string) ( $question['category'] ?? '' ) ||
			'DragDrop' !== (string) ( $question['type'] ?? '' )
		) {
			return self::result(
				'failed',
				array(
					self::error( 'UNSUPPORTED_GENERATED_FORMAT', 'Generated validation currently supports only Harvard / ReferenceList / Book / DragDrop.' ),
				),
				null
			);
		}

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

		// Liverpool Hope Book shape used by the current Citex Book validator:
		// Surname, I. (Year) Title. Place: Publisher.
		if ( ! preg_match( '/^[^,]+,\s+(?:[A-Z]\.\s*)+\(\d{4}\)\s+.+\.\s+[^:]+:\s+.+\.\s*$/u', $reference ) ) {
			$errors[] = self::error( 'BOOK_FORMAT_MISMATCH', 'Citation does not match the Liverpool Hope Book format.' );
		}

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

		return self::result( empty( $errors ) ? 'passed' : 'failed', $errors, $reference );
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
