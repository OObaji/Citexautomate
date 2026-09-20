<?php
/**
 * Regression test for a real WordPress sanitize_text_field()/
 * sanitize_textarea_field() bug: both call wp_strip_all_tags() internally,
 * which treats ANY "<...>" segment as an HTML tag to delete — even when it
 * is plain text Citex itself composed (never raw/attacker-controlled
 * markup). Two styles' own Website reference format legitimately requires
 * a literal angle-bracketed URL: Harvard's "Available from: <URL>" and
 * MHRA's "'Title', <URL> [accessed Day Month Year]." (see each style's own
 * Reference_Rules docblock). Citex_AI_V2::sanitize_reference_text()/
 * sanitize_reference_textarea() exist specifically to avoid this, and are
 * reused identically by both styles' own normalisers — this test proves
 * both are actually wired to use them, under semantics that FAITHFULLY
 * reproduce WordPress's real tag-stripping (via PHP's own strip_tags()),
 * not the no-op `trim()` stub every other test file in this suite uses
 * (which is exactly why this bug went undetected until a live "Citation
 * does not match the MHRA Bibliography Website format" / reconstruction-
 * mismatch report).
 *
 * Repo-level only, run with plain
 * `php tests/ai-v2-website-angle-bracket-sanitization.test.php` — not
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
function wp_check_invalid_utf8( $v ) {
	return $v;
}

/**
 * Faithfully replicates WordPress core's real _sanitize_text_fields(),
 * INCLUDING the strip_tags()-based branch this whole bug is about — never
 * the no-op `trim()` stub used elsewhere in this repo's own test
 * harnesses, which cannot catch this class of bug at all.
 */
function _sanitize_text_fields_real( $str, $keep_newlines = false ) {
	if ( is_object( $str ) || is_array( $str ) ) {
		return '';
	}
	$str      = (string) $str;
	$filtered = wp_check_invalid_utf8( $str );
	if ( false !== strpos( $filtered, '<' ) ) {
		$filtered = strip_tags( $filtered );
	}
	if ( ! $keep_newlines ) {
		$filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
	}
	return trim( $filtered );
}
function sanitize_text_field( $v ) {
	return _sanitize_text_fields_real( $v, false );
}
function sanitize_textarea_field( $v ) {
	return _sanitize_text_fields_real( $v, true );
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

$base = __DIR__ . '/../citex-tools/includes/';
require $base . 'class-citex-reference-rules.php';
require $base . 'class-citex-book-mcq-variants.php';
require $base . 'class-citex-website-mcq-variants.php';
require $base . 'class-citex-website-dragdrop-parts.php';
require $base . 'class-citex-mla-reference-rules.php';
require $base . 'class-citex-mla-book-dragdrop-parts.php';
require $base . 'class-citex-mla-book-mcq-variants.php';
require $base . 'class-citex-apa-reference-rules.php';
require $base . 'class-citex-apa-book-dragdrop-parts.php';
require $base . 'class-citex-apa-book-mcq-variants.php';
require $base . 'class-citex-chicago-reference-rules.php';
require $base . 'class-citex-chicago-book-dragdrop-parts.php';
require $base . 'class-citex-chicago-book-mcq-variants.php';
require $base . 'class-citex-mhra-reference-rules.php';
require $base . 'class-citex-mhra-book-dragdrop-parts.php';
require $base . 'class-citex-mhra-book-mcq-variants.php';
require $base . 'class-citex-mhra-edited-book-dragdrop-parts.php';
require $base . 'class-citex-mhra-edited-book-mcq-variants.php';
require $base . 'class-citex-mhra-journal-article-dragdrop-parts.php';
require $base . 'class-citex-mhra-journal-article-mcq-variants.php';
require $base . 'class-citex-mhra-website-dragdrop-parts.php';
require $base . 'class-citex-mhra-website-mcq-variants.php';
require $base . 'class-citex-book-dragdrop-parts.php';
require $base . 'class-citex-generated-validator.php';
require $base . 'class-citex-question-scenarios.php';
require $base . 'class-citex-question-diversity.php';
require $base . 'class-citex-ai-v2.php';

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

function invoke_normalise( $questions, $ids, $category, $type, $style ) {
	$reflection = new ReflectionMethod( 'Citex_AI_V2', 'normalise' );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $questions, $ids, 'medium', array(), $type, $category, null, '', '', 'full_reference', $style );
}

