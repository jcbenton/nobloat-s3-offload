<?php
/**
 * Unique Filename Observer.
 *
 * Ensures uploaded files have unique names in cloud storage by checking
 * if the filename already exists and appending a counter if needed.
 *
 * @package NoBloat_S3_Offload
 * @since   1.0.0
 */

namespace NBS3\Observers;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Traits\OffloaderTrait;

/**
 * Observer class that filters wp_unique_filename to check cloud storage.
 *
 * When full cloud migration is enabled (retention policy = 2), this observer
 * ensures that uploaded files have unique names in the cloud storage bucket
 * rather than just the local filesystem.
 *
 * @since 1.0.0
 */
class UniqueFilenameObserver implements ObserverInterface {

	use OffloaderTrait;

	/**
	 * The S3 provider instance for cloud operations.
	 *
	 * @since 1.0.0
	 * @var S3Provider
	 */
	private S3Provider $s3_provider;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param S3Provider $s3_provider The S3 provider instance.
	 */
	public function __construct( S3Provider $s3_provider ) {
		$this->s3_provider = $s3_provider;
	}

	/**
	 * Register the observer hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_unique_filename', array( $this, 'filter' ), 10, 3 );
	}

	/**
	 * Filter the unique filename to check cloud storage.
	 *
	 * @since 1.0.0
	 *
	 * @param string $filename The filename.
	 * @param string $ext      The file extension.
	 * @param string $dir      The directory path.
	 * @return string The unique filename.
	 */
	public function filter( $filename, $ext, $dir ) {
		// Only run when full cloud migration is enabled (retention policy = 2).
		if ( ! $this->is_full_cloud_migration_enabled() ) {
			return $filename;
		}

		// Get the base filename without extension.
		$name      = pathinfo( $filename, PATHINFO_FILENAME );
		$extension = pathinfo( $filename, PATHINFO_EXTENSION );

		// If no extension was passed but filename has one, use it.
		if ( empty( $ext ) && ! empty( $extension ) ) {
			$ext = '.' . $extension;
		}

		// Construct the cloud path for this file.
		$cloud_path = $this->get_cloud_path_for_file( $filename, $dir );

		// Check if file exists in cloud and make it unique if needed.
		$unique_filename = $this->make_cloud_filename_unique( $name, $ext, $cloud_path );

		return $unique_filename;
	}

	/**
	 * Check if full cloud migration is enabled (retention policy = 2).
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if full cloud migration is enabled.
	 */
	private function is_full_cloud_migration_enabled(): bool {
		$settings         = get_option( 'nbs3_settings', array() );
		$retention_policy = isset( $settings['retention_policy'] ) ? intval( $settings['retention_policy'] ) : 0;

		return 2 === $retention_policy;
	}

	/**
	 * Get the cloud path where this file would be stored.
	 *
	 * @since 1.0.0
	 *
	 * @param string $filename  The filename.
	 * @param string $local_dir The local directory path.
	 * @return string The cloud path.
	 */
	private function get_cloud_path_for_file( string $filename, string $local_dir ): string {
		// Get path prefix if enabled.
		$path_prefix = $this->get_path_prefix();

		// Get object version if enabled.
		$object_version = $this->get_object_version_for_new_file();

		// Determine if media is organized by year/month.
		$is_organized_by_date = nbs3_is_media_organized_by_year_month();

		if ( $is_organized_by_date ) {
			// Extract year/month from local directory.
			$upload_dir    = wp_upload_dir();
			$base_dir      = trailingslashit( $upload_dir['basedir'] );
			$relative_path = str_replace( $base_dir, '', trailingslashit( $local_dir ) );
			return $path_prefix . $relative_path . $object_version;
		}

		return $path_prefix . $object_version;
	}

	/**
	 * Get path prefix for new files.
	 *
	 * @since 1.0.0
	 *
	 * @return string The path prefix or empty string if not enabled.
	 */
	private function get_path_prefix(): string {
		$settings      = get_option( 'nbs3_settings', array() );
		$prefix_active = $settings['path_prefix_active'] ?? false;
		$path_prefix   = $settings['path_prefix'] ?? '';

		if ( ! $prefix_active || empty( $path_prefix ) ) {
			return '';
		}

		return trailingslashit( nbs3_sanitize_path( $path_prefix ) );
	}

	/**
	 * Get object version for new files.
	 *
	 * @since 1.0.0
	 *
	 * @return string The object version path or empty string if not enabled.
	 */
	private function get_object_version_for_new_file(): string {
		$settings          = get_option( 'nbs3_settings', array() );
		$object_versioning = isset( $settings['object_versioning'] ) ? $settings['object_versioning'] : '0';

		// If versioning is not enabled, return empty string.
		if ( ! $object_versioning ) {
			return '';
		}

		// Generate a new version timestamp.
		if ( ! nbs3_is_media_organized_by_year_month() ) {
			$new_version = gmdate( 'YmdHis' );
		} else {
			$new_version = gmdate( 'dHis' );
		}

		return trailingslashit( $new_version );
	}

	/**
	 * Make filename unique by checking cloud storage and appending numbers if needed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name       The base filename without extension.
	 * @param string $ext        The file extension including the dot.
	 * @param string $cloud_path The cloud storage path.
	 * @return string The unique filename.
	 */
	private function make_cloud_filename_unique( string $name, string $ext, string $cloud_path ): string {
		$original_filename = $name . $ext;
		$filename          = $original_filename;
		$counter           = 1;

		// Keep checking until we find a unique filename.
		while ( $this->s3_provider->object_exists( $cloud_path . $filename ) ) {
			$filename = $name . '-' . $counter . $ext;
			++$counter;

			// Safety check to prevent infinite loops.
			if ( 10 < $counter ) {
				// Fallback to timestamp-based uniqueness.
				$filename = $name . '-' . time() . $ext;
				break;
			}
		}

		return $filename;
	}
}
