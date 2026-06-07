# Nobloat S3 Offload

Lightweight S3 media offloader for WordPress. Offload media to any S3-compatible storage with CDN support, Bricks Builder integration, and full WP-CLI commands.

**Version:** 1.1.2

## Features

- **Automatic Media Offloading** - New uploads are automatically sent to S3 and served via CDN
- **Bulk Offload** - Migrate your existing media library to S3 in batches
- **Revert to Local** - Download files back from S3 and restore local serving
- **S3-Compatible** - Works with AWS S3, DigitalOcean Spaces, Cloudflare R2, MinIO, Wasabi, Backblaze B2, and more
- **CDN Support** - Serve media through CloudFront or any custom CDN domain
- **Media Library Integration** - View offload status directly in the Media Library list view
- **Attachment Management** - Offload, remove, or manage individual files from the attachment edit screen
- **File Versioning** - Automatic timestamp-based versioning prevents overwrites and ensures cache busting
- **Collision Safety** - Local files preserved when potential S3 collisions detected
- **Configurable ACL** - Choose between bucket policy (recommended), public-read, or private
- **Flexible Retention Policies** - Keep local files, smart cleanup (keep originals), or full cloud migration
- **Bricks Builder Integration** - Sync generated CSS files and theme assets to S3/CDN
- **WP-CLI Support** - Full command-line tools for offloading, reverting, and Bricks sync
- **Secure Credentials** - Store credentials in wp-config.php instead of the database

## Requirements

- PHP 8.1 or higher
- WordPress 6.2 or higher (tested up to WordPress 7.0)
- An S3-compatible storage bucket

## Installation

1. Download the latest release
2. Upload to `/wp-content/plugins/nobloat-s3-offload/`
3. Activate the plugin
4. Navigate to **Settings > NBS3 Offload** to configure

## Configuration

### Admin Settings

Configure your S3 credentials in the WordPress admin under **Settings > NBS3 Offload**:

- Bucket name
- Region
- Access key & secret key
- CDN domain (optional)
- Custom endpoint (for non-AWS providers)

### wp-config.php (Recommended)

For better security, define credentials as constants:

```php
define('NBS3_BUCKET', 'your-bucket-name');
define('NBS3_REGION', 'us-east-1');
define('NBS3_KEY', 'your-access-key');
define('NBS3_SECRET', 'your-secret-key');
define('NBS3_DOMAIN', 'cdn.yourdomain.com');
define('NBS3_ENDPOINT', 'https://s3.us-east-1.amazonaws.com');
```

## Bricks Builder Integration

### Generated CSS Sync

Automatically sync Bricks-generated CSS files to S3:

- Syncs when Bricks generates or regenerates CSS
- Includes global styles, color palettes, theme styles, and page-specific CSS
- Automatic URL rewriting to CDN domain

### Theme Assets Sync

Upload Bricks theme static assets to S3:

- CSS, JS, fonts, and images from the Bricks theme
- Auto-syncs when any plugin or theme is updated
- Serves assets from CDN for improved performance

## WP-CLI Commands

### Offload Media

```bash
# Offload all unoffloaded media
wp nbs3 offload

# Offload specific attachments
wp nbs3 offload 123,456,789

# Offload with limit
wp nbs3 offload --limit=100

# Skip previously failed attachments
wp nbs3 offload --skip-failed
```

### Revert to Local

```bash
# Revert all offloaded media
wp nbs3 revert

# Revert specific attachments
wp nbs3 revert 123,456,789

# Keep copies on S3 after downloading
wp nbs3 revert --keep-s3

# Preview what would be reverted
wp nbs3 revert --dry-run
```

### Bricks Sync

```bash
# Sync all Bricks files
wp nbs3 sync-bricks

# Show sync status
wp nbs3 sync-bricks --status

# Only sync generated CSS
wp nbs3 sync-bricks --css-only

# Only sync theme assets
wp nbs3 sync-bricks --assets-only

# Remove all Bricks files from S3
wp nbs3 sync-bricks --remove
```

