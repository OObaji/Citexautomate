<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status_labels = array(
	'passed'        => __( '✓ Passed', 'citex-tools' ),
	'failed'        => __( '✕ Failed', 'citex-tools' ),
	'warning'       => __( '⚠ Warning', 'citex-tools' ),
	'not_validated' => __( '— Not Validated', 'citex-tools' ),
	'unsupported'   => __( '○ Unsupported', 'citex-tools' ),
);

$wp_status_labels = array(
	'publish' => __( 'Published', 'citex-tools' ),
	'draft'   => __( 'Draft', 'citex-tools' ),
	'pending' => __( 'Pending Review', 'citex-tools' ),
	'private' => __( 'Private', 'citex-tools' ),
	'future'  => __( 'Scheduled', 'citex-tools' ),
	'trash'   => __( 'Bin', 'citex-tools' ),
	''        => __( 'Unknown', 'citex-tools' ),
);
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Question Bank', 'citex-tools' ); ?></h1>

	<?php if ( $sync_notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $sync_notice['type'] ); ?> inline"><p><?php echo esc_html( $sync_notice['message'] ); ?></p></div>
	<?php endif; ?>

	<div class="citex-scan-panel">
		<p class="citex-scan-meta">
			<?php if ( $scan && ! empty( $scan['scannedAt'] ) ) : ?>
				<?php
				printf(
					esc_html__( 'Last synced from Reference List: %1$s — %2$s active questions indexed.', 'citex-tools' ),
					esc_html( Citex_Scanner::format_scanned_at( $scan['scannedAt'] ) ),
					esc_html( number_format_i18n( $scan['total'] ) )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'No sync yet. Configure the Reference List URL on the Dashboard first.', 'citex-tools' ); ?>
			<?php endif; ?>
		</p>

		<?php if ( $scan ) : ?>
			<div style="display:flex;gap:20px;flex-wrap:wrap;margin:10px 0 14px;">
				<strong><?php printf( esc_html__( 'All: %s', 'citex-tools' ), esc_html( number_format_i18n( $status_counts['all'] ?? $scan['total'] ) ) ); ?></strong>
				<strong><?php printf( esc_html__( 'Published: %s', 'citex-tools' ), esc_html( number_format_i18n( $status_counts['publish'] ?? 0 ) ) ); ?></strong>
				<strong><?php printf( esc_html__( 'Drafts: %s', 'citex-tools' ), esc_html( number_format_i18n( $status_counts['draft'] ?? 0 ) ) ); ?></strong>
				<?php if ( isset( $status_counts['pending'] ) ) : ?><strong><?php printf( esc_html__( 'Pending: %s', 'citex-tools' ), esc_html( number_format_i18n( $status_counts['pending'] ) ) ); ?></strong><?php endif; ?>
				<strong><?php printf( esc_html__( 'Bin: %s', 'citex-tools' ), esc_html( number_format_i18n( $status_counts['trash'] ?? 0 ) ) ); ?></strong>
			</div>
		<?php endif; ?>

		<form method="post" action="">
			<?php wp_nonce_field( Citex_Questions::SYNC_NONCE_ACTION, 'citex_sync_nonce' ); ?>
			<input type="hidden" name="citex_sync_reference_list" value="1" />
			<button type="submit" class="button button-primary" <?php disabled( empty( $question_list_url ) ); ?>>
				<?php echo $scan ? esc_html__( 'Refresh / Sync Reference List', 'citex-tools' ) : esc_html__( 'Sync Reference List', 'citex-tools' ); ?>
			</button>
		</form>
		<p class="description" style="margin-top:8px;"><?php esc_html_e( 'This refresh now reads the Reference List directly from WordPress; it does not depend on browser JavaScript.', 'citex-tools' ); ?></p>
	</div>

	<form method="get" class="citex-filter-bar">
		<input type="hidden" name="page" value="citex-questions" />
		<input type="search" name="citex_search" class="citex-search-input" placeholder="<?php esc_attr_e( 'Search questions...', 'citex-tools' ); ?>" value="<?php echo esc_attr( $search ); ?>" />

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

		<select name="citex_filter_post_status">
			<option value="all" <?php selected( $filters['post_status'], 'all' ); ?>><?php esc_html_e( 'All WordPress Statuses', 'citex-tools' ); ?></option>
			<?php foreach ( $post_statuses as $row ) : ?>
				<?php if ( '(blank)' === $row['name'] ) { continue; } ?>
				<option value="<?php echo esc_attr( $row['name'] ); ?>" <?php selected( $filters['post_status'], $row['name'] ); ?>><?php echo esc_html( $wp_status_labels[ $row['name'] ] ?? ucfirst( $row['name'] ) ); ?> (<?php echo esc_html( number_format_i18n( $row['count'] ) ); ?>)</option>
			<?php endforeach; ?>
		</select>

		<select name="citex_filter_status">
			<option value="all" <?php selected( $filters['validation_status'], 'all' ); ?>><?php esc_html_e( 'All Validation Statuses', 'citex-tools' ); ?></option>
			<?php foreach ( $status_labels as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['validation_status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'citex-tools' ); ?></button>
	</form>

	<div id="citex-bulk-status-editor" class="citex-scan-panel" data-filtered-post-ids="<?php echo esc_attr( wp_json_encode( $filtered_post_ids ) ); ?>">
		<h2><?php esc_html_e( 'Bulk Edit Real Reference List Status', 'citex-tools' ); ?></h2>
		<p class="description">
			<?php
			printf(
				esc_html__( 'Change the same Published/Draft status shown on the real Reference List for all %s matching records.', 'citex-tools' ),
				esc_html( number_format_i18n( count( $filtered_post_ids ) ) )
			);
			?>
		</p>

		<select id="citex-bulk-scope">
			<option value="filtered"><?php printf( esc_html__( 'All filtered questions (%s)', 'citex-tools' ), esc_html( number_format_i18n( count( $filtered_post_ids ) ) ) ); ?></option>
			<option value="selected"><?php esc_html_e( 'Selected on this page', 'citex-tools' ); ?></option>
		</select>

		<select id="citex-bulk-status">
			<?php foreach ( $wordpress_statuses as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>

		<button type="button" id="citex-apply-bulk-status" class="button button-primary" <?php disabled( empty( $filtered_post_ids ) ); ?>><?php esc_html_e( 'Apply to Reference List', 'citex-tools' ); ?></button>
		<p id="citex-bulk-status-progress" aria-live="polite"></p>
	</div>

	<table class="wp-list-table widefat fixed striped citex-table">
		<thead>
			<tr>
				<td class="manage-column column-cb check-column"><input type="checkbox" id="citex-select-all" /></td>
				<th><?php esc_html_e( 'Question ID', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Title', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'WordPress Status', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Referencing Style', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Category', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Question Type', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Validation Status', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'citex-tools' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $questions ) ) : ?>
			<tr><td colspan="9"><?php echo $scan ? esc_html__( 'No questions match your search/filters.', 'citex-tools' ) : esc_html__( 'No questions synced yet.', 'citex-tools' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $questions as $question ) : ?>
				<tr>
					<th scope="row" class="check-column">
						<input type="checkbox" class="citex-row-select" data-key="<?php echo esc_attr( $question['validationKey'] ); ?>" <?php if ( ! empty( $question['wpPostId'] ) ) : ?>data-post-id="<?php echo esc_attr( absint( $question['wpPostId'] ) ); ?>"<?php endif; ?> />
					</th>
					<td><?php echo esc_html( $question['questionId'] ? $question['questionId'] : '—' ); ?></td>
					<td><?php echo esc_html( $question['original'] ); ?></td>
					<td><strong><?php echo esc_html( $wp_status_labels[ $question['postStatus'] ?? '' ] ?? ucfirst( (string) ( $question['postStatus'] ?? '' ) ) ); ?></strong></td>
					<td><?php echo esc_html( $question['source'] ? $question['source'] : '—' ); ?></td>
					<td><?php echo esc_html( $question['category'] ? $question['category'] : '—' ); ?></td>
					<td><?php echo esc_html( $question['type'] ? $question['type'] : '—' ); ?></td>
					<td>
						<span class="citex-badge citex-badge-<?php echo esc_attr( $question['validationStatus'] ); ?>">
							<?php
							$status = $question['validationStatus'];
							if ( 'failed' === $status && ! empty( $question['validationResult']['errors'] ) ) {
								printf( esc_html( _n( '✕ %d Error', '✕ %d Errors', count( $question['validationResult']['errors'] ), 'citex-tools' ) ), (int) count( $question['validationResult']['errors'] ) );
							} else {
								echo esc_html( $status_labels[ $status ] ?? $status );
							}
							?>
						</span>
					</td>
					<td class="citex-actions">
						<?php if ( ! empty( $question['editUrl'] ) ) : ?><a class="button button-small" href="<?php echo esc_url( $question['editUrl'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Edit', 'citex-tools' ); ?></a><?php endif; ?>
						<?php if ( $question['validatorId'] ) : ?><button type="button" class="button button-small citex-validate-btn" data-key="<?php echo esc_attr( $question['validationKey'] ); ?>"><?php echo $question['validationResult'] ? esc_html__( 'Revalidate', 'citex-tools' ) : esc_html__( 'Validate', 'citex-tools' ); ?></button><?php endif; ?>
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
						'citex_search'             => $search,
						'citex_filter_source'      => $filters['source'],
						'citex_filter_category'    => $filters['category'],
						'citex_filter_type'        => $filters['type'],
						'citex_filter_post_status' => $filters['post_status'],
						'citex_filter_status'      => $filters['validation_status'],
						'citex_paged'              => $page_number,
					),
					admin_url( 'admin.php?page=citex-questions' )
				);
				?>
				<a href="<?php echo esc_url( $page_url ); ?>" class="citex-page-link<?php echo $page_number === $paged ? ' is-current' : ''; ?>"><?php echo esc_html( $page_number ); ?></a>
			<?php endfor; ?>
		</div>
	<?php endif; ?>

	<p class="description"><?php printf( esc_html__( 'Showing %1$s of %2$s matching questions.', 'citex-tools' ), esc_html( number_format_i18n( count( $questions ) ) ), esc_html( number_format_i18n( $total_filtered ) ) ); ?></p>
</div>
