<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Generate Questions', 'citex-tools' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Generate → validate → populate. Generated questions stay inside Citex until they pass validation; only passed questions can be sent to the real Reference List.', 'citex-tools' ); ?>
	</p>

	<form method="post" class="citex-form">
		<?php wp_nonce_field( Citex_Generator::NONCE_ACTION, 'citex_generate_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="citex_referencing_style"><?php esc_html_e( 'Referencing Style', 'citex-tools' ); ?></label></th><td><select id="citex_referencing_style" name="citex_referencing_style"><?php foreach ( $referencing_styles as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th scope="row"><label for="citex_institution"><?php esc_html_e( 'Institution / Referencing Rules', 'citex-tools' ); ?></label></th><td><select id="citex_institution" name="citex_institution"><?php foreach ( $institutions as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th scope="row"><label for="citex_category"><?php esc_html_e( 'Category', 'citex-tools' ); ?></label></th><td><select id="citex_category" name="citex_category"><?php foreach ( $categories as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th scope="row"><label for="citex_question_type"><?php esc_html_e( 'Question Type', 'citex-tools' ); ?></label></th><td><select id="citex_question_type" name="citex_question_type"><?php foreach ( $question_types as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th scope="row"><label for="citex_difficulty"><?php esc_html_e( 'Difficulty', 'citex-tools' ); ?></label></th><td><select id="citex_difficulty" name="citex_difficulty"><?php foreach ( $difficulties as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( 'medium', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Difficulty currently changes the number of distractor/confusing words.', 'citex-tools' ); ?></p></td></tr>
			<tr><th scope="row"><label for="citex_starting_id"><?php esc_html_e( 'Starting Question ID', 'citex-tools' ); ?></label></th><td><input type="text" id="citex_starting_id" name="citex_starting_id" value="BK01" class="regular-text" /><p class="description"><?php esc_html_e( 'Existing Reference List and pending IDs are skipped automatically.', 'citex-tools' ); ?></p></td></tr>
			<tr><th scope="row"><label for="citex_quantity"><?php esc_html_e( 'Quantity', 'citex-tools' ); ?></label></th><td><input type="number" id="citex_quantity" name="citex_quantity" value="10" min="1" max="100" class="small-text" /></td></tr>
		</table>
		<p class="submit"><button type="submit" name="citex_generate_submit" value="1" class="button button-primary"><?php esc_html_e( 'Generate Pending Questions', 'citex-tools' ); ?></button></p>
	</form>

	<hr />
	<div class="citex-section-heading" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
		<h2><?php esc_html_e( 'Generated / Pending Questions', 'citex-tools' ); ?> (<?php echo esc_html( number_format_i18n( count( $pending_questions ) ) ); ?>)</h2>
		<?php if ( ! empty( $pending_questions ) ) : ?>
			<form method="post" style="display:inline-block;">
				<?php wp_nonce_field( Citex_Generator::NONCE_ACTION, 'citex_generate_nonce' ); ?>
				<button type="submit" name="citex_validate_pending" value="1" class="button button-primary"><?php esc_html_e( 'Validate All Pending', 'citex-tools' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-populate' ) ); ?>"><?php esc_html_e( 'Go to Populate →', 'citex-tools' ); ?></a>
			<form method="post" style="display:inline-block;">
				<?php wp_nonce_field( Citex_Generator::NONCE_ACTION, 'citex_generate_nonce' ); ?>
				<button type="submit" name="citex_clear_pending" value="1" class="button" onclick="return confirm('Clear all pending generated questions? This does not delete Reference List questions.');"><?php esc_html_e( 'Clear Pending', 'citex-tools' ); ?></button>
			</form>
		<?php endif; ?>
	</div>

	<?php if ( empty( $pending_questions ) ) : ?>
		<p><?php esc_html_e( 'No pending generated questions yet.', 'citex-tools' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped citex-table">
			<thead><tr>
				<th style="width:80px;"><?php esc_html_e( 'ID', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Scenario', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Question Parts', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Fixed Text', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Confusing Words', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Reference', 'citex-tools' ); ?></th>
				<th style="width:120px;"><?php esc_html_e( 'Validation', 'citex-tools' ); ?></th>
				<th style="width:150px;"><?php esc_html_e( 'Actions', 'citex-tools' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $pending_questions as $question ) : ?>
				<?php $validation_status = $question['validationStatus'] ?? 'not_validated'; ?>
				<tr>
					<td><strong><?php echo esc_html( $question['questionId'] ?? '—' ); ?></strong><br /><span class="description"><?php echo esc_html( $question['difficulty'] ?? '' ); ?></span></td>
					<td><?php echo esc_html( $question['scenario'] ?? '' ); ?></td>
					<td><?php echo esc_html( implode( ' · ', $question['questionParts'] ?? array() ) ); ?></td>
					<td><code><?php echo esc_html( $question['fixedText'] ?? '' ); ?></code></td>
					<td><?php echo esc_html( implode( ' · ', $question['confusingWords'] ?? array() ) ); ?></td>
					<td><?php echo esc_html( $question['reconstructedReference'] ?? '' ); ?></td>
					<td>
						<?php if ( 'passed' === $validation_status ) : ?>
							<span class="citex-badge citex-badge-passed">✓ Passed</span>
						<?php elseif ( 'failed' === $validation_status ) : ?>
							<span class="citex-badge citex-badge-failed">✕ Failed</span>
							<?php if ( ! empty( $question['validationErrors'] ) ) : ?><ul style="margin:6px 0 0 16px;"><?php foreach ( $question['validationErrors'] as $error ) : ?><li><?php echo esc_html( $error['message'] ?? '' ); ?></li><?php endforeach; ?></ul><?php endif; ?>
						<?php else : ?>
							<span class="citex-badge citex-badge-not_validated">— Not Validated</span>
						<?php endif; ?>
					</td>
					<td>
						<form method="post" style="display:inline-block;margin-right:4px;">
							<?php wp_nonce_field( Citex_Generator::NONCE_ACTION, 'citex_generate_nonce' ); ?>
							<input type="hidden" name="citex_pending_key" value="<?php echo esc_attr( $question['key'] ?? '' ); ?>" />
							<button type="submit" name="citex_validate_one_pending" value="1" class="button button-small"><?php echo 'not_validated' === $validation_status ? esc_html__( 'Validate', 'citex-tools' ) : esc_html__( 'Revalidate', 'citex-tools' ); ?></button>
						</form>
						<form method="post" style="display:inline-block;">
							<?php wp_nonce_field( Citex_Generator::NONCE_ACTION, 'citex_generate_nonce' ); ?>
							<input type="hidden" name="citex_pending_key" value="<?php echo esc_attr( $question['key'] ?? '' ); ?>" />
							<button type="submit" name="citex_delete_pending" value="1" class="button button-small"><?php esc_html_e( 'Remove', 'citex-tools' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
