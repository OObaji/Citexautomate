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
		// switch an existing caller onto a different question shape. Same
		// principle for category: 'edited_book' is the only other supported
		// category — anything else stays the original Book path.
		$type     = 'mcq' === sanitize_key( $args['type'] ?? 'dragdrop' ) ? 'MCQ' : 'DragDrop';
		$category_key = sanitize_key( $args['category'] ?? 'book' );
		if ( 'edited_book' === $category_key ) {
			$category = Citex_Reference_Rules::CATEGORY_EDITED_BOOK;
		} elseif ( 'journal_article' === $category_key ) {
			$category = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE;
		} else {
			$category = Citex_Reference_Rules::CATEGORY_BOOK;
		}
		$ids = self::build_ids( strtoupper( sanitize_text_field( $args['starting_id'] ?? 'BK01' ) ), $quantity, $args['used_ids'] ?? array() );
		if ( is_wp_error( $ids ) ) { return $ids; }
		// Citex assigns each slot's Exercise deterministically before any
		// Gemini request is made (see Citex_Generator::build_exercise_assignments());
		// Gemini's schema has no exercise field, so nothing from its response
		// is ever consulted for this.
		$exercises = array_values( (array) ( $args['exercise_assignments'] ?? array() ) );

		// Scenario (Citex_Question_Diversity::assign_scenarios(), resolved
		// via Citex_Question_Scenarios) is likewise Citex-assigned before any
		// Gemini request is made — this whole call always shares ONE
		// scenario (Citex_Generator issues one generate_questions() call per
		// scenario group), so ONE target author/editor count applies to
		// every slot here. A caller that never passes 'scenario' (any use
		// outside Citex_Generator's own grouped-request loop) leaves
		// $target_count null, and every author/editor count remains
		// unconstrained — the exact pre-framework behaviour.
		$scenario_id    = isset( $args['scenario'] ) ? sanitize_key( $args['scenario'] ) : '';
		$scenario_entry = '' !== $scenario_id ? Citex_Question_Scenarios::find( $category, $type, $scenario_id ) : null;
		$rule_tested    = $scenario_entry['ruleTested'] ?? '';
		$target_count   = $scenario_entry ? Citex_Question_Scenarios::target_count_for( $scenario_entry, $args['starting_id'] ?? $scenario_id ) : null;
		$scenario_instruction = self::scenario_count_instruction( $category, $target_count );

		// Existing reconstructed references (same category) this batch must
		// not duplicate — the one concrete "too similar to recent history"
		// case this framework checks: Gemini regenerating the exact same
		// real book/edited book a still-pending question already used. See
		// Citex_Question_Diversity::is_duplicate_reference().
		$existing_references = array_map( 'strval', (array) ( $args['existing_references'] ?? array() ) );

		$last_error = '';
		for ( $attempt = 1; $attempt <= self::MAX_QUALITY_ATTEMPTS; $attempt++ ) {
			$body = array(
				'model' => self::get_model(),
				'input' => self::build_prompt_for( $type, $category, $ids, $difficulty, $verify, $last_error, $scenario_instruction, $scenario_id ),
				'system_instruction' => self::system_instruction_for( $type, $category, $scenario_id ),
				'response_format' => array( array( 'type' => 'text', 'mime_type' => 'application/json', 'schema' => self::schema_for( $type, $category, $scenario_id ) ) ),
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
			$result = self::normalise( $questions, $ids, $difficulty, $exercises, $type, $category, $target_count, $scenario_id, $rule_tested );
			if ( is_wp_error( $result ) ) { $last_error = $result->get_error_message(); continue; }

			$duplicate = self::find_duplicate_reference( $result, $existing_references );
			if ( null !== $duplicate ) {
				$last_error = sprintf( 'A generated reference duplicates one already in the pending queue (or elsewhere in this batch): "%s". Choose a different real book/edited book.', $duplicate );
				continue;
			}

			if ( '' !== $scenario_id ) {
				Citex_Question_Diversity::record_batch( $category, array_column( $result, 'blueprint' ) );
			}
			return $result;
		}

		return new WP_Error( 'citex_ai_quality_failed', sprintf( __( 'Gemini could not produce a fully valid batch after %d quality checks. Nothing was added. Last issue: %s', 'citex-tools' ), self::MAX_QUALITY_ATTEMPTS, $last_error ) );
	}

	/**
	 * First reconstructedReference in $candidates that duplicates either an
	 * already-pending reference (same category, passed in as
	 * $existing_references) or an earlier candidate within this same batch
	 * — null when there is no duplicate at all.
	 *
	 * @param array    $candidates
	 * @param string[] $existing_references
	 * @return string|null
	 */
	private static function find_duplicate_reference( $candidates, array $existing_references ) {
		$seen_in_batch = array();
		foreach ( $candidates as $candidate ) {
			$reference = (string) ( $candidate['reconstructedReference'] ?? '' );
			if ( Citex_Question_Diversity::is_duplicate_reference( $reference, $existing_references ) || Citex_Question_Diversity::is_duplicate_reference( $reference, $seen_in_batch ) ) {
				return $reference;
			}
			$seen_in_batch[] = $reference;
		}
		return null;
	}

	/**
	 * The one place that picks which of the 4 (type x category) prompt/schema/
	 * system-instruction sets to use. Adding a third category means adding
	 * one more case here (and the category's own build_prompt and schema
	 * methods) — the request/response handling in generate_questions() above
	 * never changes.
	 */
	private static function build_prompt_for( $type, $category, $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction = '', $scenario_id = '' ) {
		if ( 'MCQ' === $type && 'identify_error' === $scenario_id ) {
			return self::build_prompt_identify_error( $category, $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction );
		}
		if ( 'MCQ' === $type && 0 === strpos( (string) $scenario_id, 'choose_treatment_' ) ) {
			// No scenario_instruction here: this mechanic asks for no
			// author/editor list at all (see build_prompt_choose_treatment()'s
			// docblock), so an "exactly N authors" instruction would be
			// meaningless noise in the prompt.
			return self::build_prompt_choose_treatment( $category, substr( (string) $scenario_id, strlen( 'choose_treatment_' ) ), $ids, $difficulty, $verify, $quality_feedback );
		}
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
			return 'MCQ' === $type
				? self::build_prompt_edited_book_mcq( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction )
				: self::build_prompt_edited_book( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction );
		}
		if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'MCQ' === $type
				? self::build_prompt_journal_article_mcq( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction )
				: self::build_prompt_journal_article( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction );
		}
		return 'MCQ' === $type
			? self::build_prompt_mcq( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction )
			: self::build_prompt( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction );
	}

	/**
	 * The author/editor-count instruction Citex_Question_Diversity's
	 * assigned scenario translates into for Gemini — e.g. "every question
	 * in this batch must use exactly 3 real authors" — appended to every
	 * prompt builder's output. Empty when no scenario was assigned (any
	 * caller outside Citex_Generator's own scenario-group loop), in which
	 * case Gemini remains free to pick any real author/editor count, exactly
	 * as before this framework existed.
	 */
	private static function scenario_count_instruction( $category, $target_count ) {
		if ( null === $target_count ) {
			return '';
		}
		$noun = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ? 'editor' : 'author';
		return sprintf(
			"AUTHOR/EDITOR COUNT FOR THIS BATCH — CRITICAL:\n- Every single question in this batch must use EXACTLY %1\$d real %2\$s(s) — not more, not fewer. Choose a real book that genuinely has %1\$d %2\$s(s); do not pad or trim the real %2\$s list to hit this number.",
			$target_count,
			$noun
		);
	}

	private static function schema_for( $type, $category, $scenario_id = '' ) {
		if ( 'MCQ' === $type && 'identify_error' === $scenario_id ) {
			return self::schema_identify_error( $category );
		}
		if ( 'MCQ' === $type && 0 === strpos( (string) $scenario_id, 'choose_treatment_' ) ) {
			return self::schema_choose_treatment();
		}
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
			return 'MCQ' === $type ? self::schema_edited_book_mcq() : self::schema_edited_book();
		}
		if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'MCQ' === $type ? self::schema_journal_article_mcq() : self::schema_journal_article();
		}
		return 'MCQ' === $type ? self::schema_mcq() : self::schema();
	}

	private static function system_instruction_for( $type, $category, $scenario_id = '' ) {
		if ( 'MCQ' === $type && 'identify_error' === $scenario_id ) {
			return self::system_instruction_identify_error( $category );
		}
		if ( 'MCQ' === $type && 0 === strpos( (string) $scenario_id, 'choose_treatment_' ) ) {
			return self::system_instruction_choose_treatment( $category );
		}
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
			return 'MCQ' === $type
				? 'You are Citex, an academic question-generation engine. Generate real, usable Liverpool Hope University Harvard ReferenceList Edited Book multiple-choice questions, not tests or fictional examples. Verify bibliographic facts when web verification is enabled. Never invent books, editors, years, publishers, or places. Every question must describe exactly ONE canonical bibliographic record with ONE OR MORE editors: editorFullNames (an array of one or two full names), year, bookTitle, place and publisher must all refer to the same real edited book — never a different edition or a different book. You are NOT asked for a scenario or question text at all; Citex supplies the entire student-facing question itself (a fixed "Which of the following is the correct Harvard reference for an edited book?" stem), so there is nothing for you to write and nothing for you to leak the answer through. Citex constructs the single correctly-formatted Harvard reference itself, including the correct editor designation ("(ed.)" for exactly one editor, "(eds)" for two) — you only ever provide THREE plausible but incorrectly-formatted `distractors`, each as {reference, errorReason} naming the SPECIFIC Harvard rule it breaks, never the correct one itself, and never one that swaps "(ed.)"/"(eds)" for the wrong editor count in a way that would make two options simultaneously look correct. Your goal is never "make four references that look different" — it is "one correct reference, three references each with one deliberate, identifiable Harvard error." For every distractor, re-read it end-to-end against the full correct format before returning it: a distractor that is wrong in your head but technically satisfies every Harvard rule when read literally must be rebuilt, since Citex independently re-validates every option and rejects the whole question if more than one is fully valid. Before returning each question, perform a strict self-check: editorFullNames, year, bookTitle, place and publisher all describe the same book with no contradictions; and all three distractors are clearly wrong (a formatting, punctuation, ordering, or wrong-designation mistake) with a specific errorReason each, mutually distinct from each other, and distinct from the correct reference you did not provide. Return only the requested JSON.'
				: 'You are Citex, an academic question-generation engine. Generate real, usable Liverpool Hope University Harvard ReferenceList Edited Book DragDrop questions, not tests or fictional examples. Verify bibliographic facts when web verification is enabled. Never invent books, editors, years, publishers, or places. Every question must describe exactly ONE canonical bibliographic record with ONE OR MORE editors: editorFullNames (an array of one or two full names), year, bookTitle, place and publisher must all refer to the same real edited book, and the scenario text must explicitly name that same title, every editor\'s full name, the same year, place and publisher — never a different edition or a different book. Citex derives each editor\'s surname and initials itself from editorFullNames, decides the correct editor designation ("(ed.)" for one editor, "(eds)" for two), and constructs Question Parts and Fixed Text itself — you never provide any of that, and your own questionParts/fixedText fields (if you include them) are never read as authoritative. CRITICAL — the scenario must state every editor\'s full name naturally and must NEVER show "(ed.)" or "(eds)" anywhere, must NEVER state, label, or abbreviate any editor\'s initials or surname separately, must NEVER show a completed or abbreviated Harvard citation, and must NEVER use the words "initial", "initials", or "surname". Before returning each question, perform a strict self-check: scenario, editorFullNames, year, bookTitle, place and publisher all describe the same book with no contradictions; the scenario reveals no answer value; and every confusing word is unique and different from every correct value. Return only the requested JSON.';
		}
		if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return self::system_instruction_journal_article( $type );
		}
		return self::system_instruction_for_book( $type );
	}

	private static function system_instruction_for_book( $type ) {
		return 'MCQ' === $type
			? 'You are Citex, an academic question-generation engine. Generate real, usable Liverpool Hope University Harvard ReferenceList Book multiple-choice questions, not tests or fictional examples. Verify bibliographic facts when web verification is enabled. Never invent books, authors, years, publishers, or places. Every question must describe exactly ONE canonical bibliographic record: authorFullNames (an array of ONE OR MORE real author full names, in the real book\'s actual author order), year, bookTitle, place and publisher must all refer to the same real book — never a different edition or a different book, and never a different number of authors than the real book actually has. You are NOT asked for a scenario or question text at all; Citex supplies the entire student-facing question itself (a fixed "Which of the following is the correct Harvard reference for a book?" stem), so there is nothing for you to write and nothing for you to leak the answer through. Citex constructs the single correctly-formatted Harvard reference itself from authorFullNames/year/bookTitle/place/publisher — including how multiple authors are joined (Liverpool Hope\'s reference-list rule: EVERY author is always listed in full, comma-separated with a final "and" before the last one, for any author count — "et al." is NEVER used in a reference-list entry; that abbreviation is only Liverpool Hope\'s separate in-text-citation convention, which this question never generates) — you only ever provide THREE plausible but incorrectly-formatted `distractors`, each as {reference, errorReason} naming the SPECIFIC Harvard rule it breaks, never the correct one itself. Your goal is never "make four references that look different" — it is "one correct reference, three references each with one deliberate, identifiable Harvard error." For every distractor, re-read it end-to-end against the full correct format before returning it: a distractor that is wrong in your head but technically satisfies every Harvard rule when read literally must be rebuilt, since Citex independently re-validates every option and rejects the whole question if more than one is fully valid. Before returning each question, perform a strict self-check: authorFullNames, year, bookTitle, place and publisher all describe the same book with no contradictions; and all three distractors are clearly wrong (a formatting, punctuation, ordering, or — when there is more than one author — author-joining mistake) with a specific errorReason each, mutually distinct from each other, and distinct from the correct reference you did not provide. Return only the requested JSON.'
			: 'You are Citex, an academic question-generation engine. Generate real, usable Liverpool Hope University Harvard ReferenceList Book DragDrop questions, not tests or fictional examples. Verify bibliographic facts when web verification is enabled. Never invent books, authors, years, publishers, or places. Every question must describe exactly ONE canonical bibliographic record: authorFullNames (an array of ONE OR MORE real author full names, in the real book\'s actual author order), year, bookTitle, place and publisher must all refer to the same real book, and the scenario text must explicitly name that same title, EVERY author\'s full name, the same year, place and publisher — never a different edition, a different book, or a different number of authors than the real book actually has. Citex derives each author\'s surname and initials itself from authorFullNames — you never provide them separately — and constructs Question Parts and Fixed Text itself (including how multiple authors are joined: Liverpool Hope always lists every author in full, joined with "and"/commas, never "et al." in the reference list), so your questionParts and fixedText values are for your own self-check only and are not read as authoritative. CRITICAL — the scenario must state every author\'s full real name naturally (for example "Alan Bryman" or "Alan Bryman and Jo Martin") and must NEVER state, label, or abbreviate any author\'s initials or surname separately, must NEVER show a completed or abbreviated Harvard reference (never write anything like "Bryman, A." or "Bryman et al."), and must NEVER use the words "initial" or "surname" — the student must derive the initials and the Harvard format themselves from the full name(s) you provide. Before returning each question, perform a strict self-check: scenario, authorFullNames, year, bookTitle, place and publisher must all describe the same book with no contradictions; the scenario must not reveal any answer value by labelling it as a surname, initial, year blank, title blank, or reference component; and every confusing word must be unique and different from every correct Question Part. Return only the requested JSON.';
	}

	/**
	 * Journal Article counterpart to system_instruction_for_book(): the same
	 * "one canonical real record, Citex derives surname/initials and
	 * constructs the reference itself" framing, but with articleTitle/
	 * journalTitle/volume/issue/pages replacing bookTitle/place/publisher —
	 * there is no place/publisher concept for a journal article.
	 */
	private static function system_instruction_journal_article( $type ) {
		return 'MCQ' === $type
			? 'You are Citex, an academic question-generation engine. Generate real, usable Liverpool Hope University Harvard ReferenceList Journal Article multiple-choice questions, not tests or fictional examples. Verify bibliographic facts when web verification is enabled. Never invent journals, articles, authors, years, volumes, issues, or page ranges. Every question must describe exactly ONE canonical, real, published journal article: authorFullNames (an array of ONE OR MORE real author full names, in the article\'s actual author order), year, articleTitle, journalTitle, volume, issue and pages must all refer to the same real article — never a different issue or a different article, and never a different number of authors than the real article actually has. You are NOT asked for a scenario or question text at all; Citex supplies the entire student-facing question itself (a fixed "Which of the following is the correct Harvard reference for a journal article?" stem), so there is nothing for you to write and nothing for you to leak the answer through. Citex constructs the single correctly-formatted Harvard reference itself from authorFullNames/year/articleTitle/journalTitle/volume/issue/pages — including how multiple authors are joined (Liverpool Hope\'s reference-list rule: EVERY author is always listed in full, comma-separated with a final "and" before the last one, for any author count — "et al." is NEVER used in a reference-list entry) — you only ever provide THREE plausible but incorrectly-formatted `distractors`, each as {reference, errorReason} naming the SPECIFIC Harvard rule it breaks, never the correct one itself. Your goal is never "make four references that look different" — it is "one correct reference, three references each with one deliberate, identifiable Harvard error." For every distractor, re-read it end-to-end against the full correct format before returning it: a distractor that is wrong in your head but technically satisfies every Harvard rule when read literally must be rebuilt, since Citex independently re-validates every option and rejects the whole question if more than one is fully valid. Before returning each question, perform a strict self-check: authorFullNames, year, articleTitle, journalTitle, volume, issue and pages all describe the same article with no contradictions; and all three distractors are clearly wrong (a formatting, punctuation, ordering, or author-joining mistake) with a specific errorReason each, mutually distinct from each other, and distinct from the correct reference you did not provide. Return only the requested JSON.'
			: 'You are Citex, an academic question-generation engine. Generate real, usable Liverpool Hope University Harvard ReferenceList Journal Article DragDrop questions, not tests or fictional examples. Verify bibliographic facts when web verification is enabled. Never invent journals, articles, authors, years, volumes, issues, or page ranges. Every question must describe exactly ONE canonical, real, published journal article: authorFullNames (an array of ONE OR MORE real author full names, in the article\'s actual author order), year, articleTitle, journalTitle, volume, issue and pages must all refer to the same real article, and the scenario text must explicitly name that same article title, journal title, EVERY author\'s full name, the same year, volume, issue and page range — never a different issue, a different article, or a different number of authors than the real article actually has. Citex derives each author\'s surname and initials itself from authorFullNames — you never provide them separately — and constructs Question Parts and Fixed Text itself. UNLIKE Book, Journal Article ALWAYS uses exactly 7 draggable parts for ANY author count (joined author list as ONE part even for a single author, year, article title, journal title, volume, issue, pages) — never split a single author into separate surname/initials parts. Your questionParts and fixedText values are for your own self-check only and are not read as authoritative. CRITICAL — the scenario must state every author\'s full real name naturally and must NEVER state, label, or abbreviate any author\'s initials or surname separately, must NEVER show a completed or abbreviated Harvard reference, must NEVER say "et al." or state the answer\'s punctuation or ordering, and must NEVER use the words "initial" or "surname" — the student must derive the initials and the Harvard format themselves from the full name(s) you provide. Before returning each question, perform a strict self-check: scenario, authorFullNames, year, articleTitle, journalTitle, volume, issue and pages must all describe the same article with no contradictions; the scenario must not reveal any answer value; and every confusing word must be unique and different from every correct Question Part. Return only the requested JSON.';
	}

	/**
	 * Advisory guidance — never a hard requirement — steering Gemini's real-
	 * book/publisher/author SELECTION toward naturally concise real names
	 * when multiple equally valid real options exist, so generated
	 * Question Parts, MCQ options, and scenario text are more compact on a
	 * mobile screen at the source. This never shortens, abbreviates, or
	 * truncates whatever real name ends up being used — Citex still writes
	 * whatever authorFullNames/editorFullNames/bookTitle/publisher Gemini
	 * actually returns, in full, exactly as everywhere else in this file;
	 * it only asks Gemini to break ties between equally good real choices
	 * in favour of the shorter one. Appended to every prompt builder that
	 * carries bibliographic data (every one except
	 * build_prompt_choose_treatment(), which has none at all — see its own
	 * docblock).
	 */
	private static function conciseness_guidance() {
		return "MOBILE READABILITY — PREFER CONCISE REAL NAMES WHEN POSSIBLE:\n"
			. "- When several equally valid, equally relevant real books/editors/authors/publishers could be used to answer this prompt, prefer the ones with naturally shorter, more concise real names — e.g. a publisher commonly known by a short name (\"Routledge\", \"SAGE\", \"Wiley\", \"Polity\") over an unusually long full imprint name, and authors/editors whose real full names are reasonably short — this keeps the generated question compact on a mobile screen.\n"
			. "- This is a PREFERENCE only, never a requirement to alter anything: NEVER abbreviate, shorten, truncate, or otherwise alter any real author name, editor name, book title, or publisher name. Always use the complete, accurate real name exactly as it is properly known.\n"
			. "- If the most relevant, appropriate real book for this topic and difficulty happens to have a longer author, editor, title, or publisher name, use it exactly as-is — accuracy and a genuine, real bibliographic record always come first. Conciseness is only a tie-breaker between otherwise equally good real choices, never a reason to pick a less accurate or less relevant book.";
	}

	private static function build_prompt( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct Liverpool Hope University Harvard / ReferenceList / Book / DragDrop questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify every bibliographic record.' : 'Do not invent bibliographic records.' ) . "\n\nONE QUESTION = ONE CANONICAL BIBLIOGRAPHIC RECORD — CRITICAL:\n- authorFullNames, year, bookTitle, place and publisher must all describe the exact same real book. Do not mix facts from a different edition, a different book by the same author(s), or a similarly-named book.\n- authorFullNames is an array of ONE OR MORE real author full names (given name(s) + surname each), e.g. [\"Alan Bryman\"] or [\"John Smith\", \"Amy Jones\"], in the book's real, actual author order. Use the book's true author count — do not invent extra authors, and do not drop real ones. Do NOT provide a surname or initials separately for any author — Citex derives both itself from each full name.\n- The scenario MUST explicitly state that same bookTitle, EVERY author's full name, the same year, the same place and the same publisher. Citex independently checks the scenario text against these fields and rejects the question if any of them is not named in the scenario.\n\nMULTIPLE AUTHORS — LIVERPOOL HOPE'S REFERENCE-LIST RULE:\n- For the reference list (which is the only thing this question generates), EVERY author is always listed in full — 2 authors are joined with \"and\"; 3 or more are comma-separated with \"and\" before the final author; this never changes at 4 or more authors.\n- \"et al.\" must NEVER appear in the reference-list entry, for any author count. (\"et al.\" is Liverpool Hope's separate IN-TEXT-CITATION convention for 4+ authors — this question never generates an in-text citation, only a reference-list entry, so that abbreviation does not belong here at all.)\n- Citex constructs the joined author list itself from authorFullNames — you never write the joined form yourself.\n\nSCENARIOS — ANSWER LEAKAGE IS A CRITICAL FAILURE:\n- Keep each scenario short and mobile-friendly, preferably under 220 characters.\n- Use natural wording such as 'You are creating a reference for a book titled...' or 'You are referencing a book titled...'.\n- State the real book title, EVERY author's FULL NAME, publication year, publisher and publication place.\n- Prefer concise real book titles; never truncate or alter the actual bibliographic title.\n- The scenario MUST NOT state, label, or abbreviate any author's initials or surname separately, MUST NOT use the words \"initial\" or \"initials\" or \"surname\" anywhere, and MUST NOT show any completed or abbreviated Harvard reference (e.g. never write \"Bryman, A.\", \"Bryman, A. (2012)\", or \"Bryman et al.\").\n- GOOD (one author): \"You are referencing the book titled Social Research Methods by Alan Bryman, published in 2012 by Oxford University Press in Oxford.\"\n- GOOD (two authors): \"You are referencing a book titled Understanding digital culture by Vincent Miller and Jo Martin, published in 2020 by SAGE Publications in London.\"\n- BAD: \"...by Alan Bryman (initials A.), published in 2012...\" — reveals the initials directly.\n- BAD: \"...by Bryman, A., published in 2012...\" — states the abbreviated citation form directly.\n- BAD: \"...by Smith et al., published in 2020...\" — states the in-text-citation abbreviation directly, and is also not how the reference-list entry is written.\n- BAD: \"The author's surname is Bryman and his initials are A.\" — explicitly labels both answers.\n- A full author name naturally containing the surname (e.g. \"Alan Bryman\") is correct and required — the failure is explicitly labelling or abbreviating an answer value, not the surname appearing as part of the full name.\n- The student must transform the full bibliographic information you give into the Harvard reference themselves; do not do that transformation for them anywhere in the scenario.\n\nDRAGDROP:\n- Citex derives each author's surname/initials from authorFullNames and constructs Question Parts and Fixed Text itself from surname(s)/initials/year/bookTitle/place/publisher — your questionParts and fixedText fields are used only for your own self-check and are not read as authoritative, so make sure they exactly match those fields too (surname = each full name's last word; initials = the first letter of every other word in that name, each followed by a full stop, no spaces, e.g. \"John Michael Smith\" -> \"J.M.\").\n- For ONE author: questionParts must contain exactly 4 items: surname, initials, year, book title.\n- For TWO OR MORE authors: questionParts must contain exactly 3 items: the joined author list (e.g. \"Smith, J. and Jones, A.\"), year, book title — the whole author list is a SINGLE draggable part, not one part per author.\n- fixedText must contain a draggable placeholder TOKEN for every item in questionParts (4 for one author, 3 for two or more).\n- A single | token is allowed only at the beginning or end. Every internal placeholder token MUST be ||.\n- Canonical fixedText for one author: |, || (||) ||. Place: Publisher.\n- Canonical fixedText for two or more authors: | (||) ||. Place: Publisher.\n- Do not use a single internal |.\n- Reconstructed answer (one author): Surname, I. (YYYY) Book Title. Place: Publisher.\n- Reconstructed answer (two or more authors): Surname, I. and Surname, I. (YYYY) Book Title. Place: Publisher. (extending with commas and a final \"and\" for 3+, never \"et al.\").\n- No full stop after the year parentheses; no spaces before punctuation; one space after the colon; final full stop required.\n\nDISTRACTORS — CRITICAL:\n- Medium exactly 3; Easy exactly 2; Hard exactly 4.\n- Every distractor must be different from ALL correct Question Parts after trimming and case-insensitive comparison.\n- Distractors must also be unique from one another.\n- Do not use the correct title, surname(s), initials, year or any exact copy of a correct part as a distractor.\n- Before returning each question, compare every confusingWords value against every Question Part and replace any match.\n- Prefer plausible alternatives such as another year, city, publisher, author surname, or book title; for a multi-author question, an author-joining mistake (\"&\" instead of \"and\", or \"et al.\") is also a good confusing word.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. scenario, authorFullNames, year, bookTitle, place and publisher all describe the exact same book — no contradictions, and the real author count.\n2. The scenario states every author's full name naturally and never the words \"initial\"/\"initials\"/\"surname\", and never a completed, abbreviated, or \"et al.\" reference.\n3. Question Parts exactly match the required shape for this author count (4 items for one author; 3 items — joined author list, year, title — for two or more), correctly derived from authorFullNames.\n4. Fixed Text has exactly as many placeholder positions as Question Parts and reconstructs the required reference, with every author listed in full and \"et al.\" never used.\n5. No unwanted punctuation or spacing errors.\n6. Correct number of distractors for the difficulty.\n7. Zero distractors match any correct Question Part.\n8. Zero duplicate distractors.\n9. Only return questions that pass all nine checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	private static function build_prompt_mcq( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$difficulty_guidance = array(
			'easy'   => "Easy: the 3 distractors should each contain one obvious, easy-to-spot mistake (e.g. missing punctuation, no parentheses around the year) — testing basic recognition of the Harvard Book structure.",
			'medium' => "Medium: the 3 distractors should each contain one specific, realistic mistake a student could plausibly make (e.g. author's full first name instead of initials, or place/publisher in the wrong order) — testing the ability to spot ONE particular error type per option.",
			'hard'   => "Hard: the 3 distractors should be very close to correctly formatted, differing from the correct one by only a small, easy-to-miss detail (e.g. a single misplaced space, comma, or full stop) — testing careful side-by-side comparison of near-identical references.",
		);
		$prompt = "Generate exactly " . count( $ids ) . " distinct Liverpool Hope University Harvard / ReferenceList / Book multiple-choice questions.\nDifficulty: " . ucfirst( $difficulty ) . ". " . ( $difficulty_guidance[ sanitize_key( $difficulty ) ] ?? $difficulty_guidance['medium'] ) . "\n" . ( $verify ? 'Use Google Search to verify every bibliographic record.' : 'Do not invent bibliographic records.' ) . "\n\nONE QUESTION = ONE CANONICAL BIBLIOGRAPHIC RECORD — CRITICAL:\n- authorFullNames, year, bookTitle, place and publisher must all describe the exact same real book. Do not mix facts from a different edition, a different book by the same author(s), or a similarly-named book.\n- authorFullNames is an array of ONE OR MORE real author full names (given name(s) + surname each), e.g. [\"Alan Bryman\"] or [\"John Smith\", \"Amy Jones\"], in the book's real, actual author order. Use the book's true author count. Do NOT provide a surname or initials separately for any author — Citex derives both itself from each full name and constructs the one correct Harvard reference from them (joining multiple authors per Liverpool Hope's reference-list rule: every author listed in full, comma-separated with a final \"and\", for any count — never \"et al.\", which is only Liverpool Hope's separate in-text-citation convention); you never provide the correct reference yourself.\n- You are NOT asked for a scenario or question text — Citex supplies the entire student-facing question itself (a fixed \"Which of the following is the correct Harvard reference for a book?\" stem), so there is nothing for you to write and nothing for you to leak the answer through."
			. self::distractor_prompt_section( Citex_Reference_Rules::CATEGORY_BOOK, 'Surname, I. (YYYY) Book Title. Place: Publisher. — or, for two or more authors, Surname, I. and Surname, I. (YYYY) Book Title. Place: Publisher., extending with commas and a final "and" for 3+, never "et al."' )
			. "\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. authorFullNames, year, bookTitle, place and publisher all describe the exact same book — no contradictions, and the real author count.\n2. Exactly 3 distractors are provided, each with a non-empty, specific errorReason naming the Harvard rule it breaks.\n3. Every distractor, re-read end-to-end against the full correct format, genuinely still breaks the rule named in its errorReason — none of them accidentally also satisfies every Harvard rule.\n4. All 3 distractors are mutually distinct from each other and from the correct reference, and exactly one reference overall (the one Citex will construct) is fully correct.\n5. None of the distractors uses \"et al.\" as if it were valid in the reference list — that abbreviation is never correct here.\n6. Only return questions that pass all six checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * Journal Article DragDrop prompt — modelled directly on build_prompt()
	 * (Book DragDrop) but for the Liverpool Hope journal-article format
	 * (Author surname(s), initial(s). (Year) Article title. Journal title,
	 * Volume(Issue), pp.xx-xx.): no place/publisher concept at all, and the
	 * DragDrop shape is a CONSTANT 7 parts for ANY author count (see
	 * Citex_Reference_Rules::journal_article_dragdrop_shape()) rather than
	 * Book's shape-varies-by-count design.
	 */
	private static function build_prompt_journal_article( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct Liverpool Hope University Harvard / ReferenceList / Journal Article / DragDrop questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify every bibliographic record.' : 'Do not invent bibliographic records.' ) . "\n\nONE QUESTION = ONE CANONICAL JOURNAL ARTICLE RECORD — CRITICAL:\n- authorFullNames, year, articleTitle, journalTitle, volume, issue and pages must all describe the exact same real, published journal article. Do not mix facts from a different article, a different issue, or a similarly-titled article.\n- authorFullNames is an array of ONE OR MORE real author full names (given name(s) + surname each), e.g. [\"Jane Smith\"] or [\"John Smith\", \"Amy Jones\"], in the article's real, actual author order. Use the article's true author count — do not invent extra authors, and do not drop real ones. Do NOT provide a surname or initials separately for any author — Citex derives both itself from each full name.\n- volume and issue must be the real numeric volume and issue number the article was published in; pages must be the real page range (e.g. \"27-35\") with no \"p.\"/\"pp.\" prefix — Citex adds that itself.\n- There is no place or publisher for a journal article — do not provide either.\n- The scenario MUST explicitly state that same articleTitle, journalTitle, EVERY author's full name, the same year, volume, issue and page range. Citex independently checks the scenario text against these fields and rejects the question if any of them is not named in the scenario.\n\nMULTIPLE AUTHORS — LIVERPOOL HOPE'S REFERENCE-LIST RULE:\n- For the reference list (which is the only thing this question generates), EVERY author is always listed in full — 2 authors are joined with \"and\"; 3 or more are comma-separated with \"and\" before the final author; this never changes at 4 or more authors.\n- \"et al.\" must NEVER appear in the reference-list entry, for any author count. (\"et al.\" is Liverpool Hope's separate IN-TEXT-CITATION convention — this question never generates an in-text citation, only a reference-list entry.)\n- Citex constructs the joined author list itself from authorFullNames — you never write the joined form yourself.\n\nSCENARIOS — ANSWER LEAKAGE IS A CRITICAL FAILURE:\n- Keep each scenario short and mobile-friendly, roughly 15-30 words, preferably under 220 characters.\n- Use natural wording such as 'You are referencing a journal article titled...' or 'You are creating a reference for an article titled...'.\n- State the real article title, journal title, EVERY author's FULL NAME, publication year, volume, issue and page range.\n- The scenario MUST NOT state, label, or abbreviate any author's initials or surname separately, MUST NOT use the words \"initial\" or \"initials\" or \"surname\" anywhere, MUST NOT show any completed or abbreviated Harvard reference, MUST NOT say \"use et al.\", and MUST NOT state the answer's punctuation or ordering.\n- GOOD (one author): \"You are referencing a journal article titled A brief guide to Harvard referencing by Jane Smith, published in 2010 in The British Journal of Referencing, volume 12, issue 2, pages 27 to 35.\"\n- GOOD (two authors): \"You are creating a reference for an article titled Digital culture and learning by Vincent Miller and Jo Martin, published in 2020 in the Journal of Media Studies, volume 8, issue 3, pages 145 to 160.\"\n- BAD: \"...by Jane Smith (initials J.), published in 2010...\" — reveals the initials directly.\n- BAD: \"...by Smith, J., published in 2010...\" — states the abbreviated citation form directly.\n- BAD: \"...by Smith et al., published in 2020...\" — states the in-text-citation abbreviation directly, and is also not how the reference-list entry is written.\n- A full author name naturally containing the surname (e.g. \"Jane Smith\") is correct and required — the failure is explicitly labelling or abbreviating an answer value, not the surname appearing as part of the full name.\n- The student must transform the full bibliographic information you give into the Harvard reference themselves; do not do that transformation for them anywhere in the scenario.\n\nDRAGDROP:\n- Citex derives each author's surname/initials from authorFullNames and constructs Question Parts and Fixed Text itself from surname(s)/initials/year/articleTitle/journalTitle/volume/issue/pages — your questionParts and fixedText fields are used only for your own self-check and are not read as authoritative, so make sure they exactly match those fields too (surname = each full name's last word; initials = the first letter of every other word in that name, each followed by a full stop, no spaces).\n- questionParts must ALWAYS contain exactly 7 items, for ANY author count: the joined author list (e.g. \"Smith, J. and Jones, A.\"), year, article title, journal title, volume, issue, page range — the whole author list is a SINGLE draggable part, never one part per author, even for a single author.\n- fixedText must contain exactly 7 draggable placeholder tokens.\n- A single | token is allowed only at the beginning or end. Every internal placeholder token MUST be ||.\n- Canonical fixedText (every author count): | (||) ||. ||, ||(||), pp.||.\n- Do not use a single internal |.\n- Reconstructed answer: Surname, I. (YYYY) Article title. Journal title, Volume(Issue), pp.Start-End. (extending the author list with commas and a final \"and\" for 2+, never \"et al.\").\n- No full stop after the year parentheses; no spaces before punctuation; final full stop required.\n\nDISTRACTORS — CRITICAL:\n- Medium exactly 3; Easy exactly 2; Hard exactly 4.\n- Every distractor must be different from ALL correct Question Parts after trimming and case-insensitive comparison.\n- Distractors must also be unique from one another.\n- Do not use the correct article title, journal title, surname(s), initials, year, volume, issue or page range as a distractor.\n- Before returning each question, compare every confusingWords value against every Question Part and replace any match.\n- Prefer plausible alternatives such as another year, volume, issue, page range, author surname, or article/journal title; for a multi-author question, an author-joining mistake (\"&\" instead of \"and\", or \"et al.\") is also a good confusing word.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. scenario, authorFullNames, year, articleTitle, journalTitle, volume, issue and pages all describe the exact same article — no contradictions, and the real author count.\n2. The scenario states every author's full name naturally and never the words \"initial\"/\"initials\"/\"surname\", and never a completed, abbreviated, or \"et al.\" reference.\n3. Question Parts always contain exactly 7 items (joined author list, year, article title, journal title, volume, issue, pages), correctly derived from authorFullNames.\n4. Fixed Text has exactly 7 placeholder positions and reconstructs the required reference, with every author listed in full and \"et al.\" never used.\n5. No unwanted punctuation or spacing errors.\n6. Correct number of distractors for the difficulty.\n7. Zero distractors match any correct Question Part.\n8. Zero duplicate distractors.\n9. Only return questions that pass all nine checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * Journal Article MCQ prompt — modelled directly on build_prompt_mcq()
	 * (Book MCQ), reusing distractor_prompt_section() with this category's
	 * own mcq_distractor_patterns() catalogue and correct-format description.
	 */
	private static function build_prompt_journal_article_mcq( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$difficulty_guidance = array(
			'easy'   => "Easy: the 3 distractors should each contain one obvious, easy-to-spot mistake (e.g. missing punctuation, no parentheses around the year) — testing basic recognition of the Harvard Journal Article structure.",
			'medium' => "Medium: the 3 distractors should each contain one specific, realistic mistake a student could plausibly make (e.g. author's full first name instead of initials, or missing the \"pp.\" prefix before the page range) — testing the ability to spot ONE particular error type per option.",
			'hard'   => "Hard: the 3 distractors should be very close to correctly formatted, differing from the correct one by only a small, easy-to-miss detail (e.g. a single misplaced space, comma, or full stop) — testing careful side-by-side comparison of near-identical references.",
		);
		$prompt = "Generate exactly " . count( $ids ) . " distinct Liverpool Hope University Harvard / ReferenceList / Journal Article multiple-choice questions.\nDifficulty: " . ucfirst( $difficulty ) . ". " . ( $difficulty_guidance[ sanitize_key( $difficulty ) ] ?? $difficulty_guidance['medium'] ) . "\n" . ( $verify ? 'Use Google Search to verify every bibliographic record.' : 'Do not invent bibliographic records.' ) . "\n\nONE QUESTION = ONE CANONICAL JOURNAL ARTICLE RECORD — CRITICAL:\n- authorFullNames, year, articleTitle, journalTitle, volume, issue and pages must all describe the exact same real, published journal article. Do not mix facts from a different article or a different issue.\n- authorFullNames is an array of ONE OR MORE real author full names (given name(s) + surname each), e.g. [\"Jane Smith\"] or [\"John Smith\", \"Amy Jones\"], in the article's real, actual author order. Use the article's true author count. Do NOT provide a surname or initials separately for any author — Citex derives both itself from each full name and constructs the one correct Harvard reference from them (joining multiple authors per Liverpool Hope's reference-list rule: every author listed in full, comma-separated with a final \"and\", for any count — never \"et al.\"); you never provide the correct reference yourself.\n- There is no place or publisher for a journal article — do not provide either.\n- You are NOT asked for a scenario or question text — Citex supplies the entire student-facing question itself (a fixed \"Which of the following is the correct Harvard reference for a journal article?\" stem), so there is nothing for you to write and nothing for you to leak the answer through."
			. self::distractor_prompt_section( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, 'Surname, I. (YYYY) Article title. Journal title, Volume(Issue), pp.Start-End. — or, for two or more authors, Surname, I. and Surname, I. (YYYY) Article title. Journal title, Volume(Issue), pp.Start-End., extending with commas and a final "and" for 3+, never "et al."' )
			. "\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. authorFullNames, year, articleTitle, journalTitle, volume, issue and pages all describe the exact same article — no contradictions, and the real author count.\n2. Exactly 3 distractors are provided, each with a non-empty, specific errorReason naming the Harvard rule it breaks.\n3. Every distractor, re-read end-to-end against the full correct format, genuinely still breaks the rule named in its errorReason — none of them accidentally also satisfies every Harvard rule.\n4. All 3 distractors are mutually distinct from each other and from the correct reference, and exactly one reference overall (the one Citex will construct) is fully correct.\n5. None of the distractors uses \"et al.\" as if it were valid in the reference list — that abbreviation is never correct here.\n6. Only return questions that pass all six checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * Shared framing for both Edited Book prompt builders: the one-or-two-
	 * editor rule, the "(ed.)"/"(eds)" designation rule, and the answer-
	 * leakage rules — identical whether the question ends up as DragDrop or
	 * MCQ, so written once and reused rather than duplicated.
	 */
	private static function edited_book_prompt_intro( $verify ) {
		return ( $verify ? 'Use Google Search to verify every bibliographic record.' : 'Do not invent bibliographic records.' )
			. "\n\nONE QUESTION = ONE CANONICAL EDITED BOOK RECORD — CRITICAL:\n- editorFullNames is an array of ONE or TWO editor full names (given name(s) + surname each), e.g. [\"Vincent Miller\"] or [\"John Smith\", \"Amy Jones\"]. Do NOT provide a surname or initials separately for any editor — Citex derives both itself from each full name.\n- year, bookTitle, place and publisher must all describe the exact same real edited book as editorFullNames. Do not mix facts from a different edition or a different book.\n- The scenario MUST explicitly state that same bookTitle, EVERY editor's full name, the same year, place and publisher. Citex independently checks the scenario text against these fields.\n\nTHE EDITOR DESIGNATION RULE — THIS IS WHAT THIS CATEGORY TESTS:\n- Exactly ONE editor -> the designation is \"(ed.)\" (with the trailing period, inside its own parentheses).\n- TWO editors -> the designation is \"(eds)\" (no period) and the two editor names are joined with \"and\" (e.g. \"Smith, J. and Jones, A.\").\n- Never use \"(ed.)\" for two editors, and never use \"(eds)\" for one editor.\n- The designation always comes immediately after the editor name(s) and before the year, each in its own parentheses: \"Surname, I. (ed.) (YYYY) Title. Place: Publisher.\"\n\nSCENARIOS — ANSWER LEAKAGE IS A CRITICAL FAILURE:\n- Keep each scenario short and mobile-friendly, preferably under 220 characters.\n- Use natural wording such as 'You are referencing a book edited by...' or 'You are creating a reference for a book edited by...'.\n- State the real book title, EVERY editor's FULL NAME, publication year, publisher and publication place.\n- The scenario MUST NOT show \"(ed.)\" or \"(eds)\" anywhere — that is the answer this question tests.\n- The scenario MUST NOT state, label, or abbreviate any editor's initials or surname separately, MUST NOT use the words \"initial\", \"initials\", or \"surname\" anywhere, and MUST NOT show a completed or abbreviated Harvard citation anywhere (e.g. never write \"Smith, J.\").\n- GOOD: \"You are referencing a book edited by Vincent Miller, titled Understanding digital culture, published in 2020 by SAGE Publications in London.\"\n- BAD: \"...edited by Vincent Miller (ed.), published in 2020...\" — reveals the designation directly.\n- BAD: \"...by Smith, J., published in 2020...\" — states the abbreviated citation form directly.";
	}

	private static function build_prompt_edited_book( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$difficulty_guidance = array(
			'easy'   => 'Easy: use exactly one editor, and make the confusingWords obviously wrong (e.g. "author", "editor" as a full word, a clearly different year) — testing basic recognition of the "(ed.)" convention.',
			'medium' => 'Medium: mix one-editor and two-editor questions across the batch, and make confusingWords plausible near-misses (e.g. "eds" as a distractor for a one-editor question, or "ed." for a two-editor one) — testing whether the student applies the right designation for the given editor count.',
			'hard'   => 'Hard: prefer two-editor questions, and make confusingWords very close to correct (e.g. "editor" vs "ed.", or "eds." with a stray period) — testing careful attention to exact punctuation.',
		);
		$prompt = "Generate exactly " . count( $ids ) . " distinct Liverpool Hope University Harvard / ReferenceList / Edited Book / DragDrop questions.\nDifficulty: " . ucfirst( $difficulty ) . ". " . ( $difficulty_guidance[ sanitize_key( $difficulty ) ] ?? $difficulty_guidance['medium'] ) . "\n"
			. self::edited_book_prompt_intro( $verify )
			. "\n\nDRAGDROP:\n- Citex derives each editor's surname/initials from editorFullNames, decides the designation (\"(ed.)\"/\"(eds)\") from the editor count, and constructs Question Parts and Fixed Text itself — your own questionParts/fixedText fields (if present) are for your own self-check only and are never read as authoritative.\n- The 4 Question Parts Citex builds are: [editor name(s) joined, e.g. \"Smith, J.\" or \"Smith, J. and Jones, A.\"], [designation, \"ed.\" or \"eds\"], [year], [book title].\n- confusingWords should test the designation and editor-formatting rules specifically — see DISTRACTORS below.\n\nDISTRACTORS — CRITICAL:\n- Medium exactly 3; Easy exactly 2; Hard exactly 4.\n- Prioritise designation-confusion distractors: \"author\", \"editor\" (the full word, not abbreviated), the WRONG designation for this question's editor count (\"eds\" for a one-editor question, \"ed.\" for a two-editor one), or a designation with wrong punctuation (\"ed\", \"eds.\").\n- Also acceptable: a different plausible year, city, publisher, or editor surname.\n- Every distractor must be different from ALL FOUR correct Question Parts after trimming and case-insensitive comparison, and unique from one another.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. scenario, editorFullNames, year, bookTitle, place and publisher all describe the exact same book — no contradictions.\n2. The scenario names every editor naturally and never shows \"(ed.)\"/\"(eds)\", never the words \"initial\"/\"initials\"/\"surname\", never a completed citation.\n3. The designation matches the editor count exactly (one editor -> \"ed.\", two -> \"eds\").\n4. Correct number of distractors for the difficulty, none matching a correct Question Part, none duplicated.\n5. Only return questions that pass all four checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * MCQ-only counterpart to edited_book_prompt_intro() (which
	 * build_prompt_edited_book()'s DragDrop path keeps using, unchanged): the
	 * same canonical-record framing and editor-designation rule, but with the
	 * scenario/answer-leakage section removed entirely, since Citex authors
	 * the whole MCQ question text itself (a fixed stem — see
	 * Citex_Reference_Rules::mcq_question_stem()) and never asks Gemini for
	 * one. Kept as a separate function specifically so DragDrop's prompt is
	 * never touched by this MCQ-specific change.
	 */
	private static function edited_book_mcq_intro( $verify ) {
		return ( $verify ? 'Use Google Search to verify every bibliographic record.' : 'Do not invent bibliographic records.' )
			. "\n\nONE QUESTION = ONE CANONICAL EDITED BOOK RECORD — CRITICAL:\n- editorFullNames is an array of ONE or TWO editor full names (given name(s) + surname each), e.g. [\"Vincent Miller\"] or [\"John Smith\", \"Amy Jones\"]. Do NOT provide a surname or initials separately for any editor — Citex derives both itself from each full name.\n- year, bookTitle, place and publisher must all describe the exact same real edited book as editorFullNames. Do not mix facts from a different edition or a different book.\n- You are NOT asked for a scenario or question text — Citex supplies the entire student-facing question itself (a fixed \"Which of the following is the correct Harvard reference for an edited book?\" stem), so there is nothing for you to write and nothing for you to leak the answer through.\n\nTHE EDITOR DESIGNATION RULE — THIS IS WHAT THIS CATEGORY TESTS:\n- Exactly ONE editor -> the designation is \"(ed.)\" (with the trailing period, inside its own parentheses).\n- TWO editors -> the designation is \"(eds)\" (no period) and the two editor names are joined with \"and\" (e.g. \"Smith, J. and Jones, A.\").\n- Never use \"(ed.)\" for two editors, and never use \"(eds)\" for one editor.\n- The designation always comes immediately after the editor name(s) and before the year, each in its own parentheses: \"Surname, I. (ed.) (YYYY) Title. Place: Publisher.\"";
	}

	private static function build_prompt_edited_book_mcq( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$difficulty_guidance = array(
			'easy'   => 'Easy: use exactly one editor, and make the 3 distractors obviously wrong (e.g. designation missing entirely, or an unmistakable punctuation error) — testing basic recognition.',
			'medium' => 'Medium: mix one- and two-editor questions, and make each distractor contain one specific, realistic mistake (e.g. "(editor)" instead of "(ed.)", or the wrong designation for the editor count) — testing the ability to spot ONE particular error type per option.',
			'hard'   => 'Hard: prefer two-editor questions, and make the 3 distractors very close to correctly formatted, differing only by a small, easy-to-miss detail (e.g. "(ed.)" used for two editors, or a misplaced comma) — testing careful side-by-side comparison.',
		);
		$prompt = "Generate exactly " . count( $ids ) . " distinct Liverpool Hope University Harvard / ReferenceList / Edited Book multiple-choice questions.\nDifficulty: " . ucfirst( $difficulty ) . ". " . ( $difficulty_guidance[ sanitize_key( $difficulty ) ] ?? $difficulty_guidance['medium'] ) . "\n"
			. self::edited_book_mcq_intro( $verify )
			. self::distractor_prompt_section( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, 'Editor(s), Initials. (ed.|eds) (YYYY) Title. Place: Publisher.' )
			. "\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. editorFullNames, year, bookTitle, place and publisher all describe the exact same book — no contradictions.\n2. Exactly 3 distractors are provided, each with a non-empty, specific errorReason naming the Harvard rule it breaks, especially designation mistakes for this category.\n3. Every distractor, re-read end-to-end against the full correct format, genuinely still breaks the rule named in its errorReason — none of them accidentally also satisfies every Harvard rule (in particular, none accidentally uses the correct \"(ed.)\"/\"(eds)\" designation for this question's editor count).\n4. All 3 distractors are mutually distinct from each other and from the correct reference, and exactly one reference overall (the one Citex will construct) is fully correct.\n5. Only return questions that pass all five checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * "Identify the error" MCQ mechanic (Citex_Question_Scenarios'
	 * `identify_error`, both categories) — the scenario shown to the
	 * student is ONE deliberately broken reference; the 4 options are
	 * plain-English descriptions of what might be wrong with it, not
	 * reference strings. Reuses the exact same distractor-construction
	 * skill Gemini already has for `select_correct` (one broken reference +
	 * a specific named errorReason, drawn from
	 * Citex_Reference_Rules::mcq_distractor_patterns()) — the only new
	 * thing asked of Gemini is `wrongDescriptions`: three plausible but
	 * untrue descriptions of what else could be wrong, so the 4 options
	 * (3 wrong descriptions + Citex's own blank 4th slot) and the Answer
	 * field (the TRUE description, i.e. errorReason) slot into exactly the
	 * same "3 distractors, 1 blank, answer = full text, never duplicated
	 * into an option" shape every other MCQ pattern already uses.
	 */
	private static function build_prompt_identify_error( $category, $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$is_edited_book = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category;
		$person_field   = $is_edited_book ? 'editorFullNames' : 'authorFullNames';
		$person_noun    = $is_edited_book ? 'editor' : 'author';
		$correct_format = $is_edited_book
			? 'Editor(s), Initials. (ed.|eds) (YYYY) Title. Place: Publisher.'
			: 'Surname, I. (YYYY) Book Title. Place: Publisher.';
		$patterns  = Citex_Reference_Rules::mcq_distractor_patterns( $category );
		$catalogue = '';
		foreach ( $patterns as $index => $pattern ) {
			$catalogue .= "\n  " . ( $index + 1 ) . '. ' . $pattern;
		}
		$canonical_intro = $is_edited_book
			? "ONE QUESTION = ONE CANONICAL EDITED BOOK RECORD — CRITICAL:\n- editorFullNames is an array of real editor full name(s). Do NOT provide a surname or initials separately for any editor — Citex derives both itself from each full name.\n- year, bookTitle, place and publisher must all describe the exact same real edited book as editorFullNames.\n"
			: "ONE QUESTION = ONE CANONICAL BIBLIOGRAPHIC RECORD — CRITICAL:\n- authorFullNames is an array of real author full name(s). Do NOT provide a surname or initials separately for any author — Citex derives both itself from each full name.\n- year, bookTitle, place and publisher must all describe the exact same real book as authorFullNames.\n";

		$prompt = "Generate exactly " . count( $ids ) . " distinct Liverpool Hope University Harvard / ReferenceList / " . ( $is_edited_book ? 'Edited Book' : 'Book' ) . " \"Identify the error\" multiple-choice questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify every bibliographic record.' : 'Do not invent bibliographic records.' ) . "\n\n"
			. $canonical_intro
			. "\nTHIS QUESTION SHOWS THE STUDENT ONE BROKEN REFERENCE AND ASKS \"What is incorrect about this reference?\" — it is NOT a \"pick the correct reference\" question:\n"
			. '- Provide exactly ONE `brokenReference`: { "reference": "...", "errorReason": "..." } — a reference for the SAME canonical record, built by deliberately applying ONE of the following known Harvard error patterns (correct format: ' . $correct_format . ') — do not invent an unrelated kind of mistake:' . $catalogue . "\n"
			. "- errorReason must name the SPECIFIC rule brokenReference breaks (e.g. \"Missing the editor designation (ed.)\") — never a vague label like \"formatting error\".\n"
			. "- brokenReference must still contain every canonical fact — every $person_noun's surname and initials, the year, title, place and publisher. The ONLY thing wrong with it is the ONE mistake named in errorReason; do not also change or omit any bibliographic fact.\n"
			. "- Provide exactly THREE `wrongDescriptions`: plain-English descriptions of OTHER things that COULD be wrong with a Harvard reference (drawn from the same list of error patterns above, or a similar realistic mistake), but which are NOT actually true of brokenReference. Each must read like a genuine, plausible answer choice — never nonsensical, never obviously wrong on its face.\n"
			. "- wrongDescriptions must be mutually distinct from each other and from errorReason (reworded, not a copy).\n"
			. "- Before finalising: re-read brokenReference end-to-end against the full correct format and confirm errorReason is the ONLY true description of what is wrong with it — none of the three wrongDescriptions may also happen to be true of brokenReference (that would create a second correct answer).\n"
			. "\nFINAL SELF-CHECK — DO NOT SKIP:\n1. " . ucfirst( $person_field ) . ", year, bookTitle, place and publisher all describe the same real record — no contradictions.\n2. brokenReference genuinely contains every canonical fact and exactly ONE deliberate mistake, correctly named by errorReason.\n3. Exactly 3 wrongDescriptions are provided, each plausible, mutually distinct, and NOT true of brokenReference.\n4. Only return questions that pass all four checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	private static function system_instruction_identify_error( $category ) {
		$is_edited_book = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category;
		$noun            = $is_edited_book ? 'editor' : 'author';
		$plural_field    = $is_edited_book ? 'editorFullNames' : 'authorFullNames';
		return "You are Citex, an academic question-generation engine. Generate real, usable Liverpool Hope University Harvard ReferenceList \"Identify the error\" multiple-choice questions, not tests or fictional examples. Verify bibliographic facts when web verification is enabled. Never invent books, {$noun}s, years, publishers, or places. This question type shows the student ONE deliberately broken reference and asks what is wrong with it — you provide that one broken reference (brokenReference: {reference, errorReason}, built by deliberately applying exactly one named Harvard error pattern to the real record described by {$plural_field}/year/bookTitle/place/publisher) plus THREE plausible-but-untrue `wrongDescriptions` of other things that could be wrong but are not actually true of brokenReference. Citex is the sole authority for the correct answer (errorReason itself) and never asks you for it separately — your job is only to make brokenReference genuinely, specifically broken in exactly the one way you claim, and to make the three wrongDescriptions plausible distractors that are demonstrably NOT true of brokenReference when re-read carefully. Before returning each question, perform a strict self-check: brokenReference contains every canonical fact with exactly one deliberate mistake; errorReason names that mistake specifically; and none of the three wrongDescriptions is also true of brokenReference (that would create a second correct answer, which Citex will reject). Return only the requested JSON.";
	}

	/**
	 * "Choose the correct rule/treatment" MCQ mechanic
	 * (Citex_Question_Scenarios' `choose_treatment_*`, both categories) —
	 * tests the joining/designation RULE directly ("which statement is
	 * correct"), not via any specific book. Citex is the SOLE author of
	 * both the question stem and the one true statement
	 * (Citex_Reference_Rules::treatment_question()) — this is pure rule
	 * knowledge, so unlike every other MCQ pattern there is no
	 * bibliographic record at all for Gemini to verify, invent, or leak an
	 * answer through. Gemini's only job is three plausible-but-wrong
	 * `wrongStatements`, reusing the exact same "3 distractors, 1 blank,
	 * answer = full text, never duplicated into an option" shape every
	 * other MCQ pattern uses.
	 */
	private static function build_prompt_choose_treatment( $category, $bucket_id, $ids, $difficulty, $verify, $quality_feedback = '' ) {
		$treatment = Citex_Reference_Rules::treatment_question( $category, $bucket_id );
		$noun      = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ? 'editor' : 'author';
		$patterns  = Citex_Reference_Rules::mcq_distractor_patterns( $category );
		$catalogue = '';
		foreach ( $patterns as $index => $pattern ) {
			$catalogue .= "\n  " . ( $index + 1 ) . '. ' . $pattern;
		}
		$prompt = "Generate exactly " . count( $ids ) . " distinct Liverpool Hope University Harvard / ReferenceList \"choose the correct rule\" multiple-choice questions, EVERY ONE of them testing this exact same rule statement (there is no specific book involved at all — this is pure rule knowledge):\n\nTHE TRUE STATEMENT (Citex will use this as the correct answer — you never provide it):\n\"" . $treatment['correctStatement'] . "\"\n\nYour ONLY job for each question is to provide exactly THREE `wrongStatements`: plausible-but-INCORRECT statements about this same rule area (how multiple {$noun}s are referenced), each describing a real, specific Harvard-referencing misconception — drawn from known error patterns such as:$catalogue\n- A wrongStatement must be a genuinely different claim from the true statement above, not a reworded copy of it.\n- Every wrongStatement must be clearly, specifically wrong — never vague, never nonsensical, never trivially obvious.\n- The three must be mutually distinct from each other.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. Exactly 3 wrongStatements are provided.\n2. None of them is a reworded copy of the true statement — each describes a genuinely different (and wrong) claim.\n3. All three are mutually distinct from each other.\n4. Only return questions that pass all three checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	private static function system_instruction_choose_treatment( $category ) {
		$noun = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ? 'editor' : 'author';
		return "You are Citex, an academic question-generation engine. Generate real, usable Liverpool Hope University \"choose the correct rule\" multiple-choice questions about how multiple {$noun}s are referenced in the Liverpool Hope Harvard reference list. This question type tests pure rule knowledge, not any specific book — Citex supplies the entire question (the stem and the one true rule statement) itself, so there is no bibliographic record for you to verify, invent, or leak an answer through at all. Your only job is to provide exactly THREE plausible-but-wrong `wrongStatements` about the same rule area, each a genuinely different (and specifically incorrect) claim from the true statement Citex will use as the answer — never a reworded copy of it, never vague, never nonsensical. Before returning each question, perform a strict self-check: all three wrongStatements are mutually distinct, each is a clearly wrong (not merely reworded-correct) claim, and none of them could be read as another way of stating the true rule. Return only the requested JSON.";
	}

	/**
	 * Schema for the "choose the correct rule" MCQ mechanic — deliberately
	 * the simplest schema in the whole file: no bibliographic fields at
	 * all (authorFullNames/year/bookTitle/etc never apply — this question
	 * tests a rule, not a book), just the three wrong statements.
	 */
	private static function schema_choose_treatment() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId'      => $s,
			'wrongStatements' => array( 'type' => 'array', 'items' => $s ),
		), 'required' => array( 'questionId', 'wrongStatements' ) ) ) ), 'required' => array( 'questions' ) );
	}

	private static function schema_edited_book() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'scenario' => $s, 'editorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'bookTitle' => $s, 'place' => $s, 'publisher' => $s,
			'questionParts' => array( 'type' => 'array', 'items' => $s ), 'fixedText' => $s, 'confusingWords' => array( 'type' => 'array', 'items' => $s )
		), 'required' => array( 'questionId','scenario','editorFullNames','year','bookTitle','place','publisher','confusingWords' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * Edited Book MCQ schema: no `scenario` — unlike DragDrop, Citex
	 * authors the entire student-facing question text itself (a fixed,
	 * category-specific, non-revealing stem — see
	 * Citex_Reference_Rules::mcq_question_stem()), so Gemini is never asked
	 * for one and has nothing to leak the answer through.
	 */
	private static function schema_edited_book_mcq() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'editorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'bookTitle' => $s, 'place' => $s, 'publisher' => $s,
			'distractors' => self::distractor_schema()
		), 'required' => array( 'questionId','editorFullNames','year','bookTitle','place','publisher','distractors' ) ) ) ), 'required' => array( 'questions' ) );
	}

	private static function schema() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'scenario' => $s, 'authorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'bookTitle' => $s, 'place' => $s, 'publisher' => $s,
			'questionParts' => array( 'type' => 'array', 'items' => $s ), 'fixedText' => $s, 'confusingWords' => array( 'type' => 'array', 'items' => $s )
		), 'required' => array( 'questionId','scenario','authorFullNames','year','bookTitle','place','publisher','questionParts','fixedText','confusingWords' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * MCQ schema: Gemini supplies the same canonical bibliographic fields as
	 * DragDrop, plus exactly THREE plausible-but-incorrect Harvard reference
	 * strings — never the correct one. Citex constructs the single correct
	 * option itself (see normalise_mcq_item()), the same "Citex is the sole
	 * authority for the correct answer" principle already used for DragDrop's
	 * Question Parts/Fixed Text.
	 *
	 * No `scenario` — Citex authors the entire student-facing question text
	 * itself (a fixed, category-specific, non-revealing stem — see
	 * Citex_Reference_Rules::mcq_question_stem()), so Gemini is never asked
	 * for one and has nothing to leak the answer through.
	 */
	private static function schema_mcq() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'authorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'bookTitle' => $s, 'place' => $s, 'publisher' => $s,
			'distractors' => self::distractor_schema()
		), 'required' => array( 'questionId','authorFullNames','year','bookTitle','place','publisher','distractors' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * Journal Article DragDrop schema — same shape as schema() (Book) but
	 * with articleTitle/journalTitle/volume/issue/pages replacing
	 * bookTitle/place/publisher; there is no place/publisher concept for a
	 * journal article.
	 */
	private static function schema_journal_article() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'scenario' => $s, 'authorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'articleTitle' => $s, 'journalTitle' => $s, 'volume' => $s, 'issue' => $s, 'pages' => $s,
			'questionParts' => array( 'type' => 'array', 'items' => $s ), 'fixedText' => $s, 'confusingWords' => array( 'type' => 'array', 'items' => $s )
		), 'required' => array( 'questionId','scenario','authorFullNames','year','articleTitle','journalTitle','volume','issue','pages','questionParts','fixedText','confusingWords' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * Journal Article MCQ schema — no `scenario`, same "Citex authors the
	 * fixed stem" principle as schema_mcq()/schema_edited_book_mcq().
	 */
	private static function schema_journal_article_mcq() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'authorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'articleTitle' => $s, 'journalTitle' => $s, 'volume' => $s, 'issue' => $s, 'pages' => $s,
			'distractors' => self::distractor_schema()
		), 'required' => array( 'questionId','authorFullNames','year','articleTitle','journalTitle','volume','issue','pages','distractors' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * Schema for the "Identify the error" MCQ mechanic — one canonical
	 * record, one deliberately broken reference (brokenReference, the same
	 * {reference, errorReason} shape as one distractor_schema() entry), and
	 * three plausible-but-untrue wrongDescriptions. No `scenario` field:
	 * Citex constructs the student-facing question text itself from
	 * brokenReference (see normalise_identify_error_item()), the same
	 * "Citex authors the fixed stem" principle already used for
	 * schema_mcq()/schema_edited_book_mcq().
	 */
	private static function schema_identify_error( $category ) {
		$s = array( 'type' => 'string' );
		$person_field = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ? 'editorFullNames' : 'authorFullNames';
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId'  => $s,
			$person_field => array( 'type' => 'array', 'items' => $s ),
			'year'        => $s,
			'bookTitle'   => $s,
			'place'       => $s,
			'publisher'   => $s,
			'brokenReference' => array(
				'type'       => 'object',
				'properties' => array( 'reference' => $s, 'errorReason' => $s ),
				'required'   => array( 'reference', 'errorReason' ),
			),
			'wrongDescriptions' => array( 'type' => 'array', 'items' => $s ),
		), 'required' => array( 'questionId', $person_field, 'year', 'bookTitle', 'place', 'publisher', 'brokenReference', 'wrongDescriptions' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * Shared shape for one MCQ distractor, used by every category's MCQ
	 * schema: the wrong reference text PLUS the single, specific Harvard
	 * rule it deliberately breaks (errorReason). Forcing Gemini to name the
	 * rule for every distractor — rather than just asking for "a wrong
	 * reference" — is what makes it actually reason about which rule it is
	 * violating instead of inventing a superficially-different reference
	 * that can accidentally still be fully valid (see
	 * normalise_mcq_item()/normalise_edited_book_mcq_item(), which reject
	 * any distractor missing this reason). errorReason is for Citex's own
	 * quality control only — it is never shown to the student and is not
	 * one of the real WordPress ACF fields.
	 */
	private static function distractor_schema() {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'reference'   => array( 'type' => 'string' ),
					'errorReason' => array( 'type' => 'string' ),
				),
				'required' => array( 'reference', 'errorReason' ),
			),
		);
	}

	/**
	 * Shared MCQ distractor instructions for every category: reused by
	 * build_prompt_mcq() (Book) and build_prompt_edited_book_mcq() (Edited
	 * Book), driven by Citex_Reference_Rules::mcq_distractor_patterns( $category )
	 * — the category's own catalogue of named, realistic Harvard mistakes —
	 * so a future category only has to supply its own pattern list and
	 * correct-format description here, never rewrite this instruction text.
	 *
	 * This is the direct fix for MCQ questions being rejected by
	 * MCQ_DISTRACTOR_LOOKS_CORRECT: rather than asking Gemini for "a wrong
	 * reference" and trusting it to have actually made it wrong, this asks
	 * for a SPECIFIC named rule violation per distractor (errorReason) and
	 * makes Gemini re-read each distractor end-to-end against the full
	 * format before returning anything — the same "test the whole option,
	 * not just the intended difference" check Citex's own validator already
	 * performs independently. The two are meant to agree; when Gemini's own
	 * self-check is done properly, Citex's validator has nothing left to
	 * reject.
	 */
	private static function distractor_prompt_section( $category, $correct_format ) {
		$patterns  = Citex_Reference_Rules::mcq_distractor_patterns( $category );
		$catalogue = '';
		foreach ( $patterns as $index => $pattern ) {
			$catalogue .= "\n  " . ( $index + 1 ) . '. ' . $pattern;
		}
		return "\n\nDISTRACTORS — CRITICAL, READ CAREFULLY:\n"
			. "- The goal is NOT \"create four references that look different.\" The goal is \"create ONE correct reference and THREE references that each contain one deliberate, identifiable Harvard error.\"\n"
			. "- Provide exactly 3 entries in `distractors`, each an object: { \"reference\": \"the wrong reference text\", \"errorReason\": \"the specific Harvard rule it breaks\" }. Never provide the correct reference yourself; Citex constructs it.\n"
			. '- Build each distractor by deliberately applying ONE of the following known Harvard error patterns to the correct reference (correct format: ' . $correct_format . ') — do not invent an unrelated kind of mistake:' . $catalogue . "\n"
			. "- errorReason must name the SPECIFIC rule the distractor breaks (e.g. \"Missing the editor designation (ed.)\", \"Places the year after the title instead of after the author\") — never a vague label like \"formatting error\". If you cannot identify a specific rule a distractor breaks, do not include it — regenerate it using a different pattern from the list above instead.\n"
			. "- Before finalising, reason through every option exactly like this, and only return the question once all four checks below pass:\n"
			. "  1. Which reference is correct, and why does it satisfy every Harvard rule?\n"
			. "  2. For each of the 3 distractors: re-read it end-to-end against the FULL correct format (not just the one detail you intended to break) — does it genuinely still violate the rule named in its errorReason, with no other part of it accidentally making it correct again? A distractor that is wrong in your head but technically satisfies every Harvard rule when read literally is NOT a valid distractor and must be rebuilt.\n"
			. "  3. Are all 3 distractors mutually different from each other and from the correct reference?\n"
			. "  4. Is exactly ONE reference overall — the correct one Citex will construct — fully valid? If a second reference (any distractor) would also satisfy every Harvard rule, that is a failure: rebuild that distractor with a different, unambiguous error before returning anything.\n"
			. "- Distractors must still be realistic and plausible — never nonsensical, never an unrelated sentence, never a trivially obvious non-reference (e.g. \"This is not a reference.\"). Every distractor should read like a genuine attempt at the reference with one real student mistake in it.\n"
			. "- Do NOT invent a different author/editor, year, title, place or publisher for a distractor — every distractor must still describe the SAME book as the canonical record above, just formatted incorrectly.\n"
			. '- None of the 3 distractors may, even coincidentally, already be a fully correctly-formatted reference for this book.';
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
	private static function normalise( $questions, $ids, $difficulty, $exercises = array(), $type = 'DragDrop', $category = null, $target_count = null, $scenario_id = '', $rule_tested = '' ) {
		$category = $category ?: Citex_Reference_Rules::CATEGORY_BOOK;
		$out = array();
		$expected_distractors = self::expected_distractor_count( $difficulty );
		foreach ( $questions as $i => $item ) {
			if ( ! is_array( $item ) ) { return new WP_Error( 'citex_ai_bad_question', sprintf( __( 'Question %d was not a valid object.', 'citex-tools' ), $i + 1 ) ); }
			$id = strtoupper( trim( (string) $ids[ $i ] ) ); $year = trim( (string) ( $item['year'] ?? '' ) ); $title = trim( (string) ( $item['bookTitle'] ?? '' ) ); $place = trim( (string) ( $item['place'] ?? '' ) ); $publisher = trim( (string) ( $item['publisher'] ?? '' ) );
			// MCQ's question text is Citex's own fixed, category-specific,
			// non-revealing stem — never Gemini's own per-book "scenario"
			// prose (which risked leaking the answer, and made the question
			// itself explain what it was testing instead of just posing a
			// referencing problem). DragDrop is unaffected: its scenario
			// still describes the specific book, exactly as before, since a
			// DragDrop student needs those facts to construct the reference.
			$scenario = 'MCQ' === $type
				? Citex_Reference_Rules::mcq_question_stem( $category )
				: trim( (string) ( $item['scenario'] ?? '' ) );

			// Exercise is Citex-assigned only — resolved by slot index from the
			// matrix built before generation began, never read from $item.
			$exercise = isset( $exercises[ $i ] ) ? sanitize_text_field( (string) $exercises[ $i ] ) : 'Exercise 1';

			if ( 'MCQ' === $type && 0 === strpos( (string) $scenario_id, 'choose_treatment_' ) ) {
				// "Choose the correct rule/treatment" needs none of the
				// generic bibliographic-data fields at all — no book, no
				// author/editor list — since it tests pure rule knowledge;
				// see normalise_choose_treatment_item()'s docblock.
				$candidate = self::normalise_choose_treatment_item( $item, $id, $category, substr( (string) $scenario_id, strlen( 'choose_treatment_' ) ), $exercise, $difficulty );
			} elseif ( 'MCQ' === $type && 'identify_error' === $scenario_id ) {
				// "Identify the error" has its own normaliser (a
				// fundamentally different data shape — a shown broken
				// reference plus text descriptions, not a generic fixed
				// stem) but shares the same person-array extraction/
				// derivation and target-count enforcement as every other
				// MCQ pattern, just for whichever field name this
				// category's people live under.
				if ( '' === $year || '' === $title || '' === $place || '' === $publisher ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing required bibliographic data.', 'citex-tools' ), $id ) ); }
				$is_edited_book = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category;
				$person_names = array_values( array_filter( array_map( 'trim', (array) ( $item[ $is_edited_book ? 'editorFullNames' : 'authorFullNames' ] ?? array() ) ), 'strlen' ) );
				if ( empty( $person_names ) || count( $person_names ) > 12 ) { return new WP_Error( 'citex_ai_bad_author_count', sprintf( __( 'Question %s must have 1 or more %2$s (12 at most); %3$d were provided.', 'citex-tools' ), $id, $is_edited_book ? 'editors' : 'authors', count( $person_names ) ) ); }
				if ( null !== $target_count && count( $person_names ) !== $target_count ) { return new WP_Error( 'citex_ai_author_count_mismatch', sprintf( __( 'Question %1$s must have exactly %2$d %3$s for this scenario; %4$d were provided.', 'citex-tools' ), $id, $target_count, $is_edited_book ? 'editors' : 'authors', count( $person_names ) ) ); }
				$people = array();
				foreach ( $person_names as $full_name ) {
					$parts = self::derive_author_parts( $full_name );
					if ( is_wp_error( $parts ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %1$s: %2$s', 'citex-tools' ), $id, $parts->get_error_message() ) ); }
					$people[] = array( 'fullName' => $full_name, 'surname' => $parts['surname'], 'initials' => $parts['initials'] );
				}
				$candidate = self::normalise_identify_error_item( $item, $id, $category, $people, $year, $title, $place, $publisher, $exercise, $difficulty );
			} elseif ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
				if ( '' === $scenario || '' === $year || '' === $title || '' === $place || '' === $publisher ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing required bibliographic data.', 'citex-tools' ), $id ) ); }
				$editor_names = array_values( array_filter( array_map( 'trim', (array) ( $item['editorFullNames'] ?? array() ) ), 'strlen' ) );
				if ( empty( $editor_names ) || count( $editor_names ) > 12 ) { return new WP_Error( 'citex_ai_bad_editor_count', sprintf( __( 'Question %s must have 1 or more editors (12 at most); %d were provided.', 'citex-tools' ), $id, count( $editor_names ) ) ); }
				if ( null !== $target_count && count( $editor_names ) !== $target_count ) { return new WP_Error( 'citex_ai_editor_count_mismatch', sprintf( __( 'Question %1$s must have exactly %2$d editors for this scenario; %3$d were provided.', 'citex-tools' ), $id, $target_count, count( $editor_names ) ) ); }
				$editors = array();
				foreach ( $editor_names as $editor_full_name ) {
					$editor_parts = self::derive_author_parts( $editor_full_name );
					if ( is_wp_error( $editor_parts ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %1$s: %2$s', 'citex-tools' ), $id, $editor_parts->get_error_message() ) ); }
					$editors[] = array( 'fullName' => $editor_full_name, 'surname' => $editor_parts['surname'], 'initials' => $editor_parts['initials'] );
				}
				$candidate = 'MCQ' === $type
					? self::normalise_edited_book_mcq_item( $item, $id, $editors, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty )
					: self::normalise_edited_book_item( $item, $id, $editors, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty, $expected_distractors );
			} elseif ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
				$article_title = trim( (string) ( $item['articleTitle'] ?? '' ) );
				$journal_title = trim( (string) ( $item['journalTitle'] ?? '' ) );
				$volume        = trim( (string) ( $item['volume'] ?? '' ) );
				$issue         = trim( (string) ( $item['issue'] ?? '' ) );
				$pages         = trim( (string) ( $item['pages'] ?? '' ) );
				if ( '' === $scenario || '' === $year || '' === $article_title || '' === $journal_title || '' === $volume || '' === $issue || '' === $pages ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing required bibliographic data.', 'citex-tools' ), $id ) ); }
				// Liverpool Hope's reference-list rule (confirmed, identical to
				// Book): a Journal Article can have any real author count, all
				// always listed in full — see build_journal_article_reference()'s
				// docblock. The upper bound here is a pure sanity guard against
				// garbled model output, not a Harvard rule.
				$author_names = array_values( array_filter( array_map( 'trim', (array) ( $item['authorFullNames'] ?? array() ) ), 'strlen' ) );
				if ( empty( $author_names ) || count( $author_names ) > 12 ) { return new WP_Error( 'citex_ai_bad_author_count', sprintf( __( 'Question %s must have 1 or more authors (12 at most); %d were provided.', 'citex-tools' ), $id, count( $author_names ) ) ); }
				if ( null !== $target_count && count( $author_names ) !== $target_count ) { return new WP_Error( 'citex_ai_author_count_mismatch', sprintf( __( 'Question %1$s must have exactly %2$d authors for this scenario; %3$d were provided.', 'citex-tools' ), $id, $target_count, count( $author_names ) ) ); }
				$authors = array();
				foreach ( $author_names as $author_full_name ) {
					$author_parts = self::derive_author_parts( $author_full_name );
					if ( is_wp_error( $author_parts ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %1$s: %2$s', 'citex-tools' ), $id, $author_parts->get_error_message() ) ); }
					$authors[] = array( 'fullName' => $author_full_name, 'surname' => $author_parts['surname'], 'initials' => $author_parts['initials'] );
				}
				$candidate = 'MCQ' === $type
					? self::normalise_journal_article_mcq_item( $item, $id, $authors, $year, $article_title, $journal_title, $volume, $issue, $pages, $scenario, $exercise, $difficulty )
					: self::normalise_journal_article_item( $item, $id, $authors, $year, $article_title, $journal_title, $volume, $issue, $pages, $scenario, $exercise, $difficulty, $expected_distractors );
			} else {
				if ( '' === $scenario || '' === $year || '' === $title || '' === $place || '' === $publisher ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing required bibliographic data.', 'citex-tools' ), $id ) ); }
				// Liverpool Hope's reference-list rule (confirmed): a Book can
				// have any real author count, all always listed in full — see
				// Citex_Reference_Rules::build_reference()'s docblock. The
				// upper bound here is a pure sanity guard against garbled
				// model output, not a Harvard rule.
				$author_names = array_values( array_filter( array_map( 'trim', (array) ( $item['authorFullNames'] ?? array() ) ), 'strlen' ) );
				if ( empty( $author_names ) || count( $author_names ) > 12 ) { return new WP_Error( 'citex_ai_bad_author_count', sprintf( __( 'Question %s must have 1 or more authors (12 at most); %d were provided.', 'citex-tools' ), $id, count( $author_names ) ) ); }
				if ( null !== $target_count && count( $author_names ) !== $target_count ) { return new WP_Error( 'citex_ai_author_count_mismatch', sprintf( __( 'Question %1$s must have exactly %2$d authors for this scenario; %3$d were provided.', 'citex-tools' ), $id, $target_count, count( $author_names ) ) ); }
				$authors = array();
				foreach ( $author_names as $author_full_name ) {
					$author_parts = self::derive_author_parts( $author_full_name );
					if ( is_wp_error( $author_parts ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %1$s: %2$s', 'citex-tools' ), $id, $author_parts->get_error_message() ) ); }
					$authors[] = array( 'fullName' => $author_full_name, 'surname' => $author_parts['surname'], 'initials' => $author_parts['initials'] );
				}

				$candidate = 'MCQ' === $type
					? self::normalise_mcq_item( $item, $id, $authors, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty )
					: self::normalise_dragdrop_item( $item, $id, $authors, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty, $expected_distractors );
			}
			if ( is_wp_error( $candidate ) ) { return $candidate; }

			// The question's blueprint — {category, questionType, scenario,
			// ruleTested, difficulty} — is what lets Citex_Question_Diversity
			// measure and steer coverage across a category's testable rules
			// instead of merely generating random questions. $scenario_id is
			// '' for any caller that does not pass one (e.g. a direct
			// programmatic call outside Citex_Generator's own scenario-group
			// loop) — the candidate still gets a valid, if empty, blueprint
			// rather than an error.
			$candidate['blueprint'] = array(
				'category'     => $category,
				'questionType' => $type,
				'scenario'     => $scenario_id,
				'ruleTested'   => $rule_tested,
				'difficulty'   => ucfirst( $difficulty ),
			);

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
	 * Text, via Citex_Reference_Rules::dragdrop_shape() — the same
	 * pluggable layer that also drives Citex_Generated_Validator, so the
	 * two can never silently disagree about what "correct" looks like.
	 * dragdrop_shape() branches on author count: a single author keeps the
	 * original 4-part shape; two or more use a 3-part shape (the whole
	 * joined author list as one draggable part) — see its docblock.
	 * Gemini's own questionParts/fixedText output is never trusted; Gemini's
	 * own fields could previously agree with each other while the
	 * separately-written scenario described a different book entirely —
	 * constructing Question Parts and Fixed Text here makes that
	 * structurally impossible instead of merely self-consistent.
	 *
	 * @param array $authors array<{fullName, surname, initials}>, 1 or more.
	 * @return array|WP_Error
	 */
	private static function normalise_dragdrop_item( $item, $id, $authors, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty, $expected_distractors ) {
		$distractors = array_values( array_filter( array_map( 'trim', (array) ( $item['confusingWords'] ?? array() ) ), 'strlen' ) );
		$fields = array( 'authors' => $authors, 'year' => $year, 'title' => $title, 'place' => $place, 'publisher' => $publisher );
		$shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_BOOK, $fields );
		$parts = $shape['parts'];
		$fixed = $shape['fixedText'];
		$count = self::placeholder_count( $fixed ); if ( is_wp_error( $count ) ) { return $count; } if ( count( $parts ) !== $count ) { return new WP_Error( 'citex_ai_bad_placeholders', sprintf( __( 'Question %1$s has %2$d draggable placeholder tokens; %3$d are required.', 'citex-tools' ), $id, $count, count( $parts ) ) ); }
		if ( count( $distractors ) !== $expected_distractors ) { return new WP_Error( 'citex_ai_bad_distractors', sprintf( __( 'Question %s has %d distractors; %d are required for %s difficulty.', 'citex-tools' ), $id, count( $distractors ), $expected_distractors, ucfirst( $difficulty ) ) ); }
		$correct_lower = array_map( 'strtolower', array_map( 'trim', $parts ) ); $seen = array();
		foreach ( $distractors as $distractor ) {
			$normal = strtolower( trim( $distractor ) );
			if ( in_array( $normal, $correct_lower, true ) ) { return new WP_Error( 'citex_ai_distractor_matches_part', sprintf( __( 'Question %s has a distractor that duplicates a correct Question Part: %s.', 'citex-tools' ), $id, $distractor ) ); }
			if ( isset( $seen[ $normal ] ) ) { return new WP_Error( 'citex_ai_duplicate_distractor', sprintf( __( 'Question %s has a duplicate distractor: %s.', 'citex-tools' ), $id, $distractor ) ); }
			$seen[ $normal ] = true;
		}
		$reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_BOOK, $fields );
		$author_full_names = array_column( $authors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'Harvard | ReferenceList | Book | DragDrop | %s', $id ), 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Book', 'exercise' => $exercise, 'type' => 'DragDrop', 'institution' => 'Liverpool Hope University', 'difficulty' => ucfirst( $difficulty ), 'scenario' => sanitize_textarea_field( $scenario ), 'authors' => array_map( function ( $author ) { return array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'initials' => sanitize_text_field( $author['initials'] ) ); }, $authors ), 'authorFullNames' => array_values( array_map( 'sanitize_text_field', $author_full_names ) ), 'authorFullName' => sanitize_text_field( $authors[0]['fullName'] ), 'authorSurname' => sanitize_text_field( $authors[0]['surname'] ), 'authorInitials' => sanitize_text_field( $authors[0]['initials'] ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'place' => sanitize_text_field( $place ), 'publisher' => sanitize_text_field( $publisher ), 'fixedText' => sanitize_text_field( $fixed ), 'questionParts' => array_values( array_map( 'sanitize_text_field', $parts ) ), 'confusingWords' => array_values( array_map( 'sanitize_text_field', $distractors ) ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * Citex — not Gemini — constructs the single correctly-formatted Harvard
	 * reference via Citex_Reference_Rules::build_reference() (the same
	 * construction used for DragDrop's reconstructedReference, joining
	 * multiple authors per Liverpool Hope's reference-list rule) and is the
	 * sole authority for the correct answer. Gemini only ever supplies
	 * THREE incorrect options; it never sees or chooses the correct one, so
	 * there is no correct-answer value for it to leak.
	 *
	 * The correct answer is never placed into, or duplicated into, any of
	 * the 4 option slots — see the "Option 1-3 hold the 3 distractors"
	 * comment below and Citex_Generated_Validator::validate_mcq().
	 *
	 * @param array $authors array<{fullName, surname, initials}>, 1 or more.
	 * @return array|WP_Error
	 */
	private static function normalise_mcq_item( $item, $id, $authors, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty ) {
		$distractors = self::extract_mcq_distractors( $item, $id );
		if ( is_wp_error( $distractors ) ) {
			return $distractors;
		}
		$incorrect = array_column( $distractors, 'reference' );

		$fields = array( 'authors' => $authors, 'year' => $year, 'title' => $title, 'place' => $place, 'publisher' => $publisher );
		$reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_BOOK, $fields );
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

		// Option 1-3 hold the 3 distractors, in the order Gemini supplied
		// them; Option 4 is ALWAYS left blank. The correct answer lives only
		// in the Answer field (reconstructedReference, below) — it is never
		// placed into, or duplicated into, any option slot. This is the
		// direct fix for a real reported bug: the previous design placed the
		// correct reference into a random option slot AND into the Answer
		// field, and the student app rendered the two copies as separate,
		// simultaneously-"selected" choices.
		$options = $incorrect;
		$options[] = '';
		$option_reasons = array_map( 'sanitize_text_field', array_column( $distractors, 'errorReason' ) );
		$option_reasons[] = null;

		// Citex — not Gemini — writes the hint too, deterministically from
		// the category's own fixed, non-revealing clue (see
		// Citex_Reference_Rules::mcq_hint()) rather than naming which option
		// is correct. This is the real site's "Hint" field content (there
		// is no separate "explanation" field — see
		// class-citex-populator.php's FIELD_HINT), and it is shown to the
		// student BEFORE they answer, so it must never identify the correct
		// answer — see Citex_Generated_Validator::validate_mcq_hint_safety().
		$hint = Citex_Reference_Rules::mcq_hint( Citex_Reference_Rules::CATEGORY_BOOK );

		// The revealing counterpart — internal/admin-only, never written to
		// WordPress (no such field exists on the real site) and never read
		// by validation. Kept purely so an admin reviewing the pending
		// queue can see WHY the Answer field's value is correct, matching
		// the "hint never reveals, explanation may (once shown post-answer)"
		// distinction even though only the non-revealing hint currently has
		// a real field to live in.
		$answer_explanation = 'The correct reference follows the required Harvard reference structure: Surname, Initials. (Year) Title. Place: Publisher. — with every author listed in full and joined with "and"/commas when there is more than one.';

		$author_full_names = array_column( $authors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'Harvard | ReferenceList | Book | MCQ | %s', $id ), 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Book', 'exercise' => $exercise, 'type' => 'MCQ', 'institution' => 'Liverpool Hope University', 'difficulty' => ucfirst( $difficulty ), 'scenario' => sanitize_textarea_field( $scenario ), 'authors' => array_map( function ( $author ) { return array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'initials' => sanitize_text_field( $author['initials'] ) ); }, $authors ), 'authorFullNames' => array_values( array_map( 'sanitize_text_field', $author_full_names ) ), 'authorFullName' => sanitize_text_field( $authors[0]['fullName'] ), 'authorSurname' => sanitize_text_field( $authors[0]['surname'] ), 'authorInitials' => sanitize_text_field( $authors[0]['initials'] ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'place' => sanitize_text_field( $place ), 'publisher' => sanitize_text_field( $publisher ), 'options' => array_values( array_map( 'sanitize_text_field', $options ) ), 'optionErrorReasons' => $option_reasons, 'hint' => sanitize_textarea_field( $hint ), 'answerExplanation' => sanitize_textarea_field( $answer_explanation ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * Extracts and validates the 3 {reference, errorReason} distractor
	 * objects Gemini supplies for one MCQ item (see distractor_schema()) —
	 * shared by every category's normalise_*_mcq_item(), so a future
	 * category's MCQ item builder gets this structural gate for free.
	 *
	 * A distractor missing a specific, non-empty errorReason is rejected
	 * here — same as any other WP_Error returned during normalise(), this
	 * fails the whole batch's quality gate and triggers Citex_AI_V2's
	 * existing regenerate-with-feedback retry loop (see
	 * generate_questions()) rather than silently accepting an unreasoned
	 * distractor. Per the "if an incorrect option cannot be given a
	 * specific valid error reason, reject and regenerate" requirement, this
	 * is a pure presence/non-emptiness check — it cannot itself verify the
	 * reason is accurate, but Citex_Generated_Validator::validate_mcq()'s
	 * existing MCQ_DISTRACTOR_LOOKS_CORRECT check (every non-correct option
	 * re-run through the real Harvard format rules) remains the actual
	 * authority on whether a distractor is genuinely wrong — this check
	 * only makes Gemini commit to a specific claim it can be judged against.
	 *
	 * @return array|WP_Error array of ['reference' => string, 'errorReason' => string], always exactly 3
	 */
	private static function extract_mcq_distractors( $item, $id ) {
		$raw = is_array( $item['distractors'] ?? null ) ? array_values( $item['distractors'] ) : array();
		if ( 3 !== count( $raw ) ) {
			return new WP_Error( 'citex_ai_bad_mcq_options', sprintf( __( 'Question %1$s has %2$d distractor(s); exactly 3 are required.', 'citex-tools' ), $id, count( $raw ) ) );
		}
		$distractors = array();
		foreach ( $raw as $index => $entry ) {
			$reference = is_array( $entry ) ? trim( (string) ( $entry['reference'] ?? '' ) ) : '';
			$reason    = is_array( $entry ) ? trim( (string) ( $entry['errorReason'] ?? '' ) ) : '';
			if ( '' === $reference ) {
				return new WP_Error( 'citex_ai_mcq_distractor_missing_reference', sprintf( __( 'Question %1$s distractor %2$d is missing its reference text.', 'citex-tools' ), $id, $index + 1 ) );
			}
			if ( '' === $reason ) {
				return new WP_Error( 'citex_ai_mcq_distractor_reason_missing', sprintf( __( 'Question %1$s distractor %2$d ("%3$s") has no specific errorReason — every incorrect option must name the Harvard rule it breaks.', 'citex-tools' ), $id, $index + 1, $reference ) );
			}
			$distractors[] = array( 'reference' => $reference, 'errorReason' => $reason );
		}
		return $distractors;
	}

	/**
	 * "Identify the error" MCQ mechanic (Citex_Question_Scenarios'
	 * `identify_error`, both categories) — see build_prompt_identify_error()'s
	 * docblock for the full mechanic description. Citex builds the
	 * canonical correct reference itself (needed only for the candidate's
	 * own record-keeping; validation checks the shown brokenReference
	 * against the canonical facts directly, not against this string) via
	 * Citex_Reference_Rules::build_reference(), and authors the
	 * student-facing question text itself: a fixed "What is incorrect about
	 * the following Harvard reference?" stem followed by Gemini's
	 * (validated) brokenReference — the same "Citex is the sole authority
	 * for fixed question text" principle already used for
	 * mcq_question_stem(), just with the broken reference appended since
	 * the student needs to see it to answer.
	 *
	 * wrongDescriptions become options 1-3 (order preserved), option 4
	 * stays blank, and the Answer field (reconstructedReference) holds the
	 * TRUE description (brokenReference's own errorReason) — never
	 * duplicated into any option, exactly like every other MCQ pattern's
	 * correct answer (see Citex_Generated_Validator::validate_identify_error()).
	 *
	 * @param array $people array<{fullName, surname, initials}> — authors
	 *              (Book) or editors (Edited Book), 1 or more.
	 * @return array|WP_Error
	 */
	private static function normalise_identify_error_item( $item, $id, $category, $people, $year, $title, $place, $publisher, $exercise, $difficulty ) {
		$broken_raw       = is_array( $item['brokenReference'] ?? null ) ? $item['brokenReference'] : array();
		$broken_reference = trim( (string) ( $broken_raw['reference'] ?? '' ) );
		$true_description = trim( (string) ( $broken_raw['errorReason'] ?? '' ) );
		if ( '' === $broken_reference ) {
			return new WP_Error( 'citex_ai_identify_error_missing_reference', sprintf( __( 'Question %s is missing its brokenReference text.', 'citex-tools' ), $id ) );
		}
		if ( '' === $true_description ) {
			return new WP_Error( 'citex_ai_identify_error_missing_reason', sprintf( __( 'Question %s\'s brokenReference has no specific errorReason.', 'citex-tools' ), $id ) );
		}

		$wrong_descriptions = array_values( array_filter( array_map( 'trim', (array) ( $item['wrongDescriptions'] ?? array() ) ), 'strlen' ) );
		if ( 3 !== count( $wrong_descriptions ) ) {
			return new WP_Error( 'citex_ai_identify_error_bad_option_count', sprintf( __( 'Question %1$s has %2$d wrongDescriptions; exactly 3 are required.', 'citex-tools' ), $id, count( $wrong_descriptions ) ) );
		}

		$true_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $true_description ) ) );
		$seen        = array( $true_normal => true );
		foreach ( $wrong_descriptions as $description ) {
			$normal = strtolower( trim( preg_replace( '/\s+/', ' ', $description ) ) );
			if ( $normal === $true_normal ) {
				return new WP_Error( 'citex_ai_identify_error_option_matches_answer', sprintf( __( 'Question %s has a "wrong" description identical to the true one.', 'citex-tools' ), $id ) );
			}
			if ( isset( $seen[ $normal ] ) ) {
				return new WP_Error( 'citex_ai_identify_error_duplicate_option', sprintf( __( 'Question %s has a duplicate wrongDescription.', 'citex-tools' ), $id ) );
			}
			$seen[ $normal ] = true;
		}

		// Option 1-3 hold the 3 wrong descriptions, in the order Gemini
		// supplied them; Option 4 is ALWAYS blank — the same shape (and the
		// same reason) as every other MCQ pattern's options. The true
		// description lives only in the Answer field, below.
		$options   = $wrong_descriptions;
		$options[] = '';

		$is_edited_book = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category;
		$fields         = $is_edited_book
			? array( 'editors' => $people, 'year' => $year, 'title' => $title, 'place' => $place, 'publisher' => $publisher )
			: array( 'authors' => $people, 'year' => $year, 'title' => $title, 'place' => $place, 'publisher' => $publisher );
		$correct_reference = Citex_Reference_Rules::build_reference( $category, $fields );

		$scenario = sprintf( "What is incorrect about the following Harvard reference?\n\n%s", $broken_reference );
		$hint     = Citex_Reference_Rules::identify_error_hint( $category );
		$answer_explanation = sprintf( 'The reference shown breaks the following Harvard rule: %s', $true_description );

		$category_label = $is_edited_book ? 'Edited Book' : 'Book';
		$person_key     = $is_edited_book ? 'editors' : 'authors';
		$full_name_key  = $is_edited_book ? 'editorFullNames' : 'authorFullNames';
		$person_projection = array_map(
			function ( $person ) {
				return array( 'fullName' => sanitize_text_field( $person['fullName'] ), 'surname' => sanitize_text_field( $person['surname'] ), 'initials' => sanitize_text_field( $person['initials'] ) );
			},
			$people
		);

		return array(
			'key' => wp_generate_uuid4(),
			'questionId' => $id,
			'title' => sprintf( 'Harvard | ReferenceList | %s | MCQ | %s', $category_label, $id ),
			'source' => 'Harvard',
			'group' => 'ReferenceList',
			'category' => $category_label,
			'exercise' => $exercise,
			'type' => 'MCQ',
			'institution' => 'Liverpool Hope University',
			'difficulty' => ucfirst( $difficulty ),
			'scenario' => sanitize_textarea_field( $scenario ),
			$person_key => $person_projection,
			$full_name_key => array_values( array_map( 'sanitize_text_field', array_column( $people, 'fullName' ) ) ),
			'year' => sanitize_text_field( $year ),
			'bookTitle' => sanitize_text_field( $title ),
			'place' => sanitize_text_field( $place ),
			'publisher' => sanitize_text_field( $publisher ),
			'brokenReference' => sanitize_text_field( $broken_reference ),
			'correctReference' => sanitize_text_field( $correct_reference ),
			'options' => array_values( array_map( 'sanitize_text_field', $options ) ),
			'hint' => sanitize_textarea_field( $hint ),
			'answerExplanation' => sanitize_textarea_field( $answer_explanation ),
			'reconstructedReference' => sanitize_text_field( $true_description ),
			'mcqPattern' => 'identify_error',
			'status' => 'pending',
			'validationStatus' => 'not_validated',
			'validationErrors' => array(),
			'origin' => 'generated_ai',
			'aiProvider' => 'Gemini',
			'aiModel' => self::get_model(),
			'generatedAt' => gmdate( 'c' ),
		);
	}

	/**
	 * "Choose the correct rule/treatment" MCQ mechanic (Citex_Question_Scenarios'
	 * `choose_treatment_*`, both categories) — see build_prompt_choose_treatment()'s
	 * docblock for the full mechanic description. Citex builds BOTH the
	 * scenario (the fixed stem for this bucket) and the correct answer (the
	 * fixed true statement for this bucket) itself, via
	 * Citex_Reference_Rules::treatment_question() — there is no
	 * bibliographic record involved at all, unlike every other MCQ
	 * pattern. wrongStatements become options 1-3 (order preserved),
	 * option 4 stays blank, and the Answer field (reconstructedReference)
	 * holds the true statement — never duplicated into any option, exactly
	 * like every other MCQ pattern's correct answer.
	 *
	 * @param string $bucket_id e.g. "two_authors", "four_or_more_authors" —
	 *               the count-bucket vocabulary Citex_Reference_Rules::
	 *               treatment_question() understands (NOT the
	 *               "choose_treatment_"-prefixed scenario catalog id).
	 * @return array|WP_Error
	 */
	private static function normalise_choose_treatment_item( $item, $id, $category, $bucket_id, $exercise, $difficulty ) {
		$treatment = Citex_Reference_Rules::treatment_question( $category, $bucket_id );
		if ( null === $treatment ) {
			return new WP_Error( 'citex_ai_unknown_treatment_bucket', sprintf( __( 'Question %1$s: unrecognised choose-treatment bucket "%2$s".', 'citex-tools' ), $id, $bucket_id ) );
		}
		$correct_statement = $treatment['correctStatement'];

		$wrong_statements = array_values( array_filter( array_map( 'trim', (array) ( $item['wrongStatements'] ?? array() ) ), 'strlen' ) );
		if ( 3 !== count( $wrong_statements ) ) {
			return new WP_Error( 'citex_ai_treatment_bad_option_count', sprintf( __( 'Question %1$s has %2$d wrongStatements; exactly 3 are required.', 'citex-tools' ), $id, count( $wrong_statements ) ) );
		}

		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $correct_statement ) ) );
		$seen           = array( $correct_normal => true );
		foreach ( $wrong_statements as $statement ) {
			$normal = strtolower( trim( preg_replace( '/\s+/', ' ', $statement ) ) );
			if ( $normal === $correct_normal ) {
				return new WP_Error( 'citex_ai_treatment_option_matches_answer', sprintf( __( 'Question %s has a "wrong" statement identical to the true one.', 'citex-tools' ), $id ) );
			}
			if ( isset( $seen[ $normal ] ) ) {
				return new WP_Error( 'citex_ai_treatment_duplicate_option', sprintf( __( 'Question %s has a duplicate wrongStatement.', 'citex-tools' ), $id ) );
			}
			$seen[ $normal ] = true;
		}

		// Option 1-3 hold the 3 wrong statements, in the order Gemini
		// supplied them; Option 4 is ALWAYS blank — the true statement
		// lives only in the Answer field, below.
		$options   = $wrong_statements;
		$options[] = '';

		$category_label = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ? 'Edited Book' : 'Book';
		$hint            = Citex_Reference_Rules::treatment_hint( $category );
		$answer_explanation = sprintf( 'The correct rule statement: %s', $correct_statement );

		return array(
			'key' => wp_generate_uuid4(),
			'questionId' => $id,
			'title' => sprintf( 'Harvard | ReferenceList | %s | MCQ | %s', $category_label, $id ),
			'source' => 'Harvard',
			'group' => 'ReferenceList',
			'category' => $category_label,
			'exercise' => $exercise,
			'type' => 'MCQ',
			'institution' => 'Liverpool Hope University',
			'difficulty' => ucfirst( $difficulty ),
			'scenario' => sanitize_textarea_field( $treatment['stem'] ),
			'options' => array_values( array_map( 'sanitize_text_field', $options ) ),
			'hint' => sanitize_textarea_field( $hint ),
			'answerExplanation' => sanitize_textarea_field( $answer_explanation ),
			'reconstructedReference' => sanitize_text_field( $correct_statement ),
			'mcqPattern' => 'choose_treatment',
			'treatmentBucket' => sanitize_key( $bucket_id ),
			'status' => 'pending',
			'validationStatus' => 'not_validated',
			'validationErrors' => array(),
			'origin' => 'generated_ai',
			'aiProvider' => 'Gemini',
			'aiModel' => self::get_model(),
			'generatedAt' => gmdate( 'c' ),
		);
	}

	/**
	 * Edited Book counterpart to normalise_dragdrop_item(). Citex — never
	 * Gemini — builds the reference, the editor designation ("(ed.)" for
	 * one editor, "(eds)" for two), and the Question Parts/Fixed Text, via
	 * Citex_Reference_Rules::build_reference()/dragdrop_shape() — the same
	 * pluggable layer that also drives Citex_Generated_Validator, so the
	 * two can never silently disagree about what "correct" looks like for
	 * this category.
	 *
	 * @return array|WP_Error
	 */
	private static function normalise_edited_book_item( $item, $id, $editors, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty, $expected_distractors ) {
		$distractors = array_values( array_filter( array_map( 'trim', (array) ( $item['confusingWords'] ?? array() ) ), 'strlen' ) );
		$fields = array( 'editors' => $editors, 'year' => $year, 'title' => $title, 'place' => $place, 'publisher' => $publisher );
		$shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $fields );
		$parts = $shape['parts'];
		$fixed = $shape['fixedText'];
		$count = self::placeholder_count( $fixed ); if ( is_wp_error( $count ) ) { return $count; } if ( 4 !== $count ) { return new WP_Error( 'citex_ai_bad_placeholders', sprintf( __( 'Question %s has %d draggable placeholder tokens; exactly 4 are required.', 'citex-tools' ), $id, $count ) ); }
		if ( count( $distractors ) !== $expected_distractors ) { return new WP_Error( 'citex_ai_bad_distractors', sprintf( __( 'Question %s has %d distractors; %d are required for %s difficulty.', 'citex-tools' ), $id, count( $distractors ), $expected_distractors, ucfirst( $difficulty ) ) ); }
		$correct_lower = array_map( 'strtolower', array_map( 'trim', $parts ) ); $seen = array();
		foreach ( $distractors as $distractor ) {
			$normal = strtolower( trim( $distractor ) );
			if ( in_array( $normal, $correct_lower, true ) ) { return new WP_Error( 'citex_ai_distractor_matches_part', sprintf( __( 'Question %s has a distractor that duplicates a correct Question Part: %s.', 'citex-tools' ), $id, $distractor ) ); }
			if ( isset( $seen[ $normal ] ) ) { return new WP_Error( 'citex_ai_duplicate_distractor', sprintf( __( 'Question %s has a duplicate distractor: %s.', 'citex-tools' ), $id, $distractor ) ); }
			$seen[ $normal ] = true;
		}
		$reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $fields );
		$editor_full_names = array_column( $editors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'Harvard | ReferenceList | Edited Book | DragDrop | %s', $id ), 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Edited Book', 'exercise' => $exercise, 'type' => 'DragDrop', 'institution' => 'Liverpool Hope University', 'difficulty' => ucfirst( $difficulty ), 'scenario' => sanitize_textarea_field( $scenario ), 'editors' => array_map( function ( $editor ) { return array( 'fullName' => sanitize_text_field( $editor['fullName'] ), 'surname' => sanitize_text_field( $editor['surname'] ), 'initials' => sanitize_text_field( $editor['initials'] ) ); }, $editors ), 'editorFullNames' => array_values( array_map( 'sanitize_text_field', $editor_full_names ) ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'place' => sanitize_text_field( $place ), 'publisher' => sanitize_text_field( $publisher ), 'fixedText' => sanitize_text_field( $fixed ), 'questionParts' => array_values( array_map( 'sanitize_text_field', $parts ) ), 'confusingWords' => array_values( array_map( 'sanitize_text_field', $distractors ) ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * Edited Book counterpart to normalise_mcq_item(): same "Citex builds
	 * the one correct option, Gemini only ever supplies 3 incorrect ones"
	 * principle, using Citex_Reference_Rules::build_reference() so the
	 * correct option always carries the right "(ed.)"/"(eds)" designation
	 * for this question's actual editor count.
	 *
	 * @return array|WP_Error
	 */
	private static function normalise_edited_book_mcq_item( $item, $id, $editors, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty ) {
		$distractors = self::extract_mcq_distractors( $item, $id );
		if ( is_wp_error( $distractors ) ) {
			return $distractors;
		}
		$incorrect = array_column( $distractors, 'reference' );

		$fields = array( 'editors' => $editors, 'year' => $year, 'title' => $title, 'place' => $place, 'publisher' => $publisher );
		$reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $fields );
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

		// Option 1-3 hold the 3 distractors, in the order Gemini supplied
		// them; Option 4 is ALWAYS left blank. The correct answer lives only
		// in the Answer field (reconstructedReference, below) — never placed
		// into, or duplicated into, any option slot. See
		// normalise_mcq_item()'s matching comment for the full rationale.
		$options = $incorrect;
		$options[] = '';
		$option_reasons = array_map( 'sanitize_text_field', array_column( $distractors, 'errorReason' ) );
		$option_reasons[] = null;

		$expected_designation = Citex_Reference_Rules::designation_for_editor_count( count( $editors ) );

		// Citex — not Gemini — writes the hint too, from the category's own
		// fixed, non-revealing clue (never named to which option it points)
		// — see normalise_mcq_item()'s matching comment for the full
		// hint-vs-explanation rationale.
		$hint = Citex_Reference_Rules::mcq_hint( Citex_Reference_Rules::CATEGORY_EDITED_BOOK );

		// Internal/admin-only revealing counterpart — never written to
		// WordPress, never read by validation.
		$answer_explanation = sprintf(
			'The correct reference follows the required Harvard Edited Book reference structure — Editor(s), Initials. (%1$s) (Year) Title. Place: Publisher — using "(%1$s)" for %2$d editor(s).',
			$expected_designation,
			count( $editors )
		);

		$editor_full_names = array_column( $editors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'Harvard | ReferenceList | Edited Book | MCQ | %s', $id ), 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Edited Book', 'exercise' => $exercise, 'type' => 'MCQ', 'institution' => 'Liverpool Hope University', 'difficulty' => ucfirst( $difficulty ), 'scenario' => sanitize_textarea_field( $scenario ), 'editors' => array_map( function ( $editor ) { return array( 'fullName' => sanitize_text_field( $editor['fullName'] ), 'surname' => sanitize_text_field( $editor['surname'] ), 'initials' => sanitize_text_field( $editor['initials'] ) ); }, $editors ), 'editorFullNames' => array_values( array_map( 'sanitize_text_field', $editor_full_names ) ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'place' => sanitize_text_field( $place ), 'publisher' => sanitize_text_field( $publisher ), 'options' => array_values( array_map( 'sanitize_text_field', $options ) ), 'optionErrorReasons' => $option_reasons, 'hint' => sanitize_textarea_field( $hint ), 'answerExplanation' => sanitize_textarea_field( $answer_explanation ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * Journal Article counterpart to normalise_dragdrop_item(). Citex —
	 * never Gemini — builds the reference and the Question Parts/Fixed Text
	 * via Citex_Reference_Rules::build_reference()/dragdrop_shape(), the
	 * same pluggable layer that also drives Citex_Generated_Validator, so
	 * the two can never silently disagree about what "correct" looks like
	 * for this category. Unlike Book, the shape is ALWAYS 7 parts (see
	 * Citex_Reference_Rules::journal_article_dragdrop_shape()) — there is no
	 * single-author special case.
	 *
	 * @param array $authors array<{fullName, surname, initials}>, 1 or more.
	 * @return array|WP_Error
	 */
	private static function normalise_journal_article_item( $item, $id, $authors, $year, $article_title, $journal_title, $volume, $issue, $pages, $scenario, $exercise, $difficulty, $expected_distractors ) {
		$distractors = array_values( array_filter( array_map( 'trim', (array) ( $item['confusingWords'] ?? array() ) ), 'strlen' ) );
		$fields = array( 'authors' => $authors, 'year' => $year, 'articleTitle' => $article_title, 'journalTitle' => $journal_title, 'volume' => $volume, 'issue' => $issue, 'pages' => $pages );
		$shape = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields );
		$parts = $shape['parts'];
		$fixed = $shape['fixedText'];
		$count = self::placeholder_count( $fixed ); if ( is_wp_error( $count ) ) { return $count; } if ( 7 !== $count ) { return new WP_Error( 'citex_ai_bad_placeholders', sprintf( __( 'Question %1$s has %2$d draggable placeholder tokens; exactly 7 are required.', 'citex-tools' ), $id, $count ) ); }
		if ( count( $distractors ) !== $expected_distractors ) { return new WP_Error( 'citex_ai_bad_distractors', sprintf( __( 'Question %s has %d distractors; %d are required for %s difficulty.', 'citex-tools' ), $id, count( $distractors ), $expected_distractors, ucfirst( $difficulty ) ) ); }
		$correct_lower = array_map( 'strtolower', array_map( 'trim', $parts ) ); $seen = array();
		foreach ( $distractors as $distractor ) {
			$normal = strtolower( trim( $distractor ) );
			if ( in_array( $normal, $correct_lower, true ) ) { return new WP_Error( 'citex_ai_distractor_matches_part', sprintf( __( 'Question %s has a distractor that duplicates a correct Question Part: %s.', 'citex-tools' ), $id, $distractor ) ); }
			if ( isset( $seen[ $normal ] ) ) { return new WP_Error( 'citex_ai_duplicate_distractor', sprintf( __( 'Question %s has a duplicate distractor: %s.', 'citex-tools' ), $id, $distractor ) ); }
			$seen[ $normal ] = true;
		}
		$reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields );
		$author_full_names = array_column( $authors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'Harvard | ReferenceList | Journal Article | DragDrop | %s', $id ), 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'exercise' => $exercise, 'type' => 'DragDrop', 'institution' => 'Liverpool Hope University', 'difficulty' => ucfirst( $difficulty ), 'scenario' => sanitize_textarea_field( $scenario ), 'authors' => array_map( function ( $author ) { return array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'initials' => sanitize_text_field( $author['initials'] ) ); }, $authors ), 'authorFullNames' => array_values( array_map( 'sanitize_text_field', $author_full_names ) ), 'authorFullName' => sanitize_text_field( $authors[0]['fullName'] ), 'authorSurname' => sanitize_text_field( $authors[0]['surname'] ), 'authorInitials' => sanitize_text_field( $authors[0]['initials'] ), 'year' => sanitize_text_field( $year ), 'articleTitle' => sanitize_text_field( $article_title ), 'journalTitle' => sanitize_text_field( $journal_title ), 'volume' => sanitize_text_field( $volume ), 'issue' => sanitize_text_field( $issue ), 'pages' => sanitize_text_field( $pages ), 'fixedText' => sanitize_text_field( $fixed ), 'questionParts' => array_values( array_map( 'sanitize_text_field', $parts ) ), 'confusingWords' => array_values( array_map( 'sanitize_text_field', $distractors ) ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * Journal Article counterpart to normalise_mcq_item(): same "Citex
	 * builds the one correct option, Gemini only ever supplies 3 incorrect
	 * ones" principle, using Citex_Reference_Rules::build_reference() for
	 * this category's format.
	 *
	 * @return array|WP_Error
	 */
	private static function normalise_journal_article_mcq_item( $item, $id, $authors, $year, $article_title, $journal_title, $volume, $issue, $pages, $scenario, $exercise, $difficulty ) {
		$distractors = self::extract_mcq_distractors( $item, $id );
		if ( is_wp_error( $distractors ) ) {
			return $distractors;
		}
		$incorrect = array_column( $distractors, 'reference' );

		$fields = array( 'authors' => $authors, 'year' => $year, 'articleTitle' => $article_title, 'journalTitle' => $journal_title, 'volume' => $volume, 'issue' => $issue, 'pages' => $pages );
		$reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields );
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

		// Option 1-3 hold the 3 distractors; Option 4 is ALWAYS blank. The
		// correct answer lives only in the Answer field (reconstructedReference,
		// below) — see normalise_mcq_item()'s matching comment for the full
		// rationale.
		$options = $incorrect;
		$options[] = '';
		$option_reasons = array_map( 'sanitize_text_field', array_column( $distractors, 'errorReason' ) );
		$option_reasons[] = null;

		$hint = Citex_Reference_Rules::mcq_hint( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE );
		$answer_explanation = 'The correct reference follows the required Harvard reference structure: Surname, Initials. (Year) Article title. Journal title, Volume(Issue), pp.Start-End. — with every author listed in full and joined with "and"/commas when there is more than one.';

		$author_full_names = array_column( $authors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'Harvard | ReferenceList | Journal Article | MCQ | %s', $id ), 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'exercise' => $exercise, 'type' => 'MCQ', 'institution' => 'Liverpool Hope University', 'difficulty' => ucfirst( $difficulty ), 'scenario' => sanitize_textarea_field( $scenario ), 'authors' => array_map( function ( $author ) { return array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'initials' => sanitize_text_field( $author['initials'] ) ); }, $authors ), 'authorFullNames' => array_values( array_map( 'sanitize_text_field', $author_full_names ) ), 'authorFullName' => sanitize_text_field( $authors[0]['fullName'] ), 'authorSurname' => sanitize_text_field( $authors[0]['surname'] ), 'authorInitials' => sanitize_text_field( $authors[0]['initials'] ), 'year' => sanitize_text_field( $year ), 'articleTitle' => sanitize_text_field( $article_title ), 'journalTitle' => sanitize_text_field( $journal_title ), 'volume' => sanitize_text_field( $volume ), 'issue' => sanitize_text_field( $issue ), 'pages' => sanitize_text_field( $pages ), 'options' => array_values( array_map( 'sanitize_text_field', $options ) ), 'optionErrorReasons' => $option_reasons, 'hint' => sanitize_textarea_field( $hint ), 'answerExplanation' => sanitize_textarea_field( $answer_explanation ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	private static function output_text( $data ) {
		if ( ! empty( $data['output_text'] ) && is_string( $data['output_text'] ) ) { return trim( $data['output_text'] ); }
		$text = array(); foreach ( (array) ( $data['steps'] ?? array() ) as $step ) { if ( 'model_output' !== ( $step['type'] ?? '' ) ) { continue; } foreach ( (array) ( $step['content'] ?? array() ) as $content ) { if ( isset( $content['text'] ) && is_string( $content['text'] ) ) { $text[] = $content['text']; } } } return trim( implode( "\n", $text ) );
	}
	private static function strip_fences( $text ) { $text = trim( $text ); $text = preg_replace( '/^```(?:json)?\s*/i', '', $text ); $text = preg_replace( '/\s*```$/', '', $text ); return trim( $text ); }
}
