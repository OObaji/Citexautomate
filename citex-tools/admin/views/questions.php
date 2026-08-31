<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
/**
 * Question Bank view.
 *
 * @var string     $search
 * @var array      $filters
 * @var array      $sources            [{name, count}] from the last scan.
 * @var array      $categories         [{name, count}] from the last scan.
 * @var array      $types              [{name, count}] from the last scan.
 * @var array      $questions          Current page slice of real scanned records.
 * @var int        $total_filtered
 * @var int        $total_pages
 * @var int        $paged
 * @var array|null $scan               Full last-scan report, or null if never scanned.
 * @var string     $question_list_url
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
	<h1 class="citex-page-title"><?php esc_html_e( 'Question Bank', 'citex-tools' ); ?></h1>

	<div class="citex-scan-panel">
		<p class="citex-scan-meta">
			<?php if ( $scan && ! empty( $scan['scannedAt'] ) ) : ?>
				<?php
				printf(
					/* translators: 1: date/time of the last scan, 2: number of questions indexed. */
					esc_html__( 'Last scanned: %1$s — %2$s questions indexed.', 'citex-tools' ),
					esc_html( Citex_Scanner::format_scanned_at( $scan['scannedAt'] ) ),
					esc_html( number_format_i18n( $scan['total'] ) )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'No scan yet. Configure the Question List URL and run a scan from the Dashboard.', 'citex-tools' ); ?>
			<?php endif; ?>
		</p>
		<button
			type="button"
			class="button button-primary citex-scan-btn"
			<?php disabled( empty( $question_list_url ) ); ?>
		>
			<?php echo $scan ? esc_html__( 'Refresh / Scan Again', 'citex-tools' ) : esc_html__( 'Scan Question Bank', 'citex-tools' ); ?>
		</button>
		<p class="citex-scan-status" aria-live="polite"></p>
	</div>

	<form method="get" class="citex-filter-bar">
		<input type="hidden" name="page" value="citex-questions" />

		<input
			type="search"
			name="citex_search"
			class="citex-search-input"
			placeholder="<?php esc_attr_e( 'Search questions...', 'citex-tools' ); ?>"
			value="<?php echo esc_attr( $search ); ?>"
		/>

		<select name="citex_filter_source">
			<option value="all" <?php selected( $filters['source'], 'all' ); ?>><?php esc_html_e( 'All Sources', 'citex-tools' ); ?></option>
			<?php foreach ( $sources as $row ) : ?>
				<option value="<?php echo esc_attr( $row['name'] ); ?>" <?php selected( $filters['source'], $row['name'] ); ?>><?php echo esc_html( $row['name'] ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="citex_filter_category">
			<option value="all" <?php selected( $filters['category'], 'all' ); ?>><?php esc_html_e( 'All Categories', 'citex-tools' ); ?></option>
			<?php foreach ( $categories as $row ) : ?>
				<option value="<?php echo esc_attr( $row['name'] ); ?>" <?php selected( $filters['category'], $row['name'] ); ?>><?php echo esc_html( $row['name'] ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="citex_filter_type">
			<option value="all" <?php selected( $filters['type'], 'all' ); ?>><?php esc_html_e( 'All Question Types', 'citex-tools' ); ?></option>
			<?php foreach ( $types as $row ) : ?>
				<option value="<?php echo esc_attr( $row['name'] ); ?>" <?php selected( $filters['type'], $row['name'] ); ?>><?php echo esc_html( $row['name'] ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="citex_filter_status">
			<option value="all" <?php selected( $filters['status'], 'all' ); ?>><?php esc_html_e( 'All', 'citex-tools' ); ?></option>
			<?php foreach ( $status_labels as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'citex-tools' ); ?></button>
	</form>

	<table class="wp-list-table widefat fixed striped citex-table">
		<thead>
			<tr>
				<td class="manage-column column-cb check-column">
					<input type="checkbox" id="citex-select-all" />
				</td>
				<th><?php esc_html_e( 'Question ID', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Title', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Referencing Style', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Category', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Question Type', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Validation Status', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'citex-tools' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $questions ) ) : ?>
			<tr>
				<td colspan="8">
					<?php echo $scan ? esc_html__( 'No questions match your search/filters.', 'citex-tools' ) : esc_html__( 'No questions scanned yet.', 'citex-tools' ); ?>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $questions as $question ) : ?>
				<tr>
					<th scope="row" class="check-column">
						<input type="checkbox" class="citex-row-select" />
					</th>
					<td><?php echo esc_html( $question['questionId'] ? $question['questionId'] : '—' ); ?></td>
					<td><?php echo esc_html( $question['original'] ); ?></td>
					<td><?php echo esc_html( $question['source'] ? $question['source'] : '—' ); ?></td>
					<td><?php echo esc_html( $question['category'] ? $question['category'] : '—' ); ?></td>
					<td><?php echo esc_html( $question['type'] ? $question['type'] : '—' ); ?></td>
					<td>
						<span class="citex-badge citex-badge-<?php echo esc_attr( $question['validationStatus'] ); ?>">
							<?php
							$status = $question['validationStatus'];
							if ( 'failed' === $status && ! empty( $question['validationResult']['errors'] ) ) {
								printf(
									/* translators: %d: number of errors. */
									esc_html( _n( '✕ %d Error', '✕ %d Errors', count( $question['validationResult']['errors'] ), 'citex-tools' ) ),
									(int) count( $question['validationResult']['errors'] )
								);
							} else {
								echo esc_html( $status_labels[ $status ] ?? $status );
							}
							?>
						</span>
					</td>
					<td class="citex-actions">
						<button type="button" class="button button-small" disabled><?php esc_html_e( 'View', 'citex-tools' ); ?></button>
						<?php if ( ! empty( $question['editUrl'] ) ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $question['editUrl'] ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Edit', 'citex-tools' ); ?>
							</a>
						<?php else : ?>
							<button type="button" class="button button-small" disabled><?php esc_html_e( 'Edit', 'citex-tools' ); ?></button>
						<?php endif; ?>
						<?php if ( $question['validatorId'] ) : ?>
							<button type="button" class="button button-small citex-validate-btn" data-key="<?php echo esc_attr( $question['validationKey'] ); ?>">
								<?php echo $question['validationResult'] ? esc_html__( 'Revalidate', 'citex-tools' ) : esc_html__( 'Validate', 'citex-tools' ); ?>
							</button>
						<?php else : ?>
							<button type="button" class="button button-small" disabled><?php esc_html_e( 'Validate', 'citex-tools' ); ?></button>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="citex-pagination">
			<?php for ( $page_number = 1; $page_number <= $total_pages; $page_number++ ) : ?>
				<?php
				$page_url = add_query_arg(
					array(
						'citex_search'           => $search,
						'citex_filter_source'    => $filters['source'],
						'citex_filter_category'  => $filters['category'],
						'citex_filter_type'      => $filters['type'],
						'citex_filter_status'    => $filters['status'],
						'citex_paged'            => $page_number,
					),
					admin_url( 'admin.php?page=citex-questions' )
				);
				?>
				<a
					href="<?php echo esc_url( $page_url ); ?>"
					class="citex-page-link<?php echo $page_number === $paged ? ' is-current' : ''; ?>"
				><?php echo esc_html( $page_number ); ?></a>
			<?php endfor; ?>
		</div>
	<?php endif; ?>

	<p class="description">
		<?php
		printf(
			/* translators: 1: number of questions shown on this page, 2: total matching the current search/filters. */
			esc_html__( 'Showing %1$s of %2$s questions.', 'citex-tools' ),
			esc_html( number_format_i18n( count( $questions ) ) ),
			esc_html( number_format_i18n( $total_filtered ) )
		);
		?>
	</p>
</div>
