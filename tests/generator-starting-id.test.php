<?php
/**
 * Regression tests for per-category question ID prefixes, added after a
 * real production complaint: Book and Edited Book questions were sharing
 * the same "BK" prefix and a single continuing number sequence, making the
 * two categories hard to tell apart just from the ID (and Edited Book
 * never actually started its own count at 01 — it silently continued
 * wherever Book's numbering had reached).
 *
 * Citex_Reference_Rules::id_prefix() gives each category its own short,
 * visually-distinct prefix ("BK" for Book, "ED" for Edited Book — see that
 * class for the "next category supplies its own prefix" rationale).
 * Citex_Generator::normalise_starting_id() uses it to auto-correct a
 * starting ID that doesn't belong to the selected category (a leftover
 * default, or a stale value from a previous batch) to a fresh
 * "<prefix>01", while leaving an ID the admin deliberately typed for that
 * category alone. Because prefixes never collide between categories, the
 * existing global "skip already-used IDs" logic in
 * Citex_AI_V2::build_ids() then keeps each category's own sequence
 * gap-free without any further change — tested separately in
 * ai-v2-*-construction.test.php.
 *
 * Repo-level only, run with plain
 * `php tests/generator-starting-id.test.php` — not shipped in
 * citex-tools.zip.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

$GLOBALS['__options'] = array();
function get_option( $key, $default = false ) {
	return $GLOBALS['__options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}

require __DIR__ . '/../citex-tools/includes/class-citex-reference-rules.php';
require __DIR__ . '/../citex-tools/includes/class-citex-populator.php';
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

// ---------------------------------------------------------------------
// 1. Each category has its own short, distinct prefix.
// ---------------------------------------------------------------------
check( '[1] Book\'s ID prefix is "BK"', Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_BOOK ), 'BK' );
check( '[1] Edited Book\'s ID prefix is "ED"', Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_EDITED_BOOK ), 'ED' );
check( '[1] the two categories\' prefixes are distinct', Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_BOOK ) === Citex_Reference_Rules::id_prefix( Citex_Reference_Rules::CATEGORY_EDITED_BOOK ), false );

// ---------------------------------------------------------------------
// 2. CRITICAL — the reported bug: leaving the form's "BK01" default in
// place while "Edited Book" is selected must NOT produce Edited Book
// questions with Book-prefixed IDs. It is auto-corrected to Edited Book's
// own prefix, starting fresh at 01 — never a continuation of Book's count.
// ---------------------------------------------------------------------
check(
	'[2] the Book-default "BK01" is auto-corrected to "ED01" when Edited Book is selected',
	Citex_Generator::normalise_starting_id( 'BK01', Citex_Reference_Rules::CATEGORY_EDITED_BOOK ),
	'ED01'
);
check(
	'[2] a stale, further-along Book ID ("BK21") is also corrected to a FRESH Edited Book start, not a continuation',
	Citex_Generator::normalise_starting_id( 'BK21', Citex_Reference_Rules::CATEGORY_EDITED_BOOK ),
	'ED01'
);

// ---------------------------------------------------------------------
// 3. An ID the admin deliberately typed FOR the selected category is
// honoured as-is — e.g. resuming a numbering gap.
// ---------------------------------------------------------------------
check( '[3] "ED05" for Edited Book is left untouched', Citex_Generator::normalise_starting_id( 'ED05', Citex_Reference_Rules::CATEGORY_EDITED_BOOK ), 'ED05' );
check( '[3] "BK25" for Book is left untouched', Citex_Generator::normalise_starting_id( 'BK25', Citex_Reference_Rules::CATEGORY_BOOK ), 'BK25' );

// ---------------------------------------------------------------------
// 4. Selecting Book after Edited Book corrects the other way too —
// neither category can "leak" its numbering into the other.
// ---------------------------------------------------------------------
check( '[4] an Edited Book ID is corrected to "BK01" when Book is selected', Citex_Generator::normalise_starting_id( 'ED01', Citex_Reference_Rules::CATEGORY_BOOK ), 'BK01' );

// ---------------------------------------------------------------------
// 5. Case-insensitivity and whitespace are handled the same way the rest
// of the ID pipeline (Citex_AI_V2::build_ids()) already handles them.
// ---------------------------------------------------------------------
check( '[5] a lower-case, correctly-prefixed ID is upper-cased but not renumbered', Citex_Generator::normalise_starting_id( ' ed07 ', Citex_Reference_Rules::CATEGORY_EDITED_BOOK ), 'ED07' );
check( '[5] a lower-case, wrongly-prefixed ID is corrected to a fresh start', Citex_Generator::normalise_starting_id( 'bk07', Citex_Reference_Rules::CATEGORY_EDITED_BOOK ), 'ED01' );

// ---------------------------------------------------------------------
// 6. An empty or garbage starting ID always resolves to a fresh, valid
// "<prefix>01" for the selected category — never left blank or malformed.
// ---------------------------------------------------------------------
check( '[6] an empty starting ID resolves to a fresh Book start', Citex_Generator::normalise_starting_id( '', Citex_Reference_Rules::CATEGORY_BOOK ), 'BK01' );
check( '[6] garbage input resolves to a fresh Edited Book start', Citex_Generator::normalise_starting_id( '???', Citex_Reference_Rules::CATEGORY_EDITED_BOOK ), 'ED01' );

echo "\n" . ( 0 === $failures ? 'All checks passed.' : $failures . ' check(s) failed.' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
