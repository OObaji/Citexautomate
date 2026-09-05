<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Population engine for validated generated questions.
 *
 * Safety contract:
 * - only generated questions with validationStatus=passed are eligible;
 * - each new Reference List record is created as Draft first;
 * - the generated question's own Category/Exercise/Type classification is
 *   always authoritative — assign_generated_classification() applies it
 *   unconditionally (see below), after any template-clone, and REPLACES
 *   (never appends to) that taxonomy's term set, so a template's own
 *   Category/Exercise can never leak into, or survive alongside, the
 *   generated question's;
 * - after every field/taxonomy write, the actual saved state is read back
 *   from WordPress and verified before the record is ever published;
 * - only after every verification passes can the requested final post
 *   status be applied;
 * - failed records are not removed from the pending queue, and a
 *   partially-created post is always rolled back (force-deleted), never
 *   left behind half-configured.
 *
 * Template hierarchy (see resolve_population_fields()/resolve_population_fields_without_template()
 * and populate_one()):
 * 1. If a real Harvard/ReferenceList/Book/DragDrop record already exists
 *    (active or in Bin), it is used as a template: its post meta and
 *    taxonomy terms are cloned so unknown site-specific setup survives.
 *    This remains the safest path and is preferred whenever available —
 *    but only for metadata Citex has no way to know otherwise. Its
 *    Category/Exercise/Type is never trusted; see assign_generated_classification().
 * 2. If no such record exists, Citex does not require one. The Question
 *    Parts / Fixed Text / Confusing Words ACF fields are addressed by the
 *    already-known field keys, and the Question/Scenario field is located
 *    by discover_scenario_field() (see its docblock). No meta is cloned in
 *    this path — Citex has no way to know which of an arbitrary template
 *    post's other meta keys would apply to an unrelated question.
 * 3. If the Question/Scenario ACF field, or the Category/Exercise taxonomy
 *    terms named by the generated question, cannot be located, Citex stops
 *    with a clear, diagnostic error rather than writing a record with a
 *    missing or incorrect classification.
 *
 * Question Parts/Confusing Words repeater rows (see resolve_repeater_text_row_shape()):
 * a real Book/DragDrop repeater row was found to have more than one
 * sub-field — a row-TYPE selector (e.g. the reported "Select Element"
 * choice field distinguishing Text rows from Punctuation rows) alongside
 * type-specific value sub-fields — not a single flat text sub-field.
 * Writing only the first sub-field of each row (the old behaviour) could
 * leave a template-cloned row's OTHER sub-field values (a punctuation
 * selection) in place, because the row's real required shape was never
 * fully written. The real shape is discovered from the field's own ACF
 * definition (acf_get_field()) — its sub-fields' types, labels and, for
 * the selector, its actual choices — never assumed from the field label,
 * and every row read back and compared value-for-value, in order, after
 * saving.
 *
 * Save lifecycle / finalisation (see finalize_question()): update_field()
 * and wp_set_object_terms() persist their values directly but do not, by
 * themselves, fire ACF's own acf/save_post action — only ACF's own admin
 * edit-screen form-processing pipeline does that (this is standard,
 * documented ACF architecture, not specific to this site). Any site logic
 * that hooks acf/save_post — the ACF-documented place to react to "this
 * post's ACF data is now saved", commonly used for indexing/cache-invalidation
 * — never runs for values written only through update_field(). Confirmed by
 * live investigation (Citex Diagnostics, see class-citex-diagnostics.php)
 * against this site: WordPress's own save lifecycle (save_post,
 * save_post_{post_type}, wp_insert_post, wp_after_insert_post,
 * transition_post_status, {old}_to_{new}) already fires identically whether
 * a post is saved by a human in wp-admin or by Citex's own wp_update_post()
 * call — a live before/after diagnostic capture showed a manual wp-admin
 * "Update" click changes no WordPress-native field, meta, taxonomy term, or
 * ACF value on the post, yet is required for the site's Appify-based
 * student app to show the question. The real application-side mechanism is
 * still not identified (it is not any currently-registered save_post/acf
 * hook on this site; see class-citex-diagnostics.php's docblock), so this
 * class does not fire a new, unverified hook to work around it. Instead,
 * finalize_question() reproduces every standard, already-confirmed part of
 * a manual Update click — the WordPress save lifecycle plus the explicit
 * acf/save_post action — as a single, reusable, verified operation, used
 * both immediately after a new question is fully populated and, via the
 * "Finalise" action on the Questions page (maybe_handle_finalize_submit()),
 * to repair an already-populated question without touching its content.
 * This plugin still has no
 * visibility into what, if anything, a separate "student app" reads beyond
 * WordPress/ACF's own data and hooks — if it has its own index or cache
 * outside of that, this cannot detect or refresh it.
 */
class Citex_Populator {

	const NONCE_ACTION = 'citex_populate_questions';

	const NONCE_FINALIZE_ACTION = 'citex_finalize_questions';
	const MAX_FINALIZE_BATCH    = 25;

	const FIELD_FIXED_TEXT      = 'field_59c2476bc859f';
	const FIELD_QUESTION_PARTS  = 'field_59c2476bc81b7';
	const FIELD_CONFUSING_WORDS = 'field_59c2476bc83ab';

	// MCQ field keys, confirmed via Citex Diagnostics against a real post on
	// this site's citex-reference post type (see class-citex-diagnostics.php)
	// — not guessed, the same real-field-key methodology already used for
	// the DragDrop fields above.
	const FIELD_ANSWER  = 'field_6818e7c20fd1e';
	const FIELD_OPTION_1 = 'field_6818e7e00fd1f';
	const FIELD_OPTION_2 = 'field_6818e7f60fd20';
	const FIELD_OPTION_3 = 'field_6818e8100fd21';
	const FIELD_OPTION_4 = 'field_6818e81a0fd22';

	// Shared fields visible on the real "Add New Reference" edit screen for
	// EVERY question (both DragDrop and MCQ) — Question Class and Hint —
	// also confirmed via Citex Diagnostics. Both write_mcq_acf_values() and
	// write_dragdrop_acf_values() set Question Class explicitly (fixed
	// "Harvard"). DragDrop was previously left to rely on Question Class's
	// ACF default instead of writing it — reported live (student app never
	// showed a newly-populated DragDrop question as "Harvard" until an
	// admin opened it in wp-admin and clicked Update): an ACF field's
	// "default value" is only ever applied when the field is RENDERED in
	// the edit-screen form and that form is then submitted — it is never
	// written to post meta by wp_insert_post()/update_field() alone, so a
	// programmatically-created post has no Question Class value at all
	// until someone opens and re-saves it. Hint has no such default and no
	// DragDrop content to write (DragDrop candidates carry no hint text at
	// all — see Citex_AI_V2's DragDrop normalisers), so it is correctly
	// left untouched for DragDrop.
	const FIELD_QUESTION_CLASS = 'field_59e09466a6813';
	const FIELD_HINT           = 'field_59c2476bc879d';

	public function render() {
		$this->maybe_handle_submit();

		$pending = Citex_Generator::get_pending_questions();
		$status  = array(
			'ready'         => 0,
			'passed'        => 0,
			'failed'        => 0,
			'not_validated' => 0,
		);

		foreach ( $pending as $question ) {
			$validation = $question['validationStatus'] ?? 'not_validated';
			if ( 'passed' === $validation ) {
				$status['passed']++;
				$status['ready']++;
			} elseif ( 'failed' === $validation ) {
				$status['failed']++;
			} else {
				$status['not_validated']++;
			}
		}

		require CITEX_TOOLS_PATH . 'admin/views/populate.php';
	}

	/**
	 * Called on admin_init (before any output) as well as at the top of
	 * render(), so a redirect after submission always reaches the browser.
	 */
	public function maybe_handle_submit() {
		if ( empty( $_POST['citex_populate_submit'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, 'citex_populate_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to populate Reference List questions.', 'citex-tools' ) );
		}

		$final_status = isset( $_POST['citex_population_status'] ) ? sanitize_key( wp_unslash( $_POST['citex_population_status'] ) ) : 'draft';
		if ( ! in_array( $final_status, array( 'draft', 'publish' ), true ) ) {
			$final_status = 'draft';
		}

		$scope = isset( $_POST['citex_population_scope'] ) ? sanitize_key( wp_unslash( $_POST['citex_population_scope'] ) ) : 'all_passed';
		$selected_keys = isset( $_POST['citex_populate_keys'] ) && is_array( $_POST['citex_populate_keys'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['citex_populate_keys'] ) )
			: array();

		$pending = Citex_Generator::get_pending_questions();
		$eligible = array();
		foreach ( $pending as $question ) {
			if ( 'passed' !== ( $question['validationStatus'] ?? '' ) ) {
				continue;
			}
			if ( 'selected' === $scope && ! in_array( (string) ( $question['key'] ?? '' ), $selected_keys, true ) ) {
				continue;
			}
			$eligible[] = $question;
		}

		if ( empty( $eligible ) ) {
			Citex_Admin::set_notice( __( 'No passed generated questions were selected. Validate generated questions first.', 'citex-tools' ), 'warning' );
			$this->redirect_back();
		}

		if ( ! function_exists( 'update_field' ) ) {
			Citex_Admin::set_notice( __( 'Advanced Custom Fields is required to populate the Reference List safely.', 'citex-tools' ), 'error' );
			$this->redirect_back();
		}

		$scan = Citex_Scanner::get_last_scan();
		if ( empty( $scan['postType'] ) ) {
			$synced = Citex_Scanner::sync_from_wordpress();
			if ( is_wp_error( $synced ) ) {
				Citex_Admin::set_notice( $synced->get_error_message(), 'error' );
				$this->redirect_back();
			}
			$scan = $synced;
		}
		$post_type = sanitize_key( (string) ( $scan['postType'] ?? '' ) );
		if ( ! $post_type || ! post_type_exists( $post_type ) ) {
			Citex_Admin::set_notice( __( 'Citex could not determine the real Reference List post type.', 'citex-tools' ), 'error' );
			$this->redirect_back();
		}

		// find_template_post_id() only ever finds a real Book/DragDrop record
		// (see its own filter) — it is never a valid clone source for an MCQ
		// post (cloning it would leave DragDrop-only meta such as Question
		// Parts/Fixed Text stray on the new MCQ post). So MCQ always takes
		// the no-template path, and field maps are resolved per question
		// TYPE (memoised), since one population run can mix both types.
		$dragdrop_template_id = $this->find_template_post_id( $post_type, $scan );
		$field_maps = array();

		$successful_keys = array();
		$created          = array();
		$failed           = array();

		foreach ( $eligible as $question ) {
			$type        = 'MCQ' === ( $question['type'] ?? '' ) ? 'MCQ' : 'DragDrop';
			$template_id = 'MCQ' === $type ? 0 : $dragdrop_template_id;

			if ( ! isset( $field_maps[ $type ] ) ) {
				$field_maps[ $type ] = $template_id
					? $this->resolve_population_fields( $template_id, $post_type, $type )
					: $this->resolve_population_fields_without_template( $post_type, $type );
			}
			$field_map = $field_maps[ $type ];
			if ( is_wp_error( $field_map ) ) {
				$failed[] = sprintf( '%s: %s', (string) ( $question['questionId'] ?? '?' ), $field_map->get_error_message() );
				continue;
			}

			$result = $this->populate_one( $question, $post_type, $template_id, $field_map, $final_status );
			if ( is_wp_error( $result ) ) {
				$failed[] = sprintf( '%s: %s', (string) ( $question['questionId'] ?? '?' ), $result->get_error_message() );
				continue;
			}
			$successful_keys[] = (string) ( $question['key'] ?? '' );
			$created[] = $result;
		}

		if ( ! empty( $successful_keys ) ) {
			$pending = array_values(
				array_filter(
					$pending,
					function ( $question ) use ( $successful_keys ) {
						return ! in_array( (string) ( $question['key'] ?? '' ), $successful_keys, true );
					}
				)
			);
			Citex_Generator::save_pending_questions( $pending );
			Citex_Scanner::sync_from_wordpress();
		}

		$message = sprintf(
			__( 'Population complete. Created in Reference List: %1$d. Failed: %2$d.', 'citex-tools' ),
			count( $created ),
			count( $failed )
		);
		if ( ! empty( $created ) ) {
			$summaries = array();
			foreach ( array_slice( $created, 0, 3 ) as $c ) {
				$content_verified = 'MCQ' === $c['type']
					? sprintf( 'Options: %1$s, Answer: %2$s', $c['optionsVerified'] ?? '0/4', ! empty( $c['answerVerified'] ) ? '✓' : '✗' )
					: sprintf( 'Question Parts: %1$s, Fixed Text: %2$s', $c['questionPartsVerified'] ?? '0/0', ! empty( $c['fixedTextVerified'] ) ? '✓' : '✗' );
				$summaries[] = sprintf(
					'%1$s (Category: %2$s ✓, Exercise: %3$s ✓, Type: %4$s ✓, Scenario: %5$s, %6$s, Taxonomy: %7$s, Status: %8$s %9$s, Save lifecycle: %10$s)',
					$c['questionId'],
					$c['category'],
					$c['exercise'],
					$c['type'],
					! empty( $c['scenarioVerified'] ) ? '✓' : '✗',
					$content_verified,
					( ! empty( $c['categoryVerified'] ) && ! empty( $c['exerciseVerified'] ) ) ? '✓' : '✗',
					$c['status'],
					! empty( $c['statusVerified'] ) ? '✓' : '✗',
					! empty( $c['saveLifecycleCompleted'] ) ? '✓' : '✗'
				);
			}
			$message .= ' ' . implode( ' | ', $summaries );
		}
		if ( ! empty( $failed ) ) {
			$message .= ' ' . implode( ' | ', array_slice( $failed, 0, 3 ) );
		}
		Citex_Admin::set_notice( $message, empty( $failed ) ? 'success' : 'warning' );
		$this->redirect_back();
	}

	/**
	 * Create one real Reference List record from one validated pending record.
	 */
	private function populate_one( $question, $post_type, $template_id, $field_map, $final_status ) {
		$title = sanitize_text_field( (string) ( $question['title'] ?? '' ) );
		if ( '' === $title ) {
			return new WP_Error( 'citex_missing_title', 'Generated question title is missing.' );
		}

		$classification = $this->resolve_classification( $question );
		if ( ! in_array( $classification['type'], array( 'DragDrop', 'MCQ' ), true ) ) {
			return new WP_Error(
				'citex_unsupported_question_type',
				sprintf( 'Citex population does not yet support question type "%s" — its real ACF structure has not been supplied. Only DragDrop and MCQ are currently supported. No post was created.', $classification['type'] )
			);
		}

		$duplicates = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'posts_per_page' => 1,
				'title'          => $title,
				'fields'         => 'ids',
			)
		);
		if ( ! empty( $duplicates ) ) {
			return new WP_Error( 'citex_duplicate_question', 'A Reference List record with this exact title already exists.' );
		}

		$template = get_post( $template_id );
		$new_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => $template ? $template->post_content : '',
				'post_excerpt' => $template ? $template->post_excerpt : '',
				'menu_order'   => $template ? (int) $template->menu_order : 0,
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$expected_parts     = array_values( is_array( $question['questionParts'] ?? null ) ? $question['questionParts'] : array() );
		$expected_confusing = array_values( is_array( $question['confusingWords'] ?? null ) ? $question['confusingWords'] : array() );
		$diagnostics        = array();

		try {
			if ( $template_id ) {
				$this->clone_template_meta( $template_id, $new_id );
				$this->clone_template_terms( $template_id, $new_id, $post_type );
			} else {
				$this->apply_classification_terms( $new_id, $post_type );
			}

			// Category/Exercise are authoritative from the GENERATED question,
			// never from the template: this runs unconditionally, after any
			// template-clone above, and replaces (never appends to) that
			// taxonomy's term set — so a template's own Category/Exercise can
			// never leak into, or survive alongside, the generated question's.
			$classification_result = $this->assign_generated_classification( $new_id, $post_type, $classification );
			if ( is_wp_error( $classification_result ) ) {
				throw new Exception( 'Category/Exercise: ' . $classification_result->get_error_message() );
			}

			// A plain WordPress post meta key (deliberately not an ACF field —
			// no such field exists on this site, and this needs no ACF schema
			// change at all) making the question type ("DragDrop"/"MCQ")
			// explicitly machine-readable, in addition to it already being
			// implicit in which set of fields is populated. Purely additive:
			// nothing currently reads this key, so writing it cannot change
			// any existing behaviour.
			if ( function_exists( 'update_post_meta' ) ) {
				update_post_meta( $new_id, '_citex_question_type', $classification['type'] );
			}

			// Question Parts/Confusing Words are ACF repeaters whose real shape
			// is only known by introspecting the field itself (see
			// resolve_repeater_text_row_shape()) — never assumed from the
			// label alone. Cloning a template can leave a repeater's OTHER
			// (non-text) sub-field values — e.g. a punctuation selector — in
			// place; only writing every row with the discovered shape (not
			// just the first sub-field) replaces them correctly.
			$write_result = 'MCQ' === $classification['type']
				? $this->write_mcq_acf_values( $new_id, $field_map, $question )
				: $this->write_dragdrop_acf_values( $new_id, $field_map, $question, $expected_parts, $expected_confusing );

			// Reproduce the WordPress/ACF save lifecycle a manual "Update"
			// click goes through — for BOTH Draft and Publish — via the same
			// reusable, verified finalize_question() used to repair existing
			// questions (see its docblock and the class docblock above).
			$finalize_result = self::finalize_question( $new_id, array( 'post_status' => $final_status ) );
			if ( is_wp_error( $finalize_result ) ) {
				throw new Exception( 'Finalisation: ' . $finalize_result->get_error_message() );
			}

			// Consolidated post-save verification, performed AFTER the save
			// lifecycle above so it reflects the truly final, settled state —
			// never trust a write call's return value alone.
			$diagnostics = array_merge(
				$diagnostics,
				'MCQ' === $classification['type']
					? $this->verify_mcq_acf_values( $new_id, $field_map, $question, $write_result )
					: $this->verify_dragdrop_acf_values( $new_id, $field_map, $question, $expected_parts, $expected_confusing, $write_result )
			);

			// Mandatory post-creation verification: read the Category/Exercise
			// terms back from WordPress rather than trusting wp_set_object_terms()'s
			// return value. This is exactly the class of bug this fix addresses —
			// a post created without its Category/Exercise actually attached must
			// never be left behind or considered populated.
			$category_terms = wp_get_object_terms( $new_id, $classification_result['categoryTaxonomy'], array( 'fields' => 'ids' ) );
			$exercise_terms = $classification_result['exerciseTaxonomy'] === $classification_result['categoryTaxonomy']
				? $category_terms
				: wp_get_object_terms( $new_id, $classification_result['exerciseTaxonomy'], array( 'fields' => 'ids' ) );
			$category_verified = ! is_wp_error( $category_terms ) && in_array( $classification_result['categoryTermId'], (array) $category_terms, true );
			$exercise_verified = ! is_wp_error( $exercise_terms ) && in_array( $classification_result['exerciseTermId'], (array) $exercise_terms, true );
			if ( ! $category_verified || ! $exercise_verified ) {
				throw new Exception(
					sprintf(
						'Category/Exercise assignment did not verify after saving: "%1$s" (%2$s), "%3$s" (%4$s).',
						$classification['category'],
						$category_verified ? 'confirmed' : 'MISSING',
						$classification['exercise'],
						$exercise_verified ? 'confirmed' : 'MISSING'
					)
				);
			}
			$diagnostics['categoryVerified'] = true;
			$diagnostics['exerciseVerified']  = true;

			$actual_status = function_exists( 'get_post_status' ) ? get_post_status( $new_id ) : $final_status;
			$diagnostics['statusVerified'] = ( $actual_status === $final_status );
			if ( ! $diagnostics['statusVerified'] ) {
				throw new Exception( sprintf( 'Post status did not verify after saving: expected "%1$s", found "%2$s".', $final_status, (string) $actual_status ) );
			}

			$diagnostics['saveLifecycleCompleted'] = true;
		} catch ( Exception $e ) {
			wp_delete_post( $new_id, true );
			return new WP_Error( 'citex_population_failed', $e->getMessage() );
		}

		$this->record_population_coverage( $classification );

		return array_merge(
			array(
				'postId'     => (int) $new_id,
				'questionId' => (string) ( $question['questionId'] ?? '' ),
				'status'     => $final_status,
				'category'   => $classification['category'],
				'exercise'   => $classification['exercise'],
				'type'       => $classification['type'],
			),
			$diagnostics
		);
	}

	/**
	 * Write DragDrop's ACF fields (Fixed Text, Question Parts, Confusing
	 * Words, Scenario, Question Class). Throws on any write-shape failure;
	 * returns the discovered repeater shapes so verify_dragdrop_acf_values()
	 * (called after the save-lifecycle finalisation) can read the same rows
	 * back without re-discovering the shape a second time.
	 */
	private function write_dragdrop_acf_values( $new_id, $field_map, $question, $expected_parts, $expected_confusing ) {
		$this->write_acf_value( $new_id, $field_map['fixedText'], (string) ( $question['fixedText'] ?? '' ) );

		$parts_shape = $this->write_repeater_rows( $new_id, $field_map['questionParts'], $expected_parts );
		if ( is_wp_error( $parts_shape ) ) {
			throw new Exception( 'Question Parts: ' . $parts_shape->get_error_message() );
		}

		$confusing_shape = $this->write_repeater_rows( $new_id, $field_map['confusingWords'], $expected_confusing );
		if ( is_wp_error( $confusing_shape ) ) {
			throw new Exception( 'Confusing Words: ' . $confusing_shape->get_error_message() );
		}

		$this->write_acf_value( $new_id, $field_map['scenario'], (string) ( $question['scenario'] ?? '' ) );
		$this->write_acf_value( $new_id, $field_map['questionClass'], 'Harvard' );

		return array( 'partsShape' => $parts_shape, 'confusingShape' => $confusing_shape );
	}

	private function verify_dragdrop_acf_values( $new_id, $field_map, $question, $expected_parts, $expected_confusing, $write_result ) {
		$diagnostics = array();
		if ( function_exists( 'get_field' ) ) {
			$stored_fixed = get_field( $field_map['fixedText'], $new_id, false );
			$diagnostics['fixedTextVerified'] = trim( (string) $stored_fixed ) === trim( (string) ( $question['fixedText'] ?? '' ) );
			if ( ! $diagnostics['fixedTextVerified'] ) {
				throw new Exception( 'Fixed Text did not persist to the new Reference List record.' );
			}

			$stored_scenario = get_field( $field_map['scenario'], $new_id, false );
			$diagnostics['scenarioVerified'] = trim( (string) $stored_scenario ) === trim( (string) ( $question['scenario'] ?? '' ) );
			if ( ! $diagnostics['scenarioVerified'] ) {
				throw new Exception( 'Scenario did not persist to the new Reference List record.' );
			}

			$stored_question_class = get_field( $field_map['questionClass'], $new_id, false );
			$diagnostics['questionClassVerified'] = 'Harvard' === trim( (string) $stored_question_class );
			if ( ! $diagnostics['questionClassVerified'] ) {
				throw new Exception( 'Question Class did not persist to the new Reference List record.' );
			}
		}

		$parts_check = $this->verify_repeater_text_values( $new_id, $field_map['questionParts'], $write_result['partsShape'], $expected_parts );
		if ( is_wp_error( $parts_check ) ) {
			throw new Exception( 'Question Parts: ' . $parts_check->get_error_message() );
		}
		$diagnostics['questionPartsVerified'] = count( $expected_parts ) . '/' . count( $expected_parts );

		$confusing_check = $this->verify_repeater_text_values( $new_id, $field_map['confusingWords'], $write_result['confusingShape'], $expected_confusing );
		if ( is_wp_error( $confusing_check ) ) {
			throw new Exception( 'Confusing Words: ' . $confusing_check->get_error_message() );
		}
		$diagnostics['confusingWordsVerified'] = true;

		return $diagnostics;
	}

	/**
	 * Write MCQ's ACF fields: option_1-4 and Scenario (the confirmed real
	 * field keys — see FIELD_OPTION_1..4), Hint (the category's fixed,
	 * non-revealing clue — see Citex_Reference_Rules::mcq_hint() — there is
	 * no separate "explanation" field on this site; Hint is the real,
	 * confirmed field, and it is shown to the student BEFORE they answer,
	 * so it must never identify the correct option), Question Class (fixed
	 * "Harvard" — see FIELD_QUESTION_CLASS's own docblock for why this must
	 * be written explicitly rather than left to an ACF default), and the
	 * Answer field.
	 *
	 * Option 1-3 hold the 3 distractors; Option 4 is ALWAYS blank. The
	 * Answer field holds the FULL TEXT of the correct reference — never a
	 * letter, number, or other short identifier, and never ALSO duplicated
	 * into one of the 4 option slots. A real live-site Diagnostics capture
	 * showed that duplicating the correct answer into both an option slot
	 * and the Answer field made the student app render the two copies as
	 * separate, simultaneously-"selected" choices — the answer belongs
	 * ONLY in the Answer field. This is also what lets the frontend grade a
	 * submission with a direct value comparison
	 * (selectedOptionText === answer) instead of needing to translate a
	 * letter back into option text.
	 *
	 * @throws Exception on any write-shape failure.
	 * @return array {options: string[4], answerValue: string, hint: string}
	 */
	private function write_mcq_acf_values( $new_id, $field_map, $question ) {
		$options = array_values( is_array( $question['options'] ?? null ) ? $question['options'] : array() );
		if ( 4 !== count( $options ) ) {
			throw new Exception( sprintf( 'Expected exactly 4 MCQ option slots (3 distractors + 1 blank), found %d.', count( $options ) ) );
		}
		$answer_value = trim( (string) ( $question['reconstructedReference'] ?? '' ) );
		if ( '' === $answer_value ) {
			throw new Exception( 'MCQ correct answer (reconstructedReference) is missing.' );
		}

		// Option 1-3 hold the 3 distractors; Option 4 is always blank — the
		// correct answer goes ONLY into the Answer field below, never
		// duplicated into an option. See Citex_Generated_Validator::validate_mcq()'s
		// docblock for the reported bug this fixes.
		$this->write_acf_value( $new_id, $field_map['option1'], (string) $options[0] );
		$this->write_acf_value( $new_id, $field_map['option2'], (string) $options[1] );
		$this->write_acf_value( $new_id, $field_map['option3'], (string) $options[2] );
		$this->write_acf_value( $new_id, $field_map['option4'], (string) $options[3] );
		$this->write_acf_value( $new_id, $field_map['scenario'], (string) ( $question['scenario'] ?? '' ) );

		$hint = (string) ( $question['hint'] ?? '' );
		$this->write_acf_value( $new_id, $field_map['hint'], $hint );
		$this->write_acf_value( $new_id, $field_map['questionClass'], 'Harvard' );

		$this->write_acf_value( $new_id, $field_map['answer'], $answer_value );

		return array( 'options' => $options, 'answerValue' => $answer_value, 'hint' => $hint );
	}

	private function verify_mcq_acf_values( $new_id, $field_map, $question, $write_result ) {
		$diagnostics = array();
		if ( ! function_exists( 'get_field' ) ) {
			return $diagnostics;
		}

		foreach ( array( 'option1', 'option2', 'option3', 'option4' ) as $index => $map_key ) {
			$stored = get_field( $field_map[ $map_key ], $new_id, false );
			if ( trim( (string) $stored ) !== trim( (string) $write_result['options'][ $index ] ) ) {
				throw new Exception( sprintf( 'Option %d did not persist to the new Reference List record.', $index + 1 ) );
			}
		}
		$diagnostics['optionsVerified'] = '4/4';

		$stored_scenario = get_field( $field_map['scenario'], $new_id, false );
		$diagnostics['scenarioVerified'] = trim( (string) $stored_scenario ) === trim( (string) ( $question['scenario'] ?? '' ) );
		if ( ! $diagnostics['scenarioVerified'] ) {
			throw new Exception( 'Scenario did not persist to the new Reference List record.' );
		}

		$stored_hint = get_field( $field_map['hint'], $new_id, false );
		$diagnostics['hintVerified'] = trim( (string) $stored_hint ) === trim( (string) $write_result['hint'] );
		if ( ! $diagnostics['hintVerified'] ) {
			throw new Exception( 'Hint did not persist to the new Reference List record.' );
		}

		$stored_answer = get_field( $field_map['answer'], $new_id, false );
		$diagnostics['answerVerified'] = ( (string) $stored_answer === (string) $write_result['answerValue'] );
		if ( ! $diagnostics['answerVerified'] ) {
			throw new Exception( 'Answer did not persist to the new Reference List record.' );
		}

		return $diagnostics;
	}

	private function find_template_post_id( $post_type, $scan ) {
		foreach ( ( $scan['questions'] ?? array() ) as $question ) {
			if (
				0 === strcasecmp( (string) ( $question['source'] ?? '' ), 'Harvard' ) &&
				0 === strcasecmp( (string) ( $question['group'] ?? '' ), 'ReferenceList' ) &&
				0 === strcasecmp( (string) ( $question['category'] ?? '' ), 'Book' ) &&
				0 === strcasecmp( (string) ( $question['type'] ?? '' ), 'DragDrop' ) &&
				! empty( $question['wpPostId'] )
			) {
				return absint( $question['wpPostId'] );
			}
		}

		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $posts as $post ) {
			$parts = array_values( array_filter( array_map( 'trim', explode( '|', get_the_title( $post ) ) ), 'strlen' ) );
			if (
				isset( $parts[0], $parts[1], $parts[2], $parts[3] ) &&
				0 === strcasecmp( preg_replace( '/^question\s+title\s*:\s*/i', '', $parts[0] ), 'Harvard' ) &&
				0 === strcasecmp( $parts[1], 'ReferenceList' ) &&
				0 === strcasecmp( $parts[2], 'Book' ) &&
				0 === strcasecmp( $parts[3], 'DragDrop' )
			) {
				return (int) $post->ID;
			}
		}
		return 0;
	}

	private function resolve_population_fields( $template_id, $post_type, $type = 'DragDrop' ) {
		$known = $this->assert_known_acf_fields_registered( $type );
		if ( is_wp_error( $known ) ) {
			return $known;
		}

		$scenario_key = $this->discover_scenario_field( $post_type, $template_id );
		if ( is_wp_error( $scenario_key ) ) {
			return $scenario_key;
		}

		return self::field_map_for_type( $type, $scenario_key );
	}

	/**
	 * Same field map as resolve_population_fields(), but discovered without
	 * any existing template post.
	 */
	private function resolve_population_fields_without_template( $post_type, $type = 'DragDrop' ) {
		$known = $this->assert_known_acf_fields_registered( $type );
		if ( is_wp_error( $known ) ) {
			return $known;
		}

		$scenario_key = $this->discover_scenario_field( $post_type, 0 );
		if ( is_wp_error( $scenario_key ) ) {
			return $scenario_key;
		}

		return self::field_map_for_type( $type, $scenario_key );
	}

	private static function field_map_for_type( $type, $scenario_key ) {
		if ( 'MCQ' === $type ) {
			return array(
				'scenario'      => $scenario_key,
				'option1'       => self::FIELD_OPTION_1,
				'option2'       => self::FIELD_OPTION_2,
				'option3'       => self::FIELD_OPTION_3,
				'option4'       => self::FIELD_OPTION_4,
				'answer'        => self::FIELD_ANSWER,
				'hint'          => self::FIELD_HINT,
				'questionClass' => self::FIELD_QUESTION_CLASS,
			);
		}
		return array(
			'fixedText'      => self::FIELD_FIXED_TEXT,
			'questionParts'  => self::FIELD_QUESTION_PARTS,
			'confusingWords' => self::FIELD_CONFUSING_WORDS,
			'scenario'       => $scenario_key,
			'questionClass'  => self::FIELD_QUESTION_CLASS,
		);
	}

	private function assert_known_acf_fields_registered( $type = 'DragDrop' ) {
		$required = 'MCQ' === $type
			? array( self::FIELD_OPTION_1, self::FIELD_OPTION_2, self::FIELD_OPTION_3, self::FIELD_OPTION_4, self::FIELD_ANSWER, self::FIELD_HINT, self::FIELD_QUESTION_CLASS )
			: array( self::FIELD_FIXED_TEXT, self::FIELD_QUESTION_PARTS, self::FIELD_CONFUSING_WORDS, self::FIELD_QUESTION_CLASS );
		foreach ( $required as $field_key ) {
			if ( function_exists( 'acf_get_field' ) && ! acf_get_field( $field_key ) ) {
				return new WP_Error( 'citex_missing_acf_field', 'Required ACF field is not registered: ' . $field_key );
			}
		}
		return true;
	}

	/**
	 * Robust Question/Scenario field discovery.
	 *
	 * A single ACF lookup mechanism can miss a field that another finds —
	 * for example, a field group whose location rule depends on more than
	 * "Post Type is equal to X" may not resolve under a post-type-only
	 * location context, while it still resolves against a specific post's
	 * full location context (or vice-versa). So every safe, ACF-native
	 * mechanism is tried and the results are merged into one candidate set
	 * before matching, rather than trusting any single one to be complete:
	 *
	 * 1. Field GROUP definitions located by post type
	 *    (acf_get_field_groups(['post_type' => ...])) — works even before
	 *    any matching Reference List record exists.
	 * 2. Field GROUP definitions located by the specific post's full
	 *    location context (acf_get_field_groups(['post_id' => ...])) — this
	 *    is the same location-rule resolution ACF itself uses to decide
	 *    what actually renders on that post's edit screen, so it can
	 *    succeed where a post-type-only rule fails.
	 * 3. get_field_objects() against the template post directly — ACF's own
	 *    "what fields does this specific post have" API.
	 *
	 * Each field definition tree is walked through repeater/group
	 * sub_fields AND flexible-content layouts' sub_fields, so a Scenario
	 * field nested inside either is still found.
	 *
	 * Matching is by normalised (lowercased, non-alphanumeric-collapsed)
	 * label or field name, tried in priority order "scenario", then
	 * "question text", then the more generic "question" — the most likely
	 * match wins first. If more than one field matches within the same
	 * priority tier, that is a genuine ambiguity: Citex does not guess
	 * which one is correct, it fails with every candidate label listed.
	 *
	 * @param string $post_type Reference List post type.
	 * @param int    $post_id   A real post to also inspect directly, or 0
	 *                          to only use post-type-level discovery
	 *                          (the no-template path).
	 * @return string|WP_Error Field key, or a WP_Error describing every ACF
	 *         field Citex could actually discover, so a failure is
	 *         diagnosable instead of a bare "not found".
	 */
	private function discover_scenario_field( $post_type, $post_id = 0 ) {
		$candidates = array();
		$this->collect_fields_by_post_type( $post_type, $candidates );
		if ( $post_id ) {
			$this->collect_fields_by_post_id( $post_id, $candidates );
			$this->collect_fields_from_post_objects( $post_id, $candidates );
		}

		foreach ( array( 'scenario', 'question text', 'question' ) as $wanted_label ) {
			$tier = array();
			foreach ( $candidates as $key => $field ) {
				$label = $this->normalise_label( $field['label'] );
				$name  = $this->normalise_label( $field['name'] );
				if ( $wanted_label === $label || $wanted_label === $name ) {
					$tier[ $key ] = $field;
				}
			}
			if ( 1 === count( $tier ) ) {
				return (string) array_key_first( $tier );
			}
			if ( count( $tier ) > 1 ) {
				return new WP_Error(
					'citex_question_field_ambiguous',
					sprintf(
						'Citex found more than one ACF field matching "%1$s". Candidates: %2$s. Rename the intended field so it is unique, or narrow the others.',
						$wanted_label,
						$this->describe_candidates( $tier )
					)
				);
			}
		}

		if ( empty( $candidates ) ) {
			return new WP_Error(
				'citex_question_field_not_found',
				'Citex could not identify the Scenario ACF field: no ACF fields were discovered at all for this Reference List post type. Confirm Advanced Custom Fields is active and its field group is attached to this post type.'
			);
		}

		return new WP_Error(
			'citex_question_field_not_found',
			sprintf( 'Citex could not identify the Scenario ACF field. Discovered ACF fields: %s.', $this->describe_candidates( $candidates ) )
		);
	}

	private function collect_fields_by_post_type( $post_type, array &$candidates ) {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return;
		}
		$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
		$this->collect_from_groups( is_array( $groups ) ? $groups : array(), $candidates );
	}

	private function collect_fields_by_post_id( $post_id, array &$candidates ) {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return;
		}
		$groups = acf_get_field_groups( array( 'post_id' => $post_id ) );
		$this->collect_from_groups( is_array( $groups ) ? $groups : array(), $candidates );
	}

	private function collect_from_groups( $groups, array &$candidates ) {
		foreach ( $groups as $group ) {
			$fields = acf_get_fields( $group );
			if ( is_array( $fields ) ) {
				$this->collect_fields_tree( $fields, $candidates );
			}
		}
	}

	private function collect_fields_from_post_objects( $post_id, array &$candidates ) {
		if ( ! function_exists( 'get_field_objects' ) ) {
			return;
		}
		$fields = get_field_objects( $post_id, false, false );
		if ( is_array( $fields ) ) {
			$this->collect_fields_tree( $fields, $candidates );
		}
	}

	/**
	 * Walk a field definition tree — repeater/group sub_fields AND
	 * flexible-content layouts' sub_fields — recording every field once by
	 * key. Deliberately does not stop at the first match: the caller needs
	 * every candidate, both to detect ambiguity and to report diagnostics.
	 */
	private function collect_fields_tree( $fields, array &$candidates ) {
		$stack = array_values( $fields );
		while ( ! empty( $stack ) ) {
			$field = array_shift( $stack );
			if ( ! is_array( $field ) ) {
				continue;
			}
			$key = (string) ( $field['key'] ?? '' );
			if ( '' !== $key && ! isset( $candidates[ $key ] ) ) {
				$candidates[ $key ] = array(
					'key'   => $key,
					'label' => (string) ( $field['label'] ?? '' ),
					'name'  => (string) ( $field['name'] ?? '' ),
				);
			}
			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$stack = array_merge( $stack, $field['sub_fields'] );
			}
			if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $layout ) {
					if ( is_array( $layout ) && ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						$stack = array_merge( $stack, $layout['sub_fields'] );
					}
				}
			}
		}
	}

	private function describe_candidates( $candidates ) {
		$labels = array();
		foreach ( $candidates as $field ) {
			$label = '' !== $field['label'] ? $field['label'] : $field['name'];
			if ( '' !== trim( (string) $label ) ) {
				$labels[] = trim( (string) $label );
			}
		}
		$labels = array_values( array_unique( $labels ) );
		return empty( $labels ) ? '(none)' : implode( ', ', $labels );
	}

	/**
	 * Derive the generated question's own Category/Exercise/Type,
	 * defaulting to today's only generation target (Book / Exercise 1 /
	 * DragDrop) when a pending record predates the Exercise field or
	 * otherwise omits one of these. This is what makes classification
	 * authoritative from the question itself rather than any template.
	 */
	private function resolve_classification( $question ) {
		$category = trim( (string) ( $question['category'] ?? '' ) );
		$exercise = trim( (string) ( $question['exercise'] ?? '' ) );
		$type     = trim( (string) ( $question['type'] ?? '' ) );
		return array(
			'category' => '' !== $category ? $category : 'Book',
			'exercise' => '' !== $exercise ? $exercise : 'Exercise 1',
			'type'     => '' !== $type ? $type : 'DragDrop',
		);
	}

	/**
	 * Assign the real WordPress Category/Exercise taxonomy terms named by
	 * the generated question's own classification — never inherited from a
	 * template. Uses only dynamic, name-based lookup (find_taxonomy_term_by_name())
	 * so no taxonomy slug or term ID is ever hard-coded or guessed.
	 *
	 * The real Reference List edit screen shows Exercise 1-5 nested under
	 * each Category in a single hierarchical taxonomy's checkbox metabox
	 * (Book -> Exercise 1, Website -> Exercise 1, etc.), so Exercise is
	 * looked up as a CHILD term of the resolved Category term first — this
	 * matters because "Exercise 1" is plausibly not a globally unique term
	 * name across categories. If that does not find a match, a flat,
	 * taxonomy-wide search is tried as a fallback in case Exercise is not
	 * actually nested under Category on this site after all.
	 *
	 * wp_set_object_terms() is called with $append = false (replace, not
	 * add) for every taxonomy touched, so a template's own Category/Exercise
	 * terms for that same taxonomy can never survive alongside — or leak
	 * into — the generated question's classification.
	 *
	 * @return array|WP_Error {categoryTaxonomy, categoryTermId,
	 *         exerciseTaxonomy, exerciseTermId} on success, or a WP_Error
	 *         naming exactly which term could not be found.
	 */
	private function assign_generated_classification( $new_id, $post_type, $classification ) {
		if ( ! function_exists( 'wp_set_object_terms' ) ) {
			return new WP_Error( 'citex_taxonomy_unavailable', 'WordPress taxonomy functions are unavailable.' );
		}

		$category_match = $this->find_taxonomy_term_by_name( $post_type, $classification['category'] );
		if ( null === $category_match ) {
			return new WP_Error(
				'citex_category_term_not_found',
				sprintf( 'Citex could not find a "%s" Reference Category term for this post type.', $classification['category'] )
			);
		}

		$exercise_match = $this->find_taxonomy_term_by_name( $post_type, $classification['exercise'], $category_match['taxonomy'], $category_match['termId'] );
		if ( null === $exercise_match ) {
			$exercise_match = $this->find_taxonomy_term_by_name( $post_type, $classification['exercise'] );
		}
		if ( null === $exercise_match ) {
			return new WP_Error(
				'citex_exercise_term_not_found',
				sprintf( 'Citex could not find an "%1$s" term (checked under "%2$s" and across the post type).', $classification['exercise'], $classification['category'] )
			);
		}

		$terms_by_taxonomy = array();
		$terms_by_taxonomy[ $category_match['taxonomy'] ][] = $category_match['termId'];
		$terms_by_taxonomy[ $exercise_match['taxonomy'] ][] = $exercise_match['termId'];
		foreach ( $terms_by_taxonomy as $taxonomy => $term_ids ) {
			wp_set_object_terms( $new_id, array_values( array_unique( $term_ids ) ), $taxonomy, false );
		}

		return array(
			'categoryTaxonomy' => $category_match['taxonomy'],
			'categoryTermId'   => $category_match['termId'],
			'exerciseTaxonomy' => $exercise_match['taxonomy'],
			'exerciseTermId'   => $exercise_match['termId'],
		);
	}

	/**
	 * Dynamically resolve a term by its exact (case-insensitive) name —
	 * never a hard-coded slug or term ID. Searches every taxonomy attached
	 * to $post_type unless $parent_taxonomy is given, in which case only
	 * that taxonomy is searched, optionally constrained to children of
	 * $parent_term_id.
	 *
	 * @return array{taxonomy: string, termId: int}|null
	 */
	private function find_taxonomy_term_by_name( $post_type, $label, $parent_taxonomy = null, $parent_term_id = null ) {
		if ( ! function_exists( 'get_object_taxonomies' ) || ! function_exists( 'get_terms' ) ) {
			return null;
		}
		$label = trim( (string) $label );
		if ( '' === $label ) {
			return null;
		}
		$taxonomies = null !== $parent_taxonomy ? array( $parent_taxonomy ) : get_object_taxonomies( $post_type, 'names' );
		if ( ! is_array( $taxonomies ) ) {
			return null;
		}
		foreach ( $taxonomies as $taxonomy ) {
			$args = array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			);
			if ( null !== $parent_term_id ) {
				$args['parent'] = $parent_term_id;
			}
			$terms = get_terms( $args );
			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( is_object( $term ) && isset( $term->name, $term->term_id ) && 0 === strcasecmp( trim( (string) $term->name ), $label ) ) {
					return array(
						'taxonomy' => $taxonomy,
						'termId'   => (int) $term->term_id,
					);
				}
			}
		}
		return null;
	}

	/**
	 * Best-effort classification tagging for the no-template path. Every
	 * taxonomy already attached to the Reference List post type is checked
	 * for a term literally named Harvard, ReferenceList, Book or DragDrop;
	 * only terms that already exist are applied. This predates — and is
	 * superseded for Category/Exercise by — assign_generated_classification(),
	 * which runs unconditionally afterwards and is authoritative; this is
	 * left in place only as harmless, best-effort tagging for any of the
	 * other three words that happen to exist as terms too.
	 */
	private function apply_classification_terms( $new_id, $post_type ) {
		if ( ! function_exists( 'get_object_taxonomies' ) || ! function_exists( 'get_term_by' ) || ! function_exists( 'wp_set_object_terms' ) ) {
			return;
		}
		$taxonomies = get_object_taxonomies( $post_type, 'names' );
		if ( ! is_array( $taxonomies ) ) {
			return;
		}

		$terms_by_taxonomy = array();
		foreach ( $taxonomies as $taxonomy ) {
			foreach ( array( 'Harvard', 'ReferenceList', 'Book', 'DragDrop' ) as $label ) {
				$term = get_term_by( 'name', $label, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					$terms_by_taxonomy[ $taxonomy ][] = (int) $term->term_id;
				}
			}
		}
		foreach ( $terms_by_taxonomy as $taxonomy => $term_ids ) {
			wp_set_object_terms( $new_id, array_values( array_unique( $term_ids ) ), $taxonomy, false );
		}
	}

	private function normalise_label( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_replace( '/[^a-z0-9]+/', ' ', $value );
	}

	private function clone_template_meta( $template_id, $new_id ) {
		$meta = get_post_meta( $template_id );
		foreach ( $meta as $key => $values ) {
			if ( in_array( $key, array( '_edit_lock', '_edit_last', '_wp_old_slug' ), true ) ) {
				continue;
			}
			delete_post_meta( $new_id, $key );
			foreach ( $values as $value ) {
				add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
			}
		}
	}

	private function clone_template_terms( $template_id, $new_id, $post_type ) {
		$taxonomies = get_object_taxonomies( $post_type, 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$term_ids = wp_get_object_terms( $template_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $term_ids ) ) {
				continue;
			}
			wp_set_object_terms( $new_id, $term_ids, $taxonomy, false );
		}
	}

	private function write_acf_value( $post_id, $field_key, $value ) {
		update_field( $field_key, $value, $post_id );
	}

	/**
	 * Write a list of plain draggable text values into an ACF field. If the
	 * field is a simple (non-repeater) list-type field, the values are
	 * written directly. If it is a repeater, its real row shape is
	 * discovered via resolve_repeater_text_row_shape() and every row is
	 * written using that shape — never just the first sub-field, which
	 * previously left a repeater's OTHER sub-field values (e.g. a
	 * punctuation-selector cloned from a template) in place because only
	 * one of a row's several required sub-fields was ever actually set.
	 *
	 * @return array|WP_Error The resolved shape (needed by
	 *         verify_repeater_text_values() afterwards), or a WP_Error
	 *         naming exactly what about the repeater's structure Citex
	 *         could not determine.
	 */
	private function write_repeater_rows( $post_id, $field_key, $values ) {
		$values = array_values( is_array( $values ) ? $values : array() );
		$field  = function_exists( 'acf_get_field' ) ? acf_get_field( $field_key ) : null;

		if ( ! is_array( $field ) || 'repeater' !== ( $field['type'] ?? '' ) || empty( $field['sub_fields'] ) ) {
			update_field( $field_key, $values, $post_id );
			return array( 'textSubfieldKey' => '', 'typeSubfieldKey' => '', 'textChoiceValue' => '' );
		}

		$shape = $this->resolve_repeater_text_row_shape( $field['sub_fields'] );
		if ( is_wp_error( $shape ) ) {
			return $shape;
		}

		$rows = array();
		foreach ( $values as $value ) {
			$row = array( $shape['textSubfieldKey'] => (string) $value );
			if ( '' !== $shape['typeSubfieldKey'] ) {
				$row[ $shape['typeSubfieldKey'] ] = $shape['textChoiceValue'];
			}
			$rows[] = $row;
		}
		update_field( $field_key, $rows, $post_id );
		return $shape;
	}

	/**
	 * Determine which sub-field of a repeater holds the actual draggable
	 * text, and — if the repeater has a row-type selector sub-field (a
	 * select/radio/button_group with its own choices, e.g. the "Select
	 * Element" field reported on the live site distinguishing Text rows
	 * from Punctuation rows) — which of its choices marks a row as plain
	 * text. Every fact used here comes from the field's own ACF definition
	 * (acf_get_field()), never a guess: if the shape can't be determined
	 * confidently, this fails with a diagnostic naming exactly what was
	 * found, rather than picking a sub-field arbitrarily.
	 *
	 * @return array{textSubfieldKey: string, typeSubfieldKey: string, textChoiceValue: string}|WP_Error
	 */
	private function resolve_repeater_text_row_shape( $sub_fields ) {
		$choice_fields = array();
		foreach ( $sub_fields as $sub ) {
			if ( ! is_array( $sub ) ) {
				continue;
			}
			$type = (string) ( $sub['type'] ?? '' );
			if ( in_array( $type, array( 'select', 'radio', 'button_group' ), true ) && ! empty( $sub['choices'] ) && is_array( $sub['choices'] ) ) {
				$choice_fields[] = $sub;
			}
		}

		// No row-type selector at all: a plain repeater where every row is
		// just one value — use its only sub-field, matching a genuinely
		// simple repeater shape.
		if ( empty( $choice_fields ) ) {
			if ( empty( $sub_fields[0]['key'] ) ) {
				return new WP_Error( 'citex_repeater_shape_unknown', 'Citex could not identify a usable sub-field on this repeater.' );
			}
			return array(
				'textSubfieldKey' => (string) $sub_fields[0]['key'],
				'typeSubfieldKey' => '',
				'textChoiceValue' => '',
			);
		}

		// More than one choice-type sub-field is not automatically
		// ambiguous — a repeater can legitimately have both a row-type
		// selector (the discriminator we want) AND a separate choice-type
		// VALUE field for one of the row types (e.g. the reported
		// "Punctuation" picker, which selects a punctuation VALUE, not a
		// row TYPE). Prefer whichever choice field's own label/name reads
		// as a discriminator — "select", "element", "type" or "kind" — a
		// common, recognisable ACF naming convention for exactly this kind
		// of field, matching the reported "Select Element" field. Only if
		// that naming convention does not narrow it to exactly one field is
		// this a genuine ambiguity Citex will not guess through.
		$discriminator = null;
		if ( 1 === count( $choice_fields ) ) {
			$discriminator = $choice_fields[0];
		} else {
			$discriminator_candidates = array();
			foreach ( $choice_fields as $sub ) {
				$label = $this->normalise_label( $sub['label'] ?? '' );
				$name  = $this->normalise_label( $sub['name'] ?? '' );
				foreach ( array( 'select', 'element', 'type', 'kind' ) as $needle ) {
					if ( false !== strpos( $label, $needle ) || false !== strpos( $name, $needle ) ) {
						$discriminator_candidates[] = $sub;
						break;
					}
				}
			}
			if ( 1 === count( $discriminator_candidates ) ) {
				$discriminator = $discriminator_candidates[0];
			}
		}

		if ( null === $discriminator ) {
			return new WP_Error(
				'citex_repeater_shape_ambiguous',
				sprintf(
					'This repeater has more than one selectable sub-field (%s); Citex cannot determine which one selects the row type.',
					implode( ', ', array_map( array( $this, 'describe_field' ), $choice_fields ) )
				)
			);
		}

		$text_choice_value = null;
		foreach ( (array) $discriminator['choices'] as $choice_value => $choice_label ) {
			if ( 'text' === $this->normalise_label( (string) $choice_value ) || 'text' === $this->normalise_label( (string) $choice_label ) ) {
				$text_choice_value = (string) $choice_value;
				break;
			}
		}
		if ( null === $text_choice_value ) {
			return new WP_Error(
				'citex_repeater_text_choice_not_found',
				sprintf(
					'Citex could not find a "Text" choice on the "%1$s" sub-field. Available choices: %2$s.',
					$this->describe_field( $discriminator ),
					implode( ', ', $discriminator['choices'] )
				)
			);
		}

		$text_candidates = array();
		foreach ( $sub_fields as $sub ) {
			if ( ! is_array( $sub ) || ( $sub['key'] ?? null ) === ( $discriminator['key'] ?? null ) ) {
				continue;
			}
			if ( in_array( (string) ( $sub['type'] ?? '' ), array( 'text', 'textarea', 'wysiwyg' ), true ) ) {
				$text_candidates[] = $sub;
			}
		}
		if ( empty( $text_candidates ) ) {
			return new WP_Error( 'citex_repeater_text_field_not_found', 'Citex could not find a text sub-field on this repeater to hold the draggable value.' );
		}

		$preferred = null;
		foreach ( $text_candidates as $sub ) {
			$label = $this->normalise_label( $sub['label'] ?? '' );
			$name  = $this->normalise_label( $sub['name'] ?? '' );
			if ( 'text' === $label || 'text' === $name || false !== strpos( $label, 'text' ) || false !== strpos( $name, 'text' ) ) {
				$preferred = $sub;
				break;
			}
		}
		if ( null === $preferred ) {
			if ( 1 === count( $text_candidates ) ) {
				$preferred = $text_candidates[0];
			} else {
				return new WP_Error(
					'citex_repeater_text_field_ambiguous',
					sprintf(
						'Citex found more than one possible text sub-field (%s) and none is clearly labelled "Text".',
						implode( ', ', array_map( array( $this, 'describe_field' ), $text_candidates ) )
					)
				);
			}
		}

		return array(
			'textSubfieldKey' => (string) $preferred['key'],
			'typeSubfieldKey' => (string) $discriminator['key'],
			'textChoiceValue' => $text_choice_value,
		);
	}

	/**
	 * Read a repeater's actual saved rows back from WordPress and confirm
	 * the text sub-field of every row exactly matches the expected values,
	 * in order. This is what catches a stray non-text value (such as a
	 * template's cloned punctuation selection) surviving in a row Citex
	 * intended to be plain text, and any missing, reordered, or truncated
	 * draggable value.
	 */
	private function verify_repeater_text_values( $post_id, $field_key, $shape, $expected_values ) {
		if ( ! function_exists( 'get_field' ) ) {
			return true;
		}
		$text_key = is_array( $shape ) ? ( $shape['textSubfieldKey'] ?? '' ) : '';
		$stored   = get_field( $field_key, $post_id, false );
		$stored   = is_array( $stored ) ? $stored : array();

		$actual_values = array();
		foreach ( $stored as $row ) {
			if ( '' !== $text_key && is_array( $row ) && array_key_exists( $text_key, $row ) ) {
				$actual_values[] = trim( (string) $row[ $text_key ] );
			} elseif ( is_scalar( $row ) ) {
				$actual_values[] = trim( (string) $row );
			} else {
				$actual_values[] = '';
			}
		}

		$expected_trimmed = array_map(
			function ( $v ) {
				return trim( (string) $v );
			},
			$expected_values
		);

		if ( $actual_values !== $expected_trimmed ) {
			return new WP_Error(
				'citex_repeater_verification_failed',
				sprintf(
					'Expected draggable values [%1$s] but found [%2$s] after saving.',
					implode( ', ', $expected_trimmed ),
					implode( ', ', $actual_values )
				)
			);
		}
		return true;
	}

	private function describe_field( $field ) {
		$label = (string) ( $field['label'] ?? '' );
		return '' !== $label ? $label : (string) ( $field['name'] ?? '' );
	}

	/**
	 * Persistent Category x Exercise x Type population history, kept purely
	 * in Citex's own WordPress option (never read from, or written to, any
	 * WordPress taxonomy/ACF data) so exercise-coverage tracking survives a
	 * pending question being populated and removed from the pending queue —
	 * without it, Citex would forget a slot was ever filled the moment it
	 * left the pending list, and the next batch could refill an
	 * already-complete exercise while leaving another one empty.
	 */
	const OPTION_COVERAGE = 'citex_population_coverage';

	public static function get_population_coverage() {
		$coverage = get_option( self::OPTION_COVERAGE, array() );
		return is_array( $coverage ) ? $coverage : array();
	}

	private function record_population_coverage( $classification ) {
		$coverage = self::get_population_coverage();
		$category = $classification['category'];
		$exercise = $classification['exercise'];
		$type     = $classification['type'];
		$coverage[ $category ][ $exercise ][ $type ] = (int) ( $coverage[ $category ][ $exercise ][ $type ] ?? 0 ) + 1;
		update_option( self::OPTION_COVERAGE, $coverage, false );
	}

	private function redirect_back() {
		wp_safe_redirect( admin_url( 'admin.php?page=citex-populate' ) );
		exit;
	}

	/**
	 * Reusable "finalisation save": re-runs the same WordPress/ACF save
	 * lifecycle a manual wp-admin "Update" click goes through against one
	 * existing post ID — wp_update_post() (which unconditionally fires
	 * save_post, save_post_{post_type}, wp_insert_post, wp_after_insert_post,
	 * transition_post_status and the {old}_to_{new} hooks), clean_post_cache(),
	 * then the explicit do_action('acf/save_post', ...) that only ACF's own
	 * admin form-processing pipeline would otherwise fire (see the class
	 * docblock for the live investigation behind this).
	 *
	 * Used both by populate_one() immediately after a new question's ACF
	 * fields/taxonomies/status are written, and by
	 * maybe_handle_finalize_submit() to re-run the same lifecycle against an
	 * EXISTING, already-populated question by post ID — e.g. to repair a
	 * question that was populated before this finalisation step existed,
	 * without touching its content.
	 *
	 * Never writes to any field, meta, or taxonomy itself: the only value it
	 * ever sets is post_status — $args['post_status'] if given, otherwise
	 * the post's own current status is written back unchanged, so calling
	 * this against an existing Draft never publishes it and calling it
	 * against an existing Published post never drafts it. Every taxonomy
	 * term and every ACF field value is snapshotted immediately before and
	 * compared immediately after; if either differs, this is treated as a
	 * failure (WP_Error) rather than a silently-accepted content change —
	 * this method's job is exclusively to re-trigger the save lifecycle, not
	 * to edit anything.
	 *
	 * @param int   $post_id
	 * @param array $args {post_status?: string} Optional target status; the
	 *              post's current status is preserved when omitted.
	 * @return array|WP_Error Diagnostics on success, or a WP_Error naming
	 *         exactly what could not be verified.
	 */
	public static function finalize_question( $post_id, array $args = array() ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return new WP_Error( 'citex_finalize_missing_post_id', __( 'No post ID was supplied to finalise.', 'citex-tools' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'citex_finalize_post_not_found', sprintf( __( 'No post found with ID %d.', 'citex-tools' ), $post_id ) );
		}

		$target_status = ( isset( $args['post_status'] ) && '' !== (string) $args['post_status'] )
			? sanitize_key( (string) $args['post_status'] )
			: $post->post_status;

		$before_terms = self::snapshot_object_terms( $post_id, $post->post_type );
		$before_acf   = function_exists( 'get_fields' ) ? ( get_fields( $post_id, false ) ?: array() ) : array();

		$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => $target_status ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'acf/save_post', $post_id );
		}

		$after_status = function_exists( 'get_post_status' ) ? get_post_status( $post_id ) : $target_status;
		if ( $after_status !== $target_status ) {
			return new WP_Error(
				'citex_finalize_status_not_verified',
				sprintf( __( 'Finalisation status did not verify: expected "%1$s", found "%2$s".', 'citex-tools' ), $target_status, (string) $after_status )
			);
		}

		$after_terms = self::snapshot_object_terms( $post_id, $post->post_type );
		if ( $after_terms !== $before_terms ) {
			return new WP_Error( 'citex_finalize_terms_changed', __( 'Finalisation unexpectedly changed this question\'s taxonomy terms; not keeping the change.', 'citex-tools' ) );
		}

		$after_acf = function_exists( 'get_fields' ) ? ( get_fields( $post_id, false ) ?: array() ) : array();
		if ( $after_acf != $before_acf ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- structurally-equal but differently-ordered ACF field trees must not be treated as a content change.
			return new WP_Error( 'citex_finalize_acf_changed', __( 'Finalisation unexpectedly changed this question\'s ACF field values; not keeping the change.', 'citex-tools' ) );
		}

		return array(
			'postId'         => $post_id,
			'statusExpected' => $target_status,
			'statusVerified' => true,
			'termsPreserved' => true,
			'acfPreserved'   => true,
		);
	}

	/**
	 * Every taxonomy term ID attached to this post, across every taxonomy
	 * registered for its post type, sorted deterministically — used only to
	 * detect whether finalize_question()'s save-lifecycle re-trigger left
	 * taxonomy state unchanged. Never written to.
	 */
	private static function snapshot_object_terms( $post_id, $post_type ) {
		if ( ! function_exists( 'get_object_taxonomies' ) || ! function_exists( 'wp_get_object_terms' ) ) {
			return array();
		}
		$taxonomies = get_object_taxonomies( $post_type, 'names' );
		$snapshot   = array();
		if ( is_array( $taxonomies ) ) {
			sort( $taxonomies );
			foreach ( $taxonomies as $taxonomy ) {
				$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
				$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', (array) $ids );
				sort( $ids );
				$snapshot[ $taxonomy ] = $ids;
			}
		}
		return $snapshot;
	}

	/**
	 * Admin action: finalise one or more EXISTING, already-populated
	 * questions by post ID — repairing a question that was populated before
	 * finalize_question() existed (or otherwise never received a manual
	 * wp-admin Update click) without requiring anyone to open and re-save it
	 * by hand. Never changes status, content, ACF values, or taxonomy terms
	 * — see finalize_question(). Capped at MAX_FINALIZE_BATCH per submission
	 * so this cannot be used to silently sweep an entire Reference List in
	 * one click; the current UI (Questions page) only ever submits one post
	 * ID at a time, but the handler itself is batch-capable.
	 *
	 * Called on admin_init (before any output), matching every other Citex
	 * admin handler's pattern — see class-citex-admin.php.
	 */
	public function maybe_handle_finalize_submit() {
		if ( empty( $_POST['citex_finalize_submit'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE_FINALIZE_ACTION, 'citex_finalize_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to finalise Reference List questions.', 'citex-tools' ) );
		}

		$raw_ids  = isset( $_POST['citex_finalize_post_ids'] ) && is_array( $_POST['citex_finalize_post_ids'] )
			? wp_unslash( $_POST['citex_finalize_post_ids'] )
			: array();
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );

		if ( empty( $post_ids ) ) {
			Citex_Admin::set_notice( __( 'No questions were selected to finalise.', 'citex-tools' ), 'warning' );
			$this->redirect_to_questions();
		}

		if ( count( $post_ids ) > self::MAX_FINALIZE_BATCH ) {
			Citex_Admin::set_notice(
				sprintf(
					/* translators: 1: number of selected posts, 2: maximum batch size */
					__( 'Too many questions selected at once (%1$d). Finalise at most %2$d at a time.', 'citex-tools' ),
					count( $post_ids ),
					self::MAX_FINALIZE_BATCH
				),
				'error'
			);
			$this->redirect_to_questions();
		}

		$succeeded = array();
		$failed    = array();
		foreach ( $post_ids as $post_id ) {
			if ( ! get_post( $post_id ) ) {
				$failed[] = sprintf( '#%1$d: %2$s', $post_id, __( 'post not found', 'citex-tools' ) );
				continue;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$failed[] = sprintf( '#%1$d: %2$s', $post_id, __( 'no permission', 'citex-tools' ) );
				continue;
			}

			$result = self::finalize_question( $post_id );
			if ( is_wp_error( $result ) ) {
				$failed[] = sprintf( '#%1$d: %2$s', $post_id, $result->get_error_message() );
				continue;
			}
			$succeeded[] = $post_id;
		}

		$message = sprintf(
			/* translators: 1: succeeded count, 2: failed count */
			__( 'Finalisation complete. Succeeded: %1$d. Failed: %2$d.', 'citex-tools' ),
			count( $succeeded ),
			count( $failed )
		);
		if ( ! empty( $succeeded ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: comma-separated post IDs */
				__( 'Finalised post IDs: %s.', 'citex-tools' ),
				implode( ', ', $succeeded )
			);
		}
		if ( ! empty( $failed ) ) {
			$message .= ' ' . implode( ' | ', array_slice( $failed, 0, 5 ) );
		}
		Citex_Admin::set_notice( $message, empty( $failed ) ? 'success' : 'warning' );
		$this->redirect_to_questions();
	}

	private function redirect_to_questions() {
		wp_safe_redirect( admin_url( 'admin.php?page=citex-questions' ) );
		exit;
	}
}
