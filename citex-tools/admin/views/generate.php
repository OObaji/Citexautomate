<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
/**
 * Generate Questions view.
 *
 * @var array $referencing_styles
 * @var array $institutions
 * @var array $categories
 * @var array $question_types
 * @var array $difficulties
 * @var array $pending_questions
 */
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Generate Questions', 'citex-tools' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Generator v1 creates Liverpool Hope Harvard Book / DragDrop questions as pending Citex records. Nothing is published to WordPress from this page.', 'citex-tools' ); ?>
	</p>

	<form method="post" class="citex-form">
		<?php wp_nonce_field( Citex_Generator::NONCE_ACTION, 'citex_generate_nonce' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="citex_referencing_style"><?php esc_html_e( 'Referencing Style', 'citex-tools' ); ?></label></th>
				<td>
					<select id="citex_referencing_style" name="citex_referencing_style">
						<?php foreach ( $referencing_styles as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="citex_institution"><?php esc_html_e( 'Institution / Referencing Rules', 'citex-tools' ); ?></label></th>
				<td>
					<select id="citex_institution" name="citex_institution">
						<?php foreach ( $institutions as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="citex_category"><?php esc_html_e( 'Category', 'citex-tools' ); ?></label></th>
				<td>
					<select id="citex_category" name="citex_category">
						<?php foreach ( $categories as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="citex_question_type"><?php esc_html_e( 'Question Type', 'citex-tools' ); ?></label></th>
				<td>
					<select id="citex_question_type" name="citex_question_type">
						<?php foreach ( $question_types as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="citex_difficulty"><?php esc_html_e( 'Difficulty', 'citex-tools' ); ?></label></th>
				<td>
					<select id="citex_difficulty" name="citex_difficulty">
						<?php foreach ( $difficulties as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( 'medium', $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Difficulty currently changes the number of distractor/confusing words.', 'citex-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="citex_starting_id"><?php esc_html_e( 'Starting Question ID', 'citex-tools' ); ?></label></th>
				<td>
					<input type="text" id="citex_starting_id" name="citex_starting_id" value="BK01" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Examples: BK01, BK25, BOOK001. Existing indexed/pending IDs are skipped automatically.', 'citex-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="citex_quantity"><?php esc_html_e( 'Quantity', 'citex-tools' ); ?></label></th>
				<td>
					<input
						type="number"
						id="citex_quantity"
						name="citex_quantity"
						value="10"
						min="1"
						max="100"
						class="small-text"
					/>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" name="citex_generate_submit" value="1" class="button button-primary">
				<?php esc_html_e( 'Generate Pending Questions', 'citex-tools' ); ?>
			</button>
		</p>
	</form>

	<hr />

	<div class="citex-section-heading">
		<h2><?php esc_html_e( 'Generated / Pending Questions', 'citex-tools' ); ?> (<?php echo esc_html( number_format_i18n( count( $pending_questions ) ) ); ?>)</h2>
		<?php if ( ! empty( $pending_questions ) ) : ?>
			<form method="post" style="display:inline-block;margin-left:12px;">
				<?php wp_nonce_field( Citex_Generator::NONCE_ACTION, 'citex_generate_nonce' ); ?>
				<button type="submit" name="citex_clear_pending" value="1" class="button" onclick="return confirm('Clear all pending generated questions? This does not delete WordPress questions.');">
					<?php esc_html_e( 'Clear Pending', 'citex-tools' ); ?>
				</button>
			</form>
		<?php endif; ?>
	</div>

	<?php if ( empty( $pending_questions ) ) : ?>
		<p><?php esc_html_e( 'No pending generated questions yet.', 'citex-tools' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped citex-table">
			<thead>
				<tr>
					<th style="width:80px;"><?php esc_html_e( 'ID', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'Question Parts', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'Fixed Text', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'Confusing Words', 'citex-tools' ); ?></th>
					<th><?php esc_html_e( 'Reconstructed Reference', 'citex-tools' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Difficulty', 'citex-tools' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Status', 'citex-tools' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Action', 'citex-tools' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $pending_questions as $question ) : ?>
				<tr>
					<td>
						<strong><?php echo esc_html( $question['questionId'] ?? '—' ); ?></strong>
						<br /><span class="description"><?php echo esc_html( $question['category'] ?? '' ); ?> / <?php echo esc_html( $question['type'] ?? '' ); ?></span>
					</td>
					<td><?php echo esc_html( implode( ' · ', $question['questionParts'] ?? array() ) ); ?></td>
					<td><code><?php echo esc_html( $question['fixedText'] ?? '' ); ?></code></td>
					<td><?php echo esc_html( implode( ' · ', $question['confusingWords'] ?? array() ) ); ?></td>
					<td><?php echo esc_html( $question['reconstructedReference'] ?? '' ); ?></td>
					<td><?php echo esc_html( $question['difficulty'] ?? '—' ); ?></td>
					<td><span class="citex-badge citex-badge-not_validated"><?php esc_html_e( 'Pending', 'citex-tools' ); ?></span></td>
					<td>
						<form method="post">
							<?php wp_nonce_field( Citex_Generator::NONCE_ACTION, 'citex_generate_nonce' ); ?>
							<input type="hidden" name="citex_pending_key" value="<?php echo esc_attr( $question['key'] ?? '' ); ?>" />
							<button type="submit" name="citex_delete_pending" value="1" class="button button-small"><?php esc_html_e( 'Remove', 'citex-tools' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
