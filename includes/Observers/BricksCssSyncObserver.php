<?php

namespace NBS3\Observers;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Services\BricksCssSyncService;

/**
 * Observer for syncing Bricks CSS files to S3 immediately when generated.
 */
class BricksCssSyncObserver implements ObserverInterface
{
    private ?BricksCssSyncService $syncService = null;
    private S3Provider $s3Provider;

    public function __construct(S3Provider $s3Provider)
    {
        $this->s3Provider = $s3Provider;
    }

    public function register(): void
    {
        // Only register if Bricks is active and sync is enabled
        if (!nbs3_is_bricks_active()) {
            return;
        }

        // Hook into Bricks CSS file generation
        add_action('bricks/generate_css_file', [$this, 'onCssFileGenerated'], 10, 2);
    }

    /**
     * Called when Bricks generates a CSS file.
     *
     * @param string $type File type: 'global-color-palettes', 'global-elements', 'theme-styles', 'global-custom-css', 'post'
     * @param string $file_name The generated CSS file name
     */
    public function onCssFileGenerated(string $type, string $file_name): void
    {
        // Check if Bricks CSS sync is enabled
        if (!nbs3_get_setting('sync_bricks_css', false)) {
            return;
        }

        // Get the sync service
        $syncService = $this->getSyncService();

        // Sync the file to S3
        $result = $syncService->syncFile($file_name);

        if ($result) {
            error_log("NBS3: Synced Bricks CSS file: {$file_name} (type: {$type})");
        }
    }

    /**
     * Get or create the sync service instance.
     */
    private function getSyncService(): BricksCssSyncService
    {
        if ($this->syncService === null) {
            $this->syncService = new BricksCssSyncService($this->s3Provider);
        }
        return $this->syncService;
    }
}
