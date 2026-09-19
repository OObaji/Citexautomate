<?php
/**
 * Regression tests for Citex_Generated_Validator::validate_dragdrop()'s
 * Journal Article-only block — the exact-match check backing
 * Citex_Journal_Article_Dragdrop_Parts' dynamic exactly-3-part selection
 * (replaces the fixed named-design catalogue). Exercises the validator
 * directly against hand-built candidate fixtures, the same style as
 * tests/generated-validator-book-dragdrop.test.php.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-journal-article-dragdrop.test.php` — not
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
require __DIR__ . '/../citex-tools/includes/class-citex-journal-article-dragdrop-parts.php';
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

$authors = array( array( 'surname' => 'Vance', 'initials' => 'L.', 'fullName' => 'Louise Vance' ), array( 'surname' => 'Dale', 'initials' => 'E.', 'fullName' => 'Edward Dale' ) );
$fields  = array( 'year' => '2021', 'articleTitle' => 'Urban Life', 'journalTitle' => 'Sociology', 'volume' => '55', 'issue' => '3', 'pages' => '410-425' );
$keys    = array( 'author_0', 'year', 'pages' );
$built   = Citex_Journal_Article_Dragdrop_Parts::build( $keys, $authors, $fields );

function ja_dragdrop_question( $keys, $built, $authors, $fields, $overrides = array() ) {
	return array_merge(
		array(
			'source'                 => 'Harvard',
			'group'                  => 'ReferenceList',
			'category'               => 'Journal Article',
			'type'                   => 'DragDrop',
			'scenario'               => 'You are creating a reference for an article titled Urban Life by Louise Vance and Edward Dale, published in 2021 in the journal Sociology, volume 55, issue 3, pages 410 to 425.',
			'dragdropPartKeys'       => $keys,
			'fixedText'              => $built['fixedText'],
			'questionParts'          => $built['parts'],
			'confusingWords'         => $built['confusingWords'],
			'reconstructedReference' => 'Vance, L. and Dale, E. (2021) ‘Urban Life’, Sociology, 55(3), pp. 410–425.',
			'authors'                => $authors,
			'authorFullNames'        => array_column( $authors, 'fullName' ),
			'authorFullName'         => $authors[0]['fullName'],
			'authorSurname'          => $authors[0]['surname'],
			'authorInitials'         => $authors[0]['initials'],
			'year'                   => $fields['year'],
			'articleTitle'           => $fields['articleTitle'],
			'journalTitle'           => $fields['journalTitle'],
			'volume'                 => $fields['volume'],
			'issue'                  => $fields['issue'],
			'pages'                  => $fields['pages'],
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A correctly-built selection PASSES, and the full reference is exactly
// the complete Cite Them Right reference — no field ever omitted.
// ---------------------------------------------------------------------
$result = Citex_Generated_Validator::validate( ja_dragdrop_question( $keys, $built, $authors, $fields ) );
check( '[1] a correctly-built Journal Article DragDrop question passes', $result['status'], 'passed' );
check( '[1] the reconstructed value is the complete reference', $result['reconstructedReference'], 'Vance, L. and Dale, E. (2021) ‘Urban Life’, Sociology, 55(3), pp. 410–425.' );

// ---------------------------------------------------------------------
// 2. A tampered Question Part fails.
// ---------------------------------------------------------------------
$tampered_parts = ja_dragdrop_question( $keys, $built, $authors, $fields, array(
	'questionParts' => array( 'Wrong', $built['parts'][1], $built['parts'][2] ),
) );
$tampered_result = Citex_Generated_Validator::validate( $tampered_parts );
check( '[2] a tampered Question Part fails', $tampered_result['status'], 'failed' );
check( '[2] reports JOURNAL_ARTICLE_DRAGDROP_PARTS_MISMATCH', has_error_code( $tampered_result, 'journal_article_dragdrop_parts_mismatch' ), true );

// ---------------------------------------------------------------------
// 3. A tampered Fixed Text fails.
// ---------------------------------------------------------------------
$tampered_fixed = ja_dragdrop_question( $keys, $built, $authors, $fields, array(
	'fixedText' => str_replace( 'Sociology', 'The Lancet', $built['fixedText'] ),
) );
$tampered_fixed_result = Citex_Generated_Validator::validate( $tampered_fixed );
check( '[3] a tampered Fixed Text fails', $tampered_fixed_result['status'], 'failed' );
check( '[3] reports JOURNAL_ARTICLE_DRAGDROP_FIXED_TEXT_MISMATCH', has_error_code( $tampered_fixed_result, 'journal_article_dragdrop_fixed_text_mismatch' ), true );

// ---------------------------------------------------------------------
// 4. A tampered confusing word fails.
// ---------------------------------------------------------------------
$tampered_confusing = ja_dragdrop_question( $keys, $built, $authors, $fields, array(
	'confusingWords' => array( 'Wrong', $built['confusingWords'][1], $built['confusingWords'][2] ),
) );
$tampered_confusing_result = Citex_Generated_Validator::validate( $tampered_confusing );
check( '[4] a tampered confusing word fails', $tampered_confusing_result['status'], 'failed' );
check( '[4] reports JOURNAL_ARTICLE_DRAGDROP_CONFUSING_WORDS_MISMATCH', has_error_code( $tampered_confusing_result, 'journal_article_dragdrop_confusing_words_mismatch' ), true );

// ---------------------------------------------------------------------
// 5. CRITICAL — a missing dragdropPartKeys field fails.
// ---------------------------------------------------------------------
$missing_keys = ja_dragdrop_question( $keys, $built, $authors, $fields, array( 'dragdropPartKeys' => array() ) );
$missing_keys_result = Citex_Generated_Validator::validate( $missing_keys );
check( '[5] a missing dragdropPartKeys fails', $missing_keys_result['status'], 'failed' );
check( '[5] reports JOURNAL_ARTICLE_DRAGDROP_PARTS_UNKNOWN', has_error_code( $missing_keys_result, 'journal_article_dragdrop_parts_unknown' ), true );

// ---------------------------------------------------------------------
// 6. CRITICAL — an out-of-range author index in dragdropPartKeys fails.
// ---------------------------------------------------------------------
$bad_author_index = ja_dragdrop_question( $keys, $built, $authors, $fields, array( 'dragdropPartKeys' => array( 'author_9', 'year' ) ) );
$bad_author_index_result = Citex_Generated_Validator::validate( $bad_author_index );
check( '[6] an out-of-range author index fails', $bad_author_index_result['status'], 'failed' );
check( '[6] reports JOURNAL_ARTICLE_DRAGDROP_PARTS_UNKNOWN', has_error_code( $bad_author_index_result, 'journal_article_dragdrop_parts_unknown' ), true );

// ---------------------------------------------------------------------
// 7. A record with no canonical author/title data at all (e.g. externally
// imported) is unaffected by this check — mirrors Book's own identical
// skip condition.
// ---------------------------------------------------------------------
$no_canonical = Citex_Generated_Validator::validate( array(
	'source'                 => 'Harvard',
	'group'                  => 'ReferenceList',
	'category'               => 'Journal Article',
	'type'                   => 'DragDrop',
	'fixedText'              => '|, || (||) ‘Example Article’, Example Journal, 1(1), pp. 1–2.',
	'questionParts'          => array( 'Smith', 'J.', '2020' ),
	'confusingWords'         => array( '2018', 'K.', 'Nature' ),
	'reconstructedReference' => 'Smith, J. (2020) ‘Example Article’, Example Journal, 1(1), pp. 1–2.',
) );
check( '[7] no JOURNAL_ARTICLE_DRAGDROP_PARTS_UNKNOWN for a record with no canonical data at all', has_error_code( $no_canonical, 'journal_article_dragdrop_parts_unknown' ), false );

// ---------------------------------------------------------------------
// 8. A different, valid selection (single author, "and" not eligible) also
// passes — proving the check adapts to whichever selection was actually
// recorded.
// ---------------------------------------------------------------------
$single_author = array( array( 'surname' => 'Adams', 'initials' => 'R.', 'fullName' => 'Robert Adams' ) );
$single_fields = array( 'year' => '2021', 'articleTitle' => 'Urban Growth', 'journalTitle' => 'Cities', 'volume' => '45', 'issue' => '2', 'pages' => '110-118' );
$single_keys   = array( 'volume', 'issue', 'pages' );
$single_built  = Citex_Journal_Article_Dragdrop_Parts::build( $single_keys, $single_author, $single_fields );
$single_question = ja_dragdrop_question( $single_keys, $single_built, $single_author, $single_fields, array(
	'scenario'               => 'You are creating a reference for an article titled Urban Growth by Robert Adams, published in 2021 in the journal Cities, volume 45, issue 2, pages 110 to 118.',
	'reconstructedReference' => 'Adams, R. (2021) ‘Urban Growth’, Cities, 45(2), pp. 110–118.',
) );
check( '[8] a different valid single-author selection also passes', Citex_Generated_Validator::validate( $single_question )['status'], 'passed' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
