<?php

namespace NBS3\Services;

use NBS3\S3Provider;

/**
 * Service for syncing Bricks theme assets to S3.
 */
class BricksThemeAssetsSyncService {

	private S3Provider $s3Provider;
	private string $localPath;
	private string $s3Prefix = 'themes/bricks/assets/';

	public function __construct( S3Provider $s3Provider ) {
		$this->s3Provider = $s3Provider;
		$this->localPath  = get_template_directory() . '/assets/';
	}

	/**
	 * Full sync - upload all files from Bricks assets folder.
	 */
	public function fullSync(): array {
		$local_files  = $this->scanLocalFiles();
		$synced_files = $this->getSyncedFiles();

		$uploaded = 0;
		$skipped  = 0;
		$errors   = 0;

		foreach ( $local_files as $relative_path => $mtime ) {
			// Check if file needs upload (new or modified)
			if ( isset( $synced_files[ $relative_path ] ) && $synced_files[ $relative_path ]['mtime'] >= $mtime ) {
				++$skipped;
				continue;
			}

			if ( $this->uploadFile( $relative_path ) ) {
				$this->markFileSynced( $relative_path, $mtime );
				++$uploaded;
			} else {
				++$errors;
			}
		}

		// Clean up files that no longer exist locally
		$deleted = 0;
		foreach ( $synced_files as $relative_path => $data ) {
			if ( ! isset( $local_files[ $relative_path ] ) ) {
				if ( $this->deleteFromS3( $relative_path ) ) {
					++$deleted;
				}
			}
		}

		return array(
			'uploaded'     => $uploaded,
			'skipped'      => $skipped,
			'deleted'      => $deleted,
			'errors'       => $errors,
			'total_synced' => count( $this->getSyncedFiles() ),
		);
	}

	/**
	 * Upload a single file to S3.
	 */
	private function uploadFile( string $relative_path ): bool {
		$local_file = $this->localPath . $relative_path;

		if ( ! file_exists( $local_file ) || ! is_readable( $local_file ) ) {
			return false;
		}

		$s3_key = $this->s3Prefix . $relative_path;
		$result = $this->s3Provider->uploadFile( $local_file, $s3_key );

		return $result !== false;
	}

	/**
	 * Delete a file from S3.
	 */
	public function deleteFromS3( string $relative_path ): bool {
		try {
			$client = $this->s3Provider->getClient();
			$client->deleteObject(
				array(
					'Bucket' => $this->s3Provider->getBucket(),
					'Key'    => $this->s3Prefix . $relative_path,
				)
			);

			$this->unmarkFileSynced( $relative_path );
			return true;
		} catch ( \Exception $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging
			error_log( "NBS3: Failed to delete Bricks theme asset from S3: {$relative_path}" );
			return false;
		}
	}

	/**
	 * Remove all Bricks theme assets from S3.
	 */
	public function removeAllFromS3(): array {
		$synced_files = $this->getSyncedFiles();
		$deleted      = 0;
		$errors       = 0;

		foreach ( $synced_files as $relative_path => $data ) {
			if ( $this->deleteFromS3( $relative_path ) ) {
				++$deleted;
			} else {
				++$errors;
			}
		}

		// Clear the synced files option
		delete_option( 'nbs3_synced_bricks_theme_assets' );

		return array(
			'deleted' => $deleted,
			'errors'  => $errors,
		);
	}

	/**
	 * Scan local Bricks assets directory recursively.
	 */
	public function scanLocalFiles(): array {
		$files = array();

		if ( ! is_dir( $this->localPath ) ) {
			return $files;
		}

		// Verify path is within themes directory
		$themes_dir  = realpath( get_theme_root() );
		$actual_path = realpath( $this->localPath );

		if ( $actual_path === false || $themes_dir === false || strpos( $actual_path, $themes_dir ) !== 0 ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Security logging
			error_log( "NBS3: Invalid Bricks theme assets path: {$this->localPath}" );
			return $files;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->localPath, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $fileinfo ) {
				if ( ! $fileinfo->isFile() || ! $fileinfo->isReadable() ) {
					continue;
				}

				// Get relative path from assets folder
				$full_path     = $fileinfo->getPathname();
				$relative_path = str_replace( $this->localPath, '', $full_path );
				$relative_path = ltrim( $relative_path, '/\\' );

				// Normalize path separators
				$relative_path = str_replace( '\\', '/', $relative_path );

				// Skip hidden files and common non-essential files
				$filename = $fileinfo->getFilename();
				if ( strpos( $filename, '.' ) === 0 ) {
					continue;
				}

				$files[ $relative_path ] = $fileinfo->getMTime();
			}
		} catch ( \Exception $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging
			error_log( 'NBS3: Error scanning Bricks theme assets: ' . $e->getMessage() );
			return $files;
		}

		return $files;
	}

	/**
	 * Get synced files from options.
	 */
	public function getSyncedFiles(): array {
		return get_option( 'nbs3_synced_bricks_theme_assets', array() );
	}

	/**
	 * Mark a file as synced.
	 */
	private function markFileSynced( string $relative_path, int $mtime ): void {
		$synced_files                   = $this->getSyncedFiles();
		$synced_files[ $relative_path ] = array(
			'mtime'     => $mtime,
			'synced_at' => time(),
		);
		update_option( 'nbs3_synced_bricks_theme_assets', $synced_files, false );
	}

	/**
	 * Remove a file from the synced list.
	 */
	private function unmarkFileSynced( string $relative_path ): void {
		$synced_files = $this->getSyncedFiles();
		if ( isset( $synced_files[ $relative_path ] ) ) {
			unset( $synced_files[ $relative_path ] );
			update_option( 'nbs3_synced_bricks_theme_assets', $synced_files, false );
		}
	}

	/**
	 * Check if a file is synced.
	 */
	public function isFileSynced( string $relative_path ): bool {
		$synced_files = $this->getSyncedFiles();
		return isset( $synced_files[ $relative_path ] );
	}

	/**
	 * Get sync status.
	 */
	public function getStatus(): array {
		$local_files  = $this->scanLocalFiles();
		$synced_files = $this->getSyncedFiles();

		$pending = 0;
		foreach ( $local_files as $path => $mtime ) {
			if ( ! isset( $synced_files[ $path ] ) || $synced_files[ $path ]['mtime'] < $mtime ) {
				++$pending;
			}
		}

		return array(
			'total'   => count( $local_files ),
			'synced'  => count( $synced_files ),
			'pending' => $pending,
		);
	}
}
