<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    return;
}
?>
<div id="nbs3">
    <div class="wrap">
        <div class="nbs3-documentation">

            <div class="nbs3-section">
                <div class="nbs3-section-header">
                    <h2><?php esc_html_e('Getting Started', 'nobloat-s3-offload'); ?></h2>
                </div>
                <div class="nbs3-section-content">
                    <p><?php esc_html_e('Nobloat S3 Offload allows you to offload your WordPress media files to Amazon S3 or any S3-compatible storage provider, reducing server disk usage and enabling CDN delivery for faster page loads.', 'nobloat-s3-offload'); ?></p>

                    <h3><?php esc_html_e('Quick Setup', 'nobloat-s3-offload'); ?></h3>
                    <ol>
                        <li><?php esc_html_e('Create an S3 bucket (or use an existing one)', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('Create IAM credentials with S3 access permissions', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('Enter your credentials in the General Settings page', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('Test the connection to verify everything works', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('Enable auto-offload to start uploading new media automatically', 'nobloat-s3-offload'); ?></li>
                    </ol>
                </div>
            </div>

            <div class="nbs3-section">
                <div class="nbs3-section-header">
                    <h2><?php esc_html_e('S3 Connection Settings', 'nobloat-s3-offload'); ?></h2>
                </div>
                <div class="nbs3-section-content">
                    <table class="nbs3-docs-table">
                        <tr>
                            <th><?php esc_html_e('Bucket', 'nobloat-s3-offload'); ?></th>
                            <td><?php esc_html_e('The name of your S3 bucket where files will be stored.', 'nobloat-s3-offload'); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Region', 'nobloat-s3-offload'); ?></th>
                            <td><?php esc_html_e('The AWS region where your bucket is located (e.g., us-east-1, eu-west-1).', 'nobloat-s3-offload'); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Access Key ID', 'nobloat-s3-offload'); ?></th>
                            <td><?php esc_html_e('Your AWS IAM access key ID with permissions to read/write to the bucket.', 'nobloat-s3-offload'); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Secret Access Key', 'nobloat-s3-offload'); ?></th>
                            <td><?php esc_html_e('Your AWS IAM secret access key (keep this secure!).', 'nobloat-s3-offload'); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Custom Endpoint', 'nobloat-s3-offload'); ?></th>
                            <td><?php esc_html_e('Optional. For S3-compatible providers like DigitalOcean Spaces, Cloudflare R2, or MinIO. Leave empty for AWS S3.', 'nobloat-s3-offload'); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('CloudFront or Custom Domain (CDN)', 'nobloat-s3-offload'); ?></th>
                            <td><?php esc_html_e('Enter your CloudFront distribution URL or custom CDN domain. When set, media URLs will be rewritten to serve files from this domain instead of your local server.', 'nobloat-s3-offload'); ?></td>
                        </tr>
                    </table>

                    <h3><?php esc_html_e('Object Permissions (ACL)', 'nobloat-s3-offload'); ?></h3>
                    <table class="nbs3-docs-table">
                        <tr>
                            <th><?php esc_html_e('None (Bucket Policy)', 'nobloat-s3-offload'); ?></th>
                            <td><?php esc_html_e('Recommended. No ACL is set on uploaded objects. Access is controlled by your bucket policy. Required for buckets with "Bucket Owner Enforced" ownership (most modern S3 setups).', 'nobloat-s3-offload'); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Public Read (ACL)', 'nobloat-s3-offload'); ?></th>
                            <td><?php esc_html_e('Sets public-read ACL on each uploaded object. Only works if your bucket allows ACLs. Objects are publicly accessible via their S3 URL.', 'nobloat-s3-offload'); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Private (ACL)', 'nobloat-s3-offload'); ?></th>
                            <td><?php esc_html_e('Sets private ACL on each uploaded object. Use when serving files through CloudFront with signed URLs or Origin Access Identity.', 'nobloat-s3-offload'); ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="nbs3-section">
                <div class="nbs3-section-header">
                    <h2><?php esc_html_e('General Settings', 'nobloat-s3-offload'); ?></h2>
                </div>
                <div class="nbs3-section-content">
                    <table class="nbs3-docs-table">
                        <tr>
                            <th><?php esc_html_e('Auto-Offload Media', 'nobloat-s3-offload'); ?></th>
                            <td><?php esc_html_e('When enabled, new media uploads are automatically sent to S3. Disable if you want to manually control which files are offloaded.', 'nobloat-s3-offload'); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Retention Policy', 'nobloat-s3-offload'); ?></th>
                            <td>
                                <strong><?php esc_html_e('Retain Local Files:', 'nobloat-s3-offload'); ?></strong> <?php esc_html_e('Keep all files on your server after offloading.', 'nobloat-s3-offload'); ?><br>
                                <strong><?php esc_html_e('Smart Local Cleanup:', 'nobloat-s3-offload'); ?></strong> <?php esc_html_e('Delete generated sizes but keep the original file.', 'nobloat-s3-offload'); ?><br>
                                <strong><?php esc_html_e('Full Cloud Migration:', 'nobloat-s3-offload'); ?></strong> <?php esc_html_e('Delete all local files after successful upload.', 'nobloat-s3-offload'); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('File Versioning', 'nobloat-s3-offload'); ?></th>
                            <td><?php esc_html_e('Adds a unique timestamp to file paths in S3, ensuring updated files bypass CDN cache. Useful when you frequently replace media files.', 'nobloat-s3-offload'); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Mirror Delete', 'nobloat-s3-offload'); ?></th>
                            <td><?php esc_html_e('When enabled, deleting a media file in WordPress also deletes it from S3. Disable if you want to preserve S3 copies.', 'nobloat-s3-offload'); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Custom Path Prefix', 'nobloat-s3-offload'); ?></th>
                            <td><?php esc_html_e('Add a prefix to organize files in your bucket. Useful for multisite or when sharing a bucket between multiple sites. Example: wp-content/uploads/', 'nobloat-s3-offload'); ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="nbs3-section">
                <div class="nbs3-section-header">
                    <h2><?php esc_html_e('Media Overview', 'nobloat-s3-offload'); ?></h2>
                </div>
                <div class="nbs3-section-content">
                    <p><?php esc_html_e('The Media Overview page shows the offload status of your media library and allows you to bulk offload existing files.', 'nobloat-s3-offload'); ?></p>

                    <h3><?php esc_html_e('Bulk Offload', 'nobloat-s3-offload'); ?></h3>
                    <p><?php esc_html_e('Use the bulk offload feature to migrate your existing media library to S3. The process runs in batches to avoid server timeouts. You can pause and resume at any time.', 'nobloat-s3-offload'); ?></p>

                    <h3><?php esc_html_e('WP-CLI Commands', 'nobloat-s3-offload'); ?></h3>
                    <p><?php esc_html_e('For large media libraries, WP-CLI is recommended:', 'nobloat-s3-offload'); ?></p>
                    <pre><code># Offload all unoffloaded media
wp nbs3 offload

# Offload specific attachment(s)
wp nbs3 offload 123
wp nbs3 offload 123,456,789

# Offload with limit
wp nbs3 offload --limit=100

# Skip previously failed attachments
wp nbs3 offload --skip-failed</code></pre>

                    <h3><?php esc_html_e('Reverting to Local Storage', 'nobloat-s3-offload'); ?></h3>
                    <p><?php esc_html_e('You can download offloaded files back to local storage using the revert command:', 'nobloat-s3-offload'); ?></p>
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
                </div>
            </div>

            <div class="nbs3-section">
                <div class="nbs3-section-header">
                    <h2><?php esc_html_e('Bricks Builder Integration', 'nobloat-s3-offload'); ?></h2>
                </div>
                <div class="nbs3-section-content">
                    <p><?php esc_html_e('When Bricks Builder is active, you can automatically sync Bricks files to S3 and serve them via CDN. Two types of files can be synced:', 'nobloat-s3-offload'); ?></p>

                    <?php if (!nbs3_is_bricks_active()) : ?>
                    <div class="notice notice-info inline" style="margin: 15px 0;">
                        <p><?php esc_html_e('Bricks Builder is not currently active. Install and activate Bricks to use this feature.', 'nobloat-s3-offload'); ?></p>
                    </div>
                    <?php endif; ?>

                    <h3><?php esc_html_e('Bricks CSS Sync', 'nobloat-s3-offload'); ?></h3>
                    <p><?php esc_html_e('Syncs dynamically generated CSS files from /uploads/bricks/css/ (per-page/template styles).', 'nobloat-s3-offload'); ?></p>
                    <ol>
                        <li><?php esc_html_e('Enable "Sync Bricks CSS to S3" in General Settings', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('When Bricks generates CSS files, they are automatically uploaded to S3', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('CSS URLs are rewritten to serve from your CDN domain', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('A background cron job cleans up deleted files every 5 minutes', 'nobloat-s3-offload'); ?></li>
                    </ol>

                    <h3><?php esc_html_e('Bricks Theme Assets Sync', 'nobloat-s3-offload'); ?></h3>
                    <p><?php esc_html_e('Syncs static theme assets from /themes/bricks/assets/ (CSS, JS, fonts, images).', 'nobloat-s3-offload'); ?></p>
                    <ol>
                        <li><?php esc_html_e('Enable "Sync Bricks Theme Assets to S3" in General Settings', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('Click "Sync Now" to upload all theme assets to S3', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('Theme asset URLs are rewritten to serve from your CDN domain', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('Assets automatically re-sync when any plugin or theme is updated', 'nobloat-s3-offload'); ?></li>
                    </ol>

                    <h3><?php esc_html_e('Requirements', 'nobloat-s3-offload'); ?></h3>
                    <ul>
                        <li><?php esc_html_e('Bricks Builder theme must be active', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('A CloudFront or Custom Domain (CDN) must be configured for URL rewriting to work', 'nobloat-s3-offload'); ?></li>
                    </ul>

                    <h3><?php esc_html_e('WP-CLI Commands', 'nobloat-s3-offload'); ?></h3>
                    <pre><code># Sync all Bricks CSS files and theme assets (if enabled)
wp nbs3 sync-bricks

# Show sync status for CSS and theme assets
wp nbs3 sync-bricks --status

# Only sync generated CSS files (skip theme assets)
wp nbs3 sync-bricks --css-only

# Only sync theme assets (skip generated CSS)
wp nbs3 sync-bricks --assets-only

# Remove all Bricks files from S3
wp nbs3 sync-bricks --remove

# Verbose output
wp nbs3 sync-bricks --verbose</code></pre>
                </div>
            </div>

            <div class="nbs3-section">
                <div class="nbs3-section-header">
                    <h2><?php esc_html_e('S3-Compatible Providers', 'nobloat-s3-offload'); ?></h2>
                </div>
                <div class="nbs3-section-content">
                    <p><?php esc_html_e('This plugin works with any S3-compatible storage provider. Here are some popular options:', 'nobloat-s3-offload'); ?></p>

                    <table class="nbs3-docs-table">
                        <tr>
                            <th><?php esc_html_e('Provider', 'nobloat-s3-offload'); ?></th>
                            <th><?php esc_html_e('Endpoint Format', 'nobloat-s3-offload'); ?></th>
                        </tr>
                        <tr>
                            <td>Amazon S3</td>
                            <td><?php esc_html_e('Leave empty (uses default)', 'nobloat-s3-offload'); ?></td>
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
                            <td><?php esc_html_e('Your MinIO server URL', 'nobloat-s3-offload'); ?></td>
                        </tr>
                    </table>

                    <p><strong><?php esc_html_e('Note:', 'nobloat-s3-offload'); ?></strong> <?php esc_html_e('Enable "Path Style Endpoint" if your provider requires it (common with MinIO and some self-hosted solutions).', 'nobloat-s3-offload'); ?></p>
                </div>
            </div>

            <div class="nbs3-section">
                <div class="nbs3-section-header">
                    <h2><?php esc_html_e('Defining Credentials in wp-config.php', 'nobloat-s3-offload'); ?></h2>
                </div>
                <div class="nbs3-section-content">
                    <p><?php esc_html_e('For improved security, you can define credentials as constants in wp-config.php instead of storing them in the database:', 'nobloat-s3-offload'); ?></p>

                    <pre><code>define('NBS3_BUCKET', 'your-bucket-name');
define('NBS3_REGION', 'us-east-1');
define('NBS3_KEY', 'your-access-key-id');
define('NBS3_SECRET', 'your-secret-access-key');

// Optional settings
define('NBS3_ENDPOINT', 'https://custom-endpoint.com');
define('NBS3_DOMAIN', 'https://cdn.yourdomain.com');
define('NBS3_PATH_STYLE_ENDPOINT', true);</code></pre>

                    <p><?php esc_html_e('When credentials are defined in wp-config.php, the corresponding fields in the settings page will be disabled and show a notice that they are configured via constants.', 'nobloat-s3-offload'); ?></p>
                </div>
            </div>

            <div class="nbs3-section">
                <div class="nbs3-section-header">
                    <h2><?php esc_html_e('Troubleshooting', 'nobloat-s3-offload'); ?></h2>
                </div>
                <div class="nbs3-section-content">
                    <h3><?php esc_html_e('Connection Fails', 'nobloat-s3-offload'); ?></h3>
                    <ul>
                        <li><?php esc_html_e('Double-check your Access Key ID and Secret Access Key', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('Verify the bucket name and region are correct', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('Ensure your IAM user has the necessary S3 permissions', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('For custom endpoints, ensure the URL is correct and accessible', 'nobloat-s3-offload'); ?></li>
                    </ul>

                    <h3><?php esc_html_e('ACL Errors', 'nobloat-s3-offload'); ?></h3>
                    <p><?php esc_html_e('If you see "AccessControlListNotSupported" errors, your bucket has ACLs disabled. Set Object Permissions to "None (Bucket Policy)" and configure a bucket policy instead.', 'nobloat-s3-offload'); ?></p>

                    <h3><?php esc_html_e('Files Not Loading', 'nobloat-s3-offload'); ?></h3>
                    <ul>
                        <li><?php esc_html_e('Verify your bucket policy allows public read access (or use CloudFront)', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('Check that the CDN domain is configured correctly', 'nobloat-s3-offload'); ?></li>
                        <li><?php esc_html_e('Ensure CORS is configured if loading assets cross-origin', 'nobloat-s3-offload'); ?></li>
                    </ul>

                    <h3><?php esc_html_e('IAM Policy Example', 'nobloat-s3-offload'); ?></h3>
                    <p><?php esc_html_e('Minimum IAM policy for the plugin:', 'nobloat-s3-offload'); ?></p>
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
                    <h2><?php esc_html_e('Installing WP-CLI', 'nobloat-s3-offload'); ?></h2>
                </div>
                <div class="nbs3-section-content">
                    <p><?php esc_html_e('WP-CLI is the command-line interface for WordPress. It allows you to run plugin commands directly from your server terminal, which is faster and more reliable for bulk operations.', 'nobloat-s3-offload'); ?></p>

                    <h3><?php esc_html_e('Installation', 'nobloat-s3-offload'); ?></h3>
                    <pre><code># Download WP-CLI
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar

# Make it executable
chmod +x wp-cli.phar

# Move to a directory in your PATH
sudo mv wp-cli.phar /usr/local/bin/wp

# Verify installation
wp --info</code></pre>

                    <h3><?php esc_html_e('Usage', 'nobloat-s3-offload'); ?></h3>
                    <p><?php esc_html_e('Run commands from your WordPress root directory (where wp-config.php is located):', 'nobloat-s3-offload'); ?></p>
                    <pre><code>cd /path/to/wordpress
wp nbs3 offload --limit=100</code></pre>

                    <p><?php esc_html_e('For more information, visit:', 'nobloat-s3-offload'); ?> <a href="https://wp-cli.org/#installing" target="_blank" rel="noopener">https://wp-cli.org/#installing</a></p>
                </div>
            </div>

        </div>
    </div>
</div>
