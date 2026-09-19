<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var array $snapshots */
/** @var array $hook_report */
/** @var string $post_type */
/** @var string $target */
/** @var array $compare */
/** @var array $field_report */
$citex_diagnostics_target_labels = array( 'reference' => __( 'Reference List', 'citex-tools' ), 'citations' => __( 'Citations', 'citex-tools' ) );
$citex_diagnostics_target_label  = $citex_diagnostics_target_labels[ $target ] ?? $citex_diagnostics_target_labels['reference'];
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Citex Diagnostics', 'citex-tools' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Read-only. Nothing on this page writes to a post or fires a save hook. Use this to investigate the "Published but not visible in the app until a manual wp-admin Update click" report: capture a post\'s full state before and after clicking Update, and see exactly what code is listening on the save lifecycle.', 'citex-tools' ); ?>
	</p>

	<h2><?php esc_html_e( '1. Who is actually listening on the save lifecycle?', 'citex-tools' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Reference List and Citations are two separate real WordPress post types, so this must be checked independently for each — a hook registered for one says nothing about the other.', 'citex-tools' ); ?>
	</p>
	<p>
		<?php foreach ( $citex_diagnostics_target_labels as $target_key => $target_label ) : ?>
			<?php if ( $target_key === $target ) : ?>
				<strong class="citex-target-tab-active"><?php echo esc_html( $target_label ); ?></strong>
			<?php else : ?>
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-diagnostics&target=' . $target_key ) ); ?>"><?php echo esc_html( $target_label ); ?></a>
			<?php endif; ?>
			&nbsp;
		<?php endforeach; ?>
	</p>
	<?php if ( ! $post_type ) : ?>
		<p>
			<?php
			printf(
				/* translators: %s: "Reference List" or "Citations" */
				esc_html__( 'Run a scan first (Dashboard) so Citex knows the real %s post type.', 'citex-tools' ),
				esc_html( $citex_diagnostics_target_label )
			);
			?>
		</p>
	<?php else : ?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: post type slug */
				esc_html__( 'Every callback currently registered on the hooks Citex\'s Populator fires for post type "%s" — read live from WordPress\'s own hook registry, not guessed.', 'citex-tools' ),
				esc_html( $post_type )
			);
			?>
		</p>
		<table class="wp-list-table widefat fixed striped citex-table">
			<thead><tr>
				<th style="width:30%"><?php esc_html_e( 'Hook', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Registered callbacks (priority — source)', 'citex-tools' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $hook_report as $hook => $callbacks ) : ?>
				<tr>
					<td><code><?php echo esc_html( $hook ); ?></code></td>
					<td>
						<?php if ( empty( $callbacks ) ) : ?>
							<span class="description"><?php esc_html_e( 'No callbacks registered.', 'citex-tools' ); ?></span>
						<?php else : ?>
							<ul style="margin:0;">
								<?php foreach ( $callbacks as $entry ) : ?>
									<li><code><?php echo esc_html( $entry['priority'] ); ?></code> — <?php echo esc_html( $entry['callback'] ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( '2. Before / after post state', 'citex-tools' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Enter the WordPress post ID of a question you populated. Capture "Before", then open that post in wp-admin and click Update, then come back and capture "After". The diff below shows exactly what changed.', 'citex-tools' ); ?></p>

	<form method="post" class="citex-form" style="margin-bottom:16px;">
		<?php wp_nonce_field( Citex_Diagnostics::NONCE_ACTION, 'citex_diagnostics_nonce' ); ?>
		<label><strong><?php esc_html_e( 'Post ID:', 'citex-tools' ); ?></strong>
			<input type="number" min="1" name="citex_diagnostics_post_id" value="<?php echo esc_attr( (string) ( $_GET['post_id'] ?? '' ) ); ?>" required />
		</label>
		<button type="submit" name="citex_diagnostics_capture" value="1" class="button button-primary" onclick="this.form.elements['citex_diagnostics_label'].value='before';">
			<?php esc_html_e( 'Capture "Before"', 'citex-tools' ); ?>
		</button>
		<button type="submit" name="citex_diagnostics_capture" value="1" class="button button-primary" onclick="this.form.elements['citex_diagnostics_label'].value='after';">
			<?php esc_html_e( 'Capture "After"', 'citex-tools' ); ?>
		</button>
		<button type="submit" name="citex_diagnostics_clear" value="1" class="button">
			<?php esc_html_e( 'Clear snapshots for this post', 'citex-tools' ); ?>
		</button>
		<input type="hidden" name="citex_diagnostics_label" value="before" />
	</form>

	<?php
	$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
	if ( ! $post_id ) {
		foreach ( array_keys( $snapshots ) as $known_id ) {
			$post_id = (int) $known_id;
			break;
		}
	}
	$pair = $post_id ? ( $snapshots[ $post_id ] ?? array() ) : array();
	?>

	<?php if ( ! empty( $pair['before'] ) ) : ?>
		<h3><?php esc_html_e( 'Before', 'citex-tools' ); ?></h3>
		<pre style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:10px;"><?php echo esc_html( wp_json_encode( $pair['before'], JSON_PRETTY_PRINT ) ); ?></pre>
	<?php endif; ?>

	<?php if ( ! empty( $pair['after'] ) ) : ?>
		<h3><?php esc_html_e( 'After', 'citex-tools' ); ?></h3>
		<pre style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:10px;"><?php echo esc_html( wp_json_encode( $pair['after'], JSON_PRETTY_PRINT ) ); ?></pre>
	<?php endif; ?>

	<?php if ( ! empty( $pair['before'] ) && ! empty( $pair['after'] ) ) : ?>
		<h3><?php esc_html_e( 'Diff (Before → After)', 'citex-tools' ); ?></h3>
		<?php $diff = Citex_Diagnostics::diff_snapshots( $pair['before'], $pair['after'] ); ?>
		<?php if ( empty( $diff ) ) : ?>
			<p><strong><?php esc_html_e( 'No difference — the manual Update click did not change any post field, meta, taxonomy term, or ACF value that this page can read.', 'citex-tools' ); ?></strong> <?php esc_html_e( 'This would mean the app reads something outside WordPress/ACF entirely (an external cache, index, or database), which this plugin cannot detect.', 'citex-tools' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped citex-table">
				<thead><tr>
					<th><?php esc_html_e( 'Path', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'Before', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'After', 'citex-tools' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $diff as $path => $change ) : ?>
					<tr>
						<td><code><?php echo esc_html( $path ); ?></code></td>
						<td><code><?php echo esc_html( wp_json_encode( $change['before'] ) ); ?></code></td>
						<td><code><?php echo esc_html( wp_json_encode( $change['after'] ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>

	<h2><?php esc_html_e( '3. Compare any two posts', 'citex-tools' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Enter the post ID of a question that already shows correctly on the app, and the post ID of one that does not (e.g. one just populated into an empty table). This diffs their full state directly against each other — every postmeta key, taxonomy term and ACF value the working post has that the other is missing (or vice versa) — without needing to click Update on anything.', 'citex-tools' ); ?>
	</p>
	<form method="post" class="citex-form" style="margin-bottom:16px;">
		<?php wp_nonce_field( Citex_Diagnostics::NONCE_ACTION, 'citex_diagnostics_nonce' ); ?>
		<label><strong><?php esc_html_e( 'Working post ID:', 'citex-tools' ); ?></strong>
			<input type="number" min="1" name="citex_diagnostics_compare_a" value="<?php echo esc_attr( (string) ( $compare['postIdA'] ?? '' ) ); ?>" required />
		</label>
		&nbsp;
		<label><strong><?php esc_html_e( 'Invisible post ID:', 'citex-tools' ); ?></strong>
			<input type="number" min="1" name="citex_diagnostics_compare_b" value="<?php echo esc_attr( (string) ( $compare['postIdB'] ?? '' ) ); ?>" required />
		</label>
		<button type="submit" name="citex_diagnostics_compare" value="1" class="button button-primary">
			<?php esc_html_e( 'Compare', 'citex-tools' ); ?>
		</button>
	</form>

	<?php if ( ! empty( $compare['a'] ) && ! empty( $compare['b'] ) ) : ?>
		<?php $compare_diff = Citex_Diagnostics::diff_snapshots( $compare['a'], $compare['b'] ); ?>
		<?php if ( empty( $compare_diff ) ) : ?>
			<p><strong><?php esc_html_e( 'No difference — both posts have identical postmeta, taxonomy terms and ACF values (aside from ID/date/title). This would rule out anything WordPress/ACF itself can see as the cause.', 'citex-tools' ); ?></strong></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped citex-table">
				<thead><tr>
					<th><?php esc_html_e( 'Path', 'citex-tools' ); ?></th>
					<th><?php printf( /* translators: %d: post ID */ esc_html__( 'Working (#%d)', 'citex-tools' ), (int) $compare['postIdA'] ); ?></th>
					<th><?php printf( /* translators: %d: post ID */ esc_html__( 'Invisible (#%d)', 'citex-tools' ), (int) $compare['postIdB'] ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $compare_diff as $path => $change ) : ?>
					<tr>
						<td><code><?php echo esc_html( $path ); ?></code></td>
						<td><code><?php echo esc_html( wp_json_encode( $change['before'] ) ); ?></code></td>
						<td><code><?php echo esc_html( wp_json_encode( $change['after'] ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>

	<h2><?php esc_html_e( '4. DragDrop-only field attachment check', 'citex-tools' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Enter a Citations DragDrop post ID (e.g. a stuck In-Text Citation question). This checks, per field, whether it is genuinely ATTACHED to this specific post (respecting the field\'s own Location rules) — not just whether Citex could write and read it back, which does not by itself prove the field group is attached to this post type at all. If the DragDrop-only fields show "No" here while the shared/MCQ fields show "Yes", that pinpoints a Location-rule gap for this post type as the cause.', 'citex-tools' ); ?>
	</p>
	<form method="get" class="citex-form" style="margin-bottom:16px;">
		<input type="hidden" name="page" value="citex-diagnostics" />
		<input type="hidden" name="target" value="<?php echo esc_attr( $target ); ?>" />
		<label><strong><?php esc_html_e( 'Post ID:', 'citex-tools' ); ?></strong>
			<input type="number" min="1" name="field_check_post_id" value="<?php echo esc_attr( (string) ( $_GET['field_check_post_id'] ?? '' ) ); ?>" required />
		</label>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Check', 'citex-tools' ); ?></button>
	</form>

	<?php if ( ! empty( $field_report ) ) : ?>
		<table class="wp-list-table widefat fixed striped citex-table">
			<thead><tr>
				<th><?php esc_html_e( 'Field', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Field key', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Globally defined?', 'citex-tools' ); ?></th>
				<th><?php esc_html_e( 'Attached to this post?', 'citex-tools' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $field_report as $label => $row ) : ?>
				<tr>
					<td><?php echo esc_html( $label ); ?></td>
					<td><code><?php echo esc_html( $row['fieldKey'] ); ?> <?php echo $row['fieldName'] ? '(' . esc_html( $row['fieldName'] ) . ')' : ''; ?></code></td>
					<td><?php echo $row['globallyDefined'] ? esc_html__( 'Yes', 'citex-tools' ) : '<strong>' . esc_html__( 'No', 'citex-tools' ) . '</strong>'; ?></td>
					<td><?php echo $row['attachedToThisPost'] ? esc_html__( 'Yes', 'citex-tools' ) : '<strong>' . esc_html__( 'No', 'citex-tools' ) . '</strong>'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
