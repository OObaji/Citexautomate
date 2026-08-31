<?php
/**
 * Plugin Name: Citex Tools
 * Plugin URI:  https://github.com/oobaji/citexautomate
 * Description: Citex admin tools for managing academic referencing questions — question generation, validation, population and the question bank overview.
 * Version:     0.9.0
 * Author:      Citex
 * Text Domain: citex-tools
 *
 * Phase 1: admin application shell.
 * Phase 2: real WordPress question scanner/index.
 * Phase 3: read-only validation framework and Harvard Book DragDrop rules.
 * v0.6.0: structured pending Book/DragDrop question generator.
 * v0.7.x: bulk Reference List status editing.
 * v0.8.x: real Reference List status mirroring, server-side sync and Bin.
 * v0.9.0: end-to-end generated-question pipeline: generated pending records can
 * be validated before population; only passed records can be created in the
 * real Reference List. Population reuses the live Book/DragDrop ACF/taxonomy
 * structure and creates Drafts by default, then re-syncs the Question Bank.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CITEX_TOOLS_VERSION', '0.9.0' );
define( 'CITEX_TOOLS_FILE', __FILE__ );
define( 'CITEX_TOOLS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CITEX_TOOLS_URL', plugin_dir_url( __FILE__ ) );

require_once CITEX_TOOLS_PATH . 'includes/class-citex-scanner.php';
require_once CITEX_TOOLS_PATH . 'includes/validators/class-citex-harvard-book-dragdrop-validator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-validator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-generated-validator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-dashboard.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-generator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-bulk-editor.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-questions.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-populator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-admin.php';

function citex_tools_init() {
	new Citex_Admin();
}
add_action( 'plugins_loaded', 'citex_tools_init' );
