<?php
/**
 * Plugin Name: Citex Tools
 * Plugin URI:  https://github.com/oobaji/citexautomate
 * Description: Citex admin tools for managing academic referencing questions — question generation, validation, population and the question bank overview.
 * Version:     0.6.0
 * Author:      Citex
 * Text Domain: citex-tools
 *
 * Phase 1 shipped the admin interface and plugin architecture shell.
 * Phase 2 connects the Dashboard and Questions page to the real WordPress
 * question records via a read-only browser-side scanner.
 * Phase 3 adds read-only validation, including the live Harvard / Book /
 * DragDrop placeholder grammar and Book-format diagnostics.
 *
 * v0.6.0 introduces Generator v1. It creates structured Liverpool Hope
 * Harvard / ReferenceList / Book / DragDrop questions in a Citex pending
 * store using synthetic source data. Generated questions are previewable and
 * removable, and the Dashboard shows the real pending count. Generation does
 * NOT create or modify WordPress question posts; population remains a separate
 * later phase.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

define( 'CITEX_TOOLS_VERSION', '0.6.0' );
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

/** Boot the plugin once all plugins are loaded. */
function citex_tools_init() {
	new Citex_Admin();
}
add_action( 'plugins_loaded', 'citex_tools_init' );
