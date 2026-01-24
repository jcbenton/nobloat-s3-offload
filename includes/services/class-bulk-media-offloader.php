<?php
/**
 * Bulk Media Offloader Service.
 *
 * Background process for bulk offloading media attachments to cloud storage.
 *
 * @package NBS3
 * @subpackage Services
 * @since 1.0.0
 */

namespace NBS3\Services;

use NBS3\Services\CloudAttachmentUploader;
use NBS3\S3Provider;
use NBS3\Abstracts\WP_Background_Processing\WP_Background_Process;

/**
 * Class BulkMediaOffloader
 *
 * Extends the background process to handle bulk media offloading.
 * Processes attachments in the background to avoid timeout issues.
 *
 * @since 1.0.0
 */
class BulkMediaOffloader extends WP_Background_Process {

	/**
	 * Action prefix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $prefix = 'nbs3';

	/**
	 * Action name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $action = 'bulk_offload_media_process';

	/**
	 * The cloud attachment uploader instance.
	 *
	 * @since 1.0.0
	 * @var CloudAttachmentUploader
	 */
	private CloudAttachmentUploader $cloud_attachment_uploader;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param S3Provider $s3_provider The S3 provider instance.
	 */
	public function __construct( S3Provider $s3_provider ) {
		parent::__construct();
		$this->cloud_attachment_uploader = new CloudAttachmentUploader( $s3_provider );
	}

	/**
	 * Process a single queue item.
	 *
	 * Uploads the attachment to cloud storage.
	 *
	 * @since 1.0.0
	 * @param mixed $item Queue item to process (attachment ID).
	 * @return mixed False to remove item from queue, item to keep in queue.
	 */
	protected function task( $item ) {
		try {
			$result = $this->cloud_attachment_uploader->upload_attachment( $item );
			$this->update_processed_count( $result );

			return false;
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for bulk offload failures.
			error_log( "NBS3 Bulk Offload Error (ID: {$item}): " . $e->getMessage() );

			// Mark as processed with error.
			$this->update_processed_count( false );

			// Add error to attachment meta.
			update_post_meta( $item, 'nbs3_error_log', $e->getMessage() );

			// Move on to the next item rather than retrying.
			return false;
		}
	}

	/**
	 * Complete the background process.
	 *
	 * Updates the bulk offload status to completed.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	protected function complete() {
		parent::complete();
		nbs3_update_bulk_offload_data( array( 'status' => 'completed' ) );
	}

	/**
	 * Update the processed count.
	 *
	 * Increments the processed count and error count as needed.
	 *
	 * @since 1.0.0
	 * @param bool $result_status Whether the upload was successful.
	 * @return void
	 */
	public function update_processed_count( $result_status ) {
		$bulk_offload_data = nbs3_get_bulk_offload_data();
		$processed_count   = $bulk_offload_data['processed'];
		++$processed_count;
		$errors = $bulk_offload_data['errors'] ?? 0;

		if ( true !== $result_status ) {
			++$errors;
		}

		nbs3_update_bulk_offload_data(
			array(
				'processed' => $processed_count,
				'total'     => $bulk_offload_data['total'],
				'status'    => $bulk_offload_data['status'],
				'errors'    => $errors,
			)
		);
	}

	/**
	 * Get the process identifier.
	 *
	 * @since 1.0.0
	 * @return string The process identifier.
	 */
	public function get_identifier() {
		return $this->identifier;
	}
}
