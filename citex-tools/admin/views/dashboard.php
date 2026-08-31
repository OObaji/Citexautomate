<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
/**
 * Dashboard view.
 *
 * @var array $stats          Placeholder statistics, keyed by metric.
 * @var array $overview_rows  Placeholder question-bank overview rows.
 */
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Citex Dashboard', 'citex-tools' ); ?></h1>

	<div class="citex-stat-cards">
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Total Questions', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( $stats['total_questions'] ); ?></span>
		</div>
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Valid Questions', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( $stats['valid_questions'] ); ?></span>
		</div>
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Questions With Errors', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( $stats['error_questions'] ); ?></span>
		</div>
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Generated / Pending Questions', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( $stats['pending_questions'] ); ?></span>
		</div>
	</div>

	<div class="citex-quick-actions">
		<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-generate' ) ); ?>">
			<?php esc_html_e( 'Generate Questions', 'citex-tools' ); ?>
		</a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-questions' ) ); ?>">
			<?php esc_html_e( 'View Question Bank', 'citex-tools' ); ?>
		</a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-validation' ) ); ?>">
			<?php esc_html_e( 'Validate Questions', 'citex-tools' ); ?>
		</a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-populate' ) ); ?>">
			<?php esc_html_e( 'Populate Questions', 'citex-tools' ); ?>
		</a>
	</div>

	<h2><?php esc_html_e( 'Question Bank Overview', 'citex-tools' ); ?></h2>
	<table class="widefat striped citex-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Referencing Style', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Category', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Questions', 'citex-tools' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $overview_rows as $row ) : ?>
			<tr>
				<td><?php echo esc_html( $row['style'] ); ?></td>
				<td><?php echo esc_html( $row['category'] ); ?></td>
				<td><?php echo esc_html( $row['questions'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p class="description">
		<?php esc_html_e( 'Placeholder data — will be replaced once the question bank is connected.', 'citex-tools' ); ?>
	</p>
</div>
