<?php
/**
 * Bulk Offload Handler Class.
 *
 * Handles bulk offloading of media attachments to cloud storage.
 *
 * @package NBS3
 * @since 1.0.0
 */

namespace NBS3;

defined( 'ABSPATH' ) || exit;

use NBS3\Services\BulkMediaOffloader;
use NBS3\S3Provider;

/**
 * Class BulkOffloadHandler
 *
 * Manages the bulk offload process including AJAX handlers,
 * progress tracking, and process recovery.
 *
 * @since 1.0.0
 */
class BulkOffloadHandler {

	/**
	 * The bulk media offloader process.
	 *
	 * @since 1.0.0
	 * @var BulkMediaOffloader
	 */
	protected $process_all;

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var BulkOffloadHandler|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.0.0
	 * @return BulkOffloadHandler The singleton instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Sets up action hooks for AJAX handlers and initialization.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'wp_ajax_nbs3_check_bulk_offload_progress', array( $this, 'get_progress' ) );
		add_action( 'wp_ajax_nbs3_start_bulk_offload', array( $this, 'bulk_offload' ) );
		add_action( 'wp_ajax_nbs3_cancel_bulk_offload', array( $this, 'cancel_bulk_offload' ) );
		add_action( 'nbs3_cleanup_orphaned_queue', array( $this, 'cleanup_orphaned_queue' ) );
	}

	/**
	 * Initialize the bulk offload handler.
	 *
	 * Sets up the S3 provider and background process.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		try {
			$bucket = nbs3_get_credential( 'bucket' );

			if ( empty( $bucket ) ) {
				return;
			}

			$s3_provider       = new S3Provider();
			$this->process_all = new BulkMediaOffloader( $s3_provider );
			add_action( $this->process_all->get_identifier() . '_cancelled', array( $this, 'process_is_cancelled' ) );

			add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
			add_action( 'nbs3_check_stalled_processes', array( $this, 'check_stalled_processes' ) );

			if ( ! wp_next_scheduled( 'nbs3_check_stalled_processes' ) ) {
				wp_schedule_event( time(), 'nbs3_fifteen_min', 'nbs3_check_stalled_processes' );
			}
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
			error_log( 'NBS3 - Error: ' . $e->getMessage() );
		}
	}

	/**
	 * Add custom cron interval.
	 *
	 * Adds a fifteen-minute interval for stalled process checks.
	 *
	 * @since 1.0.0
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified cron schedules.
	 */
	public function add_cron_interval( $schedules ) {
		$schedules['nbs3_fifteen_min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'nobloat-s3-offload' ),
		);
		return $schedules;
	}

	/**
	 * Check for stalled bulk offload processes.
	 *
	 * Monitors the bulk offload process and recovers from stalls.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function check_stalled_processes() {
		if ( ! $this->process_all instanceof BulkMediaOffloader ) {
			return;
		}

		$process_lock = get_site_transient( $this->process_all->get_identifier() . '_process_lock' );
		$bulk_data    = nbs3_get_bulk_offload_data();
		$last_update  = isset( $bulk_data['last_update'] ) ? (int) $bulk_data['last_update'] : 0;

		if ( 0 === $last_update ) {
			$last_update = (int) get_option( 'nbs3_bulk_offload_last_update', 0 );
		}
		$current_time = time();

		if ( $process_lock && ( $current_time - $last_update ) > 600 ) {
			$bulk_data = nbs3_get_bulk_offload_data();

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging stalled processes.
			error_log(
				sprintf(
					'NBS3: Detected stalled bulk offload process. Lock time: %s, Last update: %s, Processed: %d/%d',
					gmdate( 'Y-m-d H:i:s', $process_lock ),
					gmdate( 'Y-m-d H:i:s', $last_update ),
					$bulk_data['processed'] ?? 0,
					$bulk_data['total'] ?? 0
				)
			);

			$this->force_unlock_process();

			nbs3_update_bulk_offload_data(
				array(
					'status'        => 'recovered_from_stall',
					'last_recovery' => $current_time,
				)
			);

			wp_schedule_single_event( time() + 60, 'nbs3_cleanup_orphaned_queue' );
		}

		if ( $last_update > 0 && ( $current_time - $last_update ) > 3600 ) {
			$bulk_data = nbs3_get_bulk_offload_data();

			if ( isset( $bulk_data['status'] ) && in_array( $bulk_data['status'], array( 'processing', 'starting' ), true ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
				error_log( 'NBS3: Cleaning up very old bulk offload process (>1 hour)' );

				$this->force_unlock_process();
				nbs3_update_bulk_offload_data(
					array(
						'status'       => 'timeout_cleanup',
						'last_cleanup' => $current_time,
					)
				);
			}
		}
	}

	/**
	 * Force unlock a stalled process.
	 *
	 * Clears transients and scheduled hooks to recover from a stall.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function force_unlock_process() {
		if ( ! $this->process_all instanceof BulkMediaOffloader ) {
			return;
		}

		/*
		 * Preserve the operator's cancel intent. Stalled-process recovery
		 * runs on a 15-minute cron and used to wipe nbs3_bulk_offload_cancelled
		 * before re-dispatching, which silently undid an in-flight cancel
		 * that landed in the same window — the user saw "cancelled" in the
		 * UI but processing resumed.
		 */
		$is_cancelled = (bool) get_option( 'nbs3_bulk_offload_cancelled' );

		delete_site_transient( $this->process_all->get_identifier() . '_process_lock' );
		delete_site_transient( $this->process_all->get_identifier() . '_batch_lock' );
		wp_clear_scheduled_hook( $this->process_all->get_identifier() . '_cron' );

		if ( $is_cancelled ) {
			return;
		}

		if ( $this->process_all->is_queued() && ! $this->process_all->is_processing() ) {
			$this->process_all->dispatch();
		}
	}

	/**
	 * Handle bulk offload AJAX request.
	 *
	 * Starts the bulk offload process.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function bulk_offload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied', 'nobloat-s3-offload' ),
				),
				403
			);
		}

		if ( ! check_ajax_referer( 'nbs3_bulk_offload', 'bulk_offload_nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed', 'nobloat-s3-offload' ),
				),
				403
			);
		}

		$ready = $this->ensure_process_ready();
		if ( is_wp_error( $ready ) ) {
			wp_send_json_error(
				array(
					'message' => $ready->get_error_message(),
					'code'    => $ready->get_error_code(),
				),
				400
			);
		}

		try {
			$this->handle_all();
			$bulk_offload_data = nbs3_get_bulk_offload_data();

			wp_send_json_success(
				array(
					'total' => $bulk_offload_data['total'],
				)
			);
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
			error_log( 'NBS3 Bulk Offload Error: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				),
				500
			);
		}
	}

	/**
	 * Handle bulk offload progress AJAX request.
	 *
	 * Returns the current progress of the bulk offload process.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function get_progress() {
		if ( ! check_ajax_referer( 'nbs3_bulk_offload', 'bulk_offload_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'nobloat-s3-offload' ) ), 403 );
			return;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'nobloat-s3-offload' ) ), 403 );
			return;
		}

		$bulk_offload_data         = nbs3_get_bulk_offload_data();
		$is_bulk_offload_cancelled = get_option( 'nbs3_bulk_offload_cancelled' );
		wp_send_json_success(
			array(
				'processed'         => $bulk_offload_data['processed'],
				'total'             => $bulk_offload_data['total'],
				'status'            => $is_bulk_offload_cancelled ? 'cancelled' : $bulk_offload_data['status'],
				'errors'            => $bulk_offload_data['errors'] ?? 0,
				'oversized_skipped' => $bulk_offload_data['oversized_skipped'] ?? 0,
			)
		);
	}

	/**
	 * Handle all unoffloaded attachments.
	 *
	 * Queues all unoffloaded attachments for processing.
	 *
	 * @since 1.0.0
	 * @throws \Exception If the process is not ready.
	 * @return void
	 */
	protected function handle_all() {
		$ready = $this->ensure_process_ready();
		if ( is_wp_error( $ready ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP_Error message is safe, exception is caught and sanitized before output.
			throw new \Exception( $ready->get_error_message() );
		}

		// Clear any existing queue items from previous runs without triggering cancelled hook.
		$batches = $this->process_all->get_batches();
		foreach ( $batches as $batch ) {
			$this->process_all->delete( $batch->key );
		}

		$names = $this->get_unoffloaded_attachments();

		foreach ( $names as $name ) {
			$this->process_all->push_to_queue( $name );
		}

		$this->process_all->save()->dispatch();
	}

	/**
	 * Get unoffloaded attachments.
	 *
	 * Retrieves a batch of attachments that have not been offloaded.
	 *
	 * @since 1.0.0
	 * @param int $batch_size Maximum number of attachments to retrieve.
	 * @return array Array of attachment IDs.
	 */
	protected function get_unoffloaded_attachments( $batch_size = 50 ) {
		global $wpdb;

		$max_batch_size_mb    = 150;
		$current_batch_size   = 0;
		$filtered_attachments = array();
		$oversized_files      = 0;

		$query = $wpdb->prepare(
			'SELECT p.ID FROM %i p
			LEFT JOIN %i pm ON p.ID = pm.post_id AND pm.meta_key = %s
			LEFT JOIN %i em ON p.ID = em.post_id AND em.meta_key = %s
			WHERE p.post_type = %s
			AND (pm.meta_value IS NULL OR pm.meta_value = %s)
			AND em.meta_id IS NULL
			ORDER BY p.post_date ASC
			LIMIT %d',
			$wpdb->posts,
			$wpdb->postmeta,
			'nbs3_offloaded',
			$wpdb->postmeta,
			'nbs3_error_log',
			'attachment',
			'',
			$batch_size * 2
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above, complex JOIN for finding unoffloaded attachments.
		$normal_attachments = $wpdb->get_col( $query );

		foreach ( $normal_attachments as $attachment_id ) {
			if ( count( $filtered_attachments ) >= $batch_size ) {
				break;
			}

			if ( false === apply_filters( 'nbs3_should_offload_attachment', true, $attachment_id ) ) {
				continue;
			}

			$file_path = get_attached_file( $attachment_id );
			if ( file_exists( $file_path ) ) {
				$file_size = filesize( $file_path ) / ( 1024 * 1024 );

				if ( $file_size > 10 ) {
					$error_msg = sprintf(
						/* translators: %s: maximum file size in MB */
						__( 'File exceeds maximum size (%s MB) for bulk processing', 'nobloat-s3-offload' ),
						'10'
					);
					update_post_meta( $attachment_id, 'nbs3_error_log', $error_msg );
					++$oversized_files;
					continue;
				}

				if ( ( $current_batch_size + $file_size ) <= $max_batch_size_mb ) {
					$filtered_attachments[] = $attachment_id;
					$current_batch_size    += $file_size;
				} else {
					break;
				}
			} else {
				$filtered_attachments[] = $attachment_id;
			}
		}

		$remaining_slots = $batch_size - count( $filtered_attachments );

		if ( $remaining_slots > 0 ) {
			$error_query = $wpdb->prepare(
				'SELECT p.ID
				FROM %i p
				JOIN %i pm_error ON (p.ID = pm_error.post_id AND pm_error.meta_key = %s)
				LEFT JOIN %i pm_offload ON (p.ID = pm_offload.post_id AND pm_offload.meta_key = %s)
				WHERE p.post_type = %s
				AND (pm_offload.meta_value IS NULL OR pm_offload.meta_value = %s)
				AND pm_error.meta_value IS NOT NULL
				AND pm_error.meta_value != %s
				ORDER BY p.post_date ASC
				LIMIT %d',
				$wpdb->posts,
				$wpdb->postmeta,
				'nbs3_error_log',
				$wpdb->postmeta,
				'nbs3_offloaded',
				'attachment',
				'',
				'',
				$remaining_slots * 2
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above, complex JOIN for finding failed attachments.
			$error_attachments = $wpdb->get_col( $error_query );

			foreach ( $error_attachments as $attachment_id ) {
				if ( count( $filtered_attachments ) >= $batch_size ) {
					break;
				}

				if ( false === apply_filters( 'nbs3_should_offload_attachment', true, $attachment_id ) ) {
					continue;
				}

				$file_path = get_attached_file( $attachment_id );
				if ( file_exists( $file_path ) ) {
					$file_size = filesize( $file_path ) / ( 1024 * 1024 );

					if ( $file_size > 100 ) {
						continue;
					}

					if ( ( $current_batch_size + $file_size ) <= $max_batch_size_mb ) {
						$filtered_attachments[] = $attachment_id;
						$current_batch_size    += $file_size;
					} else {
						break;
					}
				} else {
					$filtered_attachments[] = $attachment_id;
				}
			}
		}

		$attachment_count = count( $filtered_attachments );
		if ( $attachment_count > 0 ) {
			nbs3_update_bulk_offload_data(
				array(
					'total'             => $attachment_count,
					'status'            => 'processing',
					'processed'         => 0,
					'errors'            => 0,
					'oversized_skipped' => $oversized_files,
				)
			);
		} else {
			nbs3_clear_bulk_offload_data();
		}

		return $filtered_attachments;
	}

	/**
	 * Handle cancel bulk offload AJAX request.
	 *
	 * Cancels the currently running bulk offload process.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function cancel_bulk_offload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied', 'nobloat-s3-offload' ),
				),
				403
			);
		}

		if ( ! check_ajax_referer( 'nbs3_bulk_offload', 'bulk_offload_nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid nonce', 'nobloat-s3-offload' ),
				),
				403
			);
		}

		$ready = $this->ensure_process_ready();
		if ( is_wp_error( $ready ) ) {
			wp_send_json_error(
				array(
					'message' => $ready->get_error_message(),
					'code'    => $ready->get_error_code(),
				),
				400
			);
		}

		$this->process_all->cancel();

		update_option( 'nbs3_bulk_offload_cancelled', true );

		wp_send_json_success(
			array(
				'message' => __( 'Bulk offload cancelled successfully.', 'nobloat-s3-offload' ),
			)
		);
	}

	/**
	 * Handle process cancelled callback.
	 *
	 * Updates the bulk offload status when the process is cancelled.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function process_is_cancelled() {
		nbs3_update_bulk_offload_data(
			array(
				'status' => 'cancelled',
			)
		);
		delete_option( 'nbs3_bulk_offload_cancelled' );
	}

	/**
	 * Clean up orphaned queue items.
	 *
	 * Removes empty batches and dispatches pending items.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function cleanup_orphaned_queue() {
		if ( ! $this->process_all instanceof BulkMediaOffloader ) {
			return;
		}

		$batches           = $this->process_all->get_batches();
		$has_pending_items = false;

		foreach ( $batches as $batch ) {
			if ( empty( $batch->data ) ) {
				$this->process_all->delete( $batch->key );
				continue;
			}

			$has_pending_items = true;
		}

		// Honor operator cancellation here too — same reason as force_unlock_process().
		if ( get_option( 'nbs3_bulk_offload_cancelled' ) ) {
			return;
		}

		if ( $has_pending_items && ! $this->process_all->is_processing() ) {
			$this->process_all->dispatch();
		}
	}

	/**
	 * Ensure the bulk offload process is ready.
	 *
	 * Checks if the BulkMediaOffloader is properly initialized.
	 *
	 * @since 1.0.0
	 * @return true|\WP_Error True if ready, WP_Error otherwise.
	 */
	private function ensure_process_ready() {
		if ( $this->process_all instanceof BulkMediaOffloader ) {
			return true;
		}

		return new \WP_Error(
			'nbs3_offloader_unavailable',
			__( 'Bulk offload requires S3 credentials. Please configure them before trying again.', 'nobloat-s3-offload' )
		);
	}

	/**
	 * Handle cron healthcheck for bulk offload.
	 *
	 * Triggers the cron healthcheck and returns success.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function bulk_offload_cron_healthcheck() {
		$this->process_all->handle_cron_healthcheck();
		wp_send_json_success();
	}
}
