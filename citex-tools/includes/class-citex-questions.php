<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Question Bank page.
 *
 * Mirrors the latest snapshot of BOTH real destinations combined — the
 * Reference List and the separate Citations post type (see
 * Citex_Scanner::target_for_group()'s own docblock) — via
 * Citex_Scanner::merge_scans(), so a Citations question gets the exact
 * same listing, filters, "Finalise" (re-run the save lifecycle) and bulk
 * status/Bin actions a Reference List question already has, rather than
 * being invisible here and only fixable by hand-opening the post. Each
 * record's native WordPress post status is shown as-is. The Refresh / Sync
 * button is deliberately handled server-side so it still works even if
 * admin JavaScript fails to initialise.
 */
class Citex_Questions {

	const PER_PAGE = 20;
	const SYNC_NONCE_ACTION = 'citex_sync_reference_list';

	/**
	 * Called on admin_init (before any output) as well as at the top of
	 * render(), so a redirect after submission always reaches the browser.
	 */
	public function maybe_handle_sync_submit() {
		if ( ! isset( $_POST['citex_sync_reference_list'] ) ) {
			return;
		}

		check_admin_referer( self::SYNC_NONCE_ACTION, 'citex_sync_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to synchronise the Question Bank.', 'citex-tools' ) );
		}

		$reference_result = Citex_Scanner::sync_from_wordpress( 'reference' );
		$citations_result  = Citex_Scanner::sync_from_wordpress( 'citations' );

		// Citations may legitimately not be configured yet on some sites;
		// that alone must never block a Reference-List-only sync from
		// succeeding and reporting its own counts.
		if ( is_wp_error( $reference_result ) ) {
			Citex_Admin::set_notice( $reference_result->get_error_message(), 'error' );
		} else {
			$counts  = $reference_result['statusCounts'] ?? array();
			$message = sprintf(
				__( 'Question Bank synced from WordPress. Reference List — All: %1$d, Published: %2$d, Drafts: %3$d, Bin: %4$d.', 'citex-tools' ),
				(int) ( $counts['all'] ?? 0 ),
				(int) ( $counts['publish'] ?? 0 ),
				(int) ( $counts['draft'] ?? 0 ),
				(int) ( $counts['trash'] ?? 0 )
			);
			if ( ! is_wp_error( $citations_result ) ) {
				$citations_counts = $citations_result['statusCounts'] ?? array();
				$message          .= ' ' . sprintf(
					__( 'Citations — All: %1$d, Published: %2$d, Drafts: %3$d, Bin: %4$d.', 'citex-tools' ),
					(int) ( $citations_counts['all'] ?? 0 ),
					(int) ( $citations_counts['publish'] ?? 0 ),
					(int) ( $citations_counts['draft'] ?? 0 ),
					(int) ( $citations_counts['trash'] ?? 0 )
				);
			}
			Citex_Admin::set_notice( $message, 'success' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=citex-questions' ) );
		exit;
	}

	public function render() {
		$this->maybe_handle_sync_submit();

		$reference_scan     = Citex_Scanner::get_last_scan( 'reference' );
		$citations_scan      = Citex_Scanner::get_last_scan( 'citations' );
		$scan               = Citex_Scanner::merge_scans( array( $reference_scan, $citations_scan ) );
		$citations_post_type = (string) ( $citations_scan['postType'] ?? '' );
		$broken_page_questions = self::find_broken_intext_page_questions( $scan['questions'] ?? array(), $citations_post_type );
		$broken_page_post_ids  = wp_list_pluck( $broken_page_questions, 'postId' );
		$question_list_url = Citex_Scanner::get_question_list_url();
		// Enables the Sync button whenever EITHER destination has a URL
		// configured — a site that has only set up Citations so far must
		// still be able to sync, not just one that has Reference List.
		$citations_list_url_configured = (bool) Citex_Scanner::get_question_list_url( 'citations' );
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

		$filtered_post_ids    = self::extract_post_ids( $filtered );
		// Every indexed post across BOTH destinations, regardless of the
		// current search/filter — this is what "Clear Question Bank" moves
		// to Bin, distinct from the existing filtered/selected bulk-status
		// editor.
		$all_indexed_post_ids = self::extract_post_ids( $all_questions );

		$paged     = isset( $_GET['citex_paged'] ) ? max( 1, absint( $_GET['citex_paged'] ) ) : 1;
		$paged     = min( $paged, $total_pages );
		$offset    = ( $paged - 1 ) * self::PER_PAGE;
		$questions = array_slice( $filtered, $offset, self::PER_PAGE );

		$wordpress_statuses = Citex_Bulk_Editor::status_choices();

		require CITEX_TOOLS_PATH . 'admin/views/questions.php';
	}

	/**
	 * Finds already-published In-Text Citation DragDrop posts whose visible
	 * scenario text never mentions the page number the student is asked to
	 * drag in — see Citex_Scanner::is_intext_dragdrop_missing_page()'s own
	 * docblock for the root cause (fixed for all newly generated questions
	 * in Citex_AI_V2::intext_dragdrop_stem(), but pre-existing published
	 * posts still carry the bug). Reads each candidate's own real, stored
	 * content via Citex_Populator's read-only shape/read helpers — never a
	 * template guess. A candidate this can't read (e.g. the field shape
	 * can't be resolved) is silently skipped rather than failing the whole
	 * page, since this is a diagnostic aid, not a required part of the
	 * listing.
	 *
	 * @param array[] $questions            Every merged, indexed question.
	 * @param string  $citations_post_type  The real Citations post type
	 *                                       slug, or '' if not configured.
	 * @return array[] {postId, editUrl, source, category, questionId, scenario, page}
	 */
	private static function find_broken_intext_page_questions( $questions, $citations_post_type ) {
		if ( '' === $citations_post_type ) {
			return array();
		}

		$candidates = array_filter(
			$questions,
			function ( $question ) {
				return 'InTextCitation' === ( $question['group'] ?? '' ) && 'DragDrop' === ( $question['type'] ?? '' ) && ! empty( $question['wpPostId'] );
			}
		);
		if ( empty( $candidates ) ) {
			return array();
		}

		$populator = new Citex_Populator();
		$shape     = $populator->resolve_dragdrop_read_shape( $citations_post_type );
		if ( is_wp_error( $shape ) ) {
			return array();
		}

		$broken = array();
		foreach ( $candidates as $question ) {
			$post_id = absint( $question['wpPostId'] );
			$content = $populator->read_dragdrop_content( $post_id, $shape );

			$is_broken = Citex_Scanner::is_intext_dragdrop_missing_page(
				$content['fixedText'] ?? '',
				$content['scenario'] ?? '',
				$content['questionParts'] ?? array()
			);
			if ( ! $is_broken ) {
				continue;
			}

			$parts = $content['questionParts'] ?? array();
			$broken[] = array(
				'postId'     => $post_id,
				'editUrl'    => $question['editUrl'] ?? get_edit_post_link( $post_id, 'raw' ),
				'source'     => $question['source'] ?? '',
				'category'   => $question['category'] ?? '',
				'questionId' => $question['questionId'] ?? '',
				'scenario'   => $content['scenario'] ?? '',
				'page'       => ! empty( $parts ) ? (string) end( $parts ) : '',
			);
		}
		return $broken;
	}

	/**
	 * Every unique, positive WordPress post ID present on a list of
	 * questions — shared by both the filtered scope and the "Clear Question
	 * Bank" (all indexed questions, regardless of filter) scope.
	 */
	private static function extract_post_ids( $questions ) {
		return array_values(
			array_unique(
				array_filter(
					array_map(
						function ( $question ) {
							return absint( $question['wpPostId'] ?? 0 );
						},
						$questions
					)
				)
			)
		);
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
