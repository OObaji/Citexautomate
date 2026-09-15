<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Populate Questions', 'citex-tools' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Only pending questions that have passed Citex validation are eligible — whether they were generated inside Citex or imported from CSV/JSON. Passed records are created in the real Reference List and then synced back into the Citex Question Bank.', 'citex-tools' ); ?></p>

	<div class="citex-stat-cards citex-stat-cards-compact">
		<div class="citex-card"><span class="citex-card-label"><?php esc_html_e( 'Ready to populate', 'citex-tools' ); ?></span><span class="citex-card-value"><?php echo esc_html( $status['ready'] ); ?></span></div>
		<div class="citex-card"><span class="citex-card-label"><?php esc_html_e( 'Passed validation', 'citex-tools' ); ?></span><span class="citex-card-value"><?php echo esc_html( $status['passed'] ); ?></span></div>
		<div class="citex-card"><span class="citex-card-label"><?php esc_html_e( 'Failed validation', 'citex-tools' ); ?></span><span class="citex-card-value"><?php echo esc_html( $status['failed'] ); ?></span></div>
		<div class="citex-card"><span class="citex-card-label"><?php esc_html_e( 'Not validated', 'citex-tools' ); ?></span><span class="citex-card-value"><?php echo esc_html( $status['not_validated'] ); ?></span></div>
	</div>

	<?php if ( empty( $pending ) ) : ?>
		<p><?php esc_html_e( 'There are no pending questions. Generate them in Citex or import them from another source first.', 'citex-tools' ); ?></p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-generate' ) ); ?>"><?php esc_html_e( 'Generate Questions', 'citex-tools' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-import' ) ); ?>"><?php esc_html_e( 'Import Questions', 'citex-tools' ); ?></a>
		</p>
	<?php else : ?>
		<form method="post" class="citex-form">
			<?php wp_nonce_field( Citex_Populator::NONCE_ACTION, 'citex_populate_nonce' ); ?>

			<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:16px 0;">
				<label><strong><?php esc_html_e( 'Scope:', 'citex-tools' ); ?></strong>
					<select name="citex_population_scope">
						<option value="all_passed"><?php printf( esc_html__( 'All passed questions (%d)', 'citex-tools' ), (int) $status['passed'] ); ?></option>
						<option value="selected"><?php esc_html_e( 'Selected passed questions', 'citex-tools' ); ?></option>
					</select>
				</label>
				<label><strong><?php esc_html_e( 'Create as:', 'citex-tools' ); ?></strong>
					<select name="citex_population_status">
						<option value="draft"><?php esc_html_e( 'Draft (recommended)', 'citex-tools' ); ?></option>
						<option value="publish"><?php esc_html_e( 'Published', 'citex-tools' ); ?></option>
					</select>
				</label>
				<button type="submit" name="citex_populate_submit" value="1" class="button button-primary" <?php disabled( 0 === (int) $status['passed'] ); ?> onclick="return confirm('Create the selected validated questions in the real WordPress Reference List?');"><?php esc_html_e( 'Populate Reference List', 'citex-tools' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-generate' ) ); ?>"><?php esc_html_e( '← Review / Validate', 'citex-tools' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-import' ) ); ?>"><?php esc_html_e( 'Import More', 'citex-tools' ); ?></a>
			</div>

			<table class="wp-list-table widefat fixed striped citex-table">
				<thead><tr>
					<td class="manage-column column-cb check-column"><input type="checkbox" id="citex-select-all" /></td>
					<th><?php esc_html_e( 'ID', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'Origin', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'Scenario', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'Reference', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'Validation', 'citex-tools' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $pending as $question ) : ?>
					<?php
					$validation = $question['validationStatus'] ?? 'not_validated';
					$origin = (string) ( $question['origin'] ?? 'generated' );
					$origin_label = 0 === strpos( $origin, 'imported_' ) ? 'Imported' : 'Generated';
					?>
					<tr>
						<th scope="row" class="check-column"><input type="checkbox" class="citex-row-select" name="citex_populate_keys[]" value="<?php echo esc_attr( $question['key'] ?? '' ); ?>" <?php disabled( 'passed' !== $validation ); ?> /></th>
						<td><strong><?php echo esc_html( $question['questionId'] ?? '—' ); ?></strong><?php if ( ! empty( $question['category'] ) ) : ?><br /><span class="description"><?php echo esc_html( $question['category'] ); ?></span><?php endif; ?></td>
						<td><strong><?php echo esc_html( $origin_label ); ?></strong><?php if ( ! empty( $question['importSource'] ) ) : ?><br /><span class="description"><?php echo esc_html( $question['importSource'] ); ?></span><?php endif; ?></td>
						<td><?php echo esc_html( $question['scenario'] ?? '' ); ?></td>
						<td><?php echo esc_html( $question['reconstructedReference'] ?? '' ); ?></td>
						<td>
							<?php if ( 'passed' === $validation ) : ?><span class="citex-badge citex-badge-passed">✓ Passed / Ready</span>
							<?php elseif ( 'failed' === $validation ) : ?><span class="citex-badge citex-badge-failed">✕ Failed</span>
							<?php else : ?><span class="citex-badge citex-badge-not_validated">— Not Validated</span><?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</form>
	<?php endif; ?>
</div>
