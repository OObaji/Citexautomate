<?php
/**
 * Plugin Name: Citex Tools
 * Plugin URI:  https://github.com/oobaji/citexautomate
 * Description: Citex admin tools for managing academic referencing questions — AI generation, import, validation, population and the question bank overview.
 * Version:     0.11.1
 * Author:      Citex
 * Text Domain: citex-tools
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'CITEX_TOOLS_VERSION', '0.11.1' );
define( 'CITEX_TOOLS_FILE', __FILE__ );
define( 'CITEX_TOOLS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CITEX_TOOLS_URL', plugin_dir_url( __FILE__ ) );
require_once CITEX_TOOLS_PATH . 'includes/class-citex-scanner.php';
require_once CITEX_TOOLS_PATH . 'includes/validators/class-citex-harvard-book-dragdrop-validator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-validator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-reference-rules.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-generated-validator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-dashboard.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-ai-v2.php';
if ( ! class_exists( 'Citex_AI', false ) ) { class Citex_AI extends Citex_AI_V2 {} }
require_once CITEX_TOOLS_PATH . 'includes/class-citex-generator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-importer.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-bulk-editor.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-questions.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-populator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-diagnostics.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-admin.php';
function citex_tools_init() { new Citex_Admin(); }
add_action( 'plugins_loaded', 'citex_tools_init' );
