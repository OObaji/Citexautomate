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
 */
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Generate Questions', 'citex-tools' ); ?></h1>

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
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
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
				<?php esc_html_e( 'Generate Questions', 'citex-tools' ); ?>
			</button>
		</p>
	</form>
</div>
