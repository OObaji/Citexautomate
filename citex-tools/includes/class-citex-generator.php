<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Citex question generator.
 *
 * Generation is now AI-backed. Gemini creates real structured questions;
 * Citex validates them independently and keeps them in the pending queue
 * until they are explicitly populated into the real Reference List.
 */
class Citex_Generator {

	const NONCE_ACTION   = 'citex_generate_questions';
	const OPTION_PENDING = 'citex_pending_questions';

	public function render() {
		$this->maybe_handle_submit();

		$referencing_styles = array( 'harvard' => 'Harvard', 'mla' => 'MLA', 'apa' => 'APA 7th', 'chicago' => 'Chicago (Author-Date)', 'mhra' => 'MHRA' );
		$categories         = array( 'book' => 'Book', 'edited_book' => 'Edited Book', 'journal_article' => 'Journal Article', 'website' => 'Website' );
		$id_prefixes        = array(
			'book'            => Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_BOOK ),
			'edited_book'     => Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_EDITED_BOOK ),
			'journal_article' => Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_JOURNAL_ARTICLE ),
			'website'         => Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_WEBSITE ),
		);
		// MLA reference-list now covers all 4 categories, each with its own
		// prefix (an "M" prefixed onto Harvard's own letter — MB/ME/MJ/MW);
		// the admin UI's JS below picks whichever of the four prefixes
		// matches the currently selected Referencing Style + Question Focus
		// combination.
		$mla_id_prefixes    = array(
			'book'            => Citex_MLA_Reference_Rules::id_prefix( Citex_MLA_Reference_Rules::CATEGORY_BOOK ),
			'edited_book'     => Citex_MLA_Reference_Rules::id_prefix( Citex_MLA_Reference_Rules::CATEGORY_EDITED_BOOK ),
			'journal_article' => Citex_MLA_Reference_Rules::id_prefix( Citex_MLA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE ),
			'website'         => Citex_MLA_Reference_Rules::id_prefix( Citex_MLA_Reference_Rules::CATEGORY_WEBSITE ),
		);
		// APA reference-list now covers all 4 categories, each with its own
		// prefix (an "A" prefixed onto Harvard's own letter — AB/AE/AJ/AW);
		// the admin UI's JS below picks whichever of the three styles'
		// prefixes matches the currently selected Referencing Style +
		// Question Focus combination.
		$apa_id_prefixes    = array(
			'book'            => Citex_APA_Reference_Rules::id_prefix( Citex_APA_Reference_Rules::CATEGORY_BOOK ),
			'edited_book'     => Citex_APA_Reference_Rules::id_prefix( Citex_APA_Reference_Rules::CATEGORY_EDITED_BOOK ),
			'journal_article' => Citex_APA_Reference_Rules::id_prefix( Citex_APA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE ),
			'website'         => Citex_APA_Reference_Rules::id_prefix( Citex_APA_Reference_Rules::CATEGORY_WEBSITE ),
		);
		// Chicago (Author-Date) reference-list now covers all 4 categories,
		// each with its own prefix (a "C" prefixed onto Harvard's own letter
		// — CB/CE/CJ/CW) — the same Phase 2 build-out APA/MLA already went
		// through.
		$chicago_id_prefixes = array(
			'book'            => Citex_Chicago_Reference_Rules::id_prefix( Citex_Chicago_Reference_Rules::CATEGORY_BOOK ),
			'edited_book'     => Citex_Chicago_Reference_Rules::id_prefix( Citex_Chicago_Reference_Rules::CATEGORY_EDITED_BOOK ),
			'journal_article' => Citex_Chicago_Reference_Rules::id_prefix( Citex_Chicago_Reference_Rules::CATEGORY_JOURNAL_ARTICLE ),
			'website'         => Citex_Chicago_Reference_Rules::id_prefix( Citex_Chicago_Reference_Rules::CATEGORY_WEBSITE ),
		);
		// MHRA (Bibliography) reference-list now covers all 4 categories,
		// each with its own prefix ("H" prefixed onto Harvard's own letter —
		// HB/HE/HJ/HW) — the same Phase 2 build-out APA/MLA/Chicago already
		// went through.
		$mhra_id_prefixes = array(
			'book'            => Citex_MHRA_Reference_Rules::id_prefix( Citex_MHRA_Reference_Rules::CATEGORY_BOOK ),
			'edited_book'     => Citex_MHRA_Reference_Rules::id_prefix( Citex_MHRA_Reference_Rules::CATEGORY_EDITED_BOOK ),
			'journal_article' => Citex_MHRA_Reference_Rules::id_prefix( Citex_MHRA_Reference_Rules::CATEGORY_JOURNAL_ARTICLE ),
			'website'         => Citex_MHRA_Reference_Rules::id_prefix( Citex_MHRA_Reference_Rules::CATEGORY_WEBSITE ),
		);
		// In-text citation has no reference-list category restriction at
		// all (see self::intext_id_prefix()'s docblock) — every category
		// gets its own prefix under all 3 styles.
		$intext_id_prefixes     = array();
		$mla_intext_id_prefixes = array();
		$apa_intext_id_prefixes = array();
		foreach ( $categories as $key => $label ) {
			$intext_id_prefixes[ $key ]     = self::intext_id_prefix( $label, 'harvard' );
			$mla_intext_id_prefixes[ $key ] = self::intext_id_prefix( $label, 'mla' );
			$apa_intext_id_prefixes[ $key ] = self::intext_id_prefix( $label, 'apa' );
		}
		$question_types     = array( 'dragdrop' => 'DragDrop', 'mcq' => 'MCQ' );
		$difficulties       = array( 'easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard' );
		$question_groups    = array( 'referencelist' => 'Reference List', 'intext' => 'In-Text Citation' );
		$citation_forms     = array( 'narrative' => 'Narrative', 'parenthetical' => 'Parenthetical', 'parenthetical_quote' => 'Parenthetical (Direct Quote)' );
		// Author Count dropdown data: {category_key: {type_key: {scenario_id: label}}}
		// — reused directly from the SAME scenario catalog that already
		// drives batch-diversity auto-selection (Citex_Question_Scenarios),
		// never a separate/new bucket list, per generate_via_scenarios()'s
		// own reuse of that catalog for forced-scenario validation.
		$scenario_catalog = array();
		foreach ( $categories as $key => $label ) {
			$scenario_catalog[ $key ] = array(
				'dragdrop' => wp_list_pluck( Citex_Question_Scenarios::catalog( $label, 'DragDrop' ), 'label', 'id' ),
				'mcq'      => wp_list_pluck( Citex_Question_Scenarios::catalog( $label, 'MCQ' ), 'label', 'id' ),
			);
		}
		$pending_questions  = self::get_pending_questions();
		$ai_configured      = '' !== Citex_AI_V2::get_api_key();
		require CITEX_TOOLS_PATH . 'admin/views/generate.php';
	}

	/**
	 * The in-text citation ID prefix map — an `I`/`MI` prefix onto each
	 * category's own reference-list letter (IB/IE/IJ/IW for Harvard, an
	 * `M` prefixed onto each for MLA: MIB/MIE/MIJ/MIW), exactly how MB was
	 * built from BK's own pattern for MLA Book. Independent of
	 * Citex_Reference_Rules::id_prefix()/Citex_MLA_Reference_Rules::id_prefix()
	 * (which name reference-list prefixes only) since in-text citation is a
	 * wholly separate `group` — both groups now cover all 4 categories
	 * under both styles, with no restriction remaining either way.
	 */
	public static function intext_id_prefix( $category_label, $style = 'harvard' ) {
		$harvard = array(
			'Book'             => 'IB',
			'Edited Book'      => 'IE',
			'Journal Article'  => 'IJ',
			'Website'          => 'IW',
		);
		$mla = array(
			'Book'             => 'MIB',
			'Edited Book'      => 'MIE',
			'Journal Article'  => 'MIJ',
			'Website'          => 'MIW',
		);
		$apa = array(
			'Book'             => 'AIB',
			'Edited Book'      => 'AIE',
			'Journal Article'  => 'AIJ',
			'Website'          => 'AIW',
		);
		$map = 'mla' === $style ? $mla : ( 'apa' === $style ? $apa : $harvard );
		return $map[ $category_label ] ?? ( 'mla' === $style ? 'MI' : ( 'apa' === $style ? 'AI' : 'I' ) );
	}

	public static function get_pending_questions() {
		$pending = get_option( self::OPTION_PENDING, array() );
		return is_array( $pending ) ? array_values( $pending ) : array();
	}

	public static function save_pending_questions( $pending ) {
		update_option( self::OPTION_PENDING, array_values( is_array( $pending ) ? $pending : array() ), false );
	}

	public static function get_pending_count() {
		return count( self::get_pending_questions() );
	}

	const EXERCISES = array( 'Exercise 1', 'Exercise 2', 'Exercise 3', 'Exercise 4', 'Exercise 5' );

	/**
	 * Combined Category x Exercise x Type coverage: already-populated
	 * records (Citex_Populator's own persistent count, since a populated
	 * question leaves the pending queue and its WordPress taxonomy is not
	 * otherwise visible to the scanner) plus not-yet-populated pending
	 * questions already carrying this classification. Both count towards
	 * "already covered" so a new batch does not pile more questions onto a
	 * slot that is merely pending, nor onto one already populated.
	 */
	public static function compute_category_coverage( $category ) {
		$coverage = array();
		foreach ( self::EXERCISES as $exercise ) {
			$coverage[ $exercise ] = array( 'DragDrop' => 0, 'MCQ' => 0 );
		}

		$populated = Citex_Populator::get_population_coverage();
		foreach ( ( $populated[ $category ] ?? array() ) as $exercise => $types ) {
			if ( ! isset( $coverage[ $exercise ] ) ) {
				continue;
			}
			foreach ( $types as $type => $count ) {
				if ( isset( $coverage[ $exercise ][ $type ] ) ) {
					$coverage[ $exercise ][ $type ] += (int) $count;
				}
			}
		}

		foreach ( self::get_pending_questions() as $question ) {
			if ( $category !== (string) ( $question['category'] ?? '' ) ) {
				continue;
			}
			$exercise = (string) ( $question['exercise'] ?? '' );
			$type     = (string) ( $question['type'] ?? '' );
			if ( isset( $coverage[ $exercise ][ $type ] ) ) {
				$coverage[ $exercise ][ $type ]++;
			}
		}

		return $coverage;
	}

	/**
	 * Deterministically assign each of $quantity generation slots to an
	 * Exercise, based on current coverage — Gemini is never asked to choose
	 * an exercise and nothing it returns is trusted for this (its response
	 * schema has no exercise field at all). Greedily fills whichever
	 * exercise currently has the fewest $type questions first (ties broken
	 * by Exercise 1-5 order), decrementing the deficit as each slot is
	 * assigned within this batch — so a 10-slot request naturally spreads
	 * 2 across every exercise instead of concentrating in whichever is
	 * lowest at the start, and a smaller request fills the most-needed
	 * exercises first rather than always starting at Exercise 1.
	 *
	 * @return string[] Exercise name for each of the $quantity slots, in order.
	 */
	public static function build_exercise_assignments( $category, $type, $quantity ) {
		$coverage = self::compute_category_coverage( $category );
		$counts   = array();
		foreach ( self::EXERCISES as $exercise ) {
			$counts[ $exercise ] = (int) ( $coverage[ $exercise ][ $type ] ?? 0 );
		}

		$assignments = array();
		for ( $i = 0; $i < $quantity; $i++ ) {
			$lowest_exercise = self::EXERCISES[0];
			$lowest_count    = $counts[ $lowest_exercise ];
			foreach ( self::EXERCISES as $exercise ) {
				if ( $counts[ $exercise ] < $lowest_count ) {
					$lowest_exercise = $exercise;
					$lowest_count    = $counts[ $exercise ];
				}
			}
			$assignments[] = $lowest_exercise;
			$counts[ $lowest_exercise ]++;
		}
		return $assignments;
	}

	/**
	 * Called on admin_init (before any output) as well as at the top of
	 * render(), so a redirect after submission always reaches the browser.
	 */
	public function maybe_handle_submit() {
		if (
			empty( $_POST['citex_generate_submit'] ) &&
			empty( $_POST['citex_clear_pending'] ) &&
			empty( $_POST['citex_delete_pending'] ) &&
			empty( $_POST['citex_validate_pending'] ) &&
			empty( $_POST['citex_validate_one_pending'] )
		) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, 'citex_generate_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'citex-tools' ) );
		}

		if ( ! empty( $_POST['citex_clear_pending'] ) ) {
			self::save_pending_questions( array() );
			Citex_Admin::set_notice( __( 'All pending generated questions were cleared. No WordPress questions were changed.', 'citex-tools' ), 'success' );
			$this->redirect_back();
		}

		if ( ! empty( $_POST['citex_delete_pending'] ) ) {
			$key = isset( $_POST['citex_pending_key'] ) ? sanitize_text_field( wp_unslash( $_POST['citex_pending_key'] ) ) : '';
			$pending = array_values( array_filter( self::get_pending_questions(), function ( $question ) use ( $key ) {
				return ( $question['key'] ?? '' ) !== $key;
			} ) );
			self::save_pending_questions( $pending );
			Citex_Admin::set_notice( __( 'Pending question removed.', 'citex-tools' ), 'success' );
			$this->redirect_back();
		}

		if ( ! empty( $_POST['citex_validate_pending'] ) ) {
			$this->validate_pending_batch();
		}

		if ( ! empty( $_POST['citex_validate_one_pending'] ) ) {
			$key = isset( $_POST['citex_pending_key'] ) ? sanitize_text_field( wp_unslash( $_POST['citex_pending_key'] ) ) : '';
			$this->validate_one_pending( $key );
		}

		$this->handle_generation();
	}

	private function handle_generation() {
		$style       = isset( $_POST['citex_referencing_style'] ) ? sanitize_key( wp_unslash( $_POST['citex_referencing_style'] ) ) : '';
		$category    = isset( $_POST['citex_category'] ) ? sanitize_key( wp_unslash( $_POST['citex_category'] ) ) : '';
		$type        = isset( $_POST['citex_question_type'] ) ? sanitize_key( wp_unslash( $_POST['citex_question_type'] ) ) : '';
		$difficulty  = isset( $_POST['citex_difficulty'] ) ? sanitize_key( wp_unslash( $_POST['citex_difficulty'] ) ) : 'medium';
		$quantity    = isset( $_POST['citex_quantity'] ) ? absint( $_POST['citex_quantity'] ) : 10;
		$starting_id = isset( $_POST['citex_starting_id'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['citex_starting_id'] ) ) ) : 'BK01';
		$web_verify  = ! empty( $_POST['citex_ai_web_verify'] );
		$group       = isset( $_POST['citex_question_group'] ) ? sanitize_key( wp_unslash( $_POST['citex_question_group'] ) ) : 'referencelist';
		if ( ! in_array( $group, array( 'referencelist', 'intext' ), true ) ) {
			$group = 'referencelist';
		}
		$citation_form = isset( $_POST['citex_citation_form'] ) ? sanitize_key( wp_unslash( $_POST['citex_citation_form'] ) ) : 'narrative';
		if ( ! in_array( $citation_form, array( 'narrative', 'parenthetical', 'parenthetical_quote' ), true ) ) {
			$citation_form = 'narrative';
		}
		$forced_scenario_id = isset( $_POST['citex_author_count_scenario'] ) ? sanitize_key( wp_unslash( $_POST['citex_author_count_scenario'] ) ) : 'auto';

		$category_labels = array( 'book' => 'Book', 'edited_book' => 'Edited Book', 'journal_article' => 'Journal Article', 'website' => 'Website' );

		$quantity   = max( 1, min( 100, $quantity ) );
		$style_ok   = in_array( $style, array( 'harvard', 'mla', 'apa', 'chicago', 'mhra' ), true );
		// MLA and APA reference-list both now cover all 4 categories (Book,
		// Edited Book, Journal Article, Website) — the same shared-structure
		// build-out already used for in-text citation (see
		// self::intext_id_prefix()'s docblock). No category restriction
		// remains for either of those 2 styles, under either group. Chicago
		// and MHRA are each Phase 1 (Book / Reference List only — see
		// Citex_Chicago_Reference_Rules's/Citex_MHRA_Reference_Rules's own
		// docblocks) and are checked separately below, once
		// $category_labels/$group are both resolved.
		$category_ok = isset( $category_labels[ $category ] );
		if ( ! $style_ok || ! $category_ok || ! in_array( $type, array( 'dragdrop', 'mcq' ), true ) ) {
			Citex_Admin::set_notice( __( 'The current AI generator supports Reference List and In-Text Citation, Harvard, MLA, APA, Chicago or MHRA, for Book, Edited Book, Journal Article or Website, as DragDrop or MCQ.', 'citex-tools' ), 'error' );
			$this->redirect_back();
		}
		// Chicago (Author-Date) Reference List now covers all 4 categories
		// (Book, Edited Book, Journal Article, Website) — the same Phase 2
		// build-out APA/MLA already went through (see the docblock above).
		// In-Text Citation is still a later phase, so $category is left to
		// $category_ok's own generic check above and only $group is
		// restricted here.
		$chicago_scope_ok = 'chicago' !== $style || 'referencelist' === $group;
		if ( ! $chicago_scope_ok ) {
			Citex_Admin::set_notice( __( 'Chicago (Author-Date) currently supports Reference List only — In-Text Citation is coming in a later update.', 'citex-tools' ), 'error' );
			$this->redirect_back();
		}
		// MHRA (11th edition) Reference List now covers all 4 categories
		// (Book, Edited Book, Journal Article, Website) — the same Phase 2
		// build-out APA/MLA/Chicago already went through. In-Text Citation
		// is still a later phase, so $category is left to $category_ok's own
		// generic check above and only $group is restricted here.
		$mhra_scope_ok = 'mhra' !== $style || 'referencelist' === $group;
		if ( ! $mhra_scope_ok ) {
			Citex_Admin::set_notice( __( 'MHRA currently supports Reference List only — In-Text Citation is coming in a later update.', 'citex-tools' ), 'error' );
			$this->redirect_back();
		}
		if ( ! in_array( $difficulty, array( 'easy', 'medium', 'hard' ), true ) ) {
			$difficulty = 'medium';
		}

		$category_label = $category_labels[ $category ];
		$starting_id    = self::normalise_starting_id( $starting_id, $category_label, $style, $group, $type );

		$type_label  = 'mcq' === $type ? 'MCQ' : 'DragDrop';

		// A forced Author Count bucket must actually exist in this
		// category/type's own scenario catalog (the SAME catalog
		// generate_via_scenarios() otherwise auto-selects from) — an
		// unrecognised or stale value (e.g. a scenario id left over from a
		// previously selected category) silently falls back to 'auto'
		// rather than erroring, matching $difficulty's own fallback pattern
		// above.
		if ( 'auto' !== $forced_scenario_id && ! Citex_Question_Scenarios::find( $category_label, $type_label, $forced_scenario_id ) ) {
			$forced_scenario_id = 'auto';
		}

		$pending     = self::get_pending_questions();
		$used_ids    = $this->collect_used_question_ids( $pending );
		$result      = $this->generate_via_scenarios( $category_label, $category, $type_label, $type, $quantity, $starting_id, $difficulty, $web_verify, $used_ids, $pending, $style, $group, $citation_form, $forced_scenario_id );

		if ( is_wp_error( $result ) ) {
			Citex_Admin::set_notice( $result->get_error_message(), 'error' );
			$this->redirect_back();
		}

		self::save_pending_questions( array_merge( $pending, $result ) );

		$coverage_after = self::compute_category_coverage( $category_label );
		$type_covered = 0;
		foreach ( $coverage_after as $counts ) {
			if ( ( $counts[ $type_label ] ?? 0 ) > 0 ) {
				$type_covered++;
			}
		}
		$message = sprintf(
			_n( '%d AI question generated and saved to Pending. Validate it when ready — only validated questions can be populated.', '%d AI questions generated and saved to Pending. Validate them when ready — only validated questions can be populated.', count( $result ), 'citex-tools' ),
			count( $result )
		);
		$message .= ' ' . sprintf(
			__( '%1$s %2$s exercise coverage: %3$d/5 exercises now have at least one question.', 'citex-tools' ),
			$category_label,
			$type_label,
			$type_covered
		);
		if ( $type_covered < 5 ) {
			$message .= ' ' . __( 'Coverage is not yet complete.', 'citex-tools' );
		}
		Citex_Admin::set_notice( $message, 'success' );
		$this->redirect_back();
	}

	/**
	 * Issues ONE Citex_AI_V2::generate_questions() request per scenario
	 * (Citex_Question_Diversity::assign_scenarios()) instead of always one
	 * shared request for the whole batch — this is what lets a single
	 * "generate 12 questions" submission actually test 4 different
	 * author-count rules 3 times each, say, instead of 12 near-identical
	 * questions differing only by book title. Exercise assignment is
	 * unaffected: still computed once for the whole batch, by slot index,
	 * and sliced per scenario group below.
	 *
	 * Groups are generated in first-seen order and the whole submission
	 * stays atomic — if any group's request ultimately fails (after its own
	 * internal quality-retry attempts), this returns that WP_Error
	 * immediately and nothing from any group is saved, matching the
	 * pre-framework single-request contract exactly. IDs are never reused
	 * across groups within one submission: each successful group's own
	 * questionIds are folded into the running $used_ids set before the next
	 * group's request, on top of the pre-existing pending/scanned IDs.
	 *
	 * @return array|WP_Error
	 */
	private function generate_via_scenarios( $category_label, $category_key, $type_label, $type_key, $quantity, $starting_id, $difficulty, $web_verify, $used_ids, $pending, $style = 'harvard', $group = 'referencelist', $citation_form = 'narrative', $forced_scenario_id = 'auto' ) {
		// Citex — not Gemini — assigns each slot's Exercise and scenario,
		// deterministically, before generation even starts. Gemini's
		// response schema carries no exercise field, and is never trusted
		// for author/editor count either (see Citex_AI_V2::normalise()'s
		// target-count enforcement) — there is nothing in its response to
		// trust or distrust for either dimension.
		$exercise_assignments = self::build_exercise_assignments( $category_label, $type_label, $quantity );
		// An admin-forced Author Count bucket (validated against
		// Citex_Question_Scenarios::find() by the caller already) assigns
		// every slot in this batch to that ONE bucket instead of letting
		// Citex_Question_Diversity spread across all of them — the
		// concrete fix for "separate single-author questions" from a
		// blended batch. Batch-history recording is unaffected: that still
		// happens inside Citex_AI_V2::generate_questions() itself, keyed
		// off the same scenario id either way.
		$scenario_assignments = 'auto' !== $forced_scenario_id
			? array_fill( 0, max( 0, (int) $quantity ), $forced_scenario_id )
			: Citex_Question_Diversity::assign_scenarios( $category_label, $type_label, $quantity );

		$groups      = array();
		$group_order = array();
		foreach ( $scenario_assignments as $index => $scenario_id ) {
			$key = (string) $scenario_id;
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array();
				$group_order[]  = $key;
			}
			$groups[ $key ][] = $index;
		}

		$existing_references = $this->collect_existing_references( $pending, $category_label );
		$all_results          = array();

		foreach ( $group_order as $scenario_id ) {
			$indices          = $groups[ $scenario_id ];
			$group_exercises  = array();
			foreach ( $indices as $index ) {
				$group_exercises[] = $exercise_assignments[ $index ];
			}

			$result = Citex_AI_V2::generate_questions(
				array(
					'quantity'             => count( $indices ),
					'starting_id'          => $starting_id,
					'difficulty'           => $difficulty,
					'web_verify'           => $web_verify,
					'used_ids'             => array_keys( $used_ids ),
					'exercise_assignments' => $group_exercises,
					'type'                 => $type_key,
					'category'             => $category_key,
					'scenario'             => $scenario_id,
					'existing_references'  => $existing_references,
					'style'                => $style,
					'group'                => $group,
					'citation_form'        => $citation_form,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			foreach ( $result as $candidate ) {
				$id = strtoupper( trim( (string) ( $candidate['questionId'] ?? '' ) ) );
				if ( '' !== $id ) {
					$used_ids[ $id ] = true;
				}
				$reference = trim( (string) ( $candidate['reconstructedReference'] ?? '' ) );
				if ( '' !== $reference ) {
					$existing_references[] = $reference;
				}
				$all_results[] = $candidate;
			}
		}

		return $all_results;
	}

	/**
	 * Same-category reconstructedReference values already sitting in the
	 * pending queue — the duplicate-book similarity guard's starting set
	 * (see Citex_Question_Diversity::is_duplicate_reference()), grown with
	 * each scenario group's own new references as generate_via_scenarios()
	 * proceeds, so group 2 also never duplicates a book group 1 just added.
	 *
	 * @return string[]
	 */
	private function collect_existing_references( $pending, $category_label ) {
		$references = array();
		foreach ( $pending as $question ) {
			if ( $category_label !== (string) ( $question['category'] ?? '' ) ) {
				continue;
			}
			// 'choose_treatment'/'identify_error' MCQ questions never store an
			// actual bibliographic reference in this field (see
			// Citex_AI_V2::find_duplicate_reference()'s identical exclusion) —
			// for choose_treatment it is Citex's own fixed, bucket-level rule
			// statement, deliberately identical across every question testing
			// that rule; including it here would make every subsequent batch
			// think that rule's text is "a real book already used".
			if ( in_array( $question['mcqPattern'] ?? '', array( 'choose_treatment', 'identify_error' ), true ) ) {
				continue;
			}
			$reference = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
			if ( '' !== $reference ) {
				$references[] = $reference;
			}
		}
		return $references;
	}

	private function validate_pending_batch() {
		$pending = self::get_pending_questions();
		$passed = 0;
		$failed = 0;
		foreach ( $pending as &$question ) {
			$result = Citex_Generated_Validator::validate( $question );
			$question['validationStatus'] = $result['status'];
			$question['validationErrors'] = $result['errors'];
			$question['validatedAt'] = $result['validatedAt'];
			if ( ! empty( $result['reconstructedReference'] ) ) {
				$question['validatedReference'] = $result['reconstructedReference'];
			}
			if ( 'passed' === $result['status'] ) { $passed++; } else { $failed++; }
		}
		unset( $question );
		self::save_pending_questions( $pending );
		Citex_Admin::set_notice( sprintf( __( 'Generated-question validation complete. Passed: %1$d. Failed: %2$d. Only passed questions can be populated.', 'citex-tools' ), $passed, $failed ), empty( $failed ) ? 'success' : 'warning' );
		$this->redirect_back();
	}

	private function validate_one_pending( $key ) {
		$pending = self::get_pending_questions();
		$found = false;
		foreach ( $pending as &$question ) {
			if ( ( $question['key'] ?? '' ) !== $key ) { continue; }
			$result = Citex_Generated_Validator::validate( $question );
			$question['validationStatus'] = $result['status'];
			$question['validationErrors'] = $result['errors'];
			$question['validatedAt'] = $result['validatedAt'];
			if ( ! empty( $result['reconstructedReference'] ) ) { $question['validatedReference'] = $result['reconstructedReference']; }
			$found = true;
			break;
		}
		unset( $question );
		self::save_pending_questions( $pending );
		Citex_Admin::set_notice( $found ? __( 'Generated question revalidated.', 'citex-tools' ) : __( 'Pending question was not found.', 'citex-tools' ), $found ? 'success' : 'error' );
		$this->redirect_back();
	}

	/**
	 * Each category gets its own visually-distinct ID prefix (BK/ED — see
	 * Citex_Reference_Rules::id_prefix()) and its own numbering that starts
	 * fresh at 01, rather than continuing another category's count. A
	 * starting ID left over from a different category (e.g. the form's
	 * "BK01" default while "Edited Book" is selected, or a stale value from
	 * a previous batch) is auto-corrected to this category's own
	 * "<prefix>01" — an ID the admin deliberately typed FOR this category
	 * (e.g. "ED05" to resume a gap) is honoured as-is. Pure/static so it can
	 * be tested directly, unlike handle_generation() itself which redirects
	 * (and exits) on every path.
	 *
	 * MCQ gets a "Q" appended onto the same base prefix (BK -> BKQ, IB ->
	 * IBQ, etc.), giving it its own independent numbering that starts fresh
	 * at 01 too — DragDrop and MCQ questions for the same
	 * category/style/group no longer share one interleaved counter (a
	 * reported problem: DragDrop and MCQ question IDs for the same category
	 * were interleaved from one shared count, e.g. IB04-IB06 landing as MCQ
	 * and IB07-IB20 as DragDrop, instead of each starting cleanly at 01).
	 * DragDrop's own prefix is left completely unchanged so every
	 * already-populated DragDrop question's ID stays valid.
	 */
	public static function normalise_starting_id( $starting_id, $category_label, $style = 'harvard', $group = 'referencelist', $type = 'dragdrop' ) {
		$starting_id     = strtoupper( trim( (string) $starting_id ) );
		if ( 'intext' === $group ) {
			$expected_prefix = self::intext_id_prefix( $category_label, $style );
		} elseif ( 'apa' === $style ) {
			$expected_prefix = Citex_APA_Reference_Rules::id_prefix( $category_label );
		} elseif ( 'chicago' === $style ) {
			$expected_prefix = Citex_Chicago_Reference_Rules::id_prefix( $category_label );
		} elseif ( 'mhra' === $style ) {
			$expected_prefix = Citex_MHRA_Reference_Rules::id_prefix( $category_label );
		} else {
			$expected_prefix = 'mla' === $style
				? Citex_MLA_Reference_Rules::id_prefix( $category_label )
				: Citex_Reference_Rules::id_prefix( $category_label );
		}
		if ( 'mcq' === $type ) {
			$expected_prefix .= 'Q';
		}
		// Matched against the digit that must immediately follow the prefix
		// — not a plain strpos() — so DragDrop's own bare prefix (e.g. "IB")
		// never matches a leftover MCQ value that merely starts with it
		// (e.g. "IBQ05"), which a simple substring check would wrongly
		// accept as "already correct for this category" instead of
		// resetting it.
		if ( ! preg_match( '/^' . preg_quote( $expected_prefix, '/' ) . '\d/', $starting_id ) ) {
			return $expected_prefix . '01';
		}
		return $starting_id;
	}

	private function collect_used_question_ids( $pending ) {
		$used = array();
		foreach ( $pending as $question ) {
			$id = strtoupper( trim( (string) ( $question['questionId'] ?? '' ) ) );
			if ( '' !== $id ) { $used[ $id ] = true; }
		}
		// A cached last-scan snapshot goes stale the moment a population run
		// creates new posts after it was taken — the classic failure mode
		// this caused: a fresh generate batch reused an ID (e.g. WR01) that
		// a PRIOR population had already created in WordPress, so every one
		// of them failed at population time with "a record with this exact
		// title already exists", 0 created. Always re-sync fresh here (a
		// fast, local get_posts() query — see Citex_Scanner::sync_from_wordpress()
		// — not an external call) so a new batch can never reuse an ID
		// already live in WordPress; fall back to the cached scan only if a
		// fresh sync can't run (e.g. that target's URL isn't configured
		// yet). Both real destinations are checked — Reference List AND
		// Citations (see Citex_Scanner::target_for_group()'s own docblock)
		// — since In-Text Citation questions live in the latter but must
		// still never collide on a reused ID.
		foreach ( array( 'reference', 'citations' ) as $target ) {
			$scan = Citex_Scanner::sync_from_wordpress( $target );
			if ( is_wp_error( $scan ) ) {
				$scan = Citex_Scanner::get_last_scan( $target );
			}
			foreach ( ( $scan['questions'] ?? array() ) as $question ) {
				$id = strtoupper( trim( (string) ( $question['questionId'] ?? '' ) ) );
				if ( '' !== $id ) { $used[ $id ] = true; }
			}
		}
		return $used;
	}

	private function redirect_back() {
		wp_safe_redirect( admin_url( 'admin.php?page=citex-generate' ) );
		exit;
	}
}
