<?php

namespace NBS3\CLI;

use NBS3\S3Provider;
use NBS3\Services\BricksCssSyncService;
use NBS3\Services\BricksThemeAssetsSyncService;

/**
 * WP CLI command for Bricks CSS sync operations.
 */
class BricksSyncCommand {

	private ?BricksCssSyncService $cssSyncService                 = null;
	private ?BricksThemeAssetsSyncService $themeAssetsSyncService = null;
	private ?S3Provider $s3Provider                               = null;

	/**
	 * Sync Bricks CSS files and theme assets to S3.
	 *
	 * ## OPTIONS
	 *
	 * [--status]
	 * : Show sync status without syncing.
	 *
	 * [--remove]
	 * : Remove all Bricks files from S3 (revert to local serving).
	 *
	 * [--revert]
	 * : Alias for --remove. Remove all Bricks files from S3.
	 *
	 * [--css-only]
	 * : Only sync generated CSS files (skip theme assets).
	 *
	 * [--assets-only]
	 * : Only sync theme assets (skip generated CSS).
	 *
	 * [--verbose]
	 * : Show detailed output.
	 *
	 * ## EXAMPLES
	 *
	 *     # Sync all Bricks CSS files and theme assets (if enabled)
	 *     wp nbs3 sync-bricks
	 *
	 *     # Show sync status
	 *     wp nbs3 sync-bricks --status
	 *
	 *     # Only sync generated CSS files
	 *     wp nbs3 sync-bricks --css-only
	 *
	 *     # Only sync theme assets
	 *     wp nbs3 sync-bricks --assets-only
	 *
	 *     # Remove all Bricks files from S3 (revert to local)
	 *     wp nbs3 sync-bricks --remove
	 *     wp nbs3 sync-bricks --revert
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments (flags).
	 */
	public function __invoke( $args, $assoc_args ) {
		// Check if Bricks is active
		if ( ! nbs3_is_bricks_active() ) {
			\WP_CLI::error( 'Bricks Builder is not active.' );
		}

		// Check S3 credentials
		$bucket = nbs3_get_credential( 'bucket' );
		if ( empty( $bucket ) ) {
			\WP_CLI::error( 'No S3 credentials configured. Please configure your S3 settings first.' );
		}

		$verbose    = isset( $assoc_args['verbose'] );
		$cssOnly    = isset( $assoc_args['css-only'] );
		$assetsOnly = isset( $assoc_args['assets-only'] );
		$isRevert   = isset( $assoc_args['remove'] ) || isset( $assoc_args['revert'] );

		// Determine what to sync/remove
		$syncCss = ! $assetsOnly;
		// For revert: remove all synced files regardless of setting state
		// For sync: only sync if setting is enabled
		$syncAssets = ! $cssOnly && ( $isRevert || nbs3_get_setting( 'sync_bricks_theme_assets', false ) );

		// Handle --status flag
		if ( isset( $assoc_args['status'] ) ) {
			$this->showStatus( $syncCss, $syncAssets );
			return;
		}

		// Handle --remove or --revert flag
		if ( $isRevert ) {
			$this->removeFromS3( $syncCss, $syncAssets );
			return;
		}

		// Perform sync
		$this->performSync( $syncCss, $syncAssets, $verbose, $cssOnly );
	}

	/**
	 * Show sync status.
	 */
	private function showStatus( bool $showCss, bool $showAssets ): void {
		if ( $showCss ) {
			$cssService = $this->getCssSyncService();
			$cssStatus  = $cssService->getStatus();

			\WP_CLI::log( 'Bricks CSS Sync Status:' );
			\WP_CLI::log( sprintf( '  Total local files: %d', $cssStatus['total'] ) );
			\WP_CLI::log( sprintf( '  Synced to S3: %d', $cssStatus['synced'] ) );
			\WP_CLI::log( sprintf( '  Pending sync: %d', $cssStatus['pending'] ) );

			$cssSetting = nbs3_get_setting( 'sync_bricks_css', false );
			\WP_CLI::log( sprintf( '  Auto-sync enabled: %s', $cssSetting ? 'Yes' : 'No' ) );
		}

		$assetsSetting = nbs3_get_setting( 'sync_bricks_theme_assets', false );

		if ( $showAssets || $assetsSetting ) {
			$assetsService = $this->getThemeAssetsSyncService();
			$assetsStatus  = $assetsService->getStatus();

			\WP_CLI::log( '' );
			\WP_CLI::log( 'Bricks Theme Assets Sync Status:' );
			\WP_CLI::log( sprintf( '  Total local files: %d', $assetsStatus['total'] ) );
			\WP_CLI::log( sprintf( '  Synced to S3: %d', $assetsStatus['synced'] ) );
			\WP_CLI::log( sprintf( '  Pending sync: %d', $assetsStatus['pending'] ) );
			\WP_CLI::log( sprintf( '  Auto-sync enabled: %s', $assetsSetting ? 'Yes' : 'No' ) );
		} elseif ( ! $showAssets ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Bricks Theme Assets: Not enabled (enable in settings to sync)' );
		}
	}

