<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Citex scanner storage/service.
 *
 * v0.8.1 adds a direct WordPress-database sync for the Reference List. The
 * configured Reference List URL is used only to identify the custom post type;
 * the actual records, post statuses and counts are then read from WordPress
 * itself rather than depending on a browser DOM scan.
 */
class Citex_Scanner {

	const OPTION_URL   = 'citex_question_list_url';
	const OPTION_SCAN  = 'citex_last_scan';
	const NONCE_ACTION = 'citex_scanner';

	const AJAX_SAVE_SETTINGS = 'citex_save_scanner_settings';
	const AJAX_SAVE_SCAN     = 'citex_save_scan_result';

	public function __construct() {
		add_action( 'wp_ajax_' . self::AJAX_SAVE_SETTINGS, array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_' . self::AJAX_SAVE_SCAN, array( $this, 'ajax_save_scan' ) );
	}

	public static function get_question_list_url() {
		return get_option( self::OPTION_URL, '' );
	}

	public static function get_last_scan() {
		$scan = get_option( self::OPTION_SCAN, null );
		return is_array( $scan ) ? $scan : null;
	}

	public static function format_scanned_at( $iso_timestamp ) {
		$time = strtotime( (string) $iso_timestamp );
		if ( ! $time ) {
			return (string) $iso_timestamp;
		}
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * Read the real Reference List directly from WordPress and persist a fresh
	 * Citex snapshot. Trash/Bin is counted separately, matching WordPress's
	 * native "All" tab behaviour.
	 *
	 * @return array|WP_Error
	 */
	public static function sync_from_wordpress() {
		$url = self::get_question_list_url();
		if ( ! $url ) {
			return new WP_Error( 'citex_no_reference_url', __( 'Reference List URL is not configured.', 'citex-tools' ) );
		}

		$post_type = self::post_type_from_url( $url );
		if ( ! $post_type || ! post_type_exists( $post_type ) ) {
			return new WP_Error( 'citex_bad_post_type', __( 'Citex could not determine the Reference List post type from the configured URL.', 'citex-tools' ) );
		}

		$statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => $statuses,
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'suppress_filters'       => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$status_counts = array(
			'all'     => 0,
			'publish' => 0,
			'draft'   => 0,
			'pending' => 0,
			'private' => 0,
			'future'  => 0,
			'trash'   => 0,
		);
		$questions = array();

		foreach ( $posts as $post ) {
			$status = sanitize_key( $post->post_status );
			if ( isset( $status_counts[ $status ] ) ) {
				$status_counts[ $status ]++;
			}

			if ( 'trash' === $status ) {
				continue;
			}

			$status_counts['all']++;
			$parsed = self::parse_title( get_the_title( $post ) );

			$questions[] = array(
				'original'           => $parsed['original'],
				'source'             => $parsed['source'],
				'group'              => $parsed['group'],
				'category'           => $parsed['category'],
				'type'               => $parsed['type'],
				'questionId'         => $parsed['questionId'],
				'parts'              => $parsed['parts'],
				'editUrl'            => get_edit_post_link( $post->ID, 'raw' ),
				'wpPostId'           => (int) $post->ID,
				'postStatus'         => $status,
				'legacySourcePrefix' => $parsed['legacySourcePrefix'],
			);
		}

		$harvard = array_filter(
			$questions,
			function ( $question ) {
				return false !== stripos( (string) ( $question['source'] ?? '' ), 'harvard' );
			}
		);

		$scan = array(
			'scannedAt'       => gmdate( 'c' ),
			'questionListUrl' => esc_url_raw( $url ),
			'postType'        => sanitize_key( $post_type ),
			'total'           => count( $questions ),
			'harvardTotal'    => count( $harvard ),
			'statusCounts'    => $status_counts,
			'questions'       => $questions,
			'breakdowns'      => array(
				'sources'      => self::count_by( $questions, 'source' ),
				'groups'       => self::count_by( $questions, 'group' ),
				'categories'   => self::count_by( $questions, 'category' ),
				'types'        => self::count_by( $questions, 'type' ),
				'postStatuses' => self::count_by( $questions, 'postStatus' ),
				'combinations' => self::count_combinations( $questions ),
			),
		);

		update_option( self::OPTION_SCAN, $scan, false );
		return $scan;
	}

	private static function post_type_from_url( $url ) {
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! $query ) {
			return '';
		}
		$params = array();
		parse_str( $query, $params );
		return isset( $params['post_type'] ) ? sanitize_key( $params['post_type'] ) : '';
	}

	private static function parse_title( $title ) {
		$original = sanitize_text_field( (string) $title );
		$parts = array_values( array_filter( array_map( 'trim', explode( '|', $original ) ), 'strlen' ) );
		$source = isset( $parts[0] ) ? $parts[0] : '';
		$legacy = (bool) preg_match( '/^question\s+title\s*:\s*/i', $source );
		$source = preg_replace( '/^question\s+title\s*:\s*/i', '', $source );

		return array(
			'original'           => $original,
			'source'             => sanitize_text_field( trim( $source ) ),
			'group'              => sanitize_text_field( $parts[1] ?? '' ),
			'category'           => sanitize_text_field( $parts[2] ?? '' ),
			'type'               => sanitize_text_field( $parts[3] ?? '' ),
			'questionId'         => sanitize_text_field( $parts[4] ?? '' ),
			'parts'              => array_map( 'sanitize_text_field', $parts ),
			'legacySourcePrefix' => $legacy,
		);
	}

	private static function count_by( $questions, $key ) {
		$counts = array();
		foreach ( $questions as $question ) {
			$name = (string) ( $question[ $key ] ?? '' );
			if ( '' === $name ) {
				$name = '(blank)';
			}
			$counts[ $name ] = isset( $counts[ $name ] ) ? $counts[ $name ] + 1 : 1;
		}
		arsort( $counts );
		$rows = array();
		foreach ( $counts as $name => $count ) {
			$rows[] = array( 'name' => $name, 'count' => $count );
		}
		return $rows;
	}

	private static function count_combinations( $questions ) {
		$counts = array();
		foreach ( $questions as $question ) {
			$pieces = array_filter(
				array(
					(string) ( $question['source'] ?? '' ),
					(string) ( $question['group'] ?? '' ),
					(string) ( $question['category'] ?? '' ),
					(string) ( $question['type'] ?? '' ),
				),
				'strlen'
			);
			$name = implode( ' | ', $pieces );
			if ( '' === $name ) {
				$name = '(blank)';
			}
			$counts[ $name ] = isset( $counts[ $name ] ) ? $counts[ $name ] + 1 : 1;
		}
		arsort( $counts );
		$rows = array();
		foreach ( $counts as $name => $count ) {
			$rows[] = array( 'name' => $name, 'count' => $count );
		}
		return $rows;
	}

	public function ajax_save_settings() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'citex-tools' ) ), 403 );
		}
		$url = isset( $_POST['question_list_url'] ) ? esc_url_raw( wp_unslash( $_POST['question_list_url'] ) ) : '';
		update_option( self::OPTION_URL, $url, false );
		wp_send_json_success( array( 'questionListUrl' => $url ) );
	}

	public function ajax_save_scan() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'citex-tools' ) ), 403 );
		}

		$raw  = isset( $_POST['scan'] ) ? wp_unslash( $_POST['scan'] ) : '';
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['questions'] ) || ! is_array( $data['questions'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid scan data.', 'citex-tools' ) ), 400 );
		}

		$scan = self::sanitize_scan( $data );
		update_option( self::OPTION_SCAN, $scan, false );
		wp_send_json_success(
			array(
				'scannedAt'    => $scan['scannedAt'],
				'total'        => $scan['total'],
				'statusCounts' => $scan['statusCounts'],
			)
		);
	}

	private static function sanitize_scan( $data ) {
		$questions = array();
		$allowed_statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );

		foreach ( $data['questions'] as $question ) {
			if ( ! is_array( $question ) ) {
				continue;
			}
			$parts = array();
			if ( ! empty( $question['parts'] ) && is_array( $question['parts'] ) ) {
				foreach ( $question['parts'] as $part ) {
					$parts[] = sanitize_text_field( (string) $part );
				}
			}

			$post_status = sanitize_key( (string) ( $question['postStatus'] ?? '' ) );
			if ( ! in_array( $post_status, $allowed_statuses, true ) ) {
				$post_status = '';
			}

			$questions[] = array(
				'original'           => sanitize_text_field( (string) ( $question['original'] ?? '' ) ),
				'source'             => sanitize_text_field( (string) ( $question['source'] ?? '' ) ),
				'group'              => sanitize_text_field( (string) ( $question['group'] ?? '' ) ),
				'category'           => sanitize_text_field( (string) ( $question['category'] ?? '' ) ),
				'type'               => sanitize_text_field( (string) ( $question['type'] ?? '' ) ),
				'questionId'         => sanitize_text_field( (string) ( $question['questionId'] ?? '' ) ),
				'parts'              => $parts,
				'editUrl'            => ! empty( $question['editUrl'] ) ? esc_url_raw( (string) $question['editUrl'] ) : '',
				'wpPostId'           => isset( $question['wpPostId'] ) && is_numeric( $question['wpPostId'] ) ? absint( $question['wpPostId'] ) : null,
				'postStatus'         => $post_status,
				'legacySourcePrefix' => ! empty( $question['legacySourcePrefix'] ),
			);
		}

		$status_counts = array();
		if ( ! empty( $data['statusCounts'] ) && is_array( $data['statusCounts'] ) ) {
			foreach ( $data['statusCounts'] as $status => $count ) {
				$status = sanitize_key( (string) $status );
				if ( 'all' === $status || in_array( $status, $allowed_statuses, true ) ) {
					$status_counts[ $status ] = absint( $count );
				}
			}
		}

		return array(
			'scannedAt'       => sanitize_text_field( (string) ( $data['scannedAt'] ?? gmdate( 'c' ) ) ),
			'questionListUrl' => ! empty( $data['questionListUrl'] ) ? esc_url_raw( (string) $data['questionListUrl'] ) : '',
			'total'           => isset( $data['total'] ) ? absint( $data['total'] ) : count( $questions ),
			'harvardTotal'    => isset( $data['harvardTotal'] ) ? absint( $data['harvardTotal'] ) : 0,
			'statusCounts'    => $status_counts,
			'questions'       => $questions,
			'breakdowns'      => array(
				'sources'      => self::sanitize_breakdown( $data, 'sources' ),
				'groups'       => self::sanitize_breakdown( $data, 'groups' ),
				'categories'   => self::sanitize_breakdown( $data, 'categories' ),
				'types'        => self::sanitize_breakdown( $data, 'types' ),
				'postStatuses' => self::sanitize_breakdown( $data, 'postStatuses' ),
				'combinations' => self::sanitize_breakdown( $data, 'combinations' ),
			),
		);
	}

	private static function sanitize_breakdown( $data, $key ) {
		$rows = array();
		if ( empty( $data['breakdowns'][ $key ] ) || ! is_array( $data['breakdowns'][ $key ] ) ) {
			return $rows;
		}
		foreach ( $data['breakdowns'][ $key ] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'name'  => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'count' => isset( $row['count'] ) ? absint( $row['count'] ) : 0,
			);
		}
		return $rows;
	}
}
