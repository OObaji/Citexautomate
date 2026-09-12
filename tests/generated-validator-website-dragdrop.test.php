<?php
/**
 * Regression tests for Citex_Generated_Validator::validate_dragdrop()'s
 * Website-only block — the exact-match check backing
 * Citex_Website_Dragdrop_Parts' dynamic exactly-3-part selection (replaces
 * the fixed named-design catalogue). Exercises the validator directly
 * against hand-built candidate fixtures, the same style as
 * tests/generated-validator-book-dragdrop.test.php.
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-website-dragdrop.test.php` — not shipped
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
require __DIR__ . '/../citex-tools/includes/class-citex-website-dragdrop-parts.php';
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

$author = array( 'type' => 'individual', 'surname' => 'Mitchell', 'initials' => 'S.', 'fullName' => 'Sarah Mitchell' );
$fields = array( 'year' => '2022', 'title' => 'Study skills guide', 'publisher' => 'University of Leeds', 'url' => 'https://www.leeds.ac.uk/study-skills', 'accessedDate' => '12 September 2026' );
$keys   = array( 'author', 'year', 'url' );
$built  = Citex_Website_Dragdrop_Parts::build( $keys, $author, $fields );

function web_dragdrop_question( $keys, $built, $author, $fields, $overrides = array() ) {
	$authors = 'individual' === $author['type']
		? array( array( 'fullName' => $author['fullName'], 'surname' => $author['surname'], 'initials' => $author['initials'] ) )
		: array();
	return array_merge(
		array(
			'source'                 => 'Harvard',
			'group'                  => 'ReferenceList',
			'category'               => 'Website',
			'type'                   => 'DragDrop',
			'scenario'               => 'You are referencing a webpage titled Study skills guide by Sarah Mitchell, published by University of Leeds in 2022, available at https://www.leeds.ac.uk/study-skills.',
			'dragdropPartKeys'       => $keys,
			'fixedText'              => $built['fixedText'],
			'questionParts'          => $built['parts'],
			'confusingWords'         => $built['confusingWords'],
			'reconstructedReference' => 'Mitchell, S. (2022) Study skills guide [online]. University of Leeds. Available from: <https://www.leeds.ac.uk/study-skills> [accessed 12 September 2026].',
			'authorType'             => $author['type'],
			'authors'                => $authors,
			'organisationName'       => 'organisation' === $author['type'] ? $author['name'] : '',
			'year'                   => $fields['year'],
			'title'                  => $fields['title'],
			'publisher'              => $fields['publisher'],
			'url'                    => $fields['url'],
			'accessedDate'           => $fields['accessedDate'],
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A correctly-built selection PASSES.
// ---------------------------------------------------------------------
$result = Citex_Generated_Validator::validate( web_dragdrop_question( $keys, $built, $author, $fields ) );
check( '[1] a correctly-built Website DragDrop question passes', $result['status'], 'passed' );
check( '[1] the reconstructed value is the correct reference', $result['reconstructedReference'], 'Mitchell, S. (2022) Study skills guide [online]. University of Leeds. Available from: <https://www.leeds.ac.uk/study-skills> [accessed 12 September 2026].' );

// ---------------------------------------------------------------------
// 2. A tampered Question Part fails.
// ---------------------------------------------------------------------
$tampered_parts = web_dragdrop_question( $keys, $built, $author, $fields, array(
	'questionParts' => array( 'Wrong', $built['parts'][1], $built['parts'][2] ),
) );
$tampered_result = Citex_Generated_Validator::validate( $tampered_parts );
check( '[2] a tampered Question Part fails', $tampered_result['status'], 'failed' );
check( '[2] reports WEBSITE_DRAGDROP_PARTS_MISMATCH', has_error_code( $tampered_result, 'website_dragdrop_parts_mismatch' ), true );

// ---------------------------------------------------------------------
// 3. A tampered Fixed Text fails.
// ---------------------------------------------------------------------
$tampered_fixed = web_dragdrop_question( $keys, $built, $author, $fields, array(
	'fixedText' => str_replace( 'University of Leeds', 'Open University', $built['fixedText'] ),
) );
$tampered_fixed_result = Citex_Generated_Validator::validate( $tampered_fixed );
check( '[3] a tampered Fixed Text fails', $tampered_fixed_result['status'], 'failed' );
check( '[3] reports WEBSITE_DRAGDROP_FIXED_TEXT_MISMATCH', has_error_code( $tampered_fixed_result, 'website_dragdrop_fixed_text_mismatch' ), true );

// ---------------------------------------------------------------------
// 4. A tampered confusing word fails.
// ---------------------------------------------------------------------
$tampered_confusing = web_dragdrop_question( $keys, $built, $author, $fields, array(
	'confusingWords' => array( 'Wrong', $built['confusingWords'][1], $built['confusingWords'][2] ),
) );
$tampered_confusing_result = Citex_Generated_Validator::validate( $tampered_confusing );
check( '[4] a tampered confusing word fails', $tampered_confusing_result['status'], 'failed' );
check( '[4] reports WEBSITE_DRAGDROP_CONFUSING_WORDS_MISMATCH', has_error_code( $tampered_confusing_result, 'website_dragdrop_confusing_words_mismatch' ), true );

// ---------------------------------------------------------------------
// 5. CRITICAL — a missing dragdropPartKeys field fails.
// ---------------------------------------------------------------------
$missing_keys = web_dragdrop_question( $keys, $built, $author, $fields, array( 'dragdropPartKeys' => array() ) );
$missing_keys_result = Citex_Generated_Validator::validate( $missing_keys );
check( '[5] a missing dragdropPartKeys fails', $missing_keys_result['status'], 'failed' );
check( '[5] reports WEBSITE_DRAGDROP_PARTS_UNKNOWN', has_error_code( $missing_keys_result, 'website_dragdrop_parts_unknown' ), true );

// ---------------------------------------------------------------------
// 6. A record with no canonical author/title data at all (e.g. externally
// imported) is unaffected by this check — mirrors Book's own identical
// skip condition.
// ---------------------------------------------------------------------
$no_canonical = Citex_Generated_Validator::validate( array(
	'source'                 => 'Harvard',
	'group'                  => 'ReferenceList',
	'category'               => 'Website',
	'type'                   => 'DragDrop',
	'authorType'             => '',
	'fixedText'              => '| (||) Example Guide [online]. Example Press. Available from: <https://example.com> [accessed ||].',
	'questionParts'          => array( 'Smith, J.', '2020', '1 January 2021' ),
	'confusingWords'         => array( 'Brown, K', '2018', '1/1/2021' ),
	'reconstructedReference' => 'Smith, J. (2020) Example Guide [online]. Example Press. Available from: <https://example.com> [accessed 1 January 2021].',
) );
check( '[6] no WEBSITE_DRAGDROP_PARTS_UNKNOWN for a record with no canonical data at all', has_error_code( $no_canonical, 'website_dragdrop_parts_unknown' ), false );

// ---------------------------------------------------------------------
// 7. A different, valid organisation-author selection also passes —
// proving the check adapts to whichever selection was actually recorded.
// ---------------------------------------------------------------------
$org_author  = array( 'type' => 'organisation', 'name' => 'World Health Organization' );
$org_fields  = array( 'year' => 'n.d.', 'title' => 'Guidance on nutrition', 'publisher' => 'WHO', 'url' => 'https://www.who.int/nutrition', 'accessedDate' => '1 January 2026' );
$org_keys    = array( 'author', 'title', 'accessed_date' );
$org_built   = Citex_Website_Dragdrop_Parts::build( $org_keys, $org_author, $org_fields );
$org_question = web_dragdrop_question( $org_keys, $org_built, $org_author, $org_fields, array(
	'scenario'               => 'You are referencing a webpage titled Guidance on nutrition published by World Health Organization, available at https://www.who.int/nutrition.',
	'reconstructedReference' => 'World Health Organization (n.d.) Guidance on nutrition [online]. WHO. Available from: <https://www.who.int/nutrition> [accessed 1 January 2026].',
) );
check( '[7] a different valid organisation-author selection also passes', Citex_Generated_Validator::validate( $org_question )['status'], 'passed' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
