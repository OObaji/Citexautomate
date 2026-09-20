<?php
/**
 * Regression tests for Citex_Generated_Validator::validate_dragdrop()'s APA
 * Book-only block — mirrors
 * tests/generated-validator-mla-book-dragdrop.test.php's own structure, but
 * for APA's own rule (Citex_APA_Book_Dragdrop_Parts): no `place` field at
 * all, authors carry `initials` (the SAME shape Harvard's own block uses,
 * never MLA's `givenName`), and the reconstructed reference must match
 * Citex_APA_Reference_Rules' own format shape (validate_reference_format()'s
 * style-aware branch), including its distinctive full stop immediately
 * after the year's closing parenthesis.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-apa-book-dragdrop.test.php` — not shipped
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
require __DIR__ . '/../citex-tools/includes/class-citex-apa-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-book-dragdrop-parts.php';
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

$authors = array( array( 'surname' => 'Smith', 'initials' => 'J.', 'fullName' => 'John Smith' ) );
$fields  = array( 'year' => '2021', 'title' => 'Digital culture', 'publisher' => 'Routledge' );
$keys    = array( 'author_0_surname', 'author_0_initials', 'year' );
$built   = Citex_APA_Book_Dragdrop_Parts::build( $keys, $authors, $fields );
$correct_reference = Citex_APA_Reference_Rules::build_reference( Citex_APA_Reference_Rules::CATEGORY_BOOK, array_merge( $fields, array( 'authors' => $authors ) ) );

function apa_book_dragdrop_question( $keys, $built, $authors, $fields, $correct_reference, $overrides = array() ) {
	return array_merge(
		array(
			'source'                 => 'APA',
			'group'                  => 'ReferenceList',
			'category'               => 'Book',
			'type'                   => 'DragDrop',
			'scenario'               => 'You are referencing a book titled ' . $fields['title'] . ' by John Smith, published in ' . $fields['year'] . ' by ' . $fields['publisher'] . '.',
			'dragdropPartKeys'       => $keys,
			'fixedText'              => $built['fixedText'],
			'questionParts'          => $built['parts'],
			'confusingWords'         => $built['confusingWords'],
			'reconstructedReference' => $correct_reference,
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
$result = Citex_Generated_Validator::validate( apa_book_dragdrop_question( $keys, $built, $authors, $fields, $correct_reference ) );
check( '[1] a correctly-built APA Book DragDrop question passes', $result['status'], 'passed' );
check( '[1] the reconstructed value returned is the correct reference', $result['reconstructedReference'], $correct_reference );

// ---------------------------------------------------------------------
// 2. A tampered Question Part fails.
// ---------------------------------------------------------------------
$tampered_parts = apa_book_dragdrop_question( $keys, $built, $authors, $fields, $correct_reference, array(
	'questionParts' => array( 'Wrong', $built['parts'][1], $built['parts'][2] ),
) );
$tampered_result = Citex_Generated_Validator::validate( $tampered_parts );
check( '[2] a tampered Question Part fails', $tampered_result['status'], 'failed' );
check( '[2] reports APA_BOOK_DRAGDROP_PARTS_MISMATCH', has_error_code( $tampered_result, 'apa_book_dragdrop_parts_mismatch' ), true );

// ---------------------------------------------------------------------
// 3. A tampered Fixed Text fails.
// ---------------------------------------------------------------------
$tampered_fixed = apa_book_dragdrop_question( $keys, $built, $authors, $fields, $correct_reference, array(
	'fixedText' => str_replace( 'Routledge', 'Oxford University Press', $built['fixedText'] ),
) );
$tampered_fixed_result = Citex_Generated_Validator::validate( $tampered_fixed );
check( '[3] a tampered Fixed Text fails', $tampered_fixed_result['status'], 'failed' );
check( '[3] reports APA_BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', has_error_code( $tampered_fixed_result, 'apa_book_dragdrop_fixed_text_mismatch' ), true );

// ---------------------------------------------------------------------
// 4. A tampered confusing word fails.
// ---------------------------------------------------------------------
$tampered_confusing = apa_book_dragdrop_question( $keys, $built, $authors, $fields, $correct_reference, array(
	'confusingWords' => array( 'Wrong', $built['confusingWords'][1], $built['confusingWords'][2] ),
) );
$tampered_confusing_result = Citex_Generated_Validator::validate( $tampered_confusing );
check( '[4] a tampered confusing word fails', $tampered_confusing_result['status'], 'failed' );
check( '[4] reports APA_BOOK_DRAGDROP_CONFUSING_WORDS_MISMATCH', has_error_code( $tampered_confusing_result, 'apa_book_dragdrop_confusing_words_mismatch' ), true );

// ---------------------------------------------------------------------
// 5. CRITICAL — a missing dragdropPartKeys field fails.
// ---------------------------------------------------------------------
$missing_keys = apa_book_dragdrop_question( $keys, $built, $authors, $fields, $correct_reference, array( 'dragdropPartKeys' => array() ) );
$missing_keys_result = Citex_Generated_Validator::validate( $missing_keys );
check( '[5] a missing dragdropPartKeys fails', $missing_keys_result['status'], 'failed' );
check( '[5] reports APA_BOOK_DRAGDROP_PARTS_UNKNOWN', has_error_code( $missing_keys_result, 'apa_book_dragdrop_parts_unknown' ), true );

// ---------------------------------------------------------------------
// 6. A record with no canonical author/title data at all (e.g. externally
// imported) is unaffected by this check — mirrors Book's own identical
// skip condition.
// ---------------------------------------------------------------------
$no_canonical = Citex_Generated_Validator::validate( array(
	'source'                 => 'APA',
	'group'                  => 'ReferenceList',
	'category'               => 'Book',
	'type'                   => 'DragDrop',
	'fixedText'              => '|, || (||). ||. ||.',
	'questionParts'          => array( 'Smith', 'J.', '2020', 'Example Publisher' ),
	'confusingWords'         => array( 'Smith\'s', 'J', '2019', 'Sample Press' ),
	'reconstructedReference' => 'Smith, J. (2020). Example book. Example Publisher.',
) );
check( '[6] no APA_BOOK_DRAGDROP_PARTS_UNKNOWN for a record with no canonical data at all', has_error_code( $no_canonical, 'apa_book_dragdrop_parts_unknown' ), false );

// ---------------------------------------------------------------------
// 7. A different, valid multi-author (3+, every author listed in full,
// "&" before the last) selection also passes — proving the check adapts
// to whichever selection was actually recorded, and that the
// APA-specific format regex accepts the "&"-joined shape.
// ---------------------------------------------------------------------
$three_authors = array(
	array( 'surname' => 'Ross', 'initials' => 'A.', 'fullName' => 'Amy Ross' ),
	array( 'surname' => 'Carter', 'initials' => 'B.', 'fullName' => 'Ben Carter' ),
	array( 'surname' => 'Lee', 'initials' => 'K.', 'fullName' => 'Kim Lee' ),
);
$three_author_keys      = array( 'ampersand', 'title' );
$three_author_built     = Citex_APA_Book_Dragdrop_Parts::build( $three_author_keys, $three_authors, $fields );
$three_author_reference = Citex_APA_Reference_Rules::build_reference( Citex_APA_Reference_Rules::CATEGORY_BOOK, array_merge( $fields, array( 'authors' => $three_authors ) ) );
$three_author_question  = apa_book_dragdrop_question( $three_author_keys, $three_author_built, $three_authors, $fields, $three_author_reference, array(
	'scenario' => 'You are referencing a book titled ' . $fields['title'] . ' by Amy Ross, Ben Carter and Kim Lee, published in ' . $fields['year'] . ' by ' . $fields['publisher'] . '.',
) );
check( '[7] a different valid 3+-author ("&"-joined) selection also passes', Citex_Generated_Validator::validate( $three_author_question )['status'], 'passed' );

// ---------------------------------------------------------------------
// 8. A Harvard-shaped reconstructed reference (no full stop after the
// year's closing parenthesis) for an APA-sourced record fails the
// APA-specific format check — proving validate_reference_format() is
// genuinely style-aware for APA too, not silently reusing Harvard's own
// regex, and that the generic YEAR_TRAILING_PERIOD check never fires for
// a correct APA reference (see [1] above, which already proves that
// positively).
// ---------------------------------------------------------------------
$harvard_shaped_keys  = array( 'year' );
$harvard_shaped_built = Citex_APA_Book_Dragdrop_Parts::build( $harvard_shaped_keys, $authors, $fields );
$harvard_shaped_question = apa_book_dragdrop_question( $harvard_shaped_keys, $harvard_shaped_built, $authors, $fields, $correct_reference, array(
	'fixedText'              => 'Smith, J. (||) Digital culture. Routledge.',
	'questionParts'          => array( '2021' ),
	'reconstructedReference' => 'Smith, J. (2021) Digital culture. Routledge.',
) );
$harvard_shaped_result = Citex_Generated_Validator::validate( $harvard_shaped_question );
check( '[8] a Harvard-shaped reference (no period after year) for an APA record fails', $harvard_shaped_result['status'], 'failed' );
check( '[8] reports APA_BOOK_FORMAT_MISMATCH', has_error_code( $harvard_shaped_result, 'apa_book_format_mismatch' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
