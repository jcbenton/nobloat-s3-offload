<?php

namespace NBS3\Services;

use NBS3\S3Provider;

/**
 * Service for syncing Bricks CSS files to S3.
 */
class BricksCssSyncService {

	private S3Provider $s3Provider;
	private string $localPath;
	private string $s3Prefix = 'uploads/bricks/css/';

	public function __construct( S3Provider $s3Provider ) {
		$this->s3Provider = $s3Provider;
		$this->localPath  = nbs3_get_bricks_css_path();
	}

	/**
	 * Sync a single file to S3 (called immediately when Bricks generates CSS).
	 */
	public function syncFile( string $file_name ): bool {
		// Security: Strip any directory components to prevent path traversal
		$file_name = basename( $file_name );

		// Security: Validate filename format (alphanumeric, dash, underscore, dot, parentheses)
		if ( ! preg_match( '/^[a-zA-Z0-9._()-]+\.css$/', $file_name ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Security logging
			error_log( "NBS3: Invalid Bricks CSS filename rejected: {$file_name}" );
			return false;
		}

		$local_file = trailingslashit( $this->localPath ) . $file_name;

		// Security: Verify the resolved path is within the expected directory
		$real_path     = realpath( $local_file );
		$expected_base = realpath( $this->localPath );

		if ( $real_path === false || $expected_base === false || strpos( $real_path, $expected_base ) !== 0 ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Security logging
			error_log( "NBS3: Path traversal attempt detected or file not found: {$file_name}" );
			return false;
		}

		if ( ! file_exists( $local_file ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging
			error_log( "NBS3: Bricks CSS file not found: {$local_file}" );
			return false;
		}

		$s3_key = $this->s3Prefix . $file_name;
		$result = $this->s3Provider->uploadFile( $local_file, $s3_key );

		if ( $result ) {
			$this->markFileSynced( $file_name, filemtime( $local_file ) );
			return true;
		}

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging
		error_log( "NBS3: Failed to sync Bricks CSS file: {$file_name}" );
		return false;
	}

	/**
	 * Full sync - upload new/modified files, delete orphaned S3 files.
	 */
	public function fullSync(): array {
		$local_files  = $this->scanLocalFiles();
		$synced_files = $this->getSyncedFiles();

		$uploaded = 0;
		$deleted  = 0;
		$errors   = 0;

		// Upload new/modified files
		foreach ( $local_files as $file => $mtime ) {
			if ( ! isset( $synced_files[ $file ] ) || $synced_files[ $file ]['mtime'] < $mtime ) {
				if ( $this->syncFile( $file ) ) {
					++$uploaded;
				} else {
					++$errors;
				}
			}
		}

		// Delete files from S3 that no longer exist locally
		foreach ( $synced_files as $file => $data ) {
			if ( ! isset( $local_files[ $file ] ) ) {
				if ( $this->deleteFromS3( $file ) ) {
					++$deleted;
				} else {
					++$errors;
				}
			}
		}

		return array(
			'uploaded'     => $uploaded,
			'deleted'      => $deleted,
			'errors'       => $errors,
			'total_synced' => count( $this->getSyncedFiles() ),
		);
	}

	/**
	 * Delete a file from S3.
	 */
	public function deleteFromS3( string $file_name ): bool {
		// Security: Strip any directory components
		$file_name = basename( $file_name );

		// Security: Validate filename format
		if ( ! preg_match( '/^[a-zA-Z0-9._()-]+\.css$/', $file_name ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Security logging
			error_log( "NBS3: Invalid filename rejected for S3 deletion: {$file_name}" );
			return false;
		}

		try {
			$client = $this->s3Provider->getClient();
			$client->deleteObject(
				array(
					'Bucket' => $this->s3Provider->getBucket(),
					'Key'    => $this->s3Prefix . $file_name,
				)
			);

			$this->unmarkFileSynced( $file_name );
			return true;
		} catch ( \Exception $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging
			error_log( "NBS3: Failed to delete Bricks CSS from S3: {$file_name}" );
			return false;
		}
	}

	/**
	 * Remove all Bricks CSS files from S3.
	 */
	public function removeAllFromS3(): array {
		$synced_files = $this->getSyncedFiles();
		$deleted      = 0;
		$errors       = 0;

		foreach ( $synced_files as $file => $data ) {
			if ( $this->deleteFromS3( $file ) ) {
				++$deleted;
			} else {
				++$errors;
			}
		}

		// Clear the synced files option
		delete_option( 'nbs3_synced_bricks_files' );

		return array(
			'deleted' => $deleted,
			'errors'  => $errors,
		);
	}

	/**
	 * Scan local Bricks CSS directory.
	 */
	public function scanLocalFiles(): array {
		$files = array();

		if ( ! is_dir( $this->localPath ) ) {
			return $files;
		}

		// Security: Validate that localPath is within wp-content/uploads
		$upload_dir    = wp_upload_dir();
		$expected_base = realpath( $upload_dir['basedir'] );
		$actual_path   = realpath( $this->localPath );

		if ( $actual_path === false || $expected_base === false || strpos( $actual_path, $expected_base ) !== 0 ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Security logging
			error_log( "NBS3: Invalid Bricks CSS path detected: {$this->localPath}" );
			return $files;
		}

		// Use DirectoryIterator for safer file scanning
		try {
			$iterator = new \DirectoryIterator( $this->localPath );

			foreach ( $iterator as $fileinfo ) {
				if ( $fileinfo->isDot() || $fileinfo->isDir() ) {
					continue;
				}

				$filename = $fileinfo->getFilename();

				// Only include valid CSS files
				if ( ! preg_match( '/^[a-zA-Z0-9._()-]+\.css$/', $filename ) ) {
					continue;
				}

				// Verify it's a regular readable file
				if ( ! $fileinfo->isFile() || ! $fileinfo->isReadable() ) {
					continue;
				}

				$files[ $filename ] = $fileinfo->getMTime();
			}
		} catch ( \Exception $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging
			error_log( 'NBS3: Error scanning Bricks CSS directory: ' . $e->getMessage() );
			return $files;
		}

		return $files;
	}

	/**
	 * Get synced files from options.
	 */
	public function getSyncedFiles(): array {
		return get_option( 'nbs3_synced_bricks_files', array() );
	}

	/**
	 * Mark a file as synced.
	 */
	public function markFileSynced( string $file_name, int $mtime ): void {
		$synced_files               = $this->getSyncedFiles();
		$synced_files[ $file_name ] = array(
			'mtime'     => $mtime,
			'synced_at' => time(),
		);
		update_option( 'nbs3_synced_bricks_files', $synced_files );
	}

	/**
	 * Remove a file from the synced list.
	 */
	public function unmarkFileSynced( string $file_name ): void {
		$synced_files = $this->getSyncedFiles();
		if ( isset( $synced_files[ $file_name ] ) ) {
			unset( $synced_files[ $file_name ] );
			update_option( 'nbs3_synced_bricks_files', $synced_files );
		}
	}

	/**
	 * Check if a file is synced.
	 */
	public function isFileSynced( string $file_name ): bool {
		// Security: Strip any directory components
		$file_name = basename( $file_name );

		// Security: Validate filename format
		if ( ! preg_match( '/^[a-zA-Z0-9._()-]+\.css$/', $file_name ) ) {
			return false;
		}

		$synced_files = $this->getSyncedFiles();
		if ( ! isset( $synced_files[ $file_name ] ) ) {
			return false;
		}

		// Also check if local file hasn't been modified since sync
		$local_file = trailingslashit( $this->localPath ) . $file_name;
		if ( ! file_exists( $local_file ) ) {
			return false;
		}

		$local_mtime = filemtime( $local_file );
		return $synced_files[ $file_name ]['mtime'] >= $local_mtime;
	}

	/**
	 * Get sync status.
	 */
	public function getStatus(): array {
		return nbs3_get_bricks_sync_status();
	}
}
