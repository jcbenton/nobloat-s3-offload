<?php
/**
 * Bricks integration admin page template.
 *
 * @package Nobloat_S3_Offload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$nbs3_options           = get_option( 'nbs3_settings' );
$nbs3_sync_bricks_css   = isset( $nbs3_options['sync_bricks_css'] ) ? intval( $nbs3_options['sync_bricks_css'] ) : 0;
$nbs3_sync_theme_assets = isset( $nbs3_options['sync_bricks_theme_assets'] ) ? intval( $nbs3_options['sync_bricks_theme_assets'] ) : 0;
$nbs3_bricks_active     = nbs3_is_bricks_active();
?>
<div id="nbs3">
	<div class="wrap">
		<h2 class="nbs3-print-notices-after"></h2>
		<?php settings_errors( 'nbs3_messages' ); ?>

		<?php if ( ! $nbs3_bricks_active ) : ?>
			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Bricks Builder Not Detected', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content" style="padding: 20px;">
					<p><?php esc_html_e( 'Bricks Builder is not currently active. Install and activate Bricks to use this feature.', 'nobloat-s3-offload' ); ?></p>
				</div>
			</div>
		<?php else : ?>
			<?php
			$nbs3_css_status = nbs3_get_bricks_sync_status();

			$nbs3_s3_provider         = new \NBS3\S3Provider();
			$nbs3_theme_service       = new \NBS3\Services\BricksThemeAssetsSyncService( $nbs3_s3_provider );
			$nbs3_theme_assets_status = $nbs3_theme_service->get_status();
			?>

			<div class="nbs3-section nbs3-bricks-integration">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Bricks CSS Sync', 'nobloat-s3-offload' ); ?></h2>
					<p><?php esc_html_e( 'Sync Bricks Builder generated CSS files to S3 for CDN delivery.', 'nobloat-s3-offload' ); ?></p>
				</div>

				<table class="form-table" role="presentation">
					<tbody>
						<tr class="nbs3-field nbs3-bricks-sync">
							<th scope="row"><?php esc_html_e( 'Bricks CSS Sync', 'nobloat-s3-offload' ); ?></th>
							<td>
								<div class="nbs3-checkbox-option">
									<input type="checkbox" id="sync_bricks_css" name="nbs3_settings[sync_bricks_css]" value="1" <?php checked( 1, $nbs3_sync_bricks_css ); ?>/>
									<label for="sync_bricks_css"><?php esc_html_e( 'Sync Bricks CSS to S3', 'nobloat-s3-offload' ); ?></label>
									<p class="description"><?php esc_html_e( 'Automatically upload Bricks-generated CSS files to S3 and serve via CDN.', 'nobloat-s3-offload' ); ?></p>
								</div>

								<div class="nbs3-bricks-status" style="margin-top: 15px; padding: 12px; background: #f0f0f1; border-radius: 4px;">
									<p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Status:', 'nobloat-s3-offload' ); ?></strong></p>
									<p style="margin: 0;" id="nbs3-bricks-status-text">
										<?php
										printf(
											/* translators: %1$d: number synced, %2$d: number pending, %3$d: total number */
											esc_html__( '%1$d synced, %2$d pending, %3$d total', 'nobloat-s3-offload' ),
											intval( $nbs3_css_status['synced'] ),
											intval( $nbs3_css_status['pending'] ),
											intval( $nbs3_css_status['total'] )
										);
										?>
									</p>
								</div>

								<div class="nbs3-bricks-actions" style="margin-top: 15px;">
									<button type="button" class="button" id="nbs3-sync-bricks-now"><?php esc_html_e( 'Sync Now', 'nobloat-s3-offload' ); ?></button>
									<button type="button" class="button" id="nbs3-remove-bricks-s3" style="color: #b32d2e;"><?php esc_html_e( 'Remove from S3', 'nobloat-s3-offload' ); ?></button>
									<button type="button" class="button" id="nbs3-invalidate-bricks-css" style="color: #996800;"><?php esc_html_e( 'Invalidate', 'nobloat-s3-offload' ); ?></button>
									<span id="nbs3-bricks-action-status" style="margin-left: 10px;"></span>
								</div>

								<div style="margin-top: 15px;">
									<p class="description"><?php esc_html_e( 'For large operations, use WP-CLI:', 'nobloat-s3-offload' ); ?> <code>wp nbs3 sync-bricks</code></p>
									<p class="description"><?php esc_html_e( 'Invalidate attempts to delete from S3 first, then clears local sync tracking.', 'nobloat-s3-offload' ); ?></p>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="nbs3-section nbs3-bricks-integration">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Bricks Theme Assets', 'nobloat-s3-offload' ); ?></h2>
					<p><?php esc_html_e( 'Sync Bricks theme static assets (CSS, JS, fonts) to S3.', 'nobloat-s3-offload' ); ?></p>
				</div>

				<table class="form-table" role="presentation">
					<tbody>
						<tr class="nbs3-field nbs3-bricks-theme-assets">
							<th scope="row"><?php esc_html_e( 'Bricks Theme Assets', 'nobloat-s3-offload' ); ?></th>
							<td>
								<div class="nbs3-checkbox-option">
									<input type="checkbox" id="sync_bricks_theme_assets" name="nbs3_settings[sync_bricks_theme_assets]" value="1" <?php checked( 1, $nbs3_sync_theme_assets ); ?>/>
									<label for="sync_bricks_theme_assets"><?php esc_html_e( 'Sync Bricks Theme Assets to S3', 'nobloat-s3-offload' ); ?></label>
									<p class="description"><?php esc_html_e( 'Upload Bricks theme static assets (CSS, JS, fonts) to S3. Re-syncs automatically when any plugin or theme is updated.', 'nobloat-s3-offload' ); ?></p>
								</div>

								<div class="nbs3-bricks-theme-assets-status" style="margin-top: 15px; padding: 12px; background: #f0f0f1; border-radius: 4px;">
									<p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Status:', 'nobloat-s3-offload' ); ?></strong></p>
									<p style="margin: 0;" id="nbs3-bricks-theme-assets-status-text">
										<?php
										printf(
											/* translators: %1$d: number synced, %2$d: number pending, %3$d: total number of files */
											esc_html__( '%1$d synced, %2$d pending, %3$d total files', 'nobloat-s3-offload' ),
											intval( $nbs3_theme_assets_status['synced'] ),
											intval( $nbs3_theme_assets_status['pending'] ),
											intval( $nbs3_theme_assets_status['total'] )
										);
										?>
									</p>
								</div>

								<div class="nbs3-bricks-theme-assets-actions" style="margin-top: 15px;">
									<button type="button" class="button" id="nbs3-sync-bricks-theme-assets-now"><?php esc_html_e( 'Sync Now', 'nobloat-s3-offload' ); ?></button>
									<button type="button" class="button" id="nbs3-remove-bricks-theme-assets-s3" style="color: #b32d2e;"><?php esc_html_e( 'Remove from S3', 'nobloat-s3-offload' ); ?></button>
									<button type="button" class="button" id="nbs3-invalidate-bricks-theme-assets" style="color: #996800;"><?php esc_html_e( 'Invalidate', 'nobloat-s3-offload' ); ?></button>
									<span id="nbs3-bricks-theme-assets-action-status" style="margin-left: 10px;"></span>
								</div>

								<div style="margin-top: 15px;">
									<p class="description"><?php esc_html_e( 'Theme assets auto-sync whenever any plugin or theme is updated.', 'nobloat-s3-offload' ); ?></p>
									<p class="description"><?php esc_html_e( 'Invalidate attempts to delete from S3 first, then clears local sync tracking.', 'nobloat-s3-offload' ); ?></p>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>
