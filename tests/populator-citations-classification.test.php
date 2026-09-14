<?php
/**
 * Regression tests for Citex_Populator's Citations-specific Category
 * taxonomy classification — added after a real live-site failure:
 * populating an In-Text Citation (IB01 etc.) question into the real
 * Citations post type failed with 'Citex could not find a "Book"
 * Reference Category term for this post type.'
 *
 * Root cause: assign_generated_classification() always searched for the
 * question's own $classification['category'] (Book/Edited Book/Journal
 * Article/Website — the Reference List's own Category taxonomy shape).
 * The live site's real Citations post type uses a COMPLETELY DIFFERENT
 * Category taxonomy (confirmed via its own live Categories screen):
 * terms grouped by PERSON COUNT — "Single Author"/"Two Authors"/"Three
 * Authors"/"More than Three Authors" — each still with Exercise 1-5
 * children, never Book/Edited Book/Journal Article/Website at all.
 *
 * taxonomy_category_label() now resolves the CORRECT term name to search
 * for based on the question's own `group`: unchanged
 * ($classification['category']) for ReferenceList, computed from the
 * question's own authors/editors count for InTextCitation — Website
 * always resolving to "Single Author" (exactly one author-or-organisation
 * entity, never a joined list). $classification['category'] itself is
 * left completely untouched everywhere else (success-message summary,
 * returned result array, population coverage tracking).
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
// 1. taxonomy_category_label(): ReferenceList group is untouched — still
// exactly $classification['category'], regardless of author count.
// ---------------------------------------------------------------------
$classification = array( 'category' => 'Book', 'exercise' => 'Exercise 1', 'type' => 'DragDrop' );
check(
	'[1] ReferenceList group: returns $classification[\'category\'] unchanged',
	invoke_private( $populator, 'taxonomy_category_label', array( array( 'group' => 'ReferenceList', 'category' => 'Book', 'authors' => array( 1, 2, 3, 4 ) ), $classification ) ),
	'Book'
);
check(
	'[1] a missing/empty group also returns $classification[\'category\'] unchanged (pre-existing default group)',
	invoke_private( $populator, 'taxonomy_category_label', array( array( 'category' => 'Book' ), $classification ) ),
	'Book'
);

// ---------------------------------------------------------------------
// 2. taxonomy_category_label(): InTextCitation group resolves by person
// count for Book/Journal Article (authors) and Edited Book (editors).
// ---------------------------------------------------------------------
function intext_question( $category, $people_key, $count ) {
	$people = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$people[] = array( 'surname' => 'Person' . $i, 'givenName' => 'X' );
	}
	return array( 'group' => 'InTextCitation', 'category' => $category, $people_key => $people );
}
check( '[2] Book, 1 author -> Single Author', invoke_private( $populator, 'taxonomy_category_label', array( intext_question( 'Book', 'authors', 1 ), $classification ) ), 'Single Author' );
check( '[2] Book, 2 authors -> Two Authors', invoke_private( $populator, 'taxonomy_category_label', array( intext_question( 'Book', 'authors', 2 ), $classification ) ), 'Two Authors' );
check( '[2] Book, 3 authors -> Three Authors', invoke_private( $populator, 'taxonomy_category_label', array( intext_question( 'Book', 'authors', 3 ), $classification ) ), 'Three Authors' );
check( '[2] Book, 4 authors -> More than Three Authors', invoke_private( $populator, 'taxonomy_category_label', array( intext_question( 'Book', 'authors', 4 ), $classification ) ), 'More than Three Authors' );
check( '[2] Book, 6 authors -> still More than Three Authors', invoke_private( $populator, 'taxonomy_category_label', array( intext_question( 'Book', 'authors', 6 ), $classification ) ), 'More than Three Authors' );
check( '[2] Journal Article, 2 authors -> Two Authors (same authors[] field as Book)', invoke_private( $populator, 'taxonomy_category_label', array( intext_question( 'Journal Article', 'authors', 2 ), $classification ) ), 'Two Authors' );
check( '[2] Edited Book, 3 editors -> Three Authors (editors[], not authors[])', invoke_private( $populator, 'taxonomy_category_label', array( intext_question( 'Edited Book', 'editors', 3 ), $classification ) ), 'Three Authors' );
check( '[2] Edited Book, 1 editor -> Single Author', invoke_private( $populator, 'taxonomy_category_label', array( intext_question( 'Edited Book', 'editors', 1 ), $classification ) ), 'Single Author' );

// ---------------------------------------------------------------------
// 3. Website ALWAYS resolves to "Single Author" — exactly one
// author-or-organisation entity, regardless of what (if anything) is in
// its own authors[] field, and regardless of authorType.
// ---------------------------------------------------------------------
check(
	'[3] Website, individual author -> Single Author',
	invoke_private( $populator, 'taxonomy_category_label', array( array( 'group' => 'InTextCitation', 'category' => 'Website', 'authorType' => 'individual', 'authors' => array( array( 'surname' => 'Ross' ) ) ), $classification ) ),
	'Single Author'
);
check(
	'[3] Website, organisation author (empty authors[]) -> still Single Author',
	invoke_private( $populator, 'taxonomy_category_label', array( array( 'group' => 'InTextCitation', 'category' => 'Website', 'authorType' => 'organisation', 'authors' => array() ), $classification ) ),
	'Single Author'
);

// ---------------------------------------------------------------------
// 4. Zero people (a malformed/edge-case record) still resolves to Single
// Author rather than a nonsensical or empty label.
// ---------------------------------------------------------------------
check(
	'[4] zero authors resolves to Single Author, never an empty/invalid label',
	invoke_private( $populator, 'taxonomy_category_label', array( array( 'group' => 'InTextCitation', 'category' => 'Book', 'authors' => array() ), $classification ) ),
	'Single Author'
);

// ---------------------------------------------------------------------
// 5. assign_generated_classification() end-to-end: a real Citations
// taxonomy shaped by person count (Single Author/Two Authors/Three
// Authors/More than Three Authors, each with Exercise 1-5 children —
// mirroring the live site's own Categories screen exactly) correctly
// classifies an InTextCitation question by its own author count, leaving
// $classification['category'] (still "Book") completely untouched.
// ---------------------------------------------------------------------
$GLOBALS['__taxonomies_by_post_type']['citex-citations'] = array( 'reference_category' );
$GLOBALS['__terms_full']['reference_category'] = array(
	10 => array( 'name' => 'Single Author', 'parent' => 0 ),
	11 => array( 'name' => 'Single Author | Exercise 1', 'parent' => 10 ),
	20 => array( 'name' => 'Two Authors', 'parent' => 0 ),
	21 => array( 'name' => 'Two Authors | Exercise 1', 'parent' => 20 ),
	22 => array( 'name' => 'Two Authors | Exercise 2', 'parent' => 20 ),
	30 => array( 'name' => 'Three Authors', 'parent' => 0 ),
	40 => array( 'name' => 'More than Three Authors', 'parent' => 0 ),
);
// The live site's own term names carry a "Category | Exercise N" suffix
// (see the earlier screenshot: "— Two Authors | Exercise 1") rather than
// a bare "Exercise 1" — exercised here to prove the lookup works with
// whatever exact child-term naming the real site actually uses, not an
// assumed bare "Exercise N" form.
$GLOBALS['__post_terms'] = array();
$two_author_question = array( 'group' => 'InTextCitation', 'category' => 'Book', 'authors' => array( array( 'surname' => 'Ross' ), array( 'surname' => 'Carter' ) ) );
$two_author_classification = array( 'category' => 'Book', 'exercise' => 'Two Authors | Exercise 2', 'type' => 'DragDrop' );
$result = invoke_private( $populator, 'assign_generated_classification', array( 501, 'citex-citations', $two_author_classification, $two_author_question ) );
check( '[5] assign_generated_classification() succeeds against the real person-count taxonomy shape', is_wp_error( $result ), false );
if ( ! is_wp_error( $result ) ) {
	$saved = $GLOBALS['__post_terms'][501]['reference_category'] ?? array();
	check( '[5] the "Two Authors" term (20) is assigned, never "Book"', in_array( 20, $saved, true ), true );
	check( '[5] the "Two Authors | Exercise 2" term (22), its own real child, is assigned', in_array( 22, $saved, true ), true );
	check( '[5] $classification[\'category\'] itself is left untouched (still reports "Book")', $two_author_classification['category'], 'Book' );
}

// ---------------------------------------------------------------------
// 6. A ReferenceList question against that SAME Citations-shaped taxonomy
// correctly FAILS (never silently misclassified) — proving the
// group-based branch, not merely luck, is what makes citations work.
// ---------------------------------------------------------------------
$reference_question = array( 'group' => 'ReferenceList', 'category' => 'Book' );
$reference_result = invoke_private( $populator, 'assign_generated_classification', array( 502, 'citex-citations', array( 'category' => 'Book', 'exercise' => 'Exercise 1', 'type' => 'DragDrop' ), $reference_question ) );
check( '[6] a ReferenceList question against the person-count-only taxonomy correctly fails (no "Book" term exists there)', is_wp_error( $reference_result ), true );
check( '[6] reports citex_category_term_not_found', is_wp_error( $reference_result ) ? $reference_result->get_error_code() : null, 'citex_category_term_not_found' );

// ---------------------------------------------------------------------
// 7. Backward compatibility: calling assign_generated_classification()
// with only 3 args (no $question at all — the pre-existing call shape
// every other test in this codebase already uses) still works exactly
// as before, against a normal Book/Edited Book/... taxonomy.
// ---------------------------------------------------------------------
$GLOBALS['__taxonomies_by_post_type']['question'] = array( 'reference_category' );
$GLOBALS['__terms_full']['reference_category'] = array(
	1 => array( 'name' => 'Book', 'parent' => 0 ),
	2 => array( 'name' => 'Exercise 1', 'parent' => 1 ),
);
$legacy_classification = array( 'category' => 'Book', 'exercise' => 'Exercise 1', 'type' => 'DragDrop' );
$legacy_result = invoke_private( $populator, 'assign_generated_classification', array( 503, 'question', $legacy_classification ) );
check( '[7] the pre-existing 3-arg call shape (no $question) still succeeds against a normal Category taxonomy', is_wp_error( $legacy_result ), false );
if ( ! is_wp_error( $legacy_result ) ) {
	$saved = $GLOBALS['__post_terms'][503]['reference_category'] ?? array();
	check( '[7] the "Book" term (1) is assigned', in_array( 1, $saved, true ), true );
}

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
