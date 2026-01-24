<?php
/**
 * Bricks Theme Assets Sync Service.
 *
 * Handles syncing Bricks theme assets to S3 storage.
 *
 * @package NBS3
 * @since   1.0.0
 */

namespace NBS3\Services;

use NBS3\S3Provider;

/**
 * Service for syncing Bricks theme assets to S3.
 *
 * @since 1.0.0
 */
class BricksThemeAssetsSyncService {

	/**
	 * The S3 provider instance.
	 *
	 * @var S3Provider
	 */
	private S3Provider $s3_provider;

	/**
	 * Local path to Bricks assets directory.
	 *
	 * @var string
	 */
	private string $local_path;

	/**
	 * S3 prefix for Bricks theme assets.
	 *
	 * @var string
	 */
	private string $s3_prefix = 'themes/bricks/assets/';

	/**
	 * Constructor.
	 *
	 * @param S3Provider $s3_provider The S3 provider instance.
	 */
	public function __construct( S3Provider $s3_provider ) {
		$this->s3_provider = $s3_provider;
		$this->local_path  = get_template_directory() . '/assets/';
	}

	/**
	 * Full sync - upload all files from Bricks assets folder.
	 *
	 * @return array Sync results with uploaded, skipped, deleted, errors, and total_synced counts.
	 */
	public function full_sync(): array {
		$local_files  = $this->scan_local_files();
		$synced_files = $this->get_synced_files();

		$uploaded = 0;
		$skipped  = 0;
		$errors   = 0;

		foreach ( $local_files as $relative_path => $mtime ) {
			// Check if file needs upload (new or modified).
			if ( isset( $synced_files[ $relative_path ] ) && $synced_files[ $relative_path ]['mtime'] >= $mtime ) {
				++$skipped;
				continue;
			}

			if ( $this->upload_file( $relative_path ) ) {
				$this->mark_file_synced( $relative_path, $mtime );
				++$uploaded;
			} else {
				++$errors;
			}
		}

		// Clean up files that no longer exist locally.
		$deleted = 0;
		foreach ( $synced_files as $relative_path => $data ) {
			if ( ! isset( $local_files[ $relative_path ] ) ) {
				if ( $this->delete_from_s3( $relative_path ) ) {
					++$deleted;
				}
			}
		}

		return array(
			'uploaded'     => $uploaded,
			'skipped'      => $skipped,
			'deleted'      => $deleted,
			'errors'       => $errors,
			'total_synced' => count( $this->get_synced_files() ),
		);
	}

	/**
	 * Upload a single file to S3.
	 *
	 * @param string $relative_path The relative path of the file to upload.
	 * @return bool True on success, false on failure.
	 */
	private function upload_file( string $relative_path ): bool {
		$local_file = $this->local_path . $relative_path;

		if ( ! file_exists( $local_file ) || ! is_readable( $local_file ) ) {
			return false;
		}

		$s3_key = $this->s3_prefix . $relative_path;
		$result = $this->s3_provider->upload_file( $local_file, $s3_key );

		return false !== $result;
	}

	/**
	 * Delete a file from S3.
	 *
	 * @param string $relative_path The relative path of the file to delete.
	 * @return bool True on success, false on failure.
	 */
	public function delete_from_s3( string $relative_path ): bool {
		try {
			$client = $this->s3_provider->get_client();
			$client->deleteObject(
				array(
					'Bucket' => $this->s3_provider->get_bucket(),
					'Key'    => $this->s3_prefix . $relative_path,
				)
			);

			$this->unmark_file_synced( $relative_path );
			return true;
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
			error_log( "NBS3: Failed to delete Bricks theme asset from S3: {$relative_path}" );
			return false;
		}
	}

	/**
	 * Remove all Bricks theme assets from S3.
	 *
	 * @return array Results with deleted and errors counts.
	 */
	public function remove_all_from_s3(): array {
		$synced_files = $this->get_synced_files();
		$deleted      = 0;
		$errors       = 0;

		foreach ( $synced_files as $relative_path => $data ) {
			if ( $this->delete_from_s3( $relative_path ) ) {
				++$deleted;
			} else {
				++$errors;
			}
		}

		// Clear the synced files option.
		delete_option( 'nbs3_synced_bricks_theme_assets' );

		return array(
			'deleted' => $deleted,
			'errors'  => $errors,
		);
	}

	/**
	 * Scan local Bricks assets directory recursively.
	 *
	 * @return array Associative array of relative paths to modification times.
	 */
	public function scan_local_files(): array {
		$files = array();

		if ( ! is_dir( $this->local_path ) ) {
			return $files;
		}

		// Verify path is within themes directory.
		$themes_dir  = realpath( get_theme_root() );
		$actual_path = realpath( $this->local_path );

		if ( false === $actual_path || false === $themes_dir || 0 !== strpos( $actual_path, $themes_dir ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Security logging.
			error_log( "NBS3: Invalid Bricks theme assets path: {$this->local_path}" );
			return $files;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->local_path, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $fileinfo ) {
				if ( ! $fileinfo->isFile() || ! $fileinfo->isReadable() ) {
					continue;
				}

				// Get relative path from assets folder.
				$full_path     = $fileinfo->getPathname();
				$relative_path = str_replace( $this->local_path, '', $full_path );
				$relative_path = ltrim( $relative_path, '/\\' );

				// Normalize path separators.
				$relative_path = str_replace( '\\', '/', $relative_path );

				// Skip hidden files and common non-essential files.
				$filename = $fileinfo->getFilename();
				if ( 0 === strpos( $filename, '.' ) ) {
					continue;
				}

				$files[ $relative_path ] = $fileinfo->getMTime();
			}
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
			error_log( 'NBS3: Error scanning Bricks theme assets: ' . $e->getMessage() );
			return $files;
		}

		return $files;
	}

	/**
	 * Get synced files from options.
	 *
	 * @return array Associative array of synced file data.
	 */
	public function get_synced_files(): array {
		return get_option( 'nbs3_synced_bricks_theme_assets', array() );
	}

	/**
	 * Mark a file as synced.
	 *
	 * @param string $relative_path The relative path of the file.
	 * @param int    $mtime         The modification time of the file.
	 * @return void
	 */
	private function mark_file_synced( string $relative_path, int $mtime ): void {
		$synced_files                   = $this->get_synced_files();
		$synced_files[ $relative_path ] = array(
			'mtime'     => $mtime,
			'synced_at' => time(),
		);
		update_option( 'nbs3_synced_bricks_theme_assets', $synced_files, false );
	}

	/**
	 * Remove a file from the synced list.
	 *
	 * @param string $relative_path The relative path of the file.
	 * @return void
	 */
	private function unmark_file_synced( string $relative_path ): void {
		$synced_files = $this->get_synced_files();
		if ( isset( $synced_files[ $relative_path ] ) ) {
			unset( $synced_files[ $relative_path ] );
			update_option( 'nbs3_synced_bricks_theme_assets', $synced_files, false );
		}
	}

	/**
	 * Check if a file is synced.
	 *
	 * @param string $relative_path The relative path of the file.
	 * @return bool True if the file is synced, false otherwise.
	 */
	public function is_file_synced( string $relative_path ): bool {
		$synced_files = $this->get_synced_files();
		return isset( $synced_files[ $relative_path ] );
	}

	/**
	 * Get sync status.
	 *
	 * @return array Status with total, synced, and pending counts.
	 */
	public function get_status(): array {
		$local_files  = $this->scan_local_files();
		$synced_files = $this->get_synced_files();

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
