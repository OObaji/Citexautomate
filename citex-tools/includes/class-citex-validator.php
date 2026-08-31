<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Question Validation.
 *
 * Architecture (per the Phase 3 brief):
 *
 *   WordPress Questions -> Citex_Scanner -> Question Index -> Citex_Validator -> Validation Results
 *
 * The scanner (includes/class-citex-scanner.php) remains solely responsible
 * for discovering records. This class routes each indexed question to a
 * validator id (or null = unsupported), renders the Validation page, and
 * persists validation results. The actual fetch-the-edit-page / parse /
 * run-the-rules work happens client-side in admin/js/citex-validator.js —
 * same pattern as the Phase 2 scanner, and the same pattern the original
 * DevTools QA Checker used — this class never fetches or writes a
 * WordPress post itself. Read-only: no post is ever created, updated, or
 * submitted.
 */
class Citex_Validator {

	const OPTION_RESULTS = 'citex_validation_results';
	const NONCE_ACTION    = 'citex_validator';
	const AJAX_SAVE_RESULT = 'citex_save_validation_result';

	/**
	 * The only statuses a stored validation result may carry.
	 */
	const STATUSES = array( 'passed', 'failed', 'warning', 'unsupported' );

	public function __construct() {
		add_action( 'wp_ajax_' . self::AJAX_SAVE_RESULT, array( $this, 'ajax_save_result' ) );
	}

	public function render() {
		$scan      = Citex_Scanner::get_last_scan();
		$questions = $scan['questions'] ?? array();
		$results   = self::get_results();

		$rows = array();
		foreach ( $questions as $question ) {
			$key          = self::result_key( $question );
			$validator_id = self::resolve_validator_id( $question );
			$stored       = $results[ $key ] ?? null;

			$rows[] = array(
				'question'     => $question,
				'key'          => $key,
				'validatorId'  => $validator_id,
				'result'       => $stored,
				'status'       => self::effective_status( $validator_id, $stored ),
			);
		}

		$summary = self::compute_summary( $rows );

		require CITEX_TOOLS_PATH . 'admin/views/validation.php';
	}

	/**
	 * Routes a scanned question to a validator id based on its parsed
	 * title metadata, or null when nothing supports that combination yet.
	 * This is the single place new validator combinations get added later
	 * (Book MCQ, EditedBook, Website, Journal, JournalArticle, ...) without
	 * touching the Validation page.
	 *
	 * @param array $question Scanned/parsed question record.
	 * @return string|null Validator id, or null (unsupported).
	 */
	public static function resolve_validator_id( $question ) {
		$routes = Citex_Harvard_Book_Dragdrop_Validator::ROUTES;

		if (
			self::normalize_route_value( $question['source'] ?? '' ) === self::normalize_route_value( $routes['source'] ) &&
			self::normalize_route_value( $question['group'] ?? '' ) === self::normalize_route_value( $routes['group'] ) &&
			self::normalize_route_value( $question['category'] ?? '' ) === self::normalize_route_value( $routes['category'] ) &&
			self::normalize_route_value( $question['type'] ?? '' ) === self::normalize_route_value( $routes['type'] )
		) {
			return Citex_Harvard_Book_Dragdrop_Validator::ID;
		}

		return null;
	}

	/**
	 * Normalizes a value for the routing comparison only: collapses any
	 * run of whitespace (including a non-breaking space, which a scraped
	 * admin-list page can leave behind) to a single space, trims, and
	 * lowercases. This never touches what's stored or displayed —
	 * category/type keep their original scanned casing everywhere else
	 * (the brief says never rename/consolidate those values; this only
	 * makes the *comparison* tolerant of a whitespace/case difference
	 * that would otherwise silently route a real, supported question to
	 * "unsupported").
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function normalize_route_value( $value ) {
		$value = str_replace( "\xc2\xa0", ' ', (string) $value );
		$value = preg_replace( '/\s+/', ' ', $value );
		return strtolower( trim( $value ) );
	}

	/**
	 * The status to display for a question: 'unsupported' when no
	 * validator is routed, 'not_validated' when one is routed but has
	 * never been run, otherwise the stored result's own status.
	 *
	 * @param string|null $validator_id
	 * @param array|null  $stored
	 * @return string
	 */
	public static function effective_status( $validator_id, $stored ) {
		if ( null === $validator_id ) {
			return 'unsupported';
		}

		if ( ! $stored ) {
			return 'not_validated';
		}

		return in_array( $stored['status'], self::STATUSES, true ) ? $stored['status'] : 'unsupported';
	}

	/**
	 * Stable key used to store/retrieve a question's validation result,
	 * independent of scan order. Prefers the parsed question ID (always
	 * present for well-formed titles); falls back to the WordPress post ID,
	 * then to a hash of the full title so no record is ever unkeyable.
	 *
	 * @param array $question
	 * @return string
	 */
	public static function result_key( $question ) {
		if ( ! empty( $question['questionId'] ) ) {
			return 'q_' . sanitize_key( $question['questionId'] );
		}

		if ( ! empty( $question['wpPostId'] ) ) {
			return 'post_' . absint( $question['wpPostId'] );
		}

		return 'h_' . md5( (string) ( $question['original'] ?? '' ) );
	}

	/**
	 * @return array<string,array> Stored validation results keyed by result_key().
	 */
	public static function get_results() {
		$results = get_option( self::OPTION_RESULTS, array() );
		return is_array( $results ) ? $results : array();
	}

	/**
	 * @param string $key
	 * @return array|null
	 */
	public static function get_result( $key ) {
		$results = self::get_results();
		return $results[ $key ] ?? null;
	}

