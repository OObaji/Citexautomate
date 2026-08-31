<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Generate Questions page.
 *
 * Renders the question-generation form only. The actual generation
 * engine (AI-driven question creation) is a future module that will
 * plug into maybe_handle_submit() below without changing the form.
 */
class Citex_Generator {

	const NONCE_ACTION = 'citex_generate_questions';

	public function render() {
		$this->maybe_handle_submit();

		$referencing_styles = array(
			'harvard' => 'Harvard',
		);

		$institutions = array(
			'liverpool_hope' => 'Liverpool Hope University',
		);

		$categories = array(
			'book'         => 'Book',
			'journal'      => 'Journal Article',
			'website'      => 'Website',
			'book_chapter' => 'Book Chapter',
			'report'       => 'Report',
			'newspaper'    => 'Newspaper',
			'other'        => 'Other',
		);

		$question_types = array(
			'multiple_choice'   => 'Multiple Choice',
			'identify_error'    => 'Identify the Error',
			'correct_reference' => 'Correct Reference',
			'missing_element'   => 'Missing Element',
		);

		$difficulties = array(
			'easy'   => 'Easy',
			'medium' => 'Medium',
			'hard'   => 'Hard',
		);

		require CITEX_TOOLS_PATH . 'admin/views/generate.php';
	}

	/**
	 * Handles the (currently stubbed) form submission and redirects back
	 * to avoid a resubmission on refresh. No questions are generated —
	 * this only surfaces the "not yet connected" notice.
	 */
	private function maybe_handle_submit() {
		if ( empty( $_POST['citex_generate_submit'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, 'citex_generate_nonce' );

		// Generation engine is not yet connected — no questions are created here.
		Citex_Admin::set_notice(
			__( 'Question generation engine has not yet been connected.', 'citex-tools' ),
			'warning'
		);

		wp_safe_redirect( admin_url( 'admin.php?page=citex-generate' ) );
		exit;
	}
}
