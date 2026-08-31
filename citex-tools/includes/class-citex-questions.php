<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Question Bank page.
 *
 * Renders search/filter controls and the question-records table, sourced
 * from the most recent Citex_Scanner scan of the real WordPress question
 * records (see includes/class-citex-scanner.php). Read-only: filtering
 * and pagination operate on the cached scan array only, nothing here
 * reads or writes question posts directly.
 */
class Citex_Questions {

	const PER_PAGE = 20;

	public function render() {
		$scan              = Citex_Scanner::get_last_scan();
		$question_list_url = Citex_Scanner::get_question_list_url();

		$search = isset( $_GET['citex_search'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_search'] ) ) : '';

		$filters = array(
			'source'   => isset( $_GET['citex_filter_source'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_source'] ) ) : 'all',
			'category' => isset( $_GET['citex_filter_category'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_category'] ) ) : 'all',
			'type'     => isset( $_GET['citex_filter_type'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_type'] ) ) : 'all',
			'status'   => isset( $_GET['citex_filter_status'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_status'] ) ) : 'all',
		);

		$all_questions = $scan['questions'] ?? array();

		$sources    = $scan['breakdowns']['sources'] ?? array();
		$categories = $scan['breakdowns']['categories'] ?? array();
		$types      = $scan['breakdowns']['types'] ?? array();

		$filtered = self::filter_questions( $all_questions, $search, $filters );
		$total_filtered = count( $filtered );
		$total_pages    = max( 1, (int) ceil( $total_filtered / self::PER_PAGE ) );

		$paged  = isset( $_GET['citex_paged'] ) ? max( 1, absint( $_GET['citex_paged'] ) ) : 1;
		$paged  = min( $paged, $total_pages );
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$questions = array_slice( $filtered, $offset, self::PER_PAGE );

		require CITEX_TOOLS_PATH . 'admin/views/questions.php';
	}

	/**
	 * Filters the cached scan records by search text and the four filter
	 * dropdowns. Every scanned record currently has validation status
	 * 'not_validated' (no validation engine yet), so any 'valid'/'error'
	 * status filter simply yields no matches.
	 *
	 * @param array[] $questions Cached scan records.
	 * @param string  $search    Free-text search (matched against the title and question ID).
	 * @param array   $filters   source/category/type/status filter values ('all' = no filter).
	 * @return array[] Filtered records.
	 */
	private static function filter_questions( $questions, $search, $filters ) {
		$search = strtolower( $search );

		return array_values(
			array_filter(
				$questions,
				function ( $question ) use ( $search, $filters ) {
					if ( '' !== $search ) {
						$haystack = strtolower( ( $question['original'] ?? '' ) . ' ' . ( $question['questionId'] ?? '' ) );
						if ( false === strpos( $haystack, $search ) ) {
							return false;
						}
					}

					if ( 'all' !== $filters['source'] && ( $question['source'] ?? '' ) !== $filters['source'] ) {
						return false;
					}

					if ( 'all' !== $filters['category'] && ( $question['category'] ?? '' ) !== $filters['category'] ) {
						return false;
					}

					if ( 'all' !== $filters['type'] && ( $question['type'] ?? '' ) !== $filters['type'] ) {
						return false;
					}

					if ( 'all' !== $filters['status'] && 'not_validated' !== $filters['status'] ) {
						return false;
					}

					return true;
				}
			)
		);
	}
}
