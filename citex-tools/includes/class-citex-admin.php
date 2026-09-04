<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Citex_Admin {

	const NOTICE_TRANSIENT_PREFIX = 'citex_notice_';

	private $dashboard;
	private $generator;
	private $importer;
	private $questions;
	private $validator;
	private $populator;
	private $scanner;
	private $bulk_editor;
	private $diagnostics;
	private $page_hooks = array();

	public function __construct() {
		$this->scanner     = new Citex_Scanner();
		$this->dashboard   = new Citex_Dashboard();
		$this->generator   = new Citex_Generator();
		$this->importer    = new Citex_Importer();
		$this->questions   = new Citex_Questions();
		$this->validator   = new Citex_Validator();
		$this->populator   = new Citex_Populator();
		$this->bulk_editor = new Citex_Bulk_Editor();
		$this->diagnostics = new Citex_Diagnostics();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );

		// Form submissions must be processed on admin_init — before WordPress
		// prints the admin header/menu/notices — so a redirect back to the
		// Citex page actually reaches the browser. Doing this from inside a
		// page's own render callback is too late: admin-header.php has
		// already streamed output by the time that callback runs, so
		// wp_safe_redirect() silently fails (headers already sent) and the
		// user is left looking at a half-printed, effectively blank page.
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );

		if ( is_admin() ) {
			register_shutdown_function( array( $this, 'handle_admin_shutdown' ) );
		}
	}

	/**
	 * Run every Citex action handler before any admin output has started.
	 * Each handler only acts when its own $_POST marker is present, so it is
	 * safe to call all of them unconditionally on every wp-admin request.
	 * A Throwable here still can't be avoided by the shutdown handler alone
	 * (which gives up once headers are already sent), so it is caught here
	 * instead, while output can still be safely redirected.
	 */
	public function handle_admin_actions() {
		$handlers = array(
			array( $this->generator, 'maybe_handle_submit' ),
			array( $this->importer, 'maybe_handle_submit' ),
			array( $this->populator, 'maybe_handle_submit' ),
			array( $this->populator, 'maybe_handle_finalize_submit' ),
			array( $this->questions, 'maybe_handle_sync_submit' ),
			array( 'Citex_AI_V2', 'maybe_handle_submit' ),
			array( $this->diagnostics, 'maybe_handle_submit' ),
		);

		foreach ( $handlers as $handler ) {
			try {
				call_user_func( $handler );
			} catch ( Throwable $e ) {
				error_log( '[Citex Tools] Admin action failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
				self::set_notice(
					sprintf(
						/* translators: %s: error message */
						__( 'Citex: action failed — %s. See the WordPress/PHP error log for full details.', 'citex-tools' ),
						$e->getMessage()
					),
					'error'
				);
				if ( ! headers_sent() ) {
					wp_safe_redirect( admin_url( 'admin.php?page=citex' ) );
					exit;
				}
			}
		}
	}

	public function register_menu() {
		$this->page_hooks[] = add_menu_page( __( 'Citex', 'citex-tools' ), __( 'Citex', 'citex-tools' ), 'manage_options', 'citex', array( $this->dashboard, 'render' ), 'dashicons-book-alt', 30 );
		$this->page_hooks[] = add_submenu_page( 'citex', __( 'Dashboard', 'citex-tools' ), __( 'Dashboard', 'citex-tools' ), 'manage_options', 'citex', array( $this->dashboard, 'render' ) );
		$this->page_hooks[] = add_submenu_page( 'citex', __( 'Generate Questions', 'citex-tools' ), __( 'Generate Questions', 'citex-tools' ), 'manage_options', 'citex-generate', array( $this->generator, 'render' ) );
		$this->page_hooks[] = add_submenu_page( 'citex', __( 'AI Settings', 'citex-tools' ), __( 'AI Settings', 'citex-tools' ), 'manage_options', 'citex-ai', array( 'Citex_AI_V2', 'render_settings' ) );
		$this->page_hooks[] = add_submenu_page( 'citex', __( 'Import Questions', 'citex-tools' ), __( 'Import Questions', 'citex-tools' ), 'manage_options', 'citex-import', array( $this->importer, 'render' ) );
		$this->page_hooks[] = add_submenu_page( 'citex', __( 'Questions', 'citex-tools' ), __( 'Questions', 'citex-tools' ), 'manage_options', 'citex-questions', array( $this->questions, 'render' ) );
		$this->page_hooks[] = add_submenu_page( 'citex', __( 'Validation', 'citex-tools' ), __( 'Validation', 'citex-tools' ), 'manage_options', 'citex-validation', array( $this->validator, 'render' ) );
		$this->page_hooks[] = add_submenu_page( 'citex', __( 'Populate', 'citex-tools' ), __( 'Populate', 'citex-tools' ), 'manage_options', 'citex-populate', array( $this->populator, 'render' ) );
		$this->page_hooks[] = add_submenu_page( 'citex', __( 'Diagnostics', 'citex-tools' ), __( 'Diagnostics', 'citex-tools' ), 'manage_options', 'citex-diagnostics', array( $this->diagnostics, 'render' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, $this->page_hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'citex-admin', CITEX_TOOLS_URL . 'admin/css/citex-admin.css', array(), self::asset_version( 'admin/css/citex-admin.css' ) );
		wp_enqueue_script( 'citex-scanner', CITEX_TOOLS_URL . 'admin/js/citex-scanner.js', array(), self::asset_version( 'admin/js/citex-scanner.js' ), true );
		wp_enqueue_script( 'citex-validator', CITEX_TOOLS_URL . 'admin/js/citex-validator.js', array(), self::asset_version( 'admin/js/citex-validator.js' ), true );
		wp_enqueue_script( 'citex-validator-site-adapter', CITEX_TOOLS_URL . 'admin/js/citex-validator-site-adapter.js', array( 'citex-validator' ), self::asset_version( 'admin/js/citex-validator-site-adapter.js' ), true );
		wp_enqueue_script( 'citex-admin', CITEX_TOOLS_URL . 'admin/js/citex-admin.js', array( 'citex-scanner', 'citex-validator-site-adapter' ), self::asset_version( 'admin/js/citex-admin.js' ), true );
		wp_enqueue_script( 'citex-bulk-edit', CITEX_TOOLS_URL . 'admin/js/citex-bulk-edit.js', array( 'citex-admin' ), self::asset_version( 'admin/js/citex-bulk-edit.js' ), true );

		wp_localize_script( 'citex-admin', 'citexTools', array(
			'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
			'nonce'              => wp_create_nonce( Citex_Scanner::NONCE_ACTION ),
			'questionListUrl'    => Citex_Scanner::get_question_list_url(),
			'saveSettingsAction' => Citex_Scanner::AJAX_SAVE_SETTINGS,
			'saveScanAction'     => Citex_Scanner::AJAX_SAVE_SCAN,
			'strings'            => array(
				'savingSettings' => __( 'Saving…', 'citex-tools' ),
				'settingsSaved'  => __( 'Saved.', 'citex-tools' ),
				'settingsFailed' => __( 'Could not save the setting.', 'citex-tools' ),
				'noUrl'          => __( 'Set the Question List URL first (Dashboard).', 'citex-tools' ),
				'scanningPage'   => __( 'Scanning page {page} of {total}...', 'citex-tools' ),
				'scanComplete'   => __( 'Scan complete — {total} questions found.', 'citex-tools' ),
				'scanFailed'     => __( 'Scan failed:', 'citex-tools' ),
			),
			'validator' => array(
				'nonce'            => wp_create_nonce( Citex_Validator::NONCE_ACTION ),
				'saveResultAction' => Citex_Validator::AJAX_SAVE_RESULT,
				'questions'        => Citex_Validator::build_client_queue(),
				'strings'          => array(
					'validating'       => __( 'Validating {index} of {total}... {questionId}', 'citex-tools' ),
					'validateComplete' => __( 'Validation complete. Passed: {passed}  Failed: {failed}  Warnings: {warnings}  Unsupported: {unsupported}', 'citex-tools' ),
					'validateFailed'   => __( 'Validation failed:', 'citex-tools' ),
					'noSelection'      => __( 'Select at least one question to validate.', 'citex-tools' ),
				),
			),
		) );

		wp_localize_script( 'citex-bulk-edit', 'citexBulkEdit', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'action'    => Citex_Bulk_Editor::AJAX_UPDATE_STATUS,
			'nonce'     => wp_create_nonce( Citex_Bulk_Editor::NONCE_ACTION ),
			'batchSize' => 40,
			'strings'   => array(
				'noSelection' => __( 'No question posts are available in that scope.', 'citex-tools' ),
				'confirm'     => __( 'Change the WordPress status of {count} question(s) to {status}? This updates the live WordPress posts.', 'citex-tools' ),
				'updating'    => __( 'Updating questions {from}–{to} of {total}…', 'citex-tools' ),
				'complete'    => __( 'Bulk update complete — updated: {updated}, already set: {skipped}, failed: {failed}.', 'citex-tools' ),
				'failed'      => __( 'Bulk update failed:', 'citex-tools' ),
			),
		) );
	}

	private static function asset_version( $relative_path ) {
		$file  = CITEX_TOOLS_PATH . $relative_path;
		$mtime = file_exists( $file ) ? filemtime( $file ) : false;
		return false !== $mtime ? $mtime : CITEX_TOOLS_VERSION;
	}

	public static function set_notice( $message, $type = 'info' ) {
		set_transient( self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(), array(
			'message' => wp_strip_all_tags( (string) $message ),
			'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
		), 60 );
	}

	public function render_notice() {
		$key    = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$type = isset( $notice['type'] ) && in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible citex-action-notice"><p><strong>Citex:</strong> ' . esc_html( $notice['message'] ) . '</p></div>';
	}

	/**
	 * Secondary safety net only. handle_admin_actions() (hooked to admin_init,
	 * before any output) is what actually prevents blank Citex screens, by
	 * catching Throwables and redirecting while it's still safe to do so.
	 * This shutdown handler only helps with fatals that occur outside those
	 * handlers (for example while a view template itself is rendering) and
	 * gives up once headers are already sent, so it cannot recover a request
	 * on its own.
	 */
	public function handle_admin_shutdown() {
		$error = error_get_last();
		if ( ! is_array( $error ) ) {
			return;
		}

		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
		if ( ! in_array( (int) $error['type'], $fatal_types, true ) ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( false === strpos( $uri, 'wp-admin' ) && false === strpos( $uri, 'admin-ajax.php' ) ) {
			return;
		}

		$message = sprintf(
			'Citex action failed: %s (line %d). See the WordPress/PHP error log for full details.',
			isset( $error['message'] ) ? wp_strip_all_tags( (string) $error['message'] ) : 'Unknown PHP error',
			isset( $error['line'] ) ? (int) $error['line'] : 0
		);
		error_log( '[Citex Tools] ' . $message . ' File: ' . ( isset( $error['file'] ) ? $error['file'] : 'unknown' ) );

		if ( false !== strpos( $uri, 'admin-ajax.php' ) || headers_sent() ) {
			return;
		}

		self::set_notice( $message, 'error' );
		wp_safe_redirect( admin_url( 'admin.php?page=citex' ) );
		exit;
	}
}
