<?php
/**
 * Regression tests for Citex_AI_V2's Website MCQ generation path —
 * replaced entirely (per explicit user request for more question variety,
 * matching Book's own catalogue) by Citex_Website_Mcq_Variants, removing
 * the original "select the correct full reference, Gemini supplies 3
 * distractors" mechanic for Website specifically (Edited Book/Journal
 * Article remain untouched, still using their own Gemini-distractor MCQ
 * mechanic).
 *
 * Unlike the mechanic this replaces, Gemini supplies ONLY the canonical
 * website record (authorType/authorFullName-or-organisationName/year/
 * title/publisher/url) — no distractors, no error reasons.
 * normalise_website_mcq_variant_item() picks a variant per QUESTION
 * (seeded by that question's own id — every variant works for both author
 * types, so there is no eligibility filter the way Book's author-count
 * one is) and builds the entire question — stem, all 4 options, and the
 * answer — deterministically via Citex_Website_Mcq_Variants::build().
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-website-mcq-variant-wiring.test.php` — not shipped in
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
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', preg_replace( '/<[^>]*>/', '', (string) $v ) ) );
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

function invoke_normalise( $questions, $ids ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions, $ids, 'medium', array(), 'MCQ', Citex_Reference_Rules::CATEGORY_WEBSITE );
}

// Publisher pool used only where a batch is large enough to trip
// normalise()'s own publisher-diversity check (mirrors
// tests/ai-v2-book-mcq-variant-wiring.test.php's diverse_publisher()) —
// single-item calls just use the first one.
function diverse_publisher( $i ) {
	$publishers = array( 'SAGE', 'WHO', 'MIT', 'BBC', 'IBM', 'NASA', 'UN', 'NHS' );
	return $publishers[ $i % count( $publishers ) ];
}
function individual_item( $suffix, $publisher = 'SAGE' ) {
	return array(
		'authorType'     => 'individual',
		'authorFullName' => 'Tom Ross',
		'year'           => '2022',
		'title'          => "Guide $suffix",
		'publisher'      => $publisher,
		'url'            => 'https://www.sage.com',
	);
}
function organisation_item( $suffix, $publisher = 'WHO' ) {
	return array(
		'authorType'       => 'organisation',
		'organisationName' => 'British Council',
		'year'             => 'n.d.',
		'title'            => "About $suffix",
		'publisher'        => $publisher,
		'url'              => 'https://www.who.int',
	);
}

$known_variants = Citex_Website_Mcq_Variants::variants();

// ---------------------------------------------------------------------
// 1. An individual-author question is fully Citex-authored: mcqPattern,
// websiteMcqVariant, and an exact match against
// Citex_Website_Mcq_Variants::build()'s own output for that variant and
// this record's fields.
// ---------------------------------------------------------------------
$single_item = individual_item( 'One' );
$single_result = invoke_normalise( array( $single_item ), array( 'WR02' ) );
check( '[1] normalise() succeeds for an individual-author Website MCQ item', is_wp_error( $single_result ), false );
if ( ! is_wp_error( $single_result ) ) {
	$candidate = $single_result[0];
	check( '[1] mcqPattern is website_mcq_variant', $candidate['mcqPattern'], 'website_mcq_variant' );
	check( '[1] websiteMcqVariant is one of the known variants', in_array( $candidate['websiteMcqVariant'], $known_variants, true ), true );
	check( '[1] options has exactly 4 slots', count( $candidate['options'] ), 4 );
	check( '[1] option 4 is always blank', $candidate['options'][3], '' );
	check( '[1] the correct answer never appears in any option slot', in_array( $candidate['reconstructedReference'], array_slice( $candidate['options'], 0, 3 ), true ), false );

	$expected = Citex_Website_Mcq_Variants::build(
		$candidate['websiteMcqVariant'],
		array(
			'author'       => array( 'type' => 'individual', 'surname' => 'Ross', 'initials' => 'T.', 'fullName' => 'Tom Ross' ),
			'year'         => '2022',
			'title'        => 'Guide One',
			'publisher'    => 'SAGE',
			'url'          => 'https://www.sage.com',
			'accessedDate' => $candidate['accessedDate'],
		)
	);
	check( '[1] scenario is exactly the variant\'s own stem', $candidate['scenario'], $expected['stem'] );
	check( '[1] reconstructedReference is exactly the variant\'s own correct answer', $candidate['reconstructedReference'], $expected['correctAnswer'] );
	check( '[1] options 1-3 are exactly the variant\'s own wrong options', array_slice( $candidate['options'], 0, 3 ), $expected['wrongOptions'] );
	check( '[1] a non-empty hint is generated', '' !== trim( (string) $candidate['hint'] ), true );

	$validated = Citex_Generated_Validator::validate( $candidate );
	check( '[1] the candidate passes Citex_Generated_Validator::validate()', $validated['status'], 'passed' );
}

// ---------------------------------------------------------------------
// 2. An organisation-author question works the same way — every variant
// is compatible with both author types.
// ---------------------------------------------------------------------
$org_item = organisation_item( 'Us' );
$org_result = invoke_normalise( array( $org_item ), array( 'WR05' ) );
check( '[2] normalise() succeeds for an organisation-author Website MCQ item', is_wp_error( $org_result ), false );
if ( ! is_wp_error( $org_result ) ) {
	$candidate = $org_result[0];
	check( '[2] authorType is organisation', $candidate['authorType'], 'organisation' );
	check( '[2] organisationName is used exactly as given', $candidate['organisationName'], 'British Council' );
	$expected = Citex_Website_Mcq_Variants::build(
		$candidate['websiteMcqVariant'],
		array(
			'author'       => array( 'type' => 'organisation', 'name' => 'British Council' ),
			'year'         => 'n.d.',
			'title'        => 'About Us',
			'publisher'    => 'WHO',
			'url'          => 'https://www.who.int',
			'accessedDate' => $candidate['accessedDate'],
		)
	);
	check( '[2] reconstructedReference is exactly the variant\'s own correct answer', $candidate['reconstructedReference'], $expected['correctAnswer'] );
	$validated = Citex_Generated_Validator::validate( $candidate );
	check( '[2] the candidate passes Citex_Generated_Validator::validate()', $validated['status'], 'passed' );
}

// ---------------------------------------------------------------------
// 3. A large batch is not all the same variant — genuine per-question
// variety, the whole point of this request.
// ---------------------------------------------------------------------
$batch_items = array();
$batch_ids   = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$batch_items[] = individual_item( (string) $i, diverse_publisher( $i ) );
	$batch_ids[]   = 'WR' . str_pad( $i, 2, '0', STR_PAD_LEFT );
}
$batch_result = invoke_normalise( $batch_items, $batch_ids );
check( '[3] normalise() succeeds for a 30-question batch', is_wp_error( $batch_result ), false );
if ( ! is_wp_error( $batch_result ) ) {
	$variants_seen = array_unique( array_column( $batch_result, 'websiteMcqVariant' ) );
	check( '[3] a batch of 30 questions is not all the same variant', count( $variants_seen ) > 1, true );
	$all_known = true;
	foreach ( $batch_result as $candidate ) {
		if ( ! in_array( $candidate['websiteMcqVariant'], $known_variants, true ) ) {
			$all_known = false;
		}
	}
	check( '[3] every candidate\'s websiteMcqVariant is a known variant', $all_known, true );
	foreach ( $batch_result as $candidate ) {
		$validated = Citex_Generated_Validator::validate( $candidate );
		if ( 'passed' !== $validated['status'] ) {
			check( '[3] candidate ' . $candidate['questionId'] . ' (' . $candidate['websiteMcqVariant'] . ') passes validation', $validated['errors'], array() );
		}
	}
}

// ---------------------------------------------------------------------
// 4. Website MCQ candidates carry no exerciseDesign field at all (that
// field belongs only to Journal Article's own DragDrop mechanism).
// ---------------------------------------------------------------------
if ( ! is_wp_error( $single_result ) ) {
	check( '[4] Website MCQ candidates carry no exerciseDesign field at all', array_key_exists( 'exerciseDesign', $single_result[0] ), false );
}

// ---------------------------------------------------------------------
// 5. The "not_a_correct_reference" variant lands on a seed that produces
// it, and its inverted shape (correctAnswer is the flawed one,
// wrongOptions are genuinely valid) still validates cleanly.
// ---------------------------------------------------------------------
$not_correct_seed = null;
foreach ( range( 1, 50 ) as $i ) {
	if ( 'not_a_correct_reference' === Citex_Website_Mcq_Variants::variant_for( 'WR' . $i ) ) {
		$not_correct_seed = 'WR' . $i;
		break;
	}
}
check( '[5] a seed producing the not_a_correct_reference variant exists within 50 tries', null !== $not_correct_seed, true );
if ( null !== $not_correct_seed ) {
	$nc_result = invoke_normalise( array( individual_item( 'NC' ) ), array( $not_correct_seed ) );
	check( '[5] normalise() succeeds for the not_a_correct_reference variant', is_wp_error( $nc_result ), false );
	if ( ! is_wp_error( $nc_result ) ) {
		$nc = $nc_result[0];
		check( '[5] variant is indeed not_a_correct_reference', $nc['websiteMcqVariant'], 'not_a_correct_reference' );
		check( '[5] stem matches the requested wording', $nc['scenario'], 'Which of the following is NOT a correct reference for a website?' );
		$validated = Citex_Generated_Validator::validate( $nc );
		check( '[5] the inverted-shape candidate still passes validation', $validated['status'], 'passed' );
	}
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
