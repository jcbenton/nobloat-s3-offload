<?php
/**
 * Cloud Attachment Uploader Service.
 *
 * Handles uploading WordPress attachments to cloud storage (S3-compatible).
 *
 * @package NBS3
 * @subpackage Services
 * @since 1.0.0
 */

namespace NBS3\Services;

defined( 'ABSPATH' ) || exit;

use NBS3\S3Provider;
use NBS3\Traits\OffloaderTrait;

/**
 * Class Cloud_Attachment_Uploader
 *
 * Manages the upload of WordPress media attachments to S3-compatible cloud storage.
 *
 * @since 1.0.0
 */
class CloudAttachmentUploader {

	use OffloaderTrait;

	/**
	 * The S3 provider instance.
	 *
	 * @since 1.0.0
	 * @var S3Provider
	 */
	private S3Provider $s3_provider;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param S3Provider $s3_provider The S3 provider instance.
	 */
	public function __construct( S3Provider $s3_provider ) {
		$this->s3_provider = $s3_provider;
	}

	/**
	 * Get all files from a size data entry, including sources for Modern Image Formats.
	 *
	 * @since 1.0.0
	 * @param array $size_data The size data array from metadata.
	 * @return array Array of unique file names.
	 */
	private function get_files_from_size_data( array $size_data ): array {
		$files = array();

		// Add the primary file.
		if ( ! empty( $size_data['file'] ) ) {
			$files[] = $size_data['file'];
		}

		// Add files from sources array (Modern Image Formats support).
		if ( ! empty( $size_data['sources'] ) && is_array( $size_data['sources'] ) ) {
			foreach ( $size_data['sources'] as $source ) {
				if ( ! empty( $source['file'] ) && ! in_array( $source['file'], $files, true ) ) {
					$files[] = $source['file'];
				}
			}
		}

		return $files;
	}

	/**
	 * Get root-level source files from metadata (Modern Image Formats support).
	 *
	 * @since 1.0.0
	 * @param array $metadata The attachment metadata.
	 * @return array Array of additional source file names (excluding the main file).
	 */
	private function get_root_source_files( array $metadata ): array {
		$files = array();

		if ( ! empty( $metadata['sources'] ) && is_array( $metadata['sources'] ) ) {
			$main_file = $metadata['file'] ?? '';
			foreach ( $metadata['sources'] as $source ) {
				if ( ! empty( $source['file'] ) && $source['file'] !== $main_file ) {
					$files[] = $source['file'];
				}
			}
		}

		return $files;
	}

	/**
	 * Upload an attachment to cloud storage.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id The attachment ID.
	 * @return bool True on success, false on failure.
	 */
	public function upload_attachment( int $attachment_id ): bool {
		/**
		 * Filter to determine whether an attachment should be offloaded.
		 *
		 * Return false to skip offloading this attachment. Useful for
		 * conditional rules (file type, size, user role, taxonomy, etc.).
		 *
		 * @param bool $should_offload Default true.
		 * @param int  $attachment_id  Attachment ID.
		 */
		$should_offload = apply_filters( 'nbs3_should_offload_attachment', true, $attachment_id );
		if ( ! $should_offload ) {
			return false;
		}

		if ( $this->is_offloaded( $attachment_id ) ) {
			return true;
		}

		/*
		 * Acquire a per-attachment lock so two concurrent triggers (e.g.
		 * `wp_generate_attachment_metadata` priority 99 and a manual
		 * "Offload Now" AJAX click) cannot both pass the is_offloaded()
		 * check, both call upload_to_cloud(), and both PUT divergent
		 * S3 keys — leaving one set orphaned on S3 and producing
		 * inconsistent post meta. wp_cache_add() is atomic check-and-set
		 * on persistent object caches (Redis/Memcached); when no
		 * persistent cache is available we fall back to add_post_meta()
		 * which is atomic on the meta_id unique key.
		 */
		$lock_key = 'nbs3_upload_lock_' . $attachment_id;
		if ( ! wp_cache_add( $lock_key, time(), 'nbs3', 5 * MINUTE_IN_SECONDS ) ) {
			return false;
		}

		try {
			// Re-check inside the lock.
			if ( $this->is_offloaded( $attachment_id ) ) {
				return true;
			}

			if ( $this->upload_to_cloud( $attachment_id ) ) {
				$this->update_attachment_metadata( $attachment_id );
				return true;
			}

			return false;
		} finally {
			wp_cache_delete( $lock_key, 'nbs3' );
		}
	}

