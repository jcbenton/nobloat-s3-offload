<?php
/**
 * Bricks CSS Sync Service.
 *
 * Handles synchronization of Bricks CSS files to S3 storage.
 *
 * @package NBS3
 * @since   1.0.0
 */

namespace NBS3\Services;

defined( 'ABSPATH' ) || exit;

use NBS3\S3Provider;

/**
 * Service for syncing Bricks CSS files to S3.
 *
 * @since 1.0.0
 */
class BricksCssSyncService {

	/**
	 * S3 provider instance.
	 *
	 * @since 1.0.0
	 * @var S3Provider
	 */
	private S3Provider $s3_provider;

	/**
	 * Local path to Bricks CSS files.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $local_path;

	/**
	 * S3 prefix for Bricks CSS files.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $s3_prefix = 'uploads/bricks/css/';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param S3Provider $s3_provider S3 provider instance.
	 */
	public function __construct( S3Provider $s3_provider ) {
		$this->s3_provider = $s3_provider;
		$this->local_path  = nbs3_get_bricks_css_path();
	}

	/**
	 * Sync a single file to S3 (called immediately when Bricks generates CSS).
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_name The CSS file name to sync.
	 * @return bool True on success, false on failure.
	 */
	public function sync_file( string $file_name ): bool {
		// Security: Strip any directory components to prevent path traversal.
		$file_name = basename( $file_name );

		// Security: Validate filename format (alphanumeric, dash, underscore, dot, parentheses).
		if ( ! preg_match( '/^[a-zA-Z0-9._()-]+\.css$/', $file_name ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Security logging.
			error_log( "NBS3: Invalid Bricks CSS filename rejected: {$file_name}" );
			return false;
		}

		$local_file = trailingslashit( $this->local_path ) . $file_name;

		// Security: Verify the resolved path is within the expected directory.
		$real_path     = realpath( $local_file );
		$expected_base = realpath( $this->local_path );

		if ( false === $real_path || false === $expected_base || 0 !== strpos( $real_path, $expected_base ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Security logging.
			error_log( "NBS3: Path traversal attempt detected or file not found: {$file_name}" );
			return false;
		}

		if ( ! file_exists( $local_file ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
			error_log( "NBS3: Bricks CSS file not found: {$local_file}" );
			return false;
		}

		$s3_key = $this->s3_prefix . $file_name;
		$result = $this->s3_provider->upload_file( $local_file, $s3_key );

		if ( $result ) {
			$this->mark_file_synced( $file_name, filemtime( $local_file ) );
			return true;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
		error_log( "NBS3: Failed to sync Bricks CSS file: {$file_name}" );
		return false;
	}

	/**
	 * Full sync - upload new/modified files, delete orphaned S3 files.
	 *
	 * @since 1.0.0
	 *
	 * @return array{uploaded: int, deleted: int, errors: int, total_synced: int} Sync results.
	 */
	public function full_sync(): array {
		$local_files  = $this->scan_local_files();
		$synced_files = $this->get_synced_files();

		$uploaded = 0;
		$deleted  = 0;
		$errors   = 0;

		// Upload new/modified files.
		foreach ( $local_files as $file => $mtime ) {
			if ( ! isset( $synced_files[ $file ] ) || $synced_files[ $file ]['mtime'] < $mtime ) {
				if ( $this->sync_file( $file ) ) {
					++$uploaded;
				} else {
					++$errors;
				}
			}
		}

		// Delete files from S3 that no longer exist locally.
		foreach ( $synced_files as $file => $data ) {
			if ( ! isset( $local_files[ $file ] ) ) {
				if ( $this->delete_from_s3( $file ) ) {
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
			'total_synced' => count( $this->get_synced_files() ),
		);
	}

	/**
	 * Batch sync - upload a limited number of files per call.
	 *
	 * @since 1.0.8
	 *
	 * @param int $limit Maximum number of files to process per batch.
	 * @return array Sync results with uploaded, errors, has_more, and processed counts.
	 */
	public function batch_sync( int $limit = 50 ): array {
		$local_files  = $this->scan_local_files();
		$synced_files = $this->get_synced_files();

		$uploaded  = 0;
		$errors    = 0;
		$processed = 0;

		// Upload new/modified files up to the limit.
		foreach ( $local_files as $file => $mtime ) {
			if ( $processed >= $limit ) {
				break;
			}

			if ( ! isset( $synced_files[ $file ] ) || $synced_files[ $file ]['mtime'] < $mtime ) {
				++$processed;

				if ( $this->sync_file( $file ) ) {
					++$uploaded;
				} else {
					++$errors;
				}
			}
		}

		// Calculate remaining files to process.
		$remaining_uploads = 0;
		$current_synced    = $this->get_synced_files();
		foreach ( $local_files as $file => $mtime ) {
			if ( ! isset( $current_synced[ $file ] ) || $current_synced[ $file ]['mtime'] < $mtime ) {
				++$remaining_uploads;
			}
		}

		// If no more uploads, clean up orphaned S3 files.
		$deleted = 0;
		if ( 0 === $remaining_uploads ) {
			foreach ( $current_synced as $file => $data ) {
				if ( ! isset( $local_files[ $file ] ) ) {
					if ( $this->delete_from_s3( $file ) ) {
						++$deleted;
					}
				}
			}
		}

		$status = nbs3_get_bricks_sync_status();

		return array(
			'uploaded'  => $uploaded,
			'deleted'   => $deleted,
			'errors'    => $errors,
			'processed' => $processed,
			'has_more'  => $remaining_uploads > 0,
			'remaining' => $remaining_uploads,
			'status'    => $status,
		);
	}

	/**
	 * Delete a file from S3.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_name The CSS file name to delete.
	 * @return bool True on success, false on failure.
	 */
	public function delete_from_s3( string $file_name ): bool {
		// Security: Strip any directory components.
		$file_name = basename( $file_name );

		// Security: Validate filename format.
		if ( ! preg_match( '/^[a-zA-Z0-9._()-]+\.css$/', $file_name ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Security logging.
			error_log( "NBS3: Invalid filename rejected for S3 deletion: {$file_name}" );
			return false;
		}

		try {
			$client = $this->s3_provider->get_client();
			$client->deleteObject(
				array(
					'Bucket' => $this->s3_provider->get_bucket(),
					'Key'    => $this->s3_prefix . $file_name,
				)
			);

			$this->unmark_file_synced( $file_name );
			return true;
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
			error_log( "NBS3: Failed to delete Bricks CSS from S3: {$file_name}" );
			return false;
		}
	}

	/**
	 * Remove all Bricks CSS files from S3.
	 *
	 * @since 1.0.0
	 *
	 * @return array{deleted: int, errors: int} Removal results.
	 */
	public function remove_all_from_s3(): array {
		$synced_files = $this->get_synced_files();
		$deleted      = 0;
		$errors       = 0;

		foreach ( $synced_files as $file => $data ) {
			if ( $this->delete_from_s3( $file ) ) {
				++$deleted;
			} else {
				++$errors;
			}
		}

		// Clear the synced files option.
		delete_option( 'nbs3_synced_bricks_files' );

		return array(
			'deleted' => $deleted,
			'errors'  => $errors,
		);
	}

	/**
	 * Scan local Bricks CSS directory.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, int> Array of filenames mapped to modification times.
	 */
	public function scan_local_files(): array {
		$files = array();

		if ( ! is_dir( $this->local_path ) ) {
			return $files;
		}

		// Security: Validate that local_path is within wp-content/uploads.
		$upload_dir    = wp_upload_dir();
		$expected_base = realpath( $upload_dir['basedir'] );
		$actual_path   = realpath( $this->local_path );

		if ( false === $actual_path || false === $expected_base || 0 !== strpos( $actual_path, $expected_base ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Security logging.
			error_log( "NBS3: Invalid Bricks CSS path detected: {$this->local_path}" );
			return $files;
		}

		// Use DirectoryIterator for safer file scanning.
		try {
			$iterator = new \DirectoryIterator( $this->local_path );

			foreach ( $iterator as $fileinfo ) {
				if ( $fileinfo->isDot() || $fileinfo->isDir() ) {
					continue;
				}

				$filename = $fileinfo->getFilename();

				// Only include valid CSS files.
				if ( ! preg_match( '/^[a-zA-Z0-9._()-]+\.css$/', $filename ) ) {
					continue;
				}

				// Verify it's a regular readable file.
				if ( ! $fileinfo->isFile() || ! $fileinfo->isReadable() ) {
					continue;
				}

				$files[ $filename ] = $fileinfo->getMTime();
			}
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
			error_log( 'NBS3: Error scanning Bricks CSS directory: ' . $e->getMessage() );
			return $files;
		}

		return $files;
	}

	/**
	 * Get synced files from options.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{mtime: int, synced_at: int}> Array of synced file data.
	 */
	public function get_synced_files(): array {
		return get_option( 'nbs3_synced_bricks_files', array() );
	}

	/**
	 * Mark a file as synced.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_name The CSS file name.
	 * @param int    $mtime     The file modification time.
	 * @return void
	 */
	public function mark_file_synced( string $file_name, int $mtime ): void {
		$synced_files               = $this->get_synced_files();
		$synced_files[ $file_name ] = array(
			'mtime'     => $mtime,
			'synced_at' => time(),
		);
		update_option( 'nbs3_synced_bricks_files', $synced_files );
	}

	/**
	 * Remove a file from the synced list.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_name The CSS file name.
	 * @return void
	 */
	public function unmark_file_synced( string $file_name ): void {
		$synced_files = $this->get_synced_files();
		if ( isset( $synced_files[ $file_name ] ) ) {
			unset( $synced_files[ $file_name ] );
			update_option( 'nbs3_synced_bricks_files', $synced_files );
		}
	}

	/**
	 * Check if a file is synced.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_name The CSS file name to check.
	 * @return bool True if file is synced and up to date, false otherwise.
	 */
	public function is_file_synced( string $file_name ): bool {
		// Security: Strip any directory components.
		$file_name = basename( $file_name );

		// Security: Validate filename format.
		if ( ! preg_match( '/^[a-zA-Z0-9._()-]+\.css$/', $file_name ) ) {
			return false;
		}

		$synced_files = $this->get_synced_files();
		if ( ! isset( $synced_files[ $file_name ] ) ) {
			return false;
		}

		// Also check if local file hasn't been modified since sync.
		$local_file = trailingslashit( $this->local_path ) . $file_name;
		if ( ! file_exists( $local_file ) ) {
			return false;
		}

		$local_mtime = filemtime( $local_file );
		return $synced_files[ $file_name ]['mtime'] >= $local_mtime;
	}

	/**
	 * Get sync status.
	 *
	 * @since 1.0.0
	 *
	 * @return array Sync status information.
	 */
	public function get_status(): array {
		return nbs3_get_bricks_sync_status();
	}
}
