<?php
/**
 * WP CLI command for reverting offloaded media back to local storage.
 *
 * @package NBS3
 * @subpackage CLI
 */

namespace NBS3\CLI;

use NBS3\S3Provider;

/**
 * WP CLI command for reverting offloaded media back to local storage.
 */
class RevertCommand {

	/**
	 * S3 provider instance.
	 *
	 * @var S3Provider
	 */
	private S3Provider $s3_provider;

	/**
	 * Constructor.
	 *
	 * Initializes the S3 provider.
	 */
	public function __construct() {
		try {
			$bucket = nbs3_get_credential( 'bucket' );
			if ( empty( $bucket ) ) {
				\WP_CLI::error( 'No S3 credentials configured. Please configure your S3 settings first.' );
			}

			$this->s3_provider = new S3Provider();
		} catch ( \Exception $e ) {
			\WP_CLI::error( 'Failed to initialize S3 provider: ' . $e->getMessage() );
		}
	}

	/**
	 * Revert offloaded media attachments back to local storage.
	 *
	 * Downloads files from S3 back to local uploads directory and removes
	 * the offload metadata. Optionally keeps or deletes files from S3.
	 *
	 * ## OPTIONS
	 *
	 * [<attachment_ids>]
	 * : Attachment ID(s) to revert. Can be a single ID or comma-separated list.
	 *
	 * [--limit=<number>]
	 * : Maximum number of attachments to process.
	 *
	 * [--keep-s3]
	 * : Keep files on S3 after downloading (don't delete from cloud).
	 *
	 * [--dry-run]
	 * : Show what would be reverted without making changes.
	 *
	 * ## EXAMPLES
	 *
	 *     # Revert all offloaded media attachments
	 *     wp nbs3 revert
	 *
	 *     # Revert a specific attachment by ID
	 *     wp nbs3 revert 123
	 *
	 *     # Revert multiple specific attachments
	 *     wp nbs3 revert 123,456,789
	 *
	 *     # Revert up to 50 attachments
	 *     wp nbs3 revert --limit=50
	 *
	 *     # Revert but keep files on S3
	 *     wp nbs3 revert --keep-s3
	 *
	 *     # Preview what would be reverted
	 *     wp nbs3 revert --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (flags).
	 *
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;
		$keep_s3 = isset( $assoc_args['keep-s3'] );
		$dry_run = isset( $assoc_args['dry-run'] );

		$attachment_ids = $this->parse_attachment_ids( $args );

		if ( ! empty( $attachment_ids ) ) {
			$this->revert_specific_attachments( $attachment_ids, $keep_s3, $dry_run );
		} else {
			$this->revert_all_attachments( $limit, $keep_s3, $dry_run );
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
	 * Revert specific attachments by ID.
	 *
	 * @param array $attachment_ids Array of attachment IDs to revert.
	 * @param bool  $keep_s3        Whether to keep files on S3 after downloading.
	 * @param bool  $dry_run        Whether this is a dry run.
	 *
	 * @return void
	 */
	private function revert_specific_attachments( $attachment_ids, $keep_s3, $dry_run ) {
		$total = count( $attachment_ids );

		if ( $dry_run ) {
			\WP_CLI::log( "DRY RUN: Would process {$total} specific attachment(s)..." );
		} else {
			\WP_CLI::log( "Processing {$total} specific attachment(s)..." );
		}

		$successful = 0;
		$failed     = 0;
		$skipped    = 0;

		foreach ( $attachment_ids as $attachment_id ) {
			\WP_CLI::log( "Processing attachment ID {$attachment_id}..." );

			if ( ! $this->attachment_exists( $attachment_id ) ) {
				\WP_CLI::warning( "Attachment ID {$attachment_id} not found, skipping." );
				++$skipped;
				continue;
			}

			if ( ! $this->is_offloaded( $attachment_id ) ) {
				\WP_CLI::log( "Attachment ID {$attachment_id} is not offloaded, skipping." );
				++$skipped;
				continue;
			}

			if ( $dry_run ) {
				\WP_CLI::log( "DRY RUN: Would revert attachment ID {$attachment_id}" );
				++$successful;
				continue;
			}

			if ( $this->revert_attachment( $attachment_id, $keep_s3 ) ) {
				\WP_CLI::success( "Successfully reverted attachment ID {$attachment_id}" );
				++$successful;
			} else {
				\WP_CLI::error( "Failed to revert attachment ID {$attachment_id}", false );
				++$failed;
			}
		}

		$this->display_results( $successful, $failed, $skipped, $total, $dry_run );
	}

