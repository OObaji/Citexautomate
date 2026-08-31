<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
/**
 * Question Validation view.
 *
 * @var array $summary      Placeholder scan summary counts.
 * @var array $demo_result  DEMO DATA — see Citex_Validator::get_demo_result().
 */
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Question Validation', 'citex-tools' ); ?></h1>

	<form method="post" class="citex-inline-form">
		<?php wp_nonce_field( Citex_Validator::NONCE_ACTION, 'citex_validate_nonce' ); ?>
		<button type="submit" name="citex_validate_action" value="all" class="button button-primary">
			<?php esc_html_e( 'Validate All Questions', 'citex-tools' ); ?>
		</button>
		<button type="submit" name="citex_validate_action" value="selected" class="button">
			<?php esc_html_e( 'Validate Selected Questions', 'citex-tools' ); ?>
		</button>
	</form>

	<div class="citex-stat-cards">
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Questions Scanned', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( $summary['scanned'] ); ?></span>
		</div>
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Passed', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( $summary['passed'] ); ?></span>
		</div>
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Failed', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( $summary['failed'] ); ?></span>
		</div>
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Warnings', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( $summary['warnings'] ); ?></span>
		</div>
	</div>

	<h2><?php esc_html_e( 'Validation Results', 'citex-tools' ); ?></h2>
	<div class="citex-validation-results">
		<p class="description">
			<?php esc_html_e( 'Demo record shown below for layout purposes only — remove once the validation engine is connected.', 'citex-tools' ); ?>
		</p>

		<div class="citex-result-card citex-result-failed">
			<div class="citex-result-header">
				<strong><?php echo esc_html( $demo_result['id'] ); ?></strong>
				<span class="citex-badge citex-badge-error"><?php echo esc_html( $demo_result['status'] ); ?></span>
			</div>
			<ul class="citex-result-errors">
				<?php foreach ( $demo_result['errors'] as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
			</ul>
			<div class="citex-actions">
				<button type="button" class="button button-small" disabled><?php esc_html_e( 'Edit Question', 'citex-tools' ); ?></button>
				<button type="button" class="button button-small" disabled><?php esc_html_e( 'Revalidate', 'citex-tools' ); ?></button>
			</div>
		</div>
	</div>
</div>
