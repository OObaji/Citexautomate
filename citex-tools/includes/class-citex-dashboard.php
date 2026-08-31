<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Citex dashboard: question-bank statistics overview.
 *
 * Total/Harvard question counts and the breakdown tables now come from
 * the most recent Citex_Scanner scan (see includes/class-citex-scanner.php).
 * Valid/Error/Pending counts remain placeholders until the validation
 * and generation modules are connected.
 */
class Citex_Dashboard {

	public function render() {
		$scan               = Citex_Scanner::get_last_scan();
		$question_list_url  = Citex_Scanner::get_question_list_url();

		$stats = array(
			'total_questions'   => $scan ? number_format_i18n( $scan['total'] ) : '—',
			'harvard_questions' => $scan ? number_format_i18n( $scan['harvardTotal'] ) : '—',
			// PLACEHOLDER DATA — replace once the validation/generation modules are connected.
			'valid_questions'   => '—',
			'error_questions'   => '—',
			'pending_questions' => '—',
		);

		$last_scanned = ( $scan && ! empty( $scan['scannedAt'] ) )
			? Citex_Scanner::format_scanned_at( $scan['scannedAt'] )
			: null;

		$breakdowns = $scan ? $scan['breakdowns'] : null;

		require CITEX_TOOLS_PATH . 'admin/views/dashboard.php';
	}
}
