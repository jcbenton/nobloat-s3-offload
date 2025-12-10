<?php

namespace NBS3\Observers;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Services\CloudAttachmentUploader;

class AttachmentUpdateObserver implements ObserverInterface
{
    /**
     * @var S3Provider
     */
    private S3Provider $s3Provider;

    private CloudAttachmentUploader $cloudAttachmentUploader;

    public function __construct(S3Provider $s3Provider)
    {
        $this->s3Provider = $s3Provider;
        $this->cloudAttachmentUploader = new CloudAttachmentUploader($s3Provider);
    }

    public function register(): void
    {
        add_filter('wp_update_attachment_metadata', [$this, 'run'], 99, 2);
    }

    public function run($metadata, $attachment_id)
    {
        // PHPCS ignore reason: Update the attachment's metadata by either restoring or editing it.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        foreach ($trace as $element) {
            switch ($element['function']) {
                case 'wp_save_image':
                    // Right after an image has been edited.
                    $this->cloudAttachmentUploader->uploadUpdatedAttachment($attachment_id, $metadata);
                    break;
                case 'wp_restore_image':
                    // When an image has been restored.
                    break;
            }
        }

        return $metadata;
    }
}
