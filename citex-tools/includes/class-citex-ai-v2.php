<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Citex_AI_V2 {
	const OPTION_API_KEY = 'citex_gemini_api_key';
	const OPTION_MODEL = 'citex_gemini_model';
	const OPTION_WEB_VERIFY = 'citex_gemini_web_verify';
	const DEFAULT_MODEL = 'gemini-3.7-flash';
	const API_URL = 'https://generativelanguage.googleapis.com/v1beta/interactions';
	const MAX_QUALITY_ATTEMPTS = 3;

	public static function get_api_key() {
		$env = getenv( 'GEMINI_API_KEY' );
		return is_string( $env ) && '' !== trim( $env ) ? trim( $env ) : trim( (string) get_option( self::OPTION_API_KEY, '' ) );
	}
	public static function get_model() {
		$model = trim( (string) get_option( self::OPTION_MODEL, self::DEFAULT_MODEL ) );
		return '' !== $model ? $model : self::DEFAULT_MODEL;
	}
	public static function web_verification_enabled() { return (bool) get_option( self::OPTION_WEB_VERIFY, true ); }
	public static function save_settings( $api_key, $model, $web_verify ) {
		if ( '' !== trim( (string) $api_key ) ) { update_option( self::OPTION_API_KEY, trim( (string) $api_key ), false ); }
		update_option( self::OPTION_MODEL, '' !== trim( (string) $model ) ? sanitize_text_field( $model ) : self::DEFAULT_MODEL, false );
		update_option( self::OPTION_WEB_VERIFY, ! empty( $web_verify ), false );
	}
	public static function maybe_handle_submit() {
		if ( empty( $_POST['citex_ai_save_settings'] ) ) { return; }
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to manage Citex AI settings.', 'citex-tools' ) ); }
		check_admin_referer( 'citex_ai_settings', 'citex_ai_settings_nonce' );
		self::save_settings( isset( $_POST['citex_gemini_api_key'] ) ? wp_unslash( $_POST['citex_gemini_api_key'] ) : '', isset( $_POST['citex_gemini_model'] ) ? wp_unslash( $_POST['citex_gemini_model'] ) : self::DEFAULT_MODEL, ! empty( $_POST['citex_gemini_web_verify'] ) );
		Citex_Admin::set_notice( __( 'Gemini AI settings saved.', 'citex-tools' ), 'success' );
		wp_safe_redirect( admin_url( 'admin.php?page=citex-ai' ) ); exit;
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to manage Citex AI settings.', 'citex-tools' ) ); }
		self::maybe_handle_submit();
		$has_key = '' !== self::get_api_key(); $model = self::get_model(); $web_verify = self::web_verification_enabled();
		require CITEX_TOOLS_PATH . 'admin/views/ai-settings.php';
	}

	public static function generate_questions( $args ) {
		$key = self::get_api_key();
		if ( '' === $key ) { return new WP_Error( 'citex_ai_no_key', __( 'Gemini is not configured. Add a Gemini API key in Citex → AI Settings first.', 'citex-tools' ) ); }
		$quantity = max( 1, min( 100, absint( $args['quantity'] ?? 10 ) ) );
		$difficulty = sanitize_key( $args['difficulty'] ?? 'medium' );
		$verify = isset( $args['web_verify'] ) ? (bool) $args['web_verify'] : self::web_verification_enabled();
		$ids = self::build_ids( strtoupper( sanitize_text_field( $args['starting_id'] ?? 'BK01' ) ), $quantity, $args['used_ids'] ?? array() );
		if ( is_wp_error( $ids ) ) { return $ids; }

		$last_error = '';
		for ( $attempt = 1; $attempt <= self::MAX_QUALITY_ATTEMPTS; $attempt++ ) {
			$body = array(
				'model' => self::get_model(),
				'input' => self::build_prompt( $ids, $difficulty, $verify, $last_error ),
				'system_instruction' => 'You are Citex, an academic question-generation engine. Generate real, usable Liverpool Hope University Harvard ReferenceList Book DragDrop questions, not tests or fictional examples. Verify bibliographic facts when web verification is enabled. Never invent books, authors, years, publishers, or places. Before returning each question, perform a strict self-check: the four Question Parts must be exactly surname, initials, year and title; Fixed Text must reconstruct the Liverpool Hope format; and every confusing word must be unique and different from every correct Question Part. Return only the requested JSON.',
				'response_format' => array( array( 'type' => 'text', 'mime_type' => 'application/json', 'schema' => self::schema() ) ),
				'generation_config' => array( 'max_output_tokens' => max( 4000, min( 24000, $quantity * 650 ) ) ),
			);
			if ( $verify ) { $body['tools'] = array( array( 'type' => 'google_search' ) ); }
			$response = wp_remote_post( self::API_URL, array( 'timeout' => 120, 'headers' => array( 'Content-Type' => 'application/json', 'x-goog-api-key' => $key ), 'body' => wp_json_encode( $body ) ) );
			if ( is_wp_error( $response ) ) { return new WP_Error( 'citex_ai_request_failed', sprintf( __( 'Gemini request failed: %s', 'citex-tools' ), $response->get_error_message() ) ); }
			$code = (int) wp_remote_retrieve_response_code( $response ); $data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( $code < 200 || $code >= 300 ) { $message = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Gemini returned an unexpected error.', 'citex-tools' ); return new WP_Error( 'citex_ai_api_error', sprintf( __( 'Gemini API error (%1$d): %2$s', 'citex-tools' ), $code, $message ) ); }
			$text = self::output_text( is_array( $data ) ? $data : array() ); $decoded = json_decode( self::strip_fences( $text ), true );
			if ( '' === $text || JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) { return new WP_Error( 'citex_ai_invalid_json', __( 'Gemini did not return valid structured question data.', 'citex-tools' ) ); }
			$questions = isset( $decoded['questions'] ) && is_array( $decoded['questions'] ) ? $decoded['questions'] : array();
			if ( count( $questions ) !== $quantity ) { $last_error = sprintf( 'The previous attempt returned %d questions instead of %d. Return exactly %d.', count( $questions ), $quantity, $quantity ); continue; }
			$result = self::normalise( $questions, $ids, $difficulty );
			if ( ! is_wp_error( $result ) ) { return $result; }
			$last_error = $result->get_error_message();
		}

		return new WP_Error( 'citex_ai_quality_failed', sprintf( __( 'Gemini could not produce a fully valid batch after %d quality checks. Nothing was added. Last issue: %s', 'citex-tools' ), self::MAX_QUALITY_ATTEMPTS, $last_error ) );
	}

	private static function build_prompt( $ids, $difficulty, $verify, $quality_feedback = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct Liverpool Hope University Harvard / ReferenceList / Book / DragDrop questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify every bibliographic record.' : 'Do not invent bibliographic records.' ) . "\n\nSCENARIOS:\n- Keep each scenario short and mobile-friendly, preferably under 220 characters.\n- Use natural wording such as 'You are creating a reference for a book titled...' or 'You are referencing a book titled...'.\n- State the real book title, author, publication year, publisher and publication place.\n- Prefer concise real book titles; never truncate or alter the actual bibliographic title.\n\nDRAGDROP:\n- questionParts must contain exactly 4 items: surname, initials, year, book title.\n- fixedText must contain exactly 4 draggable placeholder TOKENS.\n- A single | token is allowed only at the beginning or end. Every internal placeholder token MUST be ||.\n- Canonical fixedText: |, || (||) ||. Place: Publisher.\n- Do not use a single internal |.\n- Reconstructed answer: Surname, I. (YYYY) Book Title. Place: Publisher.\n- No full stop after the year parentheses; no spaces before punctuation; one space after the colon; final full stop required.\n\nDISTRACTORS — CRITICAL:\n- Medium exactly 3; Easy exactly 2; Hard exactly 4.\n- Every distractor must be different from ALL FOUR correct Question Parts after trimming and case-insensitive comparison.\n- Distractors must also be unique from one another.\n- Do not use the correct title, surname, initials, year or any exact copy of a correct part as a distractor.\n- Before returning each question, compare every confusingWords value against all four Question Parts and replace any match.\n- Prefer plausible alternatives such as another year, city, publisher, author surname, or book title.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. Four Question Parts exactly match surname, initials, year, title.\n2. Fixed Text has exactly four placeholder positions and reconstructs the required reference.\n3. No unwanted punctuation or spacing errors.\n4. Correct number of distractors for the difficulty.\n5. Zero distractors match any correct Question Part.\n6. Zero duplicate distractors.\n7. Only return questions that pass all seven checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	private static function schema() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'scenario' => $s, 'authorSurname' => $s, 'authorInitials' => $s, 'year' => $s, 'bookTitle' => $s, 'place' => $s, 'publisher' => $s,
			'questionParts' => array( 'type' => 'array', 'items' => $s ), 'fixedText' => $s, 'confusingWords' => array( 'type' => 'array', 'items' => $s )
		), 'required' => array( 'questionId','scenario','authorSurname','authorInitials','year','bookTitle','place','publisher','questionParts','fixedText','confusingWords' ) ) ) ), 'required' => array( 'questions' ) );
	}
	private static function build_ids( $start, $quantity, $used_ids ) {
		if ( ! preg_match( '/^([A-Z]+)(\d+)$/', $start, $m ) ) { return new WP_Error( 'citex_ai_bad_start_id', __( 'Starting ID must look like BK01, BK25, or BOOK001.', 'citex-tools' ) ); }
		$prefix = $m[1]; $number = absint( $m[2] ); $width = max( 2, strlen( $m[2] ) ); $used = array(); foreach ( $used_ids as $id ) { $used[ strtoupper( trim( (string) $id ) ) ] = true; }
		$out = array(); while ( count( $out ) < $quantity ) { $id = $prefix . str_pad( (string) $number++, $width, '0', STR_PAD_LEFT ); if ( isset( $used[ $id ] ) ) { continue; } $used[ $id ] = true; $out[] = $id; } return $out;
	}
	private static function placeholder_count( $fixed ) {
		$count = 0; $len = strlen( $fixed );
		for ( $i = 0; $i < $len; ) {
			if ( '|' !== $fixed[ $i ] ) { $i++; continue; }
			if ( $i + 1 < $len && '|' === $fixed[ $i + 1 ] ) { $count++; $i += 2; continue; }
			$before = substr( $fixed, 0, $i ); $after = substr( $fixed, $i + 1 );
			$is_first = '' === trim( $before ); $is_final = 1 === preg_match( '/^[\s\.,;:!?\-–—]*$/u', $after );
			if ( ! $is_first && ! $is_final ) { return new WP_Error( 'citex_ai_bad_placeholder_encoding', 'A single internal | is not allowed; internal draggable placeholders must use ||.' ); }
			$count++; $i++;
		}
		return $count;
	}
	private static function expected_distractor_count( $difficulty ) {
		switch ( sanitize_key( $difficulty ) ) {
			case 'easy': return 2;
			case 'hard': return 4;
			default: return 3;
		}
	}
	private static function normalise( $questions, $ids, $difficulty ) {
		$out = array();
		$expected_distractors = self::expected_distractor_count( $difficulty );
		foreach ( $questions as $i => $item ) {
			if ( ! is_array( $item ) ) { return new WP_Error( 'citex_ai_bad_question', sprintf( __( 'Question %d was not a valid object.', 'citex-tools' ), $i + 1 ) ); }
			$id = strtoupper( trim( (string) $ids[ $i ] ) ); $surname = trim( (string) ( $item['authorSurname'] ?? '' ) ); $initials = trim( (string) ( $item['authorInitials'] ?? '' ) ); $year = trim( (string) ( $item['year'] ?? '' ) ); $title = trim( (string) ( $item['bookTitle'] ?? '' ) ); $place = trim( (string) ( $item['place'] ?? '' ) ); $publisher = trim( (string) ( $item['publisher'] ?? '' ) ); $scenario = trim( (string) ( $item['scenario'] ?? '' ) ); $fixed = trim( (string) ( $item['fixedText'] ?? '' ) );
			$parts = array_values( array_map( 'trim', (array) ( $item['questionParts'] ?? array() ) ) ); $distractors = array_values( array_filter( array_map( 'trim', (array) ( $item['confusingWords'] ?? array() ) ), 'strlen' ) );
			if ( '' === $scenario || '' === $surname || '' === $initials || '' === $year || '' === $title || '' === $place || '' === $publisher ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing required bibliographic data.', 'citex-tools' ), $id ) ); }
			if ( 4 !== count( $parts ) || array( $surname, $initials, $year, $title ) !== $parts ) { return new WP_Error( 'citex_ai_parts_mismatch', sprintf( __( 'Question %s Question Parts must exactly match surname, initials, year and title.', 'citex-tools' ), $id ) ); }
			$count = self::placeholder_count( $fixed ); if ( is_wp_error( $count ) ) { return $count; } if ( 4 !== $count ) { return new WP_Error( 'citex_ai_bad_placeholders', sprintf( __( 'Question %s has %d draggable placeholder tokens; exactly 4 are required.', 'citex-tools' ), $id, $count ) ); }
			if ( count( $distractors ) !== $expected_distractors ) { return new WP_Error( 'citex_ai_bad_distractors', sprintf( __( 'Question %s has %d distractors; %d are required for %s difficulty.', 'citex-tools' ), $id, count( $distractors ), $expected_distractors, ucfirst( $difficulty ) ) ); }
			$correct_lower = array_map( 'strtolower', array_map( 'trim', $parts ) ); $seen = array();
			foreach ( $distractors as $distractor ) {
				$normal = strtolower( trim( $distractor ) );
				if ( in_array( $normal, $correct_lower, true ) ) { return new WP_Error( 'citex_ai_distractor_matches_part', sprintf( __( 'Question %s has a distractor that duplicates a correct Question Part: %s.', 'citex-tools' ), $id, $distractor ) ); }
				if ( isset( $seen[ $normal ] ) ) { return new WP_Error( 'citex_ai_duplicate_distractor', sprintf( __( 'Question %s has a duplicate distractor: %s.', 'citex-tools' ), $id, $distractor ) ); }
				$seen[ $normal ] = true;
			}
			$reference = sprintf( '%s, %s (%s) %s. %s: %s.', $surname, $initials, $year, $title, $place, $publisher );
			$candidate = array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'Harvard | ReferenceList | Book | DragDrop | %s', $id ), 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Book', 'type' => 'DragDrop', 'institution' => 'Liverpool Hope University', 'difficulty' => ucfirst( $difficulty ), 'scenario' => sanitize_textarea_field( $scenario ), 'fixedText' => sanitize_text_field( $fixed ), 'questionParts' => array_values( array_map( 'sanitize_text_field', $parts ) ), 'confusingWords' => array_values( array_map( 'sanitize_text_field', $distractors ) ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
			$validation = Citex_Generated_Validator::validate( $candidate );
			if ( 'passed' !== $validation['status'] ) { $first_error = ! empty( $validation['errors'][0]['message'] ) ? $validation['errors'][0]['message'] : __( 'Generated question failed Citex validation.', 'citex-tools' ); return new WP_Error( 'citex_ai_validator_rejected', sprintf( __( 'Question %s failed the pre-queue quality gate: %s', 'citex-tools' ), $id, $first_error ) ); }
			$candidate['validatedReference'] = $validation['reconstructedReference'];
			$candidate['validationStatus'] = 'passed';
			$candidate['validationErrors'] = array();
			$candidate['validatedAt'] = $validation['validatedAt'];
			$out[] = $candidate;
		}
		return $out;
	}
	private static function output_text( $data ) {
		if ( ! empty( $data['output_text'] ) && is_string( $data['output_text'] ) ) { return trim( $data['output_text'] ); }
		$text = array(); foreach ( (array) ( $data['steps'] ?? array() ) as $step ) { if ( 'model_output' !== ( $step['type'] ?? '' ) ) { continue; } foreach ( (array) ( $step['content'] ?? array() ) as $content ) { if ( isset( $content['text'] ) && is_string( $content['text'] ) ) { $text[] = $content['text']; } } } return trim( implode( "\n", $text ) );
	}
	private static function strip_fences( $text ) { $text = trim( $text ); $text = preg_replace( '/^```(?:json)?\s*/i', '', $text ); $text = preg_replace( '/\s*```$/', '', $text ); return trim( $text ); }
}
