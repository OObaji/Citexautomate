<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini-powered Citex question generation.
 *
 * The model generates structured question data only. Citex remains the
 * authority for validation and population; generated records enter the
 * existing pending queue and are never written directly to Reference List.
 */
class Citex_AI {

	const OPTION_API_KEY       = 'citex_gemini_api_key';
	const OPTION_MODEL         = 'citex_gemini_model';
	const OPTION_WEB_VERIFY    = 'citex_gemini_web_verify';
	const DEFAULT_MODEL        = 'gemini-3.7-flash';
	const API_URL               = 'https://generativelanguage.googleapis.com/v1beta/interactions';

	public static function get_api_key() {
		$env = getenv( 'GEMINI_API_KEY' );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return trim( $env );
		}
		return trim( (string) get_option( self::OPTION_API_KEY, '' ) );
	}

	public static function get_model() {
		$model = trim( (string) get_option( self::OPTION_MODEL, self::DEFAULT_MODEL ) );
		return '' !== $model ? $model : self::DEFAULT_MODEL;
	}

	public static function web_verification_enabled() {
		return (bool) get_option( self::OPTION_WEB_VERIFY, true );
	}

	public static function save_settings( $api_key, $model, $web_verify ) {
		$api_key = trim( (string) $api_key );
		$model   = trim( (string) $model );
		if ( '' !== $api_key ) {
			update_option( self::OPTION_API_KEY, $api_key, false );
		}
		update_option( self::OPTION_MODEL, '' !== $model ? sanitize_text_field( $model ) : self::DEFAULT_MODEL, false );
		update_option( self::OPTION_WEB_VERIFY, ! empty( $web_verify ), false );
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Citex AI settings.', 'citex-tools' ) );
		}

		if ( ! empty( $_POST['citex_ai_save_settings'] ) ) {
			check_admin_referer( 'citex_ai_settings', 'citex_ai_settings_nonce' );
			$api_key = isset( $_POST['citex_gemini_api_key'] ) ? wp_unslash( $_POST['citex_gemini_api_key'] ) : '';
			$model   = isset( $_POST['citex_gemini_model'] ) ? wp_unslash( $_POST['citex_gemini_model'] ) : self::DEFAULT_MODEL;
			$verify  = ! empty( $_POST['citex_gemini_web_verify'] );
			self::save_settings( $api_key, $model, $verify );
			Citex_Admin::set_notice( __( 'Gemini AI settings saved.', 'citex-tools' ), 'success' );
			wp_safe_redirect( admin_url( 'admin.php?page=citex-ai' ) );
			exit;
		}

		$has_key   = '' !== self::get_api_key();
		$model     = self::get_model();
		$web_verify = self::web_verification_enabled();
		require CITEX_TOOLS_PATH . 'admin/views/ai-settings.php';
	}

	public static function generate_questions( $args ) {
		$api_key = self::get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'citex_ai_no_key', __( 'Gemini is not configured. Add a Gemini API key in Citex → AI Settings first.', 'citex-tools' ) );
		}

		$quantity   = max( 1, min( 100, absint( $args['quantity'] ?? 10 ) ) );
		$start_id   = strtoupper( sanitize_text_field( $args['starting_id'] ?? 'BK01' ) );
		$difficulty = sanitize_key( $args['difficulty'] ?? 'medium' );
		$verify_web = isset( $args['web_verify'] ) ? (bool) $args['web_verify'] : self::web_verification_enabled();
		$ids        = self::build_ids( $start_id, $quantity, $args['used_ids'] ?? array() );

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		$system = self::system_instruction();
		$input  = self::build_prompt( $ids, $difficulty, $quantity, $verify_web );
		$schema = self::question_schema();

		$body = array(
			'model'            => self::get_model(),
			'input'            => $input,
			'system_instruction' => $system,
			'response_format'  => array(
				array(
					'type'      => 'text',
					'mime_type' => 'application/json',
					'schema'    => $schema,
				),
			),
			'generation_config' => array(
				'max_output_tokens' => max( 4000, min( 24000, $quantity * 650 ) ),
			),
		);

		if ( $verify_web ) {
			$body['tools'] = array( array( 'type' => 'google_search' ) );
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'citex_ai_request_failed', sprintf( __( 'Gemini request failed: %s', 'citex-tools' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Gemini returned an unexpected error.', 'citex-tools' );
			return new WP_Error( 'citex_ai_api_error', sprintf( __( 'Gemini API error (%1$d): %2$s', 'citex-tools' ), $code, $message ) );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'citex_ai_bad_response', __( 'Gemini returned an unreadable response.', 'citex-tools' ) );
		}

		$text = self::extract_output_text( $data );
		if ( '' === $text ) {
			return new WP_Error( 'citex_ai_empty_response', __( 'Gemini completed the request but returned no question data.', 'citex-tools' ) );
		}

		$decoded = json_decode( self::strip_json_fences( $text ), true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return new WP_Error( 'citex_ai_invalid_json', sprintf( __( 'Gemini returned invalid structured data: %s', 'citex-tools' ), json_last_error_msg() ) );
		}
		$questions = isset( $decoded['questions'] ) && is_array( $decoded['questions'] ) ? $decoded['questions'] : array();
		if ( count( $questions ) !== $quantity ) {
			return new WP_Error( 'citex_ai_wrong_count', sprintf( __( 'Gemini returned %1$d questions instead of the requested %2$d. Nothing was added to the pending queue.', 'citex-tools' ), count( $questions ), $quantity ) );
		}

		return self::normalise_questions( $questions, $ids, $difficulty );
	}

	private static function system_instruction() {
		return 'You are the Citex academic question-generation engine. Generate real, usable Liverpool Hope University Harvard ReferenceList Book DragDrop questions, not tests or examples. Bibliographic facts must be real; when web verification is enabled, verify them before returning them. Never invent a book, author, publisher, publication year, or place. Return only data matching the supplied JSON schema. Citex will validate every generated question independently before publication.';
	}

	private static function build_prompt( $ids, $difficulty, $quantity, $verify_web ) {
		$lines = array();
		foreach ( $ids as $id ) {
			$lines[] = $id;
		}
		$verification = $verify_web ? 'Use Google Search to verify each bibliographic record before returning it.' : 'Use your reliable knowledge; do not invent bibliographic records.';
		return sprintf(
			"Generate exactly %d new questions for Liverpool Hope University Harvard referencing.\n\nFormat: Harvard / ReferenceList / Book / DragDrop.\nDifficulty: %s.\n%s\n\nQuestion IDs, in this exact order:\n%s\n\nScenario rules:\n- Keep each scenario short and mobile-friendly (preferably under 220 characters).\n- Use natural wording such as 'You are creating a reference for a book titled...' or 'You are referencing a book titled...'.\n- State the real book title, author, publication year, publisher and publication place.\n- Prefer concise book titles where the real title permits; never truncate or alter the actual bibliographic title.\n- Do not write a long teaching explanation, a multi-part instruction, or a fictional story.\n\nDragDrop rules:\n- questionParts MUST contain exactly 4 draggable items, in this order: author surname, author initials, publication year, book title.\n- fixedText MUST contain exactly 4 placeholder positions.\n- A single '|' is allowed only at the beginning or end of the fixed text.\n- A middle placeholder MUST be written as '||'.\n- The canonical four-part structure is: '|, || (||) ||. Place: Publisher.'\n- Do not put a single '|' in an internal position.\n- The reconstructed answer must be: 'Surname, I. (YYYY) Book Title. Place: Publisher.'\n- There must be no full stop immediately after the year parentheses.\n- There must be a final full stop after the publisher.\n- Do not add spaces before punctuation.\n- Keep the punctuation and spacing suitable for the Citex validator.\n\nConfusing words:\n- Provide 3 plausible distractors for medium difficulty, 2 for easy, 4 for hard.\n- Distractors must not be any of the four correct Question Parts.\n- Distractors should be plausible alternatives such as another year, city, publisher, or unrelated book title.\n\nReturn exactly the requested number of distinct questions.\n\nIDs:\n%s",
			$quantity,
			ucfirst( $difficulty ),
			$verification,
			implode( ', ', $lines ),
			implode( ', ', $lines )
		);
	}

	private static function question_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'questions' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'questionId'      => array( 'type' => 'string' ),
							'scenario'        => array( 'type' => 'string' ),
							'authorSurname'   => array( 'type' => 'string' ),
							'authorInitials'  => array( 'type' => 'string' ),
							'year'            => array( 'type' => 'string' ),
							'bookTitle'       => array( 'type' => 'string' ),
							'place'            => array( 'type' => 'string' ),
							'publisher'        => array( 'type' => 'string' ),
							'questionParts'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
							'fixedText'        => array( 'type' => 'string' ),
							'confusingWords'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						),
						'required' => array( 'questionId', 'scenario', 'authorSurname', 'authorInitials', 'year', 'bookTitle', 'place', 'publisher', 'questionParts', 'fixedText', 'confusingWords' ),
					),
				),
			),
			'required' => array( 'questions' ),
		);
	}

	private static function build_ids( $starting_id, $quantity, $used_ids ) {
		if ( ! preg_match( '/^([A-Z]+)(\d+)$/', $starting_id, $matches ) ) {
			return new WP_Error( 'citex_ai_bad_start_id', __( 'Starting ID must look like BK01, BK25, or BOOK001.', 'citex-tools' ) );
		}
		$prefix = $matches[1];
		$number = absint( $matches[2] );
		$width  = max( 2, strlen( $matches[2] ) );
		$used   = array();
		foreach ( $used_ids as $id ) {
			$used[ strtoupper( trim( (string) $id ) ) ] = true;
		}
		$out = array();
		while ( count( $out ) < $quantity ) {
			$id = $prefix . str_pad( (string) $number, $width, '0', STR_PAD_LEFT );
			$number++;
			if ( isset( $used[ $id ] ) ) {
				continue;
			}
			$used[ $id ] = true;
			$out[]       = $id;
		}
		return $out;
	}

	private static function normalise_questions( $questions, $ids, $difficulty ) {
		$out = array();
		foreach ( $questions as $index => $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error( 'citex_ai_bad_question', sprintf( __( 'Question %d was not a valid object.', 'citex-tools' ), $index + 1 ) );
			}
			$id         = strtoupper( trim( (string) ( $ids[ $index ] ?? $item['questionId'] ?? '' ) ) );
			$parts      = array_values( array_map( 'trim', (array) ( $item['questionParts'] ?? array() ) ) );
			$fixed      = trim( (string) ( $item['fixedText'] ?? '' ) );
			$scenario   = trim( (string) ( $item['scenario'] ?? '' ) );
			$surname    = trim( (string) ( $item['authorSurname'] ?? '' ) );
			$initials   = trim( (string) ( $item['authorInitials'] ?? '' ) );
			$year       = trim( (string) ( $item['year'] ?? '' ) );
			$title      = trim( (string) ( $item['bookTitle'] ?? '' ) );
			$place      = trim( (string) ( $item['place'] ?? '' ) );
			$publisher  = trim( (string) ( $item['publisher'] ?? '' ) );
			$distractors = array_values( array_filter( array_map( 'trim', (array) ( $item['confusingWords'] ?? array() ) ), 'strlen' ) );

			if ( '' === $id || '' === $scenario || '' === $surname || '' === $initials || '' === $year || '' === $title || '' === $place || '' === $publisher ) {
				return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing required bibliographic data.', 'citex-tools' ), $id ?: ( $index + 1 ) ) );
			}
			if ( 4 !== count( $parts ) ) {
				return new WP_Error( 'citex_ai_bad_parts', sprintf( __( 'Question %s must contain exactly 4 Question Parts.', 'citex-tools' ), $id ) );
			}
			$placeholder_count = substr_count( $fixed, '|' );
			if ( 4 !== $placeholder_count ) {
				return new WP_Error( 'citex_ai_bad_placeholders', sprintf( __( 'Question %s must contain exactly 4 placeholder bars in Fixed Text.', 'citex-tools' ), $id ) );
			}
			if ( preg_match( '/(^|[^|])\|([^|]|$)/', $fixed, $m ) && false !== strpos( trim( $fixed ), '|' ) ) {
				// A single bar is only valid at the very beginning or very end.
				$len = strlen( $fixed );
				for ( $i = 0; $i < $len; $i++ ) {
					if ( '|' !== $fixed[ $i ] ) {
						continue;
					}
					$left  = $i > 0 ? $fixed[ $i - 1 ] : '';
					$right = $i + 1 < $len ? $fixed[ $i + 1 ] : '';
					if ( ( '' === $left && '|' !== $right ) || ( '' === $right && '|' !== $left ) ) {
						continue;
					}
					if ( '|' !== $left && '|' !== $right ) {
						return new WP_Error( 'citex_ai_bad_placeholder_encoding', sprintf( __( 'Question %s contains a single internal placeholder bar.', 'citex-tools' ), $id ) );
					}
				}
			}
			$expected_parts = array( $surname, $initials, $year, $title );
			if ( $parts !== $expected_parts ) {
				return new WP_Error( 'citex_ai_parts_mismatch', sprintf( __( 'Question %s Question Parts do not match its bibliographic fields.', 'citex-tools' ), $id ) );
			}
			$reference = sprintf( '%s, %s (%s) %s. %s: %s.', $surname, $initials, $year, $title, $place, $publisher );
			$out[] = array(
				'key'                    => wp_generate_uuid4(),
				'questionId'             => $id,
				'title'                  => sprintf( 'Harvard | ReferenceList | Book | DragDrop | %s', $id ),
				'source'                 => 'Harvard',
				'group'                  => 'ReferenceList',
				'category'               => 'Book',
				'type'                   => 'DragDrop',
				'institution'            => 'Liverpool Hope University',
				'difficulty'             => ucfirst( $difficulty ),
				'scenario'               => sanitize_textarea_field( $scenario ),
				'fixedText'              => sanitize_text_field( $fixed ),
				'questionParts'          => array_values( array_map( 'sanitize_text_field', $parts ) ),
				'confusingWords'         => array_values( array_map( 'sanitize_text_field', $distractors ) ),
				'reconstructedReference' => sanitize_text_field( $reference ),
				'status'                 => 'pending',
				'validationStatus'       => 'not_validated',
				'validationErrors'       => array(),
				'origin'                 => 'generated_ai',
				'aiProvider'             => 'Gemini',
				'aiModel'                => self::get_model(),
				'generatedAt'            => gmdate( 'c' ),
			);
		}
		return $out;
	}

	private static function extract_output_text( $data ) {
		if ( ! empty( $data['output_text'] ) && is_string( $data['output_text'] ) ) {
			return trim( $data['output_text'] );
		}
		$texts = array();
		foreach ( (array) ( $data['steps'] ?? array() ) as $step ) {
			if ( 'model_output' !== ( $step['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $step['content'] ?? array() ) as $content ) {
				if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
					$texts[] = $content['text'];
				}
			}
		}
		return trim( implode( "\n", $texts ) );
	}

	private static function strip_json_fences( $text ) {
		$text = trim( $text );
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );
		return trim( $text );
	}
}
