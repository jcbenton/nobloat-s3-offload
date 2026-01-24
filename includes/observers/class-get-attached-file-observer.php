<?php
/**
 * Get Attached File Observer.
 *
 * @package NoBloat_S3_Offload
 * @since   1.0.0
 */

namespace NBS3\Observers;

use NBS3\Interfaces\ObserverInterface;

/**
 * Observer that handles the get_attached_file filter for offloaded attachments.
 *
 * This observer hooks into the 'get_attached_file' filter to handle offloaded attachments,
 * specifically addressing issues with SVG files in WordPress and Elementor after offloading
 * to cloud storage. When the local file is missing for supported mime types (e.g., image/svg+xml),
 * it downloads a temporary copy from the cloud URL to ensure accessibility.
 *
 * Note: Even when "Smart local cleanup" is selected (which typically deletes only sized images
 * while keeping the original), SVG files may have their main file deleted due to how plugins
 * enable SVG uploads in WordPress, as SVGs do not generate sized thumbnails.
 *
 * @since 1.0.0
 */
class GetAttachedFileObserver implements ObserverInterface {

	/**
	 * Register the observer with WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'get_attached_file', array( $this, 'filter' ), 10, 2 );
	}

	/**
	 * Filter the attached file path.
	 *
	 * Downloads a temporary copy from cloud storage if the local file is missing
	 * and the attachment has been offloaded.
	 *
	 * @param string $file          The file path.
	 * @param int    $attachment_id The attachment ID.
	 * @return string The file path, potentially a temporary downloaded file.
	 */
	public function filter( $file, $attachment_id ) {
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $file;
		}

		/**
		 * Filters the supported mime types for temporary file fetching.
		 *
		 * @param array $supported_mime_types The supported mime types.
		 * @param int   $attachment_id        The attachment ID.
		 * @return array The supported mime types.
		 */
		$supported_mime_types = apply_filters( 'nbs3_temp_fetch_mime_types', array( 'image/svg+xml' ), $attachment_id );
		if ( ! in_array( $post->post_mime_type, $supported_mime_types, true ) ) {
			return $file;
		}

		if ( file_exists( $file ) ) {
			return $file;
		}

		$is_offloaded = (bool) get_post_meta( $attachment_id, 'nbs3_offloaded', true );
		if ( ! $is_offloaded ) {
			return $file;
		}

		// Get cloud URL via plugin's existing rewrite logic.

		// Temporarily remove the 'get_attached_file' filter before calling nbs3_get_public_url to prevent recursive calls.
		// Re-add the filter afterward to maintain normal behavior.
		// This resolves fatal errors on sites with offloaded SVGs or similar mime types where local files are missing.
		remove_filter( 'get_attached_file', array( $this, 'filter' ), 10 );
		$remote_url = nbs3_get_public_url( $attachment_id );
		add_filter( 'get_attached_file', array( $this, 'filter' ), 10, 2 );

		if ( empty( $remote_url ) ) {
			return $file;
		}

		$tmp_file = get_post_meta( $attachment_id, 'nbs3_tmp_file', true );
		if ( $tmp_file && file_exists( $tmp_file ) ) {
			return $tmp_file;
		}

		// Ensure download_url() is available.
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = download_url( $remote_url, 30 );
		if ( is_wp_error( $tmp ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
			error_log( 'NBS3 - GetAttachedFileObserver - Error downloading file: ' . $tmp->get_error_message() );
			return $file;
		}

		// Store the tmp file to the attachment meta.
		update_post_meta( $attachment_id, 'nbs3_tmp_file', $tmp );

		return $tmp;
	}
}
