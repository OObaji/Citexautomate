<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Citex question generator.
 *
 * Generator v1 creates Liverpool Hope Harvard / ReferenceList / Book /
 * DragDrop questions as pending Citex records. v0.9 adds an independent
 * validation gate: generated records must pass Citex_Generated_Validator
 * before the Populator is allowed to create real Reference List posts.
 */
class Citex_Generator {

	const NONCE_ACTION   = 'citex_generate_questions';
	const OPTION_PENDING = 'citex_pending_questions';

	public function render() {
		$this->maybe_handle_submit();

		$referencing_styles = array( 'harvard' => 'Harvard' );
		$institutions       = array( 'liverpool_hope' => 'Liverpool Hope University' );
		$categories         = array( 'book' => 'Book' );
		$question_types     = array( 'dragdrop' => 'DragDrop' );
		$difficulties       = array(
			'easy'   => 'Easy',
			'medium' => 'Medium',
			'hard'   => 'Hard',
		);

		$pending_questions = self::get_pending_questions();
		require CITEX_TOOLS_PATH . 'admin/views/generate.php';
	}

	public static function get_pending_questions() {
		$pending = get_option( self::OPTION_PENDING, array() );
		return is_array( $pending ) ? array_values( $pending ) : array();
	}

	public static function save_pending_questions( $pending ) {
		update_option( self::OPTION_PENDING, array_values( is_array( $pending ) ? $pending : array() ), false );
	}

	public static function get_pending_count() {
		return count( self::get_pending_questions() );
	}

	private function maybe_handle_submit() {
		if (
			empty( $_POST['citex_generate_submit'] ) &&
			empty( $_POST['citex_clear_pending'] ) &&
			empty( $_POST['citex_delete_pending'] ) &&
			empty( $_POST['citex_validate_pending'] ) &&
			empty( $_POST['citex_validate_one_pending'] )
		) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, 'citex_generate_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'citex-tools' ) );
		}

		if ( ! empty( $_POST['citex_clear_pending'] ) ) {
			self::save_pending_questions( array() );
			Citex_Admin::set_notice( __( 'All pending generated questions were cleared. No WordPress questions were changed.', 'citex-tools' ), 'success' );
			$this->redirect_back();
		}

		if ( ! empty( $_POST['citex_delete_pending'] ) ) {
			$key     = isset( $_POST['citex_pending_key'] ) ? sanitize_text_field( wp_unslash( $_POST['citex_pending_key'] ) ) : '';
			$pending = array_values(
				array_filter(
					self::get_pending_questions(),
					function ( $question ) use ( $key ) {
						return ( $question['key'] ?? '' ) !== $key;
					}
				)
			);
			self::save_pending_questions( $pending );
			Citex_Admin::set_notice( __( 'Pending question removed.', 'citex-tools' ), 'success' );
			$this->redirect_back();
		}

		if ( ! empty( $_POST['citex_validate_pending'] ) ) {
			$this->validate_pending_batch();
		}

		if ( ! empty( $_POST['citex_validate_one_pending'] ) ) {
			$key = isset( $_POST['citex_pending_key'] ) ? sanitize_text_field( wp_unslash( $_POST['citex_pending_key'] ) ) : '';
			$this->validate_one_pending( $key );
		}

