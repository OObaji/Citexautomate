<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Populate Questions page.
 *
 * Renders the populate/publish controls. The engine that writes
 * generated and validated questions into WordPress is a future module
 * that will plug into maybe_handle_submit() below without changing
 * this page.
 */
class Citex_Populator {

	const NONCE_ACTION = 'citex_populate_questions';

	public function render() {
		$this->maybe_handle_submit();

		$sources = array(
			'generated' => 'Generated Questions',
			'imported'  => 'Imported Questions',
			'manual'    => 'Manually Selected Questions',
		);

		// PLACEHOLDER DATA — replace once population status is connected.
		$status = array(
			'ready'  => '—',
			'passed' => '—',
			'failed' => '—',
		);

		require CITEX_TOOLS_PATH . 'admin/views/populate.php';
	}

	/**
	 * Handles the (currently stubbed) populate submission and redirects
	 * back to avoid a resubmission on refresh. No WordPress content is
	 * created or modified — this only surfaces the "not yet connected"
	 * notice.
	 */
	private function maybe_handle_submit() {
		if ( empty( $_POST['citex_populate_submit'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, 'citex_populate_nonce' );

		// Population engine is not yet connected — no WordPress content is created here.
		Citex_Admin::set_notice(
			__( 'WordPress population engine has not yet been connected.', 'citex-tools' ),
			'warning'
		);

		wp_safe_redirect( admin_url( 'admin.php?page=citex-populate' ) );
		exit;
	}
}
