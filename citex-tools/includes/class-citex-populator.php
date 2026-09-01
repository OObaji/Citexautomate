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
 */
class Citex_Populator {

	const NONCE_ACTION = 'citex_populate_questions';

	const FIELD_FIXED_TEXT      = 'field_59c2476bc859f';
	const FIELD_QUESTION_PARTS  = 'field_59c2476bc81b7';
	const FIELD_CONFUSING_WORDS = 'field_59c2476bc83ab';

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

		$template_id = $this->find_template_post_id( $post_type, $scan );
		$field_map   = $template_id
			? $this->resolve_population_fields( $template_id, $post_type )
			: $this->resolve_population_fields_without_template( $post_type );
		if ( is_wp_error( $field_map ) ) {
			Citex_Admin::set_notice( $field_map->get_error_message(), 'error' );
			$this->redirect_back();
		}

		$successful_keys = array();
		$created          = array();
		$failed           = array();

		foreach ( $eligible as $question ) {
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
				$summaries[] = sprintf(
					'%1$s (Category: %2$s ✓, Exercise: %3$s ✓, Type: %4$s ✓, ACF: Verified ✓)',
					$c['questionId'],
					$c['category'],
					$c['exercise'],
					$c['type']
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
		if ( 'DragDrop' !== $classification['type'] ) {
			return new WP_Error(
				'citex_unsupported_question_type',
				sprintf( 'Citex population does not yet support question type "%s" — its real ACF structure has not been supplied. Only DragDrop is currently supported. No post was created.', $classification['type'] )
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
				throw new Exception( $classification_result->get_error_message() );
			}

			$this->write_acf_value( $new_id, $field_map['fixedText'], (string) ( $question['fixedText'] ?? '' ) );
			$this->write_acf_list( $new_id, $field_map['questionParts'], $question['questionParts'] ?? array() );
			$this->write_acf_list( $new_id, $field_map['confusingWords'], $question['confusingWords'] ?? array() );
			$this->write_acf_value( $new_id, $field_map['scenario'], (string) ( $question['scenario'] ?? '' ) );

			// Verify the fields that make the question actually usable against
			// the real ACF values before the post can ever be published. A
			// record whose Scenario did not persist must never be published —
			// it would show students a blank or stale prompt with real
			// draggable answers.
			if ( function_exists( 'get_field' ) ) {
				$stored_fixed = get_field( $field_map['fixedText'], $new_id, false );
				if ( trim( (string) $stored_fixed ) !== trim( (string) ( $question['fixedText'] ?? '' ) ) ) {
					throw new Exception( 'Fixed Text did not persist to the new Reference List record.' );
				}
				$stored_scenario = get_field( $field_map['scenario'], $new_id, false );
				if ( trim( (string) $stored_scenario ) !== trim( (string) ( $question['scenario'] ?? '' ) ) ) {
					throw new Exception( 'Scenario did not persist to the new Reference List record.' );
				}
			}

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

			if ( 'publish' === $final_status ) {
				$publish_result = wp_update_post( array( 'ID' => $new_id, 'post_status' => 'publish' ), true );
				if ( is_wp_error( $publish_result ) ) {
					throw new Exception( $publish_result->get_error_message() );
				}
			}
		} catch ( Exception $e ) {
			wp_delete_post( $new_id, true );
			return new WP_Error( 'citex_population_failed', $e->getMessage() );
		}

		return array(
			'postId'           => (int) $new_id,
			'questionId'       => (string) ( $question['questionId'] ?? '' ),
			'status'           => $final_status,
			'category'         => $classification['category'],
			'exercise'         => $classification['exercise'],
			'type'             => $classification['type'],
			'categoryVerified' => true,
			'exerciseVerified' => true,
			'acfVerified'      => true,
		);
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

	private function resolve_population_fields( $template_id, $post_type ) {
		$known = $this->assert_known_acf_fields_registered();
		if ( is_wp_error( $known ) ) {
			return $known;
		}

		$scenario_key = $this->discover_scenario_field( $post_type, $template_id );
		if ( is_wp_error( $scenario_key ) ) {
			return $scenario_key;
		}

		return array(
			'fixedText'      => self::FIELD_FIXED_TEXT,
			'questionParts'  => self::FIELD_QUESTION_PARTS,
			'confusingWords' => self::FIELD_CONFUSING_WORDS,
			'scenario'       => $scenario_key,
		);
	}

	/**
	 * Same field map as resolve_population_fields(), but discovered without
	 * any existing template post.
	 */
	private function resolve_population_fields_without_template( $post_type ) {
		$known = $this->assert_known_acf_fields_registered();
		if ( is_wp_error( $known ) ) {
			return $known;
		}

		$scenario_key = $this->discover_scenario_field( $post_type, 0 );
		if ( is_wp_error( $scenario_key ) ) {
			return $scenario_key;
		}

		return array(
			'fixedText'      => self::FIELD_FIXED_TEXT,
			'questionParts'  => self::FIELD_QUESTION_PARTS,
			'confusingWords' => self::FIELD_CONFUSING_WORDS,
			'scenario'       => $scenario_key,
		);
	}

	private function assert_known_acf_fields_registered() {
		foreach ( array( self::FIELD_FIXED_TEXT, self::FIELD_QUESTION_PARTS, self::FIELD_CONFUSING_WORDS ) as $field_key ) {
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

	private function write_acf_list( $post_id, $field_key, $values ) {
		$values = array_values( is_array( $values ) ? $values : array() );
		$field  = function_exists( 'acf_get_field' ) ? acf_get_field( $field_key ) : null;
		if ( is_array( $field ) && 'repeater' === ( $field['type'] ?? '' ) && ! empty( $field['sub_fields'][0]['key'] ) ) {
			$sub_key = $field['sub_fields'][0]['key'];
			$rows = array();
			foreach ( $values as $value ) {
				$rows[] = array( $sub_key => (string) $value );
			}
			update_field( $field_key, $rows, $post_id );
			return;
		}
		update_field( $field_key, $values, $post_id );
	}

	private function redirect_back() {
		wp_safe_redirect( admin_url( 'admin.php?page=citex-populate' ) );
		exit;
	}
}
