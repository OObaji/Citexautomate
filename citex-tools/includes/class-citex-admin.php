<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Registers the Citex admin menu, loads Citex assets only on Citex
 * screens, and provides a shared one-time admin-notice helper used by
 * the other Citex modules (generator, validator, populator).
 */
class Citex_Admin {

	const NOTICE_TRANSIENT_PREFIX = 'citex_notice_';

	private $dashboard;
	private $generator;
	private $questions;
	private $validator;
	private $populator;
	private $scanner;

	/**
	 * Hook suffixes for the registered Citex pages, used to scope
	 * asset loading to Citex screens only.
	 */
	private $page_hooks = array();

	public function __construct() {
		$this->scanner    = new Citex_Scanner();
		$this->dashboard  = new Citex_Dashboard();
		$this->generator  = new Citex_Generator();
		$this->questions  = new Citex_Questions();
		$this->validator  = new Citex_Validator();
		$this->populator  = new Citex_Populator();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Registers the top-level Citex menu and its submenu pages.
	 */
	public function register_menu() {
		$this->page_hooks[] = add_menu_page(
			__( 'Citex', 'citex-tools' ),
			__( 'Citex', 'citex-tools' ),
			'manage_options',
			'citex',
			array( $this->dashboard, 'render' ),
			'dashicons-book-alt',
			30
		);

		$this->page_hooks[] = add_submenu_page(
			'citex',
			__( 'Dashboard', 'citex-tools' ),
			__( 'Dashboard', 'citex-tools' ),
			'manage_options',
			'citex',
			array( $this->dashboard, 'render' )
		);

		$this->page_hooks[] = add_submenu_page(
			'citex',
			__( 'Generate Questions', 'citex-tools' ),
			__( 'Generate Questions', 'citex-tools' ),
			'manage_options',
			'citex-generate',
			array( $this->generator, 'render' )
		);

		$this->page_hooks[] = add_submenu_page(
			'citex',
			__( 'Questions', 'citex-tools' ),
			__( 'Questions', 'citex-tools' ),
			'manage_options',
			'citex-questions',
			array( $this->questions, 'render' )
		);

		$this->page_hooks[] = add_submenu_page(
			'citex',
			__( 'Validation', 'citex-tools' ),
			__( 'Validation', 'citex-tools' ),
			'manage_options',
			'citex-validation',
			array( $this->validator, 'render' )
		);

		$this->page_hooks[] = add_submenu_page(
			'citex',
			__( 'Populate', 'citex-tools' ),
			__( 'Populate', 'citex-tools' ),
			'manage_options',
			'citex-populate',
			array( $this->populator, 'render' )
		);
	}

	/**
	 * Loads Citex CSS/JS only on Citex admin screens.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, $this->page_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'citex-admin',
			CITEX_TOOLS_URL . 'admin/css/citex-admin.css',
			array(),
			self::asset_version( 'admin/css/citex-admin.css' )
		);

		wp_enqueue_script(
			'citex-scanner',
			CITEX_TOOLS_URL . 'admin/js/citex-scanner.js',
			array(),
			self::asset_version( 'admin/js/citex-scanner.js' ),
			true
		);

		wp_enqueue_script(
			'citex-validator',
			CITEX_TOOLS_URL . 'admin/js/citex-validator.js',
			array(),
			self::asset_version( 'admin/js/citex-validator.js' ),
			true
		);

		wp_enqueue_script(
			'citex-admin',
			CITEX_TOOLS_URL . 'admin/js/citex-admin.js',
			array( 'citex-scanner', 'citex-validator' ),
			self::asset_version( 'admin/js/citex-admin.js' ),
			true
		);

		wp_localize_script(
			'citex-admin',
			'citexTools',
			array(
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
				'validator'          => array(
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
			)
		);
	}

	/**
	 * Cache-busting version for a plugin asset: the file's own last-modified
	 * time rather than the static CITEX_TOOLS_VERSION constant, so a fix
	 * that changes admin/js/citex-scanner.js (or any other asset) always
	 * gets a new ?ver= on the next enqueue and can't keep being served from
	 * a stale browser/host/CDN cache under the old, unchanged URL.
	 *
	 * @param string $relative_path Asset path relative to the plugin root, e.g. 'admin/js/citex-scanner.js'.
	 * @return string|int
	 */
	private static function asset_version( $relative_path ) {
		$file = CITEX_TOOLS_PATH . $relative_path;
		$mtime = file_exists( $file ) ? filemtime( $file ) : false;
		return false !== $mtime ? $mtime : CITEX_TOOLS_VERSION;
	}

	/**
	 * Queues a one-time admin notice for the current user. Used with the
	 * POST/redirect/GET pattern so a page refresh doesn't resubmit a form.
	 *
	 * @param string $message Notice text.
	 * @param string $type    One of 'success', 'warning', 'error', 'info'.
	 */
	public static function set_notice( $message, $type = 'info' ) {
		set_transient(
			self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			60
		);
	}

	/**
	 * Outputs and clears the queued notice, if any.
	 */
	public function render_notice() {
		$key    = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! $notice ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['message'] )
		);
	}
}
