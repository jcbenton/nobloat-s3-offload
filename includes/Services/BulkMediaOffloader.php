<?php

namespace NBS3\Services;

use NBS3\Services\CloudAttachmentUploader;
use NBS3\S3Provider;
use NBS3\Abstracts\WP_Background_Processing\WP_Background_Process;

class BulkMediaOffloader extends WP_Background_Process
{
    protected $prefix = 'nbs3';
    protected $action = 'bulk_offload_media_process';

    private CloudAttachmentUploader $cloudAttachmentUploader;

    public function __construct(S3Provider $s3Provider)
    {
        parent::__construct();
        $this->cloudAttachmentUploader = new CloudAttachmentUploader($s3Provider);
    }

    protected function task($item)
    {
        try {
            $result = $this->cloudAttachmentUploader->uploadAttachment($item);
            $this->update_processed_count($result);

            return false;
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for bulk offload failures
            error_log("NBS3 Bulk Offload Error (ID: {$item}): " . $e->getMessage());

            // Mark as processed with error
            $this->update_processed_count(false);

            // Add error to attachment meta
            update_post_meta($item, 'nbs3_error_log', $e->getMessage());

            return false; // Move on to the next item rather than retrying
        }
    }

    protected function complete()
    {
        parent::complete();
        nbs3_update_bulk_offload_data(['status' => 'completed']);
    }

    public function update_processed_count($result_status)
    {
        $bulk_offload_data = nbs3_get_bulk_offload_data();
        $processed_count = $bulk_offload_data['processed'];
        $processed_count++;
        $errors = $bulk_offload_data['errors'] ?? 0;

        if ($result_status !== true) {
            $errors++;
        }

        nbs3_update_bulk_offload_data([
            'processed' => $processed_count,
            'total' => $bulk_offload_data['total'],
            'status' => $bulk_offload_data['status'],
            'errors' => $errors,
        ]);
    }

    public function get_identifier()
    {
        return $this->identifier;
    }
}
