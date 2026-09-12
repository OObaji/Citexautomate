<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk editor for the real WordPress Reference List posts indexed by Citex.
 *
 * Published/Draft/Pending/Private are normal post_status changes. Moving a
 * record to Bin is deliberately handled with wp_trash_post(), matching
 * WordPress's native trash behaviour rather than pretending that "trash" is
 * just another Quick Edit status.
 */
class Citex_Bulk_Editor {

	const NONCE_ACTION       = 'citex_bulk_edit_questions';
	const AJAX_UPDATE_STATUS = 'citex_bulk_update_status';
	const MAX_BATCH          = 50;

	public function __construct() {
		add_action( 'wp_ajax_' . self::AJAX_UPDATE_STATUS, array( $this, 'ajax_update_status' ) );
	}

	public static function status_choices() {
		return array(
			'publish' => __( 'Published', 'citex-tools' ),
			'draft'   => __( 'Draft', 'citex-tools' ),
			'pending' => __( 'Pending Review', 'citex-tools' ),
			'private' => __( 'Private', 'citex-tools' ),
			'trash'   => __( 'Move to Bin (Trash)', 'citex-tools' ),
		);
	}

	/**
	 * Update the real Reference List records for one Citex batch.
	 *
	 * Normal statuses use wp_update_post(). Trash uses wp_trash_post(), which
	 * preserves WordPress's trash metadata and makes the record restorable from
	 * Reference List > Bin. Nothing is permanently deleted here.
	 */
	public function ajax_update_status() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please refresh the page and try again.', 'citex-tools' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to bulk edit questions.', 'citex-tools' ) ), 403 );
		}

		try {
			$this->update_status_body();
		} catch ( Throwable $e ) {
			error_log( '[Citex Tools] ajax_update_status failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => sprintf( __( 'Citex: the bulk update failed — %s.', 'citex-tools' ), $e->getMessage() ) ), 500 );
		}
	}

	private function update_status_body() {
		$status  = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$choices = self::status_choices();
		if ( ! isset( $choices[ $status ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid WordPress status.', 'citex-tools' ) ), 400 );
		}

		$raw_ids = isset( $_POST['post_ids'] ) ? wp_unslash( $_POST['post_ids'] ) : '[]';
		$ids     = json_decode( $raw_ids, true );
		if ( ! is_array( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid question ID list.', 'citex-tools' ) ), 400 );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No question posts were supplied.', 'citex-tools' ) ), 400 );
		}
		if ( count( $ids ) > self::MAX_BATCH ) {
			wp_send_json_error( array( 'message' => __( 'Too many posts in one batch.', 'citex-tools' ) ), 400 );
		}

		$scan        = Citex_Scanner::get_last_scan();
		$indexed_ids = array();
		foreach ( ( $scan['questions'] ?? array() ) as $question ) {
			$id = absint( $question['wpPostId'] ?? 0 );
			if ( $id ) {
				$indexed_ids[ $id ] = true;
			}
		}

		$updated  = 0;
		$skipped  = 0;
		$failed   = array();
		$verified = array();

		foreach ( $ids as $post_id ) {
			if ( ! isset( $indexed_ids[ $post_id ] ) ) {
				$failed[] = array( 'postId' => $post_id, 'reason' => 'not_indexed' );
				continue;
			}
			if ( ! current_user_can( 'delete_post', $post_id ) && 'trash' === $status ) {
				$failed[] = array( 'postId' => $post_id, 'reason' => 'no_delete_permission' );
				continue;
			}
			if ( 'trash' !== $status && ! current_user_can( 'edit_post', $post_id ) ) {
				$failed[] = array( 'postId' => $post_id, 'reason' => 'no_edit_permission' );
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				$failed[] = array( 'postId' => $post_id, 'reason' => 'not_found' );
				continue;
			}

			$before = $post->post_status;
			if ( $status === $before ) {
				$skipped++;
				continue;
			}

			if ( 'trash' === $status ) {
				$result = wp_trash_post( $post_id );
				if ( ! $result ) {
					$failed[] = array(
						'postId'   => $post_id,
						'postType' => $post->post_type,
						'before'   => $before,
						'reason'   => 'trash_failed',
					);
					continue;
				}
			} else {
				if ( 'trash' === $before ) {
					$failed[] = array( 'postId' => $post_id, 'reason' => 'already_trashed_use_restore', 'postType' => $post->post_type );
					continue;
				}

				$result = wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => $status,
					),
					true
				);

				if ( is_wp_error( $result ) ) {
					$failed[] = array(
						'postId'   => $post_id,
						'postType' => $post->post_type,
						'before'   => $before,
						'reason'   => $result->get_error_message(),
					);
					continue;
				}
			}

			clean_post_cache( $post_id );
			$after = get_post_status( $post_id );

			if ( $status !== $after ) {
				$failed[] = array(
					'postId'   => $post_id,
					'postType' => $post->post_type,
					'before'   => $before,
					'after'    => $after,
					'reason'   => 'status_not_persisted',
				);
				continue;
			}

			$updated++;
			if ( count( $verified ) < 5 ) {
				$verified[] = array(
					'postId'   => $post_id,
					'postType' => $post->post_type,
					'before'   => $before,
					'after'    => $after,
				);
			}
		}

		wp_send_json_success(
			array(
				'updated'  => $updated,
				'skipped'  => $skipped,
				'failed'   => $failed,
				'verified' => $verified,
				'status'   => $status,
				'label'    => $choices[ $status ],
			)
		);
	}
}
