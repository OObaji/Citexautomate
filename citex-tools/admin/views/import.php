<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Import Questions', 'citex-tools' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Import questions generated in Gemini or any other source. Imported questions enter Citex as pending records — they are not written directly to the real Reference List. The safe workflow is Import → Validate → Populate.', 'citex-tools' ); ?>
	</p>

	<div class="citex-stat-cards citex-stat-cards-compact">
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Imported / pending', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( number_format_i18n( count( $imported_pending ) ) ); ?></span>
		</div>
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Passed validation', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( number_format_i18n( count( array_filter( $imported_pending, function ( $q ) { return 'passed' === ( $q['validationStatus'] ?? '' ); } ) ) ); ?></span>
		</div>
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Failed validation', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( number_format_i18n( count( array_filter( $imported_pending, function ( $q ) { return 'failed' === ( $q['validationStatus'] ?? '' ); } ) ) ); ?></span>
		</div>
	</div>

	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:20px;margin-top:20px;">
		<div class="citex-scan-panel">
			<h2><?php esc_html_e( 'Upload CSV / TSV / JSON', 'citex-tools' ); ?></h2>
			<p><?php esc_html_e( 'Use this for a batch exported from Gemini, a spreadsheet, script or another question generator.', 'citex-tools' ); ?></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( Citex_Importer::NONCE_ACTION, 'citex_import_nonce' ); ?>
				<p><input type="file" name="citex_import_file" accept=".csv,.tsv,.txt,.json,text/csv,application/json" required /></p>
				<p><label><input type="checkbox" name="citex_validate_after_import" value="1" checked /> <?php esc_html_e( 'Validate imported questions immediately', 'citex-tools' ); ?></label></p>
				<p class="submit"><button type="submit" name="citex_import_file_submit" value="1" class="button button-primary"><?php esc_html_e( 'Import Questions', 'citex-tools' ); ?></button></p>
			</form>
			<p><a class="button" href="<?php echo esc_url( $template_url ); ?>" download><?php esc_html_e( 'Download CSV Template', 'citex-tools' ); ?></a></p>
		</div>

		<div class="citex-scan-panel">
			<h2><?php esc_html_e( 'Paste JSON', 'citex-tools' ); ?></h2>
			<p><?php esc_html_e( 'Paste one question, an array of questions, or an object containing a questions array. This is the easiest format for an AI-generated batch.', 'citex-tools' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( Citex_Importer::NONCE_ACTION, 'citex_import_nonce' ); ?>
				<textarea name="citex_import_json" rows="12" style="width:100%;font-family:monospace;" placeholder='[{"questionId":"BK01","scenario":"You are creating a reference for a book titled Global Health, written by Maria Lopez. It was published in 2019 by Oxford University Press in Oxford.","authorSurname":"Lopez","authorInitials":"M.","year":"2019","bookTitle":"Global Health","place":"Oxford","publisher":"Oxford University Press","confusingWords":["2017","2020","London"]}]'></textarea>
				<p><label><input type="checkbox" name="citex_validate_after_import" value="1" checked /> <?php esc_html_e( 'Validate imported questions immediately', 'citex-tools' ); ?></label></p>
				<p class="submit"><button type="submit" name="citex_import_json_submit" value="1" class="button button-primary"><?php esc_html_e( 'Import JSON', 'citex-tools' ); ?></button></p>
			</form>
		</div>
	</div>

	<div class="citex-scan-panel" style="margin-top:20px;">
		<h2><?php esc_html_e( 'Gemini import format', 'citex-tools' ); ?></h2>
		<p><?php esc_html_e( 'Gemini can export the simple flat format below, which Citex converts into the correct pending question structure automatically.', 'citex-tools' ); ?></p>
		<pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;overflow:auto;">{
  "questionId": "BK01",
  "scenario": "You are creating a reference for a book titled Global Health, written by Maria Lopez. It was published in 2019 by Oxford University Press in Oxford.",
  "authorSurname": "Lopez",
  "authorInitials": "M.",
  "year": "2019",
  "bookTitle": "Global Health",
  "place": "Oxford",
  "publisher": "Oxford University Press",
  "confusingWords": ["2017", "2020", "London"],
  "difficulty": "Medium"
}</pre>
		<p><strong><?php esc_html_e( 'Also accepted:', 'citex-tools' ); ?></strong> <?php esc_html_e( 'full structured records containing fixedText, questionParts and reconstructedReference, plus common aliases such as id, surname, initials, publication_year, city, distractors and answer.', 'citex-tools' ); ?></p>
		<p><?php esc_html_e( 'For CSV/TSV list fields, separate multiple values with double semicolons (;;), or provide a JSON array in the cell. JSON imports can use real arrays.', 'citex-tools' ); ?></p>
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
