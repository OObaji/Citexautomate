<?php
/**
 * Regression tests for Citex_Generated_Validator::validate_dragdrop()'s MLA
 * Book-only block — mirrors tests/generated-validator-book-dragdrop.test.php's
 * own structure exactly, but for MLA's own rule (Citex_MLA_Book_Dragdrop_Parts):
 * no `place` field at all, authors carry `givenName` (never `initials`), and
 * the reconstructed reference must match Citex_MLA_Reference_Rules' own
 * format shape (validate_reference_format()'s style-aware branch), not
 * Harvard's.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-mla-book-dragdrop.test.php` — not shipped
 * in citex-tools.zip.
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
require __DIR__ . '/../citex-tools/includes/class-citex-mla-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-book-dragdrop-parts.php';
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

$authors = array( array( 'surname' => 'Smith', 'givenName' => 'John', 'fullName' => 'John Smith' ) );
$fields  = array( 'year' => '2021', 'title' => 'Digital Culture', 'publisher' => 'Routledge' );
$keys    = array( 'author_surname', 'author_given', 'year' );
$built   = Citex_MLA_Book_Dragdrop_Parts::build( $keys, $authors, $fields );

function mla_book_dragdrop_question( $keys, $built, $authors, $fields, $overrides = array() ) {
	return array_merge(
		array(
			'source'                 => 'MLA',
			'group'                  => 'ReferenceList',
			'category'               => 'Book',
			'type'                   => 'DragDrop',
			'scenario'               => 'You are referencing a book titled Digital Culture by John Smith, published in 2021 by Routledge.',
			'dragdropPartKeys'       => $keys,
			'fixedText'              => $built['fixedText'],
			'questionParts'          => $built['parts'],
			'confusingWords'         => $built['confusingWords'],
			'reconstructedReference' => 'Smith, John. Digital Culture. Routledge, 2021.',
			'authors'                => $authors,
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
$result = Citex_Generated_Validator::validate( mla_book_dragdrop_question( $keys, $built, $authors, $fields ) );
check( '[1] a correctly-built MLA Book DragDrop question passes', $result['status'], 'passed' );
check( '[1] the reconstructed value returned is the correct reference', $result['reconstructedReference'], 'Smith, John. Digital Culture. Routledge, 2021.' );

// ---------------------------------------------------------------------
// 2. A tampered Question Part fails.
// ---------------------------------------------------------------------
$tampered_parts = mla_book_dragdrop_question( $keys, $built, $authors, $fields, array(
	'questionParts' => array( 'Wrong', $built['parts'][1], $built['parts'][2] ),
) );
$tampered_result = Citex_Generated_Validator::validate( $tampered_parts );
check( '[2] a tampered Question Part fails', $tampered_result['status'], 'failed' );
check( '[2] reports MLA_BOOK_DRAGDROP_PARTS_MISMATCH', has_error_code( $tampered_result, 'mla_book_dragdrop_parts_mismatch' ), true );

// ---------------------------------------------------------------------
// 3. A tampered Fixed Text fails.
// ---------------------------------------------------------------------
$tampered_fixed = mla_book_dragdrop_question( $keys, $built, $authors, $fields, array(
	'fixedText' => str_replace( 'Routledge', 'Oxford University Press', $built['fixedText'] ),
) );
$tampered_fixed_result = Citex_Generated_Validator::validate( $tampered_fixed );
check( '[3] a tampered Fixed Text fails', $tampered_fixed_result['status'], 'failed' );
check( '[3] reports MLA_BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', has_error_code( $tampered_fixed_result, 'mla_book_dragdrop_fixed_text_mismatch' ), true );

// ---------------------------------------------------------------------
// 4. A tampered confusing word fails.
// ---------------------------------------------------------------------
$tampered_confusing = mla_book_dragdrop_question( $keys, $built, $authors, $fields, array(
	'confusingWords' => array( 'Wrong', $built['confusingWords'][1], $built['confusingWords'][2] ),
) );
$tampered_confusing_result = Citex_Generated_Validator::validate( $tampered_confusing );
check( '[4] a tampered confusing word fails', $tampered_confusing_result['status'], 'failed' );
check( '[4] reports MLA_BOOK_DRAGDROP_CONFUSING_WORDS_MISMATCH', has_error_code( $tampered_confusing_result, 'mla_book_dragdrop_confusing_words_mismatch' ), true );

// ---------------------------------------------------------------------
// 5. CRITICAL — a missing dragdropPartKeys field fails.
// ---------------------------------------------------------------------
$missing_keys = mla_book_dragdrop_question( $keys, $built, $authors, $fields, array( 'dragdropPartKeys' => array() ) );
$missing_keys_result = Citex_Generated_Validator::validate( $missing_keys );
check( '[5] a missing dragdropPartKeys fails', $missing_keys_result['status'], 'failed' );
check( '[5] reports MLA_BOOK_DRAGDROP_PARTS_UNKNOWN', has_error_code( $missing_keys_result, 'mla_book_dragdrop_parts_unknown' ), true );

// ---------------------------------------------------------------------
// 6. A record with no canonical author/title data at all (e.g. externally
// imported) is unaffected by this check — mirrors Book's own identical
// skip condition.
// ---------------------------------------------------------------------
$no_canonical = Citex_Generated_Validator::validate( array(
	'source'                 => 'MLA',
	'group'                  => 'ReferenceList',
	'category'               => 'Book',
	'type'                   => 'DragDrop',
	'fixedText'              => '|, ||. ||, ||.',
	'questionParts'          => array( 'Smith', 'John', 'Example Publisher', '2020' ),
	'confusingWords'         => array( 'Smith\'s', 'J.', 'Sample Press', '2018' ),
	'reconstructedReference' => 'Smith, John. Example Book. Example Publisher, 2020.',
) );
check( '[6] no MLA_BOOK_DRAGDROP_PARTS_UNKNOWN for a record with no canonical data at all', has_error_code( $no_canonical, 'mla_book_dragdrop_parts_unknown' ), false );

// ---------------------------------------------------------------------
// 7. A different, valid multi-author (3+, "et al.") selection also
// passes — proving the check adapts to whichever selection was actually
// recorded, and that the MLA-specific format regex accepts the "et al."
// shape.
// ---------------------------------------------------------------------
$three_authors = array(
	array( 'surname' => 'Ross', 'givenName' => 'Amy', 'fullName' => 'Amy Ross' ),
	array( 'surname' => 'Carter', 'givenName' => 'Ben', 'fullName' => 'Ben Carter' ),
	array( 'surname' => 'Lee', 'givenName' => 'Kim', 'fullName' => 'Kim Lee' ),
);
$three_author_keys  = array( 'joiner', 'title' );
$three_author_built = Citex_MLA_Book_Dragdrop_Parts::build( $three_author_keys, $three_authors, $fields );
$three_author_question = mla_book_dragdrop_question( $three_author_keys, $three_author_built, $three_authors, $fields, array(
	'scenario'               => 'You are referencing a book titled Digital Culture by Amy Ross, Ben Carter and Kim Lee, published in 2021 by Routledge.',
	'reconstructedReference' => 'Ross, Amy, et al. Digital Culture. Routledge, 2021.',
) );
check( '[7] a different valid 3+-author ("et al.") selection also passes', Citex_Generated_Validator::validate( $three_author_question )['status'], 'passed' );

// ---------------------------------------------------------------------
// 8. A Harvard-shaped reconstructed reference (parenthetical year,
// initials, "Place: Publisher") for an MLA-sourced record fails the
// MLA-specific format check — proving validate_reference_format() is
// genuinely style-aware, not silently reusing Harvard's own regex.
// ---------------------------------------------------------------------
$harvard_shaped_keys  = array( 'year' );
$harvard_shaped_built = Citex_MLA_Book_Dragdrop_Parts::build( $harvard_shaped_keys, $authors, $fields );
$harvard_shaped_question = mla_book_dragdrop_question( $harvard_shaped_keys, $harvard_shaped_built, $authors, $fields, array(
	'fixedText'              => 'Smith, J. (||) Digital Culture. London: Routledge.',
	'questionParts'          => array( '2021' ),
	'reconstructedReference' => 'Smith, J. (2021) Digital Culture. London: Routledge.',
) );
$harvard_shaped_result = Citex_Generated_Validator::validate( $harvard_shaped_question );
check( '[8] a Harvard-shaped reference for an MLA record fails', $harvard_shaped_result['status'], 'failed' );
check( '[8] reports MLA_BOOK_FORMAT_MISMATCH', has_error_code( $harvard_shaped_result, 'mla_book_format_mismatch' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
