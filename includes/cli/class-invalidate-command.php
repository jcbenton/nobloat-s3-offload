<?php
/**
 * WP CLI command for invalidating offload status without downloading files.
 *
 * @package NBS3
 * @subpackage CLI
 */

namespace NBS3\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * WP CLI command for invalidating offload status.
 *
 * Use this command when S3 files have been deleted externally and you need
 * to clear the offload metadata so WordPress uses local files instead.
 */
class InvalidateCommand {

	/**
	 * Invalidate offload status for media attachments and/or Bricks files.
	 *
	 * Clears offload metadata without downloading files from S3. Use this when
	 * files have been deleted from S3 externally (bucket changed, manual deletion,
	 * etc.) and you need WordPress to use local files instead.
	 *
	 * ## OPTIONS
	 *
	 * [<attachment_ids>]
	 * : Attachment ID(s) to invalidate. Can be a single ID or comma-separated list.
	 *
	 * [--all]
	 * : Invalidate all offloaded attachments.
	 *
	 * [--bricks]
	 * : Invalidate Bricks CSS and theme assets sync status.
	 *
	 * [--limit=<number>]
	 * : Maximum number of attachments to process (use with --all).
	 *
	 * [--dry-run]
	 * : Show what would be invalidated without making changes.
	 *
	 * ## EXAMPLES
	 *
	 *     # Invalidate a specific attachment by ID
	 *     wp nbs3 invalidate 123
	 *
	 *     # Invalidate multiple specific attachments
	 *     wp nbs3 invalidate 123,456,789
	 *
	 *     # Invalidate all offloaded attachments
	 *     wp nbs3 invalidate --all
	 *
	 *     # Invalidate all offloaded attachments AND Bricks sync status
	 *     wp nbs3 invalidate --all --bricks
	 *
	 *     # Invalidate only Bricks sync status
	 *     wp nbs3 invalidate --bricks
	 *
	 *     # Invalidate up to 50 attachments
	 *     wp nbs3 invalidate --all --limit=50
	 *
	 *     # Preview what would be invalidated
	 *     wp nbs3 invalidate --all --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (flags).
	 *
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$all     = isset( $assoc_args['all'] );
		$bricks  = isset( $assoc_args['bricks'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;
		$dry_run = isset( $assoc_args['dry-run'] );

		$attachment_ids = $this->parse_attachment_ids( $args );

		$did_something = false;

		// Handle Bricks invalidation.
		if ( $bricks ) {
			$this->invalidate_bricks( $dry_run );
			$did_something = true;
		}

		// Handle attachment invalidation.
		if ( ! empty( $attachment_ids ) ) {
			$this->invalidate_specific_attachments( $attachment_ids, $dry_run );
			$did_something = true;
		} elseif ( $all ) {
			$this->invalidate_all_attachments( $limit, $dry_run );
			$did_something = true;
		}

		if ( ! $did_something ) {
			\WP_CLI::error( 'Please specify attachment ID(s), use --all flag, or use --bricks flag.' );
		}
	}

	/**
	 * Invalidate Bricks CSS and theme assets sync status.
	 *
	 * @param bool $dry_run Whether this is a dry run.
	 *
	 * @return void
	 */
	private function invalidate_bricks( $dry_run ) {
		$css_files    = get_option( 'nbs3_synced_bricks_files', array() );
		$theme_assets = get_option( 'nbs3_synced_bricks_theme_assets', array() );

		$css_count    = count( $css_files );
		$assets_count = count( $theme_assets );

		if ( 0 === $css_count && 0 === $assets_count ) {
			\WP_CLI::log( 'No Bricks sync status found to invalidate.' );
			return;
		}

		if ( $dry_run ) {
			\WP_CLI::log( "DRY RUN: Would invalidate {$css_count} Bricks CSS file(s) and {$assets_count} theme asset(s)." );
			return;
		}

		\WP_CLI::log( 'Invalidating Bricks sync status...' );

		if ( $css_count > 0 ) {
			delete_option( 'nbs3_synced_bricks_files' );
			\WP_CLI::success( "Invalidated {$css_count} Bricks CSS file(s)." );
		}

		if ( $assets_count > 0 ) {
			delete_option( 'nbs3_synced_bricks_theme_assets' );
			\WP_CLI::success( "Invalidated {$assets_count} Bricks theme asset(s)." );
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
	 * Invalidate specific attachments by ID.
	 *
	 * @param array $attachment_ids Array of attachment IDs to invalidate.
	 * @param bool  $dry_run        Whether this is a dry run.
	 *
	 * @return void
	 */
	private function invalidate_specific_attachments( $attachment_ids, $dry_run ) {
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
				\WP_CLI::log( "DRY RUN: Would invalidate attachment ID {$attachment_id}" );
				++$successful;
				continue;
			}

			if ( $this->invalidate_attachment( $attachment_id ) ) {
				\WP_CLI::success( "Invalidated attachment ID {$attachment_id}" );
				++$successful;
			} else {
				\WP_CLI::error( "Failed to invalidate attachment ID {$attachment_id}", false );
				++$failed;
			}
		}

		$this->display_results( $successful, $failed, $skipped, $total, $dry_run );
	}

