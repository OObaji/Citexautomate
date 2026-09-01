<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Question Bank page.
 *
 * Mirrors the latest Reference List snapshot, including each record's native
 * WordPress post status. The Refresh / Sync button is deliberately handled
 * server-side so it still works even if admin JavaScript fails to initialise.
 */
class Citex_Questions {

	const PER_PAGE = 20;
	const SYNC_NONCE_ACTION = 'citex_sync_reference_list';

	public function render() {
		if ( isset( $_POST['citex_sync_reference_list'] ) ) {
			check_admin_referer( self::SYNC_NONCE_ACTION, 'citex_sync_nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You are not allowed to synchronise the Reference List.', 'citex-tools' ) );
			}

			$result = Citex_Scanner::sync_from_wordpress();
			if ( is_wp_error( $result ) ) {
				Citex_Admin::set_notice( $result->get_error_message(), 'error' );
			} else {
				$counts = $result['statusCounts'] ?? array();
				Citex_Admin::set_notice(
					sprintf(
						__( 'Reference List synced from WordPress. All: %1$d, Published: %2$d, Drafts: %3$d, Bin: %4$d.', 'citex-tools' ),
						(int) ( $counts['all'] ?? 0 ),
						(int) ( $counts['publish'] ?? 0 ),
						(int) ( $counts['draft'] ?? 0 ),
						(int) ( $counts['trash'] ?? 0 )
					),
					'success'
				);
			}

			wp_safe_redirect( admin_url( 'admin.php?page=citex-questions' ) );
			exit;
		}

		$scan              = Citex_Scanner::get_last_scan();
		$question_list_url = Citex_Scanner::get_question_list_url();
		$search            = isset( $_GET['citex_search'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_search'] ) ) : '';

		$filters = array(
			'source'            => isset( $_GET['citex_filter_source'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_source'] ) ) : 'all',
			'category'          => isset( $_GET['citex_filter_category'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_category'] ) ) : 'all',
			'type'              => isset( $_GET['citex_filter_type'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_type'] ) ) : 'all',
			'validation_status' => isset( $_GET['citex_filter_status'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_status'] ) ) : 'all',
			'post_status'       => isset( $_GET['citex_filter_post_status'] ) ? sanitize_text_field( wp_unslash( $_GET['citex_filter_post_status'] ) ) : 'all',
		);

		$all_questions = self::attach_validation( $scan['questions'] ?? array() );
		$sources        = $scan['breakdowns']['sources'] ?? array();
		$categories     = $scan['breakdowns']['categories'] ?? array();
		$types          = $scan['breakdowns']['types'] ?? array();
		$post_statuses  = $scan['breakdowns']['postStatuses'] ?? array();
		$status_counts  = $scan['statusCounts'] ?? array();

		$filtered       = self::filter_questions( $all_questions, $search, $filters );
		$total_filtered = count( $filtered );
		$total_pages    = max( 1, (int) ceil( $total_filtered / self::PER_PAGE ) );

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

		$paged     = isset( $_GET['citex_paged'] ) ? max( 1, absint( $_GET['citex_paged'] ) ) : 1;
		$paged     = min( $paged, $total_pages );
		$offset    = ( $paged - 1 ) * self::PER_PAGE;
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
							( $question['type'] ?? '' ) . ' ' .
							( $question['postStatus'] ?? '' )
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
					if ( 'all' !== $filters['validation_status'] && $question['validationStatus'] !== $filters['validation_status'] ) {
						return false;
					}
					if ( 'all' !== $filters['post_status'] && ( $question['postStatus'] ?? '' ) !== $filters['post_status'] ) {
						return false;
					}
					return true;
				}
			)
		);
	}
}
