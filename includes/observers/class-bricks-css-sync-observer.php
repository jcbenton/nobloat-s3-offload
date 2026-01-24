<?php
/**
 * Bricks CSS Sync Observer.
 *
 * @package NoBloat_S3_Offload
 * @since   1.0.0
 */

namespace NBS3\Observers;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Services\BricksCssSyncService;

/**
 * Observer for syncing Bricks CSS files to S3 immediately when generated.
 *
 * @since 1.0.0
 */
class BricksCssSyncObserver implements ObserverInterface {

	/**
	 * The sync service instance.
	 *
	 * @var BricksCssSyncService|null
	 */
	private ?BricksCssSyncService $sync_service = null;

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
		// Only register if Bricks is active and sync is enabled.
		if ( ! nbs3_is_bricks_active() ) {
			return;
		}

		// Hook into Bricks CSS file generation.
		add_action( 'bricks/generate_css_file', array( $this, 'on_css_file_generated' ), 10, 2 );
	}

	/**
	 * Called when Bricks generates a CSS file.
	 *
	 * @param string $type      File type: 'global-color-palettes', 'global-elements', 'theme-styles', 'global-custom-css', 'post'.
	 * @param string $file_name The generated CSS file name.
	 * @return void
	 */
	public function on_css_file_generated( string $type, string $file_name ): void {
		// Check if Bricks CSS sync is enabled.
		if ( ! nbs3_get_setting( 'sync_bricks_css', false ) ) {
			return;
		}

		// Get the sync service.
		$sync_service = $this->get_sync_service();

		// Sync the file to S3.
		$result = $sync_service->syncFile( $file_name );

		if ( $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging for sync operations.
			error_log( "NBS3: Synced Bricks CSS file: {$file_name} (type: {$type})" );
		}
	}

	/**
	 * Get or create the sync service instance.
	 *
	 * @return BricksCssSyncService The sync service instance.
	 */
	private function get_sync_service(): BricksCssSyncService {
		if ( null === $this->sync_service ) {
			$this->sync_service = new BricksCssSyncService( $this->s3_provider );
		}
		return $this->sync_service;
	}
}
