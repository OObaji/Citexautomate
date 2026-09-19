<?php
/**
 * Regression tests for Citex_Generated_Validator::validate_dragdrop()'s MLA
 * Edited Book block — mirrors tests/generated-validator-mla-book-dragdrop.test.php's
 * own structure exactly, but for Citex_MLA_Edited_Book_Dragdrop_Parts: no
 * `place` field at all, editors carry `givenName` (never `initials`), an
 * always-drawn `designation` candidate, and the reconstructed reference
 * must match Citex_MLA_Reference_Rules' own Edited Book format shape.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-mla-edited-book-dragdrop.test.php` — not
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
require __DIR__ . '/../citex-tools/includes/class-citex-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-edited-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-journal-article-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-website-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-edited-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-journal-article-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-website-dragdrop-parts.php';
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

$editors = array( array( 'surname' => 'Smith', 'givenName' => 'John', 'fullName' => 'John Smith' ) );
$fields  = array( 'year' => '2021', 'title' => 'Digital Culture', 'publisher' => 'Routledge' );
$keys    = array( 'editor_surname', 'editor_given', 'designation' );
$built   = Citex_MLA_Edited_Book_Dragdrop_Parts::build( $keys, $editors, $fields );

function mla_eb_dragdrop_question( $keys, $built, $editors, $fields, $overrides = array() ) {
	return array_merge(
		array(
			'source'                 => 'MLA',
			'group'                  => 'ReferenceList',
			'category'               => 'Edited Book',
			'type'                   => 'DragDrop',
			'scenario'               => 'You are referencing an edited book titled Digital Culture, edited by John Smith, published in 2021 by Routledge.',
			'dragdropPartKeys'       => $keys,
			'fixedText'              => $built['fixedText'],
			'questionParts'          => $built['parts'],
			'confusingWords'         => $built['confusingWords'],
			'reconstructedReference' => 'Smith, John, editor. Digital Culture. Routledge, 2021.',
			'editors'                => $editors,
			'year'                   => $fields['year'],
			'bookTitle'              => $fields['title'],
			'publisher'              => $fields['publisher'],
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A correctly-built selection PASSES.
// ---------------------------------------------------------------------
$result = Citex_Generated_Validator::validate( mla_eb_dragdrop_question( $keys, $built, $editors, $fields ) );
check( '[1] a correctly-built MLA Edited Book DragDrop question passes', $result['status'], 'passed' );
check( '[1] the reconstructed value returned is the correct reference', $result['reconstructedReference'], 'Smith, John, editor. Digital Culture. Routledge, 2021.' );

// ---------------------------------------------------------------------
// 2. A tampered Question Part fails.
// ---------------------------------------------------------------------
$tampered_parts = mla_eb_dragdrop_question( $keys, $built, $editors, $fields, array(
	'questionParts' => array( 'Wrong', $built['parts'][1], $built['parts'][2] ),
) );
$tampered_result = Citex_Generated_Validator::validate( $tampered_parts );
check( '[2] a tampered Question Part fails', $tampered_result['status'], 'failed' );
check( '[2] reports MLA_EDITED_BOOK_DRAGDROP_PARTS_MISMATCH', has_error_code( $tampered_result, 'mla_edited_book_dragdrop_parts_mismatch' ), true );

// ---------------------------------------------------------------------
// 3. A tampered Fixed Text fails.
// ---------------------------------------------------------------------
$tampered_fixed = mla_eb_dragdrop_question( $keys, $built, $editors, $fields, array(
	'fixedText' => str_replace( 'Routledge', 'Oxford University Press', $built['fixedText'] ),
) );
$tampered_fixed_result = Citex_Generated_Validator::validate( $tampered_fixed );
check( '[3] a tampered Fixed Text fails', $tampered_fixed_result['status'], 'failed' );
check( '[3] reports MLA_EDITED_BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', has_error_code( $tampered_fixed_result, 'mla_edited_book_dragdrop_fixed_text_mismatch' ), true );

// ---------------------------------------------------------------------
// 4. A tampered confusing word fails.
// ---------------------------------------------------------------------
$tampered_confusing = mla_eb_dragdrop_question( $keys, $built, $editors, $fields, array(
	'confusingWords' => array( 'Wrong', $built['confusingWords'][1], $built['confusingWords'][2] ),
) );
$tampered_confusing_result = Citex_Generated_Validator::validate( $tampered_confusing );
check( '[4] a tampered confusing word fails', $tampered_confusing_result['status'], 'failed' );
check( '[4] reports MLA_EDITED_BOOK_DRAGDROP_CONFUSING_WORDS_MISMATCH', has_error_code( $tampered_confusing_result, 'mla_edited_book_dragdrop_confusing_words_mismatch' ), true );

// ---------------------------------------------------------------------
// 5. CRITICAL — a missing dragdropPartKeys field fails.
// ---------------------------------------------------------------------
$missing_keys = mla_eb_dragdrop_question( $keys, $built, $editors, $fields, array( 'dragdropPartKeys' => array() ) );
$missing_keys_result = Citex_Generated_Validator::validate( $missing_keys );
check( '[5] a missing dragdropPartKeys fails', $missing_keys_result['status'], 'failed' );
check( '[5] reports MLA_EDITED_BOOK_DRAGDROP_PARTS_UNKNOWN', has_error_code( $missing_keys_result, 'mla_edited_book_dragdrop_parts_unknown' ), true );

// ---------------------------------------------------------------------
// 6. A record with no canonical editor/title data at all is unaffected by
// this check — mirrors Book's own identical skip condition.
// ---------------------------------------------------------------------
$no_canonical = Citex_Generated_Validator::validate( array(
	'source'                 => 'MLA',
	'group'                  => 'ReferenceList',
	'category'               => 'Edited Book',
	'type'                   => 'DragDrop',
	'fixedText'              => '|, ||, editor. ||, ||.',
	'questionParts'          => array( 'Smith', 'John', 'Example Publisher', '2020' ),
	'confusingWords'         => array( 'Smith\'s', 'J.', 'Sample Press', '2018' ),
	'reconstructedReference' => 'Smith, John, editor. Example Book. Example Publisher, 2020.',
) );
check( '[6] no MLA_EDITED_BOOK_DRAGDROP_PARTS_UNKNOWN for a record with no canonical data at all', has_error_code( $no_canonical, 'mla_edited_book_dragdrop_parts_unknown' ), false );

// ---------------------------------------------------------------------
// 7. A different, valid multi-editor (3+, "et al.") selection also passes
// — proving the check adapts to whichever selection was actually recorded,
// and that the "et al." abbreviation period survives before the
// designation comma.
// ---------------------------------------------------------------------
$three_editors = array(
	array( 'surname' => 'Ross', 'givenName' => 'Amy', 'fullName' => 'Amy Ross' ),
	array( 'surname' => 'Carter', 'givenName' => 'Ben', 'fullName' => 'Ben Carter' ),
	array( 'surname' => 'Lee', 'givenName' => 'Kim', 'fullName' => 'Kim Lee' ),
);
$three_editor_keys  = array( 'joiner', 'designation', 'title' );
$three_editor_built = Citex_MLA_Edited_Book_Dragdrop_Parts::build( $three_editor_keys, $three_editors, $fields );
$three_editor_question = mla_eb_dragdrop_question( $three_editor_keys, $three_editor_built, $three_editors, $fields, array(
	'scenario'               => 'You are referencing an edited book titled Digital Culture, edited by Amy Ross, Ben Carter and Kim Lee, published in 2021 by Routledge.',
	'reconstructedReference' => 'Ross, Amy, et al., editors. Digital Culture. Routledge, 2021.',
) );
check( '[7] a different valid 3+-editor ("et al.") selection also passes', Citex_Generated_Validator::validate( $three_editor_question )['status'], 'passed' );

// ---------------------------------------------------------------------
// 8. A Harvard-shaped reconstructed reference for an MLA-sourced record
// fails the MLA-specific format check — proving validate_reference_format()
// is genuinely style-aware, not silently reusing Harvard's own regex.
// ---------------------------------------------------------------------
$harvard_shaped_keys  = array( 'year' );
$harvard_shaped_built = Citex_MLA_Edited_Book_Dragdrop_Parts::build( $harvard_shaped_keys, $editors, $fields );
$harvard_shaped_question = mla_eb_dragdrop_question( $harvard_shaped_keys, $harvard_shaped_built, $editors, $fields, array(
	'fixedText'              => 'Smith, J. (ed.) (||) Digital Culture. London: Routledge.',
	'questionParts'          => array( '2021' ),
	'reconstructedReference' => 'Smith, J. (ed.) (2021) Digital Culture. London: Routledge.',
) );
$harvard_shaped_result = Citex_Generated_Validator::validate( $harvard_shaped_question );
check( '[8] a Harvard-shaped reference for an MLA record fails', $harvard_shaped_result['status'], 'failed' );
check( '[8] reports MLA_EDITED_BOOK_FORMAT_MISMATCH', has_error_code( $harvard_shaped_result, 'mla_edited_book_format_mismatch' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
