<?php
/**
 * Attachment Upload Observer.
 *
 * @package NoBloat_S3_Offload
 * @since   1.0.0
 */

namespace NBS3\Observers;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Services\CloudAttachmentUploader;

/**
 * Observer that handles automatic offloading of newly uploaded attachments.
 *
 * @since 1.0.0
 */
class AttachmentUploadObserver implements ObserverInterface {

	/**
	 * Cloud attachment uploader service.
	 *
	 * @var CloudAttachmentUploader
	 */
	private CloudAttachmentUploader $cloud_attachment_uploader;

	/**
	 * Constructor.
	 *
	 * @param S3Provider $s3_provider The cloud provider instance.
	 */
	public function __construct( S3Provider $s3_provider ) {
		$this->cloud_attachment_uploader = new CloudAttachmentUploader( $s3_provider );
	}

	/**
	 * Register the observer with WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'run' ), 99, 2 );
	}

	/**
	 * Handle newly generated attachment metadata.
	 *
	 * Automatically offloads the attachment if auto-offload is enabled.
	 *
	 * @param array $metadata      The attachment metadata.
	 * @param int   $attachment_id The attachment ID.
	 * @return array The unmodified metadata.
	 */
	public function run( $metadata, $attachment_id ) {
		// Check if auto-offload is enabled in settings.
		$options              = get_option( 'nbs3_settings', array() );
		$auto_offload_enabled = isset( $options['auto_offload_uploads'] ) ? (int) $options['auto_offload_uploads'] : 1;

		if ( ! $auto_offload_enabled ) {
			return $metadata;
		}

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
			return $metadata;
		}

		if ( ! $this->cloud_attachment_uploader->upload_attachment( $attachment_id ) ) {
			// Log the failure but do NOT delete the attachment.
			// The error is already logged by CloudAttachmentUploader.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
			error_log(
				sprintf(
					'NBS3: Failed to offload attachment ID %d. Attachment preserved locally.',
					$attachment_id
				)
			);
		}

		return $metadata;
	}
}
