<?php
/**
 * Plugin Name: Citex Tools
 * Plugin URI:  https://github.com/oobaji/citexautomate
 * Description: Citex admin tools for managing academic referencing questions — AI generation, import, validation, population and the question bank overview.
 * Version:     0.41.1
 * Author:      Citex
 * Text Domain: citex-tools
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'CITEX_TOOLS_VERSION', '0.41.1' );
define( 'CITEX_TOOLS_FILE', __FILE__ );
define( 'CITEX_TOOLS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CITEX_TOOLS_URL', plugin_dir_url( __FILE__ ) );
require_once CITEX_TOOLS_PATH . 'includes/class-citex-scanner.php';
require_once CITEX_TOOLS_PATH . 'includes/validators/class-citex-harvard-book-dragdrop-validator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-validator.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-reference-rules.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-book-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-website-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mla-reference-rules.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mla-book-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mla-book-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-book-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-edited-book-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-journal-article-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-website-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-intext-citation-rules.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-intext-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-intext-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mla-intext-citation-rules.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mla-intext-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mla-intext-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mla-edited-book-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mla-edited-book-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mla-journal-article-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mla-journal-article-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mla-website-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mla-website-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-apa-reference-rules.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-apa-book-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-apa-book-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-apa-edited-book-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-apa-edited-book-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-apa-journal-article-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-apa-journal-article-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-apa-website-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-apa-website-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-apa-intext-citation-rules.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-apa-intext-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-apa-intext-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-chicago-reference-rules.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-chicago-book-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-chicago-book-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-chicago-edited-book-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-chicago-edited-book-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-chicago-journal-article-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-chicago-journal-article-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-chicago-website-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-chicago-website-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mhra-reference-rules.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mhra-book-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mhra-book-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mhra-edited-book-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mhra-edited-book-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mhra-journal-article-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mhra-journal-article-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mhra-website-dragdrop-parts.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-mhra-website-mcq-variants.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-question-scenarios.php';
require_once CITEX_TOOLS_PATH . 'includes/class-citex-question-diversity.php';
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
