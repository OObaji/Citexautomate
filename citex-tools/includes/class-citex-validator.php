<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Question Validation page.
 *
 * Renders validation summary cards and results. The actual validation
 * engine (Liverpool Hope Harvard rule set) is a future module that will
 * plug into maybe_handle_actions() below without changing this page.
 */
class Citex_Validator {

	const NONCE_ACTION = 'citex_validate_questions';

	public function render() {
		$this->maybe_handle_actions();

		// PLACEHOLDER DATA — replace once the validation engine is connected.
		$summary = array(
			'scanned'  => '—',
			'passed'   => '—',
			'failed'   => '—',
			'warnings' => '—',
		);

		// DEMO DATA — see get_demo_result() below.
		$demo_result = self::get_demo_result();

		require CITEX_TOOLS_PATH . 'admin/views/validation.php';
	}

	/**
	 * Handles the (currently stubbed) validate actions and redirects
	 * back to avoid a resubmission on refresh. Nothing is validated —
	 * this only surfaces the "not yet connected" notice.
	 */
	private function maybe_handle_actions() {
		if ( empty( $_POST['citex_validate_action'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, 'citex_validate_nonce' );

		// Validation engine is not yet connected — nothing is validated here.
		Citex_Admin::set_notice(
			__( 'Validation engine has not yet been connected.', 'citex-tools' ),
			'warning'
		);

		wp_safe_redirect( admin_url( 'admin.php?page=citex-validation' ) );
		exit;
	}

	/**
	 * DEMO DATA ONLY — illustrates the intended failed-record UI.
	 * Remove once the validation engine returns real results.
	 *
	 * @return array Demo failed validation record.
	 */
	private static function get_demo_result() {
		return array(
			'id'     => 'BK002',
			'status' => 'Failed',
			'errors' => array(
				'Example validation error',
				'Example formatting error',
			),
		);
	}
}
