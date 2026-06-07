<?php
/**
 * Documentation page template.
 *
 * @package Nobloat_S3_Offload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}
?>
<div id="nbs3">
	<div class="wrap">
		<div class="nbs3-documentation">

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Getting Started', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<p><?php esc_html_e( 'Nobloat S3 Offload allows you to offload your WordPress media files to Amazon S3 or any S3-compatible storage provider, reducing server disk usage and enabling CDN delivery for faster page loads.', 'nobloat-s3-offload' ); ?></p>

					<h3><?php esc_html_e( 'Quick Setup', 'nobloat-s3-offload' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Create an S3 bucket (or use an existing one)', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Create IAM credentials with S3 access permissions', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Go to the Connection tab, enter your S3 credentials, and click "Test Connection" to verify', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Go to the Settings tab to configure your preferred offload behavior', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Go to the Status tab and enable the plugin to start offloading new media uploads', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Go to the Media tab to offload existing media using the bulk offload button, or use WP-CLI (wp nbs3 offload) for large libraries', 'nobloat-s3-offload' ); ?></li>
					</ol>
				</div>
			</div>

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Connection Tab', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<p><?php esc_html_e( 'The Connection tab contains your S3 credentials and connection settings.', 'nobloat-s3-offload' ); ?></p>
					<table class="nbs3-docs-table">
						<tr>
							<th><?php esc_html_e( 'Region', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'The AWS region where your bucket is located (e.g., us-east-1, eu-west-1).', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Bucket Name', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'The name of your S3 bucket where files will be stored.', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Access Key', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'Your AWS IAM access key ID with permissions to read/write to the bucket.', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Secret Key', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'Your AWS IAM secret access key (keep this secure!).', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'CloudFront or Custom Domain (CDN)', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'Optional. Enter your CloudFront distribution URL or custom CDN domain. If left empty, URLs will use the direct S3 bucket URL.', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'S3 Endpoint', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'Leave empty for AWS S3. For S3-compatible providers like DigitalOcean Spaces, Cloudflare R2, or MinIO, enter the custom endpoint URL. Example: https://nyc3.digitaloceanspaces.com', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Use Path-Style Endpoint', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'Enable for providers that require path-style URLs (bucket in path) instead of virtual-hosted style (bucket in subdomain). Common with MinIO and some self-hosted solutions.', 'nobloat-s3-offload' ); ?></td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'Object Permissions (ACL)', 'nobloat-s3-offload' ); ?></h3>
					<table class="nbs3-docs-table">
						<tr>
							<th><?php esc_html_e( 'None (Bucket Policy)', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'Recommended. No ACL is set on uploaded objects. Access is controlled by your bucket policy. Required for buckets with "Bucket Owner Enforced" ownership (most modern S3 setups).', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Public Read (ACL)', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'Sets public-read ACL on each uploaded object. Only works if your bucket allows ACLs. Objects are publicly accessible via their S3 URL.', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Private (ACL)', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'Sets private ACL on each uploaded object. Use when serving files through CloudFront with signed URLs or Origin Access Identity.', 'nobloat-s3-offload' ); ?></td>
						</tr>
					</table>
				</div>
			</div>

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Status Tab', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<p><?php esc_html_e( 'The Status tab contains a master enable/disable toggle. This allows you to fully configure and test your S3 connection before activating the offload functionality.', 'nobloat-s3-offload' ); ?></p>
					<table class="nbs3-docs-table">
						<tr>
							<th><?php esc_html_e( 'Disabled (Default)', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'The plugin will not offload any media. Use this state while setting up credentials and testing. All settings can still be configured.', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Enabled', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'The plugin is active and will offload media according to your settings. Only enable after successfully testing your S3 connection.', 'nobloat-s3-offload' ); ?></td>
						</tr>
					</table>
				</div>
			</div>

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Settings Tab', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<p><?php esc_html_e( 'The Settings tab contains general offload behavior options.', 'nobloat-s3-offload' ); ?></p>
					<table class="nbs3-docs-table">
						<tr>
							<th><?php esc_html_e( 'Auto-Offload Media', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'When enabled, new media uploads are automatically sent to S3. Disable if you want to manually control which files are offloaded.', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Retention Policy', 'nobloat-s3-offload' ); ?></th>
							<td>
								<strong><?php esc_html_e( 'Retain Local Files:', 'nobloat-s3-offload' ); ?></strong> <?php esc_html_e( 'Keep all files on your server after offloading.', 'nobloat-s3-offload' ); ?><br>
								<strong><?php esc_html_e( 'Smart Local Cleanup:', 'nobloat-s3-offload' ); ?></strong> <?php esc_html_e( 'Delete generated sizes but keep the original file.', 'nobloat-s3-offload' ); ?><br>
								<strong><?php esc_html_e( 'Full Cloud Migration:', 'nobloat-s3-offload' ); ?></strong> <?php esc_html_e( 'Delete all local files after successful upload.', 'nobloat-s3-offload' ); ?><br>
								<em><?php esc_html_e( 'Safety Note: If File Versioning is disabled and a potential S3 collision is detected, local files are preserved to prevent data loss.', 'nobloat-s3-offload' ); ?></em>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'File Versioning', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'Enabled by default. Adds a unique timestamp to file paths in S3, preventing overwrites and ensuring updated files bypass CDN cache. Recommended to keep enabled.', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Mirror Delete', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'When enabled, deleting a media file in WordPress also deletes it from S3. Disable if you want to preserve S3 copies.', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Custom Path Prefix', 'nobloat-s3-offload' ); ?></th>
							<td><?php esc_html_e( 'Add a prefix to organize files in your bucket. Useful for multisite or when sharing a bucket between multiple sites. Example: wp-content/uploads/', 'nobloat-s3-offload' ); ?></td>
						</tr>
					</table>
				</div>
			</div>

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Media Tab', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<p><?php esc_html_e( 'The Media tab shows the offload status of your media library and allows you to bulk offload existing files.', 'nobloat-s3-offload' ); ?></p>

					<h3><?php esc_html_e( 'Media Library Integration', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'The plugin adds an "S3 Status" column to the Media Library list view, showing the offload status of each file:', 'nobloat-s3-offload' ); ?></p>
					<ul>
						<li><strong><?php esc_html_e( 'Offloaded', 'nobloat-s3-offload' ); ?></strong> - <?php esc_html_e( 'File has been uploaded to S3 and is served from the cloud.', 'nobloat-s3-offload' ); ?></li>
						<li><strong><?php esc_html_e( 'Not Offloaded', 'nobloat-s3-offload' ); ?></strong> - <?php esc_html_e( 'File is stored locally and has not been uploaded to S3.', 'nobloat-s3-offload' ); ?></li>
						<li><strong><?php esc_html_e( 'Error', 'nobloat-s3-offload' ); ?></strong> - <?php esc_html_e( 'A previous offload attempt failed. Check the error log for details.', 'nobloat-s3-offload' ); ?></li>
					</ul>
					<p><?php esc_html_e( 'Click on any attachment to view detailed S3 information and manage offload status from the edit screen.', 'nobloat-s3-offload' ); ?></p>

					<h3><?php esc_html_e( 'Individual Attachment Management', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'When editing an individual attachment, the "S3 Offload Status" meta box provides:', 'nobloat-s3-offload' ); ?></p>
					<ul>
						<li><?php esc_html_e( 'Current offload status and S3 path', 'nobloat-s3-offload' ); ?></li>
						<li><strong><?php esc_html_e( 'Offload Now', 'nobloat-s3-offload' ); ?></strong> - <?php esc_html_e( 'Upload this file to S3 immediately.', 'nobloat-s3-offload' ); ?></li>
						<li><strong><?php esc_html_e( 'Remove from S3', 'nobloat-s3-offload' ); ?></strong> - <?php esc_html_e( 'Delete the file from S3 (keeps local copy if available).', 'nobloat-s3-offload' ); ?></li>
						<li><strong><?php esc_html_e( 'Clear Status', 'nobloat-s3-offload' ); ?></strong> - <?php esc_html_e( 'Reset offload metadata without deleting files.', 'nobloat-s3-offload' ); ?></li>
					</ul>

					<h3><?php esc_html_e( 'Bulk Offload', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'Use the bulk offload feature to migrate your existing media library to S3. The process runs in batches to avoid server timeouts. You can pause and resume at any time.', 'nobloat-s3-offload' ); ?></p>

					<h3><?php esc_html_e( 'WP-CLI Commands', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'For large media libraries, WP-CLI is recommended:', 'nobloat-s3-offload' ); ?></p>
					<pre><code># Offload all unoffloaded media
wp nbs3 offload

# Offload specific attachment(s)
wp nbs3 offload 123
wp nbs3 offload 123,456,789

# Offload with limit
wp nbs3 offload --limit=100

# Skip previously failed attachments
wp nbs3 offload --skip-failed</code></pre>

					<h3><?php esc_html_e( 'Reverting to Local Storage', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'You can download offloaded files back to local storage using the revert command:', 'nobloat-s3-offload' ); ?></p>
					<pre><code># Revert all offloaded media back to local
wp nbs3 revert

# Revert specific attachment(s)
wp nbs3 revert 123
wp nbs3 revert 123,456,789

# Revert with limit
wp nbs3 revert --limit=50

# Revert but keep files on S3
wp nbs3 revert --keep-s3

# Preview what would be reverted (no changes made)
wp nbs3 revert --dry-run</code></pre>

					<h3><?php esc_html_e( 'Invalidate Offload Status', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'If files have been deleted from S3 externally (bucket changed, manual deletion, etc.), use the invalidate command to clear offload metadata so WordPress uses local files instead. This does NOT download or delete any files.', 'nobloat-s3-offload' ); ?></p>
					<pre><code># Invalidate specific attachment(s)
wp nbs3 invalidate 123
wp nbs3 invalidate 123,456,789

# Invalidate all offloaded attachments
wp nbs3 invalidate --all

# Invalidate Bricks CSS and theme assets sync status
wp nbs3 invalidate --bricks

# Invalidate all attachments AND Bricks sync status
wp nbs3 invalidate --all --bricks

# Invalidate with limit
wp nbs3 invalidate --all --limit=50

# Preview what would be invalidated (no changes made)
wp nbs3 invalidate --all --dry-run</code></pre>
				</div>
			</div>

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Bricks Tab', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<p><?php esc_html_e( 'The Bricks tab provides integration with Bricks Builder. When Bricks is active, you can automatically sync Bricks files to S3 and serve them from your CDN. Two types of files can be synced:', 'nobloat-s3-offload' ); ?></p>

					<?php if ( ! nbs3_is_bricks_active() ) : ?>
					<div class="notice notice-info inline" style="margin: 15px 0;">
						<p><?php esc_html_e( 'Bricks Builder is not currently active. Install and activate Bricks to use this feature.', 'nobloat-s3-offload' ); ?></p>
					</div>
					<?php endif; ?>

					<h3><?php esc_html_e( 'Bricks CSS Sync', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'Syncs dynamically generated CSS files from /uploads/bricks/css/ (per-page/template styles).', 'nobloat-s3-offload' ); ?></p>
					<ol>
						<li><?php esc_html_e( 'Go to the Bricks tab and enable "Sync Bricks CSS to S3"', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'When Bricks generates CSS files, they are automatically uploaded to S3', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'CSS URLs are rewritten to serve from your CDN or S3 bucket', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'A background cron job syncs and cleans up deleted files every 5 minutes', 'nobloat-s3-offload' ); ?></li>
					</ol>

					<h3><?php esc_html_e( 'Bricks Theme Assets Sync', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'Syncs static theme assets from /themes/bricks/assets/ (CSS, JS, fonts, images).', 'nobloat-s3-offload' ); ?></p>
					<ol>
						<li><?php esc_html_e( 'Go to the Bricks tab and enable "Sync Bricks Theme Assets to S3"', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Click "Sync Now" to upload all theme assets to S3', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Theme asset URLs are rewritten to serve from your CDN or S3 bucket', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Assets automatically re-sync when any plugin or theme is updated', 'nobloat-s3-offload' ); ?></li>
					</ol>

					<h3><?php esc_html_e( 'Requirements', 'nobloat-s3-offload' ); ?></h3>
					<ul>
						<li><?php esc_html_e( 'Bricks Builder theme must be active', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'S3 connection must be configured and working (CDN is optional - URLs will fall back to direct S3 bucket URL)', 'nobloat-s3-offload' ); ?></li>
					</ul>

					<h3><?php esc_html_e( 'WP-CLI Commands', 'nobloat-s3-offload' ); ?></h3>
					<pre><code># Sync all Bricks CSS files and theme assets (if enabled)
wp nbs3 sync-bricks

# Show sync status for CSS and theme assets
wp nbs3 sync-bricks --status

# Only sync generated CSS files (skip theme assets)
wp nbs3 sync-bricks --css-only

# Only sync theme assets (skip generated CSS)
wp nbs3 sync-bricks --assets-only

# Revert: Remove all Bricks files from S3 (serve locally again)
wp nbs3 sync-bricks --revert
wp nbs3 sync-bricks --remove

# Verbose output
wp nbs3 sync-bricks --verbose</code></pre>
				</div>
			</div>

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'S3-Compatible Providers', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<p><?php esc_html_e( 'This plugin works with any S3-compatible storage provider. Here are some popular options:', 'nobloat-s3-offload' ); ?></p>

					<table class="nbs3-docs-table">
						<tr>
							<th><?php esc_html_e( 'Provider', 'nobloat-s3-offload' ); ?></th>
							<th><?php esc_html_e( 'Endpoint Format', 'nobloat-s3-offload' ); ?></th>
						</tr>
						<tr>
							<td>Amazon S3</td>
							<td><?php esc_html_e( 'Leave empty (uses default)', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<td>DigitalOcean Spaces</td>
							<td>https://[region].digitaloceanspaces.com</td>
						</tr>
						<tr>
							<td>Cloudflare R2</td>
							<td>https://[account-id].r2.cloudflarestorage.com</td>
						</tr>
						<tr>
							<td>Backblaze B2</td>
							<td>https://s3.[region].backblazeb2.com</td>
						</tr>
						<tr>
							<td>Wasabi</td>
							<td>https://s3.[region].wasabisys.com</td>
						</tr>
						<tr>
							<td>MinIO</td>
							<td><?php esc_html_e( 'Your MinIO server URL', 'nobloat-s3-offload' ); ?></td>
						</tr>
					</table>

					<p><strong><?php esc_html_e( 'Note:', 'nobloat-s3-offload' ); ?></strong> <?php esc_html_e( 'Enable "Path Style Endpoint" if your provider requires it (common with MinIO and some self-hosted solutions).', 'nobloat-s3-offload' ); ?></p>
				</div>
			</div>

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Defining Credentials in wp-config.php', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<p><?php esc_html_e( 'For improved security, you can define credentials as constants in wp-config.php instead of storing them in the database:', 'nobloat-s3-offload' ); ?></p>

					<pre><code>define('NBS3_BUCKET', 'your-bucket-name');
define('NBS3_REGION', 'us-east-1');
define('NBS3_KEY', 'your-access-key-id');
define('NBS3_SECRET', 'your-secret-access-key');

// Optional settings
define('NBS3_ENDPOINT', 'https://custom-endpoint.com');
define('NBS3_DOMAIN', 'https://cdn.yourdomain.com');
define('NBS3_PATH_STYLE_ENDPOINT', true);</code></pre>

					<p><?php esc_html_e( 'When credentials are defined in wp-config.php, the corresponding fields in the settings page will be disabled and show a notice that they are configured via constants.', 'nobloat-s3-offload' ); ?></p>
				</div>
			</div>

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Cron Jobs', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<p><?php esc_html_e( 'The plugin uses WordPress cron for background processing. These jobs run automatically when enabled:', 'nobloat-s3-offload' ); ?></p>

					<table class="nbs3-docs-table">
						<tr>
							<th><?php esc_html_e( 'Job', 'nobloat-s3-offload' ); ?></th>
							<th><?php esc_html_e( 'Frequency', 'nobloat-s3-offload' ); ?></th>
							<th><?php esc_html_e( 'Description', 'nobloat-s3-offload' ); ?></th>
						</tr>
						<tr>
							<td>nbs3_bricks_sync_cron</td>
							<td><?php esc_html_e( 'Every 5 minutes', 'nobloat-s3-offload' ); ?></td>
							<td><?php esc_html_e( 'Syncs Bricks CSS files to S3 and removes deleted files (when Bricks CSS Sync is enabled)', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<td>nbs3_check_stalled_processes</td>
							<td><?php esc_html_e( 'Every 15 minutes', 'nobloat-s3-offload' ); ?></td>
							<td><?php esc_html_e( 'Monitors bulk offload processes and restarts stalled jobs', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<td>nbs3_sync_bricks_theme_assets_async</td>
							<td><?php esc_html_e( 'On-demand', 'nobloat-s3-offload' ); ?></td>
							<td><?php esc_html_e( 'Triggered when any plugin or theme is updated (when Theme Assets Sync is enabled)', 'nobloat-s3-offload' ); ?></td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'Viewing Cron Jobs', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'You can view scheduled cron jobs using WP-CLI:', 'nobloat-s3-offload' ); ?></p>
					<pre><code># List all scheduled cron events
wp cron event list

# List only this plugin's cron events
wp cron event list | grep nbs3</code></pre>

					<h3><?php esc_html_e( 'WordPress Cron Reliability', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'WordPress cron is triggered by site visits. For low-traffic sites, consider setting up a real cron job:', 'nobloat-s3-offload' ); ?></p>
					<pre><code># Add to your server's crontab (runs every 5 minutes)
*/5 * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1</code></pre>
					<p><?php esc_html_e( 'Then disable WordPress\'s built-in cron in wp-config.php:', 'nobloat-s3-offload' ); ?></p>
					<pre><code>define('DISABLE_WP_CRON', true);</code></pre>
				</div>
			</div>

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Troubleshooting', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<h3><?php esc_html_e( 'Connection Fails', 'nobloat-s3-offload' ); ?></h3>
					<ul>
						<li><?php esc_html_e( 'Double-check your Access Key ID and Secret Access Key', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Verify the bucket name and region are correct', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Ensure your IAM user has the necessary S3 permissions', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'For custom endpoints, ensure the URL is correct and accessible', 'nobloat-s3-offload' ); ?></li>
					</ul>

					<h3><?php esc_html_e( 'ACL Errors', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'If you see "AccessControlListNotSupported" errors, your bucket has ACLs disabled. Set Object Permissions to "None (Bucket Policy)" and configure a bucket policy instead.', 'nobloat-s3-offload' ); ?></p>

					<h3><?php esc_html_e( 'Files Not Loading', 'nobloat-s3-offload' ); ?></h3>
					<ul>
						<li><?php esc_html_e( 'Verify your bucket policy allows public read access (or use CloudFront)', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Check that the CDN domain is configured correctly', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Ensure CORS is configured if loading assets cross-origin', 'nobloat-s3-offload' ); ?></li>
					</ul>

					<h3><?php esc_html_e( 'IAM Policy Example', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'Minimum IAM policy for the plugin:', 'nobloat-s3-offload' ); ?></p>
					<pre><code>{
	"Version": "2012-10-17",
	"Statement": [
		{
			"Effect": "Allow",
			"Action": [
				"s3:PutObject",
				"s3:GetObject",
				"s3:DeleteObject",
				"s3:ListBucket"
			],
			"Resource": [
				"arn:aws:s3:::your-bucket-name",
				"arn:aws:s3:::your-bucket-name/*"
			]
		}
	]
}</code></pre>
				</div>
			</div>

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Installing WP-CLI', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<p><?php esc_html_e( 'WP-CLI is the command-line interface for WordPress. It allows you to run plugin commands directly from your server terminal, which is faster and more reliable for bulk operations.', 'nobloat-s3-offload' ); ?></p>

					<h3><?php esc_html_e( 'Installation', 'nobloat-s3-offload' ); ?></h3>
					<pre><code># Download WP-CLI
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar

# Make it executable
chmod +x wp-cli.phar

# Move to a directory in your PATH
sudo mv wp-cli.phar /usr/local/bin/wp

# Verify installation
wp --info</code></pre>

					<h3><?php esc_html_e( 'Usage', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'Run commands from your WordPress root directory (where wp-config.php is located):', 'nobloat-s3-offload' ); ?></p>
					<pre><code>cd /path/to/wordpress
wp nbs3 offload --limit=100</code></pre>

					<p><?php esc_html_e( 'For more information, visit:', 'nobloat-s3-offload' ); ?> <a href="https://wp-cli.org/#installing" target="_blank" rel="noopener">https://wp-cli.org/#installing</a></p>
				</div>
			</div>

		</div>
	</div>
</div>
