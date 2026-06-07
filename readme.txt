=== Nobloat S3 Offload ===
Contributors: mailborder
Donate Link: https://donate.stripe.com/3cIfZi81NbxX9CX4uybfO01
Tags: s3, media, offload, cdn, aws
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.1.1
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
* **Media Library Integration** - View offload status directly in the Media Library list view
* **Attachment Management** - Offload, remove, or manage individual files from the attachment edit screen
* **File Versioning** - Automatic timestamp-based versioning prevents overwrites and ensures cache busting
* **Collision Safety** - Local files preserved when potential S3 collisions detected
* **Configurable ACL** - Choose between bucket policy (recommended), public-read, or private
* **Flexible Retention** - Keep local files, smart cleanup, or full cloud migration
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
* WordPress 6.2 or higher
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

= 1.1.1 =
* Security: SSRF protection on the configured S3 endpoint is now enforced at connection time, not just at validation time. The endpoint host is resolved during validation and the connection is pinned to those validated IP(s) via cURL `CURLOPT_RESOLVE`, so the AWS SDK cannot re-resolve DNS to a reserved address (cloud-metadata IMDS, loopback, link-local) after validation passes. This closes the DNS-rebinding / TOCTOU window left open in 1.1.0. (Pinning requires the cURL HTTP handler; without it the point-in-time validation still applies.)
* Security: Invalid S3 region strings are now rejected at client-build time and surfaced as an error instead of being silently coerced to `us-east-1`, which previously masked misconfiguration as opaque connection failures.
* Security: `check_connection_ajax` no longer returns a truncated copy of the raw S3 SDK exception message to the admin UI in its fallback branch. Full detail is written to `error_log()`; the client receives a generic message. Closes the residual error-text leak from 1.1.0.
* Security: The vendored WP_Background_Process `maybe_handle()` now requires a capability (`manage_options` by default, filterable via `{identifier}_capability`) in addition to the existing `is_user_logged_in()` re-check, so a logged-in low-privilege user cannot drive the offload queue even if they obtain the dispatch nonce.
* Bug fix: Removing media from S3 (single-attachment and remove-all AJAX paths) now preserves the attachment's `nbs3_path` and offload metadata when any individual object delete fails. `delete_attachment()` aggregates every per-object result instead of always reporting success, so a partial failure leaves the key prefix intact for a retry rather than orphaning the remaining S3 objects.
* Hardening: `nbs3_sanitize_path()` reduced to a single authoritative character whitelist (decode-then-whitelist), removing the redundant traversal blacklist passes that masked the real control.

