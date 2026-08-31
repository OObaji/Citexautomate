<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Validator registration for: Harvard / ReferenceList / Book / DragDrop.
 *
 * ============================================================================
 * STATUS: ROUTING ONLY — rule engine not yet implemented.
 * ============================================================================
 *
 * Phase 3 requires this validator to reuse the *existing* Citex Harvard QA
 * Checker prototype rather than invent a new interpretation of Harvard
 * referencing (its ACF field selectors, its expected-reference
 * reconstruction, its exact error checks, and its DragDrop fixed-text
 * false-positive fix). That prototype's source was not supplied with the
 * Phase 3 brief, and a full search of this repository and its git history
 * turned up nothing matching it (no prior QA-checker code has ever existed
 * in this codebase — Phase 1/2 were built from scratch in this project).
 *
 * Rather than guess ACF field names or invent rule logic — both explicitly
 * disallowed by the brief, and both capable of producing false pass/fail
 * results on real academic content — this class only registers the
 * validator's identity and routing key. Citex_Validator::resolve_validator_id()
 * routes Harvard + ReferenceList + Book + DragDrop questions here, and
 * self::run() honestly reports every one of them as `unsupported` (never a
 * fabricated pass or fail) until the real field map and rule set are
 * supplied.
 *
 * FIELD_MAP below is the single place those ACF/form field selectors will
 * go once known (per the Phase 3 brief: "Create the validator so field
 * selectors are kept in one clearly documented place"). The matching
 * client-side copy — because the actual fetch-the-edit-page-and-parse-it
 * logic runs in the browser, same as the Phase 2 scanner and the original
 * DevTools QA Checker — lives in admin/js/citex-validator.js under the same
 * FIELD_MAP name; keep the two in sync.
 */
class Citex_Harvard_Book_Dragdrop_Validator {

	const ID = 'harvard-reference-list-book-dragdrop';

	/**
	 * Routing metadata this validator claims, matched exactly against a
	 * scanned question's parsed title (see Citex_Scanner / citex-scanner.js).
	 * Per the brief, category/type values are used exactly as scanned —
	 * never renamed or consolidated.
	 */
	const ROUTES = array(
		'source'   => 'Harvard',
		'group'    => 'ReferenceList',
		'category' => 'Book',
		'type'     => 'DragDrop',
	);

	/**
	 * ACF/question edit-form field selectors this validator reads.
	 * PENDING — populate from the existing QA Checker once its source is
	 * available. Left empty (not guessed) so it's obvious nothing here has
	 * been fabricated.
	 *
	 * Expected shape once populated, e.g.:
	 *   'scenario'         => '#acf-field_xxx textarea',
	 *   'reference_answer' => '#acf-field_xxx',
	 *   'question_parts'   => '.acf-field-xxx .acf-repeater tr',
	 *   'distractors'      => '.acf-field-yyy .acf-repeater tr',
	 */
	const FIELD_MAP = array();

	/**
	 * Whether this validator has a real rule engine yet. False until the
	 * existing QA Checker's rules are ported in — see the class docblock.
	 */
	const IMPLEMENTED = false;
}
