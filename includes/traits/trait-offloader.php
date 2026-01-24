<?php
/**
 * Offloader Trait
 *
 * Provides common offloading functionality for attachment handling.
 *
 * @package NoBloat_S3_Offload
 * @since   1.0.0
 */

namespace NBS3\Traits;

/**
 * Trait OffloaderTrait
 *
 * Contains shared methods for determining attachment paths, object versioning,
 * and offload status used across multiple classes.
 *
 * @since 1.0.0
 */
trait OffloaderTrait {

	/**
	 * Get the configured path prefix for S3 object storage.
	 *
	 * Retrieves the path prefix from plugin settings if enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return string The sanitized path prefix with trailing slash, or empty string if disabled.
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
	 * Get or generate the object version for an attachment.
	 *
	 * If an existing version is stored in post meta, returns that.
	 * Otherwise generates a new timestamp-based version if object versioning is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @return string The object version with trailing slash, or empty string if versioning is disabled.
	 */
	private function get_object_version( $attachment_id ) {
		$existing_version = get_post_meta( $attachment_id, 'nbs3_object_version', true );

		if ( $existing_version ) {
			return trailingslashit( $existing_version );
		}

		$settings          = get_option( 'nbs3_settings' );
		$object_versioning = isset( $settings['object_versioning'] ) ? $settings['object_versioning'] : '0';

		if ( ! $object_versioning ) {
			return '';
		}

		if ( ! nbs3_is_media_organized_by_year_month() ) {
			$new_version = gmdate( 'YmdHis' );
		} else {
			$new_version = gmdate( 'dHis' );
		}

		update_post_meta( $attachment_id, 'nbs3_object_version', $new_version );

		return trailingslashit( $new_version );
	}

	/**
	 * Get the subdirectory path for an attachment in S3 storage.
	 *
	 * Builds the path using the configured prefix, year/month directory structure,
	 * and object version where applicable.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @return string The subdirectory path for the attachment.
	 */
	public function get_attachment_subdir( $attachment_id ) {
		if ( $this->is_offloaded( $attachment_id ) ) {
			return get_post_meta( $attachment_id, 'nbs3_path', true );
		}

		$object_version = $this->get_object_version( $attachment_id );
		$path_prefix    = $this->get_path_prefix();

		$metadata  = wp_get_attachment_metadata( $attachment_id );
		$file_path = get_attached_file( $attachment_id );

		if ( isset( $metadata['file'] ) ) {
			$dirname = nbs3_is_media_organized_by_year_month() ? trailingslashit( dirname( $metadata['file'] ) ) : '';
			return $path_prefix . $dirname . $object_version;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		$relative_path = str_replace( $base_dir . '/', '', $file_path );

		$path_parts = explode( '/', trim( $relative_path, '/' ), 3 );

		$response = '';
		if ( count( $path_parts ) >= 2 && is_numeric( $path_parts[0] ) && is_numeric( $path_parts[1] ) ) {
			$response = trailingslashit( $path_parts[0] . '/' . $path_parts[1] );
		}

		return $path_prefix . $response . $object_version;
	}

	/**
	 * Filter metadata sizes to remove duplicates with the same dimensions.
	 *
	 * When multiple sizes have identical dimensions, keeps the one with the largest filesize.
	 *
	 * @since 1.0.0
	 *
	 * @param array $sizes Array of image size data from attachment metadata.
	 * @return array Filtered array with unique dimensions only.
	 */
	private function unique_meta_data_sizes( $sizes ) {
		$unique_sizes  = array();
		$dimension_map = array();

		foreach ( $sizes as $name => $size_info ) {
			$dimension = $size_info['width'] . 'x' . $size_info['height'];

			if ( ! isset( $dimension_map[ $dimension ] ) ) {
				$dimension_map[ $dimension ] = $name;
				$unique_sizes[ $name ]       = $size_info;
			} else {
				$existing_name     = $dimension_map[ $dimension ];
				$new_filesize      = $size_info['filesize'] ?? 0;
				$existing_filesize = $unique_sizes[ $existing_name ]['filesize'] ?? 0;

				if ( $new_filesize > $existing_filesize ) {
					unset( $unique_sizes[ $existing_name ] );
					$dimension_map[ $dimension ] = $name;
					$unique_sizes[ $name ]       = $size_info;
				}
			}
		}

		return $unique_sizes;
	}

	/**
	 * Check if an attachment has been offloaded to S3.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The attachment post ID.
	 * @return bool True if the attachment is offloaded, false otherwise.
	 */
	private function is_offloaded( $post_id ) {
		return (bool) get_post_meta( $post_id, 'nbs3_offloaded', true );
	}

	/**
	 * Determine if local files should be deleted after offloading.
	 *
	 * Checks the retention policy setting from plugin options.
	 *
	 * @since 1.0.0
	 *
	 * @return int The retention policy value (0 to keep local, 1 to delete).
	 */
	private function should_delete_local() {
		$settings         = get_option( 'nbs3_settings' );
		$retention_policy = isset( $settings['retention_policy'] ) ? $settings['retention_policy'] : '0';

		return intval( (string) $retention_policy );
	}

	/**
	 * Determine if cloud files should be deleted when an attachment is deleted.
	 *
	 * Checks if mirror delete is enabled and the post is an offloaded attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post The post object being deleted.
	 * @return bool True if cloud files should be deleted, false otherwise.
	 */
	private function should_delete_cloud_files( $post ) {
		$settings      = get_option( 'nbs3_settings' );
		$mirror_delete = false;

		if ( isset( $settings['mirror_delete'] ) ) {
			$mirror_delete = '1' === $settings['mirror_delete'];
		}

		return $mirror_delete && 'attachment' === $post->post_type && $this->is_offloaded( $post->ID );
	}
}
