<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
/**
 * Dashboard view.
 *
 * @var array       $stats              Total/Harvard from the last scan; Valid/Error/Pending are still placeholders.
 * @var string|null  $last_scanned       Formatted last-scan date/time, or null if never scanned.
 * @var array|null   $breakdowns         Source/Group/Category/Type breakdowns from the last scan, or null.
 * @var string       $question_list_url  Configured WordPress question-list admin URL.
 */

$breakdown_sections = array(
	'sources'    => __( 'Source', 'citex-tools' ),
	'groups'     => __( 'Group', 'citex-tools' ),
	'categories' => __( 'Category', 'citex-tools' ),
	'types'      => __( 'Question Type', 'citex-tools' ),
);
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Citex Dashboard', 'citex-tools' ); ?></h1>

	<div class="citex-stat-cards">
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Total Questions', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( $stats['total_questions'] ); ?></span>
		</div>
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Harvard Questions', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( $stats['harvard_questions'] ); ?></span>
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

	<div class="citex-scan-panel">
		<p class="citex-scan-meta">
			<?php if ( $last_scanned ) : ?>
				<?php
				printf(
					/* translators: %s: date/time of the last scan. */
					esc_html__( 'Last scanned: %s', 'citex-tools' ),
					esc_html( $last_scanned )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'Last scanned: never', 'citex-tools' ); ?>
			<?php endif; ?>
		</p>

		<button
			type="button"
			class="button button-primary citex-scan-btn"
			<?php disabled( empty( $question_list_url ) ); ?>
		>
			<?php echo $last_scanned ? esc_html__( 'Refresh / Scan Again', 'citex-tools' ) : esc_html__( 'Scan Question Bank', 'citex-tools' ); ?>
		</button>
		<p class="citex-scan-status" aria-live="polite"></p>

		<details class="citex-scan-settings" <?php echo empty( $question_list_url ) ? 'open' : ''; ?>>
			<summary><?php esc_html_e( 'Question List URL settings', 'citex-tools' ); ?></summary>
			<form id="citex-scanner-settings-form" class="citex-inline-form">
				<label for="citex_question_list_url" class="screen-reader-text"><?php esc_html_e( 'Question List URL', 'citex-tools' ); ?></label>
				<input
					type="url"
					id="citex_question_list_url"
					class="citex-input regular-text"
					placeholder="https://example.com/wp-admin/edit.php?post_type=..."
					value="<?php echo esc_attr( $question_list_url ); ?>"
				/>
				<button type="submit" class="button"><?php esc_html_e( 'Save', 'citex-tools' ); ?></button>
				<span id="citex-settings-status" class="citex-settings-status" aria-live="polite"></span>
			</form>
			<p class="description">
				<?php esc_html_e( 'Enter the WordPress admin URL of the existing question-list screen (e.g. edit.php?post_type=question). Citex scans this URL, authenticated as you, to build its index — it never modifies the underlying records.', 'citex-tools' ); ?>
			</p>
		</details>
	</div>

	<div class="citex-quick-actions">
		<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-generate' ) ); ?>">
			<?php esc_html_e( 'Generate Questions', 'citex-tools' ); ?>
		</a>
		<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-import' ) ); ?>">
			<?php esc_html_e( 'Import Questions', 'citex-tools' ); ?>
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
	<?php if ( ! $breakdowns ) : ?>
		<p class="description"><?php esc_html_e( 'No scan yet — run a scan to see the question bank breakdown.', 'citex-tools' ); ?></p>
	<?php else : ?>
		<div class="citex-breakdown-grid">
			<?php foreach ( $breakdown_sections as $key => $label ) : ?>
				<div class="citex-breakdown-card">
					<h3><?php echo esc_html( $label ); ?></h3>
					<table class="widefat striped citex-table citex-breakdown-table">
						<thead>
							<tr>
								<th><?php echo esc_html( $label ); ?></th>
								<th><?php esc_html_e( 'Questions', 'citex-tools' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( empty( $breakdowns[ $key ] ) ) : ?>
							<tr>
								<td colspan="2"><?php esc_html_e( 'No data.', 'citex-tools' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $breakdowns[ $key ] as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['name'] ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<p class="citex-version-badge">
		<?php
		printf(
			/* translators: %s: installed Citex Tools plugin version. */
			esc_html__( 'Citex Tools v%s', 'citex-tools' ),
			esc_html( CITEX_TOOLS_VERSION )
		);
		?>
	</p>
</div>
