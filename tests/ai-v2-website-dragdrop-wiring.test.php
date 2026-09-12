<?php
/**
 * Regression tests for Citex_AI_V2's Website DragDrop generation path —
 * replaced entirely (per explicit user request) by the same "always the
 * full reference, exactly 3 random blanks" model Book DragDrop already uses
 * (Citex_Website_Dragdrop_Parts), removing the old fixed named-design
 * catalogue's use for DragDrop (website_dragdrop_shape() and friends remain
 * fully intact and untouched — Website MCQ still uses them).
 *
 * Gemini now supplies ONLY the canonical record
 * (authorType/authorFullName-or-organisationName/year/title/publisher/url)
 * and a non-leaking scenario — no questionParts, no fixedText, no
 * confusingWords, no exerciseDesign, and no accessed date (Citex computes
 * that itself). normalise_website_item() picks a selection per QUESTION
 * (seeded by that question's own id) and builds the entire question
 * deterministically via Citex_Website_Dragdrop_Parts::select_parts()/build().
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-website-dragdrop-wiring.test.php` — not shipped in
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
require __DIR__ . '/../citex-tools/includes/class-citex-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-edited-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-journal-article-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-website-dragdrop-parts.php';
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
	return $reflection->invoke( null, $questions, $ids, 'medium', array(), 'DragDrop', Citex_Reference_Rules::CATEGORY_WEBSITE, $target_count, '', '', 'author_year_title' );
}

// Publisher pool with a size that doesn't evenly divide the batch sizes
// this file builds, so cycling through it by index never lets one
// publisher dominate a batch — Citex_AI_V2::normalise()'s own
// publisher-diversity check (mirroring Book's place/publisher diversity
// check) would otherwise correctly reject these hand-written fixtures for
// being exactly as repetitive as the real bug it exists to catch.
function web_diverse_publisher( $i ) {
	$publishers = array( 'University of Leeds', 'University of Manchester', 'Open University', 'University of Bristol', 'King\'s College London', 'University of Edinburgh', 'Cardiff University' );
	return $publishers[ $i % count( $publishers ) ];
}
function web_dragdrop_item_individual( $full_name, $suffix, $publisher = 'University of Leeds' ) {
	return array(
		'scenario'       => 'You are referencing a webpage titled Guide ' . $suffix . ' by ' . $full_name . ', published by ' . $publisher . ' in 2022, available at https://www.leeds.ac.uk/guide-' . strtolower( $suffix ) . '.',
		'authorType'     => 'individual',
		'authorFullName' => $full_name,
		'year'           => '2022',
		'title'          => 'Guide ' . $suffix,
		'publisher'      => $publisher,
		'url'            => 'https://www.leeds.ac.uk/guide-' . strtolower( $suffix ),
	);
}
function web_dragdrop_item_organisation( $org_name, $suffix ) {
	return array(
		'scenario'         => 'You are referencing a webpage titled Guidance ' . $suffix . ' published by ' . $org_name . ' in 2021, available at https://www.who.int/guidance-' . strtolower( $suffix ) . '.',
		'authorType'       => 'organisation',
		'organisationName' => $org_name,
		'year'             => '2021',
		'title'            => 'Guidance ' . $suffix,
		'publisher'        => $org_name,
		'url'              => 'https://www.who.int/guidance-' . strtolower( $suffix ),
	);
}

// ---------------------------------------------------------------------
// 1. An individual-author question is fully Citex-authored: dragdropPartKeys,
// questionParts/fixedText/confusingWords exactly match
// Citex_Website_Dragdrop_Parts::build()'s own recomputation, accessedDate is
// Citex's own computed date, and it round-trips through
// Citex_Generated_Validator::validate(). The full reference (all 6 fields)
// is always shown.
// ---------------------------------------------------------------------
$single_item   = web_dragdrop_item_individual( 'Sarah Mitchell', 'One' );
$single_result = invoke_normalise( array( $single_item ), array( 'WR01' ) );
check( '[1] normalise() succeeds for an individual-author Website DragDrop item', is_wp_error( $single_result ), false );
if ( ! is_wp_error( $single_result ) ) {
	$candidate = $single_result[0];
	check( '[1] dragdropPartKeys is a non-empty array', ! empty( $candidate['dragdropPartKeys'] ), true );
	check( '[1] Question Parts count is exactly 3', count( $candidate['questionParts'] ), 3 );
	check( '[1] confusingWords count matches questionParts count', count( $candidate['confusingWords'] ), count( $candidate['questionParts'] ) );
	check( '[1] no exerciseDesign field at all (DragDrop no longer uses named designs)', array_key_exists( 'exerciseDesign', $candidate ), false );
	check( '[1] accessedDate is Citex\'s own computed date, never Gemini\'s', '' !== $candidate['accessedDate'], true );

	$expected_author = array( 'type' => 'individual', 'surname' => 'Mitchell', 'initials' => 'S.', 'fullName' => 'Sarah Mitchell' );
	$expected_fields = array( 'year' => '2022', 'title' => 'Guide One', 'publisher' => 'University of Leeds', 'url' => 'https://www.leeds.ac.uk/guide-one', 'accessedDate' => $candidate['accessedDate'] );
	$expected_built  = Citex_Website_Dragdrop_Parts::build( $candidate['dragdropPartKeys'], $expected_author, $expected_fields );
	check( '[1] Question Parts are exactly Citex_Website_Dragdrop_Parts::build()\'s own output', $candidate['questionParts'], $expected_built['parts'] );
	check( '[1] Fixed Text is exactly Citex_Website_Dragdrop_Parts::build()\'s own output', $candidate['fixedText'], $expected_built['fixedText'] );
	check( '[1] confusingWords is exactly Citex_Website_Dragdrop_Parts::build()\'s own output', $candidate['confusingWords'], $expected_built['confusingWords'] );

	$validated = Citex_Generated_Validator::validate( $candidate );
	check( '[1] the candidate passes Citex_Generated_Validator::validate()', $validated['status'], 'passed' );
}

// ---------------------------------------------------------------------
// 2. An organisation-author question also builds and validates correctly.
// ---------------------------------------------------------------------
$org_item   = web_dragdrop_item_organisation( 'World Health Organization', 'Two' );
$org_result = invoke_normalise( array( $org_item ), array( 'WR02' ) );
check( '[2] normalise() succeeds for an organisation-author Website DragDrop item', is_wp_error( $org_result ), false );
if ( ! is_wp_error( $org_result ) ) {
	$candidate = $org_result[0];
	check( '[2] organisationName is stored', $candidate['organisationName'], 'World Health Organization' );
	$validated = Citex_Generated_Validator::validate( $candidate );
	check( '[2] the candidate passes Citex_Generated_Validator::validate()', $validated['status'], 'passed' );
}

// ---------------------------------------------------------------------
// 3. A large batch of individual-author questions is not all the same
// selection — genuine per-question variety — and every candidate validates.
// ---------------------------------------------------------------------
$batch_items = array();
$batch_ids   = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$batch_items[] = web_dragdrop_item_individual( 'Sarah Mitchell', (string) $i, web_diverse_publisher( $i ) );
	$batch_ids[]   = 'WR' . str_pad( $i, 2, '0', STR_PAD_LEFT );
}
$batch_result = invoke_normalise( $batch_items, $batch_ids );
check( '[3] normalise() succeeds for a 30-question batch', is_wp_error( $batch_result ), false );
if ( ! is_wp_error( $batch_result ) ) {
	$selections_seen = array_unique( array_map( function ( $c ) { return implode( ',', $c['dragdropPartKeys'] ); }, $batch_result ) );
	check( '[3] a batch of 30 questions is not all the same selection', count( $selections_seen ) > 1, true );
	$all_valid = true;
	foreach ( $batch_result as $candidate ) {
		$validated = Citex_Generated_Validator::validate( $candidate );
		if ( 'passed' !== $validated['status'] ) {
			$all_valid = false;
		}
	}
	check( '[3] every candidate in the batch passes validation', $all_valid, true );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
