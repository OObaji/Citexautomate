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
		$categories         = array( 'book' => 'Book' );
		$question_types     = array( 'dragdrop' => 'DragDrop' );
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

	private function maybe_handle_submit() {
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

		$quantity = max( 1, min( 100, $quantity ) );
		if ( 'harvard' !== $style || 'liverpool_hope' !== $institution || 'book' !== $category || 'dragdrop' !== $type ) {
			Citex_Admin::set_notice( __( 'The current AI generator supports Liverpool Hope Harvard → Book → DragDrop.', 'citex-tools' ), 'error' );
			$this->redirect_back();
		}
		if ( ! in_array( $difficulty, array( 'easy', 'medium', 'hard' ), true ) ) {
			$difficulty = 'medium';
		}

		$pending  = self::get_pending_questions();
		$used_ids = $this->collect_used_question_ids( $pending );
		$result   = Citex_AI::generate_questions(
			array(
				'quantity'   => $quantity,
				'starting_id'=> $starting_id,
				'difficulty' => $difficulty,
				'web_verify' => $web_verify,
				'used_ids'   => array_keys( $used_ids ),
			)
		);

		if ( is_wp_error( $result ) ) {
			Citex_Admin::set_notice( $result->get_error_message(), 'error' );
			$this->redirect_back();
		}

		self::save_pending_questions( array_merge( $pending, $result ) );
		Citex_Admin::set_notice(
			sprintf( _n( '%d AI question generated. Validate it before population.', '%d AI questions generated. Validate them before population.', count( $result ), 'citex-tools' ), count( $result ) ),
			'success'
		);
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
