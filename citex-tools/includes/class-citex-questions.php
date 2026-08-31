<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Question Bank page.
 *
 * Renders search/filter controls and the question-records table. The
 * real question store (backed by WordPress records) is a future module
 * — get_demo_questions() below is the ONLY place demo data lives, so it
 * can be deleted wholesale once real records are connected.
 */
class Citex_Questions {

	public function render() {
		$search = isset( $_GET['citex_search'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_search'] ) ) : '';

		$filters = array(
			'style'    => isset( $_GET['citex_filter_style'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_style'] ) ) : 'all',
			'category' => isset( $_GET['citex_filter_category'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_category'] ) ) : 'all',
			'type'     => isset( $_GET['citex_filter_type'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_type'] ) ) : 'all',
			'status'   => isset( $_GET['citex_filter_status'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_status'] ) ) : 'all',
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

		$statuses = array(
			'valid'         => 'Valid',
			'error'         => 'Error',
			'not_validated' => 'Not Validated',
		);

		// DEMO DATA — see get_demo_questions() below.
		$questions = self::get_demo_questions();

		require CITEX_TOOLS_PATH . 'admin/views/questions.php';
	}

	/**
	 * DEMO DATA ONLY, for visually testing the layout. Delete this
	 * method (and the call to it above) and replace with a real query
	 * once questions are stored via the populate module.
	 *
	 * @return array[] Demo question records.
	 */
	private static function get_demo_questions() {
		return array(
			array(
				'id'       => 'BK001',
				'title'    => 'Referencing a single-author book',
				'style'    => 'Harvard',
				'category' => 'Book',
				'type'     => 'Multiple Choice',
				'status'   => 'valid',
			),
			array(
				'id'       => 'BK002',
				'title'    => 'Identify the error in a book chapter reference',
				'style'    => 'Harvard',
				'category' => 'Book Chapter',
				'type'     => 'Identify the Error',
				'status'   => 'error',
			),
			array(
				'id'       => 'JR001',
				'title'    => 'Correcting a journal article citation',
				'style'    => 'Harvard',
				'category' => 'Journal Article',
				'type'     => 'Correct Reference',
				'status'   => 'valid',
			),
			array(
				'id'       => 'WB001',
				'title'    => 'Missing element in a website reference',
				'style'    => 'Harvard',
				'category' => 'Website',
				'type'     => 'Missing Element',
				'status'   => 'not_validated',
			),
		);
	}
}
