<?php

namespace NBS3\CLI;

use NBS3\S3Provider;

/**
 * WP CLI command for reverting offloaded media back to local storage.
 */
class RevertCommand {

	private S3Provider $s3Provider;

	public function __construct() {
		try {
			$bucket = nbs3_get_credential( 'bucket' );
			if ( empty( $bucket ) ) {
				\WP_CLI::error( 'No S3 credentials configured. Please configure your S3 settings first.' );
			}

			$this->s3Provider = new S3Provider();
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
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments (flags).
	 */
	public function __invoke( $args, $assoc_args ) {
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;
		$keep_s3 = isset( $assoc_args['keep-s3'] );
		$dry_run = isset( $assoc_args['dry-run'] );

		$attachment_ids = $this->parseAttachmentIds( $args );

		if ( ! empty( $attachment_ids ) ) {
			$this->revertSpecificAttachments( $attachment_ids, $keep_s3, $dry_run );
		} else {
			$this->revertAllAttachments( $limit, $keep_s3, $dry_run );
		}
	}

	private function parseAttachmentIds( $args ) {
		if ( empty( $args[0] ) ) {
			return array();
		}

		$ids_string = $args[0];

		if ( strpos( $ids_string, ',' ) !== false ) {
			$ids = explode( ',', $ids_string );
			return array_map( 'intval', array_filter( array_map( 'trim', $ids ) ) );
		}

		$id = intval( $ids_string );
		return $id > 0 ? array( $id ) : array();
	}

	private function revertSpecificAttachments( $attachment_ids, $keep_s3, $dry_run ) {
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

			if ( ! $this->attachmentExists( $attachment_id ) ) {
				\WP_CLI::warning( "Attachment ID {$attachment_id} not found, skipping." );
				++$skipped;
				continue;
			}

			if ( ! $this->isOffloaded( $attachment_id ) ) {
				\WP_CLI::log( "Attachment ID {$attachment_id} is not offloaded, skipping." );
				++$skipped;
				continue;
			}

			if ( $dry_run ) {
				\WP_CLI::log( "DRY RUN: Would revert attachment ID {$attachment_id}" );
				++$successful;
				continue;
			}

			if ( $this->revertAttachment( $attachment_id, $keep_s3 ) ) {
				\WP_CLI::success( "Successfully reverted attachment ID {$attachment_id}" );
				++$successful;
			} else {
				\WP_CLI::error( "Failed to revert attachment ID {$attachment_id}", false );
				++$failed;
			}
		}

		$this->displayResults( $successful, $failed, $skipped, $total, $dry_run );
	}

	private function revertAllAttachments( $limit, $keep_s3, $dry_run ) {
		$attachment_ids = $this->getOffloadedAttachments( $limit );
		$total          = count( $attachment_ids );

		if ( $total === 0 ) {
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

			if ( $this->revertAttachment( $attachment_id, $keep_s3 ) ) {
				\WP_CLI::success( "Successfully reverted attachment ID {$attachment_id}" );
				++$successful;
			} else {
				\WP_CLI::error( "Failed to revert attachment ID {$attachment_id}", false );
				++$failed;
			}
		}

		$this->displayResults( $successful, $failed, $skipped, $total, $dry_run );
	}

	private function getOffloadedAttachments( $limit ) {
		global $wpdb;

		$query = "SELECT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'nbs3_offloaded'
            WHERE p.post_type = 'attachment'
            AND pm.meta_value = '1'
            ORDER BY p.post_date DESC";

		if ( $limit > 0 ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Base query is safe, only LIMIT is parameterized
			$query = $wpdb->prepare( $query . ' LIMIT %d', $limit );
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Complex JOIN query for CLI operations
		return $wpdb->get_col( $query );
	}

	private function revertAttachment( $attachment_id, $keep_s3 ) {
		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$nbs3_path     = get_post_meta( $attachment_id, 'nbs3_path', true );

		if ( empty( $attached_file ) ) {
			\WP_CLI::warning( "No attached file found for attachment ID {$attachment_id}" );
			return false;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		// Build the S3 key
		$file_name = basename( $attached_file );
		if ( ! empty( $nbs3_path ) ) {
			$s3_key = trailingslashit( nbs3_sanitize_path( $nbs3_path ) ) . $file_name;
		} else {
			$s3_key = $file_name;
		}

		// Local file path
		$local_path = trailingslashit( $base_dir ) . $attached_file;

		// Ensure directory exists
		$local_dir = dirname( $local_path );
		if ( ! file_exists( $local_dir ) ) {
			wp_mkdir_p( $local_dir );
		}

		// Download main file
		if ( ! $this->s3Provider->downloadFile( $s3_key, $local_path ) ) {
			\WP_CLI::warning( "Failed to download main file for attachment ID {$attachment_id}" );
			return false;
		}

		\WP_CLI::log( "  Downloaded: {$file_name}" );

		// Download thumbnail sizes
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$s3_base_dir    = ! empty( $nbs3_path ) ? trailingslashit( nbs3_sanitize_path( $nbs3_path ) ) : '';
			$local_base_dir = dirname( $local_path );

			foreach ( $metadata['sizes'] as $size => $sizeinfo ) {
				if ( empty( $sizeinfo['file'] ) ) {
					continue;
				}

				$size_file       = $sizeinfo['file'];
				$size_s3_key     = $s3_base_dir . $size_file;
				$size_local_path = trailingslashit( $local_base_dir ) . $size_file;

				if ( $this->s3Provider->downloadFile( $size_s3_key, $size_local_path ) ) {
					\WP_CLI::log( "  Downloaded: {$size_file}" );
				} else {
					\WP_CLI::warning( "  Failed to download: {$size_file}" );
				}
			}

			// Download original image if exists
			if ( ! empty( $metadata['original_image'] ) ) {
				$orig_s3_key     = $s3_base_dir . $metadata['original_image'];
				$orig_local_path = trailingslashit( $local_base_dir ) . $metadata['original_image'];

				if ( $this->s3Provider->downloadFile( $orig_s3_key, $orig_local_path ) ) {
					\WP_CLI::log( "  Downloaded: {$metadata['original_image']}" );
				}
			}
		}

		// Delete from S3 if not keeping
		if ( ! $keep_s3 ) {
			if ( $this->s3Provider->deleteAttachment( $attachment_id ) ) {
				\WP_CLI::log( '  Deleted from S3' );
			} else {
				\WP_CLI::warning( '  Failed to delete from S3 (files may remain in cloud)' );
			}
		}

		// Clear offload metadata
		delete_post_meta( $attachment_id, 'nbs3_offloaded' );
		delete_post_meta( $attachment_id, 'nbs3_path' );
		delete_post_meta( $attachment_id, 'nbs3_provider' );
		delete_post_meta( $attachment_id, 'nbs3_error_log' );

		return true;
	}

	private function attachmentExists( $attachment_id ) {
		$post = get_post( $attachment_id );
		return $post && $post->post_type === 'attachment';
	}

	private function isOffloaded( $attachment_id ) {
		return get_post_meta( $attachment_id, 'nbs3_offloaded', true ) === '1';
	}

	private function displayResults( $successful, $failed, $skipped, $total, $dry_run ) {
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
