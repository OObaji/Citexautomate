<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Question Bank page.
 *
 * The table is sourced from the latest Citex scan and joined with validation
 * results. Pagination is only a display concern: the page also computes the
 * complete filtered set so Citex bulk actions can target all 200+ matching
 * questions rather than only the 20 rows currently visible.
 */
class Citex_Questions {

	const PER_PAGE = 20;

	public function render() {
		$scan              = Citex_Scanner::get_last_scan();
		$question_list_url = Citex_Scanner::get_question_list_url();
		$search            = isset( $_GET['citex_search'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_search'] ) ) : '';

		$filters = array(
			'source'   => isset( $_GET['citex_filter_source'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_source'] ) ) : 'all',
			'category' => isset( $_GET['citex_filter_category'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_category'] ) ) : 'all',
			'type'     => isset( $_GET['citex_filter_type'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_type'] ) ) : 'all',
			'status'   => isset( $_GET['citex_filter_status'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_status'] ) ) : 'all',
		);

		$all_questions = self::attach_validation( $scan['questions'] ?? array() );
		$sources        = $scan['breakdowns']['sources'] ?? array();
		$categories     = $scan['breakdowns']['categories'] ?? array();
		$types          = $scan['breakdowns']['types'] ?? array();

		$filtered       = self::filter_questions( $all_questions, $search, $filters );
		$total_filtered = count( $filtered );
		$total_pages    = max( 1, (int) ceil( $total_filtered / self::PER_PAGE ) );

		// All matching real WordPress post IDs, regardless of Citex pagination.
		// The bulk editor uses this array for "All filtered questions".
		$filtered_post_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						function ( $question ) {
							return absint( $question['wpPostId'] ?? 0 );
						},
						$filtered
					)
				)
			)
		);

		$paged  = isset( $_GET['citex_paged'] ) ? max( 1, absint( $_GET['citex_paged'] ) ) : 1;
		$paged  = min( $paged, $total_pages );
		$offset = ( $paged - 1 ) * self::PER_PAGE;
		$questions = array_slice( $filtered, $offset, self::PER_PAGE );

		$wordpress_statuses = Citex_Bulk_Editor::status_choices();

		require CITEX_TOOLS_PATH . 'admin/views/questions.php';
	}

	private static function attach_validation( $questions ) {
		$results = Citex_Validator::get_results();

		foreach ( $questions as &$question ) {
			$key                          = Citex_Validator::result_key( $question );
			$validator_id                 = Citex_Validator::resolve_validator_id( $question );
			$stored                       = $results[ $key ] ?? null;
			$question['validatorId']      = $validator_id;
			$question['validationResult'] = $stored;
			$question['validationStatus'] = Citex_Validator::effective_status( $validator_id, $stored );
			$question['validationKey']    = $key;
		}
		unset( $question );

		return $questions;
	}

	private static function filter_questions( $questions, $search, $filters ) {
		$search = strtolower( $search );

		return array_values(
			array_filter(
				$questions,
				function ( $question ) use ( $search, $filters ) {
					if ( '' !== $search ) {
						$haystack = strtolower(
							( $question['original'] ?? '' ) . ' ' .
							( $question['questionId'] ?? '' ) . ' ' .
							( $question['source'] ?? '' ) . ' ' .
							( $question['group'] ?? '' ) . ' ' .
							( $question['category'] ?? '' ) . ' ' .
							( $question['type'] ?? '' )
						);
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
					if ( 'all' !== $filters['status'] && $question['validationStatus'] !== $filters['status'] ) {
						return false;
					}

					return true;
				}
			)
		);
	}
}
