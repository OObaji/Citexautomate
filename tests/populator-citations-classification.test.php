<?php
/**
 * Regression tests for Citex_Populator's Citations-specific Category
 * taxonomy classification — the live site's Citations post type has gone
 * through two Category taxonomy shapes: originally Book/Edited Book/
 * Journal Article/Website (same as Reference List), then briefly grouped
 * by PERSON COUNT ("Single Author"/"Two Authors"/etc., handled by a
 * since-removed taxonomy_category_label() branch), and has now been
 * changed BACK to the Book/Edited Book/Journal Article/Website shape —
 * this time named "Books"/"Edited Books"/"Journal Articles"/"Web
 * Resource" (plural, except Website) with plain "Exercise 1".."Exercise
 * 5" children, never person-count buckets and never compound
 * "X | Exercise N" child names.
 *
 * assign_generated_classification() now resolves Category purely from
 * $classification['category'] for BOTH destinations — no group-based
 * branching at all — and category_term_alternates() absorbs the Citations
 * post type's plural naming (Books/Edited Books/Journal Articles) the
 * same way it already absorbed "Web Resource" for Website.
 *
 * Repo-level only, run with plain
 * `php tests/populator-citations-classification.test.php` — not shipped
 * in citex-tools.zip.
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
function get_object_taxonomies( $post_type, $output = 'names' ) {
	return $GLOBALS['__taxonomies_by_post_type'][ $post_type ] ?? array();
}
function get_terms( $args = array() ) {
	$taxonomy      = $args['taxonomy'] ?? '';
	$parent_filter = array_key_exists( 'parent', $args ) ? $args['parent'] : null;
	$out = array();
	foreach ( ( $GLOBALS['__terms_full'][ $taxonomy ] ?? array() ) as $term_id => $t ) {
		if ( null !== $parent_filter && (int) ( $t['parent'] ?? 0 ) !== (int) $parent_filter ) {
			continue;
		}
		$out[] = (object) array( 'term_id' => $term_id, 'name' => $t['name'] ?? '', 'parent' => (int) ( $t['parent'] ?? 0 ), 'taxonomy' => $taxonomy );
	}
	return $out;
}
function wp_set_object_terms( $post_id, $term_ids, $taxonomy, $append = false ) {
	$GLOBALS['__post_terms'][ $post_id ][ $taxonomy ] = array_values( (array) $term_ids );
	return $term_ids;
}
function wp_get_object_terms( $post_id, $taxonomy, $args = array() ) {
	return $GLOBALS['__post_terms'][ $post_id ][ $taxonomy ] ?? array();
}

require __DIR__ . '/../citex-tools/includes/class-citex-populator.php';

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
function invoke_private( $object, $method, array $args = array() ) {
	$reflection = new ReflectionMethod( get_class( $object ), $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

$populator = new Citex_Populator();

// ---------------------------------------------------------------------
// 1. Realistic Citations taxonomy: plural top-level category names
// ("Books"/"Edited Books"/"Journal Articles"/"Web Resource"), plain
// "Exercise N" children — never person-count buckets, never compound
// "X | Exercise N" names.
// ---------------------------------------------------------------------
$GLOBALS['__taxonomies_by_post_type']['citex-citations'] = array( 'reference_category' );
$GLOBALS['__terms_full']['reference_category']           = array(
	10 => array( 'name' => 'Books', 'parent' => 0 ),
	11 => array( 'name' => 'Exercise 1', 'parent' => 10 ),
	12 => array( 'name' => 'Exercise 2', 'parent' => 10 ),
	20 => array( 'name' => 'Edited Books', 'parent' => 0 ),
	21 => array( 'name' => 'Exercise 1', 'parent' => 20 ),
	30 => array( 'name' => 'Journal Articles', 'parent' => 0 ),
	31 => array( 'name' => 'Exercise 3', 'parent' => 30 ),
	40 => array( 'name' => 'Web Resource', 'parent' => 0 ),
	41 => array( 'name' => 'Exercise 4', 'parent' => 40 ),
);
$GLOBALS['__post_terms'] = array();

// ---------------------------------------------------------------------
// 2. An InTextCitation question resolves its Category/Exercise terms
// exactly the same way a ReferenceList question does — purely from
// $classification['category'], no group-based branching at all — via the
// "Books" plural alternate since the live term isn't the exact singular
// "Book".
// ---------------------------------------------------------------------
$intext_book_question       = array( 'group' => 'InTextCitation', 'category' => 'Book' );
$intext_book_classification = array( 'category' => 'Book', 'exercise' => 'Exercise 2', 'type' => 'DragDrop' );
$intext_result               = invoke_private( $populator, 'assign_generated_classification', array( 601, 'citex-citations', $intext_book_classification ) );
check( '[2] InTextCitation Book question resolves via the "Books" plural alternate', is_wp_error( $intext_result ), false );
if ( ! is_wp_error( $intext_result ) ) {
	$saved = $GLOBALS['__post_terms'][601]['reference_category'] ?? array();
	check( '[2] the "Books" term (10) is assigned', in_array( 10, $saved, true ), true );
	check( '[2] the "Exercise 2" term (12) is assigned', in_array( 12, $saved, true ), true );
}

// ---------------------------------------------------------------------
// 3. A ReferenceList question against the SAME Citations taxonomy
// resolves identically — proving both destinations now share one code
// path, not two.
// ---------------------------------------------------------------------
$reference_book_classification = array( 'category' => 'Book', 'exercise' => 'Exercise 1', 'type' => 'DragDrop' );
$reference_result               = invoke_private( $populator, 'assign_generated_classification', array( 602, 'citex-citations', $reference_book_classification ) );
check( '[3] ReferenceList Book question resolves the same way against the same taxonomy', is_wp_error( $reference_result ), false );
if ( ! is_wp_error( $reference_result ) ) {
	$saved = $GLOBALS['__post_terms'][602]['reference_category'] ?? array();
	check( '[3] the "Books" term (10) is assigned', in_array( 10, $saved, true ), true );
	check( '[3] the "Exercise 1" term (11) is assigned', in_array( 11, $saved, true ), true );
}

// ---------------------------------------------------------------------
// 4. Edited Book / Journal Article / Website each resolve via their own
// plural (or, for Website, "Web Resource") alternate.
// ---------------------------------------------------------------------
$edited_result = invoke_private( $populator, 'assign_generated_classification', array( 603, 'citex-citations', array( 'category' => 'Edited Book', 'exercise' => 'Exercise 1', 'type' => 'DragDrop' ) ) );
check( '[4] Edited Book resolves via the "Edited Books" plural alternate', is_wp_error( $edited_result ), false );
if ( ! is_wp_error( $edited_result ) ) {
	$saved = $GLOBALS['__post_terms'][603]['reference_category'] ?? array();
	check( '[4] the "Edited Books" term (20) is assigned', in_array( 20, $saved, true ), true );
}

$journal_result = invoke_private( $populator, 'assign_generated_classification', array( 604, 'citex-citations', array( 'category' => 'Journal Article', 'exercise' => 'Exercise 3', 'type' => 'DragDrop' ) ) );
check( '[4] Journal Article resolves via the "Journal Articles" plural alternate', is_wp_error( $journal_result ), false );
if ( ! is_wp_error( $journal_result ) ) {
	$saved = $GLOBALS['__post_terms'][604]['reference_category'] ?? array();
	check( '[4] the "Journal Articles" term (30) is assigned', in_array( 30, $saved, true ), true );
}

$website_result = invoke_private( $populator, 'assign_generated_classification', array( 605, 'citex-citations', array( 'category' => 'Website', 'exercise' => 'Exercise 4', 'type' => 'DragDrop' ) ) );
check( '[4] Website resolves via the pre-existing "Web Resource" alternate', is_wp_error( $website_result ), false );
if ( ! is_wp_error( $website_result ) ) {
	$saved = $GLOBALS['__post_terms'][605]['reference_category'] ?? array();
	check( '[4] the "Web Resource" term (40) is assigned', in_array( 40, $saved, true ), true );
}

// ---------------------------------------------------------------------
// 5. When the site names its Category term with the exact singular form
// (matching Reference List's own historical convention), the exact match
// still wins directly without ever needing an alternate.
// ---------------------------------------------------------------------
$GLOBALS['__taxonomies_by_post_type']['question'] = array( 'reference_category' );
$GLOBALS['__terms_full']['reference_category']     = array(
	1 => array( 'name' => 'Book', 'parent' => 0 ),
	2 => array( 'name' => 'Exercise 1', 'parent' => 1 ),
);
$singular_classification = array( 'category' => 'Book', 'exercise' => 'Exercise 1', 'type' => 'DragDrop' );
$singular_result          = invoke_private( $populator, 'assign_generated_classification', array( 503, 'question', $singular_classification ) );
check( '[5] an exact singular "Book" term matches directly, no alternate needed', is_wp_error( $singular_result ), false );
if ( ! is_wp_error( $singular_result ) ) {
	$saved = $GLOBALS['__post_terms'][503]['reference_category'] ?? array();
	check( '[5] the "Book" term (1) is assigned', in_array( 1, $saved, true ), true );
}

// ---------------------------------------------------------------------
// 6. A category with no matching term and no known alternate still fails
// clearly, naming the alternates it tried.
// ---------------------------------------------------------------------
$GLOBALS['__taxonomies_by_post_type']['citex-empty'] = array( 'reference_category' );
$GLOBALS['__terms_full']['reference_category']       = array(
	1 => array( 'name' => 'Something Else', 'parent' => 0 ),
);
$missing_result = invoke_private( $populator, 'assign_generated_classification', array( 606, 'citex-empty', array( 'category' => 'Book', 'exercise' => 'Exercise 1', 'type' => 'DragDrop' ) ) );
check( '[6] no matching term and no successful alternate correctly fails', is_wp_error( $missing_result ), true );
check( '[6] reports citex_category_term_not_found', is_wp_error( $missing_result ) ? $missing_result->get_error_code() : null, 'citex_category_term_not_found' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
