<?php
/**
 * WP CLI command for media offloading operations.
 *
 * @package NBS3
 * @subpackage CLI
 */

namespace NBS3\CLI;

defined( 'ABSPATH' ) || exit;

use NBS3\Services\CloudAttachmentUploader;
use NBS3\S3Provider;
use NBS3\Traits\OffloaderTrait;

/**
 * WP CLI command for media offloading operations.
 */
class OffloadCommand {

	use OffloaderTrait;

	/**
	 * Cloud attachment uploader instance.
	 *
	 * @var CloudAttachmentUploader
	 */
	private $uploader;

	/**
	 * Constructor.
	 *
	 * Initializes the S3 provider and uploader.
	 */
	public function __construct() {
		try {
			$bucket = nbs3_get_credential( 'bucket' );
			if ( empty( $bucket ) ) {
				\WP_CLI::error( 'No S3 credentials configured. Please configure your S3 settings first.' );
			}

			$s3_provider    = new S3Provider();
			$this->uploader = new CloudAttachmentUploader( $s3_provider );
		} catch ( \Exception $e ) {
			\WP_CLI::error( 'Failed to initialize S3 provider: ' . $e->getMessage() );
		}
	}

	/**
	 * Offload media attachments to S3 storage.
	 *
	 * ## OPTIONS
	 *
	 * [<attachment_ids>]
	 * : Attachment ID(s) to offload. Can be a single ID or comma-separated list.
	 *
	 * [--limit=<number>]
	 * : Maximum number of attachments to process.
	 *
	 * [--skip-failed]
	 * : Skip attachments that have previously failed offloading.
	 *
	 * ## EXAMPLES
	 *
	 *     # Offload all unoffloaded media attachments
	 *     wp nbs3 offload
	 *
	 *     # Offload a specific attachment by ID
	 *     wp nbs3 offload 123
	 *
	 *     # Offload multiple specific attachments
	 *     wp nbs3 offload 123,456,789
	 *
	 *     # Offload up to 100 most recent attachments
	 *     wp nbs3 offload --limit=100
	 *
	 *     # Offload all attachments, skipping any with previous errors
	 *     wp nbs3 offload --skip-failed
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (flags).
	 *
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$limit       = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;
		$skip_failed = isset( $assoc_args['skip-failed'] );

		$attachment_ids = $this->parse_attachment_ids( $args );

		if ( ! empty( $attachment_ids ) ) {
			$this->offload_specific_attachments( $attachment_ids, $skip_failed );
		} else {
			$this->offload_all_attachments( $limit, $skip_failed );
		}
	}

	/**
	 * Parse attachment IDs from command arguments.
	 *
	 * @param array $args Positional arguments from the command.
	 *
	 * @return array Array of attachment IDs.
	 */
	private function parse_attachment_ids( $args ) {
		if ( empty( $args[0] ) ) {
			return array();
		}

		$ids_string = $args[0];

		if ( false !== strpos( $ids_string, ',' ) ) {
			$ids = explode( ',', $ids_string );
			return array_map( 'intval', array_filter( array_map( 'trim', $ids ) ) );
		}

		$id = intval( $ids_string );
		return $id > 0 ? array( $id ) : array();
	}

	/**
	 * Offload specific attachments by ID.
	 *
	 * @param array $attachment_ids Array of attachment IDs to offload.
	 * @param bool  $skip_failed    Whether to skip attachments with previous errors.
	 *
	 * @return void
	 */
	private function offload_specific_attachments( $attachment_ids, $skip_failed ) {
		$total = count( $attachment_ids );

		\WP_CLI::log( "Processing {$total} specific attachment(s)..." );

		$successful = 0;
		$failed     = 0;
		$skipped    = 0;

		foreach ( $attachment_ids as $index => $attachment_id ) {
			\WP_CLI::log( "Processing attachment ID {$attachment_id}..." );

			if ( ! $this->attachment_exists( $attachment_id ) ) {
				\WP_CLI::warning( "Attachment ID {$attachment_id} not found, skipping." );
				++$skipped;
				continue;
			}

			if ( $this->is_offloaded( $attachment_id ) ) {
				\WP_CLI::log( "Attachment ID {$attachment_id} already offloaded, skipping." );
				++$skipped;
				continue;
			}

			if ( $skip_failed && $this->has_errors( $attachment_id ) ) {
				\WP_CLI::log( "Attachment ID {$attachment_id} has previous errors, skipping." );
				++$skipped;
				continue;
			}

			if ( $this->process_attachment( $attachment_id ) ) {
				\WP_CLI::success( "Successfully offloaded attachment ID {$attachment_id}" );
				++$successful;
			} else {
				++$failed;
				$errors        = get_post_meta( $attachment_id, 'nbs3_error_log', true );
				$error_message = is_array( $errors ) ? implode( '; ', $errors ) : $errors;
				\WP_CLI::error( "Failed to offload attachment ID {$attachment_id}: {$error_message}", false );
			}
		}

		$this->display_results( $successful, $failed, $skipped, $total );
	}