	/**
	 * Invalidate all offloaded attachments.
	 *
	 * @param int  $limit   Maximum number of attachments to process.
	 * @param bool $dry_run Whether this is a dry run.
	 *
	 * @return void
	 */
	private function invalidate_all_attachments( $limit, $dry_run ) {
		$attachment_ids = $this->get_offloaded_attachments( $limit );
		$total          = count( $attachment_ids );

		if ( 0 === $total ) {
			\WP_CLI::success( 'No offloaded attachments found to invalidate.' );
			return;
		}

		if ( $dry_run ) {
			\WP_CLI::log( "DRY RUN: Found {$total} offloaded attachment(s) to invalidate..." );
		} else {
			\WP_CLI::log( "Found {$total} offloaded attachment(s) to invalidate..." );
			\WP_CLI::confirm( "This will clear offload metadata for {$total} attachments (files on S3 will NOT be deleted). Continue?" );
		}

		$successful = 0;
		$failed     = 0;
		$skipped    = 0;

		foreach ( $attachment_ids as $index => $attachment_id ) {
			$current = $index + 1;
			\WP_CLI::log( "Processing attachment ID {$attachment_id} ({$current}/{$total})..." );

			if ( $dry_run ) {
				\WP_CLI::log( "DRY RUN: Would invalidate attachment ID {$attachment_id}" );
				++$successful;
				continue;
			}

			if ( $this->invalidate_attachment( $attachment_id ) ) {
				\WP_CLI::success( "Invalidated attachment ID {$attachment_id}" );
				++$successful;
			} else {
				\WP_CLI::error( "Failed to invalidate attachment ID {$attachment_id}", false );
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

		if ( $limit > 0 ) {
			$query = $wpdb->prepare(
				'SELECT p.ID FROM %i p
				INNER JOIN %i pm ON p.ID = pm.post_id AND pm.meta_key = %s
				WHERE p.post_type = %s
				AND pm.meta_value = %s
				ORDER BY p.post_date DESC
				LIMIT %d',
				$wpdb->posts,
				$wpdb->postmeta,
				'nbs3_offloaded',
				'attachment',
				'1',
				$limit
			);
		} else {
			$query = $wpdb->prepare(
				'SELECT p.ID FROM %i p
				INNER JOIN %i pm ON p.ID = pm.post_id AND pm.meta_key = %s
				WHERE p.post_type = %s
				AND pm.meta_value = %s
				ORDER BY p.post_date DESC',
				$wpdb->posts,
				$wpdb->postmeta,
				'nbs3_offloaded',
				'attachment',
				'1'
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is fully prepared above.
		return $wpdb->get_col( $query );
	}

	/**
	 * Invalidate a single attachment's offload status.
	 *
	 * Simply clears the offload metadata without downloading or deleting files.
	 *
	 * @param int $attachment_id The attachment ID to invalidate.
	 *
	 * @return bool True on success, false on failure.
	 */
	private function invalidate_attachment( $attachment_id ) {
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
	 * Display invalidation operation results.
	 *
	 * @param int  $successful Number of successfully invalidated attachments.
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
			if ( $dry_run ) {
				\WP_CLI::success( "{$prefix}{$successful} attachment(s) would be invalidated." );
			} else {
				\WP_CLI::success( "Successfully invalidated {$successful} attachment(s)." );
			}
		}

		if ( $failed > 0 ) {
			\WP_CLI::warning( "{$failed} attachment(s) failed to invalidate." );
		}

		if ( $skipped > 0 ) {
			\WP_CLI::log( "{$skipped} attachment(s) were skipped." );
		}

		\WP_CLI::log( "Summary: {$successful} successful, {$failed} failed, {$skipped} skipped out of {$total} total." );
	}
}
