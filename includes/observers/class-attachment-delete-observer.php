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

defined( 'ABSPATH' ) || exit;

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
	 * @throws \Exception If cloud file deletion fails.
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
	 * Logs the error and stores it in a transient for admin notice display.
	 * Does NOT halt the WordPress delete operation to prevent database inconsistency.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id       The ID of the post.
	 * @param string $error_message The error message.
	 * @return void
	 */
	private function handle_deletion_error( int $post_id, string $error_message ): void {
		$log_message = sprintf(
			'NBS3: Cloud file deletion failed for attachment ID: %d. Error: %s. ' .
			'The file may remain in cloud storage. Please delete manually if needed.',
			$post_id,
			$error_message
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for deletion failures.
		error_log( $log_message );

		// Store error in transient for admin notice display.
		$errors   = get_transient( 'nbs3_deletion_errors' );
		$errors   = is_array( $errors ) ? $errors : array();
		$errors[] = array(
			'attachment_id' => $post_id,
			'message'       => $error_message,
			'time'          => time(),
		);
		// Keep only the last 10 errors and expire after 1 hour.
		$errors = array_slice( $errors, -10 );
		set_transient( 'nbs3_deletion_errors', $errors, HOUR_IN_SECONDS );

		// Add admin notice for current request if in admin context.
		if ( is_admin() ) {
			add_action(
				'admin_notices',
				function () use ( $post_id, $error_message ) {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html(
							sprintf(
								/* translators: 1: Attachment ID, 2: Error message */
								__( 'NBS3: Failed to delete cloud file for attachment #%1$d: %2$s', 'nobloat-s3-offload' ),
								$post_id,
								$error_message
							)
						)
					);
				}
			);
		}
	}
}
