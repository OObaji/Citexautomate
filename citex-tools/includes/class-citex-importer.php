<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import externally generated questions into the same Citex pending queue used
 * by the built-in generator. Imported questions never go straight to the real
 * Reference List: they enter Pending -> Validation -> Population exactly like
 * Citex-generated records.
 */
class Citex_Importer {

	const NONCE_ACTION   = 'citex_import_questions';
	const MAX_FILE_BYTES = 5242880; // 5 MB.

	public function render() {
		$this->maybe_handle_submit();

		$pending = Citex_Generator::get_pending_questions();
		$imported_pending = array_values(
			array_filter(
				$pending,
				function ( $question ) {
					return 0 === strpos( (string) ( $question['origin'] ?? '' ), 'imported_' );
				}
			)
		);

		$template_url = CITEX_TOOLS_URL . 'admin/templates/citex-import-template.csv';
		require CITEX_TOOLS_PATH . 'admin/views/import.php';
	}

	/**
	 * Called on admin_init (before any output) as well as at the top of
	 * render(), so a redirect after submission always reaches the browser.
	 */
	public function maybe_handle_submit() {
		if ( empty( $_POST['citex_import_file_submit'] ) && empty( $_POST['citex_import_json_submit'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, 'citex_import_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to import questions.', 'citex-tools' ) );
		}

		$validate_now = ! empty( $_POST['citex_validate_after_import'] );
		$rows         = array();
		$origin       = 'imported_unknown';
		$source_name  = '';

		if ( ! empty( $_POST['citex_import_file_submit'] ) ) {
			$file_result = $this->read_uploaded_file();
			if ( is_wp_error( $file_result ) ) {
				Citex_Admin::set_notice( $file_result->get_error_message(), 'error' );
				$this->redirect_back();
			}
			$rows        = $file_result['rows'];
			$origin      = $file_result['origin'];
			$source_name = $file_result['sourceName'];
		} else {
			$raw = isset( $_POST['citex_import_json'] ) ? trim( wp_unslash( $_POST['citex_import_json'] ) ) : '';
			if ( '' === $raw ) {
				Citex_Admin::set_notice( __( 'Paste JSON before importing.', 'citex-tools' ), 'error' );
				$this->redirect_back();
			}
			$rows = $this->parse_json_rows( $raw );
			if ( is_wp_error( $rows ) ) {
				Citex_Admin::set_notice( $rows->get_error_message(), 'error' );
				$this->redirect_back();
			}
			$origin      = 'imported_json';
			$source_name = 'Pasted JSON';
		}

		$result  = $this->import_rows( $rows, $origin, $source_name, $validate_now );
		$type    = $result['imported'] > 0 ? ( $result['failed'] > 0 ? 'warning' : 'success' ) : 'error';
		$message = sprintf(
			__( 'Import complete. Added: %1$d. Duplicates skipped: %2$d. Invalid rows: %3$d.%4$s', 'citex-tools' ),
			$result['imported'],
			$result['duplicates'],
			$result['failed'],
			$validate_now ? sprintf( __( ' Validation — passed: %1$d, failed: %2$d.', 'citex-tools' ), $result['validationPassed'], $result['validationFailed'] ) : ''
		);
		if ( ! empty( $result['errors'] ) ) {
			$message .= ' ' . __( 'First issues:', 'citex-tools' ) . ' ' . implode( ' | ', array_slice( $result['errors'], 0, 3 ) );
		}
		Citex_Admin::set_notice( $message, $type );
		$this->redirect_back();
	}

	private function read_uploaded_file() {
		if ( empty( $_FILES['citex_import_file'] ) || ! is_array( $_FILES['citex_import_file'] ) ) {
			return new WP_Error( 'citex_no_import_file', __( 'Choose a CSV, TSV or JSON file to import.', 'citex-tools' ) );
		}

		$file = $_FILES['citex_import_file'];
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new WP_Error( 'citex_upload_error', __( 'The import file could not be uploaded.', 'citex-tools' ) );
		}
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'citex_bad_upload', __( 'The uploaded file could not be verified.', 'citex-tools' ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_FILE_BYTES ) {
			return new WP_Error( 'citex_file_too_large', __( 'Import files are limited to 5 MB.', 'citex-tools' ) );
		}