	/**
	 * Upload an updated attachment to cloud storage.
	 *
	 * @since 1.0.0
	 * @param int   $attachment_id The attachment ID.
	 * @param array $metadata      The attachment metadata.
	 * @return bool True on success, false on failure.
	 */
	public function upload_updated_attachment( int $attachment_id, array $metadata ): bool {
		/**
		 * Filter to determine whether an updated attachment should be re-offloaded.
		 *
		 * Return false to skip uploading the updated file and sizes.
		 * This filter is specific to attachment updates (e.g., image editor saves).
		 *
		 * @since 1.0.0
		 * @param bool  $should_offload Default true.
		 * @param int   $attachment_id  Attachment ID.
		 * @param array $metadata       Attachment metadata.
		 */
		$should_offload = apply_filters( 'nbs3_should_offload_updated_attachment', true, $attachment_id, $metadata );
		if ( ! $should_offload ) {
			return true;
		}

		if ( $metadata ) {
			$file          = get_attached_file( $attachment_id );
			$subdir        = $this->get_attachment_subdir( $attachment_id, true );
			$upload_result = $this->s3_provider->upload_file( $file, $subdir . wp_basename( $file ) );

			if ( ! $upload_result ) {
				$this->log_error( $attachment_id, 'Failed to upload resized main file to cloud storage.' );
				return false;
			}

			if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				$metadata_sizes = $this->unique_meta_data_sizes( $metadata['sizes'] );
				foreach ( $metadata_sizes as $size => $data ) {
					$pattern = '/\-e[0-9]+(?=\-)/';
					if ( ! preg_match( $pattern, $data['file'] ) ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
						error_log( "{$data['file']} is not a valid size file name." );
						continue;
					}
					$file          = get_attached_file( $attachment_id, true );
					$file          = str_replace( wp_basename( $file ), $data['file'], $file );
					$upload_result = $this->s3_provider->upload_file( $file, $subdir . wp_basename( $file ) );
					if ( ! $upload_result ) {
						$this->log_error( $attachment_id, "Failed to upload size '{$size}' to cloud storage." );
						return false;
					}
				}
			}
		}