= 1.1.0 =
* Security: Validate the configured S3 endpoint at save and at client-build time. Rejects URLs whose host resolves to a reserved IP range (cloud-metadata IMDS at 169.254.169.254, loopback, link-local, multicast, etc). RFC1918 private ranges are still accepted so MinIO and similar self-hosted services keep working.
* Security: Validate region strings to alphanumerics and dashes only — region values are concatenated into URLs and request signatures, so they cannot contain hostname-injection characters.
* Security: Disable autoload on `nbs3_credentials` and `nbs3_settings`. Existing installs are migrated automatically on upgrade. Prior behaviour pulled the AWS secret access key into memory on every request that called `wp_load_alloptions()`.
* Security: `check_connection_ajax` now requires `manage_options` (was nonce-only). Aligns with every other AJAX handler in the same class.
* Security: Per-attachment AJAX handlers (offload/remove/invalidate single) now require `edit_post` for the specific attachment (was global `upload_files`, allowing one Author to act on another Author's media).
* Security: CSV formula injection protection — exported error CSVs now prefix any cell starting with `=`, `+`, `-`, `@`, tab, or carriage return with a single quote. Prevents an attacker who can edit attachment titles from running formulas in the admin's spreadsheet client.
* Security: Removed `wp_ajax_nopriv_*` registration from the vendored WP_Background_Process library — the historical CVE class for this library was unauthenticated background-task triggering. `is_user_logged_in()` is now also re-checked inside `maybe_handle()`.
* Security: WP-CLI `wp nbs3 revert` now realpath-validates the local destination against the uploads basedir. Refuses to write outside the uploads tree (the path is reconstructed from `_wp_attached_file` post meta which an admin or a compromised lower-privileged role can mutate).
* Security: `wp nbs3 revert` only deletes from S3 when EVERY local download succeeded. A single failed thumbnail no longer leaves the site with neither the local nor the cloud copy.
* Security: Bricks CSS / theme-asset URL rewrites are now anchored to the site's host. Previously the regex `[^"\']*` prefix could match an attacker-controlled domain ending in `/uploads/bricks/css/...`, turning the rewrite into a same-origin URL takeover.
* Security: Bricks theme-assets sync now rejects symlinks and applies an extension allowlist (CSS/JS/images/fonts/manifests/media/pdf only). Prevents `.env`, `.php`, dotfiles, and arbitrary symlinked targets from being uploaded to S3.
* Security: Raw S3 SDK exception messages are no longer returned to the admin UI. Connection-test, single-attachment AJAX, sync-all, and remove-all paths log full details to `error_log()` and return generic localised messages to the client.
* Bug fix: WP-CLI `wp nbs3 sync-bricks --remove` was silently broken — the CLI command called `removeAllFromS3()` but the underlying services define `remove_all_from_s3()`. Both destructive flag handlers were dead code.
* Bug fix: Object-version generation no longer writes post meta from inside read-path filters (`wp_calculate_image_srcset_meta`, `image_downsize`, `wp_get_attachment_url`, `wp_calculate_image_srcset`). Two concurrent visitors could previously race the meta write and end up referencing different S3 keys; only the winner's URL pointed at an actual S3 object. Versions are now persisted at upload time only.
* Bug fix: Missing source files (Modern Image Formats `.webp` etc) declared in metadata but absent on disk are now a hard upload failure rather than a silent skip. Previously this could leave an attachment marked "offloaded" while the variant was never PUT, then retention=2 would delete the local copy and produce a permanent 404.
* Bug fix: `S3Provider::get_domain()` no longer falls back to the raw bucket URL when no CDN is configured. Front-end URL-rewrite observers now correctly preserve the local URL in that state. Previously the bucket name leaked into every page on the site.
* Bug fix: Per-attachment lock added to `upload_attachment()` to prevent two concurrent triggers from both passing the `is_offloaded()` check, both PUTting to S3 with divergent timestamps, and orphaning S3 objects.
* Bug fix: `force_unlock_process()` and `cleanup_orphaned_queue()` now honour an in-flight cancel signal. The 15-minute stalled-process recovery cron used to wipe `nbs3_bulk_offload_cancelled` and re-dispatch, silently undoing a user's cancel.
* Bug fix: Retention sentinel meta (`nbs3_retention_policy_started`) is now written before any local-file deletion begins, so partial-failure (PHP fatal/timeout/perm error mid-loop) leaves recoverable state.
* Bug fix: Post-content image tag rewriting now uses `WP_HTML_Tag_Processor` (WP 6.2+) instead of regex+`str_replace`. Previously the str_replace could mangle alt text or data-* attributes when the attachment URL substring also appeared there.
* Hardening: Distinct nonces for `save_general_settings` vs. `toggle_plugin_status` (the toggle endpoint reused the settings-save nonce action).
* Hardening: `nbs3_get_admin_page_url()` and `nbs3_generate_menu_item()` in the navmenu template are now wrapped in `function_exists` guards.

= 1.0.9 =
* Fixed: WP 6.7+ "Translation loading was triggered too early" notice. Plugin bootstrap deferred from file-load to the `plugins_loaded` action so class instantiation and observer registration happen after WordPress has reached the safe translation-loading window. Activation/deactivation hook registration remains at file-load (required for the activation lifecycle).

= 1.0.8 =
* Improved collision handling - WordPress now handles all filename decisions
* File versioning enabled by default to prevent S3 overwrites
* Added safety valve: local files preserved on potential S3 collisions when versioning is disabled
* Fixed path issue with files in uploads root directory (SignatureDoesNotMatch errors)
* Removed filename modification logic that could cause media library mismatches
* Added helper text explaining collision safety behavior in settings

= 1.0.7 =
* Added S3 offload status column to Media Library list view
* Added S3 status meta box on attachment edit pages
* Single attachment offload, remove, and invalidate actions from edit screen
* Improved AJAX handling with retry logic for large operations
* Reduced batch size for better reliability on shared hosting
* Better error messages with actual S3 error details

= 1.0.6 =
* Added settings caching for improved bulk operation performance
* Enhanced error logging with actual S3 error messages and file paths
* Added file existence and readability checks before upload attempts
* Improved debugging information for failed uploads

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

= 1.1.1 =
Security follow-up to 1.1.0. Enforces S3 endpoint SSRF protection at connection time (closes the DNS-rebinding window), rejects invalid regions, stops leaking raw SDK error text to the admin UI, adds a capability check to background-process dispatch, and preserves offload metadata on partial S3-delete failure. Recommended for all users on 1.1.0.

= 1.1.0 =
Security and correctness release. Closes 11 HIGH and 5 MED-severity issues found by full forensic audit, including SSRF protection on the configured S3 endpoint, AWS-secret autoload removal, CSV formula injection, vendored WP_Background_Process nopriv removal, WP-CLI revert path containment, Bricks URL-rewrite host anchoring, theme-asset symlink/extension allowlist, missing-source hard fail, retention-deletion safety, and concurrency-race fixes for object versioning and per-attachment uploads. Recommended for all users.

= 1.0.9 =
Resolves the "_load_textdomain_just_in_time was called incorrectly" notice on WordPress 6.7+. No functional changes.

= 1.0.8 =
Improved collision handling and file versioning. Recommended update for all users.

= 1.0.7 =
Added Media Library status column and attachment edit meta box for easier management.

= 1.0.6 =
Performance improvements and better error logging.

= 1.0.5 =
Initial release.

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