	/**
	 * Revert all offloaded attachments.
	 *
	 * @param int  $limit   Maximum number of attachments to process.
	 * @param bool $keep_s3 Whether to keep files on S3 after downloading.
	 * @param bool $dry_run Whether this is a dry run.
	 *
	 * @return void
	 */
	private function revert_all_attachments( $limit, $keep_s3, $dry_run ) {
		$attachment_ids = $this->get_offloaded_attachments( $limit );
		$total          = count( $attachment_ids );

		if ( 0 === $total ) {
			\WP_CLI::success( 'No offloaded attachments found to revert.' );
			return;
		}

		if ( $dry_run ) {
			\WP_CLI::log( "DRY RUN: Found {$total} offloaded attachment(s) to revert..." );
		} else {
			\WP_CLI::log( "Found {$total} offloaded attachment(s) to revert..." );

			if ( ! $keep_s3 ) {
				\WP_CLI::confirm( "This will download {$total} files from S3 and delete them from the cloud. Continue?" );
			} else {
				\WP_CLI::confirm( "This will download {$total} files from S3 (keeping copies in cloud). Continue?" );
			}
		}

		$successful = 0;
		$failed     = 0;
		$skipped    = 0;

		foreach ( $attachment_ids as $index => $attachment_id ) {
			$current = $index + 1;
			\WP_CLI::log( "Processing attachment ID {$attachment_id} ({$current}/{$total})..." );

			if ( $dry_run ) {
				\WP_CLI::log( "DRY RUN: Would revert attachment ID {$attachment_id}" );
				++$successful;
				continue;
			}

			if ( $this->revert_attachment( $attachment_id, $keep_s3 ) ) {
				\WP_CLI::success( "Successfully reverted attachment ID {$attachment_id}" );
				++$successful;
			} else {
				\WP_CLI::error( "Failed to revert attachment ID {$attachment_id}", false );
				++$failed;
			}
		}

		$this->display_results( $successful, $failed, $skipped, $total, $dry_run );
	}

	/**
	 * Get offloaded attachments from the database.
	 *
	 * @param int $limit Maximum number of attachments to retrieve.
	 *
	 * @return array Array of attachment IDs.
	 */
	private function get_offloaded_attachments( $limit ) {
		global $wpdb;

		$query = "SELECT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'nbs3_offloaded'
            WHERE p.post_type = 'attachment'
            AND pm.meta_value = '1'
            ORDER BY p.post_date DESC";

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Base query is safe, only LIMIT is parameterized.
			$query = $wpdb->prepare( $query . ' LIMIT %d', $limit );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Complex JOIN query for CLI operations.
		return $wpdb->get_col( $query );
	}

