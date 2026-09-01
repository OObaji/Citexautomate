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
 * - only after a successful field write can the requested final post status
 *   be applied;
 * - failed records are not removed from the pending queue.
 *
 * Template hierarchy (see resolve_population_fields()/resolve_population_fields_without_template()
 * and populate_one()):
 * 1. If a real Harvard/ReferenceList/Book/DragDrop record already exists
 *    (active or in Bin), it is used as a template: its post meta and
 *    taxonomy terms are cloned so unknown site-specific setup survives.
 *    This remains the safest path and is preferred whenever available.
 * 2. If no such record exists, Citex does not require one. The Question
 *    Parts / Fixed Text / Confusing Words ACF fields are addressed by the
 *    already-known field keys, and the Question/Scenario field is located
 *    by inspecting the ACF field groups registered against the Reference
 *    List post type (the same label/name matching find_acf_field_key()
 *    already used against a template post, just sourced from the field
 *    group definitions instead of one post's values). No meta is cloned in
 *    this path — Citex has no way to know which of an arbitrary template
 *    post's other meta keys would apply to an unrelated question. Any
 *    taxonomy attached to the post type is inspected for terms literally
 *    named Harvard / ReferenceList / Book / DragDrop, and only terms that
 *    already exist are applied; this is best effort, since nothing in this
 *    plugin's discovered schema shows classification ever depends on a
 *    taxonomy (the scanner and validator both classify purely from the
 *    post title), so no taxonomy assignment is treated as required.
 * 3. If the Question/Scenario ACF field cannot be located by either path,
 *    Citex stops with a clear error rather than writing a record with a
 *    missing field.
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
			? $this->resolve_population_fields( $template_id )
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

			$this->write_acf_value( $new_id, $field_map['fixedText'], (string) ( $question['fixedText'] ?? '' ) );
			$this->write_acf_list( $new_id, $field_map['questionParts'], $question['questionParts'] ?? array() );
			$this->write_acf_list( $new_id, $field_map['confusingWords'], $question['confusingWords'] ?? array() );
			$this->write_acf_value( $new_id, $field_map['scenario'], (string) ( $question['scenario'] ?? '' ) );

			// Verify the most important field against the real ACF value before the
			// post can ever be published.
			if ( function_exists( 'get_field' ) ) {
				$stored_fixed = get_field( $field_map['fixedText'], $new_id, false );
				if ( trim( (string) $stored_fixed ) !== trim( (string) ( $question['fixedText'] ?? '' ) ) ) {
					throw new Exception( 'Fixed Text did not persist to the new Reference List record.' );
				}
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
			'postId'     => (int) $new_id,
			'questionId' => (string) ( $question['questionId'] ?? '' ),
			'status'     => $final_status,
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

	private function resolve_population_fields( $template_id ) {
		$known = $this->assert_known_acf_fields_registered();
		if ( is_wp_error( $known ) ) {
			return $known;
		}

		$scenario_key = $this->find_acf_field_key( $template_id, array( 'question', 'scenario', 'question text' ) );
		if ( ! $scenario_key ) {
			return new WP_Error( 'citex_question_field_not_found', 'Citex could not identify the ACF Question/Scenario field on the Book/DragDrop template. No posts were created.' );
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
	 * any existing template post. The three known ACF field keys are used
	 * directly; the Question/Scenario field is located by inspecting the
	 * ACF field groups registered against the Reference List post type
	 * itself, so no real question record needs to exist first.
	 */
	private function resolve_population_fields_without_template( $post_type ) {
		$known = $this->assert_known_acf_fields_registered();
		if ( is_wp_error( $known ) ) {
			return $known;
		}

		$scenario_key = $this->find_acf_field_key_by_post_type( $post_type, array( 'question', 'scenario', 'question text' ) );
		if ( ! $scenario_key ) {
			return new WP_Error( 'citex_question_field_not_found', 'Citex could not identify the ACF Question/Scenario field for this Reference List post type. No posts were created.' );
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

	private function find_acf_field_key( $post_id, $wanted_labels ) {
		if ( ! function_exists( 'get_field_objects' ) ) {
			return '';
		}
		$fields = get_field_objects( $post_id, false, false );
		return $this->search_fields_tree( is_array( $fields ) ? $fields : array(), $wanted_labels );
	}

	/**
	 * Locate an ACF field key from the field GROUP definitions registered
	 * against a post type, rather than from one post's populated values.
	 * This is what lets Citex find the Question/Scenario field before any
	 * matching Reference List record exists.
	 */
	private function find_acf_field_key_by_post_type( $post_type, $wanted_labels ) {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return '';
		}
		$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
		if ( ! is_array( $groups ) ) {
			return '';
		}
		foreach ( $groups as $group ) {
			$fields = acf_get_fields( $group );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			$found = $this->search_fields_tree( $fields, $wanted_labels );
			if ( '' !== $found ) {
				return $found;
			}
		}
		return '';
	}

	private function search_fields_tree( $fields, $wanted_labels ) {
		$wanted = array_map( array( $this, 'normalise_label' ), $wanted_labels );
		$stack  = array_values( $fields );
		while ( ! empty( $stack ) ) {
			$field = array_shift( $stack );
			if ( ! is_array( $field ) ) {
				continue;
			}
			$label = $this->normalise_label( $field['label'] ?? '' );
			$name  = $this->normalise_label( $field['name'] ?? '' );
			if ( in_array( $label, $wanted, true ) || in_array( $name, $wanted, true ) ) {
				return (string) ( $field['key'] ?? '' );
			}
			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$stack = array_merge( $stack, $field['sub_fields'] );
			}
		}
		return '';
	}

	/**
	 * Best-effort classification tagging for the no-template path. Every
	 * taxonomy already attached to the Reference List post type is checked
	 * for a term literally named Harvard, ReferenceList, Book or DragDrop;
	 * only terms that already exist are applied. Nothing discovered
	 * elsewhere in this plugin shows classification ever depending on a
	 * taxonomy (the scanner and validator both classify from the post
	 * title), so a taxonomy/term not existing here is not an error.
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
