# Nobloat S3 Offload - Development Session Summary

## Plugin Overview
WordPress plugin for offloading media files to Amazon S3 or S3-compatible storage providers (DigitalOcean Spaces, Cloudflare R2, MinIO, Wasabi, Backblaze B2). Includes Bricks Builder integration for syncing CSS and theme assets.

**Version:** 1.0.5
**License:** GPL v3 or later
**Author:** Jerry Benton (Mailborder Systems)

## Key Features Implemented

### Core Functionality
- Automatic media offloading to S3 on upload
- Bulk offload with background processing
- Revert command to download files back from S3
- Configurable retention policies (retain local, smart cleanup, full cloud migration)
- Mirror delete (sync deletions to S3)
- File versioning for CDN cache busting
- Custom path prefix support

### Bricks Builder Integration
- **CSS Sync**: Auto-syncs generated CSS from `/uploads/bricks/css/`
- **Theme Assets Sync**: Syncs static assets from `/themes/bricks/assets/`
- URL rewriting via output buffering (Bricks bypasses wp_enqueue_style)
- Auto-resync on plugin/theme updates
- 5-minute cron job for CSS sync/cleanup

### CDN Support
- CloudFront or custom CDN domain
- **Fallback**: If no CDN configured, URLs fall back to direct S3 bucket URL
- CORS configuration guidance in documentation

### WP-CLI Commands
```bash
wp nbs3 offload [ids] --limit=<n> --skip-failed
wp nbs3 revert [ids] --limit=<n> --keep-s3 --dry-run
wp nbs3 sync-bricks --status --css-only --assets-only --revert --verbose
```

## Architecture

### Directory Structure
```
nobloat-s3-offload/
├── nobloat-s3-offload.php    # Main plugin file
├── includes/
│   ├── Admin/                # Settings pages, admin UI
│   ├── CLI/                  # WP-CLI commands
│   ├── Observers/            # WordPress hook handlers
│   ├── Services/             # Business logic (sync services, uploader)
│   ├── Traits/               # Shared functionality
│   ├── Interfaces/           # Observer interface
│   ├── Abstracts/            # WP_Background_Processing library
│   ├── Core/                 # DI container
│   ├── S3Provider.php        # AWS SDK wrapper
│   ├── Offloader.php         # Observer orchestration
│   └── utility-functions.php # Helper functions
├── templates/admin/          # Admin page templates
├── assets/                   # CSS and JS
├── vendor/                   # Scoped dependencies (NBS3Vendor namespace)
├── readme.txt                # WordPress.org readme
├── LICENSE                   # GPL v3
└── license.txt               # GPL v3
```

### Key Design Decisions
1. **PHP-Scoper**: All vendor dependencies prefixed with `NBS3Vendor\` to avoid conflicts
2. **Observer Pattern**: Hooks managed via observer classes attached to Offloader
3. **Singleton Pattern**: Used for settings and admin classes with clone/wakeup protection
4. **Background Processing**: WP_Background_Process library for bulk operations
5. **Transients for Progress**: Cross-process progress tracking (Options API caching issues)

## Technical Notes

### PHP-Scoper Patchers (scoper.inc.php)
Two critical patchers required:
1. **AwsClient.php parseClass()**: Fixes exception class name resolution
2. **SignatureV4.php**: Restores date format `'Ymd\THis\Z'` (scoper corrupts backslashes)

### Build Commands
```bash
# Full scoped build (use 512MB memory for php-scoper)
rm -rf vendor composer.lock
composer install --no-dev --optimize-autoloader --prefer-dist
rm -rf build
php -d memory_limit=512M ~/.composer/vendor/bin/php-scoper add-prefix
rm -rf vendor
mv build/vendor vendor
rm -rf build
composer dump-autoload --classmap-authoritative

# Create distribution package
./create-plugin-package.sh -d nobloat-s3-offload
```

### Files Excluded from Distribution
- composer.json, composer.lock
- scoper.inc.php
- .phpcs.xml, .editorconfig
- README.md, .claude/, .git/

## Bug Fixes This Session

1. **Empty CDN domain fallback**: `S3Provider::getDomain()` now auto-generates S3 bucket URL when CDN field empty
2. **Bricks revert command**: Fixed to remove ALL synced files regardless of current setting state
3. **Removed duplicate vendor directories**: Cleaned up 16 leftover directories from failed builds
4. **Removed unused `detach()` method**: Dead code in Offloader.php

## Admin Pages
1. **General Settings** - S3 credentials, offload settings, Bricks sync toggles
2. **Media Overview** - Offload status, bulk offload controls
3. **AWS Guide** - S3 bucket setup, CloudFront setup, IAM policies, CORS config
4. **Documentation** - All settings explained, WP-CLI commands, troubleshooting
5. **About** - Plugin info, no-bloat philosophy, donate link

## Cron Jobs
| Job | Frequency | Purpose |
|-----|-----------|---------|
| nbs3_bricks_sync_cron | 5 min | Bricks CSS sync/cleanup |
| nbs3_check_stalled_processes | 15 min | Bulk offload monitoring |
| nbs3_sync_bricks_theme_assets_async | On-demand | Theme asset sync on updates |

## Security Measures
- Nonce verification on all AJAX handlers
- Capability checks (`manage_options`)
- Input sanitization (sanitize_text_field, nbs3_sanitize_path)
- SQL injection protection (prepared statements)
- XSS prevention (esc_html, textContent in JS)
- Path traversal protection in file operations

## Testing Notes
- Test connection button validates S3 credentials
- Bulk offload progress uses transients (not options) for real-time updates
- CloudFront may cache 503 errors - invalidate cache after uploading files
- Bricks URL rewriting requires output buffering (Bricks doesn't use wp_enqueue_style)

## WordPress.org Compliance
- All PHPCS issues resolved (60+ fixes)
- Translators comments properly formatted
- gmdate() instead of date()
- Direct DB queries have phpcs:ignore with justification
- No premium upsells or feature gates
