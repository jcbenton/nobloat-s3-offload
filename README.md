# Nobloat S3 Offload

Lightweight S3 media offloader for WordPress. Offload media to any S3-compatible storage with CDN support, Bricks Builder integration, and full WP-CLI commands.

## Features

- **Automatic Media Offloading** - New uploads are automatically sent to S3 and served via CDN
- **Bulk Offload** - Migrate your existing media library to S3 in batches
- **Revert to Local** - Download files back from S3 and restore local serving
- **S3-Compatible** - Works with AWS S3, DigitalOcean Spaces, Cloudflare R2, MinIO, Wasabi, Backblaze B2, and more
- **CDN Support** - Serve media through CloudFront or any custom CDN domain
- **Configurable ACL** - Choose between bucket policy (recommended), public-read, or private
- **Bricks Builder Integration** - Sync generated CSS files and theme assets to S3/CDN
- **WP-CLI Support** - Full command-line tools for offloading, reverting, and Bricks sync
- **Secure Credentials** - Store credentials in wp-config.php instead of the database

## Requirements

- PHP 8.1 or higher
- WordPress 5.6 or higher
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

GPL v2 or later
