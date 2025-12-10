<?php

namespace NBS3\Observers;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Services\BricksThemeAssetsSyncService;

/**
 * Observer for syncing Bricks theme assets to S3 on plugin/theme updates.
 *
 * Triggers a background sync of /themes/bricks/assets/ whenever any plugin
 * or theme is updated, ensuring the S3 copy stays current.
 */
class BricksThemeAssetsSyncObserver implements ObserverInterface
{
    private S3Provider $s3Provider;

    public function __construct(S3Provider $s3Provider)
    {
        $this->s3Provider = $s3Provider;
    }

    public function register(): void
    {
        // Only register if Bricks is active and theme assets sync is enabled
        if (!nbs3_is_bricks_active()) {
            return;
        }

        if (!nbs3_get_setting('sync_bricks_theme_assets', false)) {
            return;
        }

        // Hook into plugin/theme updates - fires after ANY upgrade completes
        add_action('upgrader_process_complete', [$this, 'onUpgraderComplete'], 10, 2);

        // Also hook into theme switch (in case user switches back to Bricks)
        add_action('switch_theme', [$this, 'onThemeSwitch'], 10, 0);
    }

    /**
     * Called after any WordPress upgrade completes (plugin, theme, core, translation).
     *
     * @param \WP_Upgrader $upgrader The upgrader instance
     * @param array $hook_extra Extra args
     */
    public function onUpgraderComplete($upgrader, array $hook_extra): void
    {
        // Fire async sync for any update type
        // We don't care which specific plugin/theme updated - just resync
        $this->triggerBackgroundSync();
    }

    /**
     * Called when the theme is switched.
     */
    public function onThemeSwitch(): void
    {
        // If switched to Bricks, trigger a sync
        if (nbs3_is_bricks_active()) {
            $this->triggerBackgroundSync();
        }
    }

    /**
     * Trigger a background sync using wp_remote_post (fire and forget).
     */
    private function triggerBackgroundSync(): void
    {
        // Set a flag to prevent multiple syncs from running simultaneously
        $lock = get_transient('nbs3_bricks_theme_assets_sync_lock');
        if ($lock) {
            return;
        }

        // Set lock for 5 minutes
        set_transient('nbs3_bricks_theme_assets_sync_lock', true, 300);

        // Schedule the sync to run immediately via WordPress cron
        if (!wp_next_scheduled('nbs3_sync_bricks_theme_assets_async')) {
            wp_schedule_single_event(time(), 'nbs3_sync_bricks_theme_assets_async');
        }

        // Spawn cron immediately
        spawn_cron();
    }

    /**
     * Register the cron hook for async sync.
     * Called from the main plugin file.
     */
    public static function registerCronHook(S3Provider $s3Provider): void
    {
        add_action('nbs3_sync_bricks_theme_assets_async', function() use ($s3Provider) {
            // Clear the lock
            delete_transient('nbs3_bricks_theme_assets_sync_lock');

            // Verify setting is still enabled
            if (!nbs3_get_setting('sync_bricks_theme_assets', false)) {
                return;
            }

            // Run the sync
            $service = new BricksThemeAssetsSyncService($s3Provider);
            $result = $service->fullSync();

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional logging for cron sync results
            error_log(sprintf(
                'NBS3: Bricks theme assets sync complete - uploaded: %d, skipped: %d, deleted: %d, errors: %d',
                $result['uploaded'],
                $result['skipped'],
                $result['deleted'],
                $result['errors']
            ));
        });
    }
}
