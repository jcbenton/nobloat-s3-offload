=== Nobloat S3 Offload ===
Contributors: mailborder
Donate Link: https://donate.stripe.com/3cIfZi81NbxX9CX4uybfO01
Tags: s3, media, offload, cdn, aws
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.7
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Offload WordPress media to S3-compatible storage with CDN support, Bricks Builder integration, and WP-CLI commands.

== Description ==

Nobloat S3 Offload is a lightweight, no-bloat solution for offloading your WordPress media library to Amazon S3 or any S3-compatible storage provider (DigitalOcean Spaces, Cloudflare R2, MinIO, Wasabi, Backblaze B2, etc.).

Written by Jerry Benton, the creator of Mailborder and MailScanner v5.

= Features =

* **Automatic Media Offloading** - New uploads are automatically sent to S3 and served via CDN
* **Bulk Offload** - Migrate your existing media library to S3 in batches
* **Revert to Local** - Download files back from S3 and restore local serving
* **S3-Compatible** - Works with AWS S3, DigitalOcean Spaces, Cloudflare R2, MinIO, Wasabi, Backblaze B2, and more
* **CDN Support** - Serve media through CloudFront or any custom CDN domain
* **Configurable ACL** - Choose between bucket policy (recommended), public-read, or private
* **Bricks Builder Integration** - Sync generated CSS files and theme assets to S3/CDN
* **WP-CLI Support** - Full command-line tools for offloading, reverting, and Bricks sync
* **wp-config.php Credentials** - Store credentials securely as constants instead of in the database

= Bricks Builder Integration =

If you use Bricks Builder, enable automatic sync to serve your Bricks files from your CDN:

**Generated CSS Sync:**

* Automatic sync when Bricks generates or regenerates CSS
* Syncs global styles, color palettes, theme styles, and page-specific CSS
* Automatic URL rewriting to CDN domain

**Theme Assets Sync:**

* Upload Bricks theme static assets (CSS, JS, fonts, images) to S3
* Auto-syncs when any plugin or theme is updated
* Serves assets from CDN for improved performance

= Requirements =

* PHP 8.1 or higher
* WordPress 5.6 or higher
* An S3-compatible storage bucket

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/nobloat-s3-offload/` or install via the WordPress plugin screen
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to Settings > NBS3 Offload to configure your S3 credentials
4. Enter your bucket name, region, access key, and secret key
5. Optionally configure a CDN domain for URL rewriting
6. Test your connection and start offloading

= Securing Credentials =

For better security, you can define credentials in wp-config.php instead of the database:

`define('NBS3_BUCKET', 'your-bucket-name');
define('NBS3_REGION', 'us-east-1');
define('NBS3_KEY', 'your-access-key');
define('NBS3_SECRET', 'your-secret-key');
define('NBS3_DOMAIN', 'cdn.yourdomain.com');
define('NBS3_ENDPOINT', 'https://s3.us-east-1.amazonaws.com');`

== Frequently Asked Questions ==

= What S3-compatible services are supported? =

The plugin works with any S3-compatible storage:

* Amazon S3
* DigitalOcean Spaces
* Cloudflare R2
* MinIO
* Wasabi
* Backblaze B2 (with S3 compatibility)
* And more...

= Do I need to use ACLs? =

No. Modern S3 buckets often have "Bucket Owner Enforced" which disables ACLs. The plugin defaults to "None" which relies on your bucket policy for access control. This is the recommended approach.

= Will my existing media URLs break? =

The plugin rewrites URLs automatically. If you disable the plugin or remove media from S3, the original local files (if kept) will be served instead.

= Can I revert back to local storage? =

Yes! Use the WP-CLI revert command or the admin interface to download files from S3 back to local storage.

= Does this work with page builders? =

Yes! The plugin has specific integration for Bricks Builder to sync CSS files and theme assets. For other page builders, standard media offloading works normally.

= What happens if S3 is unavailable? =

If media cannot be uploaded to S3, the local file is preserved and WordPress will use it as a fallback.

== Screenshots ==

1. Settings page - Configure your S3 credentials and options
2. Media overview - View offload status of your media library
3. Bulk offload - Migrate existing media to S3

== Changelog ==

= 1.0.7 =
* Activation hook update
* Defensive path traversal check added

= 1.0.6 =
* Composer fix for vendor directory

= 1.0.5 =
* Initial release
* Media offloading to S3-compatible storage
* Bulk offload functionality
* Revert from S3 back to local storage
* CDN URL rewriting
* Configurable ACL settings (None/Public Read/Private)
* Bricks Builder CSS sync integration
* Bricks theme assets sync
* WP-CLI commands for offload, revert, and Bricks sync
* wp-config.php credential support

== Upgrade Notice ==

= 1.0.7 =
No admin action required after upgrading the plugin. 

== WP-CLI Commands ==

= Media Offloading =

Offload media attachments to S3 storage.

`wp nbs3 offload`

**Options:**

* `[<attachment_ids>]` - Specific attachment ID(s) to offload (single ID or comma-separated)
* `[--limit=<number>]` - Maximum number of attachments to process
* `[--skip-failed]` - Skip attachments that have previously failed

**Examples:**

`# Offload all unoffloaded media
wp nbs3 offload

# Offload specific attachments
wp nbs3 offload 123,456,789

# Offload up to 100 attachments
wp nbs3 offload --limit=100

# Skip previously failed attachments
wp nbs3 offload --skip-failed`

= Revert to Local Storage =

Download files from S3 back to local storage and remove offload metadata.

`wp nbs3 revert`

**Options:**

* `[<attachment_ids>]` - Specific attachment ID(s) to revert (single ID or comma-separated)
* `[--limit=<number>]` - Maximum number of attachments to process
* `[--keep-s3]` - Keep files on S3 after downloading (don't delete from cloud)
* `[--dry-run]` - Preview what would be reverted without making changes

**Examples:**

`# Revert all offloaded media
wp nbs3 revert

# Revert specific attachments
wp nbs3 revert 123,456,789

# Revert but keep copies on S3
wp nbs3 revert --keep-s3

# Preview what would be reverted
wp nbs3 revert --dry-run`

= Bricks Builder Sync =

Sync Bricks CSS files and theme assets to S3.

`wp nbs3 sync-bricks`

**Options:**

* `[--status]` - Show sync status without syncing
* `[--remove]` - Remove all Bricks files from S3
* `[--css-only]` - Only sync generated CSS files (skip theme assets)
* `[--assets-only]` - Only sync theme assets (skip generated CSS)
* `[--verbose]` - Show detailed output

**Examples:**

`# Sync all Bricks files
wp nbs3 sync-bricks

# Show sync status
wp nbs3 sync-bricks --status

# Only sync generated CSS
wp nbs3 sync-bricks --css-only

# Only sync theme assets
wp nbs3 sync-bricks --assets-only

# Remove all Bricks files from S3
wp nbs3 sync-bricks --remove

# Verbose output
wp nbs3 sync-bricks --verbose`
