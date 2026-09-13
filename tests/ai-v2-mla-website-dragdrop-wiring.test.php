<?php
/**
 * Regression tests for Citex_AI_V2's MLA Website DragDrop generation path
 * — mirrors tests/ai-v2-mla-book-dragdrop-wiring.test.php's own structure,
 * but via Citex_MLA_Website_Dragdrop_Parts: a single individual-or-
 * organisation author (never a joined list), no publisher field, and —
 * the biggest structural difference — an OPTIONAL year with no "n.d."
 * convention at all (the built reference and DragDrop shape genuinely
 * omit the year segment when undated).
 *
 * Gemini supplies ONLY the canonical website record (authorType plus
 * authorFullName/organisationName, year, title, url) and a non-leaking
 * scenario — Citex supplies accessedDate itself, deterministically.
 * normalise_mla_website_dragdrop_item() picks a selection per QUESTION
 * (seeded by that question's own id) and builds the entire question
 * deterministically via Citex_MLA_Website_Dragdrop_Parts::select_parts()/build().
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-mla-website-dragdrop-wiring.test.php` — not shipped in
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
	return $reflection->invoke( null, $questions, $ids, 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_WEBSITE, $target_count, '', '', 'full_reference', 'mla' );
}

function mla_web_individual_item( $suffix, $year = '2019' ) {
	$url = 'https://example.com/' . $suffix;
	return array(
		'scenario'         => "You are referencing a webpage titled Page $suffix by Amy Ross" . ( '' !== $year ? ", published in $year" : ', with no identifiable publication date' ) . ", at $url.",
		'authorType'       => 'individual',
		'authorFullName'   => 'Amy Ross',
		'year'             => $year,
		'title'            => "Page $suffix",
		'url'              => $url,
	);
}

function mla_web_organisation_item( $suffix, $year = '2019' ) {
	$url = 'https://who.example.com/' . $suffix;
	return array(
		'scenario'          => "You are referencing a webpage titled Facts $suffix by the World Health Organization" . ( '' !== $year ? ", published in $year" : ', with no identifiable publication date' ) . ", at $url.",
		'authorType'        => 'organisation',
		'organisationName'  => 'World Health Organization',
		'year'              => $year,
		'title'             => "Facts $suffix",
		'url'               => $url,
	);
}

// ---------------------------------------------------------------------
// 1. A dated individual question is fully Citex-authored: source/
// category, no publisher field at all, author carries givenName not
// initials, dragdropPartKeys/questionParts/fixedText/confusingWords
// exactly match Citex_MLA_Website_Dragdrop_Parts::build()'s own
// recomputation, and it round-trips through Citex_Generated_Validator::validate().
// ---------------------------------------------------------------------
$single_item   = mla_web_individual_item( 'One' );
$single_result = invoke_normalise( array( $single_item ), array( 'MW01' ) );
check( '[1] normalise() succeeds for a dated individual MLA Website DragDrop item', is_wp_error( $single_result ), false );
if ( ! is_wp_error( $single_result ) ) {
	$candidate = $single_result[0];
	check( '[1] source is MLA', $candidate['source'], 'MLA' );
	check( '[1] category is Website', $candidate['category'], 'Website' );
	check( '[1] no publisher field is carried at all', array_key_exists( 'publisher', $candidate ), false );
	check( '[1] author carries givenName, not initials', $candidate['authors'][0]['givenName'], 'Amy' );
	check( '[1] accessedDate is populated by Citex itself', '' !== trim( (string) $candidate['accessedDate'] ), true );
	check( '[1] dragdropPartKeys is a non-empty array', ! empty( $candidate['dragdropPartKeys'] ), true );
	check( '[1] Question Parts count is exactly 3', count( $candidate['questionParts'] ), 3 );
	check( '[1] confusingWords count matches questionParts count', count( $candidate['confusingWords'] ), count( $candidate['questionParts'] ) );

	$expected_author = array( 'type' => 'individual', 'fullName' => 'Amy Ross', 'surname' => 'Ross', 'givenName' => 'Amy' );
	$expected_fields = array( 'year' => '2019', 'title' => 'Page One', 'url' => 'https://example.com/One', 'accessedDate' => $candidate['accessedDate'] );
	$expected_built  = Citex_MLA_Website_Dragdrop_Parts::build( $candidate['dragdropPartKeys'], $expected_author, $expected_fields );
	check( '[1] Question Parts are exactly Citex_MLA_Website_Dragdrop_Parts::build()\'s own output', $candidate['questionParts'], $expected_built['parts'] );
	check( '[1] Fixed Text is exactly Citex_MLA_Website_Dragdrop_Parts::build()\'s own output', $candidate['fixedText'], $expected_built['fixedText'] );
	check( '[1] confusingWords is exactly Citex_MLA_Website_Dragdrop_Parts::build()\'s own output', $candidate['confusingWords'], $expected_built['confusingWords'] );

	$validated = Citex_Generated_Validator::validate( $candidate );
	check( '[1] the candidate passes Citex_Generated_Validator::validate()', $validated['status'], 'passed' );
}

// ---------------------------------------------------------------------
// 2. An undated individual question omits the year segment entirely —
// never renders "n.d." — and still validates.
// ---------------------------------------------------------------------
$undated_item   = mla_web_individual_item( 'Two', '' );
$undated_result = invoke_normalise( array( $undated_item ), array( 'MW02' ) );
check( '[2] normalise() succeeds for an undated individual MLA Website DragDrop item', is_wp_error( $undated_result ), false );
if ( ! is_wp_error( $undated_result ) ) {
	$candidate = $undated_result[0];
	check( '[2] reconstructedReference never contains "n.d."', false !== strpos( $candidate['reconstructedReference'], 'n.d.' ), false );
	check( '[2] Fixed Text never contains "n.d."', false !== strpos( $candidate['fixedText'], 'n.d.' ), false );
	check( '[2] dragdropPartKeys never contains "year" (no year field exists to draw)', in_array( 'year', $candidate['dragdropPartKeys'], true ), false );

	$validated = Citex_Generated_Validator::validate( $candidate );
	check( '[2] the candidate passes Citex_Generated_Validator::validate()', $validated['status'], 'passed' );
}

// ---------------------------------------------------------------------
// 3. An organisation author renders the organisation name as-is, never
// "et al." (there is only ever one entity) — and validates.
// ---------------------------------------------------------------------
$org_item   = mla_web_organisation_item( 'Three' );
$org_result = invoke_normalise( array( $org_item ), array( 'MW03' ) );
check( '[3] normalise() succeeds for a dated organisation MLA Website DragDrop item', is_wp_error( $org_result ), false );
if ( ! is_wp_error( $org_result ) ) {
	$candidate = $org_result[0];
	check( '[3] authorType is organisation', $candidate['authorType'], 'organisation' );
	check( '[3] organisationName is carried', $candidate['organisationName'], 'World Health Organization' );
	check( '[3] authors is empty for an organisation author', $candidate['authors'], array() );
	check( '[3] reconstructedReference never contains "et al."', false !== strpos( $candidate['reconstructedReference'], 'et al.' ), false );

	$validated = Citex_Generated_Validator::validate( $candidate );
	check( '[3] the candidate passes Citex_Generated_Validator::validate()', $validated['status'], 'passed' );
}

// ---------------------------------------------------------------------
// 4. A large batch of dated individual questions is not all the same
// selection — genuine per-question variety.
// ---------------------------------------------------------------------
$batch_items = array();
$batch_ids   = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$batch_items[] = mla_web_individual_item( (string) $i, (string) ( 2000 + $i % 20 ) );
	$batch_ids[]   = 'MW' . str_pad( $i, 2, '0', STR_PAD_LEFT );
}
$batch_result = invoke_normalise( $batch_items, $batch_ids );
check( '[4] normalise() succeeds for a 30-question batch', is_wp_error( $batch_result ), false );
if ( ! is_wp_error( $batch_result ) ) {
	$selections_seen = array_unique( array_map( function ( $c ) { return implode( ',', $c['dragdropPartKeys'] ); }, $batch_result ) );
	check( '[4] a batch of 30 questions is not all the same selection', count( $selections_seen ) > 1, true );
	foreach ( $batch_result as $candidate ) {
		$validated = Citex_Generated_Validator::validate( $candidate );
		if ( 'passed' !== $validated['status'] ) {
			check( '[4] every candidate in the batch passes validation: ' . $candidate['questionId'], $validated['status'], 'passed' );
		}
	}
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
