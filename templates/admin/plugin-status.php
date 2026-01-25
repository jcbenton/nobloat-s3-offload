<?php
/**
 * Plugin status admin page template.
 *
 * @package Nobloat_S3_Offload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$nbs3_is_enabled = nbs3_is_plugin_enabled();
?>
<div id="nbs3">
	<div class="wrap">
		<h2 class="nbs3-print-notices-after"></h2>
		<?php settings_errors( 'nbs3_messages' ); ?>

		<div class="nbs3-section nbs3-master-enable-section">
			<div class="nbs3-section-header">
				<h2><?php esc_html_e( 'Status', 'nobloat-s3-offload' ); ?></h2>
				<?php if ( ! $nbs3_is_enabled ) : ?>
					<div class="nbs3-setup-notice">
						<p><strong><?php esc_html_e( 'Setup Steps:', 'nobloat-s3-offload' ); ?></strong></p>
						<ol>
							<li><?php esc_html_e( 'Go to the Connection tab and configure your S3 credentials (Access Key, Secret Key, Bucket, Region).', 'nobloat-s3-offload' ); ?></li>
							<li><?php esc_html_e( 'Click "Test Connection" to verify your credentials work correctly.', 'nobloat-s3-offload' ); ?></li>
							<li><?php esc_html_e( 'Review the Settings tab to configure your preferred offload behavior.', 'nobloat-s3-offload' ); ?></li>
							<li><?php esc_html_e( 'Return here and enable the plugin to start offloading new media uploads.', 'nobloat-s3-offload' ); ?></li>
							<li><?php esc_html_e( 'Go to the Media tab to offload existing media using the bulk offload button, or use WP-CLI (wp nbs3 offload) for large libraries.', 'nobloat-s3-offload' ); ?></li>
						</ol>
					</div>
				<?php endif; ?>
			</div>

			<table class="form-table" role="presentation">
				<tbody>
					<tr class="nbs3-field nbs3-master-enable">
						<th scope="row"><?php esc_html_e( 'Enable Plugin', 'nobloat-s3-offload' ); ?></th>
						<td>
							<div class="nbs3-master-toggle">
								<label class="nbs3-toggle-switch">
									<input type="checkbox" id="plugin_enabled" name="plugin_enabled" value="1" <?php checked( 1, $nbs3_is_enabled ); ?>/>
									<span class="nbs3-toggle-slider"></span>
								</label>
								<span class="nbs3-toggle-label"><?php echo $nbs3_is_enabled ? esc_html__( 'Enabled', 'nobloat-s3-offload' ) : esc_html__( 'Disabled', 'nobloat-s3-offload' ); ?></span>
							</div>
							<?php if ( $nbs3_is_enabled ) : ?>
								<p class="description" style="color: #00a32a;"><?php esc_html_e( 'The plugin is active and will offload media to S3.', 'nobloat-s3-offload' ); ?></p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'The plugin is disabled. Media will not be offloaded until you enable it.', 'nobloat-s3-offload' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</div>
