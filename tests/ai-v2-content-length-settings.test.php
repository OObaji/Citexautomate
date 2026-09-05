<?php
/**
 * Regression tests for the admin-configurable author-name-length and
 * title-length settings (AI Settings page) — Citex_AI_V2::max_author_words()/
 * max_title_words() (defaults + 1-20 clamping), save_settings()'s new
 * optional parameters, and content_realism_guidance()'s interpolation of
 * the configured values into the Gemini prompt text. The actual word-count
 * enforcement mechanics (Citex_Reference_Rules::part_suitability()'s
 * $max_words parameter) are covered directly against the pure rules class,
 * with no WordPress stub needed for that half.
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-content-length-settings.test.php` — not shipped in
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

$GLOBALS['__options'] = array();
function get_option( $key, $default = false ) {
	return $GLOBALS['__options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
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

function invoke_realism_guidance() {
	$ref = new ReflectionMethod( 'Citex_AI_V2', 'content_realism_guidance' );
	$ref->setAccessible( true );
	return $ref->invoke( null );
}

// ---------------------------------------------------------------------
// 1. Defaults, with nothing saved yet.
// ---------------------------------------------------------------------
$GLOBALS['__options'] = array();
check( '[1] default max_author_words() is 4', Citex_AI_V2::max_author_words(), 4 );
check( '[1] default max_title_words() is 12', Citex_AI_V2::max_title_words(), 12 );
check( '[1] DEFAULT_MAX_AUTHOR_WORDS constant is 4', Citex_AI_V2::DEFAULT_MAX_AUTHOR_WORDS, 4 );
check( '[1] DEFAULT_MAX_TITLE_WORDS constant is 12', Citex_AI_V2::DEFAULT_MAX_TITLE_WORDS, 12 );

// ---------------------------------------------------------------------
// 2. save_settings() writes the two new options, clamped to 1-20.
// ---------------------------------------------------------------------
$GLOBALS['__options'] = array();
Citex_AI_V2::save_settings( '', 'gemini-3.7-flash', false, 6, 15 );
check( '[2] save_settings() with in-range values: max_author_words() reflects the saved value', Citex_AI_V2::max_author_words(), 6 );
check( '[2] save_settings() with in-range values: max_title_words() reflects the saved value', Citex_AI_V2::max_title_words(), 15 );

Citex_AI_V2::save_settings( '', 'gemini-3.7-flash', false, 0, 999 );
check( '[2] save_settings() clamps a too-low author-word value up to 1', Citex_AI_V2::max_author_words(), 1 );
check( '[2] save_settings() clamps a too-high title-word value down to 20', Citex_AI_V2::max_title_words(), 20 );

// ---------------------------------------------------------------------
// 3. save_settings() leaves the options untouched when null is passed
// (e.g. a caller that only wants to change the API key/model/web-verify).
// ---------------------------------------------------------------------
$GLOBALS['__options'] = array(
	Citex_AI_V2::OPTION_MAX_AUTHOR_WORDS => 7,
	Citex_AI_V2::OPTION_MAX_TITLE_WORDS  => 9,
);
Citex_AI_V2::save_settings( '', 'gemini-3.7-flash', false );
check( '[3] save_settings() without the new params leaves max_author_words() untouched', Citex_AI_V2::max_author_words(), 7 );
check( '[3] save_settings() without the new params leaves max_title_words() untouched', Citex_AI_V2::max_title_words(), 9 );

// ---------------------------------------------------------------------
// 4. content_realism_guidance() interpolates the currently configured
// values directly into the Gemini prompt instructions.
// ---------------------------------------------------------------------
$GLOBALS['__options'] = array(
	Citex_AI_V2::OPTION_MAX_AUTHOR_WORDS => 3,
	Citex_AI_V2::OPTION_MAX_TITLE_WORDS  => 8,
);
$guidance = invoke_realism_guidance();
check( '[4] the guidance names the configured author-word limit', false !== strpos( $guidance, 'EXACTLY 3 word' ), true );
check( '[4] the guidance names the configured title-word limit', false !== strpos( $guidance, 'NO MORE THAN 8 word' ), true );
check( '[4] the guidance still requires the publisher/journal to stay real', false !== stripos( $guidance, 'must always be a REAL' ), true );
check( '[4] the guidance still allows invented author names/titles for learning purposes', false !== stripos( $guidance, 'do NOT need to be real' ), true );

$GLOBALS['__options'] = array(
	Citex_AI_V2::OPTION_MAX_AUTHOR_WORDS => 5,
	Citex_AI_V2::OPTION_MAX_TITLE_WORDS  => 14,
);
$guidance2 = invoke_realism_guidance();
check( '[4] changing the saved options changes the interpolated author-word limit', false !== strpos( $guidance2, 'EXACTLY 5 word' ), true );
check( '[4] changing the saved options changes the interpolated title-word limit', false !== strpos( $guidance2, 'NO MORE THAN 14 word' ), true );

// ---------------------------------------------------------------------
// 5. Citex_Reference_Rules::part_suitability()'s $max_words parameter —
// the pure mechanism the admin-configured limit ultimately feeds into.
// Defaults to 20 (unchanged) when the caller passes nothing.
// ---------------------------------------------------------------------
check(
	'[5] part_suitability() default max_words (20): a 15-word part is fine',
	Citex_Reference_Rules::part_suitability( array( trim( str_repeat( 'a ', 15 ) ) ) ),
	null
);
check(
	'[5] part_suitability() default max_words (20): a 25-word part is rejected',
	null === Citex_Reference_Rules::part_suitability( array( trim( str_repeat( 'a ', 25 ) ) ) ),
	false
);
check(
	'[5] part_suitability() with a tighter configured max_words (4): a 5-word part is rejected',
	null === Citex_Reference_Rules::part_suitability( array( 'one two three four five' ), 4 ),
	false
);
check(
	'[5] part_suitability() with a tighter configured max_words (4): a 4-word part is fine',
	Citex_Reference_Rules::part_suitability( array( 'one two three four' ), 4 ),
	null
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