	/**
	 * Revert a single attachment from S3 to local storage.
	 *
	 * @param int  $attachment_id The attachment ID to revert.
	 * @param bool $keep_s3       Whether to keep files on S3 after downloading.
	 *
	 * @return bool True on success, false on failure.
	 */
	private function revert_attachment( $attachment_id, $keep_s3 ) {
		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$nbs3_path     = get_post_meta( $attachment_id, 'nbs3_path', true );

		if ( empty( $attached_file ) ) {
			\WP_CLI::warning( "No attached file found for attachment ID {$attachment_id}" );
			return false;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		// Build the S3 key.
		$file_name = basename( $attached_file );
		if ( ! empty( $nbs3_path ) ) {
			$s3_key = trailingslashit( nbs3_sanitize_path( $nbs3_path ) ) . $file_name;
		} else {
			$s3_key = $file_name;
		}

		// Local file path.
		$local_path = trailingslashit( $base_dir ) . $attached_file;

		// Ensure directory exists.
		$local_dir = dirname( $local_path );
		if ( ! file_exists( $local_dir ) ) {
			wp_mkdir_p( $local_dir );
		}

		// Download main file.
		if ( ! $this->s3_provider->download_file( $s3_key, $local_path ) ) {
			\WP_CLI::warning( "Failed to download main file for attachment ID {$attachment_id}" );
			return false;
		}

		\WP_CLI::log( "  Downloaded: {$file_name}" );

		// Download thumbnail sizes.
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$s3_base_dir    = ! empty( $nbs3_path ) ? trailingslashit( nbs3_sanitize_path( $nbs3_path ) ) : '';
			$local_base_dir = dirname( $local_path );

			foreach ( $metadata['sizes'] as $size => $size_info ) {
				if ( empty( $size_info['file'] ) ) {
					continue;
				}

				$size_file       = $size_info['file'];
				$size_s3_key     = $s3_base_dir . $size_file;
				$size_local_path = trailingslashit( $local_base_dir ) . $size_file;

				if ( $this->s3_provider->download_file( $size_s3_key, $size_local_path ) ) {
					\WP_CLI::log( "  Downloaded: {$size_file}" );
				} else {
					\WP_CLI::warning( "  Failed to download: {$size_file}" );
				}
			}

			// Download original image if exists.
			if ( ! empty( $metadata['original_image'] ) ) {
				$orig_s3_key     = $s3_base_dir . $metadata['original_image'];
				$orig_local_path = trailingslashit( $local_base_dir ) . $metadata['original_image'];

				if ( $this->s3_provider->download_file( $orig_s3_key, $orig_local_path ) ) {
					\WP_CLI::log( "  Downloaded: {$metadata['original_image']}" );
				}
			}
		}

		// Delete from S3 if not keeping.
		if ( ! $keep_s3 ) {
			if ( $this->s3_provider->deleteAttachment( $attachment_id ) ) {
				\WP_CLI::log( '  Deleted from S3.' );
			} else {
				\WP_CLI::warning( '  Failed to delete from S3 (files may remain in cloud).' );
			}
		}

		// Clear offload metadata.
		delete_post_meta( $attachment_id, 'nbs3_offloaded' );
		delete_post_meta( $attachment_id, 'nbs3_path' );
		delete_post_meta( $attachment_id, 'nbs3_provider' );
		delete_post_meta( $attachment_id, 'nbs3_error_log' );

		return true;
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
	 * Check if an attachment is offloaded.
	 *
	 * @param int $attachment_id The attachment ID to check.
	 *
	 * @return bool True if attachment is offloaded, false otherwise.
	 */
	private function is_offloaded( $attachment_id ) {
		return '1' === get_post_meta( $attachment_id, 'nbs3_offloaded', true );
	}

	/**
	 * Display revert operation results.
	 *
	 * @param int  $successful Number of successfully reverted attachments.
	 * @param int  $failed     Number of failed attachments.
	 * @param int  $skipped    Number of skipped attachments.
	 * @param int  $total      Total number of attachments processed.
	 * @param bool $dry_run    Whether this was a dry run.
	 *
	 * @return void
	 */
	private function display_results( $successful, $failed, $skipped, $total, $dry_run ) {
		$prefix = $dry_run ? 'DRY RUN: ' : '';

		if ( $successful > 0 ) {
			\WP_CLI::success( "{$prefix}{$successful} attachment(s) would be reverted." . ( ! $dry_run ? '' : '' ) );
			if ( ! $dry_run ) {
				\WP_CLI::success( "Successfully reverted {$successful} attachment(s)." );
			}
		}

		if ( $failed > 0 ) {
			\WP_CLI::warning( "{$failed} attachment(s) failed to revert." );
		}

		if ( $skipped > 0 ) {
			\WP_CLI::log( "{$skipped} attachment(s) were skipped." );
		}

		\WP_CLI::log( "Summary: {$successful} successful, {$failed} failed, {$skipped} skipped out of {$total} total." );
	}
}
