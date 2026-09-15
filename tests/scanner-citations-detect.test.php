<?php
/**
 * Regression tests for Citex_Scanner::detect_citations_post_type() — added
 * after a real live-site failure: the Citations post type has no
 * pre-configured URL, so every Populate run for In-Text Citation
 * questions failed outright with "Citations List URL is not configured."
 * Since Citex runs as a local WordPress plugin on the same install (see
 * sync_from_wordpress()'s own class docblock), it can find the real
 * Citations post type itself by inspecting WordPress's own registered
 * post types — no external request, no manual URL-copying required.
 *
 * Repo-level only, run with plain
 * `php tests/scanner-citations-detect.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

function sanitize_key( $v ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

$GLOBALS['__options']    = array();
$GLOBALS['__post_types'] = array();

function get_option( $key, $default = false ) {
	return $GLOBALS['__options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}
function get_post_types( $args = array(), $output = 'names' ) {
	if ( 'objects' === $output ) {
		return $GLOBALS['__post_types'];
	}
	return array_keys( $GLOBALS['__post_types'] );
}
function admin_url( $path = '' ) {
	return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
}

require __DIR__ . '/../citex-tools/includes/class-citex-scanner.php';

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
function invoke_private_static( $class, $method, array $args = array() ) {
	$reflection = new ReflectionMethod( $class, $method );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, ...$args );
}
function post_type_object( $slug, $label ) {
	return (object) array( 'label' => $label, 'labels' => (object) array( 'name' => $label ) );
}

// ---------------------------------------------------------------------
// 1. Finds a post type whose SLUG contains "citation".
// ---------------------------------------------------------------------
$GLOBALS['__options']    = array();
$GLOBALS['__post_types'] = array(
	'post'            => post_type_object( 'post', 'Posts' ),
	'page'            => post_type_object( 'page', 'Pages' ),
	'citex-citations' => post_type_object( 'citex-citations', 'Citations' ),
);
$found = invoke_private_static( 'Citex_Scanner', 'detect_citations_post_type' );
check( '[1] finds the post type by slug', $found['slug'] ?? null, 'citex-citations' );
check( '[1] reports its real label', $found['label'] ?? null, 'Citations' );

// ---------------------------------------------------------------------
// 2. Finds a post type whose LABEL (not slug) contains "Citation".
// ---------------------------------------------------------------------
$GLOBALS['__post_types'] = array(
	'post'  => post_type_object( 'post', 'Posts' ),
	'cx_ci' => post_type_object( 'cx_ci', 'In-Text Citations' ),
);
$found_by_label = invoke_private_static( 'Citex_Scanner', 'detect_citations_post_type' );
check( '[2] finds the post type by its label when the slug itself does not mention "citation"', $found_by_label['slug'] ?? null, 'cx_ci' );

// ---------------------------------------------------------------------
// 3. Never matches the Reference List's own configured post type, even
// if it happens to also mention "citation" — the exact false-positive
// this exclusion guards against.
// ---------------------------------------------------------------------
$GLOBALS['__options'] = array(
	Citex_Scanner::OPTION_URL => 'https://example.com/wp-admin/edit.php?post_type=citation-reference',
);
$GLOBALS['__post_types'] = array(
	'citation-reference' => post_type_object( 'citation-reference', 'Reference List' ),
	'citex-citations'    => post_type_object( 'citex-citations', 'Citations' ),
);
$found_excluding_reference = invoke_private_static( 'Citex_Scanner', 'detect_citations_post_type' );
check( '[3] skips the Reference List\'s own post type even though it mentions "citation"', $found_excluding_reference['slug'] ?? null, 'citex-citations' );

// ---------------------------------------------------------------------
// 4. No matching post type at all returns null.
// ---------------------------------------------------------------------
$GLOBALS['__options']    = array();
$GLOBALS['__post_types'] = array(
	'post' => post_type_object( 'post', 'Posts' ),
	'page' => post_type_object( 'page', 'Pages' ),
);
check( '[4] returns null when nothing matches', invoke_private_static( 'Citex_Scanner', 'detect_citations_post_type' ), null );

// ---------------------------------------------------------------------
// 5. ajax_detect_citations_post_type()'s own source persists the
// detected slug into the Citations URL option via admin_url() — a
// literal-source check (this codebase's own established pattern for
// logic tied to wp_send_json_success()/exit()'s own request-cycle
// machinery, impractical to invoke directly in a test).
// ---------------------------------------------------------------------
$scanner_source = file_get_contents( __DIR__ . '/../citex-tools/includes/class-citex-scanner.php' );
check(
	'[5] ajax_detect_citations_post_type() saves the detected URL to OPTION_CITATIONS_URL',
	false !== strpos( $scanner_source, "update_option( self::OPTION_CITATIONS_URL, \$url, false );" ),
	true
);
check(
	'[5] the detected URL is built from admin_url(\'edit.php?post_type=...\'), matching the manual-entry format exactly',
	false !== strpos( $scanner_source, "admin_url( 'edit.php?post_type=' . \$found['slug'] )" ),
	true
);

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
