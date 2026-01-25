<?php
/**
 * Get Attached File Observer.
 *
 * @package NoBloat_S3_Offload
 * @since   1.0.0
 */

namespace NBS3\Observers;

defined( 'ABSPATH' ) || exit;

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
	 * Temp files created during this request that need cleanup.
	 *
	 * @since 1.0.6
	 * @var array
	 */
	private static array $temp_files = array();

	/**
	 * Whether the shutdown cleanup handler has been registered.
	 *
	 * @since 1.0.6
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Register the observer with WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'get_attached_file', array( $this, 'filter' ), 10, 2 );

		// Register cleanup for old temp files on admin init.
		add_action( 'admin_init', array( $this, 'cleanup_old_temp_files' ) );
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
	public function filter( $file, $attachment_id ): string {
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

		// Check for existing temp file that's still valid.
		$tmp_file_data = get_post_meta( $attachment_id, 'nbs3_tmp_file', true );
		if ( is_array( $tmp_file_data ) && ! empty( $tmp_file_data['path'] ) ) {
			$tmp_path    = $tmp_file_data['path'];
			$tmp_created = $tmp_file_data['created'] ?? 0;

			// Use cached temp file if it exists and is less than 1 hour old.
			if ( file_exists( $tmp_path ) && ( time() - $tmp_created ) < HOUR_IN_SECONDS ) {
				return $tmp_path;
			}

			// Clean up stale temp file.
			if ( file_exists( $tmp_path ) ) {
				wp_delete_file( $tmp_path );
			}
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

		// Store temp file info with timestamp for later cleanup.
		update_post_meta(
			$attachment_id,
			'nbs3_tmp_file',
			array(
				'path'    => $tmp,
				'created' => time(),
			)
		);

		// Register for request-end cleanup.
		$this->register_temp_file_for_cleanup( $tmp, $attachment_id );

		return $tmp;
	}

	/**
	 * Register a temp file for cleanup at end of request.
	 *
	 * @since 1.0.6
	 *
	 * @param string $file_path     The temp file path.
	 * @param int    $attachment_id The attachment ID.
	 * @return void
	 */
	private function register_temp_file_for_cleanup( string $file_path, int $attachment_id ): void {
		self::$temp_files[ $attachment_id ] = $file_path;

		// Register shutdown handler once per request.
		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( array( __CLASS__, 'cleanup_request_temp_files' ) );
			self::$shutdown_registered = true;
		}
	}

	/**
	 * Cleanup temp files created during the current request.
	 *
	 * Called via register_shutdown_function() at the end of the request.
	 *
	 * @since 1.0.6
	 *
	 * @return void
	 */
	public static function cleanup_request_temp_files(): void {
		foreach ( self::$temp_files as $attachment_id => $file_path ) {
			if ( file_exists( $file_path ) ) {
				wp_delete_file( $file_path );
			}
			delete_post_meta( $attachment_id, 'nbs3_tmp_file' );
		}
		self::$temp_files = array();
	}

	/**
	 * Cleanup old temp files that may have been left behind.
	 *
	 * Runs on admin_init to clean up any orphaned temp files older than 1 hour.
	 * This is a safety net for edge cases where shutdown cleanup didn't run.
	 *
	 * @since 1.0.6
	 *
	 * @return void
	 */
	public function cleanup_old_temp_files(): void {
		// Only run occasionally to avoid performance impact.
		$last_cleanup = get_transient( 'nbs3_tmp_cleanup_last_run' );
		if ( false !== $last_cleanup ) {
			return;
		}

		// Set transient to run cleanup at most once per hour.
		set_transient( 'nbs3_tmp_cleanup_last_run', time(), HOUR_IN_SECONDS );

		// Query for attachments with temp file meta.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is fully prepared, one-time cleanup query.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT post_id, meta_value FROM %i WHERE meta_key = %s LIMIT %d',
				$wpdb->postmeta,
				'nbs3_tmp_file',
				100
			)
		);

		if ( empty( $results ) ) {
			return;
		}

		$current_time = time();
		foreach ( $results as $row ) {
			$data = maybe_unserialize( $row->meta_value );

			// Handle old format (string path) or new format (array with path and created).
			if ( is_string( $data ) ) {
				// Old format - clean up immediately.
				if ( file_exists( $data ) ) {
					wp_delete_file( $data );
				}
				delete_post_meta( $row->post_id, 'nbs3_tmp_file' );
			} elseif ( is_array( $data ) && isset( $data['path'] ) ) {
				$created = $data['created'] ?? 0;
				// Clean up if older than 1 hour.
				if ( ( $current_time - $created ) > HOUR_IN_SECONDS ) {
					if ( file_exists( $data['path'] ) ) {
						wp_delete_file( $data['path'] );
					}
					delete_post_meta( $row->post_id, 'nbs3_tmp_file' );
				}
			}
		}
	}
}