// ---------------------------------------------------------------------
// Unit-level: the shared helpers themselves, called directly. Harvard's
// own Website format only puts "<URL>" in a WRONG option (its own
// correctly-formatted reference is a bare, unbracketed URL — see
// Citex_Website_Mcq_Variants::build_url_formatting()), so a full
// normalise() batch's own reconstructedReference (always the CORRECT
// answer) never exercises this for Harvard the way it does for every MHRA
// Website question. Calling the helpers directly proves the shared
// mechanism Harvard's own normaliser already relies on is intact,
// independent of which MCQ variant a given seed happens to draw.
function assert_helpers_preserve_angle_brackets() {
	$sanitize_text     = new ReflectionMethod( 'Citex_AI_V2', 'sanitize_reference_text' );
	$sanitize_textarea = new ReflectionMethod( 'Citex_AI_V2', 'sanitize_reference_textarea' );
	$sanitize_text->setAccessible( true );
	$sanitize_textarea->setAccessible( true );

	$bracketed = "Arts Council, 'Digital Art', <https://example.com/art> [accessed 16 September 2026].";
	check(
		'sanitize_reference_text() preserves a literal "<URL>" segment untouched',
		$sanitize_text->invoke( null, $bracketed ),
		$bracketed
	);

	$bracketed_multiline = "Which option correctly identifies the error in this reference?\n\n" . $bracketed;
	check(
		'sanitize_reference_textarea() preserves a literal "<URL>" segment across a multi-line stem',
		$sanitize_textarea->invoke( null, $bracketed_multiline ),
		$bracketed_multiline
	);

	// The bug this whole file guards against, made explicit: WordPress's
	// REAL sanitize_text_field() (as faithfully reproduced above, not the
	// no-op `trim()` stub the rest of this test suite uses) DOES strip it —
	// proving these two helpers are genuinely doing something, not just
	// happening to match a no-op.
	check(
		'the REAL sanitize_text_field() (not the no-op stub) truly does strip the same segment, proving the helpers above are load-bearing',
		false !== strpos( sanitize_text_field( $bracketed ), '<' ),
		false
	);
}

function assert_website_batch_survives_sanitization( $style, $id_prefix ) {
	global $failures;
	// Distinct publishers per record — Harvard's own batch-level quality
	// gate (quality_reject 'citex_ai_publisher_not_diverse') rejects an
	// entire batch that repeats the same publisher on every question; using
	// a different real-looking publisher per record sidesteps that
	// unrelated check so this test isolates only the angle-bracket
	// sanitization bug it exists to guard.
	$organisations = array( 'Arts Council', 'British Museum', 'Tate Gallery' );
	$publishers    = array( 'Routledge', 'Pearson', 'SAGE' );
	$items = array();
	$ids   = array();
	foreach ( $organisations as $i => $org ) {
		$items[] = array(
			'scenario'         => "You are referencing the webpage Digital Art $i by the $org, published online in 2020 by {$publishers[ $i ]} at https://example$i.org.uk/art.",
			'authorType'       => 'organisation',
			'organisationName' => $org,
			'title'            => "Digital Art $i",
			'url'              => "https://example$i.org.uk/art",
			'publisher'        => $publishers[ $i ],
			'year'             => '2020',
		);
		$ids[] = $id_prefix . str_pad( $i + 1, 2, '0', STR_PAD_LEFT );
	}

	// MHRA's own Website reference ALWAYS puts the URL in literal angle
	// brackets ("'Title', <URL> [accessed ...].") — never just a wrong
	// option, unlike Harvard's own format (see assert_helpers_preserve_angle_brackets()'s
	// own docblock) — so its reconstructedReference is exactly where the
	// original bug report's "Citation does not match the MHRA Bibliography
	// Website format" / reconstruction-mismatch surfaced.
	$expect_bracketed_answer = ( 'mhra' === $style );

	foreach ( array( 'DragDrop', 'MCQ' ) as $type ) {
		$result = invoke_normalise( $items, $ids, Citex_Reference_Rules::CATEGORY_WEBSITE, $type, $style );
		check( "[$style $type] normalise() succeeds under real sanitize_text_field()", is_wp_error( $result ), false );
		if ( is_wp_error( $result ) ) {
			continue;
		}
		foreach ( $result as $question ) {
			if ( $expect_bracketed_answer ) {
				check( "[$style $type] {$question['questionId']}: reconstructedReference keeps its angle-bracketed URL", false !== strpos( $question['reconstructedReference'], '<' ) && false !== strpos( $question['reconstructedReference'], '>' ), true );
			}
			$validated = Citex_Generated_Validator::validate( $question );
			check( "[$style $type] {$question['questionId']}: validator passes", $validated['status'], 'passed' );
		}
	}
}

assert_helpers_preserve_angle_brackets();
assert_website_batch_survives_sanitization( 'harvard', 'WW' );
assert_website_batch_survives_sanitization( 'mhra', 'HW' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
