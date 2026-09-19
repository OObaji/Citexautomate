<?php
/**
 * Regression tests for Citex_Generated_Validator::validate_dragdrop()'s MLA
 * Website block — mirrors tests/generated-validator-mla-book-dragdrop.test.php's
 * own structure exactly, but for Citex_MLA_Website_Dragdrop_Parts: no
 * publisher field at all, an individual author carries `givenName` (never
 * `initials`), an organisation author renders as-is, and — the biggest
 * structural difference — an undated record's reconstructed reference
 * genuinely omits the year segment (never Harvard's "n.d." literal).
 *
 * Repo-level only, run with plain
 * `php tests/generated-validator-mla-website-dragdrop.test.php` — not
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

$individual = array( 'type' => 'individual', 'fullName' => 'Amy Ross', 'surname' => 'Ross', 'givenName' => 'Amy' );
$fields     = array( 'year' => '2021', 'title' => 'Home Page', 'url' => 'https://example.com', 'accessedDate' => '3 May 2023' );
$keys       = array( 'author', 'year', 'title' );
$built      = Citex_MLA_Website_Dragdrop_Parts::build( $keys, $individual, $fields );

function mla_web_dragdrop_question( $keys, $built, $author_type, $authors, $organisation_name, $fields, $overrides = array() ) {
	return array_merge(
		array(
			'source'                 => 'MLA',
			'group'                  => 'ReferenceList',
			'category'               => 'Website',
			'type'                   => 'DragDrop',
			'scenario'               => 'You are referencing a webpage titled Home Page by Amy Ross, published in 2021, at https://example.com.',
			'dragdropPartKeys'       => $keys,
			'fixedText'              => $built['fixedText'],
			'questionParts'          => $built['parts'],
			'confusingWords'         => $built['confusingWords'],
			'reconstructedReference' => 'Ross, Amy "Home Page." 2021, https://example.com. Accessed 3 May 2023.',
			'authorType'             => $author_type,
			'authors'                => $authors,
			'organisationName'       => $organisation_name,
			'year'                   => $fields['year'],
			'pageTitle'              => $fields['title'],
			'url'                    => $fields['url'],
			'accessedDate'           => $fields['accessedDate'],
		),
		$overrides
	);
}

// ---------------------------------------------------------------------
// 1. A correctly-built selection PASSES.
// ---------------------------------------------------------------------
$result = Citex_Generated_Validator::validate( mla_web_dragdrop_question( $keys, $built, 'individual', array( $individual ), '', $fields ) );
check( '[1] a correctly-built MLA Website DragDrop question passes', $result['status'], 'passed' );
check( '[1] the reconstructed value returned is the correct reference', $result['reconstructedReference'], 'Ross, Amy "Home Page." 2021, https://example.com. Accessed 3 May 2023.' );

// ---------------------------------------------------------------------
// 2. A tampered Question Part fails.
// ---------------------------------------------------------------------
$tampered_parts = mla_web_dragdrop_question( $keys, $built, 'individual', array( $individual ), '', $fields, array(
	'questionParts' => array( 'Wrong', $built['parts'][1], $built['parts'][2] ),
) );
$tampered_result = Citex_Generated_Validator::validate( $tampered_parts );
check( '[2] a tampered Question Part fails', $tampered_result['status'], 'failed' );
check( '[2] reports MLA_WEBSITE_DRAGDROP_PARTS_MISMATCH', has_error_code( $tampered_result, 'mla_website_dragdrop_parts_mismatch' ), true );

// ---------------------------------------------------------------------
// 3. A tampered Fixed Text fails.
// ---------------------------------------------------------------------
$tampered_fixed = mla_web_dragdrop_question( $keys, $built, 'individual', array( $individual ), '', $fields, array(
	'fixedText' => str_replace( 'example.com', 'other.com', $built['fixedText'] ),
) );
$tampered_fixed_result = Citex_Generated_Validator::validate( $tampered_fixed );
check( '[3] a tampered Fixed Text fails', $tampered_fixed_result['status'], 'failed' );
check( '[3] reports MLA_WEBSITE_DRAGDROP_FIXED_TEXT_MISMATCH', has_error_code( $tampered_fixed_result, 'mla_website_dragdrop_fixed_text_mismatch' ), true );

// ---------------------------------------------------------------------
// 4. A tampered confusing word fails.
// ---------------------------------------------------------------------
$tampered_confusing = mla_web_dragdrop_question( $keys, $built, 'individual', array( $individual ), '', $fields, array(
	'confusingWords' => array( 'Wrong', $built['confusingWords'][1], $built['confusingWords'][2] ),
) );
$tampered_confusing_result = Citex_Generated_Validator::validate( $tampered_confusing );
check( '[4] a tampered confusing word fails', $tampered_confusing_result['status'], 'failed' );
check( '[4] reports MLA_WEBSITE_DRAGDROP_CONFUSING_WORDS_MISMATCH', has_error_code( $tampered_confusing_result, 'mla_website_dragdrop_confusing_words_mismatch' ), true );

// ---------------------------------------------------------------------
// 5. CRITICAL — a missing dragdropPartKeys field fails.
// ---------------------------------------------------------------------
$missing_keys = mla_web_dragdrop_question( $keys, $built, 'individual', array( $individual ), '', $fields, array( 'dragdropPartKeys' => array() ) );
$missing_keys_result = Citex_Generated_Validator::validate( $missing_keys );
check( '[5] a missing dragdropPartKeys fails', $missing_keys_result['status'], 'failed' );
check( '[5] reports MLA_WEBSITE_DRAGDROP_PARTS_UNKNOWN', has_error_code( $missing_keys_result, 'mla_website_dragdrop_parts_unknown' ), true );

// ---------------------------------------------------------------------
// 6. A record with no canonical author/title data at all is unaffected
// by this check — mirrors Book's own identical skip condition.
// ---------------------------------------------------------------------
$no_canonical = Citex_Generated_Validator::validate( array(
	'source'                 => 'MLA',
	'group'                  => 'ReferenceList',
	'category'               => 'Website',
	'type'                   => 'DragDrop',
	'fixedText'              => '|| "||." ||, ||. Accessed ||.',
	'questionParts'          => array( 'Smith, John', 'Example Page', '2020', 'https://example.com', '1 Jan 2021' ),
	'confusingWords'         => array( 'Smith, J.', 'Sample Page', '2018', 'https://other.com', '2 Feb 2021' ),
	'reconstructedReference' => 'Smith, John "Example Page." 2020, https://example.com. Accessed 1 Jan 2021.',
) );
check( '[6] no MLA_WEBSITE_DRAGDROP_PARTS_UNKNOWN for a record with no canonical data at all', has_error_code( $no_canonical, 'mla_website_dragdrop_parts_unknown' ), false );

// ---------------------------------------------------------------------
// 7. An undated record also passes — the reconstructed reference
// genuinely omits the year segment entirely, never rendering "n.d.".
// ---------------------------------------------------------------------
$undated_fields = array( 'year' => '', 'title' => 'Home Page', 'url' => 'https://example.com', 'accessedDate' => '3 May 2023' );
$undated_keys   = array( 'author', 'title', 'accessed_date' );
$undated_built  = Citex_MLA_Website_Dragdrop_Parts::build( $undated_keys, $individual, $undated_fields );
$undated_question = mla_web_dragdrop_question( $undated_keys, $undated_built, 'individual', array( $individual ), '', $undated_fields, array(
	'scenario'               => 'You are referencing a webpage titled Home Page by Amy Ross, with no identifiable publication date, at https://example.com.',
	'reconstructedReference' => 'Ross, Amy "Home Page." https://example.com. Accessed 3 May 2023.',
) );
$undated_result = Citex_Generated_Validator::validate( $undated_question );
check( '[7] an undated record passes', $undated_result['status'], 'passed' );
check( '[7] the undated reconstructed reference never contains "n.d."', false !== strpos( $undated_result['reconstructedReference'], 'n.d.' ), false );

// ---------------------------------------------------------------------
// 8. An organisation author record also passes — the organisation name
// renders as-is.
// ---------------------------------------------------------------------
$organisation      = array( 'type' => 'organisation', 'name' => 'WHO' );
$org_fields        = array( 'year' => '2020', 'title' => 'Facts', 'url' => 'https://who.example.com', 'accessedDate' => '14 Jan 2024' );
$org_keys          = array( 'author', 'title' );
$org_built         = Citex_MLA_Website_Dragdrop_Parts::build( $org_keys, $organisation, $org_fields );
$org_question = mla_web_dragdrop_question( $org_keys, $org_built, 'organisation', array(), 'WHO', $org_fields, array(
	'scenario'               => 'You are referencing a webpage titled Facts by WHO, published in 2020, at https://who.example.com.',
	'reconstructedReference' => 'WHO "Facts." 2020, https://who.example.com. Accessed 14 Jan 2024.',
) );
check( '[8] an organisation author record passes', Citex_Generated_Validator::validate( $org_question )['status'], 'passed' );

// ---------------------------------------------------------------------
// 9. A Harvard-shaped reconstructed reference for an MLA-sourced record
// fails the MLA-specific format check — proving validate_reference_format()
// is genuinely style-aware, not silently reusing Harvard's own regex.
// ---------------------------------------------------------------------
$harvard_shaped_keys  = array( 'year' );
$harvard_shaped_built = Citex_MLA_Website_Dragdrop_Parts::build( $harvard_shaped_keys, $individual, $fields );
$harvard_shaped_question = mla_web_dragdrop_question( $harvard_shaped_keys, $harvard_shaped_built, 'individual', array( $individual ), '', $fields, array(
	'fixedText'              => 'Ross, A. (||) Home Page. Available from: <https://example.com> [Accessed 3 May 2023].',
	'questionParts'          => array( '2021' ),
	'reconstructedReference' => 'Ross, A. (2021) Home Page. Available from: <https://example.com> [Accessed 3 May 2023].',
) );
$harvard_shaped_result = Citex_Generated_Validator::validate( $harvard_shaped_question );
check( '[9] a Harvard-shaped reference for an MLA record fails', $harvard_shaped_result['status'], 'failed' );
check( '[9] reports MLA_WEBSITE_FORMAT_MISMATCH', has_error_code( $harvard_shaped_result, 'mla_website_format_mismatch' ), true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
