<?php
/**
 * Attachment Delete Observer.
 *
 * Handles the deletion of cloud files when a WordPress attachment is deleted.
 *
 * @package NBS3
 * @subpackage Observers
 * @since 1.0.0
 */

namespace NBS3\Observers;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Traits\OffloaderTrait;

/**
 * Observer class for handling attachment deletions.
 *
 * This class listens to the WordPress delete_attachment hook and ensures
 * that corresponding cloud files are removed when an attachment is deleted.
 *
 * @since 1.0.0
 */
class AttachmentDeleteObserver implements ObserverInterface {

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
	 *
	 * @param S3Provider $s3_provider The S3 provider instance.
	 */
	public function __construct( S3Provider $s3_provider ) {
		$this->s3_provider = $s3_provider;
	}

	/**
	 * Register the observer with WordPress hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'delete_attachment', array( $this, 'run' ), 10, 2 );
	}

	/**
	 * Delete cloud files when an attachment is deleted.
	 *
	 * @since 1.0.0
	 *
	 * @see https://developer.wordpress.org/reference/hooks/delete_attachment/
	 *
	 * @param int      $post_id The ID of the post.
	 * @param \WP_Post $post    The post object.
	 * @return void
	 */
	public function run( int $post_id, \WP_Post $post ): void {
		if ( $this->should_delete_cloud_files( $post ) ) {
			$this->perform_cloud_file_deletion( $post_id );
		}
	}

	/**
	 * Perform the actual deletion of cloud files.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The ID of the post.
	 * @return void
	 */
	private function perform_cloud_file_deletion( int $post_id ): void {
		try {
			$result = $this->s3_provider->delete_attachment( $post_id );
			if ( ! $result ) {
				throw new \Exception( 'Cloud file deletion failed' );
			}
		} catch ( \Exception $e ) {
			$this->handle_deletion_error( $post_id, $e->getMessage() );
		}
	}

	/**
	 * Handle errors during cloud file deletion.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id       The ID of the post.
	 * @param string $error_message The error message.
	 * @return void
	 */
	private function handle_deletion_error( int $post_id, string $error_message ): void {
		$log_message = "Cloud file deletion failed for attachment ID: {$post_id}. " .
			'The file remains in the cloud storage and locally due to an error. ' .
			'Please try again or contact support if the issue persists.';

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for deletion failures.
		error_log( $log_message );

		// Add a notice to the dashboard.
		add_action(
			'admin_notices',
			function () use ( $error_message ) {
				echo '<div class="error"><p>' . esc_html( $error_message ) . '</p></div>';
			}
		);

		wp_die( 'Error deleting file from cloud provider: ' . esc_html( $error_message ) );
	}
}
