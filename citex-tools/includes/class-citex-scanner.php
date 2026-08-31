<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Citex scanner.
 *
 * The actual scanning (fetching the WordPress question-list screens and
 * parsing titles) happens client-side in admin/js/citex-scanner.js,
 * authenticated as the logged-in administrator — this class only stores
 * the configured question-list URL and the most recent scan result so
 * the Dashboard and Questions pages can share it, and exposes the two
 * AJAX endpoints the browser-side scanner posts back to.
 *
 * Read-only: this class never reads or writes WordPress question posts
 * themselves, only its own plugin options.
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

	/**
	 * @return string The configured WordPress question-list admin URL, or ''.
	 */
	public static function get_question_list_url() {
		return get_option( self::OPTION_URL, '' );
	}

	/**
	 * @return array|null The most recent scan report, or null if none yet.
	 */
	public static function get_last_scan() {
		$scan = get_option( self::OPTION_SCAN, null );
		return is_array( $scan ) ? $scan : null;
	}

	/**
	 * Formats a scan's ISO-8601 `scannedAt` timestamp using the site's
	 * configured date/time format.
	 *
	 * @param string $iso_timestamp ISO-8601 timestamp.
	 * @return string Formatted for display.
	 */
	public static function format_scanned_at( $iso_timestamp ) {
		$time = strtotime( (string) $iso_timestamp );

		if ( ! $time ) {
			return (string) $iso_timestamp;
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * AJAX: save the administrator-entered question-list URL.
	 */
	public function ajax_save_settings() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'citex-tools' ) ), 403 );
		}

		$url = isset( $_POST['question_list_url'] ) ? esc_url_raw( wp_unslash( $_POST['question_list_url'] ) ) : '';

		update_option( self::OPTION_URL, $url, false );

		wp_send_json_success( array( 'questionListUrl' => $url ) );
	}

	/**
	 * AJAX: save a completed scan report from the browser-side scanner.
	 */
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
				'scannedAt' => $scan['scannedAt'],
				'total'     => $scan['total'],
			)
		);
	}

	/**
	 * Sanitizes a scan report received from the browser-side scanner
	 * before it is stored as a WordPress option.
	 *
	 * @param array $data Raw decoded scan payload.
	 * @return array Sanitized scan report.
	 */
	private static function sanitize_scan( $data ) {
		$questions = array();

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

			$questions[] = array(
				'original'   => sanitize_text_field( (string) ( $question['original'] ?? '' ) ),
				'source'     => sanitize_text_field( (string) ( $question['source'] ?? '' ) ),
				'group'      => sanitize_text_field( (string) ( $question['group'] ?? '' ) ),
				'category'   => sanitize_text_field( (string) ( $question['category'] ?? '' ) ),
				'type'       => sanitize_text_field( (string) ( $question['type'] ?? '' ) ),
				'questionId' => sanitize_text_field( (string) ( $question['questionId'] ?? '' ) ),
				'parts'      => $parts,
				'editUrl'    => ! empty( $question['editUrl'] ) ? esc_url_raw( (string) $question['editUrl'] ) : '',
				'wpPostId'   => isset( $question['wpPostId'] ) && is_numeric( $question['wpPostId'] ) ? absint( $question['wpPostId'] ) : null,
				// Flags records whose title had the legacy "Question title:" source
				// prefix, which the scanner strips before indexing the source. The
				// original WordPress title is untouched — see the `original` field.
				'legacySourcePrefix' => ! empty( $question['legacySourcePrefix'] ),
			);
		}

		return array(
			'scannedAt'       => sanitize_text_field( (string) ( $data['scannedAt'] ?? gmdate( 'c' ) ) ),
			'questionListUrl' => ! empty( $data['questionListUrl'] ) ? esc_url_raw( (string) $data['questionListUrl'] ) : '',
			'total'           => isset( $data['total'] ) ? absint( $data['total'] ) : count( $questions ),
			'harvardTotal'    => isset( $data['harvardTotal'] ) ? absint( $data['harvardTotal'] ) : 0,
			'questions'       => $questions,
			'breakdowns'      => array(
				'sources'      => self::sanitize_breakdown( $data, 'sources' ),
				'groups'       => self::sanitize_breakdown( $data, 'groups' ),
				'categories'   => self::sanitize_breakdown( $data, 'categories' ),
				'types'        => self::sanitize_breakdown( $data, 'types' ),
				'combinations' => self::sanitize_breakdown( $data, 'combinations' ),
			),
		);
	}

	/**
	 * @param array  $data Raw decoded scan payload.
	 * @param string $key  One of 'sources', 'groups', 'categories', 'types', 'combinations'.
	 * @return array[] Sanitized [{name, count}] rows.
	 */
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
