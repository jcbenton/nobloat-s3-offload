<?php
/**
 * WP CLI command for Bricks CSS sync operations.
 *
 * @package NBS3
 * @subpackage CLI
 */

namespace NBS3\CLI;

use NBS3\S3Provider;
use NBS3\Services\BricksCssSyncService;
use NBS3\Services\BricksThemeAssetsSyncService;

/**
 * WP CLI command for Bricks CSS sync operations.
 */
class BricksSyncCommand {

	/**
	 * CSS sync service instance.
	 *
	 * @var BricksCssSyncService|null
	 */
	private ?BricksCssSyncService $css_sync_service = null;

	/**
	 * Theme assets sync service instance.
	 *
	 * @var BricksThemeAssetsSyncService|null
	 */
	private ?BricksThemeAssetsSyncService $theme_assets_sync_service = null;

	/**
	 * S3 provider instance.
	 *
	 * @var S3Provider|null
	 */
	private ?S3Provider $s3_provider = null;

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
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (flags).
	 *
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		// Check if Bricks is active.
		if ( ! nbs3_is_bricks_active() ) {
			\WP_CLI::error( 'Bricks Builder is not active.' );
		}

		// Check S3 credentials.
		$bucket = nbs3_get_credential( 'bucket' );
		if ( empty( $bucket ) ) {
			\WP_CLI::error( 'No S3 credentials configured. Please configure your S3 settings first.' );
		}

		$verbose     = isset( $assoc_args['verbose'] );
		$css_only    = isset( $assoc_args['css-only'] );
		$assets_only = isset( $assoc_args['assets-only'] );
		$is_revert   = isset( $assoc_args['remove'] ) || isset( $assoc_args['revert'] );

		// Determine what to sync/remove.
		$sync_css = ! $assets_only;
		// For revert: remove all synced files regardless of setting state.
		// For sync: only sync if setting is enabled.
		$sync_assets = ! $css_only && ( $is_revert || nbs3_get_setting( 'sync_bricks_theme_assets', false ) );

		// Handle --status flag.
		if ( isset( $assoc_args['status'] ) ) {
			$this->show_status( $sync_css, $sync_assets );
			return;
		}

		// Handle --remove or --revert flag.
		if ( $is_revert ) {
			$this->remove_from_s3( $sync_css, $sync_assets );
			return;
		}