	/**
	 * Scanned + Passed + Failed + Warnings + Unsupported counts for the
	 * Validation page's summary cards.
	 *
	 * @param array[] $rows Rows built in render(): {question, key, validatorId, result, status}.
	 * @return array
	 */
	public static function compute_summary( $rows ) {
		$summary = array(
			'scanned'     => count( $rows ),
			'passed'      => 0,
			'failed'      => 0,
			'warnings'    => 0,
			'unsupported' => 0,
		);

		foreach ( $rows as $row ) {
			switch ( $row['status'] ) {
				case 'passed':
					$summary['passed']++;
					break;
				case 'failed':
					$summary['failed']++;
					break;
				case 'warning':
					$summary['warnings']++;
					break;
				case 'unsupported':
					$summary['unsupported']++;
					break;
				// 'not_validated' isn't one of the five summary cards.
			}
		}

		return $summary;
	}

	/**
	 * Builds the question list handed to admin/js/citex-validator.js via
	 * wp_localize_script — everything the browser-side validator needs to
	 * fetch, route, and (re)validate a question without a further AJAX
	 * round trip: its result_key(), its routed validator id (or null =
	 * unsupported), and its scan metadata (title, source/group/category/
	 * type, edit URL, WordPress post ID).
	 *
	 * @return array[] One entry per indexed question.
	 */
	public static function build_client_queue() {
		$scan  = Citex_Scanner::get_last_scan();
		$queue = array();

		foreach ( ( $scan['questions'] ?? array() ) as $question ) {
			$queue[] = array(
				'key'         => self::result_key( $question ),
				'questionId'  => $question['questionId'] ?? '',
				'title'       => $question['original'] ?? '',
				'source'      => $question['source'] ?? '',
				'group'       => $question['group'] ?? '',
				'category'    => $question['category'] ?? '',
				'type'        => $question['type'] ?? '',
				'editUrl'     => $question['editUrl'] ?? '',
				'wpPostId'    => $question['wpPostId'] ?? null,
				'validatorId' => self::resolve_validator_id( $question ),
			);
		}

		return $queue;
	}

	/**
	 * AJAX: persist one validation result computed client-side by
	 * admin/js/citex-validator.js (which fetched the question's WordPress
	 * edit screen and ran the routed validator against it — this method
	 * never fetches or writes a WordPress post). The declared validator id
	 * is not trusted blindly: it's recomputed here from the current scan
	 * index, and the status is forced to 'unsupported' if they disagree,
	 * so a stale or tampered client payload can't record a false pass/fail.
	 */
	public function ajax_save_result() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'citex-tools' ) ), 403 );
		}

		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		$raw = isset( $_POST['result'] ) ? wp_unslash( $_POST['result'] ) : '';
		$data = json_decode( $raw, true );

		if ( '' === $key || ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid validation result.', 'citex-tools' ) ), 400 );
		}

		$scan     = Citex_Scanner::get_last_scan();
		$question = null;

		foreach ( ( $scan['questions'] ?? array() ) as $candidate ) {
			if ( self::result_key( $candidate ) === $key ) {
				$question = $candidate;
				break;
			}
		}

		$expected_validator_id = $question ? self::resolve_validator_id( $question ) : null;

		$status = isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ? $data['status'] : 'unsupported';
		if ( null === $expected_validator_id ) {
			$status = 'unsupported';
		}

		$result = array(
			'questionId'  => sanitize_text_field( (string) ( $data['questionId'] ?? ( $question['questionId'] ?? '' ) ) ),
			'wpPostId'    => isset( $data['wpPostId'] ) && is_numeric( $data['wpPostId'] ) ? absint( $data['wpPostId'] ) : null,
			'title'       => sanitize_text_field( (string) ( $data['title'] ?? ( $question['original'] ?? '' ) ) ),
			'validator'   => $expected_validator_id ? sanitize_key( $expected_validator_id ) : '',
			'status'      => $status,
			'reason'      => sanitize_text_field( (string) ( $data['reason'] ?? '' ) ),
			'errors'      => self::sanitize_issue_list( $data['errors'] ?? array() ),
			'warnings'    => self::sanitize_issue_list( $data['warnings'] ?? array() ),
			'validatedAt' => sanitize_text_field( (string) ( $data['validatedAt'] ?? gmdate( 'c' ) ) ),
		);

		// Revalidation: this simply overwrites the previous entry for the
		// same key, so the stored result is always the most recent run.
		$results          = self::get_results();
		$results[ $key ]  = $result;
		update_option( self::OPTION_RESULTS, $results, false );

		wp_send_json_success(
			array(
				'key'    => $key,
				'result' => $result,
			)
		);
	}

	/**
	 * @param mixed $list Raw errors/warnings array from the client payload.
	 * @return array[] Sanitized [{code, message, field}] entries.
	 */
	private static function sanitize_issue_list( $list ) {
		$out = array();

		if ( ! is_array( $list ) ) {
			return $out;
		}

		foreach ( $list as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$out[] = array(
				'code'    => self::sanitize_error_code( $item['code'] ?? '' ),
				'message' => sanitize_text_field( (string) ( $item['message'] ?? '' ) ),
				'field'   => sanitize_text_field( (string) ( $item['field'] ?? '' ) ),
			);
		}

		return $out;
	}

	/**
	 * Error/warning codes are stable identifiers like YEAR_TRAILING_PERIOD
	 * — sanitize_key() would lowercase them, which doesn't match the
	 * brief's own examples, so this keeps case while still stripping
	 * anything that isn't a letter, digit, or underscore.
	 *
	 * @param mixed $code
	 * @return string
	 */
	private static function sanitize_error_code( $code ) {
		return preg_replace( '/[^A-Za-z0-9_]/', '', (string) $code );
	}
}
