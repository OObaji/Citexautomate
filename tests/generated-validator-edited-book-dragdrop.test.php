<?php
/**
 * Regression tests for Citex_Generated_Validator::validate_dragdrop()'s
 * Edited Book-only block — the exact-match check backing
 * Citex_Edited_Book_Dragdrop_Parts' dynamic exactly-3-part selection
 * (replaces the fixed named-design catalogue). Exercises the validator
 * directly against hand-built candidate fixtures, the same style as
 * tests/generated-validator-book-dragdrop.test.php.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-edited-book-dragdrop.test.php` — not
 * shipped in citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_code() {
		return $this->code;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function sanitize_key( $v ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
}

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-edited-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-generated-validator.php';

$failures = 0;
function check( $description, $actual, $expected ) {
	global $failures;
	$pass = $actual === $expected;
	echo ( $pass ? 'PASS' : 'FAIL' ) . ': ' . $description
		. ( $pass ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' )
		. "\n";
	if ( ! $pass ) {
		$failures++;
	}
}

function has_error_code( $result, $code ) {
	foreach ( $result['errors'] as $error ) {
		if ( $error['code'] === $code ) {
			return true;
		}
	}
	return false;
}

$editors = array( array( 'surname' => 'Smith', 'initials' => 'J.', 'fullName' => 'John Smith' ), array( 'surname' => 'Brown', 'initials' => 'K.', 'fullName' => 'Kate Brown' ) );
$fields  = array( 'year' => '2020', 'title' => 'Modern Sociology', 'place' => 'London', 'publisher' => 'Routledge' );
$keys    = array( 'editor_0', 'designation', 'place' );
$built   = Citex_Edited_Book_Dragdrop_Parts::build( $keys, $editors, $fields );

function eb_dragdrop_question( $keys, $built, $editors, $fields, $overrides = array() ) {
	return array_merge(
		array(
			'source'                 => 'Harvard',
			'group'                  => 'ReferenceList',
			'category'               => 'Edited Book',
			'type'                   => 'DragDrop',
			'scenario'               => 'You are referencing an edited book titled Modern Sociology, edited by John Smith and Kate Brown, published in 2020 by Routledge in London.',
			'dragdropPartKeys'       => $keys,
			'fixedText'              => $built['fixedText'],
			'questionParts'          => $built['parts'],
			'confusingWords'         => $built['confusingWords'],
			'reconstructedReference' => 'Smith, J. and Brown, K. (eds) (2020) Modern Sociology. London: Routledge.',
			'editors'                => $editors,
			'editorFullNames'        => array_column( $editors, 'fullName' ),
			'year'                   => $fields['year'],
			'bookTitle'              => $fields['title'],
			'place'                  => $fields['place'],
			'publisher'              => $fields['publisher'],
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A correctly-built selection PASSES.
// ---------------------------------------------------------------------
$result = Citex_Generated_Validator::validate( eb_dragdrop_question( $keys, $built, $editors, $fields ) );
check( '[1] a correctly-built Edited Book DragDrop question passes', $result['status'], 'passed' );
check( '[1] the reconstructed value is the correct reference, including "(eds)"', $result['reconstructedReference'], 'Smith, J. and Brown, K. (eds) (2020) Modern Sociology. London: Routledge.' );

// ---------------------------------------------------------------------
// 2. A tampered Question Part fails.
// ---------------------------------------------------------------------
$tampered_parts = eb_dragdrop_question( $keys, $built, $editors, $fields, array(
	'questionParts' => array( 'Wrong', $built['parts'][1], $built['parts'][2] ),
) );
$tampered_result = Citex_Generated_Validator::validate( $tampered_parts );
check( '[2] a tampered Question Part fails', $tampered_result['status'], 'failed' );
check( '[2] reports EDITED_BOOK_DRAGDROP_PARTS_MISMATCH', has_error_code( $tampered_result, 'edited_book_dragdrop_parts_mismatch' ), true );

// ---------------------------------------------------------------------
// 3. A tampered Fixed Text fails.
// ---------------------------------------------------------------------
$tampered_fixed = eb_dragdrop_question( $keys, $built, $editors, $fields, array(
	'fixedText' => str_replace( 'Routledge', 'Oxford University Press', $built['fixedText'] ),
) );
$tampered_fixed_result = Citex_Generated_Validator::validate( $tampered_fixed );
check( '[3] a tampered Fixed Text fails', $tampered_fixed_result['status'], 'failed' );
check( '[3] reports EDITED_BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', has_error_code( $tampered_fixed_result, 'edited_book_dragdrop_fixed_text_mismatch' ), true );

// ---------------------------------------------------------------------
// 4. A tampered confusing word fails.
// ---------------------------------------------------------------------
$tampered_confusing = eb_dragdrop_question( $keys, $built, $editors, $fields, array(
	'confusingWords' => array( 'Wrong', $built['confusingWords'][1], $built['confusingWords'][2] ),
) );
$tampered_confusing_result = Citex_Generated_Validator::validate( $tampered_confusing );
check( '[4] a tampered confusing word fails', $tampered_confusing_result['status'], 'failed' );
check( '[4] reports EDITED_BOOK_DRAGDROP_CONFUSING_WORDS_MISMATCH', has_error_code( $tampered_confusing_result, 'edited_book_dragdrop_confusing_words_mismatch' ), true );

// ---------------------------------------------------------------------
// 5. CRITICAL — a missing dragdropPartKeys field fails.
// ---------------------------------------------------------------------
$missing_keys = eb_dragdrop_question( $keys, $built, $editors, $fields, array( 'dragdropPartKeys' => array() ) );
$missing_keys_result = Citex_Generated_Validator::validate( $missing_keys );
check( '[5] a missing dragdropPartKeys fails', $missing_keys_result['status'], 'failed' );
check( '[5] reports EDITED_BOOK_DRAGDROP_PARTS_UNKNOWN', has_error_code( $missing_keys_result, 'edited_book_dragdrop_parts_unknown' ), true );

// ---------------------------------------------------------------------
// 6. CRITICAL — an out-of-range editor index in dragdropPartKeys fails.
// ---------------------------------------------------------------------
$bad_editor_index = eb_dragdrop_question( $keys, $built, $editors, $fields, array( 'dragdropPartKeys' => array( 'editor_9', 'designation' ) ) );
$bad_editor_index_result = Citex_Generated_Validator::validate( $bad_editor_index );
check( '[6] an out-of-range editor index fails', $bad_editor_index_result['status'], 'failed' );
check( '[6] reports EDITED_BOOK_DRAGDROP_PARTS_UNKNOWN', has_error_code( $bad_editor_index_result, 'edited_book_dragdrop_parts_unknown' ), true );

// ---------------------------------------------------------------------
// 7. A record with no canonical editor/title data at all (e.g. externally
// imported) is unaffected by this check — mirrors Book's own identical
// skip condition.
// ---------------------------------------------------------------------
$no_canonical = Citex_Generated_Validator::validate( array(
	'source'                 => 'Harvard',
	'group'                  => 'ReferenceList',
	'category'               => 'Edited Book',
	'type'                   => 'DragDrop',
	'fixedText'              => '|, || (ed.) (||) Example Book. London: Example Publisher.',
	'questionParts'          => array( 'Smith', 'J.', '2020' ),
	'confusingWords'         => array( '2018', 'K.', 'author' ),
	'reconstructedReference' => 'Smith, J. (ed.) (2020) Example Book. London: Example Publisher.',
) );
check( '[7] no EDITED_BOOK_DRAGDROP_PARTS_UNKNOWN for a record with no canonical data at all', has_error_code( $no_canonical, 'edited_book_dragdrop_parts_unknown' ), false );

// ---------------------------------------------------------------------
// 8. A different, valid single-editor selection also passes — proving the
// check adapts to whichever selection was actually recorded, and that
// "ed." (not "eds") is required for a single editor.
// ---------------------------------------------------------------------
$single_editor = array( array( 'surname' => 'Adams', 'initials' => 'P.', 'fullName' => 'Peter Adams' ) );
$single_fields = array( 'year' => '2019', 'title' => 'Digital Culture', 'place' => 'Oxford', 'publisher' => 'Oxford University Press' );
$single_keys   = array( 'editor_0', 'designation', 'year' );
$single_built  = Citex_Edited_Book_Dragdrop_Parts::build( $single_keys, $single_editor, $single_fields );
$single_question = eb_dragdrop_question( $single_keys, $single_built, $single_editor, $single_fields, array(
	'scenario'               => 'You are referencing an edited book titled Digital Culture, edited by Peter Adams, published in 2019 by Oxford University Press in Oxford.',
	'reconstructedReference' => 'Adams, P. (ed.) (2019) Digital Culture. Oxford: Oxford University Press.',
) );
check( '[8] a different valid single-editor selection also passes', Citex_Generated_Validator::validate( $single_question )['status'], 'passed' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