	/**
	 * Offload all eligible attachments.
	 *
	 * @param int  $limit       Maximum number of attachments to process.
	 * @param bool $skip_failed Whether to skip attachments with previous errors.
	 *
	 * @return void
	 */
	private function offload_all_attachments( $limit, $skip_failed ) {
		$attachment_ids = $this->get_eligible_attachments( $limit, $skip_failed );
		$total          = count( $attachment_ids );

		if ( 0 === $total ) {
			\WP_CLI::success( 'No eligible attachments found for offloading.' );
			return;
		}

		\WP_CLI::log( "Found {$total} eligible attachment(s) for offloading..." );

		$successful = 0;
		$failed     = 0;
		$skipped    = 0;

		foreach ( $attachment_ids as $index => $attachment_id ) {
			$current = $index + 1;
			\WP_CLI::log( "Processing attachment ID {$attachment_id} ({$current}/{$total})..." );

			if ( $this->is_offloaded( $attachment_id ) ) {
				\WP_CLI::log( "Attachment ID {$attachment_id} already offloaded, skipping." );
				++$skipped;
				continue;
			}

			if ( $this->process_attachment( $attachment_id ) ) {
				\WP_CLI::success( "Successfully offloaded attachment ID {$attachment_id}" );
				++$successful;
			} else {
				++$failed;
				$errors        = get_post_meta( $attachment_id, 'nbs3_error_log', true );
				$error_message = is_array( $errors ) ? implode( '; ', $errors ) : $errors;
				\WP_CLI::error( "Failed to offload attachment ID {$attachment_id}: {$error_message}", false );
			}
		}

		$this->display_results( $successful, $failed, $skipped, $total );
	}

	/**
	 * Get eligible attachments for offloading.
	 *
	 * @param int  $limit       Maximum number of attachments to retrieve.
	 * @param bool $skip_failed Whether to exclude attachments with previous errors.
	 *
	 * @return array Array of attachment IDs.
	 */
	private function get_eligible_attachments( $limit, $skip_failed ) {
		global $wpdb;

		$limit = absint( $limit );

		if ( $skip_failed ) {
			if ( $limit > 0 ) {
				$query = $wpdb->prepare(
					'SELECT p.ID FROM %i p
					LEFT JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = %s
					LEFT JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s
					WHERE p.post_type = %s
					AND (pm1.meta_value IS NULL OR pm1.meta_value = %s)
					AND pm2.meta_id IS NULL
					ORDER BY p.post_date ASC
					LIMIT %d',
					$wpdb->posts,
					$wpdb->postmeta,
					'nbs3_offloaded',
					$wpdb->postmeta,
					'nbs3_error_log',
					'attachment',
					'',
					$limit
				);
			} else {
				$query = $wpdb->prepare(
					'SELECT p.ID FROM %i p
					LEFT JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = %s
					LEFT JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s
					WHERE p.post_type = %s
					AND (pm1.meta_value IS NULL OR pm1.meta_value = %s)
					AND pm2.meta_id IS NULL
					ORDER BY p.post_date ASC',
					$wpdb->posts,
					$wpdb->postmeta,
					'nbs3_offloaded',
					$wpdb->postmeta,
					'nbs3_error_log',
					'attachment',
					''
				);
			}
		} elseif ( $limit > 0 ) {
				$query = $wpdb->prepare(
					'SELECT p.ID FROM %i p
					LEFT JOIN %i pm ON p.ID = pm.post_id AND pm.meta_key = %s
					WHERE p.post_type = %s
					AND (pm.meta_value IS NULL OR pm.meta_value = %s)
					ORDER BY p.post_date ASC
					LIMIT %d',
					$wpdb->posts,
					$wpdb->postmeta,
					'nbs3_offloaded',
					'attachment',
					'',
					$limit
				);
		} else {
			$query = $wpdb->prepare(
				'SELECT p.ID FROM %i p
					LEFT JOIN %i pm ON p.ID = pm.post_id AND pm.meta_key = %s
					WHERE p.post_type = %s
					AND (pm.meta_value IS NULL OR pm.meta_value = %s)
					ORDER BY p.post_date ASC',
				$wpdb->posts,
				$wpdb->postmeta,
				'nbs3_offloaded',
				'attachment',
				''
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Complex JOIN query for CLI operations.
		return $wpdb->get_col( $query );
	}

	/**
	 * Process a single attachment for offloading.
	 *
	 * @param int $attachment_id The attachment ID to process.
	 *
	 * @return bool True on success, false on failure.
	 */
	private function process_attachment( $attachment_id ) {
		try {
			return $this->uploader->upload_attachment( $attachment_id );
		} catch ( \Exception $e ) {
			update_post_meta( $attachment_id, 'nbs3_error_log', $e->getMessage() );
			return false;
		}
	}

	/**
	 * Check if an attachment exists.
	 *
	 * @param int $attachment_id The attachment ID to check.
	 *
	 * @return bool True if attachment exists, false otherwise.
	 */
	private function attachment_exists( $attachment_id ) {
		$post = get_post( $attachment_id );
		return $post && 'attachment' === $post->post_type;
	}

	/**
	 * Check if an attachment has previous errors.
	 *
	 * @param int $attachment_id The attachment ID to check.
	 *
	 * @return bool True if attachment has errors, false otherwise.
	 */
	private function has_errors( $attachment_id ) {
		$errors = get_post_meta( $attachment_id, 'nbs3_error_log', true );
		return ! empty( $errors );
	}

	/**
	 * Display offload operation results.
	 *
	 * @param int $successful Number of successfully offloaded attachments.
	 * @param int $failed     Number of failed attachments.
	 * @param int $skipped    Number of skipped attachments.
	 * @param int $total      Total number of attachments processed.
	 *
	 * @return void
	 */
	private function display_results( $successful, $failed, $skipped, $total ) {
		if ( $successful > 0 ) {
			\WP_CLI::success( "Successfully offloaded {$successful} attachment(s)." );
		}

		if ( $failed > 0 ) {
			\WP_CLI::warning( "{$failed} attachment(s) failed to offload." );
		}

		if ( $skipped > 0 ) {
			\WP_CLI::log( "{$skipped} attachment(s) were skipped." );
		}

		\WP_CLI::log( "Summary: {$successful} successful, {$failed} failed, {$skipped} skipped out of {$total} total." );

		if ( $failed > 0 ) {
			\WP_CLI::log( 'Check attachment error logs or use the Media page for detailed error information.' );
		}
	}
}
