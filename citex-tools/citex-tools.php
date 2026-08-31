<?php
/**
 * Plugin Name: Citex Tools
 * Plugin URI:  https://github.com/oobaji/citexautomate
 * Description: Citex admin tools for managing academic referencing questions — question generation, validation, population and the question bank overview.
 * Version:     0.5.0
 * Author:      Citex
 * Text Domain: citex-tools
 *
 * Phase 1 shipped the admin interface and plugin architecture shell.
 * Phase 2 connects the Dashboard and Questions page to the real
 * WordPress question records via a read-only browser-side scanner (see
 * includes/class-citex-scanner.php and admin/js/citex-scanner.js).
 * Phase 3 adds read-only validation (see includes/class-citex-validator.php
 * and admin/js/citex-validator.js): each indexed question is routed to a
 * validator id or left unsupported, results persist across refresh, and
 * the Dashboard/Questions page reflect them. The Harvard/ReferenceList/
 * Book/DragDrop validator (see
 * includes/validators/class-citex-harvard-book-dragdrop-validator.php) has
 * a real v1 rule engine, ported from recovered details of the original QA
 * Checker v0.3: Fixed Text + "|" placeholder + Question Parts
 * reconstruction, the YEAR_TRAILING_PERIOD / MISSING_FINAL_PERIOD
 * punctuation checks, and the Liverpool Hope Book structural check.
 * Question generation and WordPress population are still not implemented
 * — see the remaining includes/class-citex-*.php modules those features
 * will be plugged into later without rewriting the plugin.
 *
 * v0.4.0 added live routing diagnostics to the Validation page's Details
 * panel (Citex_Validator::diagnose_routing()) and displays the installed
 * plugin version on the Dashboard, for diagnosing why a question isn't
 * routing as expected on a given install without guesswork.
 *
 * v0.5.0 replaces the Harvard/ReferenceList/Book/DragDrop routing stub
 * with that real v1 rule engine and extends the Details panel with the
 * extracted fields, reconstructed reference, and rule-engine-executed
 * flag for the last validation run.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

define( 'CITEX_TOOLS_VERSION', '0.5.0' );
define( 'CITEX_TOOLS_FILE', __FILE__ );
define( 'CITEX_TOOLS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CITEX_TOOLS_URL', plugin_dir_url( __FILE__ ) );

require_once CITEX_TOOLS_PATH . 'includes/class-citex-scanner.php';
require_once CITEX_TOOLS_PATH . 'includes/validators/class-citex-harvard-book-dragdrop-validator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-validator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-dashboard.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-generator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-questions.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-populator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-admin.php';

/**
 * Boot the plugin once all plugins are loaded.
 */
function citex_tools_init() {
	new Citex_Admin();
}
add_action( 'plugins_loaded', 'citex_tools_init' );
