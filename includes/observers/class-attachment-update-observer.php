<?php
/**
 * Attachment Update Observer.
 *
 * @package NoBloat_S3_Offload
 * @since   1.0.0
 */

namespace NBS3\Observers;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Services\CloudAttachmentUploader;

/**
 * Observer that handles attachment metadata updates.
 *
 * Monitors for image edits and restores, re-offloading attachments when necessary.
 *
 * @since 1.0.0
 */
class AttachmentUpdateObserver implements ObserverInterface {

	/**
	 * Cloud provider instance.
	 *
	 * @var S3Provider
	 */
	private S3Provider $s3_provider;

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
		$this->s3_provider               = $s3_provider;
		$this->cloud_attachment_uploader = new CloudAttachmentUploader( $s3_provider );
	}

	/**
	 * Register the observer with WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_update_attachment_metadata', array( $this, 'run' ), 99, 2 );
	}

	/**
	 * Handle attachment metadata updates.
	 *
	 * Re-offloads attachments when they are edited or restored.
	 *
	 * @param array $metadata      The attachment metadata.
	 * @param int   $attachment_id The attachment ID.
	 * @return array The unmodified metadata.
	 */
	public function run( $metadata, $attachment_id ) {
		// PHPCS ignore reason: Update the attachment's metadata by either restoring or editing it.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 10 );
		foreach ( $trace as $element ) {
			switch ( $element['function'] ) {
				case 'wp_save_image':
					// Right after an image has been edited.
					$this->cloud_attachment_uploader->upload_updated_attachment( $attachment_id, $metadata );
					break;
				case 'wp_restore_image':
					// When an image has been restored.
					break;
			}
		}

		return $metadata;
	}
}
