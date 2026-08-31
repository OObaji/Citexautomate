<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Citex dashboard: question-bank statistics overview.
 *
 * All figures below are placeholders until the record-counting module
 * (see Section 10 / architecture note in README.md) is connected.
 */
class Citex_Dashboard {

	public function render() {
		// PLACEHOLDER DATA — replace once real record counting is wired in.
		$stats = array(
			'total_questions'   => '—',
			'valid_questions'   => '—',
			'error_questions'   => '—',
			'pending_questions' => '—',
		);

		// PLACEHOLDER DATA — replace with a real question-bank breakdown.
		$overview_rows = array(
			array(
				'style'     => 'Harvard',
				'category'  => 'Book',
				'questions' => '—',
			),
			array(
				'style'     => 'Harvard',
				'category'  => 'Journal',
				'questions' => '—',
			),
			array(
				'style'     => 'Harvard',
				'category'  => 'Website',
				'questions' => '—',
			),
		);

		require CITEX_TOOLS_PATH . 'admin/views/dashboard.php';
	}
}
