<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Validator registration for: Harvard / ReferenceList / Book / DragDrop.
 *
 * ============================================================================
 * STATUS: IMPLEMENTED (v1) — ported from recovered details of the original
 * Citex Harvard QA Checker v0.3, not invented.
 * ============================================================================
 *
 * The actual fetch-the-edit-page / extract-fields / reconstruct-reference /
 * run-checks logic lives client-side in admin/js/citex-validator.js — same
 * pattern as the Phase 2 scanner and the original DevTools QA Checker. This
 * PHP class is the routing + field-map registry only.
 *
 * Ported from the user-supplied recovered implementation details:
 *  - FIELD_MAP's three ACF field keys (confirmed values from QA Checker v0.3).
 *  - The Fixed-Text + "|" placeholder + Question Parts reconstruction
 *    algorithm (verified against the supplied worked example — see the test
 *    in tests/harvard-book-dragdrop-rules.test.js).
 *  - The two named punctuation checks (YEAR_TRAILING_PERIOD,
 *    MISSING_FINAL_PERIOD) and their exact messages.
 *  - The Liverpool Hope Book structural shape ("Author (Year) Title. Place:
 *    Publisher.") for the format-mismatch check.
 *
 * One genuine gap remains and is NOT invented around: the exact HTML markup
 * ACF renders these three fields as on this site's edit screen has not been
 * seen directly (no live network access from this environment). Extraction
 * in citex-validator.js therefore relies on ACF's standard, version-spanning
 * DOM convention (`.acf-field[data-key="..."]`, and `.acf-row` for repeater
 * fields) rather than a site-specific guess, and every extraction outcome is
 * surfaced in the Validation page's Details diagnostics so it can be
 * confirmed (or corrected) against a real question before bulk validation.
 */
class Citex_Harvard_Book_Dragdrop_Validator {

	const ID = 'harvard-reference-list-book-dragdrop';

	/**
	 * Routing metadata this validator claims, matched exactly against a
	 * scanned question's parsed title (see Citex_Scanner / citex-scanner.js).
	 * Per the brief, category/type values are used exactly as scanned —
	 * never renamed or consolidated. Unchanged from Phase 3 — routing itself
	 * was not touched for this task.
	 */
	const ROUTES = array(
		'source'   => 'Harvard',
		'group'    => 'ReferenceList',
		'category' => 'Book',
		'type'     => 'DragDrop',
	);

	/**
	 * ACF field keys this validator reads, confirmed from the original QA
	 * Checker v0.3. The client-side copy (admin/js/citex-validator.js) uses
	 * the identical keys — keep the two in sync.
	 */
	const FIELD_MAP = array(
		'fixedText'      => 'field_59c2476bc859f',
		'questionParts'  => 'field_59c2476bc81b7',
		'confusingWords' => 'field_59c2476bc83ab',
	);

	/**
	 * True: this validator now has a real rule engine (v1), not just a
	 * routing stub. See the class docblock for exactly what was ported vs.
	 * what still needs live confirmation (field extraction).
	 */
	const IMPLEMENTED = true;
}