		$this->handle_generation();
	}

	private function validate_pending_batch() {
		$pending = self::get_pending_questions();
		$passed  = 0;
		$failed  = 0;

		foreach ( $pending as &$question ) {
			$result = Citex_Generated_Validator::validate( $question );
			$question['validationStatus'] = $result['status'];
			$question['validationErrors'] = $result['errors'];
			$question['validatedAt']      = $result['validatedAt'];
			if ( ! empty( $result['reconstructedReference'] ) ) {
				$question['validatedReference'] = $result['reconstructedReference'];
			}
			if ( 'passed' === $result['status'] ) {
				$passed++;
			} else {
				$failed++;
			}
		}
		unset( $question );

		self::save_pending_questions( $pending );
		Citex_Admin::set_notice(
			sprintf( __( 'Generated-question validation complete. Passed: %1$d. Failed: %2$d. Only passed questions can be populated.', 'citex-tools' ), $passed, $failed ),
			empty( $failed ) ? 'success' : 'warning'
		);
		$this->redirect_back();
	}

	private function validate_one_pending( $key ) {
		$pending = self::get_pending_questions();
		$found   = false;

		foreach ( $pending as &$question ) {
			if ( ( $question['key'] ?? '' ) !== $key ) {
				continue;
			}
			$result = Citex_Generated_Validator::validate( $question );
			$question['validationStatus'] = $result['status'];
			$question['validationErrors'] = $result['errors'];
			$question['validatedAt']      = $result['validatedAt'];
			if ( ! empty( $result['reconstructedReference'] ) ) {
				$question['validatedReference'] = $result['reconstructedReference'];
			}
			$found = true;
			break;
		}
		unset( $question );

		self::save_pending_questions( $pending );
		Citex_Admin::set_notice( $found ? __( 'Generated question revalidated.', 'citex-tools' ) : __( 'Pending question was not found.', 'citex-tools' ), $found ? 'success' : 'error' );
		$this->redirect_back();
	}

	private function handle_generation() {
		$style       = isset( $_POST['citex_referencing_style'] ) ? sanitize_key( wp_unslash( $_POST['citex_referencing_style'] ) ) : '';
		$institution = isset( $_POST['citex_institution'] ) ? sanitize_key( wp_unslash( $_POST['citex_institution'] ) ) : '';
		$category    = isset( $_POST['citex_category'] ) ? sanitize_key( wp_unslash( $_POST['citex_category'] ) ) : '';
		$type        = isset( $_POST['citex_question_type'] ) ? sanitize_key( wp_unslash( $_POST['citex_question_type'] ) ) : '';
		$difficulty  = isset( $_POST['citex_difficulty'] ) ? sanitize_key( wp_unslash( $_POST['citex_difficulty'] ) ) : 'medium';
		$quantity    = isset( $_POST['citex_quantity'] ) ? absint( $_POST['citex_quantity'] ) : 10;
		$starting_id = isset( $_POST['citex_starting_id'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['citex_starting_id'] ) ) ) : 'BK01';

		$quantity = max( 1, min( 100, $quantity ) );
		if ( 'harvard' !== $style || 'liverpool_hope' !== $institution || 'book' !== $category || 'dragdrop' !== $type ) {
			Citex_Admin::set_notice( __( 'Generator v1 currently supports only Liverpool Hope Harvard → Book → DragDrop.', 'citex-tools' ), 'error' );
			$this->redirect_back();
		}
		if ( ! in_array( $difficulty, array( 'easy', 'medium', 'hard' ), true ) ) {
			$difficulty = 'medium';
		}
		if ( ! preg_match( '/^([A-Z]+)(\d+)$/', $starting_id, $matches ) ) {
			Citex_Admin::set_notice( __( 'Starting ID must look like BK01, BK25, or BOOK001.', 'citex-tools' ), 'error' );
			$this->redirect_back();
		}

		$prefix       = $matches[1];
		$start_number = absint( $matches[2] );
		$number_width = max( 2, strlen( $matches[2] ) );
		$pending      = self::get_pending_questions();
		$used_ids     = $this->collect_used_question_ids( $pending );
		$generated    = array();
		$next_number  = $start_number;

		while ( count( $generated ) < $quantity ) {
			$question_id = $prefix . str_pad( (string) $next_number, $number_width, '0', STR_PAD_LEFT );
			$next_number++;
			if ( isset( $used_ids[ $question_id ] ) ) {
				continue;
			}
			$question = $this->build_book_dragdrop_question( $question_id, $difficulty, count( $pending ) + count( $generated ) );
			$generated[]              = $question;
			$used_ids[ $question_id ] = true;
		}

		self::save_pending_questions( array_merge( $pending, $generated ) );
		Citex_Admin::set_notice(
			sprintf( _n( '%d pending question generated. Validate it before population.', '%d pending questions generated. Validate them before population.', count( $generated ), 'citex-tools' ), count( $generated ) ),
			'success'
		);
		$this->redirect_back();
	}

	private function build_book_dragdrop_question( $question_id, $difficulty, $seed ) {
		$authors = array(
			array( 'first' => 'Temi', 'surname' => 'Adebayo', 'initials' => 'T.' ),
			array( 'first' => 'Rebecca', 'surname' => 'Bennett', 'initials' => 'R.' ),
			array( 'first' => 'Michael', 'surname' => 'Clarke', 'initials' => 'M.' ),
			array( 'first' => 'Sophie', 'surname' => 'Davies', 'initials' => 'S.' ),
			array( 'first' => 'Leah', 'surname' => 'Evans', 'initials' => 'L.' ),
			array( 'first' => 'James', 'surname' => 'Foster', 'initials' => 'J.' ),
			array( 'first' => 'Priya', 'surname' => 'Green', 'initials' => 'P.' ),
			array( 'first' => 'Nadia', 'surname' => 'Hassan', 'initials' => 'N.' ),
			array( 'first' => 'Kareem', 'surname' => 'Ibrahim', 'initials' => 'K.' ),
			array( 'first' => 'Amelia', 'surname' => 'Jones', 'initials' => 'A.' ),
			array( 'first' => 'Daniel', 'surname' => 'Khan', 'initials' => 'D.' ),
			array( 'first' => 'Chloe', 'surname' => 'Lewis', 'initials' => 'C.' ),
		);
		$titles = array(
			'Digital Communities', 'Global Health Systems', 'Modern Economic Ideas', 'Cities and Social Change',
			'Learning in a Connected World', 'Public Policy in Practice', 'Culture and Communication',
			'Foundations of Data Society', 'Education and Innovation', 'Sustainable Urban Futures',
			'Media, Identity and Society', 'Contemporary Business Strategy',
		);
		$places     = array( 'London', 'Oxford', 'Manchester', 'Bristol', 'Cambridge', 'Liverpool', 'Leeds', 'Edinburgh' );
		$publishers = array( 'Northbridge Academic Press', 'Meridian Press', 'Oakwell Publishing', 'Civic Academic', 'Harbour House', 'Westfield Press', 'Elmstone Academic', 'Kingfisher Learning' );
		$years      = array( 2017, 2018, 2019, 2020, 2021, 2022, 2023, 2024, 2025 );

		$author    = $authors[ $seed % count( $authors ) ];
		$title     = $titles[ ( $seed * 5 + 2 ) % count( $titles ) ];
		$place     = $places[ ( $seed * 3 + 1 ) % count( $places ) ];
		$publisher = $publishers[ ( $seed * 7 + 3 ) % count( $publishers ) ];
		$year      = $years[ ( $seed * 2 + 1 ) % count( $years ) ];

		$question_parts = array( $author['surname'], $author['initials'], (string) $year, $title );
		$fixed_text     = sprintf( '|, || (||) ||. %s: %s.', $place, $publisher );
		$reference      = sprintf( '%s, %s (%d) %s. %s: %s.', $author['surname'], $author['initials'], $year, $title, $place, $publisher );
		$scenario       = sprintf( 'You are creating a reference for a book titled %s, written by %s %s. It was published in %d by %s in %s.', $title, $author['first'], $author['surname'], $year, $publisher, $place );

		$confusing_count = 'easy' === $difficulty ? 2 : ( 'hard' === $difficulty ? 4 : 3 );
		$confusing_words = $this->build_confusing_words( $year, $place, $title, $titles, $places, $confusing_count, $seed );

		return array(
			'key'                    => wp_generate_uuid4(),
			'questionId'             => $question_id,
			'title'                  => sprintf( 'Harvard | ReferenceList | Book | DragDrop | %s', $question_id ),
			'source'                 => 'Harvard',
			'group'                  => 'ReferenceList',
			'category'               => 'Book',
			'type'                   => 'DragDrop',
			'institution'            => 'Liverpool Hope University',
			'difficulty'             => ucfirst( $difficulty ),
			'scenario'               => $scenario,
			'fixedText'              => $fixed_text,
			'questionParts'          => $question_parts,
			'confusingWords'         => $confusing_words,
			'reconstructedReference' => $reference,
			'validationStatus'       => 'not_validated',
			'validationErrors'       => array(),
			'status'                 => 'pending',
			'generatedAt'            => gmdate( 'c' ),
		);
	}

	private function build_confusing_words( $year, $place, $title, $titles, $places, $count, $seed ) {
		$candidates = array(
			(string) ( $year - 2 ),
			(string) ( $year + 1 ),
			$places[ ( $seed + 4 ) % count( $places ) ],
			$titles[ ( $seed + 7 ) % count( $titles ) ],
			'Routledge',
			'Pearson',
		);
		$forbidden = array_map( 'strtolower', array( (string) $year, $place, $title ) );
		$out = array();
		foreach ( $candidates as $candidate ) {
			if ( in_array( strtolower( $candidate ), $forbidden, true ) || in_array( $candidate, $out, true ) ) {
				continue;
			}
			$out[] = $candidate;
			if ( count( $out ) >= $count ) {
				break;
			}
		}
		return $out;
	}

	private function collect_used_question_ids( $pending ) {
		$used = array();
		foreach ( $pending as $question ) {
			$id = strtoupper( trim( (string) ( $question['questionId'] ?? '' ) ) );
			if ( '' !== $id ) {
				$used[ $id ] = true;
			}
		}
		$scan = Citex_Scanner::get_last_scan();
		foreach ( ( $scan['questions'] ?? array() ) as $question ) {
			$id = strtoupper( trim( (string) ( $question['questionId'] ?? '' ) ) );
			if ( '' !== $id ) {
				$used[ $id ] = true;
			}
		}
		return $used;
	}

	private function redirect_back() {
		wp_safe_redirect( admin_url( 'admin.php?page=citex-generate' ) );
		exit;
	}
}
