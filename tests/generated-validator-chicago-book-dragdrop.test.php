<?php
/**
 * Regression tests for Citex_Generated_Validator::validate_dragdrop()'s
 * Chicago (Author-Date) Book-only block — mirrors
 * tests/generated-validator-apa-book-dragdrop.test.php's own structure, but
 * for Chicago's own rule (Citex_Chicago_Book_Dragdrop_Parts): a `place`
 * field IS present (like Harvard, unlike MLA/APA), authors carry
 * `givenName` (the SAME shape MLA's own block uses, never Harvard/APA's
 * `initials`), and the reconstructed reference must match
 * Citex_Chicago_Reference_Rules' own format shape (validate_reference_format()'s
 * style-aware branch), including its distinctive lack of parentheses around
 * the year.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-chicago-book-dragdrop.test.php` — not
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
require __DIR__ . '/../citex-tools/includes/class-citex-mla-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-apa-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-chicago-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-chicago-book-dragdrop-parts.php';
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
$fields  = array( 'year' => '2021', 'title' => 'Digital Culture', 'place' => 'London', 'publisher' => 'Routledge' );
$keys    = array( 'author_0_surname', 'author_0_givenname', 'year' );
$built   = Citex_Chicago_Book_Dragdrop_Parts::build( $keys, $authors, $fields );
$correct_reference = Citex_Chicago_Reference_Rules::build_reference( Citex_Chicago_Reference_Rules::CATEGORY_BOOK, array_merge( $fields, array( 'authors' => $authors ) ) );

function chicago_book_dragdrop_question( $keys, $built, $authors, $fields, $correct_reference, $overrides = array() ) {
	return array_merge(
		array(
			'source'                 => 'Chicago',
			'group'                  => 'ReferenceList',
			'category'               => 'Book',
			'type'                   => 'DragDrop',
			'scenario'               => 'You are referencing a book titled ' . $fields['title'] . ' by John Smith, published in ' . $fields['year'] . ' by ' . $fields['publisher'] . ' in ' . $fields['place'] . '.',
			'dragdropPartKeys'       => $keys,
			'fixedText'              => $built['fixedText'],
			'questionParts'          => $built['parts'],
			'confusingWords'         => $built['confusingWords'],
			'reconstructedReference' => $correct_reference,
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
$result = Citex_Generated_Validator::validate( chicago_book_dragdrop_question( $keys, $built, $authors, $fields, $correct_reference ) );
check( '[1] a correctly-built Chicago Book DragDrop question passes', $result['status'], 'passed' );
check( '[1] the reconstructed value returned is the correct reference', $result['reconstructedReference'], $correct_reference );

// ---------------------------------------------------------------------
// 2. A tampered Question Part fails.
// ---------------------------------------------------------------------
$tampered_parts = chicago_book_dragdrop_question( $keys, $built, $authors, $fields, $correct_reference, array(
	'questionParts' => array( 'Wrong', $built['parts'][1], $built['parts'][2] ),
) );
$tampered_result = Citex_Generated_Validator::validate( $tampered_parts );
check( '[2] a tampered Question Part fails', $tampered_result['status'], 'failed' );
check( '[2] reports CHICAGO_BOOK_DRAGDROP_PARTS_MISMATCH', has_error_code( $tampered_result, 'chicago_book_dragdrop_parts_mismatch' ), true );

// ---------------------------------------------------------------------
// 3. A tampered Fixed Text fails.
// ---------------------------------------------------------------------
$tampered_fixed = chicago_book_dragdrop_question( $keys, $built, $authors, $fields, $correct_reference, array(
	'fixedText' => 'X' . $built['fixedText'],
) );
$tampered_fixed_result = Citex_Generated_Validator::validate( $tampered_fixed );
check( '[3] a tampered Fixed Text fails', $tampered_fixed_result['status'], 'failed' );
check( '[3] reports CHICAGO_BOOK_DRAGDROP_FIXED_TEXT_MISMATCH', has_error_code( $tampered_fixed_result, 'chicago_book_dragdrop_fixed_text_mismatch' ), true );

// ---------------------------------------------------------------------
// 4. A tampered confusing word fails.
// ---------------------------------------------------------------------
$tampered_confusing = chicago_book_dragdrop_question( $keys, $built, $authors, $fields, $correct_reference, array(
	'confusingWords' => array( 'Wrong', $built['confusingWords'][1], $built['confusingWords'][2] ),
) );
$tampered_confusing_result = Citex_Generated_Validator::validate( $tampered_confusing );
check( '[4] a tampered confusing word fails', $tampered_confusing_result['status'], 'failed' );
check( '[4] reports CHICAGO_BOOK_DRAGDROP_CONFUSING_WORDS_MISMATCH', has_error_code( $tampered_confusing_result, 'chicago_book_dragdrop_confusing_words_mismatch' ), true );

// ---------------------------------------------------------------------
// 5. CRITICAL — a missing dragdropPartKeys field fails.
// ---------------------------------------------------------------------
$missing_keys = chicago_book_dragdrop_question( $keys, $built, $authors, $fields, $correct_reference, array( 'dragdropPartKeys' => array() ) );
$missing_keys_result = Citex_Generated_Validator::validate( $missing_keys );
check( '[5] a missing dragdropPartKeys fails', $missing_keys_result['status'], 'failed' );
check( '[5] reports CHICAGO_BOOK_DRAGDROP_PARTS_UNKNOWN', has_error_code( $missing_keys_result, 'chicago_book_dragdrop_parts_unknown' ), true );

// ---------------------------------------------------------------------
// 6. A record with no canonical author/title data at all (e.g. externally
// imported) is unaffected by this check — mirrors Book's own identical
// skip condition.
// ---------------------------------------------------------------------
$no_canonical = Citex_Generated_Validator::validate( array(
	'source'                 => 'Chicago',
	'group'                  => 'ReferenceList',
	'category'               => 'Book',
	'type'                   => 'DragDrop',
	'fixedText'              => '|, ||. ||. ||: ||.',
	'questionParts'          => array( 'Smith', 'John', '2020', 'Example Publisher' ),
	'confusingWords'         => array( 'Smith\'s', 'J.', '2019', 'Sample Press' ),
	'reconstructedReference' => 'Smith, John. 2020. Example book. London: Example Publisher.',
) );
check( '[6] no CHICAGO_BOOK_DRAGDROP_PARTS_UNKNOWN for a record with no canonical data at all', has_error_code( $no_canonical, 'chicago_book_dragdrop_parts_unknown' ), false );

// ---------------------------------------------------------------------
// 7. A different, valid multi-author (3+, every author listed in full,
// "and" before the last, comma before it) selection also passes — proving
// the check adapts to whichever selection was actually recorded, and that
// the Chicago-specific format regex accepts the "and"-joined shape.
// ---------------------------------------------------------------------
$three_authors = array(
	array( 'surname' => 'Ross', 'givenName' => 'Amy', 'fullName' => 'Amy Ross' ),
	array( 'surname' => 'Carter', 'givenName' => 'Ben', 'fullName' => 'Ben Carter' ),
	array( 'surname' => 'Lee', 'givenName' => 'Kim', 'fullName' => 'Kim Lee' ),
);
$three_author_keys      = array( 'and', 'title' );
$three_author_built     = Citex_Chicago_Book_Dragdrop_Parts::build( $three_author_keys, $three_authors, $fields );
$three_author_reference = Citex_Chicago_Reference_Rules::build_reference( Citex_Chicago_Reference_Rules::CATEGORY_BOOK, array_merge( $fields, array( 'authors' => $three_authors ) ) );
$three_author_question  = chicago_book_dragdrop_question( $three_author_keys, $three_author_built, $three_authors, $fields, $three_author_reference, array(
	'scenario' => 'You are referencing a book titled ' . $fields['title'] . ' by Amy Ross, Ben Carter and Kim Lee, published in ' . $fields['year'] . ' by ' . $fields['publisher'] . ' in ' . $fields['place'] . '.',
) );
check( '[7] a different valid 3+-author ("and"-joined) selection also passes', Citex_Generated_Validator::validate( $three_author_question )['status'], 'passed' );

// ---------------------------------------------------------------------
// 8. A Harvard/APA-shaped reconstructed reference (year wrongly wrapped in
// parentheses) for a Chicago-sourced record fails the Chicago-specific
// format check — proving validate_reference_format() is genuinely
// style-aware for Chicago too, not silently reusing another style's regex,
// and that a correct Chicago reference (see [1] above, which already
// proves this positively) is never wrongly flagged.
// ---------------------------------------------------------------------
$parenthesised_keys  = array( 'year' );
$parenthesised_built = Citex_Chicago_Book_Dragdrop_Parts::build( $parenthesised_keys, $authors, $fields );
$parenthesised_question = chicago_book_dragdrop_question( $parenthesised_keys, $parenthesised_built, $authors, $fields, $correct_reference, array(
	'fixedText'              => 'Smith, John. (||) Digital Culture. London: Routledge.',
	'questionParts'          => array( '2021' ),
	'reconstructedReference' => 'Smith, John. (2021) Digital Culture. London: Routledge.',
) );
$parenthesised_result = Citex_Generated_Validator::validate( $parenthesised_question );
check( '[8] a wrongly-parenthesised year for a Chicago record fails', $parenthesised_result['status'], 'failed' );
check( '[8] reports CHICAGO_BOOK_FORMAT_MISMATCH', has_error_code( $parenthesised_result, 'chicago_book_format_mismatch' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
