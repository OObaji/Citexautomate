<?php
/**
 * Plugin Name: Citex Tools
 * Plugin URI:  https://github.com/oobaji/citexautomate
 * Description: Citex admin tools for managing academic referencing questions — question generation, validation, population and the question bank overview.
 * Version:     0.1.0
 * Author:      Citex
 * Text Domain: citex-tools
 *
 * Phase 1 shipped the admin interface and plugin architecture shell.
 * Phase 2 connects the Dashboard and Questions page to the real
 * WordPress question records via a read-only browser-side scanner (see
 * includes/class-citex-scanner.php and admin/js/citex-scanner.js).
 * Question generation, validation and WordPress population are still
 * not implemented — see the remaining includes/class-citex-*.php
 * modules those features will be plugged into later without rewriting
 * the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

define( 'CITEX_TOOLS_VERSION', '0.1.0' );
define( 'CITEX_TOOLS_FILE', __FILE__ );
define( 'CITEX_TOOLS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CITEX_TOOLS_URL', plugin_dir_url( __FILE__ ) );

require_once CITEX_TOOLS_PATH . 'includes/class-citex-scanner.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-dashboard.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-generator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-questions.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-validator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-populator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-admin.php';

/**
 * Boot the plugin once all plugins are loaded.
 */
function citex_tools_init() {
	new Citex_Admin();
}
add_action( 'plugins_loaded', 'citex_tools_init' );
