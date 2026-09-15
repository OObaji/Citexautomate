<?php
/**
 * Regression tests for Citex_AI_V2's MLA Website MCQ generation path —
 * mirrors tests/ai-v2-mla-book-mcq-variant-wiring.test.php's own structure
 * exactly, but for Citex_MLA_Website_Mcq_Variants: Gemini supplies only
 * the canonical record (authorType plus authorFullName/organisationName,
 * year, title, url — no publisher field at all), Citex supplies
 * accessedDate itself, normalise_mla_website_mcq_variant_item() picks a
 * variant per QUESTION (seeded by that question's own id, filtered to
 * variants compatible with the record's author type) and builds the
 * entire question deterministically via Citex_MLA_Website_Mcq_Variants::build().
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-mla-website-mcq-variant-wiring.test.php` — not shipped
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
function sanitize_text_field( $v ) {
	return trim( (string) $v );
}
function sanitize_textarea_field( $v ) {
	return trim( (string) $v );
}
function wp_generate_uuid4() {
	static $n = 0;
	return 'uuid-' . ( $n++ );
}
function __( $s, $d = '' ) {
	return $s;
}
function absint( $v ) {
	return abs( intval( $v ) );
}
function get_option( $key, $default = null ) {
	return $default;
}

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-website-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-edited-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-edited-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-journal-article-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-journal-article-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-website-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-mla-website-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-generated-validator.php';
require __DIR__ . '/../citex-tools/includes/class-citex-question-scenarios.php';
require __DIR__ . '/../citex-tools/includes/class-citex-question-diversity.php';
require __DIR__ . '/../citex-tools/includes/class-citex-ai-v2.php';

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

function invoke_normalise( $questions, $ids, $target_count = null ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions, $ids, 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_WEBSITE, $target_count, '', '', 'full_reference', 'mla' );
}

function mla_web_individual_mcq_item( $suffix, $year = '2021' ) {
	return array(
		'authorType'     => 'individual',
		'authorFullName' => 'Amy Ross',
		'year'           => $year,
		'title'          => "Page $suffix",
		'url'            => 'https://example.com/' . $suffix,
	);
}

function mla_web_organisation_mcq_item( $suffix, $year = '2021' ) {
	return array(
		'authorType'       => 'organisation',
		'organisationName' => 'World Health Organization',
		'year'             => $year,
		'title'            => "Facts $suffix",
		'url'              => 'https://who.example.com/' . $suffix,
	);
}

$known_variants = Citex_MLA_Website_Mcq_Variants::variants();

// ---------------------------------------------------------------------
// 1. A dated individual question is fully Citex-authored: source/
// category, mcqPattern, mlaWebsiteMcqVariant, and an exact match against
// Citex_MLA_Website_Mcq_Variants::build()'s own output.
// ---------------------------------------------------------------------
$single_item   = mla_web_individual_mcq_item( 'One' );
$single_result = invoke_normalise( array( $single_item ), array( 'MW01' ) );
check( '[1] normalise() succeeds for a dated individual MLA Website MCQ item', is_wp_error( $single_result ), false );
if ( ! is_wp_error( $single_result ) ) {
	$candidate = $single_result[0];
	check( '[1] source is MLA', $candidate['source'], 'MLA' );
	check( '[1] category is Website', $candidate['category'], 'Website' );
	check( '[1] mcqPattern is mla_website_mcq_variant', $candidate['mcqPattern'], 'mla_website_mcq_variant' );
	check( '[1] mlaWebsiteMcqVariant is one of the 7 known variants', in_array( $candidate['mlaWebsiteMcqVariant'], $known_variants, true ), true );
	check( '[1] no publisher field is carried at all', array_key_exists( 'publisher', $candidate ), false );
	check( '[1] options has exactly 4 slots', count( $candidate['options'] ), 4 );
	check( '[1] option 4 is always blank', $candidate['options'][3], '' );
	check( '[1] the correct answer never appears in any option slot', in_array( $candidate['reconstructedReference'], array_slice( $candidate['options'], 0, 3 ), true ), false );

	$expected_author = array( 'type' => 'individual', 'fullName' => 'Amy Ross', 'surname' => 'Ross', 'givenName' => 'Amy' );
	$expected = Citex_MLA_Website_Mcq_Variants::build(
		$candidate['mlaWebsiteMcqVariant'],
		array( 'author' => $expected_author, 'year' => '2021', 'title' => 'Page One', 'url' => 'https://example.com/One', 'accessedDate' => $candidate['accessedDate'] )
	);
	check( '[1] scenario is exactly the variant\'s own stem', $candidate['scenario'], $expected['stem'] );
	check( '[1] reconstructedReference is exactly the variant\'s own correct answer', $candidate['reconstructedReference'], $expected['correctAnswer'] );
	check( '[1] options 1-3 are exactly the variant\'s own wrong options', array_slice( $candidate['options'], 0, 3 ), $expected['wrongOptions'] );
	check( '[1] a non-empty hint is generated', '' !== trim( (string) $candidate['hint'] ), true );

	$validated = Citex_Generated_Validator::validate( $candidate );
	check( '[1] the candidate passes Citex_Generated_Validator::validate()', $validated['status'], 'passed' );
}

// ---------------------------------------------------------------------
// 2. An undated individual question is also fully valid — never gated,
// never producing "n.d." anywhere.
// ---------------------------------------------------------------------
$undated_item   = mla_web_individual_mcq_item( 'Two', '' );
$undated_result = invoke_normalise( array( $undated_item ), array( 'MW02' ) );
check( '[2] normalise() succeeds for an undated individual MLA Website MCQ item', is_wp_error( $undated_result ), false );
if ( ! is_wp_error( $undated_result ) ) {
	$candidate = $undated_result[0];
	check( '[2] reconstructedReference never contains "n.d."', false !== strpos( $candidate['reconstructedReference'], 'n.d.' ), false );
	$validated = Citex_Generated_Validator::validate( $candidate );
	check( '[2] the candidate passes Citex_Generated_Validator::validate()', $validated['status'], 'passed' );
}

// ---------------------------------------------------------------------
// 3. An organisation author question is valid, and "author_name_format"
// (individual-only) is never assigned to it.
// ---------------------------------------------------------------------
$org_item   = mla_web_organisation_mcq_item( 'Three' );
$org_result = invoke_normalise( array( $org_item ), array( 'MW03' ) );
check( '[3] normalise() succeeds for an organisation MLA Website MCQ item', is_wp_error( $org_result ), false );
if ( ! is_wp_error( $org_result ) ) {
	$candidate = $org_result[0];
	check( '[3] mlaWebsiteMcqVariant is never author_name_format for an organisation', $candidate['mlaWebsiteMcqVariant'], $candidate['mlaWebsiteMcqVariant'] !== 'author_name_format' ? $candidate['mlaWebsiteMcqVariant'] : '(collision)' );
	$validated = Citex_Generated_Validator::validate( $candidate );
	check( '[3] the candidate passes Citex_Generated_Validator::validate()', $validated['status'], 'passed' );
}

// ---------------------------------------------------------------------
// 4. A large batch of organisation questions never assigns
// author_name_format to any of them, across a wide sweep.
// ---------------------------------------------------------------------
$org_batch_items = array();
$org_batch_ids   = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$org_batch_items[] = mla_web_organisation_mcq_item( (string) $i, (string) ( 2000 + $i % 20 ) );
	$org_batch_ids[]   = 'MW' . str_pad( $i, 2, '0', STR_PAD_LEFT );
}
$org_batch_result = invoke_normalise( $org_batch_items, $org_batch_ids );
check( '[4] normalise() succeeds for a 30-question organisation batch', is_wp_error( $org_batch_result ), false );
if ( ! is_wp_error( $org_batch_result ) ) {
	$has_individual_only_variant = false;
	foreach ( $org_batch_result as $candidate ) {
		if ( 'author_name_format' === $candidate['mlaWebsiteMcqVariant'] ) {
			$has_individual_only_variant = true;
		}
		$validated = Citex_Generated_Validator::validate( $candidate );
		if ( 'passed' !== $validated['status'] ) {
			check( '[4] every candidate in the batch passes validation: ' . $candidate['questionId'], $validated['status'], 'passed' );
		}
	}
	check( '[4] author_name_format is never assigned across the organisation batch', $has_individual_only_variant, false );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