		$name = sanitize_file_name( (string) ( $file['name'] ?? 'questions.csv' ) );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'tsv', 'txt', 'json' ), true ) ) {
			return new WP_Error( 'citex_bad_file_type', __( 'Supported import files are CSV, TSV, TXT and JSON.', 'citex-tools' ) );
		}

		if ( 'json' === $ext ) {
			$raw = file_get_contents( $file['tmp_name'] );
			if ( false === $raw ) {
				return new WP_Error( 'citex_read_failed', __( 'Citex could not read the JSON file.', 'citex-tools' ) );
			}
			$rows = $this->parse_json_rows( $raw );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			return array( 'rows' => $rows, 'origin' => 'imported_json', 'sourceName' => $name );
		}

		$rows = $this->parse_delimited_file( $file['tmp_name'], $ext );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		return array(
			'rows'       => $rows,
			'origin'     => 'tsv' === $ext ? 'imported_tsv' : 'imported_csv',
			'sourceName' => $name,
		);
	}

	private function parse_delimited_file( $path, $ext ) {
		$handle = fopen( $path, 'r' );
		if ( false === $handle ) {
			return new WP_Error( 'citex_csv_read_failed', __( 'Citex could not read the delimited file.', 'citex-tools' ) );
		}

		$first_line = fgets( $handle );
		if ( false === $first_line ) {
			fclose( $handle );
			return new WP_Error( 'citex_empty_import', __( 'The import file is empty.', 'citex-tools' ) );
		}
		rewind( $handle );

		$delimiter = "\t";
		if ( 'csv' === $ext ) {
			$delimiter = ',';
		} elseif ( 'txt' === $ext ) {
			$delimiter = substr_count( $first_line, "\t" ) > substr_count( $first_line, ',' ) ? "\t" : ',';
		}

		$headers = fgetcsv( $handle, 0, $delimiter );
		if ( ! is_array( $headers ) || empty( $headers ) ) {
			fclose( $handle );
			return new WP_Error( 'citex_missing_headers', __( 'The import file must contain a header row.', 'citex-tools' ) );
		}
		$headers = array_map( array( $this, 'clean_header' ), $headers );

		$rows = array();
		while ( false !== ( $values = fgetcsv( $handle, 0, $delimiter ) ) ) {
			if ( 1 === count( $values ) && '' === trim( (string) $values[0] ) ) {
				continue;
			}
			$row = array();
			foreach ( $headers as $index => $header ) {
				if ( '' !== $header ) {
					$row[ $header ] = isset( $values[ $index ] ) ? $values[ $index ] : '';
				}
			}
			$rows[] = $row;
		}
		fclose( $handle );
		return $rows;
	}

	private function parse_json_rows( $raw ) {
		$data = json_decode( (string) $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'citex_invalid_json', sprintf( __( 'Invalid JSON: %s', 'citex-tools' ), json_last_error_msg() ) );
		}
		if ( isset( $data['questions'] ) && is_array( $data['questions'] ) ) {
			$data = $data['questions'];
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'citex_json_shape', __( 'JSON must be an array of questions, or an object containing a questions array.', 'citex-tools' ) );
		}
		if ( $this->is_assoc( $data ) ) {
			$data = array( $data );
		}
		return array_values( array_filter( $data, 'is_array' ) );
	}

	private function import_rows( $rows, $origin, $source_name, $validate_now ) {
		$pending = Citex_Generator::get_pending_questions();
		$used    = $this->collect_used_ids( $pending );
		$result  = array(
			'imported'         => 0,
			'duplicates'       => 0,
			'failed'           => 0,
			'validationPassed' => 0,
			'validationFailed' => 0,
			'errors'           => array(),
		);

		foreach ( array_values( $rows ) as $index => $raw_row ) {
			$item_number = $index + 1;
			$question = $this->normalise_question( $raw_row, $origin, $source_name );
			if ( is_wp_error( $question ) ) {
				$result['failed']++;
				$result['errors'][] = sprintf( 'Item %d: %s', $item_number, $question->get_error_message() );
				continue;
			}

			$id = strtoupper( trim( (string) $question['questionId'] ) );
			if ( isset( $used[ $id ] ) ) {
				$result['duplicates']++;
				continue;
			}

			if ( $validate_now ) {
				$validation = Citex_Generated_Validator::validate( $question );
				$question['validationStatus'] = $validation['status'];
				$question['validationErrors'] = $validation['errors'];
				$question['validatedAt']      = $validation['validatedAt'];
				if ( ! empty( $validation['reconstructedReference'] ) ) {
					$question['validatedReference'] = $validation['reconstructedReference'];
				}
				if ( 'passed' === $validation['status'] ) {
					$result['validationPassed']++;
				} else {
					$result['validationFailed']++;
				}
			}

			$pending[]   = $question;
			$used[ $id ] = true;
			$result['imported']++;
		}

		Citex_Generator::save_pending_questions( $pending );
		return $result;
	}

	private function normalise_question( $row, $origin, $source_name ) {
		$normal = array();
		foreach ( $row as $key => $value ) {
			$normal[ $this->clean_header( $key ) ] = $value;
		}

		$id = strtoupper( trim( (string) $this->pick( $normal, array( 'questionid', 'id', 'questionnumber', 'questioncode' ) ) ) );
		if ( '' === $id ) {
			return new WP_Error( 'citex_import_missing_id', __( 'questionId / id is required.', 'citex-tools' ) );
		}

		$source      = $this->canonical_route_value( 'source', $this->text_or_default( $this->pick( $normal, array( 'source', 'referencingstyle', 'style' ) ), 'Harvard' ) );
		$group       = $this->canonical_route_value( 'group', $this->text_or_default( $this->pick( $normal, array( 'group', 'referencegroup' ) ), 'ReferenceList' ) );
		$category    = $this->canonical_route_value( 'category', $this->text_or_default( $this->pick( $normal, array( 'category', 'referencecategory' ) ), 'Book' ) );
		$type        = $this->canonical_route_value( 'type', $this->text_or_default( $this->pick( $normal, array( 'type', 'questiontype' ) ), 'DragDrop' ) );
		$institution = $this->text_or_default( $this->pick( $normal, array( 'institution', 'university', 'referencingrules' ) ), 'Liverpool Hope University' );
		$difficulty  = $this->text_or_default( $this->pick( $normal, array( 'difficulty', 'level' ) ), 'Medium' );
		$scenario    = trim( (string) $this->pick( $normal, array( 'scenario', 'question', 'prompt' ) ) );

		$fixed_text = trim( (string) $this->pick( $normal, array( 'fixedtext', 'fixed', 'template' ) ) );
		$parts      = $this->parse_list_value( $this->pick( $normal, array( 'questionparts', 'parts', 'draggableitems', 'correctparts' ) ) );
		$confusing  = $this->parse_list_value( $this->pick( $normal, array( 'confusingwords', 'distractors', 'wronganswers', 'incorrectparts' ) ) );
		$reference  = trim( (string) $this->pick( $normal, array( 'reconstructedreference', 'reference', 'expectedreference', 'answer' ) ) );

		// Simple external-generator format. If structured DragDrop fields were
		// not supplied, build them from ordinary book-reference columns.
		if ( '' === $fixed_text || empty( $parts ) ) {
			$surname    = trim( (string) $this->pick( $normal, array( 'authorsurname', 'surname', 'lastname', 'familyname' ) ) );
			$initials   = trim( (string) $this->pick( $normal, array( 'authorinitials', 'initials', 'initial' ) ) );
			$year       = trim( (string) $this->pick( $normal, array( 'year', 'publicationyear', 'pubyear' ) ) );
			$book_title = trim( (string) $this->pick( $normal, array( 'booktitle', 'worktitle', 'referencetitle' ) ) );
			$place      = trim( (string) $this->pick( $normal, array( 'place', 'publicationplace', 'city' ) ) );
			$publisher  = trim( (string) $this->pick( $normal, array( 'publisher', 'publishinghouse' ) ) );

			if ( '' === $surname || '' === $initials || '' === $year || '' === $book_title || '' === $place || '' === $publisher ) {
				return new WP_Error(
					'citex_import_missing_fields',
					__( 'Provide either fixedText + questionParts, or the simple Book fields: authorSurname, authorInitials, year, bookTitle, place and publisher.', 'citex-tools' )
				);
			}
			$parts      = array( $surname, $initials, $year, $book_title );
			$fixed_text = sprintf( '|, || (||) ||. %s: %s.', $place, $publisher );
			$reference  = sprintf( '%s, %s (%s) %s. %s: %s.', $surname, $initials, $year, $book_title, $place, $publisher );
		}

		if ( '' === $scenario ) {
			$scenario = 'Drag the correct items into the gaps to complete the Liverpool Hope Harvard book reference.';
		}
		if ( '' === $reference ) {
			$reference = $this->reconstruct_reference( $fixed_text, $parts );
		}

		$title = trim( (string) $this->pick( $normal, array( 'recordtitle', 'compositetitle', 'wordpresstitle' ) ) );
		if ( '' === $title ) {
			$title = sprintf( '%s | %s | %s | %s | %s', $source, $group, $category, $type, $id );
		}

		return array(
			'key'                    => wp_generate_uuid4(),
			'questionId'             => sanitize_text_field( $id ),
			'title'                  => sanitize_text_field( $title ),
			'source'                 => sanitize_text_field( $source ),
			'group'                  => sanitize_text_field( $group ),
			'category'               => sanitize_text_field( $category ),
			'type'                   => sanitize_text_field( $type ),
			'institution'            => sanitize_text_field( $institution ),
			'difficulty'             => sanitize_text_field( ucfirst( strtolower( $difficulty ) ) ),
			'scenario'               => sanitize_textarea_field( $scenario ),
			'fixedText'              => sanitize_text_field( $fixed_text ),
			'questionParts'          => array_values( array_map( 'sanitize_text_field', $parts ) ),
			'confusingWords'         => array_values( array_map( 'sanitize_text_field', $confusing ) ),
			'reconstructedReference' => sanitize_text_field( $reference ),
			'status'                 => 'pending',
			'validationStatus'       => 'not_validated',
			'origin'                 => sanitize_key( $origin ),
			'importSource'           => sanitize_text_field( $source_name ),
			'importedAt'             => gmdate( 'c' ),
		);
	}

	private function parse_list_value( $value ) {
		if ( is_array( $value ) ) {
			$clean = array_map(
				function ( $item ) {
					return is_scalar( $item ) ? trim( (string) $item ) : '';
				},
				$value
			);
			return array_values( array_filter( $clean, 'strlen' ) );
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array();
		}
		if ( '[' === substr( $value, 0, 1 ) ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				return $this->parse_list_value( $decoded );
			}
		}
		$parts = preg_split( '/\s*;;\s*|\r?\n/', $value );
		return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
	}

	private function reconstruct_reference( $fixed_text, $parts ) {
		$reference = '';
		$part      = 0;
		$length    = strlen( $fixed_text );
		for ( $i = 0; $i < $length; ) {
			if ( '|' !== $fixed_text[ $i ] ) {
				$reference .= $fixed_text[ $i++ ];
				continue;
			}
			if ( $i + 1 < $length && '|' === $fixed_text[ $i + 1 ] ) {
				$reference .= isset( $parts[ $part ] ) ? $parts[ $part++ ] : '';
				$i += 2;
				continue;
			}
			$reference .= isset( $parts[ $part ] ) ? $parts[ $part++ ] : '';
			$i++;
		}
		return trim( $reference );
	}

	private function collect_used_ids( $pending ) {
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

	private function pick( $row, $aliases ) {
		foreach ( $aliases as $alias ) {
			$key = $this->clean_header( $alias );
			if ( ! array_key_exists( $key, $row ) ) {
				continue;
			}
			$value = $row[ $key ];
			if ( is_array( $value ) && ! empty( $value ) ) {
				return $value;
			}
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return $value;
			}
		}
		return '';
	}

	private function text_or_default( $value, $default ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return '' === $value ? $default : $value;
	}

	private function canonical_route_value( $field, $value ) {
		$normal = strtolower( preg_replace( '/[^a-z0-9]+/', '', (string) $value ) );
		$map = array(
			'source'   => array( 'harvard' => 'Harvard' ),
			'group'    => array( 'referencelist' => 'ReferenceList' ),
			'category' => array( 'book' => 'Book' ),
			'type'     => array( 'dragdrop' => 'DragDrop' ),
		);
		return isset( $map[ $field ][ $normal ] ) ? $map[ $field ][ $normal ] : (string) $value;
	}

	public function clean_header( $header ) {
		$header = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header );
		return strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '', trim( $header ) ) );
	}

	private function is_assoc( $array ) {
		if ( array() === $array ) {
			return false;
		}
		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}

	private function redirect_back() {
		wp_safe_redirect( admin_url( 'admin.php?page=citex-import' ) );
		exit;
	}
}
