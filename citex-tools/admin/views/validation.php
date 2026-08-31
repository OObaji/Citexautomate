<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
/**
 * Question Validation view.
 *
 * @var array $summary Scanned/Passed/Failed/Warnings/Unsupported counts.
 * @var array $rows     One entry per indexed question: {question, key, validatorId, diagnosis, result, status}.
 */

$status_labels = array(
	'passed'        => __( '✓ Passed', 'citex-tools' ),
	'failed'        => __( '✕ Failed', 'citex-tools' ),
	'warning'       => __( '⚠ Warning', 'citex-tools' ),
	'not_validated' => __( '— Not Validated', 'citex-tools' ),
	'unsupported'   => __( '○ Unsupported', 'citex-tools' ),
);
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Question Validation', 'citex-tools' ); ?></h1>

	<div class="citex-stat-cards">
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Questions Scanned', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( number_format_i18n( $summary['scanned'] ) ); ?></span>
		</div>
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Passed', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( number_format_i18n( $summary['passed'] ) ); ?></span>
		</div>
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Failed', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( number_format_i18n( $summary['failed'] ) ); ?></span>
		</div>
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Warnings', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( number_format_i18n( $summary['warnings'] ) ); ?></span>
		</div>
		<div class="citex-card">
			<span class="citex-card-label"><?php esc_html_e( 'Unsupported', 'citex-tools' ); ?></span>
			<span class="citex-card-value"><?php echo esc_html( number_format_i18n( $summary['unsupported'] ) ); ?></span>
		</div>
	</div>

	<?php if ( empty( $rows ) ) : ?>
		<p class="description">
			<?php esc_html_e( 'No questions scanned yet. Run a scan from the Dashboard before validating.', 'citex-tools' ); ?>
		</p>
	<?php else : ?>
		<div class="citex-inline-form">
			<button type="button" id="citex-validate-all" class="button button-primary">
				<?php esc_html_e( 'Validate All Supported Questions', 'citex-tools' ); ?>
			</button>
			<button type="button" id="citex-validate-selected" class="button">
				<?php esc_html_e( 'Validate Selected Questions', 'citex-tools' ); ?>
			</button>
		</div>
		<p class="citex-validate-status citex-scan-status" aria-live="polite"></p>

		<table class="wp-list-table widefat fixed striped citex-table">
			<thead>
				<tr>
					<td class="manage-column column-cb check-column">
						<input type="checkbox" id="citex-select-all" />
					</td>
					<th><?php esc_html_e( 'Question ID', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'Category', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'Question Type', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'Status', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'Errors', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'Last Validated', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'citex-tools' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$question    = $row['question'];
				$result      = $row['result'];
				$status      = $row['status'];
				$diagnosis   = $row['diagnosis'];
				$error_count = $result && ! empty( $result['errors'] ) ? count( $result['errors'] ) : 0;
				// The routing-diagnostics panel is always available now — it's
				// live data (recomputed on every page load), not dependent on
				// whether a validation has ever been run for this question.
				?>
				<tr>
					<th scope="row" class="check-column">
						<input type="checkbox" class="citex-row-select" data-key="<?php echo esc_attr( $row['key'] ); ?>" />
					</th>
					<td><?php echo esc_html( $question['questionId'] ? $question['questionId'] : '—' ); ?></td>
					<td><?php echo esc_html( $question['category'] ? $question['category'] : '—' ); ?></td>
					<td><?php echo esc_html( $question['type'] ? $question['type'] : '—' ); ?></td>
					<td>
						<span class="citex-badge citex-badge-<?php echo esc_attr( $status ); ?>">
							<?php echo esc_html( $status_labels[ $status ] ?? $status ); ?>
						</span>
					</td>
					<td>
						<button type="button" class="button button-small citex-toggle-details" data-key="<?php echo esc_attr( $row['key'] ); ?>">
							<?php
							if ( $error_count > 0 ) {
								printf(
									/* translators: %d: number of errors. */
									esc_html( _n( '%d error', '%d errors', $error_count, 'citex-tools' ) ),
									(int) $error_count
								);
							} else {
								esc_html_e( 'Details', 'citex-tools' );
							}
							?>
						</button>
					</td>
					<td>
						<?php echo $result ? esc_html( Citex_Scanner::format_scanned_at( $result['validatedAt'] ) ) : '—'; ?>
					</td>
					<td class="citex-actions">
						<?php if ( ! empty( $question['editUrl'] ) ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $question['editUrl'] ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Open in WordPress', 'citex-tools' ); ?>
							</a>
						<?php else : ?>
							<button type="button" class="button button-small" disabled><?php esc_html_e( 'Open in WordPress', 'citex-tools' ); ?></button>
						<?php endif; ?>

						<?php if ( $row['validatorId'] ) : ?>
							<button type="button" class="button button-small citex-validate-btn" data-key="<?php echo esc_attr( $row['key'] ); ?>">
								<?php echo $result ? esc_html__( 'Revalidate', 'citex-tools' ) : esc_html__( 'Validate', 'citex-tools' ); ?>
							</button>
						<?php else : ?>
							<button type="button" class="button button-small" disabled><?php esc_html_e( 'Validate', 'citex-tools' ); ?></button>
						<?php endif; ?>
					</td>
				</tr>
				<tr class="citex-detail-row" data-key="<?php echo esc_attr( $row['key'] ); ?>" hidden>
					<td colspan="8">
						<?php if ( ! empty( $result['errors'] ) ) : ?>
							<strong><?php esc_html_e( 'Errors:', 'citex-tools' ); ?></strong>
							<ol class="citex-result-errors">
								<?php foreach ( $result['errors'] as $error ) : ?>
									<li>
										<?php echo esc_html( $error['message'] ); ?>
										<br /><span class="citex-error-code"><?php esc_html_e( 'Code:', 'citex-tools' ); ?> <?php echo esc_html( $error['code'] ); ?></span>
									</li>
								<?php endforeach; ?>
							</ol>
						<?php endif; ?>

						<?php if ( ! empty( $result['warnings'] ) ) : ?>
							<strong><?php esc_html_e( 'Warnings:', 'citex-tools' ); ?></strong>
							<ol class="citex-result-errors">
								<?php foreach ( $result['warnings'] as $warning ) : ?>
									<li>
										<?php echo esc_html( $warning['message'] ); ?>
										<br /><span class="citex-error-code"><?php esc_html_e( 'Code:', 'citex-tools' ); ?> <?php echo esc_html( $warning['code'] ); ?></span>
									</li>
								<?php endforeach; ?>
							</ol>
						<?php endif; ?>

						<?php if ( ! empty( $result['reason'] ) ) : ?>
							<p class="description"><strong><?php esc_html_e( 'Stored result reason:', 'citex-tools' ); ?></strong> <?php echo esc_html( $result['reason'] ); ?></p>
						<?php endif; ?>

						<div class="citex-diagnostics">
							<strong><?php esc_html_e( 'Routing diagnostics (live, recomputed on every page load):', 'citex-tools' ); ?></strong>
							<table class="citex-diagnostics-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Field', 'citex-tools' ); ?></th>
										<th><?php esc_html_e( 'Received by router', 'citex-tools' ); ?></th>
										<th><?php esc_html_e( 'Expected', 'citex-tools' ); ?></th>
										<th><?php esc_html_e( 'Match', 'citex-tools' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $diagnosis['fields'] as $field_name => $field ) : ?>
										<tr>
											<td><?php echo esc_html( $field_name ); ?></td>
											<td><code><?php echo esc_html( '"' . $field['received'] . '"' ); ?></code></td>
											<td><code><?php echo esc_html( '"' . $field['expected'] . '"' ); ?></code></td>
											<td><?php echo $field['match'] ? '✓' : '✕'; ?></td>
										</tr>
									<?php endforeach; ?>
									<tr>
										<td><?php esc_html_e( 'questionId', 'citex-tools' ); ?></td>
										<td colspan="3"><code><?php echo esc_html( $diagnosis['questionId'] ?: '(empty)' ); ?></code></td>
									</tr>
									<tr>
										<td><?php esc_html_e( 'wpPostId', 'citex-tools' ); ?></td>
										<td colspan="3"><code><?php echo esc_html( null !== $diagnosis['wpPostId'] ? $diagnosis['wpPostId'] : '(null)' ); ?></code></td>
									</tr>
									<tr>
										<td><?php esc_html_e( 'Expected validator key', 'citex-tools' ); ?></td>
										<td colspan="3"><code><?php echo esc_html( $diagnosis['expectedValidatorKey'] ); ?></code></td>
									</tr>
									<tr>
										<td><?php esc_html_e( 'Selected validator key', 'citex-tools' ); ?></td>
										<td colspan="3"><code><?php echo esc_html( $diagnosis['selectedValidatorKey'] ?: '(none — unsupported)' ); ?></code></td>
									</tr>
									<tr>
										<td><?php esc_html_e( 'Routing result', 'citex-tools' ); ?></td>
										<td colspan="3"><code><?php echo esc_html( $diagnosis['routingResult'] ); ?></code></td>
									</tr>
									<tr>
										<td><?php esc_html_e( 'Validator class exists', 'citex-tools' ); ?></td>
										<td colspan="3"><code><?php echo $diagnosis['validatorExists'] ? 'true' : 'false'; ?></code></td>
									</tr>
									<tr>
										<td><?php esc_html_e( 'Rule engine implemented', 'citex-tools' ); ?></td>
										<td colspan="3"><code><?php echo $diagnosis['validatorImplemented'] ? 'true' : 'false (routing-only stub)'; ?></code></td>
									</tr>
								</tbody>
							</table>
							<p class="description"><strong><?php esc_html_e( 'Reason:', 'citex-tools' ); ?></strong> <?php echo esc_html( $diagnosis['reason'] ); ?></p>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
