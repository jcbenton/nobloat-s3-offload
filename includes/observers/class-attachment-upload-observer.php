<?php

namespace NBS3\Observers;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Services\CloudAttachmentUploader;

class AttachmentUploadObserver implements ObserverInterface {

	private CloudAttachmentUploader $cloudAttachmentUploader;

	public function __construct( S3Provider $s3Provider ) {
		$this->cloudAttachmentUploader = new CloudAttachmentUploader( $s3Provider );
	}

	public function register(): void {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'run' ), 99, 2 );
	}

	public function run( $metadata, $attachment_id ) {
		// Check if auto-offload is enabled in settings
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

		if ( ! $this->cloudAttachmentUploader->uploadAttachment( $attachment_id ) ) {
			// Log the failure but do NOT delete the attachment
			// The error is already logged by CloudAttachmentUploader
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging
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
