<?php

namespace NBS3\CLI;

use NBS3\S3Provider;
use NBS3\Services\BricksCssSyncService;

/**
 * WP CLI command for Bricks CSS sync operations.
 */
class BricksSyncCommand
{
    private ?BricksCssSyncService $syncService = null;

    /**
     * Sync Bricks CSS files to S3.
     *
     * ## OPTIONS
     *
     * [--status]
     * : Show sync status without syncing.
     *
     * [--remove]
     * : Remove all Bricks CSS files from S3.
     *
     * [--verbose]
     * : Show detailed output.
     *
     * ## EXAMPLES
     *
     *     # Sync all Bricks CSS files
     *     wp nbs3 sync-bricks
     *
     *     # Show sync status
     *     wp nbs3 sync-bricks --status
     *
     *     # Remove all Bricks CSS from S3
     *     wp nbs3 sync-bricks --remove
     *
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments (flags).
     */
    public function __invoke($args, $assoc_args)
    {
        // Check if Bricks is active
        if (!nbs3_is_bricks_active()) {
            \WP_CLI::error('Bricks Builder is not active.');
        }

        // Check S3 credentials
        $bucket = nbs3_get_credential('bucket');
        if (empty($bucket)) {
            \WP_CLI::error('No S3 credentials configured. Please configure your S3 settings first.');
        }

        $syncService = $this->getSyncService();

        // Handle --status flag
        if (isset($assoc_args['status'])) {
            $this->showStatus($syncService);
            return;
        }

        // Handle --remove flag
        if (isset($assoc_args['remove'])) {
            $this->removeFromS3($syncService);
            return;
        }

        // Perform sync
        $this->performSync($syncService, isset($assoc_args['verbose']));
    }

    /**
     * Show sync status.
     */
    private function showStatus(BricksCssSyncService $syncService): void
    {
        $status = $syncService->getStatus();

        \WP_CLI::log('Bricks CSS Sync Status:');
        \WP_CLI::log(sprintf('  Total local files: %d', $status['total']));
        \WP_CLI::log(sprintf('  Synced to S3: %d', $status['synced']));
        \WP_CLI::log(sprintf('  Pending sync: %d', $status['pending']));

        $setting_enabled = nbs3_get_setting('sync_bricks_css', false);
        \WP_CLI::log(sprintf('  Auto-sync enabled: %s', $setting_enabled ? 'Yes' : 'No'));
    }

    /**
     * Perform full sync.
     */
    private function performSync(BricksCssSyncService $syncService, bool $verbose): void
    {
        \WP_CLI::log('Starting Bricks CSS sync...');

        $local_files = $syncService->scanLocalFiles();
        $total = count($local_files);

        if ($total === 0) {
            \WP_CLI::success('No Bricks CSS files found to sync.');
            return;
        }

        \WP_CLI::log(sprintf('Found %d local CSS files.', $total));

        $result = $syncService->fullSync();

        if ($verbose) {
            \WP_CLI::log(sprintf('  Uploaded: %d', $result['uploaded']));
            \WP_CLI::log(sprintf('  Deleted from S3: %d', $result['deleted']));
            \WP_CLI::log(sprintf('  Errors: %d', $result['errors']));
        }

        if ($result['errors'] > 0) {
            \WP_CLI::warning(sprintf(
                'Sync completed with %d errors. Check error log for details.',
                $result['errors']
            ));
        } else {
            \WP_CLI::success(sprintf(
                'Sync completed. %d uploaded, %d deleted, %d total synced.',
                $result['uploaded'],
                $result['deleted'],
                $result['total_synced']
            ));
        }
    }

    /**
     * Remove all Bricks CSS from S3.
     */
    private function removeFromS3(BricksCssSyncService $syncService): void
    {
        $synced_files = $syncService->getSyncedFiles();
        $count = count($synced_files);

        if ($count === 0) {
            \WP_CLI::success('No Bricks CSS files to remove from S3.');
            return;
        }

        \WP_CLI::confirm(sprintf(
            'This will remove %d Bricks CSS files from S3. Continue?',
            $count
        ));

        \WP_CLI::log('Removing Bricks CSS files from S3...');

        $result = $syncService->removeAllFromS3();

        if ($result['errors'] > 0) {
            \WP_CLI::warning(sprintf(
                'Removal completed with %d errors. %d files deleted.',
                $result['errors'],
                $result['deleted']
            ));
        } else {
            \WP_CLI::success(sprintf(
                'Successfully removed %d Bricks CSS files from S3.',
                $result['deleted']
            ));
        }
    }

    /**
     * Get or create the sync service instance.
     */
    private function getSyncService(): BricksCssSyncService
    {
        if ($this->syncService === null) {
            $s3Provider = new S3Provider();
            $this->syncService = new BricksCssSyncService($s3Provider);
        }
        return $this->syncService;
    }
}
