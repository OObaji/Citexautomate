<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var array $snapshots */
/** @var array $hook_report */
/** @var string $post_type */
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Citex Diagnostics', 'citex-tools' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Read-only. Nothing on this page writes to a post or fires a save hook. Use this to investigate the "Published but not visible in the app until a manual wp-admin Update click" report: capture a post\'s full state before and after clicking Update, and see exactly what code is listening on the save lifecycle.', 'citex-tools' ); ?>
	</p>

	<h2><?php esc_html_e( '1. Who is actually listening on the save lifecycle?', 'citex-tools' ); ?></h2>
	<?php if ( ! $post_type ) : ?>
		<p><?php esc_html_e( 'Run a scan first (Dashboard) so Citex knows the real Reference List post type.', 'citex-tools' ); ?></p>
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
</div>
