<?php
/**
 * S3 Connection admin page template.
 *
 * @package Nobloat_S3_Offload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$nbs3_options       = get_option( 'nbs3_settings' );
$nbs3_s3_object_acl = isset( $nbs3_options['s3_object_acl'] ) ? $nbs3_options['s3_object_acl'] : 'none';
$nbs3_last_check    = get_option( 'nbs3_last_connection_check', '' );
$nbs3_s3_provider   = new \NBS3\S3Provider();
?>
<div id="nbs3">
	<div class="wrap">
		<h2 class="nbs3-print-notices-after"></h2>
		<?php settings_errors( 'nbs3_messages' ); ?>

		<div class="nbs3-section nbs3-cloud-provider-settings">
			<div class="nbs3-section-header">
				<h2><?php esc_html_e( 'S3 Connection', 'nobloat-s3-offload' ); ?></h2>
				<p><?php esc_html_e( 'Configure your S3-compatible storage credentials.', 'nobloat-s3-offload' ); ?></p>
			</div>

			<table class="form-table" role="presentation">
				<tbody>
					<tr class="nbs3-field nbs3-cloud-provider-credentials">
						<th scope="row"><?php esc_html_e( 'Credentials', 'nobloat-s3-offload' ); ?></th>
						<td>
							<?php $nbs3_s3_provider->credentials_field(); ?>
						</td>
					</tr>
					<tr class="nbs3-field nbs3-s3-acl">
						<th scope="row"><?php esc_html_e( 'Object Permissions', 'nobloat-s3-offload' ); ?></th>
						<td>
							<div class="nbs3-radio-group">
								<div class="nbs3-radio-option">
									<input type="radio" id="s3_acl_none" name="nbs3_settings[s3_object_acl]" value="none" <?php checked( 'none', $nbs3_s3_object_acl ); ?>/>
									<label for="s3_acl_none"><?php esc_html_e( 'None (Bucket Policy)', 'nobloat-s3-offload' ); ?></label>
									<p class="description"><?php esc_html_e( 'Recommended. Access is controlled by your S3 bucket policy. Works with modern S3 buckets that have ACLs disabled.', 'nobloat-s3-offload' ); ?></p>
								</div>

								<div class="nbs3-radio-option">
									<input type="radio" id="s3_acl_public" name="nbs3_settings[s3_object_acl]" value="public-read" <?php checked( 'public-read', $nbs3_s3_object_acl ); ?>/>
									<label for="s3_acl_public"><?php esc_html_e( 'Public Read (ACL)', 'nobloat-s3-offload' ); ?></label>
									<p class="description"><?php esc_html_e( 'Set public-read ACL on each uploaded object. Only works if your bucket allows ACLs.', 'nobloat-s3-offload' ); ?></p>
								</div>

								<div class="nbs3-radio-option">
									<input type="radio" id="s3_acl_private" name="nbs3_settings[s3_object_acl]" value="private" <?php checked( 'private', $nbs3_s3_object_acl ); ?>/>
									<label for="s3_acl_private"><?php esc_html_e( 'Private (ACL)', 'nobloat-s3-offload' ); ?></label>
									<p class="description"><?php esc_html_e( 'Set private ACL on each uploaded object. Use with CloudFront signed URLs.', 'nobloat-s3-offload' ); ?></p>
								</div>
							</div>
						</td>
					</tr>
					<tr class="nbs3-field nbs3-connection-test">
						<th scope="row"><?php esc_html_e( 'Connection Test', 'nobloat-s3-offload' ); ?></th>
						<td>
							<button type="button" class="button button-secondary" id="nbs3-test-connection">
								<?php esc_html_e( 'Test Connection', 'nobloat-s3-offload' ); ?>
							</button>
							<span id="nbs3-connection-status" style="margin-left: 10px;"></span>
							<?php if ( ! empty( $nbs3_last_check ) ) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: last check timestamp */
										esc_html__( 'Last checked: %s', 'nobloat-s3-offload' ),
										esc_html( $nbs3_last_check )
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</div>
