<?php
/**
 * Regression tests for Citex_Generated_Validator::validate_dragdrop()'s
 * Book-only block — the exact-match check backing Citex_Book_Dragdrop_Parts'
 * dynamic 2-4-part selection (replaces the fixed 8-design catalogue).
 * Exercises the validator directly against hand-built candidate fixtures,
 * the same style as generated-validator-book-mcq-variant.test.php.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-book-dragdrop.test.php` — not shipped in
 * citex-tools.zip.
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

$authors = array( array( 'surname' => 'Brown', 'initials' => 'A.', 'fullName' => 'Andrew Brown' ) );
$fields  = array( 'year' => '2021', 'title' => 'Digital Culture', 'place' => 'London', 'publisher' => 'Routledge' );
$keys    = array( 'author_0_surname', 'author_0_initials', 'year', 'title' );
$built   = Citex_Book_Dragdrop_Parts::build( $keys, $authors, $fields );

function book_dragdrop_question( $keys, $built, $authors, $fields, $overrides = array() ) {
	return array_merge(
		array(
			'source'                 => 'Harvard',
			'group'                  => 'ReferenceList',
			'category'               => 'Book',
			'type'                   => 'DragDrop',
			'scenario'               => 'You are referencing a book titled Digital Culture by Andrew Brown, published in 2021 by Routledge in London.',
			'dragdropPartKeys'       => $keys,
			'fixedText'              => $built['fixedText'],
			'questionParts'          => $built['parts'],
			'confusingWords'         => $built['confusingWords'],
			'reconstructedReference' => 'Brown, A. (2021) Digital Culture. London: Routledge.',
			'authors'                => $authors,
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
$result = Citex_Generated_Validator::validate( book_dragdrop_question( $keys, $built, $authors, $fields ) );
check( '[1] a correctly-built book DragDrop question passes', $result['status'], 'passed' );
check( '[1] the reconstructed value returned is the correct reference', $result['reconstructedReference'], 'Brown, A. (2021) Digital Culture. London: Routledge.' );

// ---------------------------------------------------------------------
// 2. A tampered Question Part fails.
// ---------------------------------------------------------------------
$tampered_parts = book_dragdrop_question( $keys, $built, $authors, $fields, array(
	'questionParts' => array( 'Wrong', $built['parts'][1], $built['parts'][2], $built['parts'][3] ),
) );
$tampered_result = Citex_Generated_Validator::validate( $tampered_parts );
check( '[2] a tampered Question Part fails', $tampered_result['status'], 'failed' );
check( '[2] reports BOOK_DRAGDROP_PARTS_MISMATCH', has_error_code( $tampered_result, 'book_dragdrop_parts_mismatch' ), true );

// ---------------------------------------------------------------------
// 3. A tampered Fixed Text fails.
// ---------------------------------------------------------------------
$tampered_fixed = book_dragdrop_question( $keys, $built, $authors, $fields, array(
	'fixedText' => str_replace( 'London', 'Oxford', $built['fixedText'] ),
) );
$tampered_fixed_result = Citex_Generated_Validator::validate( $tampered_fixed );
check( '[3] a tampered Fixed Text fails', $tampered_fixed_result['status'], 'failed' );
check( '[3] reports BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', has_error_code( $tampered_fixed_result, 'book_dragdrop_fixed_text_mismatch' ), true );

// ---------------------------------------------------------------------
// 4. A tampered confusing word fails.
// ---------------------------------------------------------------------
$tampered_confusing = book_dragdrop_question( $keys, $built, $authors, $fields, array(
	'confusingWords' => array( 'Wrong', $built['confusingWords'][1], $built['confusingWords'][2], $built['confusingWords'][3] ),
) );
$tampered_confusing_result = Citex_Generated_Validator::validate( $tampered_confusing );
check( '[4] a tampered confusing word fails', $tampered_confusing_result['status'], 'failed' );
check( '[4] reports BOOK_DRAGDROP_CONFUSING_WORDS_MISMATCH', has_error_code( $tampered_confusing_result, 'book_dragdrop_confusing_words_mismatch' ), true );

// ---------------------------------------------------------------------
// 5. CRITICAL — a missing dragdropPartKeys field fails.
// ---------------------------------------------------------------------
$missing_keys = book_dragdrop_question( $keys, $built, $authors, $fields, array( 'dragdropPartKeys' => array() ) );
$missing_keys_result = Citex_Generated_Validator::validate( $missing_keys );
check( '[5] a missing dragdropPartKeys fails', $missing_keys_result['status'], 'failed' );
check( '[5] reports BOOK_DRAGDROP_PARTS_UNKNOWN', has_error_code( $missing_keys_result, 'book_dragdrop_parts_unknown' ), true );

// ---------------------------------------------------------------------
// 6. CRITICAL — an out-of-range author index in dragdropPartKeys fails.
// ---------------------------------------------------------------------
$bad_author_index = book_dragdrop_question( $keys, $built, $authors, $fields, array( 'dragdropPartKeys' => array( 'author_9_surname', 'year' ) ) );
$bad_author_index_result = Citex_Generated_Validator::validate( $bad_author_index );
check( '[6] an out-of-range author index fails', $bad_author_index_result['status'], 'failed' );
check( '[6] reports BOOK_DRAGDROP_PARTS_UNKNOWN', has_error_code( $bad_author_index_result, 'book_dragdrop_parts_unknown' ), true );

// ---------------------------------------------------------------------
// 7. Part count outside 2-4 fails (built directly with an out-of-bounds
// key list, bypassing select_parts()'s own floor/ceiling).
// ---------------------------------------------------------------------
$one_part_keys = array( 'year' );
$one_part_built = Citex_Book_Dragdrop_Parts::build( $one_part_keys, $authors, $fields );
$one_part_question = book_dragdrop_question( $one_part_keys, $one_part_built, $authors, $fields );
$one_part_result = Citex_Generated_Validator::validate( $one_part_question );
check( '[7] a 1-part selection fails the 2-4 part-count bound', $one_part_result['status'], 'failed' );
check( '[7] reports BOOK_DRAGDROP_PART_COUNT_OUT_OF_RANGE', has_error_code( $one_part_result, 'book_dragdrop_part_count_out_of_range' ), true );

// ---------------------------------------------------------------------
// 8. A record with no canonical author/title data at all (e.g. externally
// imported) is unaffected by this check — mirrors
// validate_bibliographic_consistency()'s own identical skip condition.
// ---------------------------------------------------------------------
$no_canonical = Citex_Generated_Validator::validate( array(
	'source'                 => 'Harvard',
	'group'                  => 'ReferenceList',
	'category'               => 'Book',
	'type'                   => 'DragDrop',
	'fixedText'              => '||, || (||) ||. London: Example Publisher.',
	'questionParts'          => array( 'Smith', 'J.', '2020', 'Example Book' ),
	'confusingWords'         => array( '2018', 'Manchester', 'Brown' ),
	'reconstructedReference' => 'Smith, J. (2020) Example Book. London: Example Publisher.',
) );
check( '[8] no BOOK_DRAGDROP_PARTS_UNKNOWN for a record with no canonical data at all', has_error_code( $no_canonical, 'book_dragdrop_parts_unknown' ), false );

// ---------------------------------------------------------------------
// 9. A different, valid multi-author selection also passes — proving the
// check adapts to whichever selection was actually recorded.
// ---------------------------------------------------------------------
$two_authors = array(
	array( 'surname' => 'Brown', 'initials' => 'A.', 'fullName' => 'Andrew Brown' ),
	array( 'surname' => 'Smith', 'initials' => 'J.', 'fullName' => 'James Smith' ),
);
$two_author_keys  = array( 'and', 'year' );
$two_author_built = Citex_Book_Dragdrop_Parts::build( $two_author_keys, $two_authors, $fields );
$two_author_question = book_dragdrop_question( $two_author_keys, $two_author_built, $two_authors, $fields, array(
	'scenario'               => 'You are referencing a book titled Digital Culture by Andrew Brown and James Smith, published in 2021 by Routledge in London.',
	'reconstructedReference' => 'Brown, A. and Smith, J. (2021) Digital Culture. London: Routledge.',
) );
check( '[9] a different valid multi-author selection also passes', Citex_Generated_Validator::validate( $two_author_question )['status'], 'passed' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
