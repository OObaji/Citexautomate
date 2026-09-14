<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Citex dashboard: question-bank statistics overview.
 *
 * Total/Harvard question counts and the breakdown tables reflect BOTH real
 * destinations combined — the Reference List and the separate Citations
 * post type (see Citex_Scanner::target_for_group()'s own docblock) — via
 * Citex_Scanner::merge_scans(), never the Reference List's own scan alone
 * (which silently excluded every Citations/In-Text Citation question from
 * every dashboard total before this). Valid/Error counts come from the
 * most recent Citex_Validator results, likewise computed across the
 * combined question list. Generated/Pending comes from the Citex
 * generator's WordPress-native pending store and does not imply anything
 * has been published to the real question bank.
 */
class Citex_Dashboard {

	public function render() {
		$question_list_url = Citex_Scanner::get_question_list_url();

		// The Citations post type is a genuinely separate real WordPress
		// list from the Reference List — its own independently configured
		// URL/last-scan; In-Text Citation questions populate there
		// instead. Surfaced both on its own dedicated panel below AND
		// merged into every main stat/breakdown above via merge_scans(),
		// so neither the Reference List nor Citations is ever silently
		// left out of "Total Questions" and the rest.
		$citations_list_url = Citex_Scanner::get_question_list_url( 'citations' );
		$reference_scan      = Citex_Scanner::get_last_scan( 'reference' );
		$citations_scan       = Citex_Scanner::get_last_scan( 'citations' );
		$citations_last_scanned = ( $citations_scan && ! empty( $citations_scan['scannedAt'] ) )
			? Citex_Scanner::format_scanned_at( $citations_scan['scannedAt'] )
			: null;
		$citations_total = $citations_scan ? number_format_i18n( $citations_scan['total'] ) : '—';

		$scan = Citex_Scanner::merge_scans( array( $reference_scan, $citations_scan ) );

		$validation_summary = self::compute_validation_summary( $scan['questions'] ?? array() );

		$stats = array(
			'total_questions'   => $scan ? number_format_i18n( $scan['total'] ) : '—',
			'harvard_questions' => $scan ? number_format_i18n( $scan['harvardTotal'] ) : '—',
			'valid_questions'   => $scan ? number_format_i18n( $validation_summary['passed'] ) : '—',
			'error_questions'   => $scan ? number_format_i18n( $validation_summary['failed'] ) : '—',
			'pending_questions' => number_format_i18n( Citex_Generator::get_pending_count() ),
		);

		// $last_scanned drives the Reference List panel's own "Last
		// scanned"/"Refresh vs Scan" wording specifically (see
		// admin/views/dashboard.php) — the REFERENCE scan's own
		// scannedAt, never the combined $scan's (which would wrongly
		// claim the Reference List had been scanned whenever only
		// Citations had).
		$last_scanned = ( $reference_scan && ! empty( $reference_scan['scannedAt'] ) )
			? Citex_Scanner::format_scanned_at( $reference_scan['scannedAt'] )
			: null;

		$breakdowns = $scan ? $scan['breakdowns'] : null;

		require CITEX_TOOLS_PATH . 'admin/views/dashboard.php';
	}

	/**
	 * @param array[] $questions Scanned questions from the last scan.
	 * @return array {passed, failed} counts using each question's current
	 *               effective validation status.
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
