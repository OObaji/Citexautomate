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

		$referencing_styles = array( 'harvard' => 'Harvard' );
		$institutions       = array( 'liverpool_hope' => 'Liverpool Hope University' );
		$categories         = array( 'book' => 'Book', 'edited_book' => 'Edited Book' );
		$id_prefixes        = array(
			'book'        => Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_BOOK ),
			'edited_book' => Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_EDITED_BOOK ),
		);
		$question_types     = array( 'dragdrop' => 'DragDrop', 'mcq' => 'MCQ' );
		$difficulties       = array( 'easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard' );
		$pending_questions  = self::get_pending_questions();
		$ai_configured      = '' !== Citex_AI::get_api_key();
		require CITEX_TOOLS_PATH . 'admin/views/generate.php';
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
		$institution = isset( $_POST['citex_institution'] ) ? sanitize_key( wp_unslash( $_POST['citex_institution'] ) ) : '';
		$category    = isset( $_POST['citex_category'] ) ? sanitize_key( wp_unslash( $_POST['citex_category'] ) ) : '';
		$type        = isset( $_POST['citex_question_type'] ) ? sanitize_key( wp_unslash( $_POST['citex_question_type'] ) ) : '';
		$difficulty  = isset( $_POST['citex_difficulty'] ) ? sanitize_key( wp_unslash( $_POST['citex_difficulty'] ) ) : 'medium';
		$quantity    = isset( $_POST['citex_quantity'] ) ? absint( $_POST['citex_quantity'] ) : 10;
		$starting_id = isset( $_POST['citex_starting_id'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['citex_starting_id'] ) ) ) : 'BK01';
		$web_verify  = ! empty( $_POST['citex_ai_web_verify'] );

		$category_labels = array( 'book' => 'Book', 'edited_book' => 'Edited Book' );

		$quantity = max( 1, min( 100, $quantity ) );
		if ( 'harvard' !== $style || 'liverpool_hope' !== $institution || ! isset( $category_labels[ $category ] ) || ! in_array( $type, array( 'dragdrop', 'mcq' ), true ) ) {
			Citex_Admin::set_notice( __( 'The current AI generator supports Liverpool Hope Harvard → Book or Edited Book → DragDrop or MCQ.', 'citex-tools' ), 'error' );
			$this->redirect_back();
		}
		if ( ! in_array( $difficulty, array( 'easy', 'medium', 'hard' ), true ) ) {
			$difficulty = 'medium';
		}

		$category_label = $category_labels[ $category ];
		$starting_id    = self::normalise_starting_id( $starting_id, $category_label );

		$type_label  = 'mcq' === $type ? 'MCQ' : 'DragDrop';
		$pending     = self::get_pending_questions();
		$used_ids    = $this->collect_used_question_ids( $pending );
		// Citex — not Gemini — assigns each slot's Exercise, deterministically,
		// before generation even starts. Gemini's response schema carries no
		// exercise field, so there is nothing from it to trust or distrust here.
		$assignments = self::build_exercise_assignments( $category_label, $type_label, $quantity );
		$result      = Citex_AI::generate_questions(
			array(
				'quantity'            => $quantity,
				'starting_id'         => $starting_id,
				'difficulty'          => $difficulty,
				'web_verify'          => $web_verify,
				'used_ids'            => array_keys( $used_ids ),
				'exercise_assignments'=> $assignments,
				'type'                => $type,
				'category'            => $category,
			)
		);

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
			_n( '%d AI question generated. Validate it before population.', '%d AI questions generated. Validate them before population.', count( $result ), 'citex-tools' ),
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
	 */
	public static function normalise_starting_id( $starting_id, $category_label ) {
		$starting_id      = strtoupper( trim( (string) $starting_id ) );
		$expected_prefix  = Citex_Reference_Rules::id_prefix( $category_label );
		if ( 0 !== strpos( $starting_id, $expected_prefix ) ) {
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
		$scan = Citex_Scanner::get_last_scan();
		foreach ( ( $scan['questions'] ?? array() ) as $question ) {
			$id = strtoupper( trim( (string) ( $question['questionId'] ?? '' ) ) );
			if ( '' !== $id ) { $used[ $id ] = true; }
		}
		return $used;
	}

	private function redirect_back() {
		wp_safe_redirect( admin_url( 'admin.php?page=citex-generate' ) );
		exit;
	}
}
