<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap citex-wrap">
	<h1 class="citex-page-title"><?php esc_html_e( 'Citex AI Settings', 'citex-tools' ); ?></h1>

	<div class="notice notice-info inline">
		<p><strong><?php esc_html_e( 'How this works:', 'citex-tools' ); ?></strong> <?php esc_html_e( 'Gemini generates structured bibliographic questions. Citex then validates the result independently. Nothing is written to the real Reference List until the question passes validation and you populate it.', 'citex-tools' ); ?></p>
	</div>

	<form method="post" class="citex-form">
		<?php wp_nonce_field( 'citex_ai_settings', 'citex_ai_settings_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="citex_gemini_api_key"><?php esc_html_e( 'Gemini API Key', 'citex-tools' ); ?></label></th>
				<td>
					<input type="password" id="citex_gemini_api_key" name="citex_gemini_api_key" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $has_key ? 'Saved — leave blank to keep it' : 'Paste Gemini API key' ); ?>" />
					<p class="description"><?php esc_html_e( 'The key is stored server-side and is never sent to the browser as part of the generator page. You can also set GEMINI_API_KEY on the server; the environment variable takes precedence.', 'citex-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="citex_gemini_model"><?php esc_html_e( 'Gemini Model', 'citex-tools' ); ?></label></th>
				<td>
					<input type="text" id="citex_gemini_model" name="citex_gemini_model" value="<?php echo esc_attr( $model ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Default: gemini-3.7-flash. Keep this configurable so the generator can move to newer supported Gemini models without a plugin code change.', 'citex-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Bibliographic Web Verification', 'citex-tools' ); ?></th>
				<td>
					<label><input type="checkbox" name="citex_gemini_web_verify" value="1" <?php checked( $web_verify, true ); ?> /> <?php esc_html_e( 'Enable Google Search verification by default', 'citex-tools' ); ?></label>
					<p class="description"><?php esc_html_e( 'Recommended when generating real questions. The generator can still override this per batch.', 'citex-tools' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="submit"><button type="submit" name="citex_ai_save_settings" value="1" class="button button-primary"><?php esc_html_e( 'Save AI Settings', 'citex-tools' ); ?></button> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=citex-generate' ) ); ?>"><?php esc_html_e( 'Back to Generate Questions', 'citex-tools' ); ?></a></p>
	</form>

	<h2><?php esc_html_e( 'Current Connection', 'citex-tools' ); ?></h2>
	<table class="widefat striped" style="max-width:760px;">
		<tbody>
			<tr><td><strong><?php esc_html_e( 'API key', 'citex-tools' ); ?></strong></td><td><?php echo $has_key ? esc_html__( 'Configured', 'citex-tools' ) : esc_html__( 'Not configured', 'citex-tools' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Model', 'citex-tools' ); ?></strong></td><td><code><?php echo esc_html( $model ); ?></code></td></tr>
			<tr><td><strong><?php esc_html_e( 'Default web verification', 'citex-tools' ); ?></strong></td><td><?php echo $web_verify ? esc_html__( 'Enabled', 'citex-tools' ) : esc_html__( 'Disabled', 'citex-tools' ); ?></td></tr>
		</tbody>
	</table>
</div>
