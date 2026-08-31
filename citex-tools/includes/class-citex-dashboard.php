<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Citex dashboard: question-bank statistics overview.
 *
 * Total/Harvard question counts and the breakdown tables come from the
 * most recent Citex_Scanner scan (see includes/class-citex-scanner.php).
 * Valid/Error counts come from the most recent Citex_Validator results
 * (see includes/class-citex-validator.php): Valid = questions with a
 * stored 'passed' result, Questions With Errors = 'failed'. Unsupported
 * and not-yet-validated questions are deliberately excluded from both —
 * counting either as "valid" would misrepresent the real validation
 * state. Generated/Pending remains a placeholder until the generation
 * module is connected.
 */
class Citex_Dashboard {

	public function render() {
		$scan               = Citex_Scanner::get_last_scan();
		$question_list_url  = Citex_Scanner::get_question_list_url();

		$validation_summary = self::compute_validation_summary( $scan['questions'] ?? array() );

		$stats = array(
			'total_questions'   => $scan ? number_format_i18n( $scan['total'] ) : '—',
			'harvard_questions' => $scan ? number_format_i18n( $scan['harvardTotal'] ) : '—',
			'valid_questions'   => $scan ? number_format_i18n( $validation_summary['passed'] ) : '—',
			'error_questions'   => $scan ? number_format_i18n( $validation_summary['failed'] ) : '—',
			// PLACEHOLDER DATA — replace once the generation module is connected.
			'pending_questions' => '—',
		);

		$last_scanned = ( $scan && ! empty( $scan['scannedAt'] ) )
			? Citex_Scanner::format_scanned_at( $scan['scannedAt'] )
			: null;

		$breakdowns = $scan ? $scan['breakdowns'] : null;

		require CITEX_TOOLS_PATH . 'admin/views/dashboard.php';
	}

	/**
	 * @param array[] $questions Scanned questions from the last scan.
	 * @return array {passed, failed} counts using each question's current
	 *               effective validation status (Citex_Validator::effective_status()).
	 */
	private static function compute_validation_summary( $questions ) {
		$results = Citex_Validator::get_results();
		$summary = array( 'passed' => 0, 'failed' => 0 );

		foreach ( $questions as $question ) {
			$key          = Citex_Validator::result_key( $question );
			$validator_id = Citex_Validator::resolve_validator_id( $question );
			$stored       = $results[ $key ] ?? null;
			$status       = Citex_Validator::effective_status( $validator_id, $stored );

			if ( 'passed' === $status ) {
				$summary['passed']++;
			} elseif ( 'failed' === $status ) {
				$summary['failed']++;
			}
		}

		return $summary;
	}
}