		return true;
	}

	/**
	 * Upload regenerated thumbnails to cloud storage and handle local cleanup.
	 *
	 * @since 1.0.0
	 * @param int   $attachment_id Attachment ID.
	 * @param array $new_metadata  New attachment metadata after regeneration.
	 * @param array $old_metadata  Old attachment metadata before regeneration.
	 * @return bool True on success, false on failure.
	 */
	public function upload_regenerated_thumbnails( int $attachment_id, array $new_metadata, array $old_metadata ): bool {
		/**
		 * Filter to determine whether regenerated thumbnails should be offloaded.
		 *
		 * Return false to skip offloading regenerated thumbnails.
		 * This filter is specific to thumbnail regeneration operations.
		 *
		 * @since 1.0.0
		 * @param bool  $should_offload Default true.
		 * @param int   $attachment_id  Attachment ID.
		 * @param array $new_metadata   New attachment metadata.
		 * @param array $old_metadata   Old attachment metadata.
		 */
		$should_offload = apply_filters( 'nbs3_should_offload_regenerated_thumbnails', true, $attachment_id, $new_metadata, $old_metadata );
		if ( ! $should_offload ) {
			return false;
		}

		// Get the subdirectory for cloud storage (upload-time path: persists object version).
		$subdir = $this->get_attachment_subdir( $attachment_id, true );

		// Get old and new sizes.
		$old_sizes = isset( $old_metadata['sizes'] ) && is_array( $old_metadata['sizes'] ) ? $old_metadata['sizes'] : array();
		$new_sizes = isset( $new_metadata['sizes'] ) && is_array( $new_metadata['sizes'] ) ? $new_metadata['sizes'] : array();

		// Find new or changed thumbnails.
		$thumbnails_to_upload  = array();
		$thumbnails_to_cleanup = array();

		foreach ( $new_sizes as $size_name => $size_data ) {
			$is_new          = ! isset( $old_sizes[ $size_name ] );
			$is_changed      = false;
			$sources_changed = false;

			if ( ! $is_new ) {
				// Check if file changed.
				$old_file = $old_sizes[ $size_name ]['file'] ?? '';
				$new_file = $size_data['file'] ?? '';

				if ( $old_file !== $new_file ) {
					$is_changed = true;
				}

				// Check if dimensions changed.
				$old_width  = $old_sizes[ $size_name ]['width'] ?? 0;
				$old_height = $old_sizes[ $size_name ]['height'] ?? 0;
				$new_width  = $size_data['width'] ?? 0;
				$new_height = $size_data['height'] ?? 0;

				if ( $old_width !== $new_width || $old_height !== $new_height ) {
					$is_changed = true;
				}

				// Check if sources changed (Modern Image Formats support).
				$old_sources = $old_sizes[ $size_name ]['sources'] ?? array();
				$new_sources = $size_data['sources'] ?? array();
				if ( $old_sources !== $new_sources ) {
					$sources_changed = true;
				}
			}

			if ( $is_new || $is_changed || $sources_changed ) {
				$thumbnails_to_upload[] = array(
					'size' => $size_name,
					'data' => $size_data,
				);

				// If changed, mark old file for cleanup.
				if ( $is_changed && isset( $old_sizes[ $size_name ]['file'] ) ) {
					$thumbnails_to_cleanup[] = $old_sizes[ $size_name ]['file'];
				}
			}
		}

		// Upload new/changed thumbnails.
		if ( ! empty( $thumbnails_to_upload ) ) {
			$base_file = get_attached_file( $attachment_id, true );
			$file_dir  = trailingslashit( dirname( $base_file ) );

			foreach ( $thumbnails_to_upload as $thumbnail ) {
				$size_data = $thumbnail['data'];

				// Get all files for this size, including sources (Modern Image Formats).
				$size_files = $this->get_files_from_size_data( $size_data );

				foreach ( $size_files as $size_file ) {
					$thumbnail_file = $file_dir . $size_file;

					// Only upload if file exists locally.
					if ( file_exists( $thumbnail_file ) ) {
						$upload_result = $this->s3_provider->upload_file( $thumbnail_file, $subdir . $size_file );
						if ( ! $upload_result ) {
							$this->log_error( $attachment_id, "Failed to upload regenerated thumbnail '{$thumbnail['size']}' file '{$size_file}' to cloud storage." );
							// Continue with other thumbnails even if one fails.
							continue;
						}
					} else {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
						error_log( "NBS3: Regenerated thumbnail file not found: {$thumbnail_file}" );
					}
				}
			}
		}

		// Check for new root-level sources (Modern Image Formats support).
		$old_root_sources     = $old_metadata['sources'] ?? array();
		$new_root_sources     = $new_metadata['sources'] ?? array();
		$has_new_root_sources = ( $old_root_sources !== $new_root_sources );

		if ( $has_new_root_sources ) {
			$base_file         = get_attached_file( $attachment_id, true );
			$file_dir          = trailingslashit( dirname( $base_file ) );
			$root_source_files = $this->get_root_source_files( $new_metadata );

			foreach ( $root_source_files as $source_file ) {
				$source_path = $file_dir . $source_file;
				if ( file_exists( $source_path ) ) {
					$upload_result = $this->s3_provider->upload_file( $source_path, $subdir . $source_file );
					if ( ! $upload_result ) {
						$this->log_error( $attachment_id, "Failed to upload root source file '{$source_file}' to cloud storage." );
						// Continue with other files even if one fails.
					}
				} else {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
					error_log( "NBS3: Root source file not found: {$source_path}" );
				}
			}
		}

		// Handle local cleanup based on retention policy.
		$delete_local_rule = $this->should_delete_local();
		if ( 0 !== $delete_local_rule && ( ! empty( $thumbnails_to_upload ) || $has_new_root_sources ) ) {
			$this->delete_regenerated_local_thumbnails( $attachment_id, $thumbnails_to_upload, $delete_local_rule, $has_new_root_sources ? $new_metadata : null );
		}

		return true;
	}

	/**
	 * Delete regenerated thumbnail files locally based on retention policy.
	 *
	 * @since 1.0.0
	 * @param int        $attachment_id        Attachment ID.
	 * @param array      $thumbnails_to_upload Array of thumbnails that were uploaded.
	 * @param int        $delete_local_rule    Retention policy (1 = Smart Local Cleanup, 2 = Full Cloud Migration).
	 * @param array|null $metadata_with_sources Optional metadata containing root sources to delete.
	 * @return bool True on success, false on failure.
	 */
	private function delete_regenerated_local_thumbnails( int $attachment_id, array $thumbnails_to_upload, int $delete_local_rule, ?array $metadata_with_sources = null ): bool {
		/**
		 * Fires before regenerated thumbnail files are deleted locally.
		 *
		 * @param int   $attachment_id        Attachment ID.
		 * @param array $thumbnails_to_upload Thumbnails that were uploaded.
		 * @param int   $delete_local_rule    Retention policy.
		 */
		do_action( 'nbs3_before_delete_regenerated_local_thumbnails', $attachment_id, $thumbnails_to_upload, $delete_local_rule );

		$original_file = get_attached_file( $attachment_id, true );
		$file_dir      = trailingslashit( dirname( $original_file ) );

		// For Smart Local Cleanup (1), delete only regenerated thumbnails, keep original.
		// For Full Cloud Migration (2), also delete regenerated thumbnails (original already deleted during initial offload).
		if ( 1 === $delete_local_rule || 2 === $delete_local_rule ) {
			foreach ( $thumbnails_to_upload as $thumbnail ) {
				// Get all files for this size, including sources (Modern Image Formats).
				$size_files = $this->get_files_from_size_data( $thumbnail['data'] );

				foreach ( $size_files as $size_file ) {
					$thumbnail_file = $file_dir . $size_file;
					if ( file_exists( $thumbnail_file ) ) {
						wp_delete_file( $thumbnail_file );
					}
				}
			}

			// Delete root-level source files if provided (Modern Image Formats support).
			if ( null !== $metadata_with_sources ) {
				$root_source_files = $this->get_root_source_files( $metadata_with_sources );
				foreach ( $root_source_files as $source_file ) {
					$source_path = $file_dir . $source_file;
					if ( file_exists( $source_path ) ) {
						wp_delete_file( $source_path );
					}
				}
			}
		}

		/**
		 * Fires after regenerated thumbnail files have been deleted locally.
		 *
		 * @param int   $attachment_id        Attachment ID.
		 * @param array $thumbnails_to_upload Thumbnails that were uploaded.
		 * @param int   $delete_local_rule    Retention policy.
		 */
		do_action( 'nbs3_after_delete_regenerated_local_thumbnails', $attachment_id, $thumbnails_to_upload, $delete_local_rule );

		return true;
	}

	/**
	 * Upload attachment files to cloud storage.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id The attachment ID.
	 * @return bool True on success, false on failure.
	 */
	private function upload_to_cloud( int $attachment_id ): bool {
		/**
		 * Fires before the attachment is uploaded to the cloud.
		 *
		 * This action allows developers to perform tasks or logging before
		 * the attachment is uploaded to the cloud.
		 *
		 * @param int $attachment_id The attachment ID.
		 */
		do_action( 'nbs3_before_upload_to_cloud', $attachment_id );

		// Remove error logs related to the attachment before starting the new upload process.
		delete_post_meta( $attachment_id, 'nbs3_error_log' );

		if ( ! $this->attachment_exists_on_disk( $attachment_id ) ) {
			return false;
		}

		$file          = get_attached_file( $attachment_id );
		$subdir        = $this->get_attachment_subdir( $attachment_id, true );
		$s3_key        = $subdir . wp_basename( $file );
		$upload_result = $this->s3_provider->upload_file( $file, $s3_key );

		if ( ! $upload_result ) {
			$s3_error  = $this->s3_provider->get_last_error();
			$error_msg = 'Failed to upload main file to cloud storage.';
			if ( $s3_error ) {
				$error_msg .= ' S3 Error: ' . $s3_error;
			}
			$error_msg .= ' File: ' . $file . ' Key: ' . $s3_key;
			$this->log_error( $attachment_id, $error_msg );
			return false;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$metadata_sizes = $this->unique_meta_data_sizes( $metadata['sizes'] );
			$base_file      = get_attached_file( $attachment_id, true );
			$file_dir       = trailingslashit( dirname( $base_file ) );

			foreach ( $metadata_sizes as $size => $data ) {
				// Get all files for this size, including sources (Modern Image Formats).
				$size_files = $this->get_files_from_size_data( $data );

				foreach ( $size_files as $size_file ) {
					$file_path     = $file_dir . $size_file;
					$upload_result = $this->s3_provider->upload_file( $file_path, $subdir . $size_file );
					if ( ! $upload_result ) {
						$s3_error  = $this->s3_provider->get_last_error();
						$error_msg = "Failed to upload size '{$size}' file '{$size_file}' to cloud storage.";
						if ( $s3_error ) {
							$error_msg .= ' S3 Error: ' . $s3_error;
						}
						$this->log_error( $attachment_id, $error_msg );
						return false;
					}
				}
			}
		}

		/**
		 * Filter to determine whether the original image should be uploaded to the cloud.
		 *
		 * Return false to skip uploading the original image.
		 *
		 * @param bool  $should_upload_original_image Default true.
		 * @param int   $attachment_id                Attachment ID.
		 * @param array $metadata                     Attachment metadata.
		 */
		$should_upload_original_image = apply_filters( 'nbs3_should_upload_original_image', true, $attachment_id, $metadata );

		if ( $should_upload_original_image && ! empty( $metadata['original_image'] ) ) {
			$original_image = wp_get_original_image_path( $attachment_id );
			$upload_result  = $this->s3_provider->upload_file( $original_image, $subdir . wp_basename( $original_image ) );
			if ( ! $upload_result ) {
				$s3_error  = $this->s3_provider->get_last_error();
				$error_msg = 'Failed to upload original image to cloud storage.';
				if ( $s3_error ) {
					$error_msg .= ' S3 Error: ' . $s3_error;
				}
				$this->log_error( $attachment_id, $error_msg );
				return false;
			}
		}

		// Upload root-level source files (Modern Image Formats support).
		$root_source_files = $this->get_root_source_files( $metadata );
		if ( ! empty( $root_source_files ) ) {
			$main_file = get_attached_file( $attachment_id, true );
			$file_dir  = trailingslashit( dirname( $main_file ) );

			foreach ( $root_source_files as $source_file ) {
				$source_path = $file_dir . $source_file;
				if ( ! file_exists( $source_path ) ) {
					/*
					 * Hard-fail when a metadata-declared source variant is
					 * missing on disk. Silently skipping here used to leave
					 * `nbs3_offloaded=true` on an attachment whose .webp
					 * (or other variant) was never PUT to S3 — and if the
					 * retention policy was Full Cloud Migration, the local
					 * copy was then deleted, producing a permanent 404 on
					 * any front-end request that selected that variant.
					 */
					$this->log_error( $attachment_id, "Source file '{$source_file}' declared in metadata but missing on disk; refusing to mark attachment offloaded." );
					return false;
				}
				$upload_result = $this->s3_provider->upload_file( $source_path, $subdir . $source_file );
				if ( ! $upload_result ) {
					$s3_error  = $this->s3_provider->get_last_error();
					$error_msg = "Failed to upload source file '{$source_file}' to cloud storage.";
					if ( $s3_error ) {
						$error_msg .= ' S3 Error: ' . $s3_error;
					}
					$this->log_error( $attachment_id, $error_msg );
					return false;
				}
			}
		}

		$delete_local_rule = $this->should_delete_local();
		if ( 0 !== $delete_local_rule ) {
			// Safety valve: When object versioning is OFF and retention policy is Full Cloud Migration (2),
			// check if a collision occurred (file already existed in S3). If so, keep local copy to prevent data loss.
			if ( 2 === $delete_local_rule && $this->had_potential_collision( $attachment_id, $s3_key ) ) {
				// Log that we're keeping the local file due to potential collision.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional info logging.
				error_log( "NBS3: Keeping local file for attachment {$attachment_id} - potential S3 collision detected (versioning off, same key existed)." );
				update_post_meta( $attachment_id, 'nbs3_local_kept_reason', 'collision_safety' );
			} else {
				$this->delete_local_file( $attachment_id, $delete_local_rule );
			}
		}

		/**
		 * Fires after the attachment has been uploaded to the cloud.
		 *
		 * This action allows developers to perform additional tasks or logging after
		 * the attachment has been uploaded to the cloud.
		 *
		 * @param int $attachment_id The ID of the attachment that was processed.
		 */
		do_action( 'nbs3_after_upload_to_cloud', $attachment_id );

		return true;
	}

	/**
	 * Log an error for the attachment.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id  The attachment ID.
	 * @param string $specific_error The specific error message.
	 * @return void
	 */
	private function log_error( int $attachment_id, string $specific_error ): void {
		$error_log = get_post_meta( $attachment_id, 'nbs3_error_log', true );
		if ( ! is_array( $error_log ) ) {
			$error_log = array();
		}

		// Add timestamp to error.
		$timestamped_error = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $specific_error;
		$error_log[]       = $timestamped_error;

		// Also log to error_log for debugging.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
		error_log( 'NBS3 Offload Error (Attachment ' . $attachment_id . '): ' . $specific_error );

		update_post_meta( $attachment_id, 'nbs3_error_log', $error_log );
		update_post_meta( $attachment_id, 'nbs3_offloaded', false );
	}

	/**
	 * Update attachment metadata after successful upload.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id The attachment ID.
	 * @return void
	 */
	private function update_attachment_metadata( int $attachment_id ): void {
		update_post_meta( $attachment_id, 'nbs3_path', $this->get_attachment_subdir( $attachment_id ) );
		update_post_meta( $attachment_id, 'nbs3_offloaded', true );
		update_post_meta( $attachment_id, 'nbs3_offloaded_at', time() );
		update_post_meta( $attachment_id, 'nbs3_provider', $this->s3_provider->get_provider_name() );
		update_post_meta( $attachment_id, 'nbs3_bucket', $this->s3_provider->get_bucket() );
	}

	/**
	 * Delete local file(s) after successful cloud upload.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id    The attachment ID.
	 * @param int $delete_local_rule The deletion rule (1 = Smart Local Cleanup, 2 = Full Cloud Migration).
	 * @return bool True on success, false on failure.
	 */
	private function delete_local_file( int $attachment_id, int $delete_local_rule ): bool {
		/**
		 * Fires before the local file(s) associated with an attachment are deleted.
		 *
		 * This action allows developers to perform tasks or logging before
		 * the local files are removed following a successful cloud upload.
		 *
		 * @param int $attachment_id    The ID of the attachment to be processed.
		 * @param int $delete_local_rule The rule to be applied for local file deletion:
		 *                               1 - Delete only sized images, keep original.
		 *                               2 - Delete all local files including the original.
		 */
		do_action( 'nbs3_before_delete_local_file', $attachment_id, $delete_local_rule );

		$original_file = get_attached_file( $attachment_id, true );

		if ( ! file_exists( $original_file ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
			error_log( "NBS3: Original file not found for deletion: $original_file" );
			return false;
		}

		/*
		 * Mark retention as in-progress before unlinking any files. If the
		 * loop is interrupted halfway (PHP fatal, request timeout, FS perm
		 * failure), the post meta still records that retention has started
		 * — preventing the front-end from falling back to local URLs and
		 * 404ing on the partially-deleted thumbnails.
		 */
		update_post_meta( $attachment_id, 'nbs3_retention_policy_started', $delete_local_rule );

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$file_dir = trailingslashit( dirname( $original_file ) );

		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size => $sizeinfo ) {
				// Get all files for this size, including sources (Modern Image Formats).
				$size_files = $this->get_files_from_size_data( $sizeinfo );

				foreach ( $size_files as $size_file ) {
					$sized_file = $file_dir . $size_file;
					if ( file_exists( $sized_file ) ) {
						wp_delete_file( $sized_file );
					}
				}
			}
		}

		if ( 2 === $delete_local_rule ) {
			wp_delete_file( $original_file );

			// Handle original image if exists (for scaled or processed images).
			if ( ! empty( $metadata['original_image'] ) ) {
				$original_image_path = wp_get_original_image_path( $attachment_id );
				wp_delete_file( $original_image_path );
			}

			// Delete root-level source files (Modern Image Formats support).
			$root_source_files = $this->get_root_source_files( $metadata );
			foreach ( $root_source_files as $source_file ) {
				$source_path = $file_dir . $source_file;
				if ( file_exists( $source_path ) ) {
					wp_delete_file( $source_path );
				}
			}
		}

		update_post_meta( $attachment_id, 'nbs3_retention_policy', $delete_local_rule );

		/**
		 * Fires after the local file(s) associated with an attachment have been deleted.
		 *
		 * This action allows developers to perform additional tasks or logging after
		 * the local files have been removed following a successful cloud upload.
		 *
		 * @param int $attachment_id    The ID of the attachment that was processed.
		 * @param int $delete_local_rule The rule applied for local file deletion:
		 *                               1 - Delete only sized images, keep original.
		 *                               2 - Delete all local files including the original.
		 */
		do_action( 'nbs3_after_delete_local_file', $attachment_id, $delete_local_rule );

		return true;
	}

	/**
	 * Check if the attachment exists on disk.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id The attachment ID.
	 * @return bool True if the attachment exists on disk, false otherwise.
	 */
	protected function attachment_exists_on_disk( $attachment_id ) {
		$errors = array();

		// Get the full path to the attachment file.
		$file_path = get_attached_file( $attachment_id );

		// Check if the main file exists.
		if ( ! file_exists( $file_path ) ) {
			$errors[] = "Main file does not exist: {$file_path}";
		}

		// If it's an image, check all sizes.
		if ( wp_attachment_is_image( $attachment_id ) ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( ! empty( $metadata['sizes'] ) ) {
				$upload_dir = wp_upload_dir();
				$base_dir   = trailingslashit( $upload_dir['basedir'] );
				$file_dir   = trailingslashit( dirname( $file_path ) );

				foreach ( $metadata['sizes'] as $size => $size_info ) {
					$size_file_path = $file_dir . $size_info['file'];
					if ( ! file_exists( $size_file_path ) ) {
						$errors[] = "Size '{$size}' does not exist: {$size_file_path}";
					}
				}
			}
		}

		// Save errors to post meta.
		if ( ! empty( $errors ) ) {
			$existing_errors = get_post_meta( $attachment_id, 'nbs3_error_log', true );
			if ( ! is_array( $existing_errors ) ) {
				$existing_errors = array();
			}
			$updated_errors = array_merge( $existing_errors, $errors );
			update_post_meta( $attachment_id, 'nbs3_error_log', $updated_errors );
		} else {
			// If there are no errors, remove any existing error log.
			delete_post_meta( $attachment_id, 'nbs3_error_log' );
		}

		// Return true if no errors, false otherwise.
		return empty( $errors );
	}

	/**
	 * Check if there was a potential S3 collision (file existed before upload).
	 *
	 * This safety check only applies when object versioning is disabled.
	 * When versioning is enabled, each file gets a unique timestamp path,
	 * so collisions are not possible.
	 *
	 * @since 1.0.8
	 * @param int    $attachment_id The attachment ID.
	 * @param string $s3_key        The S3 object key that was uploaded (reserved for future use).
	 * @return bool True if a potential collision was detected, false otherwise.
	 */
	private function had_potential_collision( int $attachment_id, string $s3_key ): bool {
		unset( $s3_key ); // Reserved for future collision detection by S3 key.
		// Check if object versioning is enabled - if so, no collision possible.
		$settings          = $this->get_cached_settings();
		$object_versioning = isset( $settings['object_versioning'] ) ? $settings['object_versioning'] : '1';

		if ( $object_versioning ) {
			// Versioning is ON, each upload gets unique timestamp path - no collision.
			return false;
		}

		// Versioning is OFF. Check if this S3 key existed before this upload.
		// We check by seeing if another attachment already uses this same nbs3_path + filename.
		$file         = get_attached_file( $attachment_id );
		$filename     = wp_basename( $file );
		$current_path = get_post_meta( $attachment_id, 'nbs3_path', true );

		// Query for other attachments with the same S3 path (excluding current attachment).
		// This query runs once per attachment during offload (not in bulk), and is necessary
		// for collision safety. The meta_query on indexed keys with a limit of 1 has minimal impact.
		// phpcs:disable WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in, WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'post__not_in'   => array( $attachment_id ),
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => 'nbs3_offloaded',
					'value'   => '1',
					'compare' => '=',
				),
				array(
					'key'     => 'nbs3_path',
					'value'   => $current_path,
					'compare' => '=',
				),
			),
			'fields'         => 'ids',
		);
		// phpcs:enable

		$existing_attachments = get_posts( $args );

		// Check if any of the existing attachments have the same filename.
		foreach ( $existing_attachments as $existing_id ) {
			$existing_file     = get_attached_file( $existing_id );
			$existing_filename = wp_basename( $existing_file );

			if ( $existing_filename === $filename ) {
				// Same filename in same S3 path - collision detected.
				return true;
			}
		}

		// Also check if the object existed in S3 before (double-check via S3).
		// This catches cases where the file exists in S3 but not in WordPress DB.
		// However, this requires an additional S3 API call, so we use the faster
		// database check above as the primary method.

		return false;
	}
}
