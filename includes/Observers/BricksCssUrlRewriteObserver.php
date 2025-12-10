<?php

namespace NBS3\Observers;

use NBS3\Interfaces\ObserverInterface;

/**
 * Observer for rewriting Bricks CSS URLs to CDN.
 */
class BricksCssUrlRewriteObserver implements ObserverInterface
{
    private ?array $syncedFilesCache = null;

    public function register(): void
    {
        // Only register if Bricks is active
        if (!nbs3_is_bricks_active()) {
            return;
        }

        // Rewrite stylesheet URLs
        add_filter('style_loader_src', [$this, 'rewriteStyleUrl'], 10, 2);
    }

    /**
     * Rewrite Bricks CSS URLs to CDN if synced.
     *
     * @param string $src The stylesheet URL
     * @param string $handle The stylesheet handle
     * @return string The potentially modified URL
     */
    public function rewriteStyleUrl(string $src, string $handle): string
    {
        // Check if Bricks CSS sync is enabled
        if (!nbs3_get_setting('sync_bricks_css', false)) {
            return $src;
        }

        // Only process Bricks CSS files
        if (strpos($src, '/wp-content/uploads/bricks/css/') === false) {
            return $src;
        }

        // Get CDN domain
        $cdn_domain = nbs3_get_credential('domain');
        if (empty($cdn_domain)) {
            return $src;
        }

        // Extract filename from URL (without query string)
        $url_path = parse_url($src, PHP_URL_PATH);
        if (!$url_path) {
            return $src;
        }
        $file_name = basename($url_path);

        // Check if file is synced
        if (!$this->isFileSynced($file_name)) {
            return $src;
        }

        // Build the CDN URL
        $cdn_domain = trailingslashit(nbs3_normalize_url($cdn_domain));

        // Replace local URL with CDN URL
        $new_src = str_replace(
            content_url('/uploads/bricks/css/'),
            $cdn_domain . 'uploads/bricks/css/',
            $src
        );

        return $new_src;
    }

    /**
     * Check if a file is in the synced files list.
     */
    private function isFileSynced(string $file_name): bool
    {
        // Cache the synced files for this request
        if ($this->syncedFilesCache === null) {
            $this->syncedFilesCache = get_option('nbs3_synced_bricks_files', []);
        }

        return isset($this->syncedFilesCache[$file_name]);
    }
}
