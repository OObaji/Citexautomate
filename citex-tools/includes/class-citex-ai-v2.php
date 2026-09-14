<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Citex_AI_V2 {
	const OPTION_API_KEY = 'citex_gemini_api_key';
	const OPTION_MODEL = 'citex_gemini_model';
	const OPTION_WEB_VERIFY = 'citex_gemini_web_verify';
	// Admin-configurable content-length knobs (see content_realism_guidance()
	// and Citex_Reference_Rules::part_suitability()) — how many words an
	// invented author name / book-or-journal title should be, since content
	// no longer needs to be real and so is no longer naturally bounded by
	// what a real source happens to be called. Bounded to a sane 1-20 word
	// range on save; DEFAULT_* mirror this sprint's previous fixed values.
	const OPTION_MAX_AUTHOR_WORDS = 'citex_gemini_max_author_words';
	const OPTION_MAX_TITLE_WORDS  = 'citex_gemini_max_title_words';
	const DEFAULT_MAX_AUTHOR_WORDS = 4;
	const DEFAULT_MAX_TITLE_WORDS  = 12;
	const DEFAULT_MODEL = 'gemini-3.7-flash';
	const API_URL = 'https://generativelanguage.googleapis.com/v1beta/interactions';
	// Genuine generation attempts (HTTP/API/parse/structural failures) — not
	// "quality" attempts; a quality problem no longer consumes a retry (see
	// QUALITY_GATE_ENABLED below).
	const MAX_GENERATION_ATTEMPTS = 2;
	// This sprint decouples validation from generation: GENERATE -> NORMALISE
	// -> STORE, not GENERATE -> VALIDATE -> RETRY -> STORE. Every quality-only
	// check (mobile/oversized-part, punctuation-only part, duplicate part,
	// distractor-matches-part, duplicate-distractor, Journal Article's
	// min/max-part-count, MCQ option-length/duplication) is gated behind this
	// flag via quality_reject() below instead of being deleted — flipping this
	// back to true fully restores today's strict, blocking behaviour in one
	// place. Structural checks (missing required fields, unparseable author
	// names, wrong placeholder/distractor counts) are never gated — those mean
	// there is no valid record to store at all, not a quality judgement.
	const QUALITY_GATE_ENABLED = false;

	/**
	 * Every quality-only rejection site funnels through here instead of
	 * `return new WP_Error(...)` directly, so it stops blocking storage
	 * while QUALITY_GATE_ENABLED is false without losing the check itself —
	 * `Citex_Generated_Validator::validate()` still runs on every candidate
	 * and still records the real status/errors for later manual validation.
	 *
	 * @return WP_Error|null
	 */
	private static function quality_reject( $code, $message ) {
		return self::QUALITY_GATE_ENABLED ? new WP_Error( $code, $message ) : null;
	}

	public static function get_api_key() {
		$env = getenv( 'GEMINI_API_KEY' );
		return is_string( $env ) && '' !== trim( $env ) ? trim( $env ) : trim( (string) get_option( self::OPTION_API_KEY, '' ) );
	}
	public static function get_model() {
		$model = trim( (string) get_option( self::OPTION_MODEL, self::DEFAULT_MODEL ) );
		return '' !== $model ? $model : self::DEFAULT_MODEL;
	}
	public static function web_verification_enabled() { return (bool) get_option( self::OPTION_WEB_VERIFY, false ); }

	/**
	 * How many words an invented author full name should be (e.g. a first
	 * and last name is 2) — admin-configurable via AI Settings, read by
	 * content_realism_guidance() (steers Gemini directly) and
	 * Citex_Reference_Rules::part_suitability() (the non-blocking backstop
	 * check). Clamped to 1-20 so a mistaken value can never disable the
	 * check entirely or produce an unusably tiny one.
	 */
	public static function max_author_words() {
		return max( 1, min( 20, absint( get_option( self::OPTION_MAX_AUTHOR_WORDS, self::DEFAULT_MAX_AUTHOR_WORDS ) ) ) );
	}

	/**
	 * How many words an invented book/article/webpage title should be —
	 * same role as max_author_words() but for titles, which are naturally
	 * longer than a name.
	 */
	public static function max_title_words() {
		return max( 1, min( 20, absint( get_option( self::OPTION_MAX_TITLE_WORDS, self::DEFAULT_MAX_TITLE_WORDS ) ) ) );
	}

	/**
	 * The single word-count backstop Citex_Reference_Rules::part_suitability()
	 * checks a draggable Question Part against — since a part could be
	 * either an author name or a title (or another field entirely),
	 * whichever of the two configured limits is larger is used, so neither
	 * one ever gets flagged as "too long" against the other's tighter
	 * setting.
	 */
	private static function configured_part_word_limit() {
		return max( self::max_author_words(), self::max_title_words() );
	}

	public static function save_settings( $api_key, $model, $web_verify, $max_author_words = null, $max_title_words = null ) {
		if ( '' !== trim( (string) $api_key ) ) { update_option( self::OPTION_API_KEY, trim( (string) $api_key ), false ); }
		update_option( self::OPTION_MODEL, '' !== trim( (string) $model ) ? sanitize_text_field( $model ) : self::DEFAULT_MODEL, false );
		update_option( self::OPTION_WEB_VERIFY, ! empty( $web_verify ), false );
		if ( null !== $max_author_words ) {
			update_option( self::OPTION_MAX_AUTHOR_WORDS, max( 1, min( 20, absint( $max_author_words ) ) ), false );
		}
		if ( null !== $max_title_words ) {
			update_option( self::OPTION_MAX_TITLE_WORDS, max( 1, min( 20, absint( $max_title_words ) ) ), false );
		}
	}
	public static function maybe_handle_submit() {
		if ( empty( $_POST['citex_ai_save_settings'] ) ) { return; }
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to manage Citex AI settings.', 'citex-tools' ) ); }
		check_admin_referer( 'citex_ai_settings', 'citex_ai_settings_nonce' );
		self::save_settings(
			isset( $_POST['citex_gemini_api_key'] ) ? wp_unslash( $_POST['citex_gemini_api_key'] ) : '',
			isset( $_POST['citex_gemini_model'] ) ? wp_unslash( $_POST['citex_gemini_model'] ) : self::DEFAULT_MODEL,
			! empty( $_POST['citex_gemini_web_verify'] ),
			isset( $_POST['citex_max_author_words'] ) ? absint( $_POST['citex_max_author_words'] ) : self::DEFAULT_MAX_AUTHOR_WORDS,
			isset( $_POST['citex_max_title_words'] ) ? absint( $_POST['citex_max_title_words'] ) : self::DEFAULT_MAX_TITLE_WORDS
		);
		Citex_Admin::set_notice( __( 'Gemini AI settings saved.', 'citex-tools' ), 'success' );
		wp_safe_redirect( admin_url( 'admin.php?page=citex-ai' ) ); exit;
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to manage Citex AI settings.', 'citex-tools' ) ); }
		self::maybe_handle_submit();
		$has_key = '' !== self::get_api_key(); $model = self::get_model(); $web_verify = self::web_verification_enabled();
		$max_author_words = self::max_author_words(); $max_title_words = self::max_title_words();
		require CITEX_TOOLS_PATH . 'admin/views/ai-settings.php';
	}

	public static function generate_questions( $args ) {
		$key = self::get_api_key();
		if ( '' === $key ) { return new WP_Error( 'citex_ai_no_key', __( 'Gemini is not configured. Add a Gemini API key in Citex → AI Settings first.', 'citex-tools' ) ); }
		$quantity = max( 1, min( 100, absint( $args['quantity'] ?? 10 ) ) );
		$difficulty = sanitize_key( $args['difficulty'] ?? 'medium' );
		$verify = isset( $args['web_verify'] ) ? (bool) $args['web_verify'] : self::web_verification_enabled();
		// 'mla' and 'apa' are the only other supported referencing styles —
		// anything else (including the default) stays the original Harvard
		// path. APA is currently Book-only (Phase 1) — Citex_Generator
		// itself restricts $category to 'book' whenever style is 'apa', so
		// this never needs checking again below.
		$style = sanitize_key( $args['style'] ?? 'harvard' );
		if ( ! in_array( $style, array( 'harvard', 'mla', 'apa' ), true ) ) {
			$style = 'harvard';
		}
		// 'intext' is the only other supported question GROUP — an
		// independent dimension from $style (Harvard/MLA both have their
		// own in-text citation rules — see Citex_Intext_Citation_Rules and
		// Citex_MLA_Intext_Citation_Rules). $citation_form only matters
		// when $group is 'intext'; it names which of the 3 in-text forms
		// (narrative/parenthetical/parenthetical_quote) this batch builds.
		$group = 'intext' === sanitize_key( $args['group'] ?? 'referencelist' ) ? 'intext' : 'referencelist';
		// Guarded behind $group itself — never touches Citex_Intext_Citation_Rules
		// at all for an ordinary 'referencelist' call, exactly like the
		// $style branch above never touches Citex_MLA_Reference_Rules
		// unless it is actually needed — so a caller that never generates
		// in-text citation questions has no dependency on that class.
		$citation_form = 'narrative';
		if ( 'intext' === $group ) {
			$citation_form = sanitize_key( $args['citation_form'] ?? Citex_Intext_Citation_Rules::FORM_NARRATIVE );
			if ( ! in_array( $citation_form, Citex_Intext_Citation_Rules::forms(), true ) ) {
				$citation_form = Citex_Intext_Citation_Rules::FORM_NARRATIVE;
			}
		}
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
		} elseif ( 'website' === $category_key ) {
			$category = Citex_Reference_Rules::CATEGORY_WEBSITE;
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
		$scenario_instruction = self::scenario_count_instruction( $category, $target_count, $scenario_id );
		// The "exercise design" this batch/question tests. For Journal
		// Article/Website this is the batch-level design named by the
		// assigned scenario (see
		// Citex_Reference_Rules::journal_article_dragdrop_shape()'s
		// docblock) — 'full_reference' (the original, unchanged shape)
		// whenever the scenario carries no 'exerciseDesign' key. For
		// Edited Book (which has never had a scenario carry this key), the
		// default is instead 'random': normalise_edited_book_item() reads
		// this to mean "pick one of
		// Citex_Reference_Rules::edited_book_dragdrop_designs(), seeded per
		// QUESTION" rather than a single fixed design for the whole batch —
		// so not every generated question tests the same fields (year
		// always, place/publisher never). Book ignores this value entirely
		// for both MCQ and DragDrop — Citex_Book_Mcq_Variants/
		// Citex_Book_Dragdrop_Parts each pick their own per-question
		// selection directly from the question id, independent of any
		// batch-level design concept.
		$default_exercise_design = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ? 'random' : 'full_reference';
		$exercise_design = $scenario_entry['exerciseDesign'] ?? $default_exercise_design;

		// Existing reconstructed references (same category) this batch must
		// not duplicate — the one concrete "too similar to recent history"
		// case this framework checks: Gemini regenerating the exact same
		// real book/edited book a still-pending question already used. See
		// Citex_Question_Diversity::is_duplicate_reference().
		$existing_references = array_map( 'strval', (array) ( $args['existing_references'] ?? array() ) );

		$debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$batch_start = $debug ? microtime( true ) : 0;
		$last_error = '';
		for ( $attempt = 1; $attempt <= self::MAX_GENERATION_ATTEMPTS; $attempt++ ) {
			$body = array(
				'model' => self::get_model(),
				'input' => self::build_prompt_for( $type, $category, $ids, $difficulty, $verify, $last_error, $scenario_instruction, $scenario_id, $exercise_design, $style, $group, $citation_form ),
				'system_instruction' => self::system_instruction_for( $type, $category, $scenario_id, $style, $group, $citation_form ),
				'response_format' => array( array( 'type' => 'text', 'mime_type' => 'application/json', 'schema' => self::schema_for( $type, $category, $scenario_id, $style, $group, $citation_form ) ) ),
				'generation_config' => array( 'max_output_tokens' => max( 4000, min( 24000, $quantity * 650 ) ) ),
			);
			if ( $verify ) { $body['tools'] = array( array( 'type' => 'google_search' ) ); }
			$request_start = $debug ? microtime( true ) : 0;
			$response = wp_remote_post( self::API_URL, array( 'timeout' => 120, 'headers' => array( 'Content-Type' => 'application/json', 'x-goog-api-key' => $key ), 'body' => wp_json_encode( $body ) ) );
			if ( $debug ) { error_log( sprintf( 'Citex AI: attempt %d/%d request took %.2fs', $attempt, self::MAX_GENERATION_ATTEMPTS, microtime( true ) - $request_start ) ); }
			// Every failure mode below is a genuine generation problem (network,
			// API, or an unusable response) — bounded-retry it instead of
			// aborting immediately, up to MAX_GENERATION_ATTEMPTS. Quality
			// differences (handled inside normalise()) never reach here as a
			// hard failure while QUALITY_GATE_ENABLED is false.
			if ( is_wp_error( $response ) ) { $last_error = sprintf( 'Gemini request failed: %s', $response->get_error_message() ); continue; }
			$code = (int) wp_remote_retrieve_response_code( $response ); $data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( $code < 200 || $code >= 300 ) { $message = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Gemini returned an unexpected error.', 'citex-tools' ); $last_error = sprintf( 'Gemini API error (%1$d): %2$s', $code, $message ); continue; }
			$text = self::output_text( is_array( $data ) ? $data : array() ); $decoded = json_decode( self::strip_fences( $text ), true );
			if ( '' === $text || JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) { $last_error = 'Gemini did not return valid structured question data.'; continue; }
			$questions = isset( $decoded['questions'] ) && is_array( $decoded['questions'] ) ? $decoded['questions'] : array();
			if ( count( $questions ) !== $quantity ) { $last_error = sprintf( 'The previous attempt returned %d questions instead of %d. Return exactly %d.', count( $questions ), $quantity, $quantity ); continue; }
			$normalise_start = $debug ? microtime( true ) : 0;
			$result = self::normalise( $questions, $ids, $difficulty, $exercises, $type, $category, $target_count, $scenario_id, $rule_tested, $exercise_design, $style, $group, $citation_form );
			if ( $debug ) { error_log( sprintf( 'Citex AI: attempt %d/%d normalise took %.2fs', $attempt, self::MAX_GENERATION_ATTEMPTS, microtime( true ) - $normalise_start ) ); }
			if ( is_wp_error( $result ) ) { $last_error = $result->get_error_message(); continue; }

			$duplicate = self::find_duplicate_reference( $result, $existing_references );
			if ( null !== $duplicate ) {
				$last_error = sprintf( 'A generated reference duplicates one already in the pending queue (or elsewhere in this batch): "%s". Choose a different real book/edited book.', $duplicate );
				continue;
			}

			if ( '' !== $scenario_id ) {
				Citex_Question_Diversity::record_batch( $category, array_column( $result, 'blueprint' ) );
			}
			if ( $debug ) { error_log( sprintf( 'Citex AI: batch of %d succeeded on attempt %d/%d, total %.2fs', $quantity, $attempt, self::MAX_GENERATION_ATTEMPTS, microtime( true ) - $batch_start ) ); }
			return $result;
		}

		return new WP_Error( 'citex_ai_generation_failed', sprintf( __( 'Gemini could not produce a usable batch after %d attempt(s). Nothing was added. Last issue: %s', 'citex-tools' ), self::MAX_GENERATION_ATTEMPTS, $last_error ) );
	}

	/**
	 * First reconstructedReference in $candidates that duplicates either an
	 * already-pending reference (same category, passed in as
	 * $existing_references) or an earlier candidate within this same batch
	 * — null when there is no duplicate at all.
	 *
	 * 'choose_treatment' and 'identify_error' candidates are skipped
	 * entirely: their `reconstructedReference` field never holds an actual
	 * bibliographic reference at all — for choose_treatment it is Citex's
	 * own FIXED, bucket-level rule statement (see
	 * normalise_choose_treatment_item()), identical by design for every
	 * question testing the same rule, and for identify_error it is
	 * Gemini's free-form errorReason text. Treating either as "the real
	 * book/edited book this question is about" caused a real reported bug:
	 * a second choose_treatment question for a bucket that already had one
	 * pending (or two in the same batch) always failed with a spurious
	 * "duplicates one already in the pending queue" error, since the
	 * correct rule statement is deliberately the same text every time.
	 *
	 * The same class of bug applies to 3 of Book's 16 'book_mcq_variant'
	 * templates — see Citex_Book_Mcq_Variants::book_independent_answer_variants()
	 * — whose `correctAnswer` (stored as `reconstructedReference`) is also a
	 * fixed, book-independent string; those are skipped here too.
	 *
	 * @param array    $candidates
	 * @param string[] $existing_references
	 * @return string|null
	 */
	private static function find_duplicate_reference( $candidates, array $existing_references ) {
		$seen_in_batch = array();
		foreach ( $candidates as $candidate ) {
			$mcq_pattern = $candidate['mcqPattern'] ?? '';
			if ( in_array( $mcq_pattern, array( 'choose_treatment', 'identify_error' ), true ) ) {
				continue;
			}
			if ( 'book_mcq_variant' === $mcq_pattern && in_array( $candidate['bookMcqVariant'] ?? '', Citex_Book_Mcq_Variants::book_independent_answer_variants(), true ) ) {
				continue;
			}
			if ( 'website_mcq_variant' === $mcq_pattern && in_array( $candidate['websiteMcqVariant'] ?? '', Citex_Website_Mcq_Variants::website_independent_answer_variants(), true ) ) {
				continue;
			}
			if ( 'mla_book_mcq_variant' === $mcq_pattern && in_array( $candidate['mlaBookMcqVariant'] ?? '', Citex_MLA_Book_Mcq_Variants::mla_book_independent_answer_variants(), true ) ) {
				continue;
			}
			if ( 'mla_edited_book_mcq_variant' === $mcq_pattern && in_array( $candidate['mlaEditedBookMcqVariant'] ?? '', Citex_MLA_Edited_Book_Mcq_Variants::mla_edited_book_independent_answer_variants(), true ) ) {
				continue;
			}
			if ( 'apa_book_mcq_variant' === $mcq_pattern && in_array( $candidate['apaBookMcqVariant'] ?? '', Citex_APA_Book_Mcq_Variants::apa_book_independent_answer_variants(), true ) ) {
				continue;
			}
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
	private static function build_prompt_for( $type, $category, $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction = '', $scenario_id = '', $exercise_design = 'full_reference', $style = 'harvard', $group = 'referencelist', $citation_form = '' ) {
		if ( 'intext' === $group ) {
			return self::build_prompt_intext( $category, $style, $citation_form, $type, $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction );
		}
		if ( 'mla' === $style ) {
			if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
				return 'MCQ' === $type
					? self::build_prompt_mla_edited_book_mcq( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction )
					: self::build_prompt_mla_edited_book_dragdrop( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction );
			}
			if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
				return 'MCQ' === $type
					? self::build_prompt_mla_journal_article_mcq( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction )
					: self::build_prompt_mla_journal_article_dragdrop( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction );
			}
			if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
				return 'MCQ' === $type
					? self::build_prompt_mla_website_mcq( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction )
					: self::build_prompt_mla_website_dragdrop( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction );
			}
			return 'MCQ' === $type
				? self::build_prompt_mla_book_mcq_variant( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction )
				: self::build_prompt_mla_book_dragdrop( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction );
		}
		if ( 'apa' === $style ) {
			// Phase 1: Book only — Citex_Generator itself restricts
			// $category to 'book' whenever style is 'apa', mirroring MLA's
			// own Phase 1.
			return 'MCQ' === $type
				? self::build_prompt_apa_book_mcq_variant( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction )
				: self::build_prompt_apa_book_dragdrop( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction );
		}
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
				? self::build_prompt_journal_article_mcq( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction, $exercise_design )
				: self::build_prompt_journal_article( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction, $exercise_design );
		}
		if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
			return 'MCQ' === $type
				? self::build_prompt_website_mcq_variant( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction )
				: self::build_prompt_website( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction );
		}
		return 'MCQ' === $type
			? self::build_prompt_book_mcq_variant( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction )
			: self::build_prompt_book_dragdrop( $ids, $difficulty, $verify, $quality_feedback, $scenario_instruction );
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
	private static function scenario_count_instruction( $category, $target_count, $scenario_id = '' ) {
		// Website has no author-COUNT dimension at all (see
		// Citex_Question_Scenarios::website_buckets()'s docblock) — its
		// scenario id instead encodes an author-TYPE/dated-ness constraint,
		// so it is routed to its own instruction builder rather than the
		// generic "exactly N authors" text below, which would be meaningless
		// for it.
		if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
			return self::website_scenario_instruction( $scenario_id );
		}
		if ( null === $target_count ) {
			return '';
		}
		$noun = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ? 'editor' : 'author';
		return sprintf(
			"AUTHOR/EDITOR COUNT FOR THIS BATCH — CRITICAL:\n- Every single question in this batch must use EXACTLY %1\$d %2\$s(s) — not more, not fewer. Invent (or choose) a book that genuinely has %1\$d %2\$s(s); do not pad or trim the %2\$s list to hit this number.",
			$target_count,
			$noun
		);
	}

	/**
	 * The author-type/dated-ness instruction Website's assigned scenario
	 * bucket (see Citex_Question_Scenarios::website_buckets()) translates
	 * into for Gemini, parsed directly from the bucket id string
	 * ("individual_author_dated", "organisation_author_undated", etc.) —
	 * the same "trust but verify" pattern as scenario_count_instruction()'s
	 * author-count text, just for a boolean pair of dimensions instead of a
	 * count. Empty when no scenario was assigned, in which case Gemini
	 * remains free to pick any real author type/date availability.
	 */
	private static function website_scenario_instruction( $scenario_id ) {
		$scenario_id = (string) $scenario_id;
		if ( '' === $scenario_id ) {
			return '';
		}
		$lines = array();
		if ( false !== strpos( $scenario_id, 'individual_author' ) ) {
			$lines[] = '- Every question in this batch must use a named INDIVIDUAL person as the author (authorType = "individual") — not an organisation.';
		} elseif ( false !== strpos( $scenario_id, 'organisation_author' ) ) {
			$lines[] = '- Every question in this batch must use an ORGANISATION as the author (authorType = "organisation") — not a named individual person.';
		}
		if ( false !== strpos( $scenario_id, '_dated' ) ) {
			$lines[] = '- Every question in this batch must use a webpage/document that HAS a clearly identifiable publication/creation year — do not use "n.d." for this batch.';
		} elseif ( false !== strpos( $scenario_id, 'undated' ) ) {
			$lines[] = '- Every question in this batch must use a webpage/document with NO identifiable publication/creation date, so the year field must be exactly "n.d." — do not invent a year for this batch.';
		}
		if ( empty( $lines ) ) {
			return '';
		}
		return "AUTHOR TYPE AND DATE FOR THIS BATCH — CRITICAL:\n" . implode( "\n", $lines );
	}

	private static function schema_for( $type, $category, $scenario_id = '', $style = 'harvard', $group = 'referencelist', $citation_form = '' ) {
		if ( 'intext' === $group ) {
			return self::schema_intext( $category, $style, $citation_form );
		}
		if ( 'mla' === $style ) {
			if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
				return 'MCQ' === $type ? self::schema_mla_edited_book_mcq() : self::schema_mla_edited_book_dragdrop();
			}
			if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
				return 'MCQ' === $type ? self::schema_mla_journal_article_mcq() : self::schema_mla_journal_article_dragdrop();
			}
			if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
				return 'MCQ' === $type ? self::schema_mla_website_mcq() : self::schema_mla_website_dragdrop();
			}
			return 'MCQ' === $type ? self::schema_mla_book_mcq_variant() : self::schema_mla_book_dragdrop();
		}
		if ( 'apa' === $style ) {
			// Phase 1: Book only.
			return 'MCQ' === $type ? self::schema_apa_book_mcq_variant() : self::schema_apa_book_dragdrop();
		}
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
		if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
			return 'MCQ' === $type ? self::schema_website_mcq_variant() : self::schema_website();
		}
		return 'MCQ' === $type ? self::schema_book_mcq_variant() : self::schema_book_dragdrop();
	}

	private static function system_instruction_for( $type, $category, $scenario_id = '', $style = 'harvard', $group = 'referencelist', $citation_form = '' ) {
		if ( 'intext' === $group ) {
			return self::system_instruction_intext( $category, $style, $citation_form, $type );
		}
		if ( 'mla' === $style ) {
			if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
				return self::system_instruction_mla_edited_book( $type );
			}
			if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
				return self::system_instruction_mla_journal_article( $type );
			}
			if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
				return self::system_instruction_mla_website( $type );
			}
			return self::system_instruction_mla_book( $type );
		}
		if ( 'apa' === $style ) {
			// Phase 1: Book only.
			return self::system_instruction_apa_book( $type );
		}
		if ( 'MCQ' === $type && 'identify_error' === $scenario_id ) {
			return self::system_instruction_identify_error( $category );
		}
		if ( 'MCQ' === $type && 0 === strpos( (string) $scenario_id, 'choose_treatment_' ) ) {
			return self::system_instruction_choose_treatment( $category );
		}
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
			return 'MCQ' === $type
				? 'You are Citex, an academic question-generation engine. Generate usable Harvard ReferenceList Edited Book multiple-choice questions for practice — invented-but-plausible sources are fine, as long as each question is internally consistent — editors, titles, years and places may be invented, but the publisher must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical bibliographic record with ONE OR MORE editors: editorFullNames (an array of one or two full names), year, bookTitle, place and publisher must all describe ONE single, internally consistent edited book — never a different edition or a different book. You are NOT asked for a scenario or question text at all; Citex supplies the entire student-facing question itself (a fixed "Which of the following is the correct Harvard reference for an edited book?" stem), so there is nothing for you to write and nothing for you to leak the answer through. Citex constructs the single correctly-formatted Harvard reference itself, including the correct editor designation ("(ed.)" for exactly one editor, "(eds)" for two) — you only ever provide THREE plausible but incorrectly-formatted `distractors`, each as {reference, errorReason} naming the SPECIFIC Harvard rule it breaks, never the correct one itself, and never one that swaps "(ed.)"/"(eds)" for the wrong editor count in a way that would make two options simultaneously look correct. Your goal is never "make four references that look different" — it is "one correct reference, three references each with one deliberate, identifiable Harvard error." For every distractor, re-read it end-to-end against the full correct format before returning it: a distractor that is wrong in your head but technically satisfies every Harvard rule when read literally must be rebuilt, since Citex independently re-validates every option and rejects the whole question if more than one is fully valid. Before returning each question, perform a strict self-check: editorFullNames, year, bookTitle, place and publisher all describe the same book with no contradictions; and all three distractors are clearly wrong (a formatting, punctuation, ordering, or wrong-designation mistake) with a specific errorReason each, mutually distinct from each other, and distinct from the correct reference you did not provide. Return only the requested JSON.'
				: 'You are Citex, an academic question-generation engine. Generate usable Harvard ReferenceList Edited Book DragDrop questions for practice — invented-but-plausible sources are fine, as long as each question is internally consistent — editors, titles, years and places may be invented, but the publisher must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical bibliographic record with ONE OR MORE editors: editorFullNames (an array of one or two full names), year, bookTitle, place and publisher must all describe ONE single, internally consistent edited book, and the scenario text must explicitly name that same title, every editor\'s full name, the same year, place and publisher — never a different edition or a different book. Citex derives each editor\'s surname and initials itself from editorFullNames, decides the correct editor designation ("(ed.)" for one editor, "(eds)" for two), and constructs Question Parts and Fixed Text itself — you never provide any of that, and your own questionParts/fixedText fields (if you include them) are never read as authoritative. CRITICAL — the scenario must state every editor\'s full name naturally and must NEVER show "(ed.)" or "(eds)" anywhere, must NEVER state, label, or abbreviate any editor\'s initials or surname separately, must NEVER show a completed or abbreviated Harvard citation, and must NEVER use the words "initial", "initials", or "surname". Before returning each question, perform a strict self-check: scenario, editorFullNames, year, bookTitle, place and publisher all describe the same book with no contradictions; the scenario reveals no answer value; and every confusing word is unique and different from every correct value. Return only the requested JSON.';
		}
		if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return self::system_instruction_journal_article( $type );
		}
		if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
			return self::system_instruction_website( $type );
		}
		return self::system_instruction_for_book( $type );
	}

	private static function system_instruction_for_book( $type ) {
		return 'MCQ' === $type
			? 'You are Citex, an academic question-generation engine. Generate usable Harvard ReferenceList Book bibliographic records for multiple-choice questions — invented-but-plausible sources are fine, as long as each question is internally consistent — authors, titles, years and places may be invented, but the publisher must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical bibliographic record: authorFullNames (an array of ONE OR MORE author full names, in the given author order), year, bookTitle, place and publisher must all describe ONE single, internally consistent book — never a different edition or a different book, and never a different number of authors than the real book actually has. You are NOT asked for a scenario, question text, options, or a correct answer of any kind; Citex builds the ENTIRE multiple-choice question itself — a stem, all 4 options, and the answer — deterministically from this canonical record alone, drawing on a fixed catalogue of Harvard book-formatting rules (author joining and ordering, publication year formatting, place/publisher ordering, overall reference structure, and more) that varies from question to question. There is nothing for you to write beyond the record itself, and nothing for you to leak an answer through. Before returning each record, perform a strict self-check: authorFullNames, year, bookTitle, place and publisher all describe the same book with no contradictions, and the real author count. Return only the requested JSON.'
			: 'You are Citex, an academic question-generation engine. Generate usable Harvard ReferenceList Book DragDrop questions for practice — invented-but-plausible sources are fine, as long as each question is internally consistent — authors, titles, years and places may be invented, but the publisher must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical bibliographic record: authorFullNames (an array of ONE OR MORE author full names, in the given author order), year, bookTitle, place and publisher must all describe ONE single, internally consistent book, and the scenario text must explicitly name that same title, EVERY author\'s full name, the same year, place and publisher — never a different edition, a different book, or a different number of authors than the real book actually has. Vary the place of publication GLOBALLY across the batch — never default to London (or any single city) for every question; spread it realistically across cities worldwide (UK and beyond) consistent with each real publisher\'s actual offices. You are NOT asked for questionParts, fixedText, or any distractor/confusingWords list at all; Citex builds the ENTIRE draggable question itself — deterministically, from this canonical record alone — deciding which 3 parts a student must drag into place (possibly including the joining word "and", not just whole bibliographic fields) and every wrong chip, covering a range of different Harvard book-formatting rules across the batch. There is nothing for you to write beyond the record and scenario, and nothing for you to leak an answer through. CRITICAL — the scenario must state every author\'s full real name naturally (for example "Alan Cole" or "Alan Cole and Jo Kaur") and must NEVER state, label, or abbreviate any author\'s initials or surname separately, must NEVER show a completed or abbreviated Harvard reference (never write anything like "Cole, A." or "Cole et al."), and must NEVER use the words "initial" or "surname" — the student must derive the initials and the Harvard format themselves from the full name(s) you provide. Before returning each question, perform a strict self-check: scenario, authorFullNames, year, bookTitle, place and publisher must all describe the same book with no contradictions; and the scenario must not reveal any answer value by labelling it as a surname, initial, year blank, title blank, or reference component. Return only the requested JSON.';
	}

	/**
	 * MLA counterpart to system_instruction_for_book() — Phase 1: Book
	 * only. The same "one canonical real record, Citex constructs the
	 * reference itself" framing, but for MLA's genuinely different Book
	 * rule (see Citex_MLA_Reference_Rules's own docblock): the FULL first
	 * name is used (never an initial), there is no place of publication at
	 * all, and three or more authors collapse to "et al." after the first
	 * (the opposite of Harvard's own "always list every author in full"
	 * rule) — Citex derives the surname and full given name itself from
	 * each authorFullNames entry, exactly as Harvard's own
	 * derive_author_parts() derives surname/initials.
	 */
	private static function system_instruction_mla_book( $type ) {
		return 'MCQ' === $type
			? 'You are Citex, an academic question-generation engine. Generate usable MLA Works-Cited Book bibliographic records for multiple-choice questions — invented-but-plausible sources are fine, as long as each question is internally consistent — authors, titles and years may be invented, but the publisher must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical bibliographic record: authorFullNames (an array of ONE OR MORE author full names, in the given author order), year, bookTitle and publisher must all describe ONE single, internally consistent book — never a different edition or a different book, and never a different number of authors than the real book actually has. There is no place of publication at all in MLA style — do not provide one. You are NOT asked for a scenario, question text, options, or a correct answer of any kind; Citex builds the ENTIRE multiple-choice question itself — a stem, all 4 options, and the answer — deterministically from this canonical record alone, drawing on a fixed catalogue of MLA book-formatting rules (the full first-name requirement, author joining and the "et al." rule for three or more authors, publisher/year ordering and punctuation, overall reference structure, and more) that varies from question to question. There is nothing for you to write beyond the record itself, and nothing for you to leak an answer through. Before returning each record, perform a strict self-check: authorFullNames, year, bookTitle and publisher all describe the same book with no contradictions, and the real author count. Return only the requested JSON.'
			: 'You are Citex, an academic question-generation engine. Generate usable MLA Works-Cited Book DragDrop questions for practice — invented-but-plausible sources are fine, as long as each question is internally consistent — authors, titles and years may be invented, but the publisher must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical bibliographic record: authorFullNames (an array of ONE OR MORE author full names, in the given author order), year, bookTitle and publisher must all describe ONE single, internally consistent book, and the scenario text must explicitly name that same title, EVERY author\'s full name, the same year, and the same publisher — never a different edition, a different book, or a different number of authors than the real book actually has. There is no place of publication at all in MLA style — do not provide one. You are NOT asked for questionParts, fixedText, or any distractor/confusingWords list at all; Citex builds the ENTIRE draggable question itself — deterministically, from this canonical record alone — deciding which 3 parts a student must drag into place (possibly including the joining word "and"/"et al.", not just whole bibliographic fields) and every wrong chip, covering a range of different MLA book-formatting rules across the batch. There is nothing for you to write beyond the record and scenario, and nothing for you to leak an answer through. CRITICAL — the scenario must state every author\'s full real name naturally (for example "Alan Cole" or "Alan Cole and Jo Kaur") and must NEVER state, label, or abbreviate any author\'s given name separately, must NEVER show a completed or abbreviated MLA reference (never write anything like "Cole, Alan." or "Cole et al."), and must NEVER use the word "surname" — the student must derive the MLA format themselves from the full name(s) you provide. Before returning each question, perform a strict self-check: scenario, authorFullNames, year, bookTitle and publisher must all describe the same book with no contradictions; and the scenario must not reveal any answer value. Return only the requested JSON.';
	}

	/**
	 * APA counterpart to system_instruction_for_book()/system_instruction_mla_book() —
	 * Phase 1: Book only. The same "one canonical real record, Citex
	 * constructs the reference itself" framing, but for APA's own rule
	 * (see Citex_APA_Reference_Rules's own docblock): initials, never a
	 * full given name (the SAME derivation Harvard already uses — Citex
	 * derives surname/initials itself from authorFullNames, exactly like
	 * Harvard's own derive_author_parts()); no place of publication at
	 * all; two or more authors always joined with "&" preceded by a comma,
	 * even at exactly two; every author always listed in full, "et al."
	 * never used; and — the one content-shape instruction unique to APA —
	 * the invented title must be SENTENCE CASE (only the first word/proper
	 * nouns capitalised), never Title Case.
	 */
	private static function system_instruction_apa_book( $type ) {
		return 'MCQ' === $type
			? 'You are Citex, an academic question-generation engine. Generate usable APA (7th edition) Reference List Book bibliographic records for multiple-choice questions — invented-but-plausible sources are fine, as long as each question is internally consistent — authors, titles and years may be invented, but the publisher must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical bibliographic record: authorFullNames (an array of ONE OR MORE author full names, in the given author order), year, bookTitle and publisher must all describe ONE single, internally consistent book — never a different edition or a different book, and never a different number of authors than the real book actually has. There is no place of publication at all in APA style — do not provide one. bookTitle MUST be written in SENTENCE CASE — only the first word (and any proper nouns) capitalised, e.g. "Life among the giants", never "Life Among The Giants". You are NOT asked for a scenario, question text, options, or a correct answer of any kind; Citex builds the ENTIRE multiple-choice question itself — a stem, all 4 options, and the answer — deterministically from this canonical record alone, drawing on a fixed catalogue of APA book-formatting rules (initials rather than a full first name, author joining with "&" for two or more, the full stop after the year\'s closing parenthesis, overall reference structure, and more) that varies from question to question. There is nothing for you to write beyond the record itself, and nothing for you to leak an answer through. Before returning each record, perform a strict self-check: authorFullNames, year, bookTitle and publisher all describe the same book with no contradictions, the real author count, and bookTitle is genuinely in sentence case. Return only the requested JSON.'
			: 'You are Citex, an academic question-generation engine. Generate usable APA (7th edition) Reference List Book DragDrop questions for practice — invented-but-plausible sources are fine, as long as each question is internally consistent — authors, titles and years may be invented, but the publisher must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical bibliographic record: authorFullNames (an array of ONE OR MORE author full names, in the given author order), year, bookTitle and publisher must all describe ONE single, internally consistent book, and the scenario text must explicitly name that same title, EVERY author\'s full name, the same year, and the same publisher — never a different edition, a different book, or a different number of authors than the real book actually has. There is no place of publication at all in APA style — do not provide one. bookTitle MUST be written in SENTENCE CASE — only the first word (and any proper nouns) capitalised, e.g. "Life among the giants", never "Life Among The Giants". You are NOT asked for questionParts, fixedText, or any distractor/confusingWords list at all; Citex builds the ENTIRE draggable question itself — deterministically, from this canonical record alone — deciding which 3 parts a student must drag into place (possibly including the joining symbol "&", not just whole bibliographic fields) and every wrong chip, covering a range of different APA book-formatting rules across the batch. There is nothing for you to write beyond the record and scenario, and nothing for you to leak an answer through. CRITICAL — the scenario must state every author\'s full real name naturally (for example "Alan Cole" or "Alan Cole and Jo Kaur") and must NEVER state, label, or abbreviate any author\'s initials or surname separately, must NEVER show a completed or abbreviated APA reference (never write anything like "Cole, A." or "Cole & Kaur, J."), and must NEVER use the words "initial" or "surname" — the student must derive the initials and the APA format themselves from the full name(s) you provide. Before returning each question, perform a strict self-check: scenario, authorFullNames, year, bookTitle and publisher must all describe the same book with no contradictions; bookTitle is genuinely in sentence case; and the scenario must not reveal any answer value. Return only the requested JSON.';
	}

	/**
	 * MLA counterpart to system_instruction_mla_book(), for Edited Book:
	 * editorFullNames replaces authorFullNames, and Citex derives each
	 * editor's surname/full given name itself plus the "editor"/"editors"
	 * designation and the "et al." rule for 3+ editors — see
	 * Citex_MLA_Reference_Rules::join_editors()'s own docblock.
	 */
	private static function system_instruction_mla_edited_book( $type ) {
		return 'MCQ' === $type
			? 'You are Citex, an academic question-generation engine. Generate usable MLA Works-Cited Edited Book bibliographic records for multiple-choice questions — invented-but-plausible sources are fine, as long as each question is internally consistent — editors, titles and years may be invented, but the publisher must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical bibliographic record: editorFullNames (an array of ONE OR MORE editor full names, in the given editor order), year, bookTitle and publisher must all describe ONE single, internally consistent edited book — never a different edition or a different book, and never a different number of editors than the real book actually has. There is no place of publication at all in MLA style — do not provide one. You are NOT asked for a scenario, question text, options, or a correct answer of any kind; Citex builds the ENTIRE multiple-choice question itself — a stem, all 4 options, and the answer — deterministically from this canonical record alone, deciding the "editor"/"editors" designation and the "et al." rule for 3+ editors itself. There is nothing for you to write beyond the record itself, and nothing for you to leak an answer through. Before returning each record, perform a strict self-check: editorFullNames, year, bookTitle and publisher all describe the same book with no contradictions, and the real editor count. Return only the requested JSON.'
			: 'You are Citex, an academic question-generation engine. Generate usable MLA Works-Cited Edited Book DragDrop questions for practice — invented-but-plausible sources are fine, as long as each question is internally consistent — editors, titles and years may be invented, but the publisher must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical bibliographic record: editorFullNames (an array of ONE OR MORE editor full names, in the given editor order), year, bookTitle and publisher must all describe ONE single, internally consistent edited book, and the scenario text must explicitly name that same title, EVERY editor\'s full name, the same year, and the same publisher. There is no place of publication at all in MLA style — do not provide one. You are NOT asked for questionParts, fixedText, or any distractor/confusingWords list at all; Citex builds the ENTIRE draggable question itself, deciding which parts a student must drag into place (including the "editor"/"editors" designation and the "and"/"et al." joining word) and every wrong chip. CRITICAL — the scenario must state every editor\'s full real name naturally and must NEVER state, label, or abbreviate any editor\'s given name separately, must NEVER show "editor"/"editors"/"(ed.)"/"(eds)", and must NEVER show a completed or abbreviated MLA reference. Before returning each question, perform a strict self-check: scenario, editorFullNames, year, bookTitle and publisher must all describe the same book with no contradictions; and the scenario must not reveal any answer value. Return only the requested JSON.';
	}

	/**
	 * MLA counterpart to system_instruction_mla_book(), for Journal
	 * Article: articleTitle/journalTitle/volume/issue/pages replace
	 * bookTitle/publisher — there is no place/publisher concept for a
	 * journal article.
	 */
	private static function system_instruction_mla_journal_article( $type ) {
		return 'MCQ' === $type
			? 'You are Citex, an academic question-generation engine. Generate usable MLA Works-Cited Journal Article multiple-choice questions for practice — invented-but-plausible sources are fine, as long as each question is internally consistent — articles, authors, years, volumes, issues and page ranges may be invented, but the journal name must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical, real, published journal article: authorFullNames (an array of ONE OR MORE author full names, in the article\'s actual author order), year, articleTitle, journalTitle, volume, issue and pages must all describe ONE single, internally consistent article. You are NOT asked for a scenario, question text, options, or a correct answer of any kind; Citex builds the ENTIRE multiple-choice question itself — a stem, all 4 options, and the answer — deterministically from this canonical record alone, applying MLA\'s own rules (double quotation marks around the article title with the period inside them, "vol."/"no." labels, the year with no parentheses at all, and the "et al." rule for 3+ authors). There is nothing for you to write beyond the record itself, and nothing for you to leak an answer through. Before returning each record, perform a strict self-check: authorFullNames, year, articleTitle, journalTitle, volume, issue and pages all describe the same article with no contradictions, and the real author count. Return only the requested JSON.'
			: 'You are Citex, an academic question-generation engine. Generate usable MLA Works-Cited Journal Article DragDrop questions for practice — invented-but-plausible sources are fine, as long as each question is internally consistent — articles, authors, years, volumes, issues and page ranges may be invented, but the journal name must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical, real, published journal article: authorFullNames (an array of ONE OR MORE author full names, in the article\'s actual author order), year, articleTitle, journalTitle, volume, issue and pages must all describe ONE single, internally consistent article, and the scenario text must explicitly name that same article title, journal title, EVERY author\'s full name, the same year, volume, issue and page range. You are NOT asked for questionParts, fixedText, or any distractor/confusingWords list at all; Citex builds the ENTIRE draggable question itself, deterministically, applying MLA\'s own article-title-quotation and "vol."/"no." rules. CRITICAL — the scenario must state every author\'s full real name naturally and must NEVER show a completed or abbreviated MLA reference, and must NEVER say "et al.". Before returning each question, perform a strict self-check: scenario, authorFullNames, year, articleTitle, journalTitle, volume, issue and pages must all describe the same article with no contradictions; and the scenario must not reveal any answer value. Return only the requested JSON.';
	}

	/**
	 * MLA counterpart to system_instruction_mla_book(), for Website: a
	 * single author-or-organisation, and NO "n.d." convention at all —
	 * year is OPTIONAL, left completely empty when no date can be
	 * identified, never a placeholder string. Accessed date is never asked
	 * of Gemini at all — Citex supplies it itself, deterministically, from
	 * the actual generation date. There is deliberately no `publisher`
	 * field requested at all — see
	 * Citex_AI_V2::normalise_mla_website_dispatch()'s own docblock.
	 */
	private static function system_instruction_mla_website( $type ) {
		return 'MCQ' === $type
			? 'You are Citex, an academic question-generation engine. Generate usable MLA Works-Cited Website bibliographic records for multiple-choice questions — invented-but-plausible sources are fine, as long as each question is internally consistent. Every question must describe exactly ONE canonical, real, currently-accessible webpage: authorType ("individual" or "organisation") plus either authorFullName or organisationName, title and url must all describe the same internally-consistent source; year is OPTIONAL — a real 4-digit year when one can genuinely be identified, otherwise left completely empty (never "n.d." or any placeholder text — real MLA style has no such convention at all). You are NOT asked for a scenario, question text, options, an accessed date, or a correct answer of any kind; Citex builds the ENTIRE multiple-choice question itself and computes the accessed date itself. There is nothing for you to write beyond the record itself, and nothing for you to leak an answer through. Before returning each record, perform a strict self-check: authorType/author-or-organisation-name, title and url all describe the same real, currently-accessible source with no contradictions, and year is either a real 4-digit year or completely empty. Return only the requested JSON.'
			: 'You are Citex, an academic question-generation engine. Generate usable MLA Works-Cited Website DragDrop questions for practice — invented-but-plausible sources are fine, as long as each question is internally consistent. Every question must describe exactly ONE canonical, real, currently-accessible webpage: authorType ("individual" or "organisation") plus either authorFullName or organisationName, title and url must all describe the same internally-consistent source; year is OPTIONAL — a real 4-digit year when identifiable, otherwise left completely empty (never "n.d." or any placeholder text). The scenario text must explicitly name the same title, the author\'s full name or the organisation\'s name, the url, and (when provided) the same year. Citex computes the accessed date itself (never ask for or invent one) and constructs the ENTIRE draggable question itself. CRITICAL — the scenario must NEVER show a completed or abbreviated MLA reference and must NEVER use the words "n.d.", "no date", or "undated" even when no year is provided. Before returning each question, perform a strict self-check: scenario, authorType/author-or-organisation-name, title and url must all describe the same internally-consistent source with no contradictions; and the scenario must not reveal any answer value. Return only the requested JSON.';
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
			? 'You are Citex, an academic question-generation engine. Generate usable Harvard ReferenceList Journal Article multiple-choice questions for practice — invented-but-plausible sources are fine, as long as each question is internally consistent — articles, authors, years, volumes, issues and page ranges may be invented, but the journal name must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical, real, published journal article: authorFullNames (an array of ONE OR MORE author full names, in the article\'s actual author order), year, articleTitle, journalTitle, volume, issue and pages must all describe ONE single, internally consistent article — never a different issue or a different article, and never a different number of authors than the real article actually has. You are NOT asked for a scenario or question text at all; Citex supplies the entire student-facing question itself (a fixed "Which of the following is the correct Harvard reference for a journal article?" stem), so there is nothing for you to write and nothing for you to leak the answer through. Citex constructs the single correctly-formatted Harvard reference itself from authorFullNames/year/articleTitle/journalTitle/volume/issue/pages — including how multiple authors are joined (Harvard\'s reference-list rule: EVERY author is always listed in full, comma-separated with a final "and" before the last one, for any author count — "et al." is NEVER used in a reference-list entry) — you only ever provide THREE plausible but incorrectly-formatted `distractors`, each as {reference, errorReason} naming the SPECIFIC Harvard rule it breaks, never the correct one itself. Your goal is never "make four references that look different" — it is "one correct reference, three references each with one deliberate, identifiable Harvard error." For every distractor, re-read it end-to-end against the full correct format before returning it: a distractor that is wrong in your head but technically satisfies every Harvard rule when read literally must be rebuilt, since Citex independently re-validates every option and rejects the whole question if more than one is fully valid. Before returning each question, perform a strict self-check: authorFullNames, year, articleTitle, journalTitle, volume, issue and pages all describe the same article with no contradictions; and all three distractors are clearly wrong (a formatting, punctuation, ordering, or author-joining mistake) with a specific errorReason each, mutually distinct from each other, and distinct from the correct reference you did not provide. Return only the requested JSON.'
			: 'You are Citex, an academic question-generation engine. Generate usable Harvard ReferenceList Journal Article DragDrop questions for practice — invented-but-plausible sources are fine, as long as each question is internally consistent — articles, authors, years, volumes, issues and page ranges may be invented, but the journal name must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical, real, published journal article: authorFullNames (an array of ONE OR MORE author full names, in the article\'s actual author order), year, articleTitle, journalTitle, volume, issue and pages must all describe ONE single, internally consistent article, and the scenario text must explicitly name that same article title, journal title, EVERY author\'s full name, the same year, volume, issue and page range — never a different issue, a different article, or a different number of authors than the real article actually has. Citex derives each author\'s surname and initials itself from authorFullNames — you never provide them separately — and constructs Question Parts and Fixed Text itself: the complete reference (author(s), year, article title, journal title, volume, issue, pages) is always shown in full, with exactly 3 of these 7 fields drawn as draggable parts each time, chosen at random per question — the rest remain fixed, correctly-formatted literal text alongside them. A drawn author (whichever one) is always ONE combined part, never split into separate surname/initials parts. Your questionParts and fixedText values are for your own self-check only and are not read as authoritative. CRITICAL — the scenario must state every author\'s full real name naturally and must NEVER state, label, or abbreviate any author\'s initials or surname separately, must NEVER show a completed or abbreviated Harvard reference, must NEVER say "et al." or state the answer\'s punctuation or ordering, and must NEVER use the words "initial" or "surname" — the student must derive the initials and the Harvard format themselves from the full name(s) you provide. Before returning each question, perform a strict self-check: scenario, authorFullNames, year, articleTitle, journalTitle, volume, issue and pages must all describe the same article with no contradictions; the scenario must not reveal any answer value; and every confusing word must be unique and different from every correct Question Part. Return only the requested JSON.';
	}

	/**
	 * Website counterpart to system_instruction_for_book()/system_instruction_journal_article():
	 * the same "one canonical real record, Citex constructs the reference
	 * itself" framing, but for a genuinely different Harvard rule —
	 * there is only ever ONE author-or-organisation (no multi-person joining
	 * concept at all), a year OR the literal "n.d." (never a guessed year),
	 * and no publisher element in the reference itself at all: publisher is
	 * still requested (for source-realism verification only) and stored,
	 * but never appears in the built reference or as a draggable field — see
	 * Citex_Reference_Rules::build_website_reference()'s "Author/Organisation
	 * (Year|n.d.) Title. Available at: URL (Accessed: Day Month Year)."
	 * format. Accessed date is never asked of Gemini at all: Citex supplies
	 * it itself, deterministically, from the actual generation date (see
	 * Citex_AI_V2::current_accessed_date()), so there is no "invented access
	 * date" failure mode to guard against here the way there is for every
	 * other field.
	 */
	private static function system_instruction_website( $type ) {
		return 'MCQ' === $type
			? 'You are Citex, an academic question-generation engine. Generate usable Harvard ReferenceList Website/Web Resource bibliographic records for multiple-choice questions — invented-but-plausible sources are fine, as long as each question is internally consistent — the webpage, document, author/organisation name, year and URL may be invented, but the publisher must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical, real, currently-accessible webpage or downloadable document (e.g. a PDF): authorType ("individual" or "organisation") plus either authorFullName or organisationName, year (a real 4-digit year, or exactly "n.d." if — and only if — no publication/creation date can be identified for the real source; never guess a year, and never use "n.d." for a source that does have an identifiable date), title, publisher and url must all describe the same internally-consistent source. You are NOT asked for a scenario, question text, options, a correct answer, or an accessed date at all; Citex builds the ENTIRE multiple-choice question itself — a stem, all 4 options, and the answer — deterministically from this canonical record alone, drawing on a fixed catalogue of Harvard website-formatting rules (year/date formatting, "Available at:" placement, URL formatting, accessed-date formatting, author-or-organisation-name formatting, overall reference structure, and more) that varies from question to question, and computes the accessed date itself. Note the reference itself never shows the publisher at all — it is used only to verify the source is real. There is nothing for you to write beyond the record itself, and nothing for you to leak an answer through. Before returning each record, perform a strict self-check: authorType/authorFullName-or-organisationName, year, title, publisher and url all describe the same real, currently-accessible source with no contradictions. Return only the requested JSON.'
			: 'You are Citex, an academic question-generation engine. Generate usable Harvard ReferenceList Website/Web Resource DragDrop questions for practice — invented-but-plausible sources are fine, as long as each question is internally consistent — the webpage, document, author/organisation name, year and URL may be invented, but the publisher must always be real (verify it when web verification is enabled). Every question must describe exactly ONE canonical, real, currently-accessible webpage or downloadable document: authorType ("individual" or "organisation") plus either authorFullName or organisationName, year (a real 4-digit year, or exactly "n.d." if — and only if — no publication/creation date can be identified; never guess a year, and never use "n.d." for a source that does have an identifiable date), title, publisher and url must all describe the same internally-consistent source, and the scenario text must explicitly name that same title, the author\'s full name or the organisation\'s name, the year (or state plainly that no date is available, without using the words "n.d.", "no date", or "undated"), the publisher and the url — never a different page or a different source. Citex derives an individual author\'s surname and initials itself from authorFullName — you never provide them separately — computes the accessed date itself (never ask for or invent one), and constructs Question Parts and Fixed Text itself: the complete reference (author-or-organisation, year-or-"n.d.", title, url, accessed date — the publisher never appears in the reference at all, only in the scenario, and is otherwise used solely to verify the source is real) is always shown in full, with exactly 3 of the 4 eligible fields (author-or-organisation, year-or-"n.d.", title, accessed date) drawn as draggable parts each time, chosen at random per question — the rest remain fixed, correctly-formatted literal text alongside them, together with "Available at:", which is never draggable. The url is NEVER one of the draggable parts either — it needs no Harvard-format transformation, so it always stays as plain fixed text. Your questionParts and fixedText values are for your own self-check only and are not read as authoritative. CRITICAL — the scenario must state the author\'s full real name OR the organisation\'s real name naturally, the real title, publisher and url, and must NEVER state, label, or abbreviate the author\'s initials or surname separately, must NEVER show a completed or abbreviated Harvard reference, must NEVER use the words "initial" or "surname", and must NEVER use the words "n.d.", "no date", or "undated" even when the source genuinely has no identifiable date — the student must recognise the absence of a date themselves and derive "(n.d.)". Before returning each question, perform a strict self-check: scenario, authorType/author-or-organisation-name, year, title, publisher and url all describe the same internally-consistent source with no contradictions; the scenario must not reveal any answer value or state "(n.d.)"/"no date"/"undated" directly; and every confusing word must be unique and different from every correct Question Part. Return only the requested JSON.';
	}

	/**
	 * Advisory guidance steering Gemini's publisher/journal SELECTION
	 * toward naturally concise real names when multiple equally valid real
	 * options exist, so generated Question Parts, MCQ options, and scenario
	 * text are more compact on a mobile screen at the source. The hard
	 * word/character caps (see Citex_Reference_Rules::part_suitability())
	 * are the real backstop; this is only a preference for picking well
	 * among the always-real publisher/journal options. Appended to every
	 * prompt builder that carries bibliographic data (every one except
	 * build_prompt_choose_treatment(), which has none at all — see its own
	 * docblock).
	 */
	private static function conciseness_guidance() {
		return "MOBILE READABILITY — PREFER CONCISE REAL PUBLISHER/JOURNAL NAMES WHEN POSSIBLE:\n"
			. "- When several equally valid, equally well-known real publishers/journals could be used, prefer the one with a naturally shorter, more concise real name — e.g. \"Routledge\", \"SAGE\", \"Wiley\", \"Polity\", \"Nature\", \"The Lancet\" — over an unusually long full imprint or journal name. NEVER abbreviate, shorten, truncate, or otherwise alter the real publisher/journal name itself — always use its complete, accurate real name exactly as it is properly known; this is only a preference for WHICH real publisher/journal to pick, never a reason to alter one.\n"
			. "- For every other field (author name, book/article/webpage title, organisation name) — see the invented-content guidance below — invent something naturally short and concise directly, rather than picking a long value and shortening it.";
	}

	/**
	 * Writing-style guidance for every piece of free-form prose Gemini
	 * authors (scenarios, wrongStatements, distractor reference text,
	 * errorReason). Em dashes are a well-known AI-writing tell that makes
	 * generated text read as obviously machine-authored to students —
	 * Citex's own authored text (question stems, hints, treatment
	 * statements) never uses one, and Gemini's output must match that same
	 * plain house style. Appended to every prompt builder that asks Gemini
	 * for any free-form text, exactly like conciseness_guidance().
	 */
	private static function plain_style_guidance() {
		return "WRITING STYLE — NO EM DASHES:\n"
			. "- Never use an em dash (—) anywhere in your text. Use a comma, colon, or separate sentence instead. An em dash reads as an obvious AI-writing tell to students and must not appear in any scenario, statement, reference text, or reason you write.";
	}

	/**
	 * Batch-level place/publisher variety guidance — appended to every
	 * prompt builder that carries a real place-of-publication AND
	 * publisher (Book, Edited Book, and their identify_error variant).
	 * This alone is not the enforcement mechanism: normalise() runs a
	 * hard, code-level batch-diversity check on the actual returned data
	 * (see its own docblock) and rejects/regenerates the whole batch when
	 * it fails, precisely because a soft "please vary it" request on its
	 * own was not reliably followed. This guidance exists to make Gemini
	 * more likely to pass that check on the FIRST attempt, not to replace it.
	 */
	private static function place_publisher_diversity_guidance() {
		return "PLACE AND PUBLISHER — VARY GLOBALLY ACROSS THE BATCH, CRITICAL:\n"
			. "- Do NOT default to London (or any single city) for place, and do NOT settle on one favourite publisher — spread both realistically across a genuinely global range of real cities and real, well-known publishers. Places: London, Oxford, Cambridge, Manchester, Edinburgh, Dublin, New York, Boston, Chicago, Toronto, Sydney, Melbourne, Singapore, Delhi, Mumbai, Tokyo, Paris, Berlin, Amsterdam, Cape Town. Publishers: Routledge, Pearson, SAGE, Palgrave Macmillan, Oxford University Press, Cambridge University Press, Wiley, Springer, Elsevier, Taylor & Francis, Bloomsbury, Harvard University Press, Yale University Press.\n"
			. "- Never repeat the exact same (place, publisher) combination on more than one question in this batch, and never let a single place or a single publisher dominate the batch.\n"
			. "- Both must still be genuine: the place must be one that publisher plausibly operates from, and the publisher must always be real.";
	}

	/**
	 * Publisher-only counterpart to place_publisher_diversity_guidance(),
	 * for Website (which has no place-of-publication concept at all —
	 * only a publisher).
	 */
	private static function publisher_diversity_guidance() {
		return "PUBLISHER — VARY GLOBALLY ACROSS THE BATCH, CRITICAL:\n"
			. "- Do NOT settle on one favourite publisher — spread it across a genuinely wide range of real, well-known publishers (e.g. Routledge, Pearson, SAGE, Palgrave Macmillan, Oxford University Press, Cambridge University Press, Wiley, Springer, Elsevier, Taylor & Francis, Bloomsbury, Harvard University Press, Yale University Press) rather than repeating the same one across the batch.\n"
			. "- The publisher must always be real.";
	}

	/**
	 * Content realism guidance. Author names, book/article/webpage titles,
	 * and organisation names no longer need to correspond to a real,
	 * findable source: this tool is for learning Harvard-referencing
	 * MECHANICS (formatting, punctuation, ordering), not bibliographic
	 * research, so an invented-but-plausible source is fine. The one
	 * deliberate exception is the publisher (Book/Edited Book/Website) or
	 * journal name (Journal Article) — each category's own prompt still
	 * separately states which field this is — which must always be a REAL,
	 * currently-existing, well-known publisher/journal, so students learn
	 * to recognise real ones even when the rest of the source is invented.
	 * Author-name and title word limits are admin-configurable (AI
	 * Settings — see Citex_AI_V2::max_author_words()/max_title_words()),
	 * since content is invented and so is no longer naturally bounded by
	 * what a real source happens to be called. Appended to every prompt
	 * builder that carries bibliographic data, exactly like
	 * conciseness_guidance().
	 */
	private static function content_realism_guidance() {
		$max_author_words = self::max_author_words();
		$max_title_words  = self::max_title_words();
		return "INVENTED CONTENT IS FINE — THIS IS FOR LEARNING PURPOSES:\n"
			. "- Author names, the book/article/webpage title, and organisation names do NOT need to be real or belong to a genuinely published/existing source — you may invent them, as long as the whole record is internally consistent (the same invented author/title/year/etc. throughout one question). This tool teaches Harvard formatting mechanics, not bibliographic research.\n"
			. "- Invented author names must read as ordinary, plausible personal names — NEVER reuse the name of a real, identifiable, notable person (e.g. a well-known author, academic, or public figure) as an invented author, so no question ever misattributes invented work to someone real.\n"
			. "- The one exception: the publisher (or journal name — see this category's own instructions above for which field that is) must always be a REAL, currently-existing, well-known academic or trade publisher/journal, even though the rest of the record may be invented. Never invent a publisher/journal name.\n"
			. sprintf( "- Invent each author's full name as EXACTLY %d word(s) — not more, not fewer (e.g. a single given name and surname for 2 words).\n", $max_author_words )
			. "- The SURNAME specifically (the family/last name — the part actually shown in the Harvard reference, e.g. \"Ross\" in \"Ross, A.\") must be NO MORE THAN 5 CHARACTERS long — invent a short surname directly (e.g. \"Ross\", \"Dale\", \"Cole\", \"Vance\", \"Ng\", \"Kaur\") rather than a longer one you then shorten. The given name(s) may be any normal length; only the surname itself is capped.\n"
			. sprintf( "- Invent the book/article/webpage title as NO MORE THAN %d word(s), and short enough to sit comfortably on a mobile screen — invent a short title directly rather than a long one you then shorten.\n", $max_title_words )
			. "- Keep every other field concise too, short enough to sit comfortably on a mobile screen.";
	}

	/**
	 * Book DragDrop prompt — like build_prompt_book_mcq_variant(), this asks
	 * Gemini for NOTHING beyond the canonical book record and a non-leaking
	 * scenario: no questionParts, no fixedText, no confusingWords.
	 * Citex_Book_Dragdrop_Parts::select_parts()/build() construct the entire
	 * question (which 3 parts are drawn, Fixed Text, and every wrong
	 * chip) deterministically from that record alone — replaces the
	 * original fixed 8-design catalogue and its Gemini-authored
	 * confusingWords list.
	 */
	private static function build_prompt_book_dragdrop( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct Harvard / ReferenceList / Book / DragDrop questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the publisher is real.' : 'Invent a plausible, internally consistent record if needed — the publisher must still be real.' ) . "\n\nONE QUESTION = ONE CANONICAL BIBLIOGRAPHIC RECORD — CRITICAL:\n- authorFullNames, year, bookTitle, place and publisher must all describe ONE single, internally consistent book. Do not mix facts from a different edition, a different book by the same author(s), or a similarly-named book.\n- authorFullNames is an array of ONE OR MORE author full names (given name(s) + surname each), e.g. [\"Alan Cole\"] or [\"John Smith\", \"Amy Jones\"], in the book's real, actual author order. Keep the author count consistent throughout the question. Do NOT provide a surname or initials separately for any author — Citex derives both itself from each full name.\n- The scenario MUST explicitly state that same bookTitle, EVERY author's full name, the same year, the same place and the same publisher. Citex independently checks the scenario text against these fields and rejects the question if any of them is not named in the scenario.\n\nMULTIPLE AUTHORS — LIVERPOOL HOPE'S REFERENCE-LIST RULE:\n- For the reference list (which is the only thing this question generates), EVERY author is always listed in full — 2 authors are joined with \"and\"; 3 or more are comma-separated with \"and\" before the final author; this never changes at 4 or more authors.\n- \"et al.\" must NEVER appear in the reference-list entry, for any author count. (\"et al.\" is Harvard's separate IN-TEXT-CITATION convention for 4+ authors — this question never generates an in-text citation, only a reference-list entry, so that abbreviation does not belong here at all.)\n- Citex constructs the joined author list itself from authorFullNames — you never write the joined form yourself.\n\nSCENARIOS — ANSWER LEAKAGE IS A CRITICAL FAILURE:\n- Keep each scenario short and mobile-friendly, preferably under 220 characters.\n- Use natural wording such as 'You are creating a reference for a book titled...' or 'You are referencing a book titled...'.\n- State the book title, EVERY author's FULL NAME, publication year, publisher and publication place.\n- Prefer concise real book titles; never truncate or alter the actual bibliographic title.\n- The scenario MUST NOT state, label, or abbreviate any author's initials or surname separately, MUST NOT use the words \"initial\" or \"initials\" or \"surname\" anywhere, and MUST NOT show any completed or abbreviated Harvard reference (e.g. never write \"Cole, A.\", \"Cole, A. (2012)\", or \"Cole et al.\").\n- GOOD (one author): \"You are referencing the book titled Social Research Methods by Alan Cole, published in 2012 by Oxford University Press in Oxford.\"\n- GOOD (two authors): \"You are referencing a book titled Understanding digital culture by Vincent Dale and Jo Kaur, published in 2020 by SAGE Publications in London.\"\n- BAD: \"...by Alan Cole (initials A.), published in 2012...\" — reveals the initials directly.\n- BAD: \"...by Cole, A., published in 2012...\" — states the abbreviated citation form directly.\n- BAD: \"...by Smith et al., published in 2020...\" — states the in-text-citation abbreviation directly, and is also not how the reference-list entry is written.\n- BAD: \"The author's surname is Cole and his initials are A.\" — explicitly labels both answers.\n- A full author name naturally containing the surname (e.g. \"Alan Cole\") is correct and required — the failure is explicitly labelling or abbreviating an answer value, not the surname appearing as part of the full name.\n- The student must transform the full bibliographic information you give into the Harvard reference themselves; do not do that transformation for them anywhere in the scenario.\n\nYou are NOT asked for questionParts, fixedText, or any distractor/confusingWords list — Citex builds the whole draggable question itself (which 3 parts are drawn from the record — possibly including the joining word \"and\" — Fixed Text, and every wrong chip), deterministically, after you respond. There is nothing for you to write beyond the record and scenario, and nothing for you to leak an answer through.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. scenario, authorFullNames, year, bookTitle, place and publisher all describe the exact same book — no contradictions, and the real author count.\n2. The scenario states every author's full name naturally and never the words \"initial\"/\"initials\"/\"surname\", and never a completed, abbreviated, or \"et al.\" reference.\n3. place and publisher are each a genuinely global/varied choice for this batch, not a repeat of a prior question's place or publisher.\n4. Only return questions that pass all three checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance() . "\n\n" . self::place_publisher_diversity_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * MLA Book DragDrop prompt — mirrors build_prompt_book_dragdrop()'s
	 * "Gemini supplies only the canonical record and a non-leaking
	 * scenario" structure, but for MLA's own rule: no place of
	 * publication at all, and authorFullNames' given names are kept in
	 * FULL by Citex (never reduced to initials — see
	 * Citex_AI_V2::derive_mla_author_parts()).
	 */
	private static function build_prompt_mla_book_dragdrop( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct MLA / Works Cited / Book / DragDrop questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the publisher is real.' : 'Invent a plausible, internally consistent record if needed — the publisher must still be real.' ) . "\n\nONE QUESTION = ONE CANONICAL BIBLIOGRAPHIC RECORD — CRITICAL:\n- authorFullNames, year, bookTitle and publisher must all describe ONE single, internally consistent book. Do not mix facts from a different edition, a different book by the same author(s), or a similarly-named book.\n- authorFullNames is an array of ONE OR MORE author full names (given name(s) + surname each), e.g. [\"Alan Cole\"] or [\"John Smith\", \"Amy Jones\"], in the book's real, actual author order. Keep the author count consistent throughout the question. Do NOT provide a surname separately for any author — Citex derives it itself from each full name, keeping the given name in full (MLA never abbreviates a first name to an initial).\n- There is no place of publication at all in MLA style — do NOT provide one.\n- The scenario MUST explicitly state that same bookTitle, EVERY author's full name, the same year and the same publisher. Citex independently checks the scenario text against these fields and rejects the question if any of them is not named in the scenario.\n\nMULTIPLE AUTHORS — MLA'S WORKS-CITED RULE (the opposite of Harvard's):\n- For the reference list (which is the only thing this question generates), only the FIRST author's name is ever inverted; a second author (exactly 2) keeps their natural word order, joined by \"and\". For 3 or more authors, every author AFTER the first is dropped from the reference entirely, replaced by \"et al.\" — Citex constructs this itself; you never write the joined form yourself, and you must still provide every real author's full name in authorFullNames regardless of how many will actually appear in the constructed reference.\n\nSCENARIOS — ANSWER LEAKAGE IS A CRITICAL FAILURE:\n- Keep each scenario short and mobile-friendly, preferably under 220 characters.\n- Use natural wording such as 'You are creating a reference for a book titled...' or 'You are referencing a book titled...'.\n- State the book title, EVERY author's FULL NAME, publication year and publisher.\n- Prefer concise real book titles; never truncate or alter the actual bibliographic title.\n- The scenario MUST NOT state, label, or abbreviate any author's given name separately, MUST NOT use the word \"surname\" anywhere, and MUST NOT show any completed or abbreviated MLA reference (e.g. never write \"Cole, Alan.\", \"Cole, Alan. The Great Adventure.\", or \"Cole et al.\").\n- GOOD (one author): \"You are referencing the book titled Social Research Methods by Alan Cole, published in 2012 by Oxford University Press.\"\n- GOOD (two authors): \"You are referencing a book titled Understanding digital culture by Vincent Dale and Jo Kaur, published in 2020 by SAGE Publications.\"\n- BAD: \"...by Cole, Alan., published in 2012...\" — states the abbreviated citation form directly.\n- BAD: \"...by Smith et al., published in 2020...\" — states the answer's own abbreviation directly.\n- The student must transform the full bibliographic information you give into the MLA reference themselves; do not do that transformation for them anywhere in the scenario.\n\nYou are NOT asked for questionParts, fixedText, or any distractor/confusingWords list — Citex builds the whole draggable question itself (which 3 parts are drawn from the record — possibly including \"and\"/\"et al.\" — Fixed Text, and every wrong chip), deterministically, after you respond. There is nothing for you to write beyond the record and scenario, and nothing for you to leak an answer through.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. scenario, authorFullNames, year, bookTitle and publisher all describe the exact same book — no contradictions, and the real author count.\n2. The scenario states every author's full name naturally and never the word \"surname\", and never a completed or abbreviated reference.\n3. publisher is a genuinely varied real choice for this batch, not a repeat of a prior question's publisher.\n4. Only return questions that pass all three checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance() . "\n\n" . self::publisher_diversity_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * MLA Book MCQ prompt — mirrors build_prompt_book_mcq_variant()
	 * exactly: Gemini supplies ONLY the canonical record, and
	 * Citex_MLA_Book_Mcq_Variants builds the entire question.
	 */
	private static function build_prompt_mla_book_mcq_variant( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct MLA / Works Cited / Book bibliographic records for multiple-choice questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the publisher is real.' : 'Invent a plausible, internally consistent record if needed — the publisher must still be real.' ) . "\n\nONE QUESTION = ONE CANONICAL BIBLIOGRAPHIC RECORD — CRITICAL:\n- authorFullNames, year, bookTitle and publisher must all describe ONE single, internally consistent book. Do not mix facts from a different edition, a different book by the same author(s), or a similarly-named book.\n- authorFullNames is an array of ONE OR MORE author full names (given name(s) + surname each), e.g. [\"Alan Cole\"] or [\"John Smith\", \"Amy Jones\"], in the book's real, actual author order. Use the book's true author count. Do NOT provide a surname separately for any author — Citex derives it itself from each full name, keeping the given name in full.\n- There is no place of publication at all in MLA style — do NOT provide one.\n- You are NOT asked for a scenario, question text, options, or a correct answer of any kind — Citex builds the ENTIRE multiple-choice question itself (the stem and all 4 options) from this canonical record alone, covering a range of different MLA book-formatting rules across the batch. There is nothing for you to write beyond the record itself, and nothing for you to leak an answer through.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. authorFullNames, year, bookTitle and publisher all describe the exact same book — no contradictions, and the real author count.\n2. Only return records that pass this check.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance() . "\n\n" . self::publisher_diversity_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * APA Book DragDrop prompt — mirrors build_prompt_mla_book_dragdrop()'s
	 * "Gemini supplies only the canonical record and a non-leaking
	 * scenario" structure, but for APA's own rule: initials (never a full
	 * given name), no place of publication, "&" preceded by a comma even
	 * at exactly two authors, every author always listed in full, and a
	 * sentence-case bookTitle.
	 */
	private static function build_prompt_apa_book_dragdrop( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct APA / ReferenceList / Book / DragDrop questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the publisher is real.' : 'Invent a plausible, internally consistent record if needed — the publisher must still be real.' ) . "\n\nONE QUESTION = ONE CANONICAL BIBLIOGRAPHIC RECORD — CRITICAL:\n- authorFullNames, year, bookTitle and publisher must all describe ONE single, internally consistent book. Do not mix facts from a different edition, a different book by the same author(s), or a similarly-named book.\n- authorFullNames is an array of ONE OR MORE author full names (given name(s) + surname each), e.g. [\"Alan Cole\"] or [\"John Smith\", \"Amy Jones\"], in the book's real, actual author order. Keep the author count consistent throughout the question. Do NOT provide a surname or initials separately for any author — Citex derives both itself from each full name.\n- There is no place of publication at all in APA style — do NOT provide one.\n- bookTitle MUST be sentence case — only the first word (and any proper nouns) capitalised, e.g. \"Life among the giants\", never \"Life Among The Giants\".\n- The scenario MUST explicitly state that same bookTitle, EVERY author's full name, the same year and the same publisher. Citex independently checks the scenario text against these fields and rejects the question if any of them is not named in the scenario.\n\nMULTIPLE AUTHORS — APA'S REFERENCE-LIST RULE:\n- For the reference list (which is the only thing this question generates), EVERY author is always listed in full — two or more authors are joined with \"&\", preceded by a comma even at exactly two authors; this never changes at 4 or more authors.\n- \"et al.\" must NEVER appear in the reference-list entry, for any author count this app generates.\n- Citex constructs the joined author list itself from authorFullNames — you never write the joined form yourself.\n\nSCENARIOS — ANSWER LEAKAGE IS A CRITICAL FAILURE:\n- Keep each scenario short and mobile-friendly, preferably under 220 characters.\n- Use natural wording such as 'You are creating a reference for a book titled...' or 'You are referencing a book titled...'.\n- State the book title, EVERY author's FULL NAME, publication year and publisher.\n- Prefer concise real book titles (in sentence case); never truncate or alter the actual bibliographic title.\n- The scenario MUST NOT state, label, or abbreviate any author's initials or surname separately, MUST NOT use the words \"initial\" or \"initials\" or \"surname\" anywhere, and MUST NOT show any completed or abbreviated APA reference (e.g. never write \"Cole, A.\", \"Cole, A. (2012)\", or \"Cole & Kaur, J.\").\n- GOOD (one author): \"You are referencing the book titled Social research methods by Alan Cole, published in 2012 by Oxford University Press.\"\n- GOOD (two authors): \"You are referencing a book titled Understanding digital culture by Vincent Dale and Jo Kaur, published in 2020 by SAGE Publications.\"\n- BAD: \"...by Alan Cole (initials A.), published in 2012...\" — reveals the initials directly.\n- BAD: \"...by Cole, A., published in 2012...\" — states the abbreviated citation form directly.\n- BAD: \"The author's surname is Cole and his initials are A.\" — explicitly labels both answers.\n- A full author name naturally containing the surname (e.g. \"Alan Cole\") is correct and required — the failure is explicitly labelling or abbreviating an answer value, not the surname appearing as part of the full name.\n- The student must transform the full bibliographic information you give into the APA reference themselves; do not do that transformation for them anywhere in the scenario.\n\nYou are NOT asked for questionParts, fixedText, or any distractor/confusingWords list — Citex builds the whole draggable question itself (which 3 parts are drawn from the record — possibly including the joining symbol \"&\" — Fixed Text, and every wrong chip), deterministically, after you respond. There is nothing for you to write beyond the record and scenario, and nothing for you to leak an answer through.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. scenario, authorFullNames, year, bookTitle and publisher all describe the exact same book — no contradictions, and the real author count.\n2. bookTitle is genuinely in sentence case.\n3. The scenario states every author's full name naturally and never the words \"initial\"/\"initials\"/\"surname\", and never a completed or abbreviated reference.\n4. publisher is a genuinely varied real choice for this batch, not a repeat of a prior question's publisher.\n5. Only return questions that pass all four checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance() . "\n\n" . self::publisher_diversity_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * APA Book MCQ prompt — mirrors build_prompt_mla_book_mcq_variant()
	 * exactly: Gemini supplies ONLY the canonical record, and
	 * Citex_APA_Book_Mcq_Variants builds the entire question.
	 */
	private static function build_prompt_apa_book_mcq_variant( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct APA / ReferenceList / Book bibliographic records for multiple-choice questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the publisher is real.' : 'Invent a plausible, internally consistent record if needed — the publisher must still be real.' ) . "\n\nONE QUESTION = ONE CANONICAL BIBLIOGRAPHIC RECORD — CRITICAL:\n- authorFullNames, year, bookTitle and publisher must all describe ONE single, internally consistent book. Do not mix facts from a different edition, a different book by the same author(s), or a similarly-named book.\n- authorFullNames is an array of ONE OR MORE author full names (given name(s) + surname each), e.g. [\"Alan Cole\"] or [\"John Smith\", \"Amy Jones\"], in the book's real, actual author order. Use the book's true author count. Do NOT provide a surname or initials separately for any author — Citex derives both itself from each full name.\n- There is no place of publication at all in APA style — do NOT provide one.\n- bookTitle MUST be sentence case — only the first word (and any proper nouns) capitalised.\n- You are NOT asked for a scenario, question text, options, or a correct answer of any kind — Citex builds the ENTIRE multiple-choice question itself (the stem and all 4 options) from this canonical record alone, covering a range of different APA book-formatting rules across the batch. There is nothing for you to write beyond the record itself, and nothing for you to leak an answer through.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. authorFullNames, year, bookTitle and publisher all describe the exact same book — no contradictions, and the real author count.\n2. bookTitle is genuinely in sentence case.\n3. Only return records that pass this check.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance() . "\n\n" . self::publisher_diversity_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * MLA Edited Book DragDrop prompt — mirrors build_prompt_mla_book_dragdrop()'s
	 * shape, with editorFullNames replacing authorFullNames and Citex
	 * constructing the "editor"/"editors" designation itself (see
	 * Citex_MLA_Reference_Rules::join_editors()).
	 */
	private static function build_prompt_mla_edited_book_dragdrop( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct MLA / Works Cited / Edited Book / DragDrop questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the publisher is real.' : 'Invent a plausible, internally consistent record if needed — the publisher must still be real.' ) . "\n\nONE QUESTION = ONE CANONICAL BIBLIOGRAPHIC RECORD — CRITICAL:\n- editorFullNames, year, bookTitle and publisher must all describe ONE single, internally consistent edited book.\n- editorFullNames is an array of ONE OR MORE editor full names (given name(s) + surname each), in the book's real, actual editor order. Do NOT provide a surname separately — Citex derives it itself from each full name, keeping the given name in full, and decides the correct \"editor\"/\"editors\" designation and the \"et al.\" rule for 3+ editors itself.\n- There is no place of publication at all in MLA style — do NOT provide one.\n- The scenario MUST explicitly state that same bookTitle, EVERY editor's full name, the same year and the same publisher.\n\nSCENARIOS — ANSWER LEAKAGE IS A CRITICAL FAILURE:\n- Keep each scenario short and mobile-friendly, under 220 characters, naming the book title, every editor's full name, year and publisher.\n- The scenario MUST NOT abbreviate any editor's given name, MUST NOT show \"editor\"/\"editors\"/\"(ed.)\"/\"(eds)\", and MUST NOT show a completed or abbreviated MLA reference.\n\nYou are NOT asked for questionParts, fixedText, or any distractor/confusingWords list — Citex builds the whole draggable question itself. There is nothing for you to write beyond the record and scenario, and nothing for you to leak an answer through.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. scenario, editorFullNames, year, bookTitle and publisher all describe the exact same book — no contradictions, and the real editor count.\n2. publisher is a genuinely varied real choice for this batch, not a repeat of a prior question's publisher.\n3. Only return questions that pass both checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance() . "\n\n" . self::publisher_diversity_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * MLA Edited Book MCQ prompt — mirrors build_prompt_mla_book_mcq_variant()
	 * exactly, via editorFullNames instead.
	 */
	private static function build_prompt_mla_edited_book_mcq( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct MLA / Works Cited / Edited Book bibliographic records for multiple-choice questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the publisher is real.' : 'Invent a plausible, internally consistent record if needed — the publisher must still be real.' ) . "\n\nONE QUESTION = ONE CANONICAL BIBLIOGRAPHIC RECORD — CRITICAL:\n- editorFullNames, year, bookTitle and publisher must all describe ONE single, internally consistent edited book.\n- editorFullNames is an array of ONE OR MORE editor full names, in the book's real, actual editor order. Do NOT provide a surname separately — Citex derives it itself.\n- There is no place of publication at all in MLA style — do NOT provide one.\n- You are NOT asked for a scenario, question text, options, or a correct answer of any kind — Citex builds the ENTIRE multiple-choice question itself from this canonical record alone. There is nothing for you to write beyond the record itself, and nothing for you to leak an answer through.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. editorFullNames, year, bookTitle and publisher all describe the exact same book — no contradictions, and the real editor count.\n2. Only return records that pass this check.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance() . "\n\n" . self::publisher_diversity_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * MLA Journal Article DragDrop prompt — mirrors
	 * build_prompt_mla_book_dragdrop()'s shape, via
	 * articleTitle/journalTitle/volume/issue/pages instead of
	 * bookTitle/publisher; no place or publisher concept at all.
	 */
	private static function build_prompt_mla_journal_article_dragdrop( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct MLA / Works Cited / Journal Article / DragDrop questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the journal name is real.' : 'Invent a plausible, internally consistent record if needed — the journal name must still be real.' ) . "\n\nONE QUESTION = ONE CANONICAL, REAL, PUBLISHED JOURNAL ARTICLE — CRITICAL:\n- authorFullNames, year, articleTitle, journalTitle, volume, issue and pages must all describe ONE single, internally consistent article.\n- authorFullNames is an array of ONE OR MORE author full names, in the article's real, actual author order. Do NOT provide a surname separately — Citex derives it itself, keeping the given name in full, and decides the \"et al.\" rule for 3+ authors itself.\n- The scenario MUST explicitly state that same article title, journal title, EVERY author's full name, the same year, volume, issue and page range.\n\nSCENARIOS — ANSWER LEAKAGE IS A CRITICAL FAILURE:\n- Keep each scenario short and mobile-friendly, under 220 characters.\n- The scenario MUST NOT abbreviate any author's given name, MUST NOT show the article title in quotation marks with \"vol.\"/\"no.\" labels, and MUST NOT show a completed or abbreviated MLA reference.\n\nYou are NOT asked for questionParts, fixedText, or any distractor/confusingWords list — Citex builds the whole draggable question itself. There is nothing for you to write beyond the record and scenario, and nothing for you to leak an answer through.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. scenario, authorFullNames, year, articleTitle, journalTitle, volume, issue and pages all describe the exact same article — no contradictions, and the real author count.\n2. Only return questions that pass this check.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * MLA Journal Article MCQ prompt — mirrors build_prompt_mla_journal_article_dragdrop()
	 * minus the scenario.
	 */
	private static function build_prompt_mla_journal_article_mcq( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct MLA / Works Cited / Journal Article bibliographic records for multiple-choice questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the journal name is real.' : 'Invent a plausible, internally consistent record if needed — the journal name must still be real.' ) . "\n\nONE QUESTION = ONE CANONICAL, REAL, PUBLISHED JOURNAL ARTICLE — CRITICAL:\n- authorFullNames, year, articleTitle, journalTitle, volume, issue and pages must all describe ONE single, internally consistent article.\n- authorFullNames is an array of ONE OR MORE author full names, in the article's real, actual author order. Do NOT provide a surname separately — Citex derives it itself.\n- You are NOT asked for a scenario, question text, options, or a correct answer of any kind — Citex builds the ENTIRE multiple-choice question itself from this canonical record alone. There is nothing for you to write beyond the record itself, and nothing for you to leak an answer through.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. authorFullNames, year, articleTitle, journalTitle, volume, issue and pages all describe the exact same article — no contradictions, and the real author count.\n2. Only return records that pass this check.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * MLA Website DragDrop prompt — mirrors build_prompt_mla_book_dragdrop()'s
	 * shape, via a single author-or-organisation and NO "n.d." convention
	 * at all: year is requested but may be left empty when no publication/
	 * creation date can be identified (never a guessed year), in which
	 * case Citex's built reference simply omits that segment and relies on
	 * the Accessed date, which Citex supplies itself. There is deliberately
	 * no `publisher` field requested at all for this category (see
	 * Citex_AI_V2::normalise_mla_website_dispatch()'s own docblock).
	 */
	private static function build_prompt_mla_website_dragdrop( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct MLA / Works Cited / Website / DragDrop questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the source is real where practical.' : 'Invent a plausible, internally consistent source.' ) . "\n\nONE QUESTION = ONE CANONICAL, REAL, CURRENTLY-ACCESSIBLE WEBPAGE — CRITICAL:\n- authorType must be exactly \"individual\" or \"organisation\". For \"individual\", provide authorFullName (given name(s) + surname) and leave organisationName empty; for \"organisation\", provide organisationName and leave authorFullName empty. Do NOT provide a surname separately for an individual — Citex derives it itself, keeping the given name in full.\n- title must describe ONE single, internally consistent webpage, and url must be its real (or plausible) address.\n- year is OPTIONAL: provide a real 4-digit publication/creation year when one can genuinely be identified; leave it completely empty when it cannot. NEVER write \"n.d.\", \"undated\", or any placeholder text in year — MLA style has no such convention at all; an empty value is the ONLY correct way to signal an unknown date.\n- The scenario MUST explicitly state the same title, the author's full name or the organisation's name, and the url — and, when a year is provided, that same year too.\n\nSCENARIOS — ANSWER LEAKAGE IS A CRITICAL FAILURE:\n- Keep each scenario short and mobile-friendly, under 220 characters.\n- The scenario MUST NOT abbreviate the author's given name, MUST NOT show a completed or abbreviated MLA reference, and MUST NOT use the words \"n.d.\", \"no date\", or \"undated\" even when no year is provided.\n\nYou are NOT asked for questionParts, fixedText, confusingWords, or an accessed date — Citex builds the whole draggable question itself and computes the accessed date itself. There is nothing for you to write beyond the record and scenario, and nothing for you to leak an answer through.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. scenario, authorType/author-or-organisation-name, title and url all describe the same real, currently-accessible source with no contradictions.\n2. year is either a real 4-digit year or left completely empty — never \"n.d.\" or any other placeholder.\n3. Only return questions that pass both checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * MLA Website MCQ prompt — mirrors build_prompt_mla_website_dragdrop()
	 * minus the scenario.
	 */
	private static function build_prompt_mla_website_mcq( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct MLA / Works Cited / Website bibliographic records for multiple-choice questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the source is real where practical.' : 'Invent a plausible, internally consistent source.' ) . "\n\nONE QUESTION = ONE CANONICAL, REAL, CURRENTLY-ACCESSIBLE WEBPAGE — CRITICAL:\n- authorType must be exactly \"individual\" or \"organisation\". For \"individual\", provide authorFullName and leave organisationName empty; for \"organisation\", provide organisationName and leave authorFullName empty. Do NOT provide a surname separately — Citex derives it itself.\n- title must describe ONE single, internally consistent webpage, and url must be its real (or plausible) address.\n- year is OPTIONAL: a real 4-digit year when identifiable, otherwise leave it completely empty. NEVER write \"n.d.\" or any placeholder text — MLA has no such convention.\n- You are NOT asked for a scenario, question text, options, an accessed date, or a correct answer of any kind — Citex builds the ENTIRE multiple-choice question itself and computes the accessed date itself. There is nothing for you to write beyond the record itself, and nothing for you to leak an answer through.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. authorType/author-or-organisation-name, title, url and (when provided) year all describe the same real, currently-accessible source with no contradictions.\n2. year is either a real 4-digit year or left completely empty.\n3. Only return records that pass both checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	// -----------------------------------------------------------------
	// In-text citation — category-agnostic prompt/system-instruction
	// builders. One method covers all 4 categories and both styles
	// (parametrised, rather than duplicated per combination), since the
	// canonical data an in-text citation needs is genuinely the same
	// shape everywhere: a person list (or Website's single author-or-
	// organisation), a title (scenario text only), a year (Harvard only),
	// and either a short paraphrase clause or a short quote+page — never
	// a publisher, place, journal, volume or issue, none of which an
	// in-text citation ever shows. See Citex_Intext_Citation_Rules and
	// Citex_MLA_Intext_Citation_Rules for the actual formatting rules
	// Citex applies once Gemini's canonical record comes back.
	// -----------------------------------------------------------------

	private static function intext_category_noun( $category ) {
		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
			return 'edited book';
		}
		if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return 'journal article';
		}
		if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
			return 'webpage';
		}
		return 'book';
	}

	private static function intext_people_label( $category ) {
		return Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ? 'editor' : 'author';
	}

	private static function intext_people_field( $category ) {
		return Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ? 'editorFullNames' : 'authorFullNames';
	}

	private static function intext_title_field( $category ) {
		return Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ? 'articleTitle' : 'bookTitle';
	}

	/**
	 * Stricter than content_realism_guidance()'s own surname-only/
	 * word-count caps: in-text citation blanks and MCQ options have even
	 * less room on a mobile screen than a full reference does, so EVERY
	 * word of the title and of every invented name is capped at 5
	 * characters, invented short directly rather than shortened after the
	 * fact — the explicit, stricter constraint the user asked for
	 * specifically for this mechanic.
	 */
	private static function intext_content_guidance() {
		return "INVENTED CONTENT AND SHORT NAMES — CRITICAL FOR THIS MECHANIC:\n"
			. "- Names, titles and any other content do NOT need to be real — you may invent them, as long as the whole record is internally consistent. This tool teaches in-text citation FORMATTING, not bibliographic research.\n"
			. "- Invented names must read as ordinary, plausible personal (or organisation) names — NEVER reuse the name of a real, identifiable, notable person.\n"
			. "- Every WORD of the invented title must be NO MORE THAN 5 CHARACTERS long — invent a short, plain title directly (e.g. \"Urban Life\", \"City Data\") rather than a longer one you then shorten.\n"
			. "- Every WORD of each invented name (given name(s) AND surname alike, or an organisation name) must be NO MORE THAN 5 CHARACTERS long — invent short names directly (e.g. \"Amy Ross\", \"Ben Cole\", \"Jo Kaur\") rather than longer ones you then shorten.";
	}

	private static function intext_form_description( $form ) {
		if ( Citex_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
			return "a NARRATIVE in-text citation, where the author's name is named as part of the sentence itself, only the year (Harvard) sitting in parentheses";
		}
		if ( Citex_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
			return 'a PARENTHETICAL in-text citation, where the whole citation sits in parentheses at the end of a paraphrased sentence';
		}
		return 'a PARENTHETICAL in-text citation of a DIRECT QUOTATION, which always needs a page reference';
	}

	private static function system_instruction_intext( $category, $style, $form, $type ) {
		$style_label = 'mla' === $style ? 'MLA' : 'Harvard';
		return sprintf(
			'You are Citex, an academic question-generation engine. Generate usable %1$s in-text citation %2$s questions about a %3$s, using %4$s. Invented-but-plausible sources are fine — this tool teaches in-text citation FORMATTING, not bibliographic research — as long as each record is internally consistent and no invented name belongs to a real, identifiable person. You are NOT asked for a scenario, question text, options, blanks, or a correct answer of any kind: Citex builds the ENTIRE question itself, deterministically, from the canonical record you provide, applying %1$s\'s own in-text citation rules (including its own "et al." threshold, and — for MLA — the complete absence of a publication year in-text at all). There is nothing for you to write beyond the canonical record itself, and nothing for you to leak an answer through. Before returning each record, perform a strict self-check: every field describes the same source with no contradictions, and any paraphrase clause or quotation names no author, year, or citation detail itself. Return only the requested JSON.',
			$style_label,
			$type,
			self::intext_category_noun( $category ),
			self::intext_form_description( $form )
		);
	}

	/**
	 * Builds the prompt for ANY (category, style, form, type) in-text
	 * citation combination — Gemini supplies only the canonical source
	 * record (people + title + year (Harvard only) + clause, or
	 * quote+page for a direct quotation); Citex constructs the entire
	 * question — stem, blanks/options, and answer — itself, exactly like
	 * every other *_mcq_variant()/DragDrop mechanic that hands the whole
	 * construction job to a dedicated Rules/Dragdrop_Parts/Mcq_Variants
	 * class (see Citex_Intext_Citation_Rules and its MLA counterpart).
	 */
	private static function build_prompt_intext( $category, $style, $form, $type, $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$is_website = Citex_Reference_Rules::CATEGORY_WEBSITE === $category;
		$is_mla     = 'mla' === $style;
		$is_quote   = Citex_Intext_Citation_Rules::FORM_PARENTHETICAL_QUOTE === $form;
		$style_label = $is_mla ? 'MLA' : 'Harvard';
		$noun        = self::intext_category_noun( $category );

		$lines   = array();
		$lines[] = 'Generate exactly ' . count( $ids ) . " distinct {$style_label} / In-Text Citation / " . ucfirst( $noun ) . " / {$type} questions (citation form: {$form}).";
		$lines[] = 'Difficulty: ' . ucfirst( $difficulty ) . '.';
		$lines[] = $verify ? 'Use Google Search to verify the source is real where practical.' : 'Invent a plausible, internally consistent source.';
		$lines[] = '';
		$lines[] = 'ONE QUESTION = ONE CANONICAL SOURCE — CRITICAL:';

		if ( $is_website ) {
			$lines[] = '- authorType must be exactly "individual" or "organisation".';
			$lines[] = '- For "individual", provide authorFullName (given name(s) + surname) and leave organisationName empty; for "organisation", provide organisationName and leave authorFullName empty.';
			$lines[] = '- pageTitle must describe ONE single, internally consistent webpage.';
		} else {
			$people_field = self::intext_people_field( $category );
			$title_field  = self::intext_title_field( $category );
			$label        = self::intext_people_label( $category );
			$lines[]      = "- {$people_field} is an array of ONE OR MORE {$label} full names (given name(s) + surname each), in the source's real, actual {$label} order. Do NOT provide a surname separately — Citex derives it itself from each full name.";
			$lines[]      = "- {$title_field} must describe ONE single, internally consistent {$noun}.";
		}
		if ( ! $is_mla ) {
			$lines[] = $is_website
				? '- year must be a real 4-digit publication/creation year, or exactly "n.d." when no such date can be identified — never a guessed year.'
				: "- year must be the source's real publication year.";
		}

		if ( $is_quote ) {
			$lines[] = '- quote must be a SHORT (10 words or fewer), invented-but-plausible direct quotation from this source — never a real quotation from a real, identifiable work.';
			$lines[] = '- page must be the plain page number the quotation appears on (digits only, no "p."/"pp." prefix — Citex adds that itself).';
		} else {
			$lines[] = '- clause must be a SHORT (under 20 words), invented-but-plausible paraphrase of what this source argues, finds, or suggests — written as the part of a sentence that follows the citation (e.g. "argues that ..." or "finds that ..."), with NO leading capital letter and NO trailing full stop (Citex adds the surrounding sentence and punctuation itself).';
			$lines[] = '- clause must NEVER name the author, mention the year, or contain any part of a citation itself — it must read as pure subject-matter content, never a description of the source.';
			if ( $is_mla && ! $is_website ) {
				$lines[] = '- page must be the plain page number this paraphrased point appears on (digits only) — MLA style includes a page reference whenever one exists, even for a paraphrase.';
			}
		}

		$lines[] = '';
		$lines[] = 'YOU ARE NOT ASKED FOR A SCENARIO, QUESTION TEXT, OPTIONS, OR AN ANSWER OF ANY KIND:';
		$lines[] = '- Citex builds the ENTIRE question itself — the stem, every blank/option, and the correct answer — deterministically from the canonical record above, applying the correct in-text citation rule for this style and form. There is nothing else for you to write, and nothing for you to leak an answer through.';

		$lines[] = '';
		$lines[] = 'FINAL SELF-CHECK — DO NOT SKIP:';
		$lines[] = '1. Every field above describes the exact same source, with no contradictions.';
		$lines[] = $is_quote
			? '2. quote is short, plausible, and contains no citation information itself; page is a plain number.'
			: '2. clause is a short, plausible paraphrase that names no author, year, or citation detail.';
		$lines[] = '3. Only return questions that pass this check.';
		$lines[] = '';
		$lines[] = 'IDs in exact order:';
		$lines[] = implode( ', ', $ids );

		$prompt = implode( "\n", $lines );
		$prompt .= "\n\n" . self::intext_content_guidance() . "\n\n" . self::plain_style_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * Book MCQ prompt — unlike every prior Book MCQ mechanic (and unlike
	 * every OTHER category's MCQ prompt), this asks Gemini for NOTHING
	 * beyond the canonical book record: no distractors, no error reasons.
	 * Citex_Book_Mcq_Variants::build() constructs the entire question
	 * (stem, all 4 options, and the answer) deterministically from that
	 * record alone, covering the user's own fixed 16-variant catalogue —
	 * which variant is used is decided after Gemini responds, so this one
	 * prompt/schema covers all 16 uniformly.
	 */
	private static function build_prompt_book_mcq_variant( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct Harvard / ReferenceList / Book bibliographic records for multiple-choice questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the publisher is real.' : 'Invent a plausible, internally consistent record if needed — the publisher must still be real.' ) . "\n\nONE QUESTION = ONE CANONICAL BIBLIOGRAPHIC RECORD — CRITICAL:\n- authorFullNames, year, bookTitle, place and publisher must all describe ONE single, internally consistent book. Do not mix facts from a different edition, a different book by the same author(s), or a similarly-named book.\n- authorFullNames is an array of ONE OR MORE author full names (given name(s) + surname each), e.g. [\"Alan Cole\"] or [\"John Smith\", \"Amy Jones\"], in the book's real, actual author order. Use the book's true author count. Do NOT provide a surname or initials separately for any author — Citex derives both itself from each full name.\n- You are NOT asked for a scenario, question text, options, or a correct answer of any kind — Citex builds the ENTIRE multiple-choice question itself (the stem and all 4 options) from this canonical record alone, covering a range of different Harvard book-formatting rules across the batch. There is nothing for you to write beyond the record itself, and nothing for you to leak an answer through.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. authorFullNames, year, bookTitle, place and publisher all describe the exact same book — no contradictions, and the real author count.\n2. Only return records that pass this check.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance() . "\n\n" . self::place_publisher_diversity_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * Journal Article DragDrop prompt — modelled directly on build_prompt()
	 * (Book DragDrop) but for the Harvard journal-article format
	 * (Author surname(s), initial(s). (Year) 'Article title', Journal title,
	 * Volume(Issue), pp. xx–xx.): no place/publisher concept at all, and the
	 * DragDrop shape is a CONSTANT 7 parts for ANY author count (see
	 * Citex_Reference_Rules::journal_article_dragdrop_shape()) rather than
	 * Book's shape-varies-by-count design.
	 */
	private static function build_prompt_journal_article( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '', $exercise_design = 'full_reference' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct Harvard / ReferenceList / Journal Article / DragDrop questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the publisher/journal is real.' : 'Invent a plausible, internally consistent record if needed — the publisher/journal must still be real.' ) . "\n\nONE QUESTION = ONE CANONICAL JOURNAL ARTICLE RECORD — CRITICAL:\n- authorFullNames, year, articleTitle, journalTitle, volume, issue and pages must all describe ONE single, internally consistent journal article. Do not mix facts from a different article, a different issue, or a similarly-titled article.\n- authorFullNames is an array of ONE OR MORE author full names (given name(s) + surname each), e.g. [\"Jane Smith\"] or [\"John Smith\", \"Amy Jones\"], in the given author order. Keep the author count consistent throughout the question. Do NOT provide a surname or initials separately for any author — Citex derives both itself from each full name.\n- volume and issue must be the real numeric volume and issue number the article was published in; pages must be the real page range (e.g. \"27-35\") with no \"p.\"/\"pp.\" prefix — Citex adds that itself.\n- There is no place or publisher for a journal article — do not provide either.\n- Provide this FULL canonical record regardless of which specific part the question below actually tests — Citex always keeps the complete real source data, even when only a small part of it is shown to the student.\n- The scenario MUST explicitly state that same articleTitle, journalTitle, EVERY author's full name, the same year, volume, issue and page range. Citex independently checks the scenario text against these fields and rejects the question if any of them is not named in the scenario.\n\nMULTIPLE AUTHORS — LIVERPOOL HOPE'S REFERENCE-LIST RULE:\n- For the reference list (which is the only thing this question generates), EVERY author is always listed in full — 2 authors are joined with \"and\"; 3 or more are comma-separated with \"and\" before the final author; this never changes at 4 or more authors.\n- \"et al.\" must NEVER appear in the reference-list entry, for any author count. (\"et al.\" is Harvard's separate IN-TEXT-CITATION convention — this question never generates an in-text citation, only a reference-list entry.)\n- Citex constructs the joined author list itself from authorFullNames — you never write the joined form yourself.\n\nSCENARIOS — ANSWER LEAKAGE IS A CRITICAL FAILURE:\n- Keep each scenario short and mobile-friendly, roughly 15-30 words, preferably under 220 characters.\n- Use natural wording such as 'You are referencing a journal article titled...' or 'You are creating a reference for an article titled...'.\n- State the article title, journal title, EVERY author's FULL NAME, publication year, volume, issue and page range.\n- ALWAYS introduce the journal title with the word \"journal\" (e.g. \"in the journal Cities\", \"in the journal Nature\") rather than naming it bare (\"in Cities\") — some real journal names are short, ordinary words or phrases (\"Cities\", \"Nature\", \"Science\", \"Language\") that read as a place, a topic, or a generic noun instead of a journal title when introduced without that word. This applies regardless of how distinctive the real journal's own name already is.\n- The scenario MUST NOT state, label, or abbreviate any author's initials or surname separately, MUST NOT use the words \"initial\" or \"initials\" or \"surname\" anywhere, MUST NOT show any completed or abbreviated Harvard reference, MUST NOT say \"use et al.\", and MUST NOT state the answer's punctuation or ordering.\n- GOOD (one author): \"You are referencing a journal article titled A brief guide to Harvard referencing by Jane Smith, published in 2010 in the journal The British Journal of Referencing, volume 12, issue 2, pages 27 to 35.\"\n- GOOD (two authors): \"You are creating a reference for an article titled Digital culture and learning by Vincent Dale and Jo Kaur, published in 2020 in the journal Journal of Media Studies, volume 8, issue 3, pages 145 to 160.\"\n- GOOD (short/ambiguous-sounding real journal name): \"You are referencing an article titled Urban Growth by Liam Vance, published in 2021 in the journal Cities, volume 45, issue 2, pages 110 to 118.\" — never \"...published in 2021 in Cities, volume 45...\", which reads as if \"Cities\" were a place.\n- BAD: \"...by Jane Smith (initials J.), published in 2010...\" — reveals the initials directly.\n- BAD: \"...by Smith, J., published in 2010...\" — states the abbreviated citation form directly.\n- BAD: \"...by Smith et al., published in 2020...\" — states the in-text-citation abbreviation directly, and is also not how the reference-list entry is written.\n- A full author name naturally containing the surname (e.g. \"Jane Smith\") is correct and required — the failure is explicitly labelling or abbreviating an answer value, not the surname appearing as part of the full name.\n- The student must transform the full bibliographic information you give into the Harvard reference themselves; do not do that transformation for them anywhere in the scenario.\n\nDRAGDROP — HARD RULE, EXACTLY 3 DRAGGABLE PARTS:\n- Citex derives the author list from authorFullNames and INDEPENDENTLY constructs the real Question Parts, Fixed Text, AND every wrong chip itself, deterministically, from the complete record above — your own questionParts/fixedText/confusingWords fields (if you include them) are used only for your own self-check and are never read as authoritative. You are NOT asked for a distractor/confusingWords list at all — there is nothing for you to write beyond the record and scenario, and nothing for you to leak an answer through.\n- Every DragDrop question tests EXACTLY 3 of the following facts, never fewer, never more: the author list (surname = each full name's last word; initials = the first letter of every other word, each followed by a full stop, no spaces), year, volume, issue, page range, or journal title. The author list — for any real author count — is always ONE COMPACT PIECE (all authors joined together, e.g. \"Smith, J., Jones, A. and Lee, K.\") — NEVER one piece per author, and NEVER \"et al.\" (Harvard's reference-list rule always lists every author in full).\n- Exactly which 3 facts this specific question tests is fixed by the EXERCISE DESIGN note below — follow it precisely; do not add or drop a fact.\n- The article title is NEVER a draggable piece for any DragDrop design — do not include it in questionParts for a DragDrop question.\n- No full stop after the year parentheses; no spaces before punctuation.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. scenario, authorFullNames, year, articleTitle, journalTitle, volume, issue and pages all describe the exact same article — no contradictions, and the real author count.\n2. The scenario states every author's full name naturally and never the words \"initial\"/\"initials\"/\"surname\", and never a completed, abbreviated, or \"et al.\" reference.\n3. The author list is correctly derived from authorFullNames and, when tested, is joined into ONE compact piece (never \"et al.\", never split into separate pieces).\n4. The question tests EXACTLY 3 facts as specified by the exercise design, never fewer, never more, and never the article title.\n5. No unwanted punctuation or spacing errors.\n6. Only return questions that pass all six checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance();
		$design_note = self::journal_article_design_prompt_note( $exercise_design );
		if ( '' !== $design_note ) { $prompt .= "\n\n" . $design_note; }
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * MOBILE SUITABILITY — explains to Gemini which small component of the
	 * full record this particular question will actually show/test on the
	 * real mobile DragDrop interface, so it can prefer a real source whose
	 * relevant piece is naturally short (per conciseness_guidance() applied
	 * to the whole record) even though the FULL record is still always
	 * required above. Citex still builds Question Parts/Fixed Text/the
	 * correct MCQ answer itself from the full record — this note only helps
	 * Gemini pick a well-suited real source; it changes nothing about what
	 * is actually asked for. Empty string for 'full_reference' (no
	 * additional note needed) or an unrecognised design.
	 */
	private static function journal_article_design_prompt_note( $exercise_design ) {
		$notes = array(
			'author_only'              => "EXERCISE DESIGN — AUTHOR NAME ONLY (MCQ):\n- This question tests ONLY the author list's Harvard-format name (\"Surname, I.\") in isolation, as a multiple-choice question — not the rest of the reference. Still provide the complete real record above, but prefer a source whose author name(s) are not unusually long.",
			'author_year_volume_pages' => "EXERCISE DESIGN — AUTHOR(S) + YEAR + VOLUME (exactly 3 draggable parts):\n- This question shows and tests the author list AS ONE COMPACT PIECE (all authors joined together — never one piece per author, never \"et al.\"), plus the year and volume as 2 further short pieces — 3 pieces total, never the page range, article title, or journal title (the page range still appears in the reference as ordinary fixed text, just not as a draggable piece). Still provide the complete real record above, but prefer author names and volume that stay short.",
			'author_year_issue'        => "EXERCISE DESIGN — AUTHOR(S) + YEAR + ISSUE (exactly 3 draggable parts):\n- This question shows and tests the author list AS ONE COMPACT PIECE (never one piece per author, never \"et al.\"), plus the year and the issue number as 2 further short pieces — 3 pieces total. Still provide the complete real record above.",
			'author_year_journal'      => "EXERCISE DESIGN — AUTHOR(S) + YEAR + JOURNAL TITLE (exactly 3 draggable parts):\n- This question shows and tests the author list AS ONE COMPACT PIECE (never one piece per author, never \"et al.\"), plus the year and the journal title as 2 further short pieces — 3 pieces total. Still provide the complete real record above, but prefer a journal title that is not unusually long.",
			'volume_issue_pages'       => "EXERCISE DESIGN — VOLUME/ISSUE/PAGES (exactly 3 draggable parts):\n- This particular question will only show and test the volume(issue), page-range structure (\"14(2), pp. 45–52.\") — not the rest of the reference. Still provide the complete real record above.",
			'journal_volume_issue'     => "EXERCISE DESIGN — JOURNAL/VOLUME/ISSUE (exactly 3 draggable parts):\n- This particular question will only show and test the journal title, volume and issue (\"Journal Name, 14(2)\") — not the rest of the reference. Still provide the complete real record above, but prefer a journal title that is not unusually long.",
			'year_volume_issue_pages'  => "EXERCISE DESIGN — YEAR + VOLUME + ISSUE, NO AUTHOR (exactly 3 draggable parts):\n- This question shows and tests the year, volume and issue as 3 short pieces — not the page range, author(s), article title, or journal title at all (the page range still appears in the reference as ordinary fixed text, just not as a draggable piece). Still provide the complete real record above.",
		);
		return $notes[ $exercise_design ] ?? '';
	}

	/**
	 * Journal Article MCQ prompt — modelled directly on build_prompt_book_mcq_variant()
	 * (Book MCQ), reusing distractor_prompt_section() with this category's
	 * own mcq_distractor_patterns() catalogue and correct-format description.
	 */
	private static function build_prompt_journal_article_mcq( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '', $exercise_design = 'full_reference' ) {
		$difficulty_guidance = array(
			'easy'   => "Easy: the 3 distractors should each contain one obvious, easy-to-spot mistake (e.g. missing punctuation, no parentheses around the year) — testing basic recognition of the Harvard Journal Article structure.",
			'medium' => "Medium: the 3 distractors should each contain one specific, realistic mistake a student could plausibly make (e.g. author's full first name instead of initials, or missing the \"pp.\" prefix before the page range) — testing the ability to spot ONE particular error type per option.",
			'hard'   => "Hard: the 3 distractors should be very close to correctly formatted, differing from the correct one by only a small, easy-to-miss detail (e.g. a single misplaced space, comma, or full stop) — testing careful side-by-side comparison of near-identical references.",
		);
		$prompt = "Generate exactly " . count( $ids ) . " distinct Harvard / ReferenceList / Journal Article multiple-choice questions.\nDifficulty: " . ucfirst( $difficulty ) . ". " . ( $difficulty_guidance[ sanitize_key( $difficulty ) ] ?? $difficulty_guidance['medium'] ) . "\n" . ( $verify ? 'Use Google Search to verify the publisher/journal is real.' : 'Invent a plausible, internally consistent record if needed — the publisher/journal must still be real.' ) . "\n\nONE QUESTION = ONE CANONICAL JOURNAL ARTICLE RECORD — CRITICAL:\n- authorFullNames, year, articleTitle, journalTitle, volume, issue and pages must all describe ONE single, internally consistent journal article. Do not mix facts from a different article or a different issue.\n- authorFullNames is an array of ONE OR MORE author full names (given name(s) + surname each), e.g. [\"Jane Smith\"] or [\"John Smith\", \"Amy Jones\"], in the given author order. Use the article's true author count. Do NOT provide a surname or initials separately for any author — Citex derives both itself from each full name and constructs the one correct Harvard reference from them (joining multiple authors per Harvard's reference-list rule: every author listed in full, comma-separated with a final \"and\", for any count — never \"et al.\"); you never provide the correct reference yourself.\n- There is no place or publisher for a journal article — do not provide either.\n- Provide this FULL canonical record regardless of which specific part the options below actually compare — Citex always keeps the complete real source data, even when the options only show a short segment of it.\n- You are NOT asked for a scenario or question text — Citex supplies the entire student-facing question itself (a fixed, design-appropriate stem), so there is nothing for you to write and nothing for you to leak the answer through."
			. self::distractor_prompt_section( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, 'Surname, I. (YYYY) \'Article title\', Journal title, Volume(Issue), pp. Start–End. — or, for two or more authors, Surname, I. and Surname, I. (YYYY) \'Article title\', Journal title, Volume(Issue), pp. Start–End., extending with commas and a final "and" for 3+, never "et al." — the article title is wrapped in single quotation marks and followed by a comma (never a full stop), and the page range uses an en dash ("–") with a space after "pp." — NOTE: for this batch, the options actually being compared may be a SHORT SEGMENT of this format (e.g. only the author, or only the volume/issue/pages) rather than the full reference — build each distractor as a wrong variant of that same segment, still by deliberately applying one of the numbered error patterns below.' )
			. "\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. authorFullNames, year, articleTitle, journalTitle, volume, issue and pages all describe the exact same article — no contradictions, and the real author count.\n2. Exactly 3 distractors are provided, each with a non-empty, specific errorReason naming the Harvard rule it breaks.\n3. Every distractor, re-read end-to-end against the full correct format, genuinely still breaks the rule named in its errorReason — none of them accidentally also satisfies every Harvard rule.\n4. All 3 distractors are mutually distinct from each other and from the correct reference, and exactly one reference overall (the one Citex will construct) is fully correct.\n5. None of the distractors uses \"et al.\" as if it were valid in the reference list — that abbreviation is never correct here.\n6. Only return questions that pass all six checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance();
		$design_note = self::journal_article_design_prompt_note( $exercise_design );
		if ( '' !== $design_note ) { $prompt .= "\n\n" . $design_note; }
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * Website (Web Resource) DragDrop prompt — the Harvard
	 * website/webpage format: Author/Organisation (Year|n.d.) Title.
	 * Available at: URL (Accessed: Day Month Year). There is no publisher
	 * element in the reference at all — publisher is still requested and
	 * kept in the record (used only to verify the source is real), but
	 * never rendered and never draggable. Unlike every other category,
	 * there is only ONE author-or-organisation (no multi-person joining
	 * rule), and Gemini is never asked for an accessed date at all — Citex
	 * computes it itself.
	 */
	private static function build_prompt_website( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct Harvard / ReferenceList / Website (Web Resource) / DragDrop questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the publisher is real.' : 'Do not invent sources.' ) . "\n\nONE QUESTION = ONE CANONICAL WEB SOURCE — CRITICAL:\n- authorType, year, title, publisher and url must all describe ONE single, internally consistent webpage or downloadable document (e.g. a PDF report). Do not mix facts from a different page or a different source.\n- authorType must be exactly \"individual\" or \"organisation\". If \"individual\", provide authorFullName (a full name, e.g. \"Sarah Ross\") — do NOT provide a surname or initials separately; Citex derives both itself. If \"organisation\", provide organisationName (the organisation's real name exactly as it should appear, e.g. \"University of Leeds\") — never invert it into a surname-style format.\n- year must be the real 4-digit publication/creation year IF the source clearly states or shows one. If — and ONLY if — no such date can genuinely be identified for the real source, year must be exactly the literal string \"n.d.\". NEVER guess a year, and NEVER use today's year merely because the page happens to be online now. NEVER use \"n.d.\" for a source that does have an identifiable date.\n- publisher is the organisation responsible for publishing/hosting the page — this may be the SAME organisation as an organisation author (Harvard's own official example uses the same organisation as both author and publisher), or a different one when the author is an individual. NOTE: the publisher never appears in the built reference itself — it is used only to confirm the source is a real one, and is still worth stating naturally in the scenario.\n- url must be AS SHORT AS POSSIBLE: JUST a domain, with NO path at all after it (e.g. \"https://www.sage.com\", \"https://www.who.int\", \"https://www.mit.edu\", \"https://www.bbc.co.uk\" — never \"/guide\", \"/about\", \"/news/climate\", or any other word, slug, or subpage). The domain's own name (the part before the .com/.org/.edu/.ac.uk/etc.) must be 5 CHARACTERS OR FEWER — e.g. \"sage\", \"who\", \"mit\", \"bbc\", \"ibm\", \"nasa\", \"un\", \"nhs\" — never a longer one like \"leeds\" or \"energyinst\". It does not need to be the source's literal real-world domain — invent a short, genuine-looking one for the same kind of organisation if the real one is longer, as long as it uses a normal domain ending.\n- Do NOT provide an accessed date — Citex supplies it itself.\n\nSCENARIOS — ANSWER LEAKAGE IS A CRITICAL FAILURE:\n- Keep each scenario short and mobile-friendly, preferably under 220 characters.\n- Use natural wording such as 'You are referencing a webpage written by...' or 'You are referencing a webpage published by...' or 'You are creating a reference for a document titled...'.\n- State the title, the author's full name OR the organisation's name, the publisher, and the url. If the source has a real year, state it; if it genuinely has no identifiable date, describe the source in a way that makes this apparent WITHOUT using the words \"n.d.\", \"no date\", or \"undated\" — e.g. simply omit any date reference.\n- The scenario MUST NOT state, label, or abbreviate the author's initials or surname separately, MUST NOT use the words \"initial\" or \"initials\" or \"surname\" anywhere, MUST NOT show any completed or abbreviated Harvard reference, and MUST NOT use the words \"n.d.\", \"no date\", or \"undated\".\n- GOOD (individual, dated): \"You are referencing a webpage written by Sarah Ross in 2024 and published by the University of Leeds, titled Study skills guide, at https://www.sage.com.\"\n- GOOD (organisation, undated): \"You are referencing a University of Leeds webpage titled About us, at https://www.sage.com.\" (no date mentioned at all, since none exists)\n- BAD: \"...by Sarah Ross (initials S.)...\" — reveals the initials directly.\n- BAD: \"...by Ross, S., published in 2024...\" — states the abbreviated citation form directly.\n- BAD: \"...this page has no date, so use (n.d.)...\" — states the answer directly instead of letting the student recognise it.\n- The student must transform the full source information you give into the Harvard reference themselves; do not do that transformation for them anywhere in the scenario.\n\nDRAGDROP — HARD RULE, EXACTLY 3 DRAGGABLE PARTS:\n- Citex derives the individual author's surname/initials from authorFullName (or uses organisationName exactly as given) and constructs Question Parts, Fixed Text, AND every wrong chip itself, deterministically, from the complete record above (author-or-organisation, year-or-\"n.d.\", title, url, and the accessed date it computes — the publisher is never part of the built reference at all) — deciding internally which EXACTLY 3 of the 4 eligible fields (author-or-organisation, year-or-\"n.d.\", title, accessed date) are drawn as draggable Question Parts for this specific question — the url is NEVER draggable, since it needs no Harvard-format transformation and would just repeat the scenario's own text verbatim; the rest stay as ordinary fixed text. You are NOT asked for a distractor/confusingWords list at all — there is nothing for you to write beyond the record and scenario, and nothing for you to leak an answer through.\n- Reconstructed answer (individual, dated): Surname, I. (YYYY) Title. Available at: URL (Accessed: DD Month YYYY).\n- Reconstructed answer (organisation, undated): Organisation Name (n.d.) Title. Available at: URL (Accessed: DD Month YYYY).\n- No full stop after the year parentheses; no spaces before punctuation; final full stop required.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. scenario, authorType, author-or-organisation-name, year (or \"n.d.\"), title, publisher and url all describe the exact same real source — no contradictions.\n2. The scenario states the author's or organisation's full real name naturally and never the words \"initial\"/\"initials\"/\"surname\"/\"n.d.\"/\"no date\"/\"undated\", and never a completed or abbreviated reference.\n3. No unwanted punctuation or spacing errors.\n4. Only return questions that pass all four checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance() . "\n\n" . self::publisher_diversity_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	/**
	 * Website MCQ prompt — mirrors build_prompt_book_mcq_variant() exactly:
	 * Gemini supplies ONLY the canonical record (same fields DragDrop
	 * already asks for), and Citex_Website_Mcq_Variants builds the entire
	 * question — stem, all 4 options, and the answer — deterministically
	 * from it. Replaces the previous "Gemini supplies 3 distractors"
	 * mechanic entirely, after a request for more question variety
	 * matching what Book's own 16-variant catalogue already provides.
	 */
	private static function build_prompt_website_mcq_variant( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$prompt = "Generate exactly " . count( $ids ) . " distinct Harvard / ReferenceList / Website (Web Resource) bibliographic records for multiple-choice questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the publisher is real.' : 'Invent a plausible, internally consistent record if needed — the publisher must still be real.' ) . "\n\nONE QUESTION = ONE CANONICAL WEB SOURCE — CRITICAL:\n- authorType, year, title, publisher and url must all describe ONE single, internally consistent webpage or downloadable document. Do not mix facts from a different page or a different source.\n- authorType must be exactly \"individual\" or \"organisation\". If \"individual\", provide authorFullName — do NOT provide a surname or initials separately; Citex derives both itself. If \"organisation\", provide organisationName, used exactly as given.\n- year must be a plausible 4-digit year, or exactly \"n.d.\" if the source has no identifiable date — never use \"n.d.\" for a dated source.\n- publisher never appears in the built reference itself — it is used only to confirm the source is a real one.\n- url must be AS SHORT AS POSSIBLE: JUST a domain, with NO path at all after it (e.g. \"https://www.sage.com\", \"https://www.who.int\", \"https://www.mit.edu\", \"https://www.bbc.co.uk\" — never \"/guide\", \"/about\", \"/news/climate\", or any other word, slug, or subpage). The domain's own name (the part before the .com/.org/.edu/.ac.uk/etc.) must be 5 CHARACTERS OR FEWER — e.g. \"sage\", \"who\", \"mit\", \"bbc\", \"ibm\", \"nasa\", \"un\", \"nhs\" — never a longer one like \"leeds\" or \"energyinst\". It does not need to be the source's literal real-world domain — invent a short, genuine-looking one for the same kind of organisation if the real one is longer, as long as it uses a normal domain ending.\n- Do NOT provide an accessed date — Citex supplies it itself.\n- You are NOT asked for a scenario, question text, options, or a correct answer of any kind — Citex builds the ENTIRE multiple-choice question itself (the stem and all 4 options) from this canonical record alone, covering a range of different Harvard website-formatting rules across the batch. There is nothing for you to write beyond the record itself, and nothing for you to leak an answer through.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. authorType, author-or-organisation-name, year (or \"n.d.\"), title, publisher and url all describe the exact same real source — no contradictions.\n2. Only return records that pass this check.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance() . "\n\n" . self::publisher_diversity_guidance();
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
		return ( $verify ? 'Use Google Search to verify the publisher/journal is real.' : 'Invent a plausible, internally consistent record if needed — the publisher/journal must still be real.' )
			. "\n\nONE QUESTION = ONE CANONICAL EDITED BOOK RECORD — CRITICAL:\n- editorFullNames is an array of ONE or TWO editor full names (given name(s) + surname each), e.g. [\"Vincent Dale\"] or [\"John Smith\", \"Amy Jones\"]. Do NOT provide a surname or initials separately for any editor — Citex derives both itself from each full name.\n- year, bookTitle, place and publisher must all describe ONE single, internally consistent edited book as editorFullNames. Do not mix facts from a different edition or a different book.\n- The scenario MUST explicitly state that same bookTitle, EVERY editor's full name, the same year, place and publisher. Citex independently checks the scenario text against these fields.\n\nTHE EDITOR DESIGNATION RULE — THIS IS WHAT THIS CATEGORY TESTS:\n- Exactly ONE editor -> the designation is \"(ed.)\" (with the trailing period, inside its own parentheses).\n- TWO editors -> the designation is \"(eds)\" (no period) and the two editor names are joined with \"and\" (e.g. \"Smith, J. and Jones, A.\").\n- Never use \"(ed.)\" for two editors, and never use \"(eds)\" for one editor.\n- The designation always comes immediately after the editor name(s) and before the year, each in its own parentheses: \"Surname, I. (ed.) (YYYY) Title. Place: Publisher.\"\n\nSCENARIOS — ANSWER LEAKAGE IS A CRITICAL FAILURE:\n- Keep each scenario short and mobile-friendly, preferably under 220 characters.\n- Use natural wording such as 'You are referencing a book edited by...' or 'You are creating a reference for a book edited by...'.\n- State the book title, EVERY editor's FULL NAME, publication year, publisher and publication place.\n- The scenario MUST NOT show \"(ed.)\" or \"(eds)\" anywhere — that is the answer this question tests.\n- The scenario MUST NOT state, label, or abbreviate any editor's initials or surname separately, MUST NOT use the words \"initial\", \"initials\", or \"surname\" anywhere, and MUST NOT show a completed or abbreviated Harvard citation anywhere (e.g. never write \"Smith, J.\").\n- GOOD: \"You are referencing a book edited by Vincent Dale, titled Understanding digital culture, published in 2020 by SAGE Publications in London.\"\n- BAD: \"...edited by Vincent Dale (ed.), published in 2020...\" — reveals the designation directly.\n- BAD: \"...by Smith, J., published in 2020...\" — states the abbreviated citation form directly.";
	}

	private static function build_prompt_edited_book( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$difficulty_guidance = array(
			'easy'   => 'Easy: use exactly one editor, and make the confusingWords obviously wrong (e.g. "author", "editor" as a full word, a clearly different year) — testing basic recognition of the "(ed.)" convention.',
			'medium' => 'Medium: mix one-editor and two-editor questions across the batch, and make confusingWords plausible near-misses (e.g. "eds" as a distractor for a one-editor question, or "ed." for a two-editor one) — testing whether the student applies the right designation for the given editor count.',
			'hard'   => 'Hard: prefer two-editor questions, and make confusingWords very close to correct (e.g. "editor" vs "ed.", or "eds." with a stray period) — testing careful attention to exact punctuation.',
		);
		$prompt = "Generate exactly " . count( $ids ) . " distinct Harvard / ReferenceList / Edited Book / DragDrop questions.\nDifficulty: " . ucfirst( $difficulty ) . ". " . ( $difficulty_guidance[ sanitize_key( $difficulty ) ] ?? $difficulty_guidance['medium'] ) . "\n"
			. self::edited_book_prompt_intro( $verify )
			. "\n\nDRAGDROP — HARD RULE, EXACTLY 3 DRAGGABLE PARTS:\n- Citex derives each editor's surname/initials from editorFullNames, decides the designation (\"(ed.)\"/\"(eds)\") from the editor count, and constructs Question Parts, Fixed Text, AND every wrong chip itself, deterministically, from the complete record above — your own questionParts/fixedText/confusingWords fields (if present) are for your own self-check only and are never read as authoritative. You are NOT asked for a distractor/confusingWords list at all — there is nothing for you to write beyond the record and scenario, and nothing for you to leak an answer through.\n- Every question tests EXACTLY 3 facts: the editor(s) (as ONE joined chip, e.g. \"Smith, J.\" or \"Smith, J. and Jones, A.\" — split into surname/initials as two separate parts for some questions instead), the designation (\"ed.\" or \"eds\" — never traded away, always tested), and exactly ONE further fact rotating across the batch: year, book title, place, or publisher. Whichever fields are not drawn for a given question still appear in the reference as ordinary fixed text.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. scenario, editorFullNames, year, bookTitle, place and publisher all describe the exact same book — no contradictions.\n2. The scenario names every editor naturally and never shows \"(ed.)\"/\"(eds)\", never the words \"initial\"/\"initials\"/\"surname\", never a completed citation.\n3. The designation matches the editor count exactly (one editor -> \"ed.\", two -> \"eds\").\n4. Only return questions that pass all four checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance() . "\n\n" . self::place_publisher_diversity_guidance();
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
		return ( $verify ? 'Use Google Search to verify the publisher/journal is real.' : 'Invent a plausible, internally consistent record if needed — the publisher/journal must still be real.' )
			. "\n\nONE QUESTION = ONE CANONICAL EDITED BOOK RECORD — CRITICAL:\n- editorFullNames is an array of ONE or TWO editor full names (given name(s) + surname each), e.g. [\"Vincent Dale\"] or [\"John Smith\", \"Amy Jones\"]. Do NOT provide a surname or initials separately for any editor — Citex derives both itself from each full name.\n- year, bookTitle, place and publisher must all describe ONE single, internally consistent edited book as editorFullNames. Do not mix facts from a different edition or a different book.\n- You are NOT asked for a scenario or question text — Citex supplies the entire student-facing question itself (a fixed \"Which of the following is the correct Harvard reference for an edited book?\" stem), so there is nothing for you to write and nothing for you to leak the answer through.\n\nTHE EDITOR DESIGNATION RULE — THIS IS WHAT THIS CATEGORY TESTS:\n- Exactly ONE editor -> the designation is \"(ed.)\" (with the trailing period, inside its own parentheses).\n- TWO editors -> the designation is \"(eds)\" (no period) and the two editor names are joined with \"and\" (e.g. \"Smith, J. and Jones, A.\").\n- Never use \"(ed.)\" for two editors, and never use \"(eds)\" for one editor.\n- The designation always comes immediately after the editor name(s) and before the year, each in its own parentheses: \"Surname, I. (ed.) (YYYY) Title. Place: Publisher.\"";
	}

	private static function build_prompt_edited_book_mcq( $ids, $difficulty, $verify, $quality_feedback = '', $scenario_instruction = '' ) {
		$difficulty_guidance = array(
			'easy'   => 'Easy: use exactly one editor, and make the 3 distractors obviously wrong (e.g. designation missing entirely, or an unmistakable punctuation error) — testing basic recognition.',
			'medium' => 'Medium: mix one- and two-editor questions, and make each distractor contain one specific, realistic mistake (e.g. "(editor)" instead of "(ed.)", or the wrong designation for the editor count) — testing the ability to spot ONE particular error type per option.',
			'hard'   => 'Hard: prefer two-editor questions, and make the 3 distractors very close to correctly formatted, differing only by a small, easy-to-miss detail (e.g. "(ed.)" used for two editors, or a misplaced comma) — testing careful side-by-side comparison.',
		);
		$prompt = "Generate exactly " . count( $ids ) . " distinct Harvard / ReferenceList / Edited Book multiple-choice questions.\nDifficulty: " . ucfirst( $difficulty ) . ". " . ( $difficulty_guidance[ sanitize_key( $difficulty ) ] ?? $difficulty_guidance['medium'] ) . "\n"
			. self::edited_book_mcq_intro( $verify )
			. self::distractor_prompt_section( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, 'Editor(s), Initials. (ed.|eds) (YYYY) Title. Place: Publisher.' )
			. "\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. editorFullNames, year, bookTitle, place and publisher all describe the exact same book — no contradictions.\n2. Exactly 3 distractors are provided, each with a non-empty, specific errorReason naming the Harvard rule it breaks, especially designation mistakes for this category.\n3. Every distractor, re-read end-to-end against the full correct format, genuinely still breaks the rule named in its errorReason — none of them accidentally also satisfies every Harvard rule (in particular, none accidentally uses the correct \"(ed.)\"/\"(eds)\" designation for this question's editor count).\n4. All 3 distractors are mutually distinct from each other and from the correct reference, and exactly one reference overall (the one Citex will construct) is fully correct.\n5. Only return questions that pass all five checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance() . "\n\n" . self::place_publisher_diversity_guidance();
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
			? "ONE QUESTION = ONE CANONICAL EDITED BOOK RECORD — CRITICAL:\n- editorFullNames is an array of editor full name(s) (may be invented — see below). Do NOT provide a surname or initials separately for any editor — Citex derives both itself from each full name.\n- year, bookTitle, place and publisher must all describe ONE single, internally consistent edited book as editorFullNames — the publisher must be real.\n"
			: "ONE QUESTION = ONE CANONICAL BIBLIOGRAPHIC RECORD — CRITICAL:\n- authorFullNames is an array of author full name(s) (may be invented — see below). Do NOT provide a surname or initials separately for any author — Citex derives both itself from each full name.\n- year, bookTitle, place and publisher must all describe ONE single, internally consistent book as authorFullNames — the publisher must be real.\n";

		$prompt = "Generate exactly " . count( $ids ) . " distinct Harvard / ReferenceList / " . ( $is_edited_book ? 'Edited Book' : 'Book' ) . " \"Identify the error\" multiple-choice questions.\nDifficulty: " . ucfirst( $difficulty ) . ".\n" . ( $verify ? 'Use Google Search to verify the publisher/journal is real.' : 'Invent a plausible, internally consistent record if needed — the publisher/journal must still be real.' ) . "\n\n"
			. $canonical_intro
			. "\nTHIS QUESTION SHOWS THE STUDENT ONE BROKEN REFERENCE AND ASKS \"What is incorrect about this reference?\" — it is NOT a \"pick the correct reference\" question:\n"
			. '- Provide exactly ONE `brokenReference`: { "reference": "...", "errorReason": "..." } — a reference for the SAME canonical record, built by deliberately applying ONE of the following known Harvard error patterns (correct format: ' . $correct_format . ') — do not invent an unrelated kind of mistake:' . $catalogue . "\n"
			. "- errorReason must name the SPECIFIC rule brokenReference breaks (e.g. \"Missing the editor designation (ed.)\") — never a vague label like \"formatting error\".\n"
			. "- brokenReference must still contain every canonical fact — every $person_noun's surname and initials, the year, title, place and publisher. The ONLY thing wrong with it is the ONE mistake named in errorReason; do not also change or omit any bibliographic fact.\n"
			. "- Provide exactly THREE `wrongDescriptions`: plain-English descriptions of OTHER things that COULD be wrong with a Harvard reference (drawn from the same list of error patterns above, or a similar realistic mistake), but which are NOT actually true of brokenReference. Each must read like a genuine, plausible answer choice — never nonsensical, never obviously wrong on its face.\n"
			. "- wrongDescriptions must be mutually distinct from each other and from errorReason (reworded, not a copy).\n"
			. "- Before finalising: re-read brokenReference end-to-end against the full correct format and confirm errorReason is the ONLY true description of what is wrong with it — none of the three wrongDescriptions may also happen to be true of brokenReference (that would create a second correct answer).\n"
			. "\nFINAL SELF-CHECK — DO NOT SKIP:\n1. " . ucfirst( $person_field ) . ", year, bookTitle, place and publisher all describe the same internally-consistent record — no contradictions.\n2. brokenReference genuinely contains every canonical fact and exactly ONE deliberate mistake, correctly named by errorReason.\n3. Exactly 3 wrongDescriptions are provided, each plausible, mutually distinct, and NOT true of brokenReference.\n4. Only return questions that pass all four checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::conciseness_guidance() . "\n\n" . self::content_realism_guidance() . "\n\n" . self::plain_style_guidance() . "\n\n" . self::place_publisher_diversity_guidance();
		if ( '' !== trim( $scenario_instruction ) ) { $prompt .= "\n\n" . $scenario_instruction; }
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	private static function system_instruction_identify_error( $category ) {
		$is_edited_book = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category;
		$noun            = $is_edited_book ? 'editor' : 'author';
		$plural_field    = $is_edited_book ? 'editorFullNames' : 'authorFullNames';
		return "You are Citex, an academic question-generation engine. Generate usable Harvard ReferenceList \"Identify the error\" multiple-choice questions for practice — invented-but-plausible sources are fine, as long as each question is internally consistent (see below). This is for learning purposes: {$noun}s, titles, years and places may be invented, as long as one question is internally consistent — the publisher must always be real, verify it when web verification is enabled. This question type shows the student ONE deliberately broken reference and asks what is wrong with it — you provide that one broken reference (brokenReference: {reference, errorReason}, built by deliberately applying exactly one named Harvard error pattern to the record described by {$plural_field}/year/bookTitle/place/publisher) plus THREE plausible-but-untrue `wrongDescriptions` of other things that could be wrong but are not actually true of brokenReference. Citex is the sole authority for the correct answer (errorReason itself) and never asks you for it separately — your job is only to make brokenReference genuinely, specifically broken in exactly the one way you claim, and to make the three wrongDescriptions plausible distractors that are demonstrably NOT true of brokenReference when re-read carefully. Before returning each question, perform a strict self-check: brokenReference contains every canonical fact with exactly one deliberate mistake; errorReason names that mistake specifically; and none of the three wrongDescriptions is also true of brokenReference (that would create a second correct answer, which Citex will reject). Return only the requested JSON.";
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
		$prompt = "Generate exactly " . count( $ids ) . " distinct Harvard / ReferenceList \"choose the correct rule\" multiple-choice questions, EVERY ONE of them testing this exact same rule statement (there is no specific book involved at all — this is pure rule knowledge):\n\nTHE TRUE STATEMENT (Citex will use this as the correct answer — you never provide it):\n\"" . $treatment['correctStatement'] . "\"\n\nYour ONLY job for each question is to provide exactly THREE `wrongStatements`: plausible-but-INCORRECT statements about this same rule area (how multiple {$noun}s are referenced), each describing a real, specific Harvard-referencing misconception — drawn from known error patterns such as:$catalogue\n- A wrongStatement must be a genuinely different claim from the true statement above, not a reworded copy of it.\n- Every wrongStatement must be clearly, specifically wrong — never vague, never nonsensical, never trivially obvious.\n- The three must be mutually distinct from each other.\n- KEEP EACH wrongStatement SHORT — state the rule/mistake as a single plain claim. Do NOT include a worked example (no \"e.g.\", no illustrative names like \"Smith, J. and Jones, A.\") — the true statement itself never has one, so an example on a wrongStatement would make it stand out as different.\n\nFINAL SELF-CHECK — DO NOT SKIP:\n1. Exactly 3 wrongStatements are provided.\n2. None of them is a reworded copy of the true statement — each describes a genuinely different (and wrong) claim.\n3. All three are mutually distinct from each other.\n4. None of them includes a worked example or illustrative names — each is a short, plain statement of the rule.\n5. Only return questions that pass all four checks.\n\nIDs in exact order:\n" . implode( ', ', $ids );
		$prompt .= "\n\n" . self::plain_style_guidance();
		if ( '' !== trim( $quality_feedback ) ) { $prompt .= "\n\nIMPORTANT — PREVIOUS ATTEMPT FAILED QUALITY CONTROL:\n" . $quality_feedback . "\nRegenerate the affected data and apply the final self-check before returning anything."; }
		return $prompt;
	}

	private static function system_instruction_choose_treatment( $category ) {
		$noun = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ? 'editor' : 'author';
		return "You are Citex, an academic question-generation engine. Generate usable Harvard \"choose the correct rule\" multiple-choice questions about how multiple {$noun}s are referenced in the Harvard reference list. This question type tests pure rule knowledge, not any specific book — Citex supplies the entire question (the stem and the one true rule statement) itself, so there is no bibliographic record for you to verify, invent, or leak an answer through at all. Your only job is to provide exactly THREE plausible-but-wrong `wrongStatements` about the same rule area, each a genuinely different (and specifically incorrect) claim from the true statement Citex will use as the answer — never a reworded copy of it, never vague, never nonsensical. Keep every wrongStatement short: a single plain claim about the rule, never a worked example with illustrative names. Before returning each question, perform a strict self-check: all three wrongStatements are mutually distinct, each is a clearly wrong (not merely reworded-correct) claim, none of them could be read as another way of stating the true rule, and none of them includes a worked example. Return only the requested JSON.";
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

	/**
	 * Book DragDrop schema — Gemini supplies only the canonical
	 * bibliographic record and a scenario, the same shape
	 * schema_book_mcq_variant() already uses: no `questionParts`,
	 * `fixedText`, or `confusingWords` properties at all —
	 * Citex_Book_Dragdrop_Parts constructs the entire question
	 * deterministically from this record alone.
	 */
	private static function schema_book_dragdrop() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'scenario' => $s, 'authorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'bookTitle' => $s, 'place' => $s, 'publisher' => $s,
		), 'required' => array( 'questionId','scenario','authorFullNames','year','bookTitle','place','publisher' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * Book MCQ schema — Gemini supplies ONLY the canonical bibliographic
	 * record, the same fields DragDrop already asks for. Unlike every other
	 * category's MCQ schema (and unlike this schema before the user's own
	 * 16-variant catalogue replaced the prior Book MCQ mechanic), there is
	 * no `distractors` property at all: Citex_Book_Mcq_Variants::build()
	 * constructs the entire question — stem, all 4 options, and the
	 * answer — deterministically from this record alone.
	 */
	private static function schema_book_mcq_variant() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'authorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'bookTitle' => $s, 'place' => $s, 'publisher' => $s,
		), 'required' => array( 'questionId','authorFullNames','year','bookTitle','place','publisher' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * MLA Book DragDrop schema — same shape as schema_book_dragdrop() but
	 * with no `place` property at all (MLA has none).
	 */
	private static function schema_mla_book_dragdrop() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'scenario' => $s, 'authorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'bookTitle' => $s, 'publisher' => $s,
		), 'required' => array( 'questionId','scenario','authorFullNames','year','bookTitle','publisher' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * MLA Book MCQ schema — mirrors schema_book_mcq_variant() exactly,
	 * minus `place`. No `distractors` property at all —
	 * Citex_MLA_Book_Mcq_Variants::build() constructs the entire question —
	 * stem, all 4 options, and the answer — deterministically from this
	 * record alone.
	 */
	private static function schema_mla_book_mcq_variant() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'authorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'bookTitle' => $s, 'publisher' => $s,
		), 'required' => array( 'questionId','authorFullNames','year','bookTitle','publisher' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * APA Book DragDrop schema — same shape as schema_mla_book_dragdrop()
	 * (no `place`) — the schema itself carries no case-sensitivity
	 * constraint (sentence-case bookTitle is a prompt instruction, not a
	 * schema-enforceable rule).
	 */
	private static function schema_apa_book_dragdrop() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'scenario' => $s, 'authorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'bookTitle' => $s, 'publisher' => $s,
		), 'required' => array( 'questionId','scenario','authorFullNames','year','bookTitle','publisher' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * APA Book MCQ schema — mirrors schema_mla_book_mcq_variant() exactly.
	 * No `distractors` property at all — Citex_APA_Book_Mcq_Variants::build()
	 * constructs the entire question — stem, all 4 options, and the
	 * answer — deterministically from this record alone.
	 */
	private static function schema_apa_book_mcq_variant() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'authorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'bookTitle' => $s, 'publisher' => $s,
		), 'required' => array( 'questionId','authorFullNames','year','bookTitle','publisher' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * MLA Edited Book DragDrop schema — mirrors schema_mla_book_dragdrop()
	 * exactly, via editorFullNames instead.
	 */
	private static function schema_mla_edited_book_dragdrop() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'scenario' => $s, 'editorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'bookTitle' => $s, 'publisher' => $s,
		), 'required' => array( 'questionId','scenario','editorFullNames','year','bookTitle','publisher' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/** MLA Edited Book MCQ schema — mirrors schema_mla_book_mcq_variant() exactly, via editorFullNames instead. */
	private static function schema_mla_edited_book_mcq() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'editorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'bookTitle' => $s, 'publisher' => $s,
		), 'required' => array( 'questionId','editorFullNames','year','bookTitle','publisher' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * MLA Journal Article DragDrop schema — same shape as
	 * schema_mla_book_dragdrop() but with articleTitle/journalTitle/
	 * volume/issue/pages replacing bookTitle/publisher.
	 */
	private static function schema_mla_journal_article_dragdrop() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'scenario' => $s, 'authorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'articleTitle' => $s, 'journalTitle' => $s, 'volume' => $s, 'issue' => $s, 'pages' => $s,
		), 'required' => array( 'questionId','scenario','authorFullNames','year','articleTitle','journalTitle','volume','issue','pages' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/** MLA Journal Article MCQ schema — mirrors schema_mla_journal_article_dragdrop() minus the scenario. */
	private static function schema_mla_journal_article_mcq() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'authorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'articleTitle' => $s, 'journalTitle' => $s, 'volume' => $s, 'issue' => $s, 'pages' => $s,
		), 'required' => array( 'questionId','authorFullNames','year','articleTitle','journalTitle','volume','issue','pages' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * MLA Website DragDrop schema — authorType plus EITHER authorFullName
	 * OR organisationName (same either/or pattern as schema_website(), only
	 * `authorType` itself is in `required`). `year` is deliberately NOT in
	 * `required` at all — real MLA 9 has no "n.d." convention, so an
	 * entirely absent year is a normal, valid value here (unlike Harvard's
	 * schema_website(), which always requires one). There is no
	 * `publisher`/`accessedDate` property at all — see
	 * Citex_AI_V2::normalise_mla_website_dispatch()'s own docblock.
	 */
	private static function schema_mla_website_dragdrop() {
		$s   = array( 'type' => 'string' );
		$url = array( 'type' => 'string', 'maxLength' => 32 );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'scenario' => $s, 'authorType' => $s, 'authorFullName' => $s, 'organisationName' => $s, 'year' => $s, 'title' => $s, 'url' => $url,
		), 'required' => array( 'questionId','scenario','authorType','title','url' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/** MLA Website MCQ schema — mirrors schema_mla_website_dragdrop() minus the scenario. */
	private static function schema_mla_website_mcq() {
		$s   = array( 'type' => 'string' );
		$url = array( 'type' => 'string', 'maxLength' => 32 );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'authorType' => $s, 'authorFullName' => $s, 'organisationName' => $s, 'year' => $s, 'title' => $s, 'url' => $url,
		), 'required' => array( 'questionId','authorType','title','url' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * In-text citation schema — shared by DragDrop and MCQ (both ask for
	 * exactly the same canonical fields, since Citex authors the whole
	 * question — the stem/options — itself either way; see
	 * build_prompt_intext()'s own docblock). No publisher, place, journal,
	 * volume, issue or URL at all — none of those ever appear in an
	 * in-text citation, unlike a reference-list entry.
	 *
	 * Category dimension: Website supplies authorType + EITHER
	 * authorFullName OR organisationName (only authorType is in
	 * `required`, mirroring schema_website()'s own pattern, since which of
	 * the other two is actually required depends on the record's own
	 * authorType value — checked in normalise() instead); every other
	 * category supplies a people-name array under its own established
	 * field name (authorFullNames/editorFullNames) plus its own title
	 * field (bookTitle/articleTitle/pageTitle).
	 *
	 * Style dimension: `year` is requested only for Harvard (MLA in-text
	 * never shows a year at all — see Citex_MLA_Intext_Citation_Rules's
	 * own docblock).
	 *
	 * Form dimension: parenthetical_quote asks for `quote` + `page`
	 * instead of `clause`; MLA's narrative form (for any category except
	 * Website, which has no page concept) also asks for `page`, since MLA
	 * style prefers a page reference whenever one exists, even for a
	 * paraphrase.
	 */
	private static function schema_intext( $category, $style, $form ) {
		$s          = array( 'type' => 'string' );
		$is_website = Citex_Reference_Rules::CATEGORY_WEBSITE === $category;
		$props      = array( 'questionId' => $s );
		$required   = array( 'questionId' );

		if ( $is_website ) {
			$props['authorType']       = $s;
			$props['authorFullName']   = $s;
			$props['organisationName'] = $s;
			$props['pageTitle']        = $s;
			$required[]                = 'authorType';
			$required[]                = 'pageTitle';
		} else {
			$people_field         = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ? 'editorFullNames' : 'authorFullNames';
			$title_field           = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ? 'articleTitle' : 'bookTitle';
			$props[ $people_field ] = array( 'type' => 'array', 'items' => $s );
			$props[ $title_field ]  = $s;
			$required[]             = $people_field;
			$required[]             = $title_field;
		}

		if ( 'harvard' === $style ) {
			$props['year'] = $s;
			$required[]    = 'year';
		}

		if ( Citex_Intext_Citation_Rules::FORM_PARENTHETICAL_QUOTE === $form ) {
			$props['quote'] = $s;
			$props['page']  = $s;
			$required[]     = 'quote';
			$required[]     = 'page';
		} else {
			$props['clause'] = $s;
			$required[]      = 'clause';
			if ( 'mla' === $style && ! $is_website && Citex_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
				$props['page'] = $s;
				$required[]    = 'page';
			}
		}

		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => $props, 'required' => $required ) ) ), 'required' => array( 'questions' ) );
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
	 * fixed stem" principle as schema_book_mcq_variant()/schema_edited_book_mcq().
	 */
	private static function schema_journal_article_mcq() {
		$s = array( 'type' => 'string' );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'authorFullNames' => array( 'type' => 'array', 'items' => $s ), 'year' => $s, 'articleTitle' => $s, 'journalTitle' => $s, 'volume' => $s, 'issue' => $s, 'pages' => $s,
			'distractors' => self::distractor_schema()
		), 'required' => array( 'questionId','authorFullNames','year','articleTitle','journalTitle','volume','issue','pages','distractors' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * Website DragDrop schema — authorType plus EITHER authorFullName OR
	 * organisationName (only 'authorType' itself is in the required list;
	 * normalise() enforces presence of whichever of the other two applies,
	 * since JSON Schema's plain `required` cannot express an either/or
	 * across two properties). publisher IS one of the required canonical
	 * fields (used only to verify the source is real — the built reference
	 * never shows it), and there is deliberately no `accessedDate` property
	 * at all — Gemini is never asked for one. `url` is capped short — a
	 * bare "https://www.xxxxx.tld" domain with no path — see
	 * build_prompt_website()'s own URL-length guidance.
	 */
	private static function schema_website() {
		$s   = array( 'type' => 'string' );
		$url = array( 'type' => 'string', 'maxLength' => 32 );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'scenario' => $s, 'authorType' => $s, 'authorFullName' => $s, 'organisationName' => $s, 'year' => $s, 'title' => $s, 'publisher' => $s, 'url' => $url,
			'questionParts' => array( 'type' => 'array', 'items' => $s ), 'fixedText' => $s, 'confusingWords' => array( 'type' => 'array', 'items' => $s )
		), 'required' => array( 'questionId','scenario','authorType','year','title','publisher','url','confusingWords' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * Website MCQ schema — mirrors schema_book_mcq_variant() exactly:
	 * Gemini supplies ONLY the canonical record (the same fields DragDrop
	 * already asks for). No `distractors` property at all —
	 * Citex_Website_Mcq_Variants::build() constructs the entire question —
	 * stem, all 4 options, and the answer — deterministically from this
	 * record alone.
	 */
	private static function schema_website_mcq_variant() {
		$s   = array( 'type' => 'string' );
		$url = array( 'type' => 'string', 'maxLength' => 32 );
		return array( 'type' => 'object', 'properties' => array( 'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'questionId' => $s, 'authorType' => $s, 'authorFullName' => $s, 'organisationName' => $s, 'year' => $s, 'title' => $s, 'publisher' => $s, 'url' => $url,
		), 'required' => array( 'questionId','authorType','year','title','publisher','url' ) ) ) ), 'required' => array( 'questions' ) );
	}

	/**
	 * Schema for the "Identify the error" MCQ mechanic — one canonical
	 * record, one deliberately broken reference (brokenReference, the same
	 * {reference, errorReason} shape as one distractor_schema() entry), and
	 * three plausible-but-untrue wrongDescriptions. No `scenario` field:
	 * Citex constructs the student-facing question text itself from
	 * brokenReference (see normalise_identify_error_item()), the same
	 * "Citex authors the fixed stem" principle already used for
	 * schema_book_mcq_variant()/schema_edited_book_mcq().
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
	 * Shared shape for one MCQ distractor, used by Edited Book/Journal
	 * Article/Website's MCQ schema (Book no longer uses this at all — its
	 * MCQ questions are built entirely by Citex_Book_Mcq_Variants from the
	 * canonical record, with no Gemini-authored distractors): the wrong
	 * reference text PLUS the single, specific Harvard rule it deliberately
	 * breaks (errorReason). Forcing Gemini to name the rule for every
	 * distractor — rather than just asking for "a wrong reference" — is
	 * what makes it actually reason about which rule it is violating
	 * instead of inventing a superficially-different reference that can
	 * accidentally still be fully valid (see
	 * normalise_edited_book_mcq_item(), which rejects any distractor
	 * missing this reason). errorReason is for Citex's own quality control
	 * only — it is never shown to the student and is not one of the real
	 * WordPress ACF fields.
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
	 * Shared MCQ distractor instructions for Edited Book/Journal Article/
	 * Website (Book no longer uses this — see schema_book_mcq_variant()'s
	 * docblock), driven by Citex_Reference_Rules::mcq_distractor_patterns( $category )
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

	/**
	 * MLA's own counterpart to derive_author_parts() — Citex is the sole
	 * authority for the author's surname here too, but keeps the FULL given
	 * name(s) rather than reducing them to initials (MLA never abbreviates
	 * a first name — see Citex_MLA_Reference_Rules's own docblock).
	 *
	 * @return array{surname: string, givenName: string}|WP_Error
	 */
	private static function derive_mla_author_parts( $full_name ) {
		$full_name = trim( preg_replace( '/\s+/', ' ', (string) $full_name ) );
		$tokens = '' === $full_name ? array() : array_values( array_filter( explode( ' ', $full_name ), 'strlen' ) );
		if ( count( $tokens ) < 2 ) {
			return new WP_Error( 'citex_ai_incomplete_author_name', __( 'Author full name must include at least one given name and a surname.', 'citex-tools' ) );
		}
		$surname = array_pop( $tokens );
		return array( 'surname' => $surname, 'givenName' => implode( ' ', $tokens ) );
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
	/**
	 * "by First Last" / "by First Last and First Last" / "by First Last,
	 * First Last and First Last" — the natural-language author-list used
	 * ONLY in a DragDrop in-text-citation stem to introduce the source
	 * (see intext_dragdrop_stem()); genuinely different from
	 * Citex_Intext_Citation_Rules::join_people_intext() (which joins
	 * SURNAMES with the style's own "et al." rule for the graded blanks
	 * themselves) — the stem must name every person in full, naturally,
	 * regardless of how the citation itself abbreviates them.
	 */
	private static function intext_full_names_display( array $full_names ) {
		$count = count( $full_names );
		if ( 0 === $count ) {
			return '';
		}
		if ( 1 === $count ) {
			return $full_names[0];
		}
		$last = array_pop( $full_names );
		return implode( ', ', $full_names ) . ' and ' . $last;
	}

	/**
	 * The Citex-authored DragDrop stem — "Complete the {form} in-text
	 * citation for a {paraphrase|direct quotation} from the {category}
	 * {Title} by {Author(s)}[, published in {Year}]." — matches the
	 * established pattern of every other DragDrop mechanic (Citex states
	 * the facts naturally; the student applies the FORMATTING rule to
	 * them). $year is null/empty for MLA (which never shows a year at
	 * all, so stating one in the stem would be misleading).
	 */
	private static function intext_dragdrop_stem( $form, $category_noun, $title, $who_display, $year ) {
		$kind = Citex_Intext_Citation_Rules::FORM_PARENTHETICAL_QUOTE === $form ? 'direct quotation' : 'paraphrase';
		$form_label = Citex_Intext_Citation_Rules::FORM_NARRATIVE === $form ? 'narrative' : 'parenthetical';
		$published = ( null !== $year && '' !== trim( (string) $year ) ) ? sprintf( ', published in %s', $year ) : '';
		return sprintf( 'Complete the %1$s in-text citation for a %2$s from the %3$s %4$s by %5$s%6$s.', $form_label, $kind, $category_noun, $title, $who_display, $published );
	}

	/**
	 * Resolves the category-appropriate person list (or Website's single
	 * individual-or-organisation author) and every other in-text-specific
	 * field (clause, or quote+page) from Gemini's canonical record, then
	 * dispatches to the style-appropriate leaf normaliser. This is the
	 * ONE place that understands "which field holds the who" per
	 * category — every downstream piece (Citex_Intext_Citation_Rules and
	 * its Dragdrop/Mcq classes, and their MLA counterparts) is
	 * category-agnostic, working only from the resolved person list/
	 * surnames/who string this method builds.
	 *
	 * @return array|WP_Error
	 */
	private static function normalise_intext_item( $item, $id, $category, $style, $form, $type, $exercise, $difficulty, $target_count ) {
		$is_website = Citex_Reference_Rules::CATEGORY_WEBSITE === $category;
		$is_edited_book = Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category;
		$is_mla = 'mla' === $style;
		$derive = $is_mla ? 'derive_mla_author_parts' : 'derive_author_parts';

		$ctx = array( 'category' => $category, 'form' => $form, 'author_record' => null );

		if ( $is_website ) {
			$author_type = sanitize_key( trim( (string) ( $item['authorType'] ?? '' ) ) );
			if ( ! in_array( $author_type, array( 'individual', 'organisation' ), true ) ) {
				return new WP_Error( 'citex_ai_website_author_type_invalid', sprintf( __( 'Question %s: authorType must be exactly "individual" or "organisation".', 'citex-tools' ), $id ) );
			}
			if ( 'individual' === $author_type ) {
				$full_name = trim( (string) ( $item['authorFullName'] ?? '' ) );
				if ( '' === $full_name ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing authorFullName for an individual author.', 'citex-tools' ), $id ) ); }
				$parts = self::$derive( $full_name );
				if ( is_wp_error( $parts ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %1$s: %2$s', 'citex-tools' ), $id, $parts->get_error_message() ) ); }
				$author_record = array_merge( array( 'type' => 'individual', 'fullName' => $full_name ), $parts );
				$surnames = array( $parts['surname'] );
			} else {
				$org_name = trim( (string) ( $item['organisationName'] ?? '' ) );
				if ( '' === $org_name ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing organisationName for an organisation author.', 'citex-tools' ), $id ) ); }
				$author_record = array( 'type' => 'organisation', 'name' => $org_name );
				$surnames = array( $org_name );
			}
			$ctx['author_record'] = $author_record;
			$ctx['surnames'] = $surnames;
			$ctx['who'] = $is_mla ? Citex_MLA_Intext_Citation_Rules::display_person_or_org( $author_record ) : Citex_Intext_Citation_Rules::display_person_or_org( $author_record );
			$ctx['who_display'] = 'organisation' === $author_type ? $author_record['name'] : $author_record['fullName'];
			$ctx['title'] = trim( (string) ( $item['pageTitle'] ?? '' ) );
			if ( '' === $ctx['title'] ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing pageTitle.', 'citex-tools' ), $id ) ); }
			if ( ! $is_mla ) {
				$year_field = trim( (string) ( $item['year'] ?? '' ) );
				if ( ! preg_match( '/^(?:\d{4}|n\.d\.)$/', $year_field ) ) {
					return new WP_Error( 'citex_ai_website_year_invalid', sprintf( __( 'Question %1$s: year must be a real 4-digit year, or exactly "n.d." when no date can be identified; got "%2$s".', 'citex-tools' ), $id, $year_field ) );
				}
				$ctx['year'] = $year_field;
			} else {
				$ctx['year'] = '';
			}
			$ctx['page'] = ''; // Website never has a page reference at all.
		} else {
			$people_key   = $is_edited_book ? 'editors' : 'authors';
			$people_field = $is_edited_book ? 'editorFullNames' : 'authorFullNames';
			$label        = $is_edited_book ? 'editors' : 'authors';
			$title_field  = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ? 'articleTitle' : 'bookTitle';

			$person_names = array_values( array_filter( array_map( 'trim', (array) ( $item[ $people_field ] ?? array() ) ), 'strlen' ) );
			if ( empty( $person_names ) || count( $person_names ) > 12 ) {
				return new WP_Error( 'citex_ai_bad_author_count', sprintf( __( 'Question %s must have 1 or more %2$s (12 at most); %3$d were provided.', 'citex-tools' ), $id, $label, count( $person_names ) ) );
			}
			if ( null !== $target_count && count( $person_names ) !== $target_count ) {
				return new WP_Error( 'citex_ai_author_count_mismatch', sprintf( __( 'Question %1$s must have exactly %2$d %3$s for this scenario; %4$d were provided.', 'citex-tools' ), $id, $target_count, $label, count( $person_names ) ) );
			}
			$people    = array();
			$surnames  = array();
			foreach ( $person_names as $full_name ) {
				$parts = self::$derive( $full_name );
				if ( is_wp_error( $parts ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %1$s: %2$s', 'citex-tools' ), $id, $parts->get_error_message() ) ); }
				$people[]   = array_merge( array( 'fullName' => $full_name ), $parts );
				$surnames[] = $parts['surname'];
			}
			$ctx['people_key']  = $people_key;
			$ctx['people']      = $people;
			$ctx['surnames']    = $surnames;
			$ctx['who']         = $is_mla ? Citex_MLA_Intext_Citation_Rules::join_people_intext( $people ) : Citex_Intext_Citation_Rules::join_people_intext( $people );
			$ctx['who_display'] = self::intext_full_names_display( $person_names );
			$ctx['title']       = trim( (string) ( $item[ $title_field ] ?? '' ) );
			if ( '' === $ctx['title'] ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing %2$s.', 'citex-tools' ), $id, $title_field ) ); }
			if ( ! $is_mla ) {
				$ctx['year'] = trim( (string) ( $item['year'] ?? '' ) );
				if ( '' === $ctx['year'] ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing a year.', 'citex-tools' ), $id ) ); }
			} else {
				$ctx['year'] = '';
			}
			$ctx['page'] = ''; // filled below, when this style/form actually uses one.
		}

		if ( Citex_Intext_Citation_Rules::FORM_PARENTHETICAL_QUOTE === $form ) {
			$ctx['quote'] = trim( (string) ( $item['quote'] ?? '' ) );
			$ctx['page']  = trim( (string) ( $item['page'] ?? '' ) );
			if ( '' === $ctx['quote'] || '' === $ctx['page'] ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing quote or page for a direct-quote in-text citation.', 'citex-tools' ), $id ) ); }
			if ( ! preg_match( '/^\d+$/', $ctx['page'] ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s: page must be a plain number.', 'citex-tools' ), $id ) ); }
			$ctx['clause'] = '';
		} else {
			$ctx['clause'] = trim( (string) ( $item['clause'] ?? '' ) );
			$ctx['quote']  = '';
			if ( '' === $ctx['clause'] ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing a clause for its in-text citation paraphrase.', 'citex-tools' ), $id ) ); }
			// MLA's narrative form (any category except Website, which has
			// no page concept at all) always carries a page — see
			// Citex_MLA_Intext_Dragdrop_Parts's own docblock for why a
			// page-less narrative is otherwise too thin a DragDrop question.
			if ( $is_mla && ! $is_website && Citex_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
				$page = trim( (string) ( $item['page'] ?? '' ) );
				if ( '' === $page || ! preg_match( '/^\d+$/', $page ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing a valid page for its MLA narrative in-text citation.', 'citex-tools' ), $id ) ); }
				$ctx['page'] = $page;
			}
		}

		$category_noun = self::intext_category_noun( $category );
		if ( 'MCQ' === $type ) {
			$ctx['scenario'] = $is_mla ? Citex_MLA_Intext_Citation_Rules::mcq_question_stem( $form ) : Citex_Intext_Citation_Rules::mcq_question_stem( $form );
		} else {
			$ctx['scenario'] = self::intext_dragdrop_stem( $form, $category_noun, $ctx['title'], $ctx['who_display'], $is_mla ? null : $ctx['year'] );
		}

		if ( 'MCQ' === $type ) {
			return $is_mla
				? self::normalise_mla_intext_mcq_item( $item, $id, $ctx, $exercise, $difficulty )
				: self::normalise_intext_mcq_item( $item, $id, $ctx, $exercise, $difficulty );
		}
		return $is_mla
			? self::normalise_mla_intext_dragdrop_item( $item, $id, $ctx, $exercise, $difficulty )
			: self::normalise_intext_dragdrop_item( $item, $id, $ctx, $exercise, $difficulty );
	}

	/**
	 * Fields common to every in-text citation record, regardless of
	 * style/form/category/type — folded into each leaf normaliser's own
	 * return array via array_merge() so the category/person-list fields
	 * (which DO vary in shape) are never duplicated here.
	 */
	private static function intext_common_fields( $id, array $ctx, $exercise, $difficulty ) {
		$fields = array(
			'exercise'     => $exercise,
			'category'     => $ctx['category'],
			'citationForm' => $ctx['form'],
			'difficulty'   => ucfirst( $difficulty ),
			'scenario'     => sanitize_textarea_field( $ctx['scenario'] ),
			'clause'       => sanitize_text_field( $ctx['clause'] ),
			'quote'        => sanitize_text_field( $ctx['quote'] ),
			'page'         => sanitize_text_field( $ctx['page'] ),
			'year'         => sanitize_text_field( $ctx['year'] ),
			'status'       => 'pending',
			'validationStatus' => 'not_validated',
			'validationErrors' => array(),
			'origin'       => 'generated_ai',
			'aiProvider'   => 'Gemini',
			'aiModel'      => self::get_model(),
			'generatedAt'  => gmdate( 'c' ),
		);
		if ( null !== $ctx['author_record'] ) {
			$fields['authorType'] = $ctx['author_record']['type'];
			$fields['authors']    = 'individual' === $ctx['author_record']['type'] ? array( $ctx['author_record'] ) : array();
			$fields['organisationName'] = 'organisation' === $ctx['author_record']['type'] ? $ctx['author_record']['name'] : '';
			$fields['pageTitle'] = sanitize_text_field( $ctx['title'] );
		} else {
			$fields[ $ctx['people_key'] ] = $ctx['people'];
			$fields['bookTitle'] = Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $ctx['category'] ? '' : sanitize_text_field( $ctx['title'] );
			if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $ctx['category'] ) {
				$fields['articleTitle'] = sanitize_text_field( $ctx['title'] );
			}
		}
		return $fields;
	}

	/**
	 * Harvard in-text citation DragDrop candidate — builds every draggable
	 * blank via Citex_Intext_Dragdrop_Parts::build() (always ALL blanks,
	 * never a subset — see that class's own docblock) and the matching
	 * full-sentence "reconstructedReference" via
	 * Citex_Intext_Citation_Rules' own narrative_sentence()/
	 * parenthetical_sentence()/parenthetical_quote_sentence().
	 */
	private static function normalise_intext_dragdrop_item( $item, $id, array $ctx, $exercise, $difficulty ) {
		$form = $ctx['form'];
		$built = Citex_Intext_Dragdrop_Parts::build( $form, $ctx['who'], $ctx['surnames'], $ctx['year'], $ctx['clause'], $ctx['page'], $ctx['quote'] );
		if ( null === $built ) { return new WP_Error( 'citex_ai_intext_build_failed', sprintf( __( 'Question %s could not be built.', 'citex-tools' ), $id ) ); }

		if ( Citex_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
			$reference = Citex_Intext_Citation_Rules::narrative_sentence( $ctx['who'], $ctx['year'], $ctx['clause'] );
		} elseif ( Citex_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
			$reference = Citex_Intext_Citation_Rules::parenthetical_sentence( $ctx['who'], $ctx['year'], $ctx['clause'] );
		} else {
			$reference = Citex_Intext_Citation_Rules::parenthetical_quote_sentence( $ctx['who'], $ctx['year'], $ctx['page'], $ctx['quote'] );
		}

		$tokens = Citex_Intext_Dragdrop_Parts::build_tokens( $form, $ctx['who'], $ctx['year'], $ctx['clause'], $ctx['page'], $ctx['quote'] );
		$part_kinds = array_values( array_filter( array_map( function ( $t ) { return $t['literal'] ? null : $t['kind']; }, $tokens ) ) );

		return array_merge(
			self::intext_common_fields( $id, $ctx, $exercise, $difficulty ),
			array(
				'key'        => wp_generate_uuid4(),
				'questionId' => $id,
				'title'      => sprintf( 'Harvard | InTextCitation | %s | DragDrop | %s', $ctx['category'], $id ),
				'source'     => 'Harvard',
				'group'      => 'InTextCitation',
				'type'       => 'DragDrop',
				'dragdropPartKeys' => array_values( array_map( 'sanitize_key', $part_kinds ) ),
				'fixedText'  => sanitize_text_field( $built['fixedText'] ),
				'questionParts' => array_values( array_map( 'sanitize_text_field', $built['parts'] ) ),
				'confusingWords' => array_values( array_map( 'sanitize_text_field', $built['confusingWords'] ) ),
				'reconstructedReference' => sanitize_text_field( $reference ),
			)
		);
	}

	/**
	 * Harvard in-text citation MCQ candidate — the entire question (stem,
	 * all 4 options, and the answer) is built deterministically by
	 * Citex_Intext_Mcq_Variants::build(), exactly like every other
	 * *_mcq_variant mechanic.
	 */
	private static function normalise_intext_mcq_item( $item, $id, array $ctx, $exercise, $difficulty ) {
		$form = $ctx['form'];
		$fields = array(
			'form'     => $form,
			'who'      => $ctx['who'],
			'surnames' => $ctx['surnames'],
			'year'     => $ctx['year'],
			'clause'   => $ctx['clause'],
			'page'     => $ctx['page'],
			'quote'    => $ctx['quote'],
		);
		$variant_seed  = $id;
		$author_count  = count( $ctx['surnames'] );
		$variant       = Citex_Intext_Mcq_Variants::variant_for( $variant_seed, $form, $author_count );
		$built         = Citex_Intext_Mcq_Variants::build( $variant, $fields );
		if ( null === $built ) { return new WP_Error( 'citex_ai_intext_build_failed', sprintf( __( 'Question %s could not be built for variant "%2$s".', 'citex-tools' ), $id, $variant ) ); }

		return array_merge(
			self::intext_common_fields( $id, $ctx, $exercise, $difficulty ),
			array(
				'key'        => wp_generate_uuid4(),
				'questionId' => $id,
				'title'      => sprintf( 'Harvard | InTextCitation | %s | MCQ | %s', $ctx['category'], $id ),
				'source'     => 'Harvard',
				'group'      => 'InTextCitation',
				'type'       => 'MCQ',
				'mcqPattern' => 'intext_mcq_variant',
				'intextMcqVariant' => sanitize_key( $variant ),
				'scenario'   => sanitize_textarea_field( $built['stem'] ),
				'options'    => array_values( array_map( 'sanitize_text_field', array_merge( $built['wrongOptions'], array( '' ) ) ) ),
				'hint'       => sanitize_textarea_field( Citex_Intext_Citation_Rules::mcq_hint( $form ) ),
				'reconstructedReference' => sanitize_text_field( $built['correctAnswer'] ),
			)
		);
	}

	/**
	 * MLA in-text citation DragDrop candidate — mirrors
	 * normalise_intext_dragdrop_item() exactly, via
	 * Citex_MLA_Intext_Dragdrop_Parts/Citex_MLA_Intext_Citation_Rules
	 * instead.
	 */
	private static function normalise_mla_intext_dragdrop_item( $item, $id, array $ctx, $exercise, $difficulty ) {
		$form = $ctx['form'];
		$built = Citex_MLA_Intext_Dragdrop_Parts::build( $form, $ctx['who'], $ctx['surnames'], $ctx['clause'], $ctx['page'], $ctx['quote'] );
		if ( null === $built ) { return new WP_Error( 'citex_ai_intext_build_failed', sprintf( __( 'Question %s could not be built.', 'citex-tools' ), $id ) ); }

		if ( Citex_MLA_Intext_Citation_Rules::FORM_NARRATIVE === $form ) {
			$reference = Citex_MLA_Intext_Citation_Rules::narrative_sentence( $ctx['who'], $ctx['clause'], '' === $ctx['page'] ? null : $ctx['page'] );
		} elseif ( Citex_MLA_Intext_Citation_Rules::FORM_PARENTHETICAL === $form ) {
			$reference = Citex_MLA_Intext_Citation_Rules::parenthetical_sentence( $ctx['who'], $ctx['clause'] );
		} else {
			$reference = Citex_MLA_Intext_Citation_Rules::parenthetical_quote_sentence( $ctx['who'], $ctx['page'], $ctx['quote'] );
		}

		$tokens = Citex_MLA_Intext_Dragdrop_Parts::build_tokens( $form, $ctx['who'], $ctx['clause'], $ctx['page'], $ctx['quote'] );
		$part_kinds = array_values( array_filter( array_map( function ( $t ) { return $t['literal'] ? null : $t['kind']; }, $tokens ) ) );

		return array_merge(
			self::intext_common_fields( $id, $ctx, $exercise, $difficulty ),
			array(
				'key'        => wp_generate_uuid4(),
				'questionId' => $id,
				'title'      => sprintf( 'MLA | InTextCitation | %s | DragDrop | %s', $ctx['category'], $id ),
				'source'     => 'MLA',
				'group'      => 'InTextCitation',
				'type'       => 'DragDrop',
				'dragdropPartKeys' => array_values( array_map( 'sanitize_key', $part_kinds ) ),
				'fixedText'  => sanitize_text_field( $built['fixedText'] ),
				'questionParts' => array_values( array_map( 'sanitize_text_field', $built['parts'] ) ),
				'confusingWords' => array_values( array_map( 'sanitize_text_field', $built['confusingWords'] ) ),
				'reconstructedReference' => sanitize_text_field( $reference ),
			)
		);
	}

	/**
	 * MLA in-text citation MCQ candidate — mirrors
	 * normalise_intext_mcq_item() exactly, via
	 * Citex_MLA_Intext_Mcq_Variants instead.
	 */
	private static function normalise_mla_intext_mcq_item( $item, $id, array $ctx, $exercise, $difficulty ) {
		$form = $ctx['form'];
		$fields = array(
			'form'     => $form,
			'who'      => $ctx['who'],
			'surnames' => $ctx['surnames'],
			'clause'   => $ctx['clause'],
			'page'     => $ctx['page'],
			'quote'    => $ctx['quote'],
		);
		$variant_seed = $id;
		$author_count = count( $ctx['surnames'] );
		$variant      = Citex_MLA_Intext_Mcq_Variants::variant_for( $variant_seed, $form, $author_count );
		$built        = Citex_MLA_Intext_Mcq_Variants::build( $variant, $fields );
		if ( null === $built ) { return new WP_Error( 'citex_ai_intext_build_failed', sprintf( __( 'Question %s could not be built for variant "%2$s".', 'citex-tools' ), $id, $variant ) ); }

		return array_merge(
			self::intext_common_fields( $id, $ctx, $exercise, $difficulty ),
			array(
				'key'        => wp_generate_uuid4(),
				'questionId' => $id,
				'title'      => sprintf( 'MLA | InTextCitation | %s | MCQ | %s', $ctx['category'], $id ),
				'source'     => 'MLA',
				'group'      => 'InTextCitation',
				'type'       => 'MCQ',
				'mcqPattern' => 'mla_intext_mcq_variant',
				'mlaIntextMcqVariant' => sanitize_key( $variant ),
				'scenario'   => sanitize_textarea_field( $built['stem'] ),
				'options'    => array_values( array_map( 'sanitize_text_field', array_merge( $built['wrongOptions'], array( '' ) ) ) ),
				'hint'       => sanitize_textarea_field( Citex_MLA_Intext_Citation_Rules::mcq_hint( $form ) ),
				'reconstructedReference' => sanitize_text_field( $built['correctAnswer'] ),
			)
		);
	}

	private static function normalise( $questions, $ids, $difficulty, $exercises = array(), $type = 'DragDrop', $category = null, $target_count = null, $scenario_id = '', $rule_tested = '', $exercise_design = 'full_reference', $style = 'harvard', $group = 'referencelist', $citation_form = '' ) {
		$category = $category ?: Citex_Reference_Rules::CATEGORY_BOOK;
		$out = array();
		foreach ( $questions as $i => $item ) {
			if ( ! is_array( $item ) ) { return new WP_Error( 'citex_ai_bad_question', sprintf( __( 'Question %d was not a valid object.', 'citex-tools' ), $i + 1 ) ); }
			$id = strtoupper( trim( (string) $ids[ $i ] ) ); $year = trim( (string) ( $item['year'] ?? '' ) ); $title = trim( (string) ( $item['bookTitle'] ?? '' ) ); $place = trim( (string) ( $item['place'] ?? '' ) ); $publisher = trim( (string) ( $item['publisher'] ?? '' ) );

			// Exercise is Citex-assigned only — resolved by slot index from the
			// matrix built before generation began, never read from $item.
			$exercise = isset( $exercises[ $i ] ) ? sanitize_text_field( (string) $exercises[ $i ] ) : 'Exercise 1';

			if ( 'intext' === $group ) {
				// In-text citation is a fundamentally different data shape
				// (no place/publisher/journal/volume/issue at all, and its
				// own stem is built entirely differently for DragDrop vs
				// MCQ) — see normalise_intext_item()'s own docblock. Routed
				// first, before every reference-list-only branch below,
				// since $group is an independent dimension from $category.
				$candidate = self::normalise_intext_item( $item, $id, $category, $style, $citation_form, $type, $exercise, $difficulty, $target_count );
				if ( is_wp_error( $candidate ) ) { return $candidate; }
				$candidate['blueprint'] = array(
					'category'     => $category,
					'questionType' => $type,
					'scenario'     => $scenario_id,
					'ruleTested'   => $rule_tested,
					'difficulty'   => ucfirst( $difficulty ),
				);
				$validation = Citex_Generated_Validator::validate( $candidate );
				if ( 'passed' !== $validation['status'] ) {
					$first_error = ! empty( $validation['errors'][0]['message'] ) ? $validation['errors'][0]['message'] : __( 'Generated question failed Citex validation.', 'citex-tools' );
					$rejection = self::quality_reject( 'citex_ai_validator_rejected', sprintf( __( 'Question %s failed the pre-queue quality gate: %s', 'citex-tools' ), $id, $first_error ) );
					if ( $rejection ) { return $rejection; }
					$candidate['validatedReference'] = $validation['reconstructedReference'];
					$candidate['validationStatus'] = $validation['status'];
					$candidate['validationErrors'] = $validation['errors'];
					$candidate['validatedAt'] = $validation['validatedAt'];
				} else {
					$candidate['validatedReference'] = $validation['reconstructedReference'];
					$candidate['validationStatus'] = 'passed';
					$candidate['validationErrors'] = array();
					$candidate['validatedAt'] = $validation['validatedAt'];
				}
				$out[] = $candidate;
				continue;
			}

			// MCQ's question text is Citex's own fixed, category-specific,
			// non-revealing stem — never Gemini's own per-book "scenario"
			// prose (which risked leaking the answer, and made the question
			// itself explain what it was testing instead of just posing a
			// referencing problem). DragDrop is unaffected: its scenario
			// still describes the specific book, exactly as before, since a
			// DragDrop student needs those facts to construct the reference.
			// $exercise_design only affects Journal Article's own stem
			// lookup (see mcq_question_stem()'s docblock); every other
			// category ignores the second argument entirely.
			$scenario = 'MCQ' === $type
				? Citex_Reference_Rules::mcq_question_stem( $category, $exercise_design )
				: trim( (string) ( $item['scenario'] ?? '' ) );

			if ( 'mla' === $style ) {
				// MLA is dispatched FIRST, before any Harvard-only
				// scenario_id-based routing (choose_treatment_*/
				// identify_error) or category branch below — $style is an
				// independent dimension from $category and from
				// $scenario_id (both dimensions reuse the SAME scenario
				// catalogue as Harvard — see Citex_Question_Scenarios's own
				// "reuse, not new buckets" docblock — so an Edited Book
				// batch assigned e.g. "identify_error" must still be built
				// through MLA's own rules, never silently fall through to
				// Harvard's identify_error mechanic). MLA never uses that
				// mechanic at all: each category's own curated
				// *_Mcq_Variants catalogue already includes its own
				// 'identify_the_error' variant, covering the same ground.
				$candidate = self::normalise_mla_item( $item, $id, $category, $type, $exercise, $difficulty, $target_count );
			} elseif ( 'apa' === $style ) {
				// Same rationale as the MLA branch above — dispatched before
				// any Harvard-only scenario_id-based routing. Phase 1: Book
				// only (Citex_Generator restricts $category to 'book'
				// whenever style is 'apa').
				$candidate = self::normalise_apa_item( $item, $id, $category, $type, $exercise, $difficulty, $target_count );
			} elseif ( 'MCQ' === $type && 0 === strpos( (string) $scenario_id, 'choose_treatment_' ) ) {
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
					: self::normalise_edited_book_item( $item, $id, $editors, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty );
			} elseif ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
				$article_title = trim( (string) ( $item['articleTitle'] ?? '' ) );
				$journal_title = trim( (string) ( $item['journalTitle'] ?? '' ) );
				$volume        = trim( (string) ( $item['volume'] ?? '' ) );
				$issue         = trim( (string) ( $item['issue'] ?? '' ) );
				$pages         = trim( (string) ( $item['pages'] ?? '' ) );
				if ( '' === $scenario || '' === $year || '' === $article_title || '' === $journal_title || '' === $volume || '' === $issue || '' === $pages ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing required bibliographic data.', 'citex-tools' ), $id ) ); }
				// Harvard's reference-list rule (confirmed, identical to
				// Book): a Journal Article can have any real author count, all
				// always listed in full — see build_journal_article_reference()'s
				// docblock. The upper bound here is a pure sanity guard against
				// garbled model output, not a Harvard rule.
				$author_names = array_values( array_filter( array_map( 'trim', (array) ( $item['authorFullNames'] ?? array() ) ), 'strlen' ) );
				if ( empty( $author_names ) || count( $author_names ) > 12 ) { return new WP_Error( 'citex_ai_bad_author_count', sprintf( __( 'Question %s must have 1 or more authors (12 at most); %d were provided.', 'citex-tools' ), $id, count( $author_names ) ) ); }
				if ( null !== $target_count && count( $author_names ) !== $target_count ) { return new WP_Error( 'citex_ai_author_count_mismatch', sprintf( __( 'Question %1$s must have exactly %2$d authors for this scenario; %3$d were provided.', 'citex-tools' ), $id, $target_count, count( $author_names ) ) ); }
				// Some exercise designs need a real author count within a
				// specific range regardless of what target_count enforcement
				// above already checked (defence in depth — target_count is
				// only set when a scenario was actually assigned; a direct
				// caller outside Citex_Generator's loop could otherwise slip
				// past it) — see Citex_Reference_Rules::journal_article_design_author_bounds().
				$author_bounds = Citex_Reference_Rules::journal_article_design_author_bounds( $exercise_design );
				if ( null !== $author_bounds && ( count( $author_names ) < $author_bounds[0] || count( $author_names ) > $author_bounds[1] ) ) {
					return new WP_Error( 'citex_ai_author_count_mismatch', sprintf( __( 'Question %1$s: the "%2$s" exercise design requires between %3$d and %4$d authors; %5$d were provided.', 'citex-tools' ), $id, $exercise_design, $author_bounds[0], $author_bounds[1], count( $author_names ) ) );
				}
				$authors = array();
				foreach ( $author_names as $author_full_name ) {
					$author_parts = self::derive_author_parts( $author_full_name );
					if ( is_wp_error( $author_parts ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %1$s: %2$s', 'citex-tools' ), $id, $author_parts->get_error_message() ) ); }
					$authors[] = array( 'fullName' => $author_full_name, 'surname' => $author_parts['surname'], 'initials' => $author_parts['initials'] );
				}
				$candidate = 'MCQ' === $type
					? self::normalise_journal_article_mcq_item( $item, $id, $authors, $year, $article_title, $journal_title, $volume, $issue, $pages, $scenario, $exercise, $difficulty, $exercise_design )
					: self::normalise_journal_article_item( $item, $id, $authors, $year, $article_title, $journal_title, $volume, $issue, $pages, $scenario, $exercise, $difficulty );
			} elseif ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
				$author_type = sanitize_key( trim( (string) ( $item['authorType'] ?? '' ) ) );
				if ( ! in_array( $author_type, array( 'individual', 'organisation' ), true ) ) {
					return new WP_Error( 'citex_ai_website_author_type_invalid', sprintf( __( 'Question %s: authorType must be exactly "individual" or "organisation".', 'citex-tools' ), $id ) );
				}
				$page_title = trim( (string) ( $item['title'] ?? '' ) );
				$publisher  = trim( (string) ( $item['publisher'] ?? '' ) );
				$url        = trim( (string) ( $item['url'] ?? '' ) );
				$year_field = trim( (string) ( $item['year'] ?? '' ) );
				if ( '' === $scenario || '' === $page_title || '' === $publisher || '' === $url || '' === $year_field ) {
					return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing required bibliographic data.', 'citex-tools' ), $id ) );
				}
				// Harvard's website rule: a real 4-digit publication/
				// creation year, or exactly the literal "n.d." when no such
				// date can be identified for the real source — never a
				// guessed year, and never "n.d." for a source that does have
				// an identifiable date (see build_website_reference()'s
				// docblock).
				if ( ! preg_match( '/^(?:\d{4}|n\.d\.)$/', $year_field ) ) {
					return new WP_Error( 'citex_ai_website_year_invalid', sprintf( __( 'Question %1$s: year must be a real 4-digit year, or exactly "n.d." when no date can be identified; got "%2$s".', 'citex-tools' ), $id, $year_field ) );
				}
				if ( ! preg_match( '#^https?://\S+$#', $url ) ) {
					return new WP_Error( 'citex_ai_website_url_malformed', sprintf( __( 'Question %s has a malformed URL.', 'citex-tools' ), $id ) );
				}
				// Enforce the assigned scenario bucket's author-type/dated-ness
				// — the same "trust but verify" pattern as Book's author-count
				// enforcement, just parsed from the bucket id string (see
				// Citex_Question_Scenarios::website_buckets()).
				if ( false !== strpos( (string) $scenario_id, 'individual_author' ) && 'individual' !== $author_type ) {
					return new WP_Error( 'citex_ai_website_author_type_mismatch', sprintf( __( 'Question %s must use an individual author for this scenario.', 'citex-tools' ), $id ) );
				}
				if ( false !== strpos( (string) $scenario_id, 'organisation_author' ) && 'organisation' !== $author_type ) {
					return new WP_Error( 'citex_ai_website_author_type_mismatch', sprintf( __( 'Question %s must use an organisation author for this scenario.', 'citex-tools' ), $id ) );
				}
				$is_undated = 'n.d.' === $year_field;
				if ( false !== strpos( (string) $scenario_id, '_dated' ) && $is_undated ) {
					return new WP_Error( 'citex_ai_website_date_mismatch', sprintf( __( 'Question %s must use a dated source (a real year) for this scenario, not "n.d.".', 'citex-tools' ), $id ) );
				}
				if ( false !== strpos( (string) $scenario_id, 'undated' ) && ! $is_undated ) {
					return new WP_Error( 'citex_ai_website_date_mismatch', sprintf( __( 'Question %s must use an undated source ("n.d.") for this scenario, not a real year.', 'citex-tools' ), $id ) );
				}

				$author = array( 'type' => $author_type );
				if ( 'individual' === $author_type ) {
					$author_full_name = trim( (string) ( $item['authorFullName'] ?? '' ) );
					if ( '' === $author_full_name ) {
						return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing authorFullName for an individual author.', 'citex-tools' ), $id ) );
					}
					$author_parts = self::derive_author_parts( $author_full_name );
					if ( is_wp_error( $author_parts ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %1$s: %2$s', 'citex-tools' ), $id, $author_parts->get_error_message() ) ); }
					$author['fullName'] = $author_full_name;
					$author['surname']  = $author_parts['surname'];
					$author['initials'] = $author_parts['initials'];
				} else {
					$organisation_name = trim( (string) ( $item['organisationName'] ?? '' ) );
					if ( '' === $organisation_name ) {
						return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing organisationName for an organisation author.', 'citex-tools' ), $id ) );
					}
					$author['name'] = $organisation_name;
				}

				$candidate = 'MCQ' === $type
					? self::normalise_website_mcq_variant_item( $item, $id, $author, $year_field, $page_title, $publisher, $url, $exercise, $difficulty )
					: self::normalise_website_item( $item, $id, $author, $year_field, $page_title, $publisher, $url, $scenario, $exercise, $difficulty );
			} else {
				if ( '' === $scenario || '' === $year || '' === $title || '' === $place || '' === $publisher ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing required bibliographic data.', 'citex-tools' ), $id ) ); }
				// Harvard's reference-list rule (confirmed): a Book can
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
					? self::normalise_book_mcq_variant_item( $item, $id, $authors, $year, $title, $place, $publisher, $exercise, $difficulty )
					: self::normalise_book_dragdrop_item( $item, $id, $authors, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty );
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

			// Citex_Generated_Validator::validate() always runs and its real
			// result is always recorded onto the candidate (so Pending shows an
			// accurate Passed/Failed badge immediately, and the existing manual
			// "Validate"/"Revalidate" mechanism has nothing new to do for a
			// question already validated here) — but a failure only blocks
			// storage while QUALITY_GATE_ENABLED is true. This sprint's
			// GENERATE -> NORMALISE -> STORE pipeline stores every structurally
			// valid candidate and lets validation be corrected later, not a
			// precondition for being saved at all.
			$validation = Citex_Generated_Validator::validate( $candidate );
			if ( 'passed' !== $validation['status'] ) {
				$first_error = ! empty( $validation['errors'][0]['message'] ) ? $validation['errors'][0]['message'] : __( 'Generated question failed Citex validation.', 'citex-tools' );
				$rejection = self::quality_reject( 'citex_ai_validator_rejected', sprintf( __( 'Question %s failed the pre-queue quality gate: %s', 'citex-tools' ), $id, $first_error ) );
				if ( $rejection ) { return $rejection; }
				$candidate['validatedReference'] = $validation['reconstructedReference'];
				$candidate['validationStatus'] = $validation['status'];
				$candidate['validationErrors'] = $validation['errors'];
				$candidate['validatedAt'] = $validation['validatedAt'];
			} else {
				$candidate['validatedReference'] = $validation['reconstructedReference'];
				$candidate['validationStatus'] = 'passed';
				$candidate['validationErrors'] = array();
				$candidate['validatedAt'] = $validation['validatedAt'];
			}
			$out[] = $candidate;
		}
		$diversity_error = self::check_place_publisher_diversity( $out );
		if ( is_wp_error( $diversity_error ) ) {
			return $diversity_error;
		}
		return $out;
	}

	/**
	 * HARD ENFORCEMENT (never gated behind QUALITY_GATE_ENABLED), not just
	 * a prompt request: place and publisher are invented per-question by
	 * Gemini, and a soft "vary it globally" prompt instruction
	 * (place_publisher_diversity_guidance()/publisher_diversity_guidance())
	 * was not reliably followed on its own — the same place (very often
	 * "London") and the same publisher kept recurring across a batch. This
	 * scans the WHOLE batch just built and rejects it outright (feeding the
	 * existing bounded regenerate-with-feedback retry, exactly like every
	 * other structural check in normalise()) when:
	 * - the exact same (place, publisher) combination is used by more than
	 *   MAX_PLACE_PUBLISHER_PAIR_REPEATS questions — the literal "it should
	 *   be rare to have the same question with the same place and
	 *   publisher" requirement, or
	 * - a single place, or a single publisher, dominates more than a
	 *   quarter of the batch (floor of 2, so a small batch of 2-8 still
	 *   tolerates one incidental repeat) — catches "way too much London"
	 *   even when it is paired with a varying publisher each time.
	 * Only candidates that actually carry both fields (Book, Edited Book,
	 * and their identify_error variant) contribute to the pair/place
	 * checks; a category with 'publisher' but no 'place' (Website) still
	 * contributes to the publisher-dominance check alone. Journal Article
	 * (neither field) and choose_treatment (pure rule text, neither field)
	 * are unaffected — they simply never populate these keys.
	 *
	 * @param array $out Candidates already built by normalise()'s own loop.
	 * @return WP_Error|null
	 */
	private static function check_place_publisher_diversity( array $out ) {
		$max_pair_repeats = 2;
		$n = count( $out );
		if ( $n < 3 ) {
			return null;
		}
		$pair_counts   = array();
		$pair_display  = array();
		$place_counts  = array();
		$place_display = array();
		$pub_counts    = array();
		$pub_display   = array();
		foreach ( $out as $candidate ) {
			$publisher = isset( $candidate['publisher'] ) ? trim( (string) $candidate['publisher'] ) : '';
			if ( '' === $publisher ) {
				continue;
			}
			$pub_key = strtolower( $publisher );
			$pub_counts[ $pub_key ]   = ( $pub_counts[ $pub_key ] ?? 0 ) + 1;
			$pub_display[ $pub_key ] = $publisher;

			$place = isset( $candidate['place'] ) ? trim( (string) $candidate['place'] ) : '';
			if ( '' === $place ) {
				continue;
			}
			$place_key = strtolower( $place );
			$place_counts[ $place_key ]   = ( $place_counts[ $place_key ] ?? 0 ) + 1;
			$place_display[ $place_key ] = $place;

			$pair_key = $place_key . '|' . $pub_key;
			$pair_counts[ $pair_key ]   = ( $pair_counts[ $pair_key ] ?? 0 ) + 1;
			$pair_display[ $pair_key ] = $place . ' / ' . $publisher;
		}
		foreach ( $pair_counts as $pair_key => $count ) {
			if ( $count > $max_pair_repeats ) {
				return new WP_Error(
					'citex_ai_place_publisher_pair_repeated',
					sprintf(
						__( 'This batch reuses the exact same place/publisher combination ("%1$s") on %2$d different questions — vary the place and publisher so the same combination is rare, not repeated.', 'citex-tools' ),
						$pair_display[ $pair_key ],
						$count
					)
				);
			}
		}
		$max_dominance = max( 2, (int) ceil( $n / 4 ) );
		foreach ( $place_counts as $place_key => $count ) {
			if ( $count > $max_dominance ) {
				return new WP_Error(
					'citex_ai_place_not_diverse',
					sprintf( __( 'This batch uses "%1$s" as the place of publication on %2$d of %3$d questions — spread the place of publication globally instead of letting one city dominate.', 'citex-tools' ), $place_display[ $place_key ], $count, $n )
				);
			}
		}
		foreach ( $pub_counts as $pub_key => $count ) {
			if ( $count > $max_dominance ) {
				return new WP_Error(
					'citex_ai_publisher_not_diverse',
					sprintf( __( 'This batch uses "%1$s" as the publisher on %2$d of %3$d questions — spread the publisher across a wider range of real publishers instead of letting one dominate.', 'citex-tools' ), $pub_display[ $pub_key ], $count, $n )
				);
			}
		}
		return null;
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
	/**
	 * Citex — not Gemini — authors the ENTIRE Book DragDrop question: which
	 * 3 parts are drawn, the wrong ("confusing") chip for each, Question
	 * Parts, and Fixed Text, via Citex_Book_Dragdrop_Parts — replaces the
	 * original fixed 8-design catalogue (Citex_Reference_Rules::
	 * book_dragdrop_designs() and friends, now removed) and its
	 * Gemini-authored confusingWords list. Gemini supplies nothing beyond
	 * the canonical book record ($authors/$year/$title/$place/$publisher)
	 * and a non-leaking scenario — no distractor text, nothing that needs
	 * its own plausibility check, since every part and every wrong chip is
	 * a deterministic transformation of that one record (see
	 * Citex_Generated_Validator::validate_dragdrop()'s Book-only block,
	 * which recomputes and exact-matches this from the stored
	 * `dragdropPartKeys` selection).
	 *
	 * The selection is picked per QUESTION (seeded by this question's own
	 * id, via Citex_Book_Dragdrop_Parts::select_parts()) — so a batch is
	 * not all the same part combination, and always includes at least one
	 * real content field (never only structural chips).
	 *
	 * @param array $authors array<{fullName, surname, initials}>, 1 or more.
	 * @return array|WP_Error
	 */
	private static function normalise_book_dragdrop_item( $item, $id, $authors, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty ) {
		$fields = array( 'year' => $year, 'title' => $title, 'place' => $place, 'publisher' => $publisher );
		$selected_keys = Citex_Book_Dragdrop_Parts::select_parts( $id, $authors );
		$built = Citex_Book_Dragdrop_Parts::build( $selected_keys, $authors, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_book_dragdrop_parts_unknown', sprintf( __( 'Question %s: unable to build Book DragDrop parts for this record.', 'citex-tools' ), $id ) );
		}
		$reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_BOOK, array_merge( $fields, array( 'authors' => $authors ) ) );
		$author_full_names = array_column( $authors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'Harvard | ReferenceList | Book | DragDrop | %s', $id ), 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Book', 'exercise' => $exercise, 'type' => 'DragDrop', 'institution' => 'Harvard', 'difficulty' => ucfirst( $difficulty ), 'dragdropPartKeys' => array_values( array_map( 'sanitize_key', $selected_keys ) ), 'scenario' => sanitize_textarea_field( $scenario ), 'authors' => array_map( function ( $author ) { return array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'initials' => sanitize_text_field( $author['initials'] ) ); }, $authors ), 'authorFullNames' => array_values( array_map( 'sanitize_text_field', $author_full_names ) ), 'authorFullName' => sanitize_text_field( $authors[0]['fullName'] ), 'authorSurname' => sanitize_text_field( $authors[0]['surname'] ), 'authorInitials' => sanitize_text_field( $authors[0]['initials'] ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'place' => sanitize_text_field( $place ), 'publisher' => sanitize_text_field( $publisher ), 'fixedText' => sanitize_text_field( $built['fixedText'] ), 'questionParts' => array_values( array_map( 'sanitize_text_field', $built['parts'] ) ), 'confusingWords' => array_values( array_map( 'sanitize_text_field', $built['confusingWords'] ) ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * Citex — not Gemini — authors the ENTIRE Book MCQ question: the stem,
	 * all 4 options, and the answer, via Citex_Book_Mcq_Variants::build(),
	 * from the user's own fixed 16-variant catalogue (replaces the original
	 * "select the correct full reference" mechanic, "Identify the error",
	 * and "Choose the correct rule/treatment" for Book — all three stay in
	 * place for Edited Book/Journal Article/Website). Gemini supplies
	 * nothing beyond the canonical book record ($authors/$year/$title/
	 * $place/$publisher) — no distractor text, no error reasons, nothing
	 * that needs its own plausibility check, since every option is a
	 * deterministic transformation of that one record.
	 *
	 * The variant is picked per QUESTION (seeded by this question's own
	 * id, via Citex_Book_Mcq_Variants::variant_for()), filtered to those
	 * compatible with this record's real author count — so a batch is not
	 * all the same variant, and an author-count-specific variant (e.g.
	 * "two_authors") is never picked for a record with the wrong count.
	 *
	 * The correct answer is never placed into, or duplicated into, any of
	 * the 4 option slots — same "never duplicate the answer into an
	 * option" contract as every other MCQ mechanic (see
	 * Citex_Generated_Validator::validate_book_mcq_variant()).
	 *
	 * @param array $authors array<{fullName, surname, initials}>, 1 or more.
	 * @return array|WP_Error
	 */
	private static function normalise_book_mcq_variant_item( $item, $id, $authors, $year, $title, $place, $publisher, $exercise, $difficulty ) {
		$fields = array( 'authors' => $authors, 'year' => $year, 'title' => $title, 'place' => $place, 'publisher' => $publisher );
		$variant = Citex_Book_Mcq_Variants::variant_for( $id, count( $authors ) );
		$built   = Citex_Book_Mcq_Variants::build( $variant, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_book_mcq_variant_unknown', sprintf( __( 'Question %1$s: unrecognised Book MCQ variant "%2$s".', 'citex-tools' ), $id, $variant ) );
		}

		// Option 1-3 hold the 3 Citex-authored wrong options; Option 4 is
		// ALWAYS left blank — the correct answer lives only in the Answer
		// field (reconstructedReference, below), matching the same
		// "never duplicate the answer into an option slot" contract every
		// other MCQ mechanic in this plugin already follows.
		$options = $built['wrongOptions'];
		$options[] = '';

		// Citex — not Gemini — writes the hint too, deterministically from
		// the category's own fixed, non-revealing clue (unchanged from the
		// mechanic this replaces).
		$hint = Citex_Reference_Rules::mcq_hint( Citex_Reference_Rules::CATEGORY_BOOK );
		$answer_explanation = sprintf( 'This question tests the "%s" Harvard formatting rule.', $variant );

		$author_full_names = array_column( $authors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'Harvard | ReferenceList | Book | MCQ | %s', $id ), 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Book', 'exercise' => $exercise, 'type' => 'MCQ', 'institution' => 'Harvard', 'difficulty' => ucfirst( $difficulty ), 'mcqPattern' => 'book_mcq_variant', 'bookMcqVariant' => sanitize_key( $variant ), 'scenario' => sanitize_textarea_field( $built['stem'] ), 'authors' => array_map( function ( $author ) { return array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'initials' => sanitize_text_field( $author['initials'] ) ); }, $authors ), 'authorFullNames' => array_values( array_map( 'sanitize_text_field', $author_full_names ) ), 'authorFullName' => sanitize_text_field( $authors[0]['fullName'] ), 'authorSurname' => sanitize_text_field( $authors[0]['surname'] ), 'authorInitials' => sanitize_text_field( $authors[0]['initials'] ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'place' => sanitize_text_field( $place ), 'publisher' => sanitize_text_field( $publisher ), 'options' => array_values( array_map( 'sanitize_text_field', $options ) ), 'hint' => sanitize_textarea_field( $hint ), 'answerExplanation' => sanitize_textarea_field( $answer_explanation ), 'reconstructedReference' => sanitize_text_field( $built['correctAnswer'] ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * The single MLA dispatcher for all 4 categories — mirrors
	 * normalise_intext_item()'s own role (one early, category-agnostic
	 * entry point, called before any Harvard-only scenario_id routing) but
	 * for MLA's reference-list mechanic. Recomputes `$scenario` itself via
	 * Citex_MLA_Reference_Rules::mcq_question_stem() for MCQ (the caller's
	 * own $scenario was computed with Harvard's stem function, which would
	 * be the wrong text for an MLA question) — DragDrop's own scenario
	 * still comes from $item['scenario'] exactly like every other category.
	 *
	 * @return array|WP_Error
	 */
	private static function normalise_mla_item( $item, $id, $category, $type, $exercise, $difficulty, $target_count ) {
		$scenario = 'MCQ' === $type
			? Citex_MLA_Reference_Rules::mcq_question_stem( $category )
			: trim( (string) ( $item['scenario'] ?? '' ) );

		if ( Citex_Reference_Rules::CATEGORY_EDITED_BOOK === $category ) {
			return self::normalise_mla_edited_book_dispatch( $item, $id, $type, $scenario, $exercise, $difficulty, $target_count );
		}
		if ( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE === $category ) {
			return self::normalise_mla_journal_article_dispatch( $item, $id, $type, $scenario, $exercise, $difficulty, $target_count );
		}
		if ( Citex_Reference_Rules::CATEGORY_WEBSITE === $category ) {
			return self::normalise_mla_website_dispatch( $item, $id, $type, $scenario, $exercise, $difficulty );
		}

		// Book (the default/only category before this task).
		$year      = trim( (string) ( $item['year'] ?? '' ) );
		$title     = trim( (string) ( $item['bookTitle'] ?? '' ) );
		$publisher = trim( (string) ( $item['publisher'] ?? '' ) );
		if ( '' === $scenario || '' === $year || '' === $title || '' === $publisher ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing required bibliographic data.', 'citex-tools' ), $id ) ); }
		$author_names = array_values( array_filter( array_map( 'trim', (array) ( $item['authorFullNames'] ?? array() ) ), 'strlen' ) );
		if ( empty( $author_names ) || count( $author_names ) > 12 ) { return new WP_Error( 'citex_ai_bad_author_count', sprintf( __( 'Question %s must have 1 or more authors (12 at most); %d were provided.', 'citex-tools' ), $id, count( $author_names ) ) ); }
		if ( null !== $target_count && count( $author_names ) !== $target_count ) { return new WP_Error( 'citex_ai_author_count_mismatch', sprintf( __( 'Question %1$s must have exactly %2$d authors for this scenario; %3$d were provided.', 'citex-tools' ), $id, $target_count, count( $author_names ) ) ); }
		$authors = array();
		foreach ( $author_names as $author_full_name ) {
			$author_parts = self::derive_mla_author_parts( $author_full_name );
			if ( is_wp_error( $author_parts ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %1$s: %2$s', 'citex-tools' ), $id, $author_parts->get_error_message() ) ); }
			$authors[] = array( 'fullName' => $author_full_name, 'surname' => $author_parts['surname'], 'givenName' => $author_parts['givenName'] );
		}
		return 'MCQ' === $type
			? self::normalise_mla_book_mcq_variant_item( $item, $id, $authors, $year, $title, $publisher, $exercise, $difficulty )
			: self::normalise_mla_book_dragdrop_item( $item, $id, $authors, $year, $title, $publisher, $scenario, $exercise, $difficulty );
	}

	/**
	 * Extracts and validates one MLA Edited Book record's fields
	 * (editorFullNames/year/bookTitle/publisher — no `place` at all, same
	 * as MLA Book) and dispatches to the DragDrop/MCQ leaf normaliser.
	 * Mirrors the Harvard Edited Book extraction block's own shape,
	 * deriving each editor's surname/FULL given name via
	 * derive_mla_author_parts() instead of Harvard's surname/initials.
	 */
	private static function normalise_mla_edited_book_dispatch( $item, $id, $type, $scenario, $exercise, $difficulty, $target_count ) {
		$year      = trim( (string) ( $item['year'] ?? '' ) );
		$title     = trim( (string) ( $item['bookTitle'] ?? '' ) );
		$publisher = trim( (string) ( $item['publisher'] ?? '' ) );
		if ( '' === $scenario || '' === $year || '' === $title || '' === $publisher ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing required bibliographic data.', 'citex-tools' ), $id ) ); }
		$editor_names = array_values( array_filter( array_map( 'trim', (array) ( $item['editorFullNames'] ?? array() ) ), 'strlen' ) );
		if ( empty( $editor_names ) || count( $editor_names ) > 12 ) { return new WP_Error( 'citex_ai_bad_editor_count', sprintf( __( 'Question %s must have 1 or more editors (12 at most); %d were provided.', 'citex-tools' ), $id, count( $editor_names ) ) ); }
		if ( null !== $target_count && count( $editor_names ) !== $target_count ) { return new WP_Error( 'citex_ai_editor_count_mismatch', sprintf( __( 'Question %1$s must have exactly %2$d editors for this scenario; %3$d were provided.', 'citex-tools' ), $id, $target_count, count( $editor_names ) ) ); }
		$editors = array();
		foreach ( $editor_names as $editor_full_name ) {
			$editor_parts = self::derive_mla_author_parts( $editor_full_name );
			if ( is_wp_error( $editor_parts ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %1$s: %2$s', 'citex-tools' ), $id, $editor_parts->get_error_message() ) ); }
			$editors[] = array( 'fullName' => $editor_full_name, 'surname' => $editor_parts['surname'], 'givenName' => $editor_parts['givenName'] );
		}
		return 'MCQ' === $type
			? self::normalise_mla_edited_book_mcq_item( $item, $id, $editors, $year, $title, $publisher, $exercise, $difficulty )
			: self::normalise_mla_edited_book_dragdrop_item( $item, $id, $editors, $year, $title, $publisher, $scenario, $exercise, $difficulty );
	}

	/**
	 * Extracts and validates one MLA Journal Article record's fields
	 * (authorFullNames/year/articleTitle/journalTitle/volume/issue/pages —
	 * no place/publisher at all, same as Harvard's own Journal Article).
	 */
	private static function normalise_mla_journal_article_dispatch( $item, $id, $type, $scenario, $exercise, $difficulty, $target_count ) {
		$year          = trim( (string) ( $item['year'] ?? '' ) );
		$article_title = trim( (string) ( $item['articleTitle'] ?? '' ) );
		$journal_title = trim( (string) ( $item['journalTitle'] ?? '' ) );
		$volume        = trim( (string) ( $item['volume'] ?? '' ) );
		$issue         = trim( (string) ( $item['issue'] ?? '' ) );
		$pages         = trim( (string) ( $item['pages'] ?? '' ) );
		if ( '' === $scenario || '' === $year || '' === $article_title || '' === $journal_title || '' === $volume || '' === $issue || '' === $pages ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing required bibliographic data.', 'citex-tools' ), $id ) ); }
		$author_names = array_values( array_filter( array_map( 'trim', (array) ( $item['authorFullNames'] ?? array() ) ), 'strlen' ) );
		if ( empty( $author_names ) || count( $author_names ) > 12 ) { return new WP_Error( 'citex_ai_bad_author_count', sprintf( __( 'Question %s must have 1 or more authors (12 at most); %d were provided.', 'citex-tools' ), $id, count( $author_names ) ) ); }
		if ( null !== $target_count && count( $author_names ) !== $target_count ) { return new WP_Error( 'citex_ai_author_count_mismatch', sprintf( __( 'Question %1$s must have exactly %2$d authors for this scenario; %3$d were provided.', 'citex-tools' ), $id, $target_count, count( $author_names ) ) ); }
		$authors = array();
		foreach ( $author_names as $author_full_name ) {
			$author_parts = self::derive_mla_author_parts( $author_full_name );
			if ( is_wp_error( $author_parts ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %1$s: %2$s', 'citex-tools' ), $id, $author_parts->get_error_message() ) ); }
			$authors[] = array( 'fullName' => $author_full_name, 'surname' => $author_parts['surname'], 'givenName' => $author_parts['givenName'] );
		}
		return 'MCQ' === $type
			? self::normalise_mla_journal_article_mcq_item( $item, $id, $authors, $article_title, $journal_title, $volume, $issue, $year, $pages, $exercise, $difficulty )
			: self::normalise_mla_journal_article_dragdrop_item( $item, $id, $authors, $article_title, $journal_title, $volume, $issue, $year, $pages, $scenario, $exercise, $difficulty );
	}

	/**
	 * Extracts and validates one MLA Website record's fields — authorType
	 * plus authorFullName/organisationName, pageTitle(title), url, and an
	 * OPTIONAL year (never "n.d." — real MLA 9 has no such convention; an
	 * empty year simply means the built reference omits that segment
	 * entirely, see Citex_MLA_Reference_Rules::build_website_reference()'s
	 * own docblock). There is deliberately no `publisher` field requested
	 * or stored at all — MLA's own Website format never shows one, unlike
	 * Harvard's (which keeps it purely for source-realism verification) —
	 * a deliberate simplification for this category, consistent with how
	 * the rest of this app already accepts an invented-but-plausible
	 * source for every non-publisher/journal field.
	 */
	private static function normalise_mla_website_dispatch( $item, $id, $type, $scenario, $exercise, $difficulty ) {
		$author_type = sanitize_key( trim( (string) ( $item['authorType'] ?? '' ) ) );
		if ( ! in_array( $author_type, array( 'individual', 'organisation' ), true ) ) {
			return new WP_Error( 'citex_ai_website_author_type_invalid', sprintf( __( 'Question %s: authorType must be exactly "individual" or "organisation".', 'citex-tools' ), $id ) );
		}
		$page_title = trim( (string) ( $item['title'] ?? '' ) );
		$url        = trim( (string) ( $item['url'] ?? '' ) );
		$year_field = trim( (string) ( $item['year'] ?? '' ) );
		if ( '' === $scenario || '' === $page_title || '' === $url ) {
			return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing required bibliographic data.', 'citex-tools' ), $id ) );
		}
		if ( '' !== $year_field && ! preg_match( '/^\d{4}$/', $year_field ) ) {
			return new WP_Error( 'citex_ai_website_year_invalid', sprintf( __( 'Question %1$s: year must be a real 4-digit year, or left empty when none can be identified (MLA never uses "n.d."); got "%2$s".', 'citex-tools' ), $id, $year_field ) );
		}
		if ( ! preg_match( '#^https?://\S+$#', $url ) ) {
			return new WP_Error( 'citex_ai_website_url_malformed', sprintf( __( 'Question %s has a malformed URL.', 'citex-tools' ), $id ) );
		}

		$author = array( 'type' => $author_type );
		if ( 'individual' === $author_type ) {
			$author_full_name = trim( (string) ( $item['authorFullName'] ?? '' ) );
			if ( '' === $author_full_name ) {
				return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing authorFullName for an individual author.', 'citex-tools' ), $id ) );
			}
			$author_parts = self::derive_mla_author_parts( $author_full_name );
			if ( is_wp_error( $author_parts ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %1$s: %2$s', 'citex-tools' ), $id, $author_parts->get_error_message() ) ); }
			$author['fullName']  = $author_full_name;
			$author['surname']   = $author_parts['surname'];
			$author['givenName'] = $author_parts['givenName'];
		} else {
			$organisation_name = trim( (string) ( $item['organisationName'] ?? '' ) );
			if ( '' === $organisation_name ) {
				return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing organisationName for an organisation author.', 'citex-tools' ), $id ) );
			}
			$author['name'] = $organisation_name;
		}

		return 'MCQ' === $type
			? self::normalise_mla_website_mcq_variant_item( $item, $id, $author, $year_field, $page_title, $url, $exercise, $difficulty )
			: self::normalise_mla_website_dragdrop_item( $item, $id, $author, $year_field, $page_title, $url, $scenario, $exercise, $difficulty );
	}

	/**
	 * MLA counterpart to normalise_book_dragdrop_item() — Phase 1: Book
	 * only. Citex — not Gemini — authors the ENTIRE MLA Book DragDrop
	 * question via Citex_MLA_Book_Dragdrop_Parts, from the canonical
	 * record's authors/year/title/publisher — there is no `place` field at
	 * all in this record shape (see Citex_MLA_Reference_Rules's own
	 * docblock). Field naming otherwise mirrors normalise_book_dragdrop_item()
	 * exactly (same key names) for consistent downstream data handling,
	 * except each author holds `givenName` (the full first name) instead
	 * of `initials`, and there is a new `mlaBookMcqVariant`-style
	 * `dragdropPartKeys` field reused verbatim from Book's own convention.
	 *
	 * @param array $authors array<{fullName, surname, givenName}>, 1 or more.
	 * @return array|WP_Error
	 */
	private static function normalise_mla_book_dragdrop_item( $item, $id, $authors, $year, $title, $publisher, $scenario, $exercise, $difficulty ) {
		$fields = array( 'year' => $year, 'title' => $title, 'publisher' => $publisher );
		$selected_keys = Citex_MLA_Book_Dragdrop_Parts::select_parts( $id, $authors );
		$built = Citex_MLA_Book_Dragdrop_Parts::build( $selected_keys, $authors, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_mla_book_dragdrop_parts_unknown', sprintf( __( 'Question %s: unable to build MLA Book DragDrop parts for this record.', 'citex-tools' ), $id ) );
		}
		$reference = Citex_MLA_Reference_Rules::build_reference( Citex_MLA_Reference_Rules::CATEGORY_BOOK, array_merge( $fields, array( 'authors' => $authors ) ) );
		$author_full_names = array_column( $authors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'MLA | ReferenceList | Book | DragDrop | %s', $id ), 'source' => 'MLA', 'group' => 'ReferenceList', 'category' => 'Book', 'exercise' => $exercise, 'type' => 'DragDrop', 'institution' => 'MLA', 'difficulty' => ucfirst( $difficulty ), 'dragdropPartKeys' => array_values( array_map( 'sanitize_key', $selected_keys ) ), 'scenario' => sanitize_textarea_field( $scenario ), 'authors' => array_map( function ( $author ) { return array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'givenName' => sanitize_text_field( $author['givenName'] ) ); }, $authors ), 'authorFullNames' => array_values( array_map( 'sanitize_text_field', $author_full_names ) ), 'authorFullName' => sanitize_text_field( $authors[0]['fullName'] ), 'authorSurname' => sanitize_text_field( $authors[0]['surname'] ), 'authorGivenName' => sanitize_text_field( $authors[0]['givenName'] ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'publisher' => sanitize_text_field( $publisher ), 'fixedText' => sanitize_text_field( $built['fixedText'] ), 'questionParts' => array_values( array_map( 'sanitize_text_field', $built['parts'] ) ), 'confusingWords' => array_values( array_map( 'sanitize_text_field', $built['confusingWords'] ) ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * MLA counterpart to normalise_book_mcq_variant_item() — Phase 1: Book
	 * only. Citex — not Gemini — authors the ENTIRE MLA Book MCQ question
	 * via Citex_MLA_Book_Mcq_Variants::build(), from the canonical record's
	 * authors/year/title/publisher.
	 *
	 * @param array $authors array<{fullName, surname, givenName}>, 1 or more.
	 * @return array|WP_Error
	 */
	private static function normalise_mla_book_mcq_variant_item( $item, $id, $authors, $year, $title, $publisher, $exercise, $difficulty ) {
		$fields = array( 'authors' => $authors, 'year' => $year, 'title' => $title, 'publisher' => $publisher );
		$variant = Citex_MLA_Book_Mcq_Variants::variant_for( $id, count( $authors ) );
		$built   = Citex_MLA_Book_Mcq_Variants::build( $variant, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_mla_book_mcq_variant_unknown', sprintf( __( 'Question %1$s: unrecognised MLA Book MCQ variant "%2$s".', 'citex-tools' ), $id, $variant ) );
		}

		$options = $built['wrongOptions'];
		$options[] = '';

		$hint = Citex_MLA_Reference_Rules::mcq_hint( Citex_MLA_Reference_Rules::CATEGORY_BOOK );
		$answer_explanation = sprintf( 'This question tests the "%s" MLA formatting rule.', $variant );

		$author_full_names = array_column( $authors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'MLA | ReferenceList | Book | MCQ | %s', $id ), 'source' => 'MLA', 'group' => 'ReferenceList', 'category' => 'Book', 'exercise' => $exercise, 'type' => 'MCQ', 'institution' => 'MLA', 'difficulty' => ucfirst( $difficulty ), 'mcqPattern' => 'mla_book_mcq_variant', 'mlaBookMcqVariant' => sanitize_key( $variant ), 'scenario' => sanitize_textarea_field( $built['stem'] ), 'authors' => array_map( function ( $author ) { return array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'givenName' => sanitize_text_field( $author['givenName'] ) ); }, $authors ), 'authorFullNames' => array_values( array_map( 'sanitize_text_field', $author_full_names ) ), 'authorFullName' => sanitize_text_field( $authors[0]['fullName'] ), 'authorSurname' => sanitize_text_field( $authors[0]['surname'] ), 'authorGivenName' => sanitize_text_field( $authors[0]['givenName'] ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'publisher' => sanitize_text_field( $publisher ), 'options' => array_values( array_map( 'sanitize_text_field', $options ) ), 'hint' => sanitize_textarea_field( $hint ), 'answerExplanation' => sanitize_textarea_field( $answer_explanation ), 'reconstructedReference' => sanitize_text_field( $built['correctAnswer'] ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * APA dispatcher — Phase 1: Book only (mirrors normalise_mla_item()'s
	 * own shape). Reuses Harvard's derive_author_parts() as-is (APA uses
	 * initials, the same shape Harvard already derives — never a new
	 * derive_apa_author_parts(), unlike MLA which needed full given
	 * names).
	 */
	private static function normalise_apa_item( $item, $id, $category, $type, $exercise, $difficulty, $target_count ) {
		$scenario = 'MCQ' === $type
			? Citex_APA_Reference_Rules::mcq_question_stem( $category )
			: trim( (string) ( $item['scenario'] ?? '' ) );

		// Book (the only category in Phase 1).
		$year      = trim( (string) ( $item['year'] ?? '' ) );
		$title     = trim( (string) ( $item['bookTitle'] ?? '' ) );
		$publisher = trim( (string) ( $item['publisher'] ?? '' ) );
		if ( '' === $scenario || '' === $year || '' === $title || '' === $publisher ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %s is missing required bibliographic data.', 'citex-tools' ), $id ) ); }
		$author_names = array_values( array_filter( array_map( 'trim', (array) ( $item['authorFullNames'] ?? array() ) ), 'strlen' ) );
		if ( empty( $author_names ) || count( $author_names ) > 12 ) { return new WP_Error( 'citex_ai_bad_author_count', sprintf( __( 'Question %s must have 1 or more authors (12 at most); %d were provided.', 'citex-tools' ), $id, count( $author_names ) ) ); }
		if ( null !== $target_count && count( $author_names ) !== $target_count ) { return new WP_Error( 'citex_ai_author_count_mismatch', sprintf( __( 'Question %1$s must have exactly %2$d authors for this scenario; %3$d were provided.', 'citex-tools' ), $id, $target_count, count( $author_names ) ) ); }
		$authors = array();
		foreach ( $author_names as $author_full_name ) {
			$author_parts = self::derive_author_parts( $author_full_name );
			if ( is_wp_error( $author_parts ) ) { return new WP_Error( 'citex_ai_missing_field', sprintf( __( 'Question %1$s: %2$s', 'citex-tools' ), $id, $author_parts->get_error_message() ) ); }
			$authors[] = array( 'fullName' => $author_full_name, 'surname' => $author_parts['surname'], 'initials' => $author_parts['initials'] );
		}
		return 'MCQ' === $type
			? self::normalise_apa_book_mcq_variant_item( $item, $id, $authors, $year, $title, $publisher, $exercise, $difficulty )
			: self::normalise_apa_book_dragdrop_item( $item, $id, $authors, $year, $title, $publisher, $scenario, $exercise, $difficulty );
	}

	/**
	 * APA counterpart to normalise_book_dragdrop_item()/normalise_mla_book_dragdrop_item() —
	 * Phase 1: Book only. Citex — not Gemini — authors the ENTIRE APA Book
	 * DragDrop question via Citex_APA_Book_Dragdrop_Parts, from the
	 * canonical record's authors/year/title/publisher — there is no
	 * `place` field at all (same as MLA), and authors carry `initials`
	 * (same as Harvard, unlike MLA's `givenName`).
	 *
	 * @param array $authors array<{fullName, surname, initials}>, 1 or more.
	 * @return array|WP_Error
	 */
	private static function normalise_apa_book_dragdrop_item( $item, $id, $authors, $year, $title, $publisher, $scenario, $exercise, $difficulty ) {
		$fields = array( 'year' => $year, 'title' => $title, 'publisher' => $publisher );
		$selected_keys = Citex_APA_Book_Dragdrop_Parts::select_parts( $id, $authors );
		$built = Citex_APA_Book_Dragdrop_Parts::build( $selected_keys, $authors, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_apa_book_dragdrop_parts_unknown', sprintf( __( 'Question %s: unable to build APA Book DragDrop parts for this record.', 'citex-tools' ), $id ) );
		}
		$reference = Citex_APA_Reference_Rules::build_reference( Citex_APA_Reference_Rules::CATEGORY_BOOK, array_merge( $fields, array( 'authors' => $authors ) ) );
		$author_full_names = array_column( $authors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'APA | ReferenceList | Book | DragDrop | %s', $id ), 'source' => 'APA', 'group' => 'ReferenceList', 'category' => 'Book', 'exercise' => $exercise, 'type' => 'DragDrop', 'institution' => 'APA', 'difficulty' => ucfirst( $difficulty ), 'dragdropPartKeys' => array_values( array_map( 'sanitize_key', $selected_keys ) ), 'scenario' => sanitize_textarea_field( $scenario ), 'authors' => array_map( function ( $author ) { return array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'initials' => sanitize_text_field( $author['initials'] ) ); }, $authors ), 'authorFullNames' => array_values( array_map( 'sanitize_text_field', $author_full_names ) ), 'authorFullName' => sanitize_text_field( $authors[0]['fullName'] ), 'authorSurname' => sanitize_text_field( $authors[0]['surname'] ), 'authorInitials' => sanitize_text_field( $authors[0]['initials'] ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'publisher' => sanitize_text_field( $publisher ), 'fixedText' => sanitize_text_field( $built['fixedText'] ), 'questionParts' => array_values( array_map( 'sanitize_text_field', $built['parts'] ) ), 'confusingWords' => array_values( array_map( 'sanitize_text_field', $built['confusingWords'] ) ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * APA counterpart to normalise_book_mcq_variant_item()/normalise_mla_book_mcq_variant_item() —
	 * Phase 1: Book only. Citex — not Gemini — authors the ENTIRE APA Book
	 * MCQ question via Citex_APA_Book_Mcq_Variants::build(), from the
	 * canonical record's authors/year/title/publisher.
	 *
	 * @param array $authors array<{fullName, surname, initials}>, 1 or more.
	 * @return array|WP_Error
	 */
	private static function normalise_apa_book_mcq_variant_item( $item, $id, $authors, $year, $title, $publisher, $exercise, $difficulty ) {
		$fields = array( 'authors' => $authors, 'year' => $year, 'title' => $title, 'publisher' => $publisher );
		$variant = Citex_APA_Book_Mcq_Variants::variant_for( $id, count( $authors ) );
		$built   = Citex_APA_Book_Mcq_Variants::build( $variant, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_apa_book_mcq_variant_unknown', sprintf( __( 'Question %1$s: unrecognised APA Book MCQ variant "%2$s".', 'citex-tools' ), $id, $variant ) );
		}

		$options = $built['wrongOptions'];
		$options[] = '';

		$hint = Citex_APA_Reference_Rules::mcq_hint( Citex_APA_Reference_Rules::CATEGORY_BOOK );
		$answer_explanation = sprintf( 'This question tests the "%s" APA formatting rule.', $variant );

		$author_full_names = array_column( $authors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'APA | ReferenceList | Book | MCQ | %s', $id ), 'source' => 'APA', 'group' => 'ReferenceList', 'category' => 'Book', 'exercise' => $exercise, 'type' => 'MCQ', 'institution' => 'APA', 'difficulty' => ucfirst( $difficulty ), 'mcqPattern' => 'apa_book_mcq_variant', 'apaBookMcqVariant' => sanitize_key( $variant ), 'scenario' => sanitize_textarea_field( $built['stem'] ), 'authors' => array_map( function ( $author ) { return array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'initials' => sanitize_text_field( $author['initials'] ) ); }, $authors ), 'authorFullNames' => array_values( array_map( 'sanitize_text_field', $author_full_names ) ), 'authorFullName' => sanitize_text_field( $authors[0]['fullName'] ), 'authorSurname' => sanitize_text_field( $authors[0]['surname'] ), 'authorInitials' => sanitize_text_field( $authors[0]['initials'] ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'publisher' => sanitize_text_field( $publisher ), 'options' => array_values( array_map( 'sanitize_text_field', $options ) ), 'hint' => sanitize_textarea_field( $hint ), 'answerExplanation' => sanitize_textarea_field( $answer_explanation ), 'reconstructedReference' => sanitize_text_field( $built['correctAnswer'] ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * MLA Edited Book DragDrop — mirrors normalise_mla_book_dragdrop_item()
	 * exactly, via Citex_MLA_Edited_Book_Dragdrop_Parts/
	 * Citex_MLA_Reference_Rules::CATEGORY_EDITED_BOOK instead.
	 *
	 * @param array $editors array<{fullName, surname, givenName}>, 1 or more.
	 * @return array|WP_Error
	 */
	private static function normalise_mla_edited_book_dragdrop_item( $item, $id, $editors, $year, $title, $publisher, $scenario, $exercise, $difficulty ) {
		$fields        = array( 'year' => $year, 'title' => $title, 'publisher' => $publisher );
		$selected_keys = Citex_MLA_Edited_Book_Dragdrop_Parts::select_parts( $id, $editors );
		$built         = Citex_MLA_Edited_Book_Dragdrop_Parts::build( $selected_keys, $editors, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_mla_edited_book_dragdrop_parts_unknown', sprintf( __( 'Question %s: unable to build MLA Edited Book DragDrop parts for this record.', 'citex-tools' ), $id ) );
		}
		$reference          = Citex_MLA_Reference_Rules::build_reference( Citex_MLA_Reference_Rules::CATEGORY_EDITED_BOOK, array_merge( $fields, array( 'editors' => $editors ) ) );
		$editor_full_names  = array_column( $editors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'MLA | ReferenceList | Edited Book | DragDrop | %s', $id ), 'source' => 'MLA', 'group' => 'ReferenceList', 'category' => 'Edited Book', 'exercise' => $exercise, 'type' => 'DragDrop', 'institution' => 'MLA', 'difficulty' => ucfirst( $difficulty ), 'dragdropPartKeys' => array_values( array_map( 'sanitize_key', $selected_keys ) ), 'scenario' => sanitize_textarea_field( $scenario ), 'editors' => array_map( function ( $editor ) { return array( 'fullName' => sanitize_text_field( $editor['fullName'] ), 'surname' => sanitize_text_field( $editor['surname'] ), 'givenName' => sanitize_text_field( $editor['givenName'] ) ); }, $editors ), 'editorFullNames' => array_values( array_map( 'sanitize_text_field', $editor_full_names ) ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'publisher' => sanitize_text_field( $publisher ), 'fixedText' => sanitize_text_field( $built['fixedText'] ), 'questionParts' => array_values( array_map( 'sanitize_text_field', $built['parts'] ) ), 'confusingWords' => array_values( array_map( 'sanitize_text_field', $built['confusingWords'] ) ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * MLA Edited Book MCQ — mirrors normalise_mla_book_mcq_variant_item()
	 * exactly, via Citex_MLA_Edited_Book_Mcq_Variants instead.
	 *
	 * @param array $editors array<{fullName, surname, givenName}>, 1 or more.
	 * @return array|WP_Error
	 */
	private static function normalise_mla_edited_book_mcq_item( $item, $id, $editors, $year, $title, $publisher, $exercise, $difficulty ) {
		$fields  = array( 'editors' => $editors, 'year' => $year, 'title' => $title, 'publisher' => $publisher );
		$variant = Citex_MLA_Edited_Book_Mcq_Variants::variant_for( $id, count( $editors ) );
		$built   = Citex_MLA_Edited_Book_Mcq_Variants::build( $variant, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_mla_edited_book_mcq_variant_unknown', sprintf( __( 'Question %1$s: unrecognised MLA Edited Book MCQ variant "%2$s".', 'citex-tools' ), $id, $variant ) );
		}
		$options   = $built['wrongOptions'];
		$options[] = '';
		$hint      = Citex_MLA_Reference_Rules::mcq_hint( Citex_MLA_Reference_Rules::CATEGORY_EDITED_BOOK );
		$answer_explanation = sprintf( 'This question tests the "%s" MLA formatting rule.', $variant );

		$editor_full_names = array_column( $editors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'MLA | ReferenceList | Edited Book | MCQ | %s', $id ), 'source' => 'MLA', 'group' => 'ReferenceList', 'category' => 'Edited Book', 'exercise' => $exercise, 'type' => 'MCQ', 'institution' => 'MLA', 'difficulty' => ucfirst( $difficulty ), 'mcqPattern' => 'mla_edited_book_mcq_variant', 'mlaEditedBookMcqVariant' => sanitize_key( $variant ), 'scenario' => sanitize_textarea_field( $built['stem'] ), 'editors' => array_map( function ( $editor ) { return array( 'fullName' => sanitize_text_field( $editor['fullName'] ), 'surname' => sanitize_text_field( $editor['surname'] ), 'givenName' => sanitize_text_field( $editor['givenName'] ) ); }, $editors ), 'editorFullNames' => array_values( array_map( 'sanitize_text_field', $editor_full_names ) ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'publisher' => sanitize_text_field( $publisher ), 'options' => array_values( array_map( 'sanitize_text_field', $options ) ), 'hint' => sanitize_textarea_field( $hint ), 'answerExplanation' => sanitize_textarea_field( $answer_explanation ), 'reconstructedReference' => sanitize_text_field( $built['correctAnswer'] ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * MLA Journal Article DragDrop — mirrors normalise_mla_book_dragdrop_item()'s
	 * own shape, via Citex_MLA_Journal_Article_Dragdrop_Parts/
	 * Citex_MLA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE instead. No
	 * place/publisher fields at all — same as Harvard's own Journal
	 * Article.
	 *
	 * @param array $authors array<{fullName, surname, givenName}>, 1 or more.
	 * @return array|WP_Error
	 */
	private static function normalise_mla_journal_article_dragdrop_item( $item, $id, $authors, $article_title, $journal_title, $volume, $issue, $year, $pages, $scenario, $exercise, $difficulty ) {
		$fields        = array( 'articleTitle' => $article_title, 'journalTitle' => $journal_title, 'volume' => $volume, 'issue' => $issue, 'year' => $year, 'pages' => $pages );
		$selected_keys = Citex_MLA_Journal_Article_Dragdrop_Parts::select_parts( $id, $authors );
		$built         = Citex_MLA_Journal_Article_Dragdrop_Parts::build( $selected_keys, $authors, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_mla_journal_article_dragdrop_parts_unknown', sprintf( __( 'Question %s: unable to build MLA Journal Article DragDrop parts for this record.', 'citex-tools' ), $id ) );
		}
		$reference         = Citex_MLA_Reference_Rules::build_reference( Citex_MLA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, array_merge( $fields, array( 'authors' => $authors ) ) );
		$author_full_names = array_column( $authors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'MLA | ReferenceList | Journal Article | DragDrop | %s', $id ), 'source' => 'MLA', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'exercise' => $exercise, 'type' => 'DragDrop', 'institution' => 'MLA', 'difficulty' => ucfirst( $difficulty ), 'dragdropPartKeys' => array_values( array_map( 'sanitize_key', $selected_keys ) ), 'scenario' => sanitize_textarea_field( $scenario ), 'authors' => array_map( function ( $author ) { return array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'givenName' => sanitize_text_field( $author['givenName'] ) ); }, $authors ), 'authorFullNames' => array_values( array_map( 'sanitize_text_field', $author_full_names ) ), 'authorFullName' => sanitize_text_field( $authors[0]['fullName'] ), 'authorSurname' => sanitize_text_field( $authors[0]['surname'] ), 'authorGivenName' => sanitize_text_field( $authors[0]['givenName'] ), 'year' => sanitize_text_field( $year ), 'articleTitle' => sanitize_text_field( $article_title ), 'journalTitle' => sanitize_text_field( $journal_title ), 'volume' => sanitize_text_field( $volume ), 'issue' => sanitize_text_field( $issue ), 'pages' => sanitize_text_field( $pages ), 'fixedText' => sanitize_text_field( $built['fixedText'] ), 'questionParts' => array_values( array_map( 'sanitize_text_field', $built['parts'] ) ), 'confusingWords' => array_values( array_map( 'sanitize_text_field', $built['confusingWords'] ) ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * MLA Journal Article MCQ — mirrors normalise_mla_book_mcq_variant_item()'s
	 * own shape, via Citex_MLA_Journal_Article_Mcq_Variants instead.
	 *
	 * @param array $authors array<{fullName, surname, givenName}>, 1 or more.
	 * @return array|WP_Error
	 */
	private static function normalise_mla_journal_article_mcq_item( $item, $id, $authors, $article_title, $journal_title, $volume, $issue, $year, $pages, $exercise, $difficulty ) {
		$fields  = array( 'authors' => $authors, 'articleTitle' => $article_title, 'journalTitle' => $journal_title, 'volume' => $volume, 'issue' => $issue, 'year' => $year, 'pages' => $pages );
		$variant = Citex_MLA_Journal_Article_Mcq_Variants::variant_for( $id, count( $authors ) );
		$built   = Citex_MLA_Journal_Article_Mcq_Variants::build( $variant, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_mla_journal_article_mcq_variant_unknown', sprintf( __( 'Question %1$s: unrecognised MLA Journal Article MCQ variant "%2$s".', 'citex-tools' ), $id, $variant ) );
		}
		$options   = $built['wrongOptions'];
		$options[] = '';
		$hint      = Citex_MLA_Reference_Rules::mcq_hint( Citex_MLA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE );
		$answer_explanation = sprintf( 'This question tests the "%s" MLA formatting rule.', $variant );

		$author_full_names = array_column( $authors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'MLA | ReferenceList | Journal Article | MCQ | %s', $id ), 'source' => 'MLA', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'exercise' => $exercise, 'type' => 'MCQ', 'institution' => 'MLA', 'difficulty' => ucfirst( $difficulty ), 'mcqPattern' => 'mla_journal_article_mcq_variant', 'mlaJournalArticleMcqVariant' => sanitize_key( $variant ), 'scenario' => sanitize_textarea_field( $built['stem'] ), 'authors' => array_map( function ( $author ) { return array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'givenName' => sanitize_text_field( $author['givenName'] ) ); }, $authors ), 'authorFullNames' => array_values( array_map( 'sanitize_text_field', $author_full_names ) ), 'authorFullName' => sanitize_text_field( $authors[0]['fullName'] ), 'authorSurname' => sanitize_text_field( $authors[0]['surname'] ), 'authorGivenName' => sanitize_text_field( $authors[0]['givenName'] ), 'year' => sanitize_text_field( $year ), 'articleTitle' => sanitize_text_field( $article_title ), 'journalTitle' => sanitize_text_field( $journal_title ), 'volume' => sanitize_text_field( $volume ), 'issue' => sanitize_text_field( $issue ), 'pages' => sanitize_text_field( $pages ), 'options' => array_values( array_map( 'sanitize_text_field', $options ) ), 'hint' => sanitize_textarea_field( $hint ), 'answerExplanation' => sanitize_textarea_field( $answer_explanation ), 'reconstructedReference' => sanitize_text_field( $built['correctAnswer'] ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * MLA Website DragDrop — mirrors normalise_website_item()'s own role,
	 * via Citex_MLA_Website_Dragdrop_Parts/Citex_MLA_Reference_Rules::CATEGORY_WEBSITE
	 * instead. No `accessedDate` is requested of Gemini at all — same as
	 * Harvard's own Website mechanic, Citex supplies it itself,
	 * deterministically, from the actual generation date. No `publisher`
	 * field either — see normalise_mla_website_dispatch()'s own docblock.
	 *
	 * @param array $author {type, surname?, givenName?, name?, fullName?}.
	 * @return array|WP_Error
	 */
	private static function normalise_mla_website_dragdrop_item( $item, $id, $author, $year, $title, $url, $scenario, $exercise, $difficulty ) {
		$accessed_date = self::current_accessed_date();
		$fields        = array( 'year' => $year, 'title' => $title, 'url' => $url, 'accessedDate' => $accessed_date );
		$has_year      = '' !== $year;
		$selected_keys = Citex_MLA_Website_Dragdrop_Parts::select_parts( $id, $has_year );
		$built         = Citex_MLA_Website_Dragdrop_Parts::build( $selected_keys, $author, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_mla_website_dragdrop_parts_unknown', sprintf( __( 'Question %s: unable to build MLA Website DragDrop parts for this record.', 'citex-tools' ), $id ) );
		}
		$reference = Citex_MLA_Reference_Rules::build_reference( Citex_MLA_Reference_Rules::CATEGORY_WEBSITE, array_merge( $fields, array( 'author' => $author ) ) );
		return array(
			'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'MLA | ReferenceList | Website | DragDrop | %s', $id ), 'source' => 'MLA', 'group' => 'ReferenceList', 'category' => 'Website', 'exercise' => $exercise, 'type' => 'DragDrop', 'institution' => 'MLA', 'difficulty' => ucfirst( $difficulty ),
			'dragdropPartKeys' => array_values( array_map( 'sanitize_key', $selected_keys ) ), 'scenario' => sanitize_textarea_field( $scenario ),
			'authorType' => sanitize_key( $author['type'] ), 'authors' => 'individual' === $author['type'] ? array( array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'givenName' => sanitize_text_field( $author['givenName'] ) ) ) : array(), 'organisationName' => 'organisation' === $author['type'] ? sanitize_text_field( $author['name'] ) : '',
			'year' => sanitize_text_field( $year ), 'pageTitle' => sanitize_text_field( $title ), 'url' => sanitize_text_field( $url ), 'accessedDate' => sanitize_text_field( $accessed_date ),
			'fixedText' => sanitize_text_field( $built['fixedText'] ), 'questionParts' => array_values( array_map( 'sanitize_text_field', $built['parts'] ) ), 'confusingWords' => array_values( array_map( 'sanitize_text_field', $built['confusingWords'] ) ), 'reconstructedReference' => sanitize_text_field( $reference ),
			'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ),
		);
	}

	/**
	 * MLA Website MCQ — mirrors normalise_website_mcq_variant_item()'s own
	 * role, via Citex_MLA_Website_Mcq_Variants instead.
	 *
	 * @param array $author {type, surname?, givenName?, name?, fullName?}.
	 * @return array|WP_Error
	 */
	private static function normalise_mla_website_mcq_variant_item( $item, $id, $author, $year, $title, $url, $exercise, $difficulty ) {
		$accessed_date = self::current_accessed_date();
		$fields        = array( 'author' => $author, 'year' => $year, 'title' => $title, 'url' => $url, 'accessedDate' => $accessed_date );
		$is_individual = 'individual' === $author['type'];
		$variant       = Citex_MLA_Website_Mcq_Variants::variant_for( $id, $is_individual );
		$built         = Citex_MLA_Website_Mcq_Variants::build( $variant, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_mla_website_mcq_variant_unknown', sprintf( __( 'Question %1$s: unrecognised or incompatible MLA Website MCQ variant "%2$s".', 'citex-tools' ), $id, $variant ) );
		}
		$options   = $built['wrongOptions'];
		$options[] = '';
		$hint      = Citex_MLA_Reference_Rules::mcq_hint( Citex_MLA_Reference_Rules::CATEGORY_WEBSITE );
		$answer_explanation = sprintf( 'This question tests the "%s" MLA formatting rule.', $variant );

		return array(
			'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'MLA | ReferenceList | Website | MCQ | %s', $id ), 'source' => 'MLA', 'group' => 'ReferenceList', 'category' => 'Website', 'exercise' => $exercise, 'type' => 'MCQ', 'institution' => 'MLA', 'difficulty' => ucfirst( $difficulty ),
			'mcqPattern' => 'mla_website_mcq_variant', 'mlaWebsiteMcqVariant' => sanitize_key( $variant ), 'scenario' => sanitize_textarea_field( $built['stem'] ),
			'authorType' => sanitize_key( $author['type'] ), 'authors' => 'individual' === $author['type'] ? array( array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'givenName' => sanitize_text_field( $author['givenName'] ) ) ) : array(), 'organisationName' => 'organisation' === $author['type'] ? sanitize_text_field( $author['name'] ) : '',
			'year' => sanitize_text_field( $year ), 'pageTitle' => sanitize_text_field( $title ), 'url' => sanitize_text_field( $url ), 'accessedDate' => sanitize_text_field( $accessed_date ),
			'options' => array_values( array_map( 'sanitize_text_field', $options ) ), 'hint' => sanitize_textarea_field( $hint ), 'answerExplanation' => sanitize_textarea_field( $answer_explanation ), 'reconstructedReference' => sanitize_text_field( $built['correctAnswer'] ),
			'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ),
		);
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
				$rejection = self::quality_reject( 'citex_ai_identify_error_option_matches_answer', sprintf( __( 'Question %s has a "wrong" description identical to the true one.', 'citex-tools' ), $id ) );
				if ( $rejection ) { return $rejection; }
			}
			if ( isset( $seen[ $normal ] ) ) {
				$rejection = self::quality_reject( 'citex_ai_identify_error_duplicate_option', sprintf( __( 'Question %s has a duplicate wrongDescription.', 'citex-tools' ), $id ) );
				if ( $rejection ) { return $rejection; }
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
			'institution' => 'Harvard',
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
				$rejection = self::quality_reject( 'citex_ai_treatment_option_matches_answer', sprintf( __( 'Question %s has a "wrong" statement identical to the true one.', 'citex-tools' ), $id ) );
				if ( $rejection ) { return $rejection; }
			}
			if ( isset( $seen[ $normal ] ) ) {
				$rejection = self::quality_reject( 'citex_ai_treatment_duplicate_option', sprintf( __( 'Question %s has a duplicate wrongStatement.', 'citex-tools' ), $id ) );
				if ( $rejection ) { return $rejection; }
			}
			$seen[ $normal ] = true;
			// HARD ENFORCEMENT, not just a prompt request: the true statement
			// (Citex_Reference_Rules::treatment_question()) is always a short,
			// example-free claim — a wrongStatement carrying a worked example
			// ("e.g. Smith, J. and Jones, A.") stands out as visibly longer
			// and differently styled from the other three options, which
			// itself gives the answer away. A soft prompt instruction alone
			// is not reliably followed by Gemini, so this is a real WP_Error
			// (like the option-count check above), never gated behind
			// QUALITY_GATE_ENABLED/quality_reject() — length/style
			// consistency between all four options is a structural
			// requirement for this MCQ pattern, not an optional quality nit.
			if ( 1 === preg_match( '/\be\.?\s?g\.?\b|\bfor example\b|\bfor instance\b/i', $statement ) ) {
				return new WP_Error( 'citex_ai_treatment_option_has_example', sprintf( __( 'Question %1$s has a wrongStatement with a worked example, making it visibly different from the true statement: "%2$s".', 'citex-tools' ), $id, $statement ) );
			}
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
			'institution' => 'Harvard',
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
	 * Citex — not Gemini — authors the ENTIRE Edited Book DragDrop
	 * question: which 3 parts are drawn (the editor+designation pair is
	 * always forced; the third is a random pick from
	 * year/title/place/publisher/"and"), the wrong ("confusing") chip for
	 * each, Question Parts, and Fixed Text, via
	 * Citex_Edited_Book_Dragdrop_Parts — the complete reference is always
	 * shown in full; nothing is ever omitted (replaces the old fixed
	 * named-design catalogue, which hid every field except its own 3).
	 * Gemini supplies nothing beyond the canonical record
	 * ($editors/$year/$title/$place/$publisher) and a non-leaking scenario
	 * — no distractor text (see Citex_Generated_Validator::validate_dragdrop()'s
	 * Edited Book block, which recomputes and exact-matches this from the
	 * stored `dragdropPartKeys` selection).
	 *
	 * @return array|WP_Error
	 */
	private static function normalise_edited_book_item( $item, $id, $editors, $year, $title, $place, $publisher, $scenario, $exercise, $difficulty ) {
		$fields = array( 'year' => $year, 'title' => $title, 'place' => $place, 'publisher' => $publisher );
		$selected_keys = Citex_Edited_Book_Dragdrop_Parts::select_parts( $id, $editors );
		$built = Citex_Edited_Book_Dragdrop_Parts::build( $selected_keys, $editors, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_edited_book_dragdrop_parts_unknown', sprintf( __( 'Question %s: unable to build Edited Book DragDrop parts for this record.', 'citex-tools' ), $id ) );
		}
		$reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, array_merge( $fields, array( 'editors' => $editors ) ) );
		$editor_full_names = array_column( $editors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'Harvard | ReferenceList | Edited Book | DragDrop | %s', $id ), 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Edited Book', 'exercise' => $exercise, 'type' => 'DragDrop', 'institution' => 'Harvard', 'difficulty' => ucfirst( $difficulty ), 'dragdropPartKeys' => array_values( array_map( 'sanitize_key', $selected_keys ) ), 'scenario' => sanitize_textarea_field( $scenario ), 'editors' => array_map( function ( $editor ) { return array( 'fullName' => sanitize_text_field( $editor['fullName'] ), 'surname' => sanitize_text_field( $editor['surname'] ), 'initials' => sanitize_text_field( $editor['initials'] ) ); }, $editors ), 'editorFullNames' => array_values( array_map( 'sanitize_text_field', $editor_full_names ) ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'place' => sanitize_text_field( $place ), 'publisher' => sanitize_text_field( $publisher ), 'fixedText' => sanitize_text_field( $built['fixedText'] ), 'questionParts' => array_values( array_map( 'sanitize_text_field', $built['parts'] ) ), 'confusingWords' => array_values( array_map( 'sanitize_text_field', $built['confusingWords'] ) ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * Edited Book counterpart to normalise_book_mcq_variant_item(): same "Citex builds
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
				$rejection = self::quality_reject( 'citex_ai_mcq_option_matches_correct', sprintf( __( 'Question %s has an "incorrect" reference option identical to the correct one.', 'citex-tools' ), $id ) );
				if ( $rejection ) { return $rejection; }
			}
			if ( isset( $seen[ $normal ] ) ) {
				$rejection = self::quality_reject( 'citex_ai_mcq_duplicate_option', sprintf( __( 'Question %s has a duplicate incorrect reference option.', 'citex-tools' ), $id ) );
				if ( $rejection ) { return $rejection; }
			}
			$seen[ $normal ] = true;
		}

		// Option 1-3 hold the 3 distractors, in the order Gemini supplied
		// them; Option 4 is ALWAYS left blank. The correct answer lives only
		// in the Answer field (reconstructedReference, below) — never placed
		// into, or duplicated into, any option slot. See
		// normalise_book_mcq_variant_item()'s matching comment for the full rationale.
		$options = $incorrect;
		$options[] = '';
		$option_reasons = array_map( 'sanitize_text_field', array_column( $distractors, 'errorReason' ) );
		$option_reasons[] = null;

		$expected_designation = Citex_Reference_Rules::designation_for_editor_count( count( $editors ) );

		// Citex — not Gemini — writes the hint too, from the category's own
		// fixed, non-revealing clue (never named to which option it points)
		// — see normalise_book_mcq_variant_item()'s matching comment for the full
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
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'Harvard | ReferenceList | Edited Book | MCQ | %s', $id ), 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Edited Book', 'exercise' => $exercise, 'type' => 'MCQ', 'institution' => 'Harvard', 'difficulty' => ucfirst( $difficulty ), 'scenario' => sanitize_textarea_field( $scenario ), 'editors' => array_map( function ( $editor ) { return array( 'fullName' => sanitize_text_field( $editor['fullName'] ), 'surname' => sanitize_text_field( $editor['surname'] ), 'initials' => sanitize_text_field( $editor['initials'] ) ); }, $editors ), 'editorFullNames' => array_values( array_map( 'sanitize_text_field', $editor_full_names ) ), 'year' => sanitize_text_field( $year ), 'bookTitle' => sanitize_text_field( $title ), 'place' => sanitize_text_field( $place ), 'publisher' => sanitize_text_field( $publisher ), 'options' => array_values( array_map( 'sanitize_text_field', $options ) ), 'optionErrorReasons' => $option_reasons, 'hint' => sanitize_textarea_field( $hint ), 'answerExplanation' => sanitize_textarea_field( $answer_explanation ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * Citex — not Gemini — authors the ENTIRE Journal Article DragDrop
	 * question: which 3 parts are drawn (from author name, year, title,
	 * journal, volume, issue, pages, and "and"), the wrong ("confusing")
	 * chip for each, Question Parts, and Fixed Text, via
	 * Citex_Journal_Article_Dragdrop_Parts — the complete Cite Them Right
	 * reference is always shown in full; nothing is ever omitted (replaces
	 * the old fixed named-design catalogue, which hid every field except
	 * its own 3 — e.g. "Journal, Volume(Issue)" alone, with no
	 * author/year/title/pages at all). Gemini supplies nothing beyond the
	 * canonical record ($authors/$year/$article_title/$journal_title/
	 * $volume/$issue/$pages) and a non-leaking scenario — no distractor
	 * text (see Citex_Generated_Validator::validate_dragdrop()'s Journal
	 * Article block, which recomputes and exact-matches this from the
	 * stored `dragdropPartKeys` selection).
	 *
	 * @param array $authors array<{fullName, surname, initials}>, 1 or more.
	 * @return array|WP_Error
	 */
	private static function normalise_journal_article_item( $item, $id, $authors, $year, $article_title, $journal_title, $volume, $issue, $pages, $scenario, $exercise, $difficulty ) {
		$fields = array( 'year' => $year, 'articleTitle' => $article_title, 'journalTitle' => $journal_title, 'volume' => $volume, 'issue' => $issue, 'pages' => $pages );
		$selected_keys = Citex_Journal_Article_Dragdrop_Parts::select_parts( $id, $authors );
		$built = Citex_Journal_Article_Dragdrop_Parts::build( $selected_keys, $authors, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_journal_article_dragdrop_parts_unknown', sprintf( __( 'Question %s: unable to build Journal Article DragDrop parts for this record.', 'citex-tools' ), $id ) );
		}
		$reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, array_merge( $fields, array( 'authors' => $authors ) ) );
		$author_full_names = array_column( $authors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'Harvard | ReferenceList | Journal Article | DragDrop | %s', $id ), 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'exercise' => $exercise, 'type' => 'DragDrop', 'institution' => 'Harvard', 'difficulty' => ucfirst( $difficulty ), 'dragdropPartKeys' => array_values( array_map( 'sanitize_key', $selected_keys ) ), 'scenario' => sanitize_textarea_field( $scenario ), 'authors' => array_map( function ( $author ) { return array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'initials' => sanitize_text_field( $author['initials'] ) ); }, $authors ), 'authorFullNames' => array_values( array_map( 'sanitize_text_field', $author_full_names ) ), 'authorFullName' => sanitize_text_field( $authors[0]['fullName'] ), 'authorSurname' => sanitize_text_field( $authors[0]['surname'] ), 'authorInitials' => sanitize_text_field( $authors[0]['initials'] ), 'year' => sanitize_text_field( $year ), 'articleTitle' => sanitize_text_field( $article_title ), 'journalTitle' => sanitize_text_field( $journal_title ), 'volume' => sanitize_text_field( $volume ), 'issue' => sanitize_text_field( $issue ), 'pages' => sanitize_text_field( $pages ), 'fixedText' => sanitize_text_field( $built['fixedText'] ), 'questionParts' => array_values( array_map( 'sanitize_text_field', $built['parts'] ) ), 'confusingWords' => array_values( array_map( 'sanitize_text_field', $built['confusingWords'] ) ), 'reconstructedReference' => sanitize_text_field( $reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * Journal Article counterpart to normalise_book_mcq_variant_item(): same "Citex
	 * builds the one correct option, Gemini only ever supplies 3 incorrect
	 * ones" principle, using Citex_Reference_Rules::build_reference() for
	 * this category's format.
	 *
	 * @return array|WP_Error
	 */
	private static function normalise_journal_article_mcq_item( $item, $id, $authors, $year, $article_title, $journal_title, $volume, $issue, $pages, $scenario, $exercise, $difficulty, $exercise_design = 'full_reference' ) {
		$distractors = self::extract_mcq_distractors( $item, $id );
		if ( is_wp_error( $distractors ) ) {
			return $distractors;
		}
		$incorrect = array_column( $distractors, 'reference' );

		$fields = array( 'authors' => $authors, 'year' => $year, 'articleTitle' => $article_title, 'journalTitle' => $journal_title, 'volume' => $volume, 'issue' => $issue, 'pages' => $pages );
		// The correct answer for THIS design (a short segment for every
		// design except 'full_reference') — built via the identical
		// shape+reconstruct algorithm DragDrop uses, so the two mechanics
		// can never silently disagree for any design (see
		// Citex_Reference_Rules::reconstruct_reference()'s docblock). This
		// is a pure refactor for 'full_reference' itself: the string
		// produced is unchanged from calling build_reference() directly.
		$shape     = Citex_Reference_Rules::dragdrop_shape( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields, $exercise_design );
		$reference = Citex_Reference_Rules::reconstruct_reference( $shape );
		$canonical_reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE, $fields );
		// MOBILE SUITABILITY for MCQ — only meaningful for the short-segment
		// designs, whose options are individual components exactly like
		// DragDrop's draggable parts. 'full_reference' options are,
		// structurally and unavoidably, complete reference strings —
		// comparing THOSE against
		// the same per-component threshold used for a single short segment
		// would reject every one of them; their conciseness is instead
		// steered at the source-selection stage by conciseness_guidance().
		if ( 'full_reference' !== $exercise_design ) {
			$mobile_reason = Citex_Reference_Rules::part_suitability( array_merge( array( $reference ), $incorrect ), self::configured_part_word_limit() );
			if ( null !== $mobile_reason ) {
				$rejection = self::quality_reject( 'citex_ai_journal_article_mobile_unsuitable', sprintf( __( 'Question %1$s: %2$s', 'citex-tools' ), $id, $mobile_reason ) );
				if ( $rejection ) { return $rejection; }
			}
		}
		$correct_normal = strtolower( trim( preg_replace( '/\s+/', ' ', $reference ) ) );
		$seen = array( $correct_normal => true );
		foreach ( $incorrect as $option ) {
			$normal = strtolower( trim( preg_replace( '/\s+/', ' ', $option ) ) );
			if ( $normal === $correct_normal ) {
				$rejection = self::quality_reject( 'citex_ai_mcq_option_matches_correct', sprintf( __( 'Question %s has an "incorrect" reference option identical to the correct one.', 'citex-tools' ), $id ) );
				if ( $rejection ) { return $rejection; }
			}
			if ( isset( $seen[ $normal ] ) ) {
				$rejection = self::quality_reject( 'citex_ai_mcq_duplicate_option', sprintf( __( 'Question %s has a duplicate incorrect reference option.', 'citex-tools' ), $id ) );
				if ( $rejection ) { return $rejection; }
			}
			$seen[ $normal ] = true;
		}

		// Option 1-3 hold the 3 distractors; Option 4 is ALWAYS blank. The
		// correct answer lives only in the Answer field (reconstructedReference,
		// below) — see normalise_book_mcq_variant_item()'s matching comment for the full
		// rationale.
		$options = $incorrect;
		$options[] = '';
		$option_reasons = array_map( 'sanitize_text_field', array_column( $distractors, 'errorReason' ) );
		$option_reasons[] = null;

		$hint = Citex_Reference_Rules::mcq_hint( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE );
		$answer_explanation = 'full_reference' === $exercise_design
			? 'The correct reference follows the required Harvard reference structure: Surname, Initials. (Year) \'Article title\', Journal title, Volume(Issue), pp. Start–End. — with every author listed in full and joined with "and"/commas when there is more than one.'
			: sprintf( 'The correct answer is the correctly Harvard-formatted "%s" component of this reference, derived from the real source data.', str_replace( '_', ' ', $exercise_design ) );

		$author_full_names = array_column( $authors, 'fullName' );
		return array( 'key' => wp_generate_uuid4(), 'questionId' => $id, 'title' => sprintf( 'Harvard | ReferenceList | Journal Article | MCQ | %s', $id ), 'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Journal Article', 'exercise' => $exercise, 'type' => 'MCQ', 'institution' => 'Harvard', 'difficulty' => ucfirst( $difficulty ), 'exerciseDesign' => sanitize_key( $exercise_design ), 'scenario' => sanitize_textarea_field( $scenario ), 'authors' => array_map( function ( $author ) { return array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'initials' => sanitize_text_field( $author['initials'] ) ); }, $authors ), 'authorFullNames' => array_values( array_map( 'sanitize_text_field', $author_full_names ) ), 'authorFullName' => sanitize_text_field( $authors[0]['fullName'] ), 'authorSurname' => sanitize_text_field( $authors[0]['surname'] ), 'authorInitials' => sanitize_text_field( $authors[0]['initials'] ), 'year' => sanitize_text_field( $year ), 'articleTitle' => sanitize_text_field( $article_title ), 'journalTitle' => sanitize_text_field( $journal_title ), 'volume' => sanitize_text_field( $volume ), 'issue' => sanitize_text_field( $issue ), 'pages' => sanitize_text_field( $pages ), 'options' => array_values( array_map( 'sanitize_text_field', $options ) ), 'optionErrorReasons' => $option_reasons, 'hint' => sanitize_textarea_field( $hint ), 'answerExplanation' => sanitize_textarea_field( $answer_explanation ), 'reconstructedReference' => sanitize_text_field( $reference ), 'canonicalReference' => sanitize_text_field( $canonical_reference ), 'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ) );
	}

	/**
	 * Citex — never Gemini — supplies the Website category's accessed date,
	 * deterministically, from the actual generation date rather than asking
	 * Gemini for one at all (see build_prompt_website()'s "Do NOT provide an
	 * accessed date" instruction). This both matches the explicit "use the
	 * actual generation/access date rather than an arbitrary historical
	 * date" requirement and removes an entire class of failure (an invented
	 * or impossible access date) that every other Website field still needs
	 * guarding against.
	 */
	private static function current_accessed_date() {
		return gmdate( 'j F Y' );
	}

	/**
	 * Citex — not Gemini — authors the ENTIRE Website DragDrop question:
	 * which 3 of the 4 fields (author/org, year, title, accessedDate) are
	 * drawn, the wrong ("confusing") chip for each, Question Parts, and
	 * Fixed Text, via Citex_Website_Dragdrop_Parts — the complete reference
	 * is always shown in full; nothing is ever omitted. There is no
	 * publisher element in this format at all — $publisher is still
	 * accepted and stored on the record (used only to verify the source is
	 * real), but never appears in the built reference or as a draggable
	 * field. Gemini supplies nothing beyond the canonical record
	 * ($author/$year/$title/$publisher/$url) and a non-leaking scenario —
	 * no distractor text, and no accessed date (Citex computes that itself,
	 * below) — see Citex_Generated_Validator::validate_dragdrop()'s Website
	 * block, which recomputes and exact-matches this from the stored
	 * `dragdropPartKeys` selection.
	 *
	 * @param array $author {type: 'individual'|'organisation', fullName?, surname?, initials?, name?}.
	 * @return array|WP_Error
	 */
	private static function normalise_website_item( $item, $id, $author, $year, $title, $publisher, $url, $scenario, $exercise, $difficulty ) {
		$accessed_date = self::current_accessed_date();
		$fields = array( 'year' => $year, 'title' => $title, 'publisher' => $publisher, 'url' => $url, 'accessedDate' => $accessed_date );
		$selected_keys = Citex_Website_Dragdrop_Parts::select_parts( $id );
		$built = Citex_Website_Dragdrop_Parts::build( $selected_keys, $author, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_website_dragdrop_parts_unknown', sprintf( __( 'Question %s: unable to build Website DragDrop parts for this record.', 'citex-tools' ), $id ) );
		}
		$reference = Citex_Reference_Rules::build_reference( Citex_Reference_Rules::CATEGORY_WEBSITE, array_merge( $fields, array( 'author' => $author ) ) );
		return array(
			'key' => wp_generate_uuid4(), 'questionId' => $id,
			'title' => sprintf( 'Harvard | ReferenceList | Website | DragDrop | %s', $id ),
			'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Website', 'exercise' => $exercise, 'type' => 'DragDrop',
			'institution' => 'Harvard', 'difficulty' => ucfirst( $difficulty ), 'dragdropPartKeys' => array_values( array_map( 'sanitize_key', $selected_keys ) ), 'scenario' => sanitize_textarea_field( $scenario ),
			'authorType' => $author['type'],
			'authors' => 'individual' === $author['type'] ? array( array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'initials' => sanitize_text_field( $author['initials'] ) ) ) : array(),
			'organisationName' => 'organisation' === $author['type'] ? sanitize_text_field( $author['name'] ) : '',
			'year' => sanitize_text_field( $year ), 'pageTitle' => sanitize_text_field( $title ), 'publisher' => sanitize_text_field( $publisher ), 'url' => sanitize_text_field( $url ), 'accessedDate' => sanitize_text_field( $accessed_date ),
			'fixedText' => self::sanitize_reference_text( $built['fixedText'] ), 'questionParts' => array_values( array_map( 'sanitize_text_field', $built['parts'] ) ), 'confusingWords' => array_values( array_map( 'sanitize_text_field', $built['confusingWords'] ) ),
			'reconstructedReference' => self::sanitize_reference_text( $reference ),
			'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ),
		);
	}

	/**
	 * Website counterpart to normalise_book_mcq_variant_item(): same
	 * "Citex builds the ENTIRE question — stem, all 4 options, and the
	 * answer — deterministically from one canonical record; Gemini
	 * supplies nothing beyond that record" principle, using
	 * Citex_Website_Mcq_Variants (replaces the old "Gemini supplies 3
	 * distractors" mechanic entirely).
	 *
	 * @return array|WP_Error
	 */
	private static function normalise_website_mcq_variant_item( $item, $id, $author, $year, $title, $publisher, $url, $exercise, $difficulty ) {
		$accessed_date = self::current_accessed_date();
		$fields  = array( 'author' => $author, 'year' => $year, 'title' => $title, 'publisher' => $publisher, 'url' => $url, 'accessedDate' => $accessed_date );
		$variant = Citex_Website_Mcq_Variants::variant_for( $id );
		$built   = Citex_Website_Mcq_Variants::build( $variant, $fields );
		if ( null === $built ) {
			return new WP_Error( 'citex_ai_website_mcq_variant_unknown', sprintf( __( 'Question %1$s: unrecognised Website MCQ variant "%2$s".', 'citex-tools' ), $id, $variant ) );
		}

		// Option 1-3 hold the 3 Citex-authored wrong options; Option 4 is
		// ALWAYS left blank — see normalise_book_mcq_variant_item()'s
		// matching comment for the full rationale.
		$options = $built['wrongOptions'];
		$options[] = '';

		// Citex — not Gemini — writes the hint too, deterministically from
		// the category's own fixed, non-revealing clue (unchanged from the
		// mechanic this replaces).
		$hint = Citex_Reference_Rules::mcq_hint( Citex_Reference_Rules::CATEGORY_WEBSITE );
		$answer_explanation = sprintf( 'This question tests the "%s" Harvard Website formatting rule.', $variant );

		return array(
			'key' => wp_generate_uuid4(), 'questionId' => $id,
			'title' => sprintf( 'Harvard | ReferenceList | Website | MCQ | %s', $id ),
			'source' => 'Harvard', 'group' => 'ReferenceList', 'category' => 'Website', 'exercise' => $exercise, 'type' => 'MCQ',
			'institution' => 'Harvard', 'difficulty' => ucfirst( $difficulty ), 'mcqPattern' => 'website_mcq_variant', 'websiteMcqVariant' => sanitize_key( $variant ),
			'scenario' => self::sanitize_reference_textarea( $built['stem'] ),
			'authorType' => $author['type'],
			'authors' => 'individual' === $author['type'] ? array( array( 'fullName' => sanitize_text_field( $author['fullName'] ), 'surname' => sanitize_text_field( $author['surname'] ), 'initials' => sanitize_text_field( $author['initials'] ) ) ) : array(),
			'organisationName' => 'organisation' === $author['type'] ? sanitize_text_field( $author['name'] ) : '',
			'year' => sanitize_text_field( $year ), 'pageTitle' => sanitize_text_field( $title ), 'publisher' => sanitize_text_field( $publisher ), 'url' => sanitize_text_field( $url ), 'accessedDate' => sanitize_text_field( $accessed_date ),
			'options' => array_values( array_map( array( __CLASS__, 'sanitize_reference_text' ), $options ) ),
			'hint' => sanitize_textarea_field( $hint ), 'answerExplanation' => sanitize_textarea_field( $answer_explanation ),
			'reconstructedReference' => self::sanitize_reference_text( $built['correctAnswer'] ),
			'status' => 'pending', 'validationStatus' => 'not_validated', 'validationErrors' => array(), 'origin' => 'generated_ai', 'aiProvider' => 'Gemini', 'aiModel' => self::get_model(), 'generatedAt' => gmdate( 'c' ),
		);
	}

	/**
	 * Like sanitize_text_field() but does NOT strip HTML-like "<...>"
	 * markup. Needed because Website's Harvard format legitimately
	 * requires a literal angle-bracketed URL ("Available from: <URL>") in
	 * fixedText/reconstructedReference/MCQ option text — sanitize_text_field()
	 * calls wp_strip_all_tags() internally, which treats "<https://example.com>"
	 * as an (unclosed) HTML tag and silently deletes the ENTIRE bracketed
	 * segment, dropping the URL from the stored question entirely. No other
	 * category's reference text ever contains "<" or ">", so this is only
	 * used for Website's own normalisers. Every consumer already
	 * output-escapes this value with esc_html() (see admin/views/generate.php),
	 * so skipping tag-stripping here introduces no XSS risk — it only stops
	 * WordPress from corrupting legitimate Harvard-format content.
	 */
	private static function sanitize_reference_text( $value ) {
		$value = (string) $value;
		$value = preg_replace( '/[\r\n\t]+/', ' ', $value );
		$value = preg_replace( '/ {2,}/', ' ', $value );
		return trim( $value );
	}

	/**
	 * Like sanitize_reference_text() but preserves newlines instead of
	 * collapsing them to spaces — needed for Website MCQ's
	 * 'identify_the_error' variant, whose stem deliberately embeds a
	 * literal "\n\n"-separated broken reference (see
	 * Citex_Website_Mcq_Variants::build_identify_the_error()) containing
	 * the same bracketed "<URL>" sanitize_textarea_field() would otherwise
	 * silently delete as an unclosed HTML tag — the exact bug
	 * sanitize_reference_text() already exists to avoid for single-line
	 * fields, just also needed here for a multi-line one.
	 */
	private static function sanitize_reference_textarea( $value ) {
		$value = (string) $value;
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = preg_replace( '/[ \t]{2,}/', ' ', $value );
		$value = preg_replace( '/\n{3,}/', "\n\n", $value );
		return trim( $value );
	}

	private static function output_text( $data ) {
		if ( ! empty( $data['output_text'] ) && is_string( $data['output_text'] ) ) { return trim( $data['output_text'] ); }
		$text = array(); foreach ( (array) ( $data['steps'] ?? array() ) as $step ) { if ( 'model_output' !== ( $step['type'] ?? '' ) ) { continue; } foreach ( (array) ( $step['content'] ?? array() ) as $content ) { if ( isset( $content['text'] ) && is_string( $content['text'] ) ) { $text[] = $content['text']; } } } return trim( implode( "\n", $text ) );
	}
	private static function strip_fences( $text ) { $text = trim( $text ); $text = preg_replace( '/^```(?:json)?\s*/i', '', $text ); $text = preg_replace( '/\s*```$/', '', $text ); return trim( $text ); }
}