## S3-Compatible Providers

Tested with:

- Amazon S3
- DigitalOcean Spaces
- Cloudflare R2
- MinIO
- Wasabi
- Backblaze B2

## Retention Policies

Control what happens to local files after offloading:

- **Retain Local Files** - Keep all files on your local server after offloading (safest option)
- **Smart Local Cleanup** - Remove resized images locally, but keep the original file as a backup
- **Full Cloud Migration** - Remove all local files after successful cloud offloading (maximum space savings)

### Collision Safety

When File Versioning is disabled and you use Full Cloud Migration, the plugin includes a safety mechanism: if a file with the same name already exists in S3, the local copy will be preserved to prevent data loss.

## ACL Settings

The plugin supports three ACL modes:

- **None (Recommended)** - Relies on bucket policy for access control. Works with "Bucket Owner Enforced" settings.
- **Public Read** - Sets `public-read` ACL on uploaded objects
- **Private** - Sets `private` ACL (requires signed URLs or bucket policy for access)

## Hooks & Filters

### Skip offloading for specific attachments

```php
add_filter('nbs3_should_offload_attachment', function($should_offload, $attachment_id) {
    // Skip offloading for specific post types, sizes, etc.
    return $should_offload;
}, 10, 2);
```

## License

GPLv3 or later

## Changelog

See [readme.txt](readme.txt) for full changelog.

### 1.1.2
- Docs: Fixed the wp-config.php constant names in the in-plugin AWS Setup Guide (`NBS3_KEY` / `NBS3_SECRET` / `NBS3_DOMAIN`, not the previously-listed `NBS3_ACCESS_KEY_ID` / `NBS3_SECRET_ACCESS_KEY` / `NBS3_CDN_DOMAIN`).
- Docs: Removed the invalid `s3:HeadObject` action from the IAM policy examples in the AWS Setup Guide and Documentation tabs.

### 1.1.1
- Security: Endpoint SSRF protection is now enforced at connection time. The validated S3 endpoint host is pinned to the IP(s) verified during validation (via cURL `CURLOPT_RESOLVE`), so the AWS SDK cannot re-resolve DNS to a reserved address (cloud IMDS, loopback, link-local) after validation — closing the DNS-rebinding window.
- Security: Invalid S3 region strings are now rejected at client-build time instead of being silently coerced to `us-east-1`.
- Security: The connection-test handler no longer echoes raw S3 SDK error text to the admin UI in its fallback branch; full details go to the error log only.
- Security: Background-process dispatch now requires a capability (`manage_options`, filterable) in addition to a logged-in check.
- Bug fix: Removing media from S3 now preserves the attachment's offload metadata when any object delete fails, so a partial failure no longer orphans the remaining S3 objects.
- Hardening: `nbs3_sanitize_path()` simplified to a single authoritative character whitelist.

### 1.1.0
- Security & correctness release closing 11 HIGH and 5 MED-severity issues found in a full forensic audit: SSRF validation on the configured S3 endpoint, AWS-secret autoload removal, CSV formula-injection protection, vendored WP_Background_Process `nopriv` removal, WP-CLI revert path containment, Bricks URL-rewrite host anchoring, theme-asset symlink/extension allowlist, missing-source hard-fail, retention-deletion safety, and concurrency-race fixes for object versioning and per-attachment uploads. See [readme.txt](readme.txt) for the full list.

### 1.0.9
- Fixed WordPress 6.7+ "Translation loading was triggered too early" notice by deferring bootstrap to the `plugins_loaded` action.

### 1.0.8
- Improved collision handling - WordPress handles all filenames
- File versioning enabled by default
- Safety valve to preserve local files on potential S3 collisions
- Fixed path issues with files in uploads root directory

### 1.0.7
- Media Library offload status column
- Attachment edit page S3 status meta box
- Single attachment management actions
- Improved AJAX handling with retry logic

### 1.0.6
- Settings caching for bulk operations
- Enhanced error logging with S3 error details

### 1.0.5
- Initial release
