<?php
/**
 * Bricks Theme Assets Sync Observer.
 *
 * @package NoBloat_S3_Offload
 * @since   1.0.0
 */

namespace NBS3\Observers;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Services\BricksThemeAssetsSyncService;

/**
 * Observer for syncing Bricks theme assets to S3 on plugin/theme updates.
 *
 * Triggers a background sync of /themes/bricks/assets/ whenever any plugin
 * or theme is updated, ensuring the S3 copy stays current.
 *
 * @since 1.0.0
 */
class BricksThemeAssetsSyncObserver implements ObserverInterface {

	/**
	 * Cloud provider instance.
	 *
	 * @var S3Provider
	 */
	private S3Provider $s3_provider;

	/**
	 * Constructor.
	 *
	 * @param S3Provider $s3_provider The cloud provider instance.
	 */
	public function __construct( S3Provider $s3_provider ) {
		$this->s3_provider = $s3_provider;
	}

	/**
	 * Register the observer with WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Only register if Bricks is active and theme assets sync is enabled.
		if ( ! nbs3_is_bricks_active() ) {
			return;
		}

		if ( ! nbs3_get_setting( 'sync_bricks_theme_assets', false ) ) {
			return;
		}

		// Hook into plugin/theme updates - fires after ANY upgrade completes.
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );

		// Also hook into theme switch (in case user switches back to Bricks).
		add_action( 'switch_theme', array( $this, 'on_theme_switch' ), 10, 0 );
	}

	/**
	 * Called after any WordPress upgrade completes (plugin, theme, core, translation).
	 *
	 * @param \WP_Upgrader $upgrader   The upgrader instance.
	 * @param array        $hook_extra Extra args.
	 * @return void
	 */
	public function on_upgrader_complete( $upgrader, array $hook_extra ): void {
		// Fire async sync for any update type.
		// We don't care which specific plugin/theme updated - just resync.
		$this->trigger_background_sync();
	}

	/**
	 * Called when the theme is switched.
	 *
	 * @return void
	 */
	public function on_theme_switch(): void {
		// If switched to Bricks, trigger a sync.
		if ( nbs3_is_bricks_active() ) {
			$this->trigger_background_sync();
		}
	}

	/**
	 * Trigger a background sync using wp_remote_post (fire and forget).
	 *
	 * @return void
	 */
	private function trigger_background_sync(): void {
		// Set a flag to prevent multiple syncs from running simultaneously.
		$lock = get_transient( 'nbs3_bricks_theme_assets_sync_lock' );
		if ( $lock ) {
			return;
		}

		// Set lock for 5 minutes.
		set_transient( 'nbs3_bricks_theme_assets_sync_lock', true, 300 );

		// Schedule the sync to run immediately via WordPress cron.
		if ( ! wp_next_scheduled( 'nbs3_sync_bricks_theme_assets_async' ) ) {
			wp_schedule_single_event( time(), 'nbs3_sync_bricks_theme_assets_async' );
		}

		// Spawn cron immediately.
		spawn_cron();
	}

	/**
	 * Register the cron hook for async sync.
	 *
	 * Called from the main plugin file.
	 *
	 * @param S3Provider $s3_provider The cloud provider instance.
	 * @return void
	 */
	public static function register_cron_hook( S3Provider $s3_provider ): void {
		add_action(
			'nbs3_sync_bricks_theme_assets_async',
			function () use ( $s3_provider ) {
				// Clear the lock.
				delete_transient( 'nbs3_bricks_theme_assets_sync_lock' );

				// Verify setting is still enabled.
				if ( ! nbs3_get_setting( 'sync_bricks_theme_assets', false ) ) {
					return;
				}

				// Run the sync.
				$service = new BricksThemeAssetsSyncService( $s3_provider );
				$result  = $service->fullSync();

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional logging for cron sync results.
				error_log(
					sprintf(
						'NBS3: Bricks theme assets sync complete - uploaded: %d, skipped: %d, deleted: %d, errors: %d',
						$result['uploaded'],
						$result['skipped'],
						$result['deleted'],
						$result['errors']
					)
				);
			}
		);
	}
}
