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
		// 'MCQ' is the only other supported type — anything else (including the
		// default) is the original DragDrop path, so this can never silently
		// switch an existing caller onto a different question shape.
		$type = 'mcq' === sanitize_key( $args['type'] ?? 'dragdrop' ) ? 'MCQ' : 'DragDrop';
		$ids = self::build_ids( strtoupper( sanitize_text_field( $args['starting_id'] ?? 'BK01' ) ), $quantity, $args['used_ids'] ?? array() );
		if ( is_wp_error( $ids ) ) { return $ids; }
		// Citex assigns each slot's Exercise deterministically before any
		// Gemini request is made (see Citex_Generator::build_exercise_assignments());
		// Gemini's schema has no exercise field, so nothing from its response
		// is ever consulted for this.
		$exercises = array_values( (array) ( $args['exercise_assignments'] ?? array() ) );

		$last_error = '';
		for ( $attempt = 1; $attempt <= self::MAX_QUALITY_ATTEMPTS; $attempt++ ) {
			$body = array(
				'model' => self::get_model(),
				'input' => 'MCQ' === $type ? self::build_prompt_mcq( $ids, $difficulty, $verify, $last_error ) : self::build_prompt( $ids, $difficulty, $verify, $last_error ),
				'system_instruction' => 'MCQ' === $type
					? 'You are Citex, an academic question-generation engine. Generate real, usable Liverpool Hope University Harvard ReferenceList Book multiple-choice questions, not tests or fictional examples. Verify bibliographic facts when web verification is enabled. Never invent books, authors, years, publishers, or places. Every question must describe exactly ONE canonical bibliographic record: authorFullName, year, bookTitle, place and publisher must all refer to the same real book, and the scenario text must explicitly name that same title, author, year, place and publisher — never a different edition or a different book. Citex constructs the single correctly-formatted Harvard reference itself from authorFullName/year/bookTitle/place/publisher — you only ever provide THREE plausible but incorrectly-formatted references for the same book (incorrectReferences), never the correct one itself. CRITICAL — the scenario must state the author\'s full real name naturally (for example "Alan Bryman") and must NEVER state, label, or abbreviate the author\'s initials or surname separately, must NEVER show a completed or abbreviated Harvard reference anywhere in the scenario (never write anything like "Bryman, A."), and must NEVER use the words "initial" or "surname" — the student must recognise the correctly-formatted reference among the options themselves, not be handed it in the scenario. Before returning each question, perform a strict self-check: scenario, authorFullName, year, bookTitle, place and publisher all describe the same book with no contradictions; the scenario reveals no answer value; and all three incorrectReferences are clearly wrong (a formatting, punctuation, or ordering mistake), mutually distinct from each other, and distinct from the correct reference you did not provide. Return only the requested JSON.'
					: 'You are Citex, an academic question-generation engine. Generate real, usable Liverpool Hope University Harvard ReferenceList Book DragDrop questions, not tests or fictional examples. Verify bibliographic facts when web verification is enabled. Never invent books, authors, years, publishers, or places. Every question must describe exactly ONE canonical bibliographic record: authorFullName, year, bookTitle, place and publisher must all refer to the same real book, and the scenario text must explicitly name that same title, author, year, place and publisher — never a different edition or a different book. Citex derives the author\'s surname and initials itself from authorFullName — you never provide them separately — and constructs Question Parts and Fixed Text itself from authorFullName/year/bookTitle/place/publisher, so your questionParts and fixedText values are for your own self-check only and are not read as authoritative. CRITICAL — the scenario must state the author\'s full real name naturally (for example "Alan Bryman") and must NEVER state, label, or abbreviate the author\'s initials or surname separately, must NEVER show a completed or abbreviated Harvard reference (never write anything like "Bryman, A."), and must NEVER use the words "initial" or "surname" — the student must derive the initials and the Harvard format themselves from the full name you provide. Before returning each question, perform a strict self-check: scenario, authorFullName, year, bookTitle, place and publisher must all describe the same book with no contradictions; the scenario must not reveal any answer value by labelling it as a surname, initial, year blank, title blank, or reference component; and every confusing word must be unique and different from every correct Question Part. Return only the requested JSON.',
				'response_format' => array( array( 'type' => 'text', 'mime_type' => 'application/json', 'schema' => 'MCQ' === $type ? self::schema_mcq() : self::schema() ) ),
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
			$result = self::normalise( $questions, $ids, $difficulty, $exercises, $type );
			if ( ! is_wp_error( $result ) ) { return $result; }
			$last_error = $result->get_error_message();
		}

		return new WP_Error( 'citex_ai_quality_failed', sprintf( __( 'Gemini could not produce a fully valid batch after %d quality checks. Nothing was added. Last issue: %s', 'citex-tools' ), self::MAX_QUALITY_ATTEMPTS, $last_error ) );
	}

	private static function build_prompt( $ids, $difficulty, $verify, $quality_feedback = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct Liverpool Hope University Harvard / ReferenceList / Book / DragDrop questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify every bibliographic record.' : 'Do not invent bibliographic records.' ) . "\n\nONE QUESTION = ONE CANONICAL BIBLIOGRAPHIC RECORD — CRITICAL:\n- authorFullName, year, bookTitle, place and publisher must all describe the exact same real book. Do not mix facts from a different edition, a different book by the same author, or a similarly-named book.\n- authorFullName is the author's real full name (given name(s) + surname), e.g. \"Alan Bryman\" or \"John Michael Smith\". Do NOT provide a surname or initials separately — Citex derives both itself from authorFullName.\n- The scenario MUST explicitly state that same bookTitle, the same author's full name, the same year, the same place and the same publisher. Citex independently checks the scenario text against these fields and rejects the question if any of them is not named in the scenario.\n\nSCENARIOS — ANSWER LEAKAGE IS A CRITICAL FAILURE:\n- Keep each scenario short and mobile-friendly, preferably under 220 characters.\n- Use natural wording such as 'You are creating a reference for a book titled...' or 'You are referencing a book titled...'.\n- State the real book title, the author's FULL NAME, publication year, publisher and publication place.\n- Prefer concise real book titles; never truncate or alter the actual bibliographic title.\n- The scenario MUST NOT state, label, or abbreviate the author's initials or surname separately, MUST NOT use the words \"initial\" or \"initials\" or \"surname\" anywhere, and MUST NOT show any completed or abbreviated Harvard reference (e.g. never write \"Bryman, A.\" or \"Bryman, A. (2012)\").\n- GOOD: \"You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.\"\n- BAD: \"...by Alan Bryman (initials A.), published in 2012...\" — reveals the initials directly.\n- BAD: \"...by Bryman, A., published in 2012...\" — states the abbreviated citation form directly.\n- BAD: \"The author's surname is Bryman and his initials are A.\" — explicitly labels both answers.\n- A full author name naturally containing the surname (e.g. \"Alan Bryman\") is correct and required — the failure is explicitly labelling or abbreviating an answer value, not the surname appearing as part of the full name.\n- The student must transform the full bibliographic information you give into the Harvard reference themselves; do not do that transformation for them anywhere in the scenario.\n\nDRAGDROP:\n- Citex derives the author's surname/initials from authorFullName and constructs Question Parts and Fixed Text itself from surname/initials/year/bookTitle/place/publisher — your questionParts and fixedText fields are used only for your own self-check and are not read as authoritative, so make sure they exactly match those fields too (surname = authorFullName's last word; initials = the first letter of every other word in authorFullName, each followed by a full stop, no spaces, e.g. \"John Michael Smith\" -> \"J.M.\").\n- questionParts must contain exactly 4 items: surname, initials, year, book title.\n- fixedText must contain exactly 4 draggable placeholder TOKENS.\n- A single | token is allowed only at the beginning or end. Every internal placeholder token MUST be ||.\n- Canonical fixedText: |, || (||) ||. Place: Publisher.\n- Do not use a single internal |.\n- Reconstructed answer: Surname, I. (YYYY) Book Title. Place: Publisher.\n- No full stop after the year parentheses; no spaces before punctuation; one space after the colon; final full stop required.\n\nDISTRACTORS — CRITICAL:\n- Medium exactly 3; Easy exactly 2; Hard exactly 4.\n- Every distractor must be different from ALL FOUR correct Question Parts after trimming and case-insensitive comparison.\n- Distractors must also be unique from one another.\n- Do not use the correct title, surname, initials, year or any exact copy of a correct part as a distractor.\n- Before returning each question, compare every confusingWords value against all four Question Parts and replace any match.\n- Prefer plausible alternatives such as another year, city, publisher, author surname, or book title.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. scenario, authorFullName, year, bookTitle, place and publisher all describe the exact same book — no contradictions.\n2. The scenario states the author's full name naturally and never the words \"initial\"/\"initials\"/\"surname\", and never a completed or abbreviated reference.\n3. Four Question Parts exactly match surname, initials, year, title, correctly derived from authorFullName.\n4. Fixed Text has exactly four placeholder positions and reconstructs the required reference.\n5. No unwanted punctuation or spacing errors.\n6. Correct number of distractors for the difficulty.\n7. Zero distractors match any correct Question Part.\n8. Zero duplicate distractors.\n9. Only return questions that pass all nine checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	private static function build_prompt_mcq( $ids, $difficulty, $verify, $quality_feedback = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct Liverpool Hope University Harvard / ReferenceList / Book multiple-choice questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify every bibliographic record.' : 'Do not invent bibliographic records.' ) . "\n\nONE QUESTION = ONE CANONICAL BIBLIOGRAPHIC RECORD — CRITICAL:\n- authorFullName, year, bookTitle, place and publisher must all describe the exact same real book. Do not mix facts from a different edition, a different book by the same author, or a similarly-named book.\n- authorFullName is the author's real full name (given name(s) + surname), e.g. \"Alan Bryman\" or \"John Michael Smith\". Do NOT provide a surname or initials separately — Citex derives both itself from authorFullName and constructs the one correct Harvard reference from them; you never provide the correct reference yourself.\n- The scenario MUST explicitly state that same bookTitle, the same author's full name, the same year, the same place and the same publisher. Citex independently checks the scenario text against these fields and rejects the question if any of them is not named in the scenario.\n\nSCENARIOS — ANSWER LEAKAGE IS A CRITICAL FAILURE:\n- Keep each scenario short and mobile-friendly, preferably under 220 characters.\n- Use natural wording such as 'You are creating a reference for a book titled...' or 'You are referencing a book titled...'.\n- State the real book title, the author's FULL NAME, publication year, publisher and publication place.\n- Prefer concise real book titles; never truncate or alter the actual bibliographic title.\n- The scenario MUST NOT state, label, or abbreviate the author's initials or surname separately, MUST NOT use the words \"initial\" or \"initials\" or \"surname\" anywhere, and MUST NOT show any completed or abbreviated Harvard reference anywhere (e.g. never write \"Bryman, A.\" or \"Bryman, A. (2012)\") — this applies even though you are not asked to provide the correct reference yourself, because the correct option Citex constructs must not already be visible in the scenario text.\n- GOOD: \"You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.\"\n- BAD: \"...by Alan Bryman (initials A.), published in 2012...\" — reveals the initials directly.\n- BAD: \"...by Bryman, A., published in 2012...\" — states the abbreviated citation form directly.\n- A full author name naturally containing the surname (e.g. \"Alan Bryman\") is correct and required — the failure is explicitly labelling or abbreviating an answer value, not the surname appearing as part of the full name.\n\nINCORRECT REFERENCES — CRITICAL:\n- Provide exactly 3 incorrectReferences for the same book — plausible-looking but definitely wrong Harvard Book references.\n- Each one must contain a genuine formatting mistake compared to the correct format 'Surname, I. (YYYY) Book Title. Place: Publisher.' — for example: wrong element order, a missing or misplaced full stop/comma, a missing space after the colon, the year not in parentheses, or initials written in full instead of abbreviated.\n- Do NOT simply invent a different author, year, title, place or publisher for an incorrectReference — every incorrectReference must still describe the SAME book/author/year/place/publisher as the scenario, just formatted incorrectly. A reference describing a different book is not a plausible distractor and will be rejected.\n- All three incorrectReferences must be different from one another.\n- None of the three may, even coincidentally, already be a correctly-formatted 'Surname, I. (YYYY) Title. Place: Publisher.' string.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. scenario, authorFullName, year, bookTitle, place and publisher all describe the exact same book — no contradictions.\n2. The scenario states the author's full name naturally and never the words \"initial\"/\"initials\"/\"surname\", and never a completed or abbreviated reference.\n3. Exactly 3 incorrectReferences are provided, each for the same book, each with a genuine formatting mistake.\n4. All 3 incorrectReferences are mutually distinct and none is accidentally correctly formatted.\n5. Only return questions that pass all five checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	private static function schema() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'scenario' => $s, 'authorFullName' => $s, 'year' => $s, 'bookTitle' => $s, 'place' => $s, 'publisher' => $s,
			'questionParts' => array( 'type' => 'array', 'items' => $s ), 'fixedText' => $s, 'confusingWords' => array( 'type' => 'array', 'items' => $s )
		), 'required' => array( 'questionId','scenario','authorFullName','year','bookTitle','place','publisher','questionParts','fixedText','confusingWords' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * MCQ schema: Gemini supplies the same canonical bibliographic fields as
	 * DragDrop, plus exactly THREE plausible-but-incorrect Harvard reference
	 * strings — never the correct one. Citex constructs the single correct
	 * option itself (see normalise_mcq_item()), the same "Citex is the sole
	 * authority for the correct answer" principle already used for DragDrop's
	 * Question Parts/Fixed Text.
	 */
	private static function schema_mcq() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'scenario' => $s, 'authorFullName' => $s, 'year' => $s, 'bookTitle' => $s, 'place' => $s, 'publisher' => $s,
			'incorrectReferences' => array( 'type' => 'array', 'items' => $s )
		), 'required' => array( 'questionId','scenario','authorFullName','year','bookTitle','place','publisher','incorrectReferences' ) ) ) ), 'required' => array( 'questions' ) );
	}
	/**
	 * Citex — never Gemini — is the sole authority for the author's surname
	 * and initials: both are derived deterministically from authorFullName
	 * rather than asked of Gemini directly, so there is no separate
	 * "initials" value Gemini could leak into the scenario even by mistake.
	 * Initials are every given-name word's first letter, uppercased and
	 * followed by a full stop, concatenated with no spaces (e.g. "John
	 * Michael Smith" -> surname "Smith", initials "J.M.").
	 *
	 * @return array{surname: string, initials: string}|WP_Error
	 */
	private static function derive_author_parts( $full_name ) {
		$full_name = trim( preg_replace( '/\s+/', ' ', (string) $full_name ) );
		$tokens = '' === $full_name ? array() : array_values( array_filter( explode( ' ', $full_name ), 'strlen' ) );
		if ( count( $tokens ) < 2 ) {
			return new WP_Error( 'citex_ai_incomplete_author_name', __( 'Author full name must include at least one given name and a surname.', 'citex-tools' ) );
		}
		$surname = array_pop( $tokens );
		$initials = '';
		foreach ( $tokens as $given_name ) {
			$letter = mb_substr( $given_name, 0, 1 );
			if ( '' === $letter ) {
				continue;
			}
			$initials .= mb_strtoupper( $letter ) . '.';
		}
		if ( '' === $initials ) {
			return new WP_Error( 'citex_ai_incomplete_author_name', __( 'Author full name must include at least one given name and a surname.', 'citex-tools' ) );
		}
		return array( 'surname' => $surname, 'initials' => $initials );
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
	private static function normalise( $questions, $ids, $difficulty, $exercises = array(), $type = 'DragDrop' ) {
		$out = array();
		$expected_distractors = self::expected_distractor_count( $difficulty );
		foreach ( $questions as $i => $item ) {
			if ( ! is_array( $item ) ) { return new WP_Error( 'citex_ai_bad_question', sprintf( __( 'Question %d was not a valid object.', 'citex-tools' ), $i + 1 ) ); }
			$id = strtoupper( trim( (string) $ids[ $i ] ) ); $full_name = trim( (string) ( $item['authorFullName'] ?? '' ) ); $year = trim( (string) ( $item['year'] ?? '' ) ); $title = trim( (string) ( $item['bookTitle'] ?? '' ) ); $place = trim( (string) ( $item['place'] ?? '' ) ); $publisher = trim( (string) ( $item['publisher'] ?? '' ) ); $scenario = trim( (string) ( $item['scenario'] ?? '' ) );
			if ( '' === $scenario || '' === $full_name || '' === $year || '' === $title || '' === $place || '' === $publisher ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing required bibliographic data.', 'citex-tools' ), $id ) ); }

			$author_parts = self::derive_author_parts( $full_name );
			if ( is_wp_error( $author_parts ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %1$s: %2$s', 'citex-tools' ), $id, $author_parts->get_error_message() ) ); }
			$surname = $author_parts['surname'];
			$initials = $author_parts['initials'];

			// Exercise is Citex-assigned only — resolved by slot index from the
			// matrix built before generation began, never read from $item.
			$exercise = isset( $exercises[ $i ] ) ? sanitize_text_field( (string) $exercises[ $i ] ) : 'Exercise 1';

			$candidate = 'MCQ' === $type
				? self::normalise_mcq_item( $item, $id, $full_name, $surname, $initials, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty )
				: self::normalise_dragdrop_item( $item, $id, $full_name, $surname, $initials, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty, $expected_distractors );
			if ( is_wp_error( $candidate ) ) { return $candidate; }

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

	/**
	 * Citex — not Gemini — is the sole author of Question Parts and Fixed
	 * Text. Both are built here directly from the canonical bibliographic
	 * record (authorSurname/authorInitials/year/bookTitle/place/publisher)
	 * instead of trusting Gemini's own questionParts/fixedText output.
	 * Gemini's own fields could previously agree with each other while the
	 * separately-written scenario described a different book entirely —
	 * constructing Question Parts and Fixed Text here makes that
	 * structurally impossible instead of merely self-consistent.
	 *
	 * @return array|WP_Error
	 */
	private static function normalise_dragdrop_item( $item, $id, $full_name, $surname, $initials, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty, $expected_distractors ) {
		$distractors = array_values( array_filter( array_map( 'trim', (array) ( $item['confusingWords'] ?? array() ) ), 'strlen' ) );
		$parts = array( $surname, $initials, $year, $title );
		$fixed = sprintf( '|, || (||) ||. %s: %s.', $place, $publisher );
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
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'Harvard | ReferenceList | Book | DragDrop | %s', $id ), 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Book', 'exercise' => $exercise, 'type' => 'DragDrop', 'institution' => 'Liverpool Hope University', 'difficulty' => ucfirst( $difficulty ), 'scenario' => sanitize_textarea_field( $scenario ), 'authorFullName' => sanitize_text_field( $full_name ), 'authorSurname' => sanitize_text_field( $surname ), 'authorInitials' => sanitize_text_field( $initials ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'place' => sanitize_text_field( $place ), 'publisher' => sanitize_text_field( $publisher ), 'fixedText' => sanitize_text_field( $fixed ), 'questionParts' => array_values( array_map( 'sanitize_text_field', $parts ) ), 'confusingWords' => array_values( array_map( 'sanitize_text_field', $distractors ) ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * Citex — not Gemini — constructs the single correctly-formatted Harvard
	 * reference (the same construction used for DragDrop's
	 * reconstructedReference) and is the sole authority for which of the 4
	 * options is correct. Gemini only ever supplies THREE incorrect options;
	 * it never sees or chooses the correct one, so there is no correct-answer
	 * value for it to leak or place predictably.
	 *
	 * The correct option's position (0-3) is derived deterministically from
	 * the question ID (crc32) rather than true randomness — this still
	 * varies unpredictably per question (never a fixed slot a student could
	 * learn to exploit) while keeping generation and its tests deterministic.
	 *
	 * @return array|WP_Error
	 */
	private static function normalise_mcq_item( $item, $id, $full_name, $surname, $initials, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty ) {
		$incorrect = array_values( array_filter( array_map( 'trim', (array) ( $item['incorrectReferences'] ?? array() ) ), 'strlen' ) );
		if ( 3 !== count( $incorrect ) ) {
			return new WP_Error( 'citex_ai_bad_mcq_options', sprintf( __( 'Question %1$s has %2$d incorrect reference option(s); exactly 3 are required.', 'citex-tools' ), $id, count( $incorrect ) ) );
		}

		$reference = sprintf( '%s, %s (%s) %s. %s: %s.', $surname, $initials, $year, $title, $place, $publisher );
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $reference ) ) );
		$seen = array( $correct_normal => true );
		foreach ( $incorrect as $option ) {
			$normal = strtolower( trim( preg_replace( '/\s+/', ' ', $option ) ) );
			if ( $normal === $correct_normal ) {
				return new WP_Error( 'citex_ai_mcq_option_matches_correct', sprintf( __( 'Question %s has an "incorrect" reference option identical to the correct one.', 'citex-tools' ), $id ) );
			}
			if ( isset( $seen[ $normal ] ) ) {
				return new WP_Error( 'citex_ai_mcq_duplicate_option', sprintf( __( 'Question %s has a duplicate incorrect reference option.', 'citex-tools' ), $id ) );
			}
			$seen[ $normal ] = true;
		}

		$correct_index = crc32( $id ) % 4;
		$options = array();
		$cursor = 0;
		for ( $slot = 0; $slot < 4; $slot++ ) {
			$options[] = ( $slot === $correct_index ) ? $reference : $incorrect[ $cursor++ ];
		}

		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'Harvard | ReferenceList | Book | MCQ | %s', $id ), 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Book', 'exercise' => $exercise, 'type' => 'MCQ', 'institution' => 'Liverpool Hope University', 'difficulty' => ucfirst( $difficulty ), 'scenario' => sanitize_textarea_field( $scenario ), 'authorFullName' => sanitize_text_field( $full_name ), 'authorSurname' => sanitize_text_field( $surname ), 'authorInitials' => sanitize_text_field( $initials ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'place' => sanitize_text_field( $place ), 'publisher' => sanitize_text_field( $publisher ), 'options' => array_values( array_map( 'sanitize_text_field', $options ) ), 'correctOptionIndex' => $correct_index, 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}
	private static function output_text( $data ) {
		if ( ! empty( $data['output_text'] ) && is_string( $data['output_text'] ) ) { return trim( $data['output_text'] ); }
		$text = array(); foreach ( (array) ( $data['steps'] ?? array() ) as $step ) { if ( 'model_output' !== ( $step['type'] ?? '' ) ) { continue; } foreach ( (array) ( $step['content'] ?? array() ) as $content ) { if ( isset( $content['text'] ) && is_string( $content['text'] ) ) { $text[] = $content['text']; } } } return trim( implode( "\n", $text ) );
	}
	private static function strip_fences( $text ) { $text = trim( $text ); $text = preg_replace( '/^```(?:json)?\s*/i', '', $text ); $text = preg_replace( '/\s*```$/', '', $text ); return trim( $text ); }
}