	/**
	 * Perform full sync.
	 */
	private function performSync( bool $syncCss, bool $syncAssets, bool $verbose, bool $cssOnly = false ): void {
		$totalUploaded = 0;
		$totalDeleted  = 0;
		$totalErrors   = 0;

		// Sync generated CSS files
		if ( $syncCss ) {
			\WP_CLI::log( 'Starting Bricks CSS sync...' );

			$cssService  = $this->getCssSyncService();
			$local_files = $cssService->scanLocalFiles();
			$total       = count( $local_files );

			if ( $total === 0 ) {
				\WP_CLI::log( 'No Bricks CSS files found.' );
			} else {
				\WP_CLI::log( sprintf( 'Found %d local CSS files.', $total ) );

				$result         = $cssService->fullSync();
				$totalUploaded += $result['uploaded'];
				$totalDeleted  += $result['deleted'];
				$totalErrors   += $result['errors'];

				if ( $verbose ) {
					\WP_CLI::log( sprintf( '  CSS uploaded: %d', $result['uploaded'] ) );
					\WP_CLI::log( sprintf( '  CSS deleted from S3: %d', $result['deleted'] ) );
					\WP_CLI::log( sprintf( '  CSS errors: %d', $result['errors'] ) );
				}
			}
		}

		// Sync theme assets
		if ( $syncAssets ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Starting Bricks theme assets sync...' );

			$assetsService = $this->getThemeAssetsSyncService();
			$local_files   = $assetsService->scanLocalFiles();
			$total         = count( $local_files );

			if ( $total === 0 ) {
				\WP_CLI::log( 'No Bricks theme asset files found.' );
			} else {
				\WP_CLI::log( sprintf( 'Found %d local theme asset files.', $total ) );

				$result         = $assetsService->fullSync();
				$totalUploaded += $result['uploaded'];
				$totalDeleted  += $result['deleted'];
				$totalErrors   += $result['errors'];

				if ( $verbose ) {
					\WP_CLI::log( sprintf( '  Assets uploaded: %d', $result['uploaded'] ) );
					\WP_CLI::log( sprintf( '  Assets skipped (unchanged): %d', $result['skipped'] ) );
					\WP_CLI::log( sprintf( '  Assets deleted from S3: %d', $result['deleted'] ) );
					\WP_CLI::log( sprintf( '  Assets errors: %d', $result['errors'] ) );
				}
			}
		} elseif ( ! $cssOnly ) {
			// Show hint about theme assets only if not using --css-only (i.e., normal sync mode)
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Theme assets sync not enabled. Enable in settings or use --assets-only to force.' );
		}

		// Summary
		\WP_CLI::log( '' );
		if ( $totalErrors > 0 ) {
			\WP_CLI::warning(
				sprintf(
					'Sync completed with %d errors. %d uploaded, %d deleted.',
					$totalErrors,
					$totalUploaded,
					$totalDeleted
				)
			);
		} else {
			\WP_CLI::success(
				sprintf(
					'Sync completed. %d uploaded, %d deleted.',
					$totalUploaded,
					$totalDeleted
				)
			);
		}
	}

	/**
	 * Remove all Bricks files from S3.
	 */
	private function removeFromS3( bool $removeCss, bool $removeAssets ): void {
		$cssCount    = 0;
		$assetsCount = 0;

		if ( $removeCss ) {
			$cssService = $this->getCssSyncService();
			$cssCount   = count( $cssService->getSyncedFiles() );
		}

		if ( $removeAssets ) {
			$assetsService = $this->getThemeAssetsSyncService();
			$assetsCount   = count( $assetsService->getSyncedFiles() );
		}

		$totalCount = $cssCount + $assetsCount;

		if ( $totalCount === 0 ) {
			\WP_CLI::success( 'No Bricks files to remove from S3.' );
			return;
		}

		$message = sprintf( 'This will remove %d files from S3', $totalCount );
		if ( $cssCount > 0 && $assetsCount > 0 ) {
			$message .= sprintf( ' (%d CSS, %d theme assets)', $cssCount, $assetsCount );
		}
		$message .= '. Continue?';

		\WP_CLI::confirm( $message );

		$totalDeleted = 0;
		$totalErrors  = 0;

		if ( $removeCss && $cssCount > 0 ) {
			\WP_CLI::log( 'Removing Bricks CSS files from S3...' );
			$result        = $this->getCssSyncService()->removeAllFromS3();
			$totalDeleted += $result['deleted'];
			$totalErrors  += $result['errors'];
		}

		if ( $removeAssets && $assetsCount > 0 ) {
			\WP_CLI::log( 'Removing Bricks theme assets from S3...' );
			$result        = $this->getThemeAssetsSyncService()->removeAllFromS3();
			$totalDeleted += $result['deleted'];
			$totalErrors  += $result['errors'];
		}

		if ( $totalErrors > 0 ) {
			\WP_CLI::warning(
				sprintf(
					'Removal completed with %d errors. %d files deleted.',
					$totalErrors,
					$totalDeleted
				)
			);
		} else {
			\WP_CLI::success(
				sprintf(
					'Successfully removed %d Bricks files from S3.',
					$totalDeleted
				)
			);
		}
	}

	/**
	 * Get or create the S3 provider instance.
	 */
	private function getS3Provider(): S3Provider {
		if ( $this->s3Provider === null ) {
			$this->s3Provider = new S3Provider();
		}
		return $this->s3Provider;
	}

	/**
	 * Get or create the CSS sync service instance.
	 */
	private function getCssSyncService(): BricksCssSyncService {
		if ( $this->cssSyncService === null ) {
			$this->cssSyncService = new BricksCssSyncService( $this->getS3Provider() );
		}
		return $this->cssSyncService;
	}

	/**
	 * Get or create the theme assets sync service instance.
	 */
	private function getThemeAssetsSyncService(): BricksThemeAssetsSyncService {
		if ( $this->themeAssetsSyncService === null ) {
			$this->themeAssetsSyncService = new BricksThemeAssetsSyncService( $this->getS3Provider() );
		}
		return $this->themeAssetsSyncService;
	}
}
