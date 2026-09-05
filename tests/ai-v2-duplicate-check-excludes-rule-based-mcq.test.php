<?php
/**
 * Regression test for a real reported bug: generating a "Choose the
 * correct rule/treatment" MCQ question (Citex_Question_Scenarios'
 * choose_treatment_* scenarios) failed with "A generated reference
 * duplicates one already in the pending queue" whenever a batch contained
 * more than one question for the same rule bucket, or the pending queue
 * already had one for that bucket — because Citex_AI_V2::
 * find_duplicate_reference() treated every candidate's reconstructedReference
 * as if it were a unique book reference. For choose_treatment, that field
 * is Citex's own FIXED, bucket-level rule statement (see
 * normalise_choose_treatment_item()) — deliberately identical across every
 * question testing the same rule, never a real book. 'identify_error'
 * questions (Gemini's own free-form errorReason text) get the same
 * exclusion for the same reason: neither pattern's reconstructedReference
 * is an actual bibliographic reference.
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-duplicate-check-excludes-rule-based-mcq.test.php` — not
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

function invoke_find_duplicate_reference( $candidates, $existing_references ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'find_duplicate_reference' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $candidates, $existing_references );
}

$rule_statement = 'Both authors are included, joined by "and" — e.g. Smith, J. and Jones, A.';

// ---------------------------------------------------------------------
// 1. The exact reported bug: two choose_treatment candidates for the same
// bucket in one batch share the identical (by design) correct rule
// statement — this must NOT be flagged as a duplicate reference.
// ---------------------------------------------------------------------
$two_choose_treatment_candidates = array(
	array( 'mcqPattern' => 'choose_treatment', 'reconstructedReference' => $rule_statement ),
	array( 'mcqPattern' => 'choose_treatment', 'reconstructedReference' => $rule_statement ),
);
check(
	'[bug repro] two choose_treatment candidates in the same batch sharing the same rule statement is NOT a duplicate',
	invoke_find_duplicate_reference( $two_choose_treatment_candidates, array() ),
	null
);

// ---------------------------------------------------------------------
// 2. The same rule statement already sitting in the pending queue (a
// prior choose_treatment question for the same bucket) must not block a
// new one either.
// ---------------------------------------------------------------------
check(
	'[bug repro] a choose_treatment candidate matching an existing pending "reference" (really a rule statement) is NOT a duplicate',
	invoke_find_duplicate_reference(
		array( array( 'mcqPattern' => 'choose_treatment', 'reconstructedReference' => $rule_statement ) ),
		array( $rule_statement )
	),
	null
);

// ---------------------------------------------------------------------
// 3. identify_error gets the same exclusion.
// ---------------------------------------------------------------------
$error_reason = 'Missing the final full stop.';
check(
	'[identify_error] two identify_error candidates sharing the same errorReason text is NOT a duplicate',
	invoke_find_duplicate_reference(
		array(
			array( 'mcqPattern' => 'identify_error', 'reconstructedReference' => $error_reason ),
			array( 'mcqPattern' => 'identify_error', 'reconstructedReference' => $error_reason ),
		),
		array()
	),
	null
);

// ---------------------------------------------------------------------
// 4. A genuine DragDrop/select_correct duplicate (a real repeated book) is
// still caught — this fix must not weaken the check for the case it
// actually exists to catch.
// ---------------------------------------------------------------------
$real_reference = 'Cottrell, S. (2019) Critical Thinking Skills. London: Red Globe Press.';
check(
	'[still works] two ordinary DragDrop candidates with the exact same real reference IS a duplicate',
	invoke_find_duplicate_reference(
		array(
			array( 'reconstructedReference' => $real_reference ),
			array( 'reconstructedReference' => $real_reference ),
		),
		array()
	),
	$real_reference
);
check(
	'[still works] an ordinary candidate matching an existing pending reference IS a duplicate',
	invoke_find_duplicate_reference(
		array( array( 'reconstructedReference' => $real_reference ) ),
		array( $real_reference )
	),
	$real_reference
);

// ---------------------------------------------------------------------
// 5. A mixed batch: a genuine duplicate among ordinary candidates is still
// caught even when choose_treatment/identify_error candidates share the
// batch and would otherwise "look like" duplicates of each other.
// ---------------------------------------------------------------------
$mixed_batch = array(
	array( 'mcqPattern' => 'choose_treatment', 'reconstructedReference' => $rule_statement ),
	array( 'reconstructedReference' => $real_reference ),
	array( 'mcqPattern' => 'choose_treatment', 'reconstructedReference' => $rule_statement ),
	array( 'reconstructedReference' => $real_reference ),
);
check(
	'[mixed batch] the real duplicate is caught while the repeated rule statements are ignored',
	invoke_find_duplicate_reference( $mixed_batch, array() ),
	$real_reference
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
