<?php
/**
 * Regression tests for this sprint's retry-loop fix: genuine
 * generation/API failures (network error, non-2xx response) now retry up
 * to Citex_AI_V2::MAX_GENERATION_ATTEMPTS instead of returning
 * immediately on the first failure (the old, backwards behaviour, where a
 * genuine API failure never retried but a mere quality difference did).
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-retry-on-api-failure.test.php` — not shipped in
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
function wp_json_encode( $v ) {
	return json_encode( $v );
}

$GLOBALS['__options']       = array();
$GLOBALS['__responses']     = array();
$GLOBALS['__request_count'] = 0;
function get_option( $key, $default = false ) {
	return $GLOBALS['__options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}
function wp_remote_post( $url, $args ) {
	$GLOBALS['__request_count']++;
	$responses = &$GLOBALS['__responses'];
	if ( empty( $responses ) ) {
		return new WP_Error( 'http_request_failed', 'No more canned responses.' );
	}
	return count( $responses ) > 1 ? array_shift( $responses ) : $responses[0];
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? 200;
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-book-mcq-variants.php';
require __DIR__ . '/../citex-tools/includes/class-citex-question-scenarios.php';
require __DIR__ . '/../citex-tools/includes/class-citex-question-diversity.php';
require __DIR__ . '/../citex-tools/includes/class-citex-generated-validator.php';
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
function check_true( $description, $actual ) {
	check( $description, $actual, true );
}

function reset_environment() {
	$GLOBALS['__options']       = array( Citex_AI_V2::OPTION_API_KEY => 'test-key' );
	$GLOBALS['__responses']     = array();
	$GLOBALS['__request_count'] = 0;
}

function gemini_response_for( $questions ) {
	$body = wp_json_encode( array( 'questions' => $questions ) );
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array( 'output_text' => $body ) ),
	);
}

function book_mcq_question( $id ) {
	return array(
		'questionId'      => $id,
		'authorFullNames' => array( 'John Smith' ),
		'year'            => '2020',
		'bookTitle'       => 'Understanding digital culture',
		'place'           => 'London',
		'publisher'       => 'SAGE Publications',
		'distractors'     => array(
			array( 'reference' => 'Wrong, X (2020) Understanding digital culture. London: SAGE Publications.', 'errorReason' => 'Missing comma and initials formatting.' ),
			array( 'reference' => 'Also Wrong, Y. 2020 Understanding digital culture. London: SAGE Publications.', 'errorReason' => 'Missing parentheses around the year.' ),
			array( 'reference' => 'Still Wrong, Z. (2020) Understanding digital culture. SAGE Publications: London.', 'errorReason' => 'Place and publisher swapped.' ),
		),
	);
}

$base_args = array(
	'quantity'    => 1,
	'starting_id' => 'BK01',
	'difficulty'  => 'medium',
	'type'        => 'mcq',
	'category'    => 'book',
);

// ---------------------------------------------------------------------
// 1. Sanity: MAX_GENERATION_ATTEMPTS exists and is exactly 2 (the sprint's
// explicit cap), and QUALITY_GATE_ENABLED is false by default.
// ---------------------------------------------------------------------
check( '[1] MAX_GENERATION_ATTEMPTS is exactly 2', Citex_AI_V2::MAX_GENERATION_ATTEMPTS, 2 );
check( '[1] QUALITY_GATE_ENABLED is false by default', Citex_AI_V2::QUALITY_GATE_ENABLED, false );
check( '[1] web_verification_enabled() defaults to false', Citex_AI_V2::web_verification_enabled(), false );

// ---------------------------------------------------------------------
// 2. A network-level failure (wp_remote_post returns WP_Error) on the
// first attempt, then success on the second, now succeeds overall — this
// used to return immediately on the very first failure with no retry.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__responses'] = array(
	new WP_Error( 'http_request_failed', 'Connection timed out.' ),
	gemini_response_for( array( book_mcq_question( 'BK01' ) ) ),
);
$retried = Citex_AI_V2::generate_questions( $base_args );
check( '[2] a network failure on attempt 1 is retried and attempt 2 succeeds', is_wp_error( $retried ), false );
check( '[2] exactly 2 requests were made', $GLOBALS['__request_count'], 2 );

// ---------------------------------------------------------------------
// 3. A non-2xx API error response on the first attempt, then success on
// the second, now succeeds overall — same fix, different failure mode.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__responses'] = array(
	array( 'response' => array( 'code' => 503 ), 'body' => wp_json_encode( array( 'error' => array( 'message' => 'Service unavailable.' ) ) ) ),
	gemini_response_for( array( book_mcq_question( 'BK01' ) ) ),
);
$retried_503 = Citex_AI_V2::generate_questions( $base_args );
check( '[3] a 503 API error on attempt 1 is retried and attempt 2 succeeds', is_wp_error( $retried_503 ), false );
check( '[3] exactly 2 requests were made', $GLOBALS['__request_count'], 2 );

// ---------------------------------------------------------------------
// 4. Invalid/unparseable JSON on the first attempt, then success on the
// second, now succeeds overall.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__responses'] = array(
	array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'output_text' => 'not valid json {' ) ) ),
	gemini_response_for( array( book_mcq_question( 'BK01' ) ) ),
);
$retried_json = Citex_AI_V2::generate_questions( $base_args );
check( '[4] invalid JSON on attempt 1 is retried and attempt 2 succeeds', is_wp_error( $retried_json ), false );
check( '[4] exactly 2 requests were made', $GLOBALS['__request_count'], 2 );

// ---------------------------------------------------------------------
// 5. A failure on every attempt exhausts exactly MAX_GENERATION_ATTEMPTS
// requests and returns one clear WP_Error — never an unbounded retry loop.
// ---------------------------------------------------------------------
reset_environment();
$GLOBALS['__responses'] = array(
	new WP_Error( 'http_request_failed', 'Connection timed out.' ),
	new WP_Error( 'http_request_failed', 'Connection timed out.' ),
);
$always_fails = Citex_AI_V2::generate_questions( $base_args );
check( '[5] exhausting every attempt returns a WP_Error', is_wp_error( $always_fails ), true );
check( '[5] error code is citex_ai_generation_failed', is_wp_error( $always_fails ) ? $always_fails->get_error_code() : null, 'citex_ai_generation_failed' );
check( '[5] exactly MAX_GENERATION_ATTEMPTS (2) requests were made, not more', $GLOBALS['__request_count'], Citex_AI_V2::MAX_GENERATION_ATTEMPTS );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
