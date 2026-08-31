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

	/**
	 * Hook suffixes for the registered Citex pages, used to scope
	 * asset loading to Citex screens only.
	 */
	private $page_hooks = array();

	public function __construct() {
		$this->dashboard = new Citex_Dashboard();
		$this->generator = new Citex_Generator();
		$this->questions = new Citex_Questions();
		$this->validator = new Citex_Validator();
		$this->populator = new Citex_Populator();

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
			CITEX_TOOLS_VERSION
		);

		wp_enqueue_script(
			'citex-admin',
			CITEX_TOOLS_URL . 'admin/js/citex-admin.js',
			array(),
			CITEX_TOOLS_VERSION,
			true
		);
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
