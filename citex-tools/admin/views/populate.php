<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
/**
 * Populate Questions view.
 *
 * @var array $sources
 * @var array $status  Placeholder population-readiness counts.
 */
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Populate Questions', 'citex-tools' ); ?></h1>

	<form method="post" class="citex-form">
		<?php wp_nonce_field( Citex_Populator::NONCE_ACTION, 'citex_populate_nonce' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="citex_populate_source"><?php esc_html_e( 'Source', 'citex-tools' ); ?></label></th>
				<td>
					<select id="citex_populate_source" name="citex_populate_source">
						<?php foreach ( $sources as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>

		<div class="citex-stat-cards citex-stat-cards-compact">
			<div class="citex-card">
				<span class="citex-card-label"><?php esc_html_e( 'Questions ready', 'citex-tools' ); ?></span>
				<span class="citex-card-value"><?php echo esc_html( $status['ready'] ); ?></span>
			</div>
			<div class="citex-card">
				<span class="citex-card-label"><?php esc_html_e( 'Passed validation', 'citex-tools' ); ?></span>
				<span class="citex-card-value"><?php echo esc_html( $status['passed'] ); ?></span>
			</div>
			<div class="citex-card">
				<span class="citex-card-label"><?php esc_html_e( 'Failed validation', 'citex-tools' ); ?></span>
				<span class="citex-card-value"><?php echo esc_html( $status['failed'] ); ?></span>
			</div>
		</div>

		<p class="submit">
			<button type="submit" name="citex_populate_submit" value="1" class="button button-primary">
				<?php esc_html_e( 'Populate WordPress', 'citex-tools' ); ?>
			</button>
		</p>
	</form>
</div>
