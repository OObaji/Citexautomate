<?php
/**
 * Regression tests for the Auto-Generate feature — a real requested
 * feature: "I want about 100 questions total, I can only generate 20 at
 * a time, so I want the site to generate 20, populate, and repeat until
 * it reaches 100" — without the admin having to click "Generate &
 * Publish" by hand each time.
 *
 * Server side, this is Citex_Generator::ajax_auto_generate_batch()
 * (public entry, nonce/capability-checked, try/catch(Throwable)-wrapped —
 * see admin-action-feedback.test.php's own generic AJAX-handler
 * conventions check) → auto_generate_batch_body() (reads $_POST, clamps
 * quantity to the same 20-question "Generate & Publish" cap, always
 * publishes) → the SAME run_generate_batch() core the classic
 * Generate/Generate & Publish form uses via handle_mixed_generation() —
 * factored out this session precisely so the two entry points can never
 * drift apart on what one batch actually does. validate_generation_scope()
 * was likewise factored out of handle_generation()'s own inline checks so
 * both entry points reject an unsupported Style/Category/Question Focus
 * combination identically.
 *
 * This file exercises validate_generation_scope() and run_generate_batch()
 * directly (mirroring generator-scenario-grouping.test.php's own Gemini-
 * response-queue stub environment, since run_generate_batch() ultimately
 * calls the same generation machinery), plus auto_generate_batch_body()'s
 * own request parsing/clamping via source-wiring checks where a full
 * integration test would need the entire ACF/WordPress population stack
 * (already covered elsewhere — see populator-*.test.php).
 *
 * Repo-level only, run with plain
 * `php tests/generator-auto-generate-batch.test.php` — not shipped in
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
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
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
function _n( $single, $plural, $number, $d = '' ) {
	return 1 === (int) $number ? $single : $plural;
}
function absint( $v ) {
	return abs( intval( $v ) );
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}

$GLOBALS['__options']        = array();
$GLOBALS['__response_queue'] = array();
function get_option( $key, $default = false ) {
	return $GLOBALS['__options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}
function wp_remote_post( $url, $args ) {
	if ( empty( $GLOBALS['__response_queue'] ) ) {
		return array( 'response' => array( 'code' => 500 ), 'body' => '' );
	}
	return array_shift( $GLOBALS['__response_queue'] );
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? 200;
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}

class Citex_Populator {
	public static function get_population_coverage() {
		return array();
	}
}

// Minimal stand-in for Citex_Scanner — run_generate_batch() pulls
// collect_used_question_ids() through it, which just needs these 3
// methods to exist; ID-collision avoidance itself is covered elsewhere
// (see generator-used-ids-fresh-scan.test.php), not the concern here.
class Citex_Scanner {
	public static function sync_from_wordpress( $target = 'reference' ) {
		return new WP_Error( 'citex_no_reference_url', 'not configured' );
	}
	public static function get_last_scan( $target = 'reference' ) {
		return null;
	}
	public static function trashed_question_ids( $target = 'reference' ) {
		return array();
	}
}

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-website-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-dragdrop-parts.php';
require __DIR__ . '/../citex-tools/includes/class-citex-question-scenarios.php';
require __DIR__ . '/../citex-tools/includes/class-citex-question-diversity.php';
require __DIR__ . '/../citex-tools/includes/class-citex-generated-validator.php';
require __DIR__ . '/../citex-tools/includes/class-citex-ai-v2.php';
require __DIR__ . '/../citex-tools/includes/class-citex-generator.php';

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

function reset_environment() {
	$GLOBALS['__options']        = array( Citex_AI_V2::OPTION_API_KEY => 'test-key' );
	$GLOBALS['__response_queue'] = array();
}

function queue_response( $questions ) {
	$body = wp_json_encode( array( 'questions' => $questions ) );
	$GLOBALS['__response_queue'][] = array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array( 'output_text' => $body ) ),
	);
}

function book_mcq_question( $id, $author_names, $book_title ) {
	return array(
		'questionId'      => $id,
		'authorFullNames' => $author_names,
		'year'            => '2020',
		'bookTitle'       => $book_title,
		'place'           => 'London',
		'publisher'       => 'SAGE Publications',
		'distractors'     => array(
			array( 'reference' => 'Wrong, X (2020) ' . $book_title . '. London: SAGE Publications.', 'errorReason' => 'Missing comma and initials formatting.' ),
			array( 'reference' => 'Also Wrong, Y. 2020 ' . $book_title . '. London: SAGE Publications.', 'errorReason' => 'Missing parentheses around the year.' ),
			array( 'reference' => 'Still Wrong, Z. (2020) ' . $book_title . '. SAGE Publications: London.', 'errorReason' => 'Place and publisher swapped.' ),
		),
	);
}

function invoke_validate_generation_scope( $style, $category, $group ) {
	$reflection = new ReflectionMethod( 'Citex_Generator', 'validate_generation_scope' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $style, $category, $group );
}

function invoke_run_generate_batch( $generator, $category_label, $category, $quantity, $difficulty, $web_verify, $style, $group, $publish_immediately, $type_filter ) {
	$reflection = new ReflectionMethod( 'Citex_Generator', 'run_generate_batch' );
	$reflection->setAccessible( true );
	return $reflection->invoke( $generator, $category_label, $category, $quantity, $difficulty, $web_verify, $style, $group, $publish_immediately, $type_filter );
}

// ---------------------------------------------------------------------
// 1. validate_generation_scope() — the exact rule both handle_generation()
// (classic form) and ajax_auto_generate_batch() (Auto-Generate) share.
// ---------------------------------------------------------------------
check( '[1] harvard + book + referencelist is valid', invoke_validate_generation_scope( 'harvard', 'book', 'referencelist' ), true );
check( '[1] mla + website + intext is valid (MLA covers all 4 categories + both groups)', invoke_validate_generation_scope( 'mla', 'website', 'intext' ), true );
check( '[1] apa + edited_book + intext is valid', invoke_validate_generation_scope( 'apa', 'edited_book', 'intext' ), true );
check( '[1] an unrecognised style is rejected', is_wp_error( invoke_validate_generation_scope( 'chicago-manual', 'book', 'referencelist' ) ), true );
check( '[1] an unrecognised category is rejected', is_wp_error( invoke_validate_generation_scope( 'harvard', 'not_a_category', 'referencelist' ) ), true );
check( '[1] chicago + referencelist is valid (Phase 1: Reference List only)', invoke_validate_generation_scope( 'chicago', 'book', 'referencelist' ), true );
check( '[1] chicago + intext is rejected (In-Text Citation not yet supported)', is_wp_error( invoke_validate_generation_scope( 'chicago', 'book', 'intext' ) ), true );
check( '[1] mhra + referencelist is valid (Phase 1: Reference List only)', invoke_validate_generation_scope( 'mhra', 'website', 'referencelist' ), true );
check( '[1] mhra + intext is rejected (In-Text Citation not yet supported)', is_wp_error( invoke_validate_generation_scope( 'mhra', 'website', 'intext' ) ), true );

// ---------------------------------------------------------------------
// 2. run_generate_batch() with $publish_immediately = false — the plain
// "Generate" shape: generated questions, dragdrop/mcq counts, coverage
// and warnings populated, but `passed`/`populate` stay null (never
// validated/populated) since that only happens when publishing.
//
// "four_or_more_authors" picks its exact target author count
// DETERMINISTICALLY from a hash of the starting id it's given (see
// Citex_Question_Scenarios::target_count_for() — several possible
// counts, never a fixed 4), so the canned response for that scenario
// must supply exactly however many names the REAL starting id
// run_generate_batch() computes picks — hardcoding 4 names intermittently
// fails with "must have exactly N authors for this scenario" once that
// hash picks something other than 4. $starting_id here must match
// Citex_Generator::normalise_starting_id()'s own output for this exact
// style/group/type combination.
// ---------------------------------------------------------------------
reset_environment();
$starting_id_mcq        = Citex_Generator::normalise_starting_id( '', 'Book', 'harvard', 'referencelist', 'mcq' );
$four_or_more_entry     = Citex_Question_Scenarios::find( 'Book', 'MCQ', 'four_or_more_authors' );
$four_or_more_count     = Citex_Question_Scenarios::target_count_for( $four_or_more_entry, $starting_id_mcq );
$four_or_more_names     = array_slice(
	array( 'John Smith', 'Amy Jones', 'Tom Brown', 'Rita Williams', 'Sam Green', 'Priya Patel', 'Chen Wei', 'Maria Garcia' ),
	0,
	$four_or_more_count
);
queue_response( array( book_mcq_question( 'BK01', array( 'John Smith' ), 'Book One' ) ) );
queue_response( array( book_mcq_question( 'BK02', array( 'John Smith', 'Amy Jones' ), 'Book Two' ) ) );
queue_response( array( book_mcq_question( 'BK03', array( 'John Smith', 'Amy Jones', 'Tom Brown' ), 'Book Three' ) ) );
queue_response( array( book_mcq_question( 'BK04', $four_or_more_names, 'Book Four' ) ) );

$generate_only_generator = new Citex_Generator();
$generate_only_output    = invoke_run_generate_batch( $generate_only_generator, 'Book', 'book', 4, 'medium', false, 'harvard', 'referencelist', false, 'mcq' );
check( '[2] run_generate_batch() succeeds', is_wp_error( $generate_only_output ), false );
if ( ! is_wp_error( $generate_only_output ) ) {
	check( '[2] all 4 requested questions were generated', count( $generate_only_output['generated'] ), 4 );
	check( '[2] mcqCount reflects the type_filter=mcq override', $generate_only_output['mcqCount'], 4 );
	check( '[2] dragdropCount is 0 under type_filter=mcq', $generate_only_output['dragdropCount'], 0 );
	check( '[2] coverage is a non-empty array', is_array( $generate_only_output['coverage'] ) && ! empty( $generate_only_output['coverage'] ), true );
	check( '[2] passed stays null — plain Generate never validates', $generate_only_output['passed'], null );
	check( '[2] populate stays null — plain Generate never populates', $generate_only_output['populate'], null );
}

// ---------------------------------------------------------------------
// 3. run_generate_batch() still returns a WP_Error when generation itself
// totally fails (every scenario group exhausted) — the one case
// genuinely unrecoverable within a single batch, unchanged by this
// refactor (see generator-scenario-grouping.test.php's own check [5]).
// ---------------------------------------------------------------------
reset_environment();
for ( $i = 0; $i < 6; $i++ ) {
	$GLOBALS['__response_queue'][] = array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'output_text' => wp_json_encode( array( 'questions' => array() ) ) ) ) );
}
$total_failure_generator = new Citex_Generator();
$total_failure_output    = invoke_run_generate_batch( $total_failure_generator, 'Book', 'book', 2, 'medium', false, 'harvard', 'referencelist', true, 'mcq' );
check( '[3] run_generate_batch() still returns a WP_Error when generation itself totally fails', is_wp_error( $total_failure_output ), true );

// ---------------------------------------------------------------------
// 4. Source wiring: the constructor registers the AJAX hook, and
// auto_generate_batch_body() clamps its quantity to the SAME 20-question
// cap a manual "Generate & Publish" click uses, and always publishes
// (passes `true` through to run_generate_batch()) — Auto-Generate never
// raises the per-request cap, it only repeats it.
// ---------------------------------------------------------------------
$generator_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-generator.php' );
check(
	'[4] the constructor registers the Auto-Generate AJAX hook',
	false !== strpos( $generator_source, "add_action( 'wp_ajax_' . self::AJAX_AUTO_GENERATE_BATCH, array( \$this, 'ajax_auto_generate_batch' ) )" ),
	true
);
check(
	'[4] auto_generate_batch_body() clamps quantity to the same 20-question cap',
	false !== strpos( $generator_source, 'max( 1, min( 20, isset( $_POST[\'citex_quantity\']' ),
	true
);
check(
	'[4] auto_generate_batch_body() always publishes (passes true through to run_generate_batch())',
	false !== strpos( $generator_source, '$this->run_generate_batch( $category_label, $category, $quantity, $difficulty, $web_verify, $style, $group, true, $type_filter )' ),
	true
);
check(
	'[4] auto_generate_batch_body() rejects an unsupported scope before ever generating',
	false !== strpos( $generator_source, 'self::validate_generation_scope( $style, $category, $group )' ),
	true
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
