<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Import Questions', 'citex-tools' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Bring questions generated elsewhere into Citex. Imported records enter the same Pending → Validate → Populate workflow as Citex-generated questions; importing never writes directly to the Reference List.', 'citex-tools' ); ?>
	</p>

	<div class="citex-stat-cards citex-stat-cards-compact">
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Imported / pending', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( number_format_i18n( count( $imported_pending ) ) ); ?></span>
		</div>
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Passed validation', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( number_format_i18n( count( array_filter( $imported_pending, function ( $q ) { return 'passed' === ( $q['validationStatus'] ?? '' ); } ) ) ) ); ?></span>
		</div>
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Failed validation', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( number_format_i18n( count( array_filter( $imported_pending, function ( $q ) { return 'failed' === ( $q['validationStatus'] ?? '' ); } ) ) ) ); ?></span>
		</div>
	</div>

	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:20px;margin-top:20px;">
		<div class="citex-scan-panel">
			<h2><?php esc_html_e( 'Upload CSV / TSV / JSON', 'citex-tools' ); ?></h2>
			<p><?php esc_html_e( 'Best for batches exported from another AI tool, spreadsheet, script or question generator.', 'citex-tools' ); ?></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( Citex_Importer::NONCE_ACTION, 'citex_import_nonce' ); ?>
				<p><input type="file" name="citex_import_file" accept=".csv,.tsv,.txt,.json,text/csv,application/json" required /></p>
				<p><label><input type="checkbox" name="citex_validate_after_import" value="1" checked /> <?php esc_html_e( 'Validate supported questions immediately after import', 'citex-tools' ); ?></label></p>
				<p class="submit"><button type="submit" name="citex_import_file_submit" value="1" class="button button-primary"><?php esc_html_e( 'Import File', 'citex-tools' ); ?></button></p>
			</form>
			<p><a class="button" href="<?php echo esc_url( $template_url ); ?>" download><?php esc_html_e( 'Download CSV Template', 'citex-tools' ); ?></a></p>
		</div>

		<div class="citex-scan-panel">
			<h2><?php esc_html_e( 'Paste JSON', 'citex-tools' ); ?></h2>
			<p><?php esc_html_e( 'Useful when another AI or script already returns structured JSON. Paste either an array of questions or {"questions":[...]} .', 'citex-tools' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( Citex_Importer::NONCE_ACTION, 'citex_import_nonce' ); ?>
				<textarea name="citex_import_json" rows="12" style="width:100%;font-family:monospace;" placeholder='[{"questionId":"BK01","authorSurname":"Lopez","authorInitials":"M.","year":"2019","bookTitle":"Global Health","place":"Oxford","publisher":"Oxford University Press","confusingWords":["2017","2020","London"]}]'></textarea>
				<p><label><input type="checkbox" name="citex_validate_after_import" value="1" checked /> <?php esc_html_e( 'Validate supported questions immediately after import', 'citex-tools' ); ?></label></p>
				<p class="submit"><button type="submit" name="citex_import_json_submit" value="1" class="button button-primary"><?php esc_html_e( 'Import JSON', 'citex-tools' ); ?></button></p>
			</form>
		</div>
	</div>

	<div class="citex-scan-panel" style="margin-top:20px;">
		<h2><?php esc_html_e( 'Accepted formats', 'citex-tools' ); ?></h2>
		<p><strong><?php esc_html_e( 'Simple Book / DragDrop format (recommended)', 'citex-tools' ); ?></strong></p>
		<p><code>questionId, authorSurname, authorInitials, year, bookTitle, place, publisher, confusingWords, difficulty</code></p>
		<p><?php esc_html_e( 'Citex automatically builds the Fixed Text, Question Parts, reference title and reconstructed reference from those columns.', 'citex-tools' ); ?></p>

		<p><strong><?php esc_html_e( 'Full structured format', 'citex-tools' ); ?></strong></p>
		<p><code>questionId, source, group, category, type, institution, difficulty, scenario, fixedText, questionParts, confusingWords, reconstructedReference</code></p>
		<p><?php esc_html_e( 'For CSV/TSV list fields, use double semicolons between values (e.g. Lopez;;M.;;2019;;Global Health) or put a JSON array in the cell. JSON imports can use real arrays.', 'citex-tools' ); ?></p>
		<p><?php esc_html_e( 'Common aliases such as id, question_id, surname, initials, publication_year, city, distractors and answer are also recognised.', 'citex-tools' ); ?></p>
	</div>

	<div class="citex-section-heading" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:24px;">
		<h2><?php esc_html_e( 'Imported Questions Still Pending', 'citex-tools' ); ?> (<?php echo esc_html( number_format_i18n( count( $imported_pending ) ) ); ?>)</h2>
		<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-generate' ) ); ?>"><?php esc_html_e( 'Review / Validate Pending →', 'citex-tools' ); ?></a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-populate' ) ); ?>"><?php esc_html_e( 'Go to Populate →', 'citex-tools' ); ?></a>
	</div>

	<?php if ( empty( $imported_pending ) ) : ?>
		<p><?php esc_html_e( 'No imported questions are currently waiting in Citex.', 'citex-tools' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped citex-table">
			<thead><tr>
				<th><?php esc_html_e( 'ID', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Imported From', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Category / Type', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Reference', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Validation', 'citex-tools' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $imported_pending as $question ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $question['questionId'] ?? '—' ); ?></strong></td>
					<td><?php echo esc_html( $question['importSource'] ?? ( $question['origin'] ?? 'Imported' ) ); ?></td>
					<td><?php echo esc_html( ( $question['category'] ?? '—' ) . ' / ' . ( $question['type'] ?? '—' ) ); ?></td>
					<td><?php echo esc_html( $question['reconstructedReference'] ?? '' ); ?></td>
					<td>
						<?php $status = $question['validationStatus'] ?? 'not_validated'; ?>
						<?php if ( 'passed' === $status ) : ?><span class="citex-badge citex-badge-passed">✓ Passed</span>
						<?php elseif ( 'failed' === $status ) : ?><span class="citex-badge citex-badge-failed">✕ Failed</span>
						<?php else : ?><span class="citex-badge citex-badge-not_validated">— Not Validated</span><?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
