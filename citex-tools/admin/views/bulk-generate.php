<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var array $referencing_styles */
/** @var array $difficulties */
/** @var array $pending_questions */
/** @var bool $ai_configured */
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Bulk Generate', 'citex-tools' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Generate a large number of questions for one Referencing Style in a single request. The total is spread automatically and evenly across Reference List and In-Text Citation, across Book/Edited Book/Journal Article/Website, and within each of those across DragDrop/MCQ and, for In-Text Citation, all 3 Citation Forms — e.g. "Harvard, 500" generates roughly equal shares of every one of those combinations rather than 500 of any single kind.', 'citex-tools' ); ?>
	</p>
	<p class="description">
		<?php esc_html_e( 'Chicago (Author-Date) and MHRA currently support Reference List only, so their total is split across the 4 categories only, not across Question Focus too.', 'citex-tools' ); ?>
	</p>

	<?php if ( ! $ai_configured ) : ?>
		<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Gemini is not configured.', 'citex-tools' ); ?></strong> <?php esc_html_e( 'Add your API key in AI Settings before generating questions.', 'citex-tools' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=citex-ai' ) ); ?>"><?php esc_html_e( 'Open AI Settings →', 'citex-tools' ); ?></a></p></div>
	<?php endif; ?>

	<form method="post" class="citex-form">
		<?php wp_nonce_field( Citex_Generator::NONCE_ACTION, 'citex_generate_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="citex_bulk_style"><?php esc_html_e( 'Referencing Style', 'citex-tools' ); ?></label></th><td><select id="citex_bulk_style" name="citex_bulk_style"><?php foreach ( $referencing_styles as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th scope="row"><label for="citex_bulk_difficulty"><?php esc_html_e( 'Difficulty', 'citex-tools' ); ?></label></th><td><select id="citex_bulk_difficulty" name="citex_bulk_difficulty"><?php foreach ( $difficulties as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( 'hard', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th scope="row"><label for="citex_bulk_quantity"><?php esc_html_e( 'Total Quantity', 'citex-tools' ); ?></label></th><td><input type="number" id="citex_bulk_quantity" name="citex_bulk_quantity" value="100" min="1" max="2000" class="small-text" /><p class="description"><?php esc_html_e( 'Split evenly across every Question Focus x Category combination this style supports (and, within each, evenly across DragDrop/MCQ and Citation Form). Each combination is saved to Pending as soon as it completes — not only at the very end — so even if the request times out partway through (a real risk above a few hundred, since it is a large number of Gemini requests in one browser request), whatever already finished is safely kept; you may see a plain server error page when that happens rather than the usual notice, but nothing is lost. Check Pending below, then re-run with a smaller total (100-200 is a safer starting point) to fill in the rest.', 'citex-tools' ); ?></p></td></tr>
		</table>
		<p class="submit">
			<button type="submit" name="citex_bulk_generate_submit" value="1" class="button button-primary" <?php disabled( ! $ai_configured ); ?>><?php esc_html_e( 'Bulk Generate', 'citex-tools' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-generate' ) ); ?>"><?php esc_html_e( '← Single Batch Generate', 'citex-tools' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-populate' ) ); ?>"><?php esc_html_e( 'Go to Populate →', 'citex-tools' ); ?></a>
		</p>
	</form>

	<hr />
	<p><?php esc_html_e( 'Pending questions', 'citex-tools' ); ?>: <strong><?php echo esc_html( number_format_i18n( count( $pending_questions ) ) ); ?></strong> — <?php esc_html_e( 'validate and populate them from the Generate Questions or Populate screens. Populate lets you publish a large pending queue in chunks (e.g. 100 at a time) rather than all at once.', 'citex-tools' ); ?></p>
</div>
