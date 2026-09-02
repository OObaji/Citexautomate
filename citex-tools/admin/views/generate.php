<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Generate Questions', 'citex-tools' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Generate real questions with Gemini → keep them pending → validate with Citex rules → populate only approved questions into the real Reference List.', 'citex-tools' ); ?>
	</p>

	<?php if ( ! $ai_configured ) : ?>
		<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Gemini is not configured.', 'citex-tools' ); ?></strong> <?php esc_html_e( 'Add your API key in AI Settings before generating questions.', 'citex-tools' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=citex-ai' ) ); ?>"><?php esc_html_e( 'Open AI Settings →', 'citex-tools' ); ?></a></p></div>
	<?php else : ?>
		<div class="notice notice-success inline"><p><strong><?php esc_html_e( 'Gemini connected.', 'citex-tools' ); ?></strong> <?php echo esc_html( Citex_AI::get_model() ); ?><?php if ( Citex_AI::web_verification_enabled() ) : ?> — <?php esc_html_e( 'web verification enabled', 'citex-tools' ); ?><?php endif; ?>.</p></div>
	<?php endif; ?>

	<form method="post" class="citex-form">
		<?php wp_nonce_field( Citex_Generator::NONCE_ACTION, 'citex_generate_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="citex_referencing_style"><?php esc_html_e( 'Referencing Style', 'citex-tools' ); ?></label></th><td><select id="citex_referencing_style" name="citex_referencing_style"><?php foreach ( $referencing_styles as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th scope="row"><label for="citex_institution"><?php esc_html_e( 'Institution / Referencing Rules', 'citex-tools' ); ?></label></th><td><select id="citex_institution" name="citex_institution"><?php foreach ( $institutions as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th scope="row"><label for="citex_category"><?php esc_html_e( 'Category', 'citex-tools' ); ?></label></th><td><select id="citex_category" name="citex_category"><?php foreach ( $categories as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" data-id-prefix="<?php echo esc_attr( $id_prefixes[ $value ] ?? '' ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th scope="row"><label for="citex_question_type"><?php esc_html_e( 'Question Type', 'citex-tools' ); ?></label></th><td><select id="citex_question_type" name="citex_question_type"><?php foreach ( $question_types as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th scope="row"><label for="citex_difficulty"><?php esc_html_e( 'Difficulty', 'citex-tools' ); ?></label></th><td><select id="citex_difficulty" name="citex_difficulty"><?php foreach ( $difficulties as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( 'medium', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th scope="row"><label for="citex_starting_id"><?php esc_html_e( 'Starting Question ID', 'citex-tools' ); ?></label></th><td><input type="text" id="citex_starting_id" name="citex_starting_id" value="<?php echo esc_attr( ( $id_prefixes['book'] ?? 'BK' ) . '01' ); ?>" class="regular-text" /><p class="description"><?php esc_html_e( 'Each category has its own ID prefix (e.g. BK for Book, ED for Edited Book) and starts its own numbering fresh at 01 — updates automatically when you change Category. Existing Reference List and pending IDs within that category are skipped automatically.', 'citex-tools' ); ?></p></td></tr>
			<tr><th scope="row"><label for="citex_quantity"><?php esc_html_e( 'Quantity', 'citex-tools' ); ?></label></th><td><input type="number" id="citex_quantity" name="citex_quantity" value="20" min="1" max="100" class="small-text" /><p class="description"><?php esc_html_e( 'Generate up to 100 questions in one batch.', 'citex-tools' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Bibliographic Verification', 'citex-tools' ); ?></th><td><label><input type="checkbox" name="citex_ai_web_verify" value="1" <?php checked( Citex_AI::web_verification_enabled(), true ); ?> /> <?php esc_html_e( 'Use Gemini Google Search to verify books, authors, years, publishers and places before returning questions.', 'citex-tools' ); ?></label><p class="description"><?php esc_html_e( 'Recommended for real questions. It may use additional Gemini tool quota.', 'citex-tools' ); ?></p></td></tr>
		</table>
		<p class="submit"><button type="submit" name="citex_generate_submit" value="1" class="button button-primary" <?php disabled( ! $ai_configured ); ?>><?php esc_html_e( 'Generate Real Questions with Gemini', 'citex-tools' ); ?></button> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-ai' ) ); ?>"><?php esc_html_e( 'AI Settings', 'citex-tools' ); ?></a></p>
	</form>
	<script>
	( function () {
		// Keeps "Starting Question ID" in sync with the selected Category's
		// own ID prefix (BK/ED/...) so each category visibly starts fresh at
		// 01 instead of showing a leftover prefix from a different category.
		// Only overwrites the field when it still looks like a bare
		// "<PREFIX>01" default — a value the admin has deliberately edited
		// (e.g. "ED05" to resume a gap) is left alone.
		var categorySelect = document.getElementById( 'citex_category' );
		var startingIdField = document.getElementById( 'citex_starting_id' );
		if ( ! categorySelect || ! startingIdField ) {
			return;
		}
		categorySelect.addEventListener( 'change', function () {
			var option = categorySelect.options[ categorySelect.selectedIndex ];
			var prefix = option ? option.getAttribute( 'data-id-prefix' ) : '';
			if ( ! prefix ) {
				return;
			}
			if ( /^[A-Z]+01$/.test( startingIdField.value.trim().toUpperCase() ) ) {
				startingIdField.value = prefix + '01';
			}
		} );
	} )();
	</script>

	<hr />
	<div class="citex-section-heading" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
		<h2><?php esc_html_e( 'Pending Questions — AI + Imported', 'citex-tools' ); ?> (<?php echo esc_html( number_format_i18n( count( $pending_questions ) ) ); ?>)</h2>
		<?php if ( ! empty( $pending_questions ) ) : ?>
			<form method="post" style="display:inline-block;"><?php wp_nonce_field( Citex_Generator::NONCE_ACTION, 'citex_generate_nonce' ); ?><button type="submit" name="citex_validate_pending" value="1" class="button button-primary"><?php esc_html_e( 'Validate All Pending', 'citex-tools' ); ?></button></form>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-populate' ) ); ?>"><?php esc_html_e( 'Go to Populate →', 'citex-tools' ); ?></a>
			<form method="post" style="display:inline-block;"><?php wp_nonce_field( Citex_Generator::NONCE_ACTION, 'citex_generate_nonce' ); ?><button type="submit" name="citex_clear_pending" value="1" class="button" onclick="return confirm('Clear all pending questions? This does not delete Reference List questions.');"><?php esc_html_e( 'Clear Pending', 'citex-tools' ); ?></button></form>
		<?php endif; ?>
	</div>

	<?php if ( empty( $pending_questions ) ) : ?>
		<p><?php esc_html_e( 'No pending questions yet.', 'citex-tools' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped citex-table">
			<thead><tr><th style="width:70px;">ID</th><th style="width:90px;">Origin</th><th style="width:60px;">Type</th><th>Scenario</th><th>Details</th><th>Reference</th><th style="width:140px;">Validation</th><th style="width:150px;">Actions</th></tr></thead>
			<tbody>
			<?php foreach ( $pending_questions as $question ) : ?>
				<?php $validation_status = $question['validationStatus'] ?? 'not_validated'; $origin = (string) ( $question['origin'] ?? 'generated' ); $origin_label = 0 === strpos( $origin, 'imported_' ) ? 'Imported' : ( 'generated_ai' === $origin ? 'Gemini AI' : 'Generated' ); $q_type = (string) ( $question['type'] ?? 'DragDrop' ); ?>
				<tr>
					<td><strong><?php echo esc_html( $question['questionId'] ?? '—' ); ?></strong><br /><span class="description"><?php echo esc_html( $question['category'] ?? '' ); ?><?php if ( ! empty( $question['difficulty'] ) ) : ?> · <?php echo esc_html( $question['difficulty'] ); ?><?php endif; ?></span></td>
					<td><strong><?php echo esc_html( $origin_label ); ?></strong><?php if ( ! empty( $question['aiModel'] ) ) : ?><br /><span class="description"><?php echo esc_html( $question['aiModel'] ); ?></span><?php endif; ?></td>
					<td><?php echo esc_html( $q_type ); ?></td>
					<td><?php echo esc_html( $question['scenario'] ?? '' ); ?></td>
					<td>
						<?php if ( 'MCQ' === $q_type ) : ?>
							<?php $correct_index = (int) ( $question['correctOptionIndex'] ?? -1 ); $option_reasons = (array) ( $question['optionErrorReasons'] ?? array() ); ?>
							<ol style="margin:0 0 0 18px;">
								<?php foreach ( (array) ( $question['options'] ?? array() ) as $option_index => $option_text ) : ?>
									<li<?php echo $option_index === $correct_index ? ' style="font-weight:600;"' : ''; ?>><?php echo esc_html( $option_text ); ?><?php echo $option_index === $correct_index ? esc_html__( ' (correct)', 'citex-tools' ) : ''; ?><?php if ( $option_index !== $correct_index && ! empty( $option_reasons[ $option_index ] ) ) : ?><br /><span class="description"><?php esc_html_e( 'Error:', 'citex-tools' ); ?> <?php echo esc_html( $option_reasons[ $option_index ] ); ?></span><?php endif; ?></li>
								<?php endforeach; ?>
							</ol>
							<?php if ( ! empty( $question['hint'] ) ) : ?><strong><?php esc_html_e( 'Hint:', 'citex-tools' ); ?></strong> <?php echo esc_html( $question['hint'] ); ?><?php endif; ?>
						<?php else : ?>
							<strong><?php esc_html_e( 'Question Parts:', 'citex-tools' ); ?></strong> <?php echo esc_html( implode( ' · ', $question['questionParts'] ?? array() ) ); ?><br />
							<strong><?php esc_html_e( 'Fixed Text:', 'citex-tools' ); ?></strong> <code><?php echo esc_html( $question['fixedText'] ?? '' ); ?></code><br />
							<strong><?php esc_html_e( 'Confusing Words:', 'citex-tools' ); ?></strong> <?php echo esc_html( implode( ' · ', $question['confusingWords'] ?? array() ) ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $question['reconstructedReference'] ?? '' ); ?></td>
					<td>
						<?php if ( 'passed' === $validation_status ) : ?><span class="citex-badge citex-badge-passed">✓ Passed</span>
						<?php elseif ( 'failed' === $validation_status ) : ?><span class="citex-badge citex-badge-failed">✕ Failed</span><?php if ( ! empty( $question['validationErrors'] ) ) : ?><ul style="margin:6px 0 0 16px;"><?php foreach ( $question['validationErrors'] as $error ) : ?><li><?php echo esc_html( $error['message'] ?? '' ); ?></li><?php endforeach; ?></ul><?php endif; ?>
						<?php else : ?><span class="citex-badge citex-badge-not_validated">— Not Validated</span><?php endif; ?>
					</td>
					<td>
						<form method="post" style="display:inline-block;margin-right:4px;"><?php wp_nonce_field( Citex_Generator::NONCE_ACTION, 'citex_generate_nonce' ); ?><input type="hidden" name="citex_pending_key" value="<?php echo esc_attr( $question['key'] ?? '' ); ?>" /><button type="submit" name="citex_validate_one_pending" value="1" class="button button-small"><?php echo 'not_validated' === $validation_status ? esc_html__( 'Validate', 'citex-tools' ) : esc_html__( 'Revalidate', 'citex-tools' ); ?></button></form>
						<form method="post" style="display:inline-block;"><?php wp_nonce_field( Citex_Generator::NONCE_ACTION, 'citex_generate_nonce' ); ?><input type="hidden" name="citex_pending_key" value="<?php echo esc_attr( $question['key'] ?? '' ); ?>" /><button type="submit" name="citex_delete_pending" value="1" class="button button-small"><?php esc_html_e( 'Remove', 'citex-tools' ); ?></button></form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
