<?php
/**
 * Regression tests for Citex_AI_V2::conciseness_guidance() — the advisory
 * "prefer naturally concise real names when multiple equally valid real
 * options exist" instruction appended to every prompt that carries
 * bibliographic data, so generated Question Parts/MCQ options/scenario
 * text tend to be more compact on mobile without ever shortening,
 * abbreviating, or truncating whatever real name Gemini actually returns.
 *
 * These tests only inspect prompt TEXT (via reflection on the private
 * prompt builders) — they do not, and cannot, verify Gemini's actual
 * output length, since that depends on which real book Gemini selects.
 * What they lock in is structural: every prompt that could leak/carry a
 * real name gets the guidance, the guidance itself never tells Gemini to
 * shorten/truncate/abbreviate anything, and the one prompt with no
 * bibliographic data (choose_treatment) does not get it at all.
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-conciseness-guidance.test.php` — not shipped in
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
function absint( $v ) {
	return abs( intval( $v ) );
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
function get_option( $key, $default = null ) {
	return $default;
}

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
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

function invoke_private( $method, $args ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $args );
}

$ids = array( 'BK01' );
$marker = 'PREFER CONCISE REAL PUBLISHER/JOURNAL NAMES WHEN POSSIBLE';
$never_shorten_marker = 'NEVER abbreviate, shorten, truncate, or otherwise alter the real publisher/journal name';

// ---------------------------------------------------------------------
// 1. Every prompt builder that carries bibliographic data includes the
// conciseness guidance.
// ---------------------------------------------------------------------
$book_dragdrop_prompt = invoke_private( 'build_prompt', array( $ids, 'medium', false ) );
check( '[1] Book DragDrop prompt includes the conciseness guidance', false !== strpos( $book_dragdrop_prompt, $marker ), true );

$book_mcq_prompt = invoke_private( 'build_prompt_mcq', array( $ids, 'medium', false ) );
check( '[1] Book MCQ prompt includes the conciseness guidance', false !== strpos( $book_mcq_prompt, $marker ), true );

$edited_book_dragdrop_prompt = invoke_private( 'build_prompt_edited_book', array( $ids, 'medium', false ) );
check( '[1] Edited Book DragDrop prompt includes the conciseness guidance', false !== strpos( $edited_book_dragdrop_prompt, $marker ), true );

$edited_book_mcq_prompt = invoke_private( 'build_prompt_edited_book_mcq', array( $ids, 'medium', false ) );
check( '[1] Edited Book MCQ prompt includes the conciseness guidance', false !== strpos( $edited_book_mcq_prompt, $marker ), true );

$identify_error_book_prompt = invoke_private( 'build_prompt_identify_error', array( Citex_Reference_Rules::CATEGORY_BOOK, $ids, 'medium', false ) );
check( '[1] identify_error (Book) prompt includes the conciseness guidance', false !== strpos( $identify_error_book_prompt, $marker ), true );

$identify_error_edited_book_prompt = invoke_private( 'build_prompt_identify_error', array( Citex_Reference_Rules::CATEGORY_EDITED_BOOK, $ids, 'medium', false ) );
check( '[1] identify_error (Edited Book) prompt includes the conciseness guidance', false !== strpos( $identify_error_edited_book_prompt, $marker ), true );

// ---------------------------------------------------------------------
// 2. choose_treatment has no bibliographic data at all — it must NOT get
// the conciseness guidance (there is no author/publisher/title for it to
// apply to).
// ---------------------------------------------------------------------
$treatment_prompt = invoke_private( 'build_prompt_choose_treatment', array( Citex_Reference_Rules::CATEGORY_BOOK, 'two_authors', $ids, 'medium', false ) );
check( '[2] choose_treatment prompt does NOT include the conciseness guidance', false !== strpos( $treatment_prompt, $marker ), false );

// ---------------------------------------------------------------------
// 3. CRITICAL — the guidance itself is a preference between equally valid
// real options, never an instruction to shorten/abbreviate/truncate a
// real name that is actually used.
// ---------------------------------------------------------------------
check( '[3] the guidance explicitly forbids abbreviating/shortening/truncating the real publisher/journal name', false !== strpos( $book_mcq_prompt, $never_shorten_marker ), true );
check( '[3] the guidance frames conciseness as a tie-breaker between real publisher/journal choices, never a reason to alter one', false !== strpos( $book_mcq_prompt, 'never a reason to alter one' ), true );
check( '[3] the guidance also covers content_realism_guidance() (invented content is fine except the publisher/journal)', false !== strpos( $book_mcq_prompt, 'INVENTED CONTENT IS FINE' ), true );

// ---------------------------------------------------------------------
// 4. The guidance still coexists correctly with scenario_instruction and
// quality_feedback appends — all three sections present when supplied,
// in the expected relative order (guidance before scenario_instruction
// before quality_feedback, matching the source order).
// ---------------------------------------------------------------------
$full_prompt = invoke_private( 'build_prompt_mcq', array( $ids, 'medium', false, 'Some quality feedback here.', 'AUTHOR/EDITOR COUNT FOR THIS BATCH — CRITICAL:' ) );
$guidance_pos  = strpos( $full_prompt, $marker );
$scenario_pos  = strpos( $full_prompt, 'AUTHOR/EDITOR COUNT FOR THIS BATCH' );
$feedback_pos  = strpos( $full_prompt, 'Some quality feedback here.' );
check( '[4] all three sections are present', false !== $guidance_pos && false !== $scenario_pos && false !== $feedback_pos, true );
check( '[4] conciseness guidance appears before the scenario instruction', $guidance_pos < $scenario_pos, true );
check( '[4] the scenario instruction appears before the quality feedback', $scenario_pos < $feedback_pos, true );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
