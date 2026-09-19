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

	// Citations is a genuinely separate real WordPress post type/list from
	// the Reference List (confirmed live: the site's admin sidebar shows
	// "Reference List" and "Citations" as two distinct top-level CPT
	// screens) — In-Text Citation questions must populate there, never
	// into the Reference List. This is its own independently configured
	// URL/scan, mirroring the Reference List's own OPTION_URL/OPTION_SCAN
	// exactly, selected via the `$target` parameter threaded through every
	// method below (default 'reference' so every existing call site keeps
	// working unchanged).
	const OPTION_CITATIONS_URL  = 'citex_citations_list_url';
	const OPTION_CITATIONS_SCAN = 'citex_last_scan_citations';

	const AJAX_SAVE_SETTINGS      = 'citex_save_scanner_settings';
	const AJAX_SAVE_SCAN          = 'citex_save_scan_result';
	const AJAX_DETECT_CITATIONS   = 'citex_detect_citations_post_type';

	public function __construct() {
		add_action( 'wp_ajax_' . self::AJAX_SAVE_SETTINGS, array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_' . self::AJAX_SAVE_SCAN, array( $this, 'ajax_save_scan' ) );
		add_action( 'wp_ajax_' . self::AJAX_DETECT_CITATIONS, array( $this, 'ajax_detect_citations_post_type' ) );
	}

	/**
	 * Normalises any incoming target string to exactly 'reference' or
	 * 'citations' — an unrecognised value always falls back to
	 * 'reference', the pre-existing single-target behaviour.
	 */
	private static function normalise_target( $target ) {
		return 'citations' === sanitize_key( (string) $target ) ? 'citations' : 'reference';
	}

	/**
	 * The {url option, scan option} pair for one target.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function option_names( $target ) {
		return 'citations' === self::normalise_target( $target )
			? array( self::OPTION_CITATIONS_URL, self::OPTION_CITATIONS_SCAN )
			: array( self::OPTION_URL, self::OPTION_SCAN );
	}

	/**
	 * Which scan target a question's own `group` field belongs to —
	 * 'InTextCitation' always means the separate Citations post type;
	 * every other group (including the default 'ReferenceList') means the
	 * Reference List. Shared by Citex_Populator (routes population) and
	 * Citex_Generator (merges used-question-ID collision checks across
	 * both real post types).
	 */
	public static function target_for_group( $group ) {
		return 'InTextCitation' === (string) $group ? 'citations' : 'reference';
	}

	public static function get_question_list_url( $target = 'reference' ) {
		list( $url_option ) = self::option_names( $target );
		return get_option( $url_option, '' );
	}

	public static function get_last_scan( $target = 'reference' ) {
		list( , $scan_option ) = self::option_names( $target );
		$scan = get_option( $scan_option, null );
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
	public static function sync_from_wordpress( $target = 'reference' ) {
		$target = self::normalise_target( $target );
		$url    = self::get_question_list_url( $target );
		if ( ! $url ) {
			return new WP_Error(
				'citations' === $target ? 'citex_no_citations_url' : 'citex_no_reference_url',
				'citations' === $target ? __( 'Citations List URL is not configured.', 'citex-tools' ) : __( 'Reference List URL is not configured.', 'citex-tools' )
			);
		}

		$post_type = self::post_type_from_url( $url );
		if ( ! $post_type || ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'citex_bad_post_type',
				'citations' === $target
					? __( 'Citex could not determine the Citations post type from the configured URL.', 'citex-tools' )
					: __( 'Citex could not determine the Reference List post type from the configured URL.', 'citex-tools' )
			);
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
			'breakdowns'      => self::compute_breakdowns( $questions ),
		);

		list( , $scan_option ) = self::option_names( $target );
		update_option( $scan_option, $scan, false );
		return $scan;
	}

	/**
	 * Every questionId parsed from this target's TRASHED posts' titles.
	 * Deliberately not part of sync_from_wordpress()'s own $questions array
	 * (see its "if ( 'trash' === $status ) { continue; }" — trash correctly
	 * never counts toward Dashboard totals/coverage), but a trashed post's
	 * row, and its title, still genuinely exist: Citex_Populator::populate_one()'s
	 * own duplicate-title check queries 'trash' among its statuses and DOES
	 * still block creating a new post with that same title.
	 *
	 * A real reported bug: Citex_Generator::collect_used_question_ids() only
	 * ever read sync_from_wordpress()'s own $questions array, so a trashed
	 * post's ID looked "free" to the generator, which reused it for a fresh
	 * batch — every one of which then failed to populate with "A record
	 * with this exact title already exists in this post type," 0 created,
	 * because the trashed post was still there the whole time. This gives
	 * collect_used_question_ids() the missing half of the picture without
	 * changing sync_from_wordpress()'s own, correct, trash-excluding
	 * behaviour for every other caller (Dashboard totals/coverage).
	 *
	 * @return string[] Every used questionId, uppercased.
	 */
	public static function trashed_question_ids( $target = 'reference' ) {
		$target = self::normalise_target( $target );
		$url    = self::get_question_list_url( $target );
		if ( ! $url ) {
			return array();
		}
		$post_type = self::post_type_from_url( $url );
		if ( ! $post_type || ! post_type_exists( $post_type ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'trash',
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'suppress_filters'       => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$ids = array();
		foreach ( $posts as $post ) {
			$parsed = self::parse_title( get_the_title( $post ) );
			$id     = strtoupper( trim( (string) ( $parsed['questionId'] ?? '' ) ) );
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Combines any number of per-target scans (Reference List, Citations —
	 * see target_for_group()'s own docblock) into ONE scan-shaped array,
	 * for callers that need a single combined view (the Dashboard's own
	 * "Total Questions"/breakdown cards, in particular — which, before
	 * this method existed, only ever reflected the Reference List's own
	 * scan, silently excluding every Citations question from every total).
	 * A null/WP_Error entry (a target whose URL isn't configured, or that
	 * has never been scanned) is skipped, not treated as an error.
	 *
	 * @param array $scans Any number of scan arrays (as returned by
	 *              sync_from_wordpress()/get_last_scan()), or null/WP_Error
	 *              entries to skip.
	 * @return array|null The combined scan, or null if every entry was
	 *         empty (mirrors get_last_scan()'s own "never scanned" null).
	 */
	public static function merge_scans( array $scans ) {
		$questions     = array();
		$scanned_ats   = array();
		$status_counts = array(
			'all'     => 0,
			'publish' => 0,
			'draft'   => 0,
			'pending' => 0,
			'private' => 0,
			'future'  => 0,
			'trash'   => 0,
		);
		foreach ( $scans as $scan ) {
			if ( ! is_array( $scan ) ) {
				continue;
			}
			foreach ( ( $scan['questions'] ?? array() ) as $question ) {
				$questions[] = $question;
			}
			if ( ! empty( $scan['scannedAt'] ) ) {
				$scanned_ats[] = (string) $scan['scannedAt'];
			}
			foreach ( ( is_array( $scan['statusCounts'] ?? null ) ? $scan['statusCounts'] : array() ) as $status => $count ) {
				if ( isset( $status_counts[ $status ] ) ) {
					$status_counts[ $status ] += (int) $count;
				}
			}
		}
		if ( empty( $questions ) && empty( $scanned_ats ) ) {
			return null;
		}

		$harvard = array_filter(
			$questions,
			function ( $question ) {
				return false !== stripos( (string) ( $question['source'] ?? '' ), 'harvard' );
			}
		);

		return array(
			'scannedAt'    => empty( $scanned_ats ) ? gmdate( 'c' ) : max( $scanned_ats ),
			'total'        => count( $questions ),
			'harvardTotal' => count( $harvard ),
			'statusCounts' => $status_counts,
			'questions'    => $questions,
			'breakdowns'   => self::compute_breakdowns( $questions ),
		);
	}

	/**
	 * The Source/Group/Category/Type/PostStatus/Combination breakdown shape
	 * shared by sync_from_wordpress() and merge_scans() (and, for a single
	 * referencing style's own slice, Citex_Dashboard) — factored out so both
	 * compute it identically rather than duplicating the same five calls.
	 *
	 * @param array[] $questions
	 * @return array{sources:array,groups:array,categories:array,types:array,postStatuses:array,combinations:array}
	 */
	public static function compute_breakdowns( $questions ) {
		return array(
			'sources'      => self::count_by( $questions, 'source' ),
			'groups'       => self::count_by( $questions, 'group' ),
			'categories'   => self::count_by( $questions, 'category' ),
			'types'        => self::count_by( $questions, 'type' ),
			'postStatuses' => self::count_by( $questions, 'postStatus' ),
			'combinations' => self::count_combinations( $questions ),
		);
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

	/**
	 * Finds the real Citations post type by inspecting WordPress's OWN
	 * registered post types directly (Citex runs as a local plugin on the
	 * same install — see sync_from_wordpress()'s own class docblock — so
	 * this needs no external request at all). Looks for the first
	 * registered, non-builtin post type whose slug or label contains
	 * "citation" (case-insensitively) — deliberately excluding the
	 * Reference List's own configured post type, so a Reference List
	 * post-type name that happens to also mention "citation" can never be
	 * wrongly detected as Citations.
	 *
	 * @return array{slug: string, label: string}|null
	 */
	private static function detect_citations_post_type() {
		$reference_post_type = self::post_type_from_url( self::get_question_list_url( 'reference' ) );
		$types = get_post_types( array(), 'objects' );
		foreach ( $types as $slug => $object ) {
			if ( $slug === $reference_post_type ) {
				continue;
			}
			$label = is_object( $object ) ? (string) ( $object->labels->name ?? $object->label ?? $slug ) : (string) $slug;
			if ( false !== stripos( $slug, 'citation' ) || false !== stripos( $label, 'citation' ) ) {
				return array( 'slug' => sanitize_key( $slug ), 'label' => $label );
			}
		}
		return null;
	}

	public function ajax_detect_citations_post_type() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please refresh the page and try again.', 'citex-tools' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'citex-tools' ) ), 403 );
		}

		$found = self::detect_citations_post_type();
		if ( null === $found ) {
			wp_send_json_error(
				array(
					'message' => __( 'Citex could not find a registered post type mentioning "citation". Open the Citations screen in WP Admin yourself and paste its URL below instead.', 'citex-tools' ),
				),
				404
			);
		}

		$url = admin_url( 'edit.php?post_type=' . $found['slug'] );
		update_option( self::OPTION_CITATIONS_URL, $url, false );
		wp_send_json_success( array( 'questionListUrl' => $url, 'postType' => $found['slug'], 'label' => $found['label'] ) );
	}

	public function ajax_save_settings() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please refresh the page and try again.', 'citex-tools' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'citex-tools' ) ), 403 );
		}

		try {
			$target = self::normalise_target( $_POST['target'] ?? 'reference' );
			list( $url_option ) = self::option_names( $target );
			$url = isset( $_POST['question_list_url'] ) ? esc_url_raw( wp_unslash( $_POST['question_list_url'] ) ) : '';
			update_option( $url_option, $url, false );
			wp_send_json_success( array( 'questionListUrl' => $url, 'target' => $target ) );
		} catch ( Throwable $e ) {
			error_log( '[Citex Tools] ajax_save_settings failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => sprintf( __( 'Citex: saving the setting failed — %s.', 'citex-tools' ), $e->getMessage() ) ), 500 );
		}
	}

	public function ajax_save_scan() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please refresh the page and try again.', 'citex-tools' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'citex-tools' ) ), 403 );
		}

		try {
			$target = self::normalise_target( $_POST['target'] ?? 'reference' );
			$raw    = isset( $_POST['scan'] ) ? wp_unslash( $_POST['scan'] ) : '';
			$data   = json_decode( $raw, true );
			if ( ! is_array( $data ) || ! isset( $data['questions'] ) || ! is_array( $data['questions'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid scan data.', 'citex-tools' ) ), 400 );
			}

			$scan = self::sanitize_scan( $data );
			list( , $scan_option ) = self::option_names( $target );
			update_option( $scan_option, $scan, false );
			wp_send_json_success(
				array(
					'scannedAt'    => $scan['scannedAt'],
					'total'        => $scan['total'],
					'statusCounts' => $scan['statusCounts'],
				)
			);
		} catch ( Throwable $e ) {
			error_log( '[Citex Tools] ajax_save_scan failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => sprintf( __( 'Citex: saving the scan failed — %s.', 'citex-tools' ), $e->getMessage() ) ), 500 );
		}
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
