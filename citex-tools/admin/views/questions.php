<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
/**
 * Question Bank view.
 *
 * @var string $search
 * @var array  $filters
 * @var array  $categories
 * @var array  $question_types
 * @var array  $statuses
 * @var array  $questions       DEMO DATA — see Citex_Questions::get_demo_questions().
 */

$status_labels = array(
	'valid'         => __( 'Valid', 'citex-tools' ),
	'error'         => __( 'Error', 'citex-tools' ),
	'not_validated' => __( 'Not Validated', 'citex-tools' ),
);
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Question Bank', 'citex-tools' ); ?></h1>

	<form method="get" class="citex-filter-bar">
		<input type="hidden" name="page" value="citex-questions" />

		<input
			type="search"
			name="citex_search"
			class="citex-search-input"
			placeholder="<?php esc_attr_e( 'Search questions...', 'citex-tools' ); ?>"
			value="<?php echo esc_attr( $search ); ?>"
		/>

		<select name="citex_filter_style">
			<option value="all" <?php selected( $filters['style'], 'all' ); ?>><?php esc_html_e( 'All', 'citex-tools' ); ?></option>
			<option value="harvard" <?php selected( $filters['style'], 'harvard' ); ?>><?php esc_html_e( 'Harvard', 'citex-tools' ); ?></option>
		</select>

		<select name="citex_filter_category">
			<option value="all" <?php selected( $filters['category'], 'all' ); ?>><?php esc_html_e( 'All', 'citex-tools' ); ?></option>
			<?php foreach ( $categories as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['category'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="citex_filter_type">
			<option value="all" <?php selected( $filters['type'], 'all' ); ?>><?php esc_html_e( 'All', 'citex-tools' ); ?></option>
			<?php foreach ( $question_types as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="citex_filter_status">
			<option value="all" <?php selected( $filters['status'], 'all' ); ?>><?php esc_html_e( 'All', 'citex-tools' ); ?></option>
			<?php foreach ( $statuses as $value => $label ) : ?>
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
				<td colspan="8"><?php esc_html_e( 'No questions found.', 'citex-tools' ); ?></td>
			</tr>
		<?php else : ?>
			<?php foreach ( $questions as $question ) : ?>
				<tr>
					<th scope="row" class="check-column">
						<input type="checkbox" class="citex-row-select" />
					</th>
					<td><?php echo esc_html( $question['id'] ); ?></td>
					<td><?php echo esc_html( $question['title'] ); ?></td>
					<td><?php echo esc_html( $question['style'] ); ?></td>
					<td><?php echo esc_html( $question['category'] ); ?></td>
					<td><?php echo esc_html( $question['type'] ); ?></td>
					<td>
						<span class="citex-badge citex-badge-<?php echo esc_attr( $question['status'] ); ?>">
							<?php echo esc_html( isset( $status_labels[ $question['status'] ] ) ? $status_labels[ $question['status'] ] : $question['status'] ); ?>
						</span>
					</td>
					<td class="citex-actions">
						<button type="button" class="button button-small" disabled><?php esc_html_e( 'View', 'citex-tools' ); ?></button>
						<button type="button" class="button button-small" disabled><?php esc_html_e( 'Edit', 'citex-tools' ); ?></button>
						<button type="button" class="button button-small" disabled><?php esc_html_e( 'Validate', 'citex-tools' ); ?></button>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
	<p class="description">
		<?php esc_html_e( 'Demo records shown for layout purposes only — remove once real question records are connected.', 'citex-tools' ); ?>
	</p>
</div>
