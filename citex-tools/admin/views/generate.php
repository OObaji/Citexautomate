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
		<div class="notice notice-success inline"><p><strong><?php esc_html_e( 'Gemini connected.', 'citex-tools' ); ?></strong> <?php echo esc_html( Citex_AI_V2::get_model() ); ?><?php if ( Citex_AI_V2::web_verification_enabled() ) : ?> — <?php esc_html_e( 'web verification enabled', 'citex-tools' ); ?><?php endif; ?>.</p></div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'Question Type (DragDrop/MCQ), Citation Form (for In-Text Citation) and Author Count are no longer chosen here — every batch is automatically split evenly across DragDrop and MCQ, evenly across every Citation Form, and equally across every Author Count scenario, so a batch never lands lopsided. Question IDs are always freshly auto-numbered.', 'citex-tools' ); ?>
	</p>

	<form method="post" class="citex-form">
		<?php wp_nonce_field( Citex_Generator::NONCE_ACTION, 'citex_generate_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="citex_question_group"><?php esc_html_e( 'Question Focus', 'citex-tools' ); ?></label></th><td><select id="citex_question_group" name="citex_question_group"><?php foreach ( $question_groups as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Reference List builds a full bibliography entry. In-Text Citation builds the short in-sentence/parenthetical citation instead — available for every category under both styles.', 'citex-tools' ); ?></p></td></tr>
			<tr><th scope="row"><label for="citex_referencing_style"><?php esc_html_e( 'Referencing Style', 'citex-tools' ); ?></label></th><td><select id="citex_referencing_style" name="citex_referencing_style"><?php foreach ( $referencing_styles as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th scope="row"><label for="citex_category"><?php esc_html_e( 'Category', 'citex-tools' ); ?></label></th><td><select id="citex_category" name="citex_category"><?php foreach ( $categories as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><p id="citex_chicago_scope_note" class="description citex-chicago-scope-note" style="display:none;"><?php esc_html_e( 'Chicago (Author-Date) currently supports Reference List only — In-Text Citation is coming in a later update.', 'citex-tools' ); ?></p><p id="citex_mhra_scope_note" class="description citex-mhra-scope-note" style="display:none;"><?php esc_html_e( 'MHRA currently supports Reference List only — In-Text Citation is coming in a later update.', 'citex-tools' ); ?></p></td></tr>
			<tr><th scope="row"><label for="citex_difficulty"><?php esc_html_e( 'Difficulty', 'citex-tools' ); ?></label></th><td><select id="citex_difficulty" name="citex_difficulty"><?php foreach ( $difficulties as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( 'hard', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th scope="row"><label for="citex_quantity"><?php esc_html_e( 'Quantity', 'citex-tools' ); ?></label></th><td><input type="number" id="citex_quantity" name="citex_quantity" value="20" min="1" max="100" class="small-text" /><p class="description"><?php esc_html_e( 'Generate up to 100 questions in one batch, split evenly across DragDrop/MCQ (and Citation Form, for In-Text Citation). "Generate & Publish" also populates every question that passes in the same request, so it is capped lower, at 20, to avoid timing out — "Generate Real Questions with Gemini" alone still allows the full 100.', 'citex-tools' ); ?></p></td></tr>
		</table>
		<p class="submit">
			<button type="submit" name="citex_generate_submit" value="1" class="button button-primary" <?php disabled( ! $ai_configured ); ?>><?php esc_html_e( 'Generate Real Questions with Gemini', 'citex-tools' ); ?></button>
			<button type="submit" name="citex_generate_and_populate_submit" value="1" class="button button-primary" <?php disabled( ! $ai_configured ); ?>><?php esc_html_e( 'Generate & Publish', 'citex-tools' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-ai' ) ); ?>"><?php esc_html_e( 'AI Settings', 'citex-tools' ); ?></a>
		</p>
		<p class="description"><?php esc_html_e( '"Generate & Publish" also validates this batch and immediately populates whichever questions pass straight into the real Reference List/Citations as Published — skipping the separate Validate and Populate steps for this batch. Anything that fails validation stays in Pending below for review.', 'citex-tools' ); ?></p>
	</form>
	<script>
	( function () {
		// "Generate & Publish" is capped lower server-side than plain
		// "Generate" (20 vs 100 — see handle_generation()'s own docblock:
		// it does full generation AND full synchronous population in one
		// request, genuinely double the per-question work, and a large
		// Quantity reliably timed out under that combined load). The
		// Quantity field is shared by both buttons and its own max="100"
		// only reflects plain Generate's cap, so a value above 20 would
		// otherwise be silently clamped down to 20 server-side with no
		// explanation — this asks first and lets the admin choose to
		// proceed at 20 or go back and lower Quantity themselves.
		var publishButton = document.querySelector( 'button[name="citex_generate_and_populate_submit"]' );
		var quantityInput  = document.getElementById( 'citex_quantity' );
		var PUBLISH_QUANTITY_CAP = 20;
		if ( publishButton && quantityInput ) {
			publishButton.addEventListener( 'click', function ( event ) {
				var requested = parseInt( quantityInput.value, 10 ) || 0;
				if ( requested <= PUBLISH_QUANTITY_CAP ) {
					return;
				}
				var proceed = confirm(
					'Generate & Publish generates AND publishes every question in the same request, so it is capped at ' + PUBLISH_QUANTITY_CAP + ' at a time to avoid timing out. Continue with ' + PUBLISH_QUANTITY_CAP + ' instead of ' + requested + '?'
				);
				if ( ! proceed ) {
					event.preventDefault();
					return;
				}
				quantityInput.value = PUBLISH_QUANTITY_CAP;
			} );
		}

		var styleSelect = document.getElementById( 'citex_referencing_style' );
		var groupSelect  = document.getElementById( 'citex_question_group' );

		// Chicago (Author-Date) Reference List now covers all 4 categories
		// (the same Phase 2 build-out APA/MLA already went through) — only
		// Question Focus is still locked to "Reference List" whenever
		// Chicago is selected (In-Text Citation is still a later phase),
		// disabling every other option in that one dropdown so an
		// unsupported combination can never be submitted, and shows a note
		// explaining why. handle_generation() enforces the same restriction
		// server-side regardless (see $chicago_scope_ok), so this is a UX
		// convenience, not the only safeguard.
		function syncChicagoScope() {
			if ( ! styleSelect ) {
				return;
			}
			var isChicago = 'chicago' === styleSelect.value;
			if ( groupSelect ) {
				Array.prototype.forEach.call( groupSelect.options, function ( opt ) {
					opt.disabled = isChicago && 'referencelist' !== opt.value;
				} );
				if ( isChicago && 'referencelist' !== groupSelect.value ) {
					groupSelect.value = 'referencelist';
				}
			}
			var note = document.getElementById( 'citex_chicago_scope_note' );
			if ( note ) {
				note.style.display = isChicago ? '' : 'none';
			}
		}

		// MHRA (11th edition) Reference List now covers all 4 categories
		// (Book, Edited Book, Journal Article, Website) — the same Phase 2
		// build-out APA/MLA/Chicago already went through. In-Text Citation
		// is still a later phase, so only the Question Focus lock remains —
		// mirrors syncChicagoScope() exactly, its own independent scope
		// lock. Only one Referencing Style can ever be selected at once, so
		// this and syncChicagoScope() each unconditionally set every
		// option's `disabled` from scratch off their own single condition —
		// calling both in sequence (either order) always leaves the options
		// reflecting whichever style is actually selected, with no need to
		// OR the two locks together.
		function syncMhraScope() {
			if ( ! styleSelect ) {
				return;
			}
			var isMhra = 'mhra' === styleSelect.value;
			if ( groupSelect ) {
				Array.prototype.forEach.call( groupSelect.options, function ( opt ) {
					opt.disabled = isMhra && 'referencelist' !== opt.value;
				} );
				if ( isMhra && 'referencelist' !== groupSelect.value ) {
					groupSelect.value = 'referencelist';
				}
			}
			var note = document.getElementById( 'citex_mhra_scope_note' );
			if ( note ) {
				note.style.display = isMhra ? '' : 'none';
			}
		}

		if ( styleSelect ) {
			styleSelect.addEventListener( 'change', function () {
				syncChicagoScope();
				syncMhraScope();
			} );
		}

		syncChicagoScope();
		syncMhraScope();
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
					<td><strong><?php echo esc_html( $question['questionId'] ?? '—' ); ?></strong><br /><span class="description"><?php echo esc_html( $question['category'] ?? '' ); ?><?php if ( ! empty( $question['difficulty'] ) ) : ?> · <?php echo esc_html( $question['difficulty'] ); ?><?php endif; ?></span><?php $blueprint = (array) ( $question['blueprint'] ?? array() ); if ( ! empty( $blueprint['scenario'] ) ) : ?><br /><span class="description" title="<?php esc_attr_e( 'The specific Harvard rule this question tests — part of the dynamic question-generation framework\'s coverage tracking.', 'citex-tools' ); ?>"><?php esc_html_e( 'Scenario:', 'citex-tools' ); ?> <?php echo esc_html( $blueprint['scenario'] ); ?><?php if ( ! empty( $blueprint['ruleTested'] ) ) : ?> (<?php echo esc_html( $blueprint['ruleTested'] ); ?>)<?php endif; ?></span><?php endif; ?></td>
					<td><strong><?php echo esc_html( $origin_label ); ?></strong><?php if ( ! empty( $question['aiModel'] ) ) : ?><br /><span class="description"><?php echo esc_html( $question['aiModel'] ); ?></span><?php endif; ?></td>
					<td><?php echo esc_html( $q_type ); ?></td>
					<td><?php $mcq_pattern = (string) ( $question['mcqPattern'] ?? '' ); $is_identify_error = 'identify_error' === $mcq_pattern; $is_choose_treatment = 'choose_treatment' === $mcq_pattern; $is_book_mcq_variant = 'book_mcq_variant' === $mcq_pattern; ?><?php if ( $is_identify_error ) : ?><span class="description"><?php esc_html_e( 'Identify the error:', 'citex-tools' ); ?></span><br /><?php elseif ( $is_choose_treatment ) : ?><span class="description"><?php esc_html_e( 'Choose the correct rule:', 'citex-tools' ); ?></span><br /><?php elseif ( $is_book_mcq_variant ) : ?><span class="description" title="<?php echo esc_attr( (string) ( $question['bookMcqVariant'] ?? '' ) ); ?>"><?php esc_html_e( 'Book reference format:', 'citex-tools' ); ?></span><br /><?php endif; ?><?php echo ( $is_identify_error || $is_book_mcq_variant ) ? nl2br( esc_html( $question['scenario'] ?? '' ) ) : esc_html( $question['scenario'] ?? '' ); ?></td>
					<td>
						<?php if ( 'MCQ' === $q_type ) : ?>
							<?php $option_reasons = (array) ( $question['optionErrorReasons'] ?? array() ); ?>
							<strong><?php echo $is_identify_error ? esc_html__( 'True description:', 'citex-tools' ) : ( $is_choose_treatment ? esc_html__( 'True statement:', 'citex-tools' ) : esc_html__( 'Answer:', 'citex-tools' ) ); ?></strong> <?php echo esc_html( $question['reconstructedReference'] ?? '' ); ?>
							<ol style="margin:6px 0 0 18px;">
								<?php foreach ( (array) ( $question['options'] ?? array() ) as $option_index => $option_text ) : ?>
									<li><?php echo '' !== trim( (string) $option_text ) ? esc_html( $option_text ) : '<em>' . esc_html__( '(blank)', 'citex-tools' ) . '</em>'; ?><?php if ( ! empty( $option_reasons[ $option_index ] ) ) : ?><br /><span class="description"><?php esc_html_e( 'Error:', 'citex-tools' ); ?> <?php echo esc_html( $option_reasons[ $option_index ] ); ?></span><?php endif; ?></li>
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