		// Perform sync.
		$this->perform_sync( $sync_css, $sync_assets, $verbose, $css_only );
	}

	/**
	 * Show sync status.
	 *
	 * @param bool $show_css    Whether to show CSS sync status.
	 * @param bool $show_assets Whether to show assets sync status.
	 *
	 * @return void
	 */
	private function show_status( bool $show_css, bool $show_assets ): void {
		if ( $show_css ) {
			$css_service = $this->get_css_sync_service();
			$css_status  = $css_service->getStatus();

			\WP_CLI::log( 'Bricks CSS Sync Status:' );
			\WP_CLI::log( sprintf( '  Total local files: %d', $css_status['total'] ) );
			\WP_CLI::log( sprintf( '  Synced to S3: %d', $css_status['synced'] ) );
			\WP_CLI::log( sprintf( '  Pending sync: %d', $css_status['pending'] ) );

			$css_setting = nbs3_get_setting( 'sync_bricks_css', false );
			\WP_CLI::log( sprintf( '  Auto-sync enabled: %s', $css_setting ? 'Yes' : 'No' ) );
		}

		$assets_setting = nbs3_get_setting( 'sync_bricks_theme_assets', false );

		if ( $show_assets || $assets_setting ) {
			$assets_service = $this->get_theme_assets_sync_service();
			$assets_status  = $assets_service->getStatus();

			\WP_CLI::log( '' );
			\WP_CLI::log( 'Bricks Theme Assets Sync Status:' );
			\WP_CLI::log( sprintf( '  Total local files: %d', $assets_status['total'] ) );
			\WP_CLI::log( sprintf( '  Synced to S3: %d', $assets_status['synced'] ) );
			\WP_CLI::log( sprintf( '  Pending sync: %d', $assets_status['pending'] ) );
			\WP_CLI::log( sprintf( '  Auto-sync enabled: %s', $assets_setting ? 'Yes' : 'No' ) );
		} elseif ( ! $show_assets ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Bricks Theme Assets: Not enabled (enable in settings to sync)' );
		}
	}

	/**
	 * Perform full sync.
	 *
	 * @param bool $sync_css    Whether to sync CSS files.
	 * @param bool $sync_assets Whether to sync theme assets.
	 * @param bool $verbose     Whether to show verbose output.
	 * @param bool $css_only    Whether only CSS is being synced.
	 *
	 * @return void
	 */
	private function perform_sync( bool $sync_css, bool $sync_assets, bool $verbose, bool $css_only = false ): void {
		$total_uploaded = 0;
		$total_deleted  = 0;
		$total_errors   = 0;

		// Sync generated CSS files.
		if ( $sync_css ) {
			\WP_CLI::log( 'Starting Bricks CSS sync...' );

			$css_service = $this->get_css_sync_service();
			$local_files = $css_service->scanLocalFiles();
			$total       = count( $local_files );

			if ( 0 === $total ) {
				\WP_CLI::log( 'No Bricks CSS files found.' );
			} else {
				\WP_CLI::log( sprintf( 'Found %d local CSS files.', $total ) );

				$result          = $css_service->fullSync();
				$total_uploaded += $result['uploaded'];
				$total_deleted  += $result['deleted'];
				$total_errors   += $result['errors'];

				if ( $verbose ) {
					\WP_CLI::log( sprintf( '  CSS uploaded: %d', $result['uploaded'] ) );
					\WP_CLI::log( sprintf( '  CSS deleted from S3: %d', $result['deleted'] ) );
					\WP_CLI::log( sprintf( '  CSS errors: %d', $result['errors'] ) );
				}
			}
		}

		// Sync theme assets.
		if ( $sync_assets ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Starting Bricks theme assets sync...' );

			$assets_service = $this->get_theme_assets_sync_service();
			$local_files    = $assets_service->scanLocalFiles();
			$total          = count( $local_files );

			if ( 0 === $total ) {
				\WP_CLI::log( 'No Bricks theme asset files found.' );
			} else {
				\WP_CLI::log( sprintf( 'Found %d local theme asset files.', $total ) );

				$result          = $assets_service->fullSync();
				$total_uploaded += $result['uploaded'];
				$total_deleted  += $result['deleted'];
				$total_errors   += $result['errors'];

				if ( $verbose ) {
					\WP_CLI::log( sprintf( '  Assets uploaded: %d', $result['uploaded'] ) );
					\WP_CLI::log( sprintf( '  Assets skipped (unchanged): %d', $result['skipped'] ) );
					\WP_CLI::log( sprintf( '  Assets deleted from S3: %d', $result['deleted'] ) );
					\WP_CLI::log( sprintf( '  Assets errors: %d', $result['errors'] ) );
				}
			}
		} elseif ( ! $css_only ) {
			// Show hint about theme assets only if not using --css-only (i.e., normal sync mode).
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Theme assets sync not enabled. Enable in settings or use --assets-only to force.' );
		}

		// Summary.
		\WP_CLI::log( '' );
		if ( $total_errors > 0 ) {
			\WP_CLI::warning(
				sprintf(
					'Sync completed with %d errors. %d uploaded, %d deleted.',
					$total_errors,
					$total_uploaded,
					$total_deleted
				)
			);
		} else {
			\WP_CLI::success(
				sprintf(
					'Sync completed. %d uploaded, %d deleted.',
					$total_uploaded,
					$total_deleted
				)
			);
		}
	}

	/**
	 * Remove all Bricks files from S3.
	 *
	 * @param bool $remove_css    Whether to remove CSS files.
	 * @param bool $remove_assets Whether to remove theme assets.
	 *
	 * @return void
	 */
	private function remove_from_s3( bool $remove_css, bool $remove_assets ): void {
		$css_count    = 0;
		$assets_count = 0;

		if ( $remove_css ) {
			$css_service = $this->get_css_sync_service();
			$css_count   = count( $css_service->getSyncedFiles() );
		}

		if ( $remove_assets ) {
			$assets_service = $this->get_theme_assets_sync_service();
			$assets_count   = count( $assets_service->getSyncedFiles() );
		}

		$total_count = $css_count + $assets_count;

		if ( 0 === $total_count ) {
			\WP_CLI::success( 'No Bricks files to remove from S3.' );
			return;
		}

		$message = sprintf( 'This will remove %d files from S3', $total_count );
		if ( $css_count > 0 && $assets_count > 0 ) {
			$message .= sprintf( ' (%d CSS, %d theme assets)', $css_count, $assets_count );
		}
		$message .= '. Continue?';

		\WP_CLI::confirm( $message );

		$total_deleted = 0;
		$total_errors  = 0;

		if ( $remove_css && $css_count > 0 ) {
			\WP_CLI::log( 'Removing Bricks CSS files from S3...' );
			$result         = $this->get_css_sync_service()->removeAllFromS3();
			$total_deleted += $result['deleted'];
			$total_errors  += $result['errors'];
		}

		if ( $remove_assets && $assets_count > 0 ) {
			\WP_CLI::log( 'Removing Bricks theme assets from S3...' );
			$result         = $this->get_theme_assets_sync_service()->removeAllFromS3();
			$total_deleted += $result['deleted'];
			$total_errors  += $result['errors'];
		}

		if ( $total_errors > 0 ) {
			\WP_CLI::warning(
				sprintf(
					'Removal completed with %d errors. %d files deleted.',
					$total_errors,
					$total_deleted
				)
			);
		} else {
			\WP_CLI::success(
				sprintf(
					'Successfully removed %d Bricks files from S3.',
					$total_deleted
				)
			);
		}
	}

	/**
	 * Get or create the S3 provider instance.
	 *
	 * @return S3Provider The S3 provider instance.
	 */
	private function get_s3_provider(): S3Provider {
		if ( null === $this->s3_provider ) {
			$this->s3_provider = new S3Provider();
		}
		return $this->s3_provider;
	}

	/**
	 * Get or create the CSS sync service instance.
	 *
	 * @return BricksCssSyncService The CSS sync service instance.
	 */
	private function get_css_sync_service(): BricksCssSyncService {
		if ( null === $this->css_sync_service ) {
			$this->css_sync_service = new BricksCssSyncService( $this->get_s3_provider() );
		}
		return $this->css_sync_service;
	}

	/**
	 * Get or create the theme assets sync service instance.
	 *
	 * @return BricksThemeAssetsSyncService The theme assets sync service instance.
	 */
	private function get_theme_assets_sync_service(): BricksThemeAssetsSyncService {
		if ( null === $this->theme_assets_sync_service ) {
			$this->theme_assets_sync_service = new BricksThemeAssetsSyncService( $this->get_s3_provider() );
		}
		return $this->theme_assets_sync_service;
	}
}
