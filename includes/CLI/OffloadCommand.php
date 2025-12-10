<?php

namespace NBS3\CLI;

use NBS3\Services\CloudAttachmentUploader;
use NBS3\S3Provider;
use NBS3\Traits\OffloaderTrait;

/**
 * WP CLI command for media offloading operations.
 */
class OffloadCommand
{
    use OffloaderTrait;

    private $uploader;

    public function __construct()
    {
        try {
            $bucket = nbs3_get_credential('bucket');
            if (empty($bucket)) {
                \WP_CLI::error('No S3 credentials configured. Please configure your S3 settings first.');
            }

            $s3Provider = new S3Provider();
            $this->uploader = new CloudAttachmentUploader($s3Provider);
        } catch (\Exception $e) {
            \WP_CLI::error('Failed to initialize S3 provider: ' . $e->getMessage());
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
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments (flags).
     */
    public function __invoke($args, $assoc_args)
    {
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 0;
        $skip_failed = isset($assoc_args['skip-failed']);

        $attachment_ids = $this->parseAttachmentIds($args);

        if (!empty($attachment_ids)) {
            $this->offloadSpecificAttachments($attachment_ids, $skip_failed);
        } else {
            $this->offloadAllAttachments($limit, $skip_failed);
        }
    }

    private function parseAttachmentIds($args)
    {
        if (empty($args[0])) {
            return [];
        }

        $ids_string = $args[0];

        if (strpos($ids_string, ',') !== false) {
            $ids = explode(',', $ids_string);
            return array_map('intval', array_filter(array_map('trim', $ids)));
        }

        $id = intval($ids_string);
        return $id > 0 ? [$id] : [];
    }

    private function offloadSpecificAttachments($attachment_ids, $skip_failed)
    {
        $total = count($attachment_ids);

        \WP_CLI::log("Processing {$total} specific attachment(s)...");

        $successful = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($attachment_ids as $index => $attachment_id) {
            \WP_CLI::log("Processing attachment ID {$attachment_id}...");

            if (!$this->attachmentExists($attachment_id)) {
                \WP_CLI::warning("Attachment ID {$attachment_id} not found, skipping.");
                $skipped++;
                continue;
            }

            if ($this->is_offloaded($attachment_id)) {
                \WP_CLI::log("Attachment ID {$attachment_id} already offloaded, skipping.");
                $skipped++;
                continue;
            }

            if ($skip_failed && $this->hasErrors($attachment_id)) {
                \WP_CLI::log("Attachment ID {$attachment_id} has previous errors, skipping.");
                $skipped++;
                continue;
            }

            if ($this->processAttachment($attachment_id)) {
                \WP_CLI::success("Successfully offloaded attachment ID {$attachment_id}");
                $successful++;
            } else {
                $failed++;
                $errors = get_post_meta($attachment_id, 'nbs3_error_log', true);
                $error_message = is_array($errors) ? implode('; ', $errors) : $errors;
                \WP_CLI::error("Failed to offload attachment ID {$attachment_id}: {$error_message}", false);
            }
        }

        $this->displayResults($successful, $failed, $skipped, $total);
    }

    private function offloadAllAttachments($limit, $skip_failed)
    {
        $attachment_ids = $this->getEligibleAttachments($limit, $skip_failed);
        $total = count($attachment_ids);

        if ($total === 0) {
            \WP_CLI::success('No eligible attachments found for offloading.');
            return;
        }

        \WP_CLI::log("Found {$total} eligible attachment(s) for offloading...");

        $successful = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($attachment_ids as $index => $attachment_id) {
            $current = $index + 1;
            \WP_CLI::log("Processing attachment ID {$attachment_id} ({$current}/{$total})...");

            if ($this->is_offloaded($attachment_id)) {
                \WP_CLI::log("Attachment ID {$attachment_id} already offloaded, skipping.");
                $skipped++;
                continue;
            }

            if ($this->processAttachment($attachment_id)) {
                \WP_CLI::success("Successfully offloaded attachment ID {$attachment_id}");
                $successful++;
            } else {
                $failed++;
                $errors = get_post_meta($attachment_id, 'nbs3_error_log', true);
                $error_message = is_array($errors) ? implode('; ', $errors) : $errors;
                \WP_CLI::error("Failed to offload attachment ID {$attachment_id}: {$error_message}", false);
            }
        }

        $this->displayResults($successful, $failed, $skipped, $total);
    }

    private function getEligibleAttachments($limit, $skip_failed)
    {
        global $wpdb;

        $limit = absint($limit);

        if ($skip_failed) {
            $query = "SELECT p.ID FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'nbs3_offloaded'
                LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'nbs3_error_log'
                WHERE p.post_type = 'attachment'
                AND (pm1.meta_value IS NULL OR pm1.meta_value = '')
                AND pm2.meta_id IS NULL
                ORDER BY p.post_date ASC";

            if ($limit > 0) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Base query is safe, only LIMIT is parameterized
                $query = $wpdb->prepare($query . " LIMIT %d", $limit);
            }
        } else {
            $query = "SELECT p.ID FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'nbs3_offloaded'
                WHERE p.post_type = 'attachment'
                AND (pm.meta_value IS NULL OR pm.meta_value = '')
                ORDER BY p.post_date ASC";

            if ($limit > 0) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Base query is safe, only LIMIT is parameterized
                $query = $wpdb->prepare($query . " LIMIT %d", $limit);
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Complex JOIN query for CLI operations
        return $wpdb->get_col($query);
    }

    private function processAttachment($attachment_id)
    {
        try {
            return $this->uploader->uploadAttachment($attachment_id);
        } catch (\Exception $e) {
            update_post_meta($attachment_id, 'nbs3_error_log', $e->getMessage());
            return false;
        }
    }

    private function attachmentExists($attachment_id)
    {
        $post = get_post($attachment_id);
        return $post && $post->post_type === 'attachment';
    }

    private function hasErrors($attachment_id)
    {
        $errors = get_post_meta($attachment_id, 'nbs3_error_log', true);
        return !empty($errors);
    }

    private function displayResults($successful, $failed, $skipped, $total)
    {
        if ($successful > 0) {
            \WP_CLI::success("Successfully offloaded {$successful} attachment(s).");
        }

        if ($failed > 0) {
            \WP_CLI::warning("{$failed} attachment(s) failed to offload.");
        }

        if ($skipped > 0) {
            \WP_CLI::log("{$skipped} attachment(s) were skipped.");
        }

        \WP_CLI::log("Summary: {$successful} successful, {$failed} failed, {$skipped} skipped out of {$total} total.");

        if ($failed > 0) {
            \WP_CLI::log('Check attachment error logs or use the Media Overview page for detailed error information.');
        }
    }
}
