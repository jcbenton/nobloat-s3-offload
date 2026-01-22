<?php

namespace NBS3\Observers;

defined( 'ABSPATH' ) || exit;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Traits\OffloaderTrait;

class OffloadStatusObserver implements ObserverInterface
{
    use OffloaderTrait;

    /**
     * Cloud provider instance.
     *
     * @var S3Provider
     */
    private S3Provider $s3Provider;

    /**
     * Meta keys for offload information.
     */
    private const META_OFFLOADED_AT = 'nbs3_offloaded_at';
    private const META_PROVIDER = 'nbs3_provider';
    private const META_BUCKET = 'nbs3_bucket';

    /**
     * Constructor.
     *
     * @param S3Provider $s3Provider The cloud provider instance.
     */
    public function __construct(S3Provider $s3Provider)
    {
        $this->s3Provider = $s3Provider;
    }

    /**
     * Register the observer with WordPress hooks.
     *
     * @return void
     */
    public function register(): void
    {
        add_filter('attachment_fields_to_edit', [$this, 'run'], 10, 2);
    }

    /**
     * Display the offload status of the attachment.
     *
     * @param array    $form_fields The form fields for the attachment.
     * @param \WP_Post $post        The attachment post object.
     * @return array The modified form fields.
     */
    public function run(array $form_fields, \WP_Post $post): array
    {
        $status_details = $this->getOffloadStatusDetails($post->ID);

        $form_fields['nbs3_offload_status'] = [
            'label' => __('Offload Status:', 'nobloat-s3-offload'),
            'input' => 'html',
            'html'  => $this->generateStatusHtml($status_details),
        ];

        return $form_fields;
    }

    /**
     * Get offload status details for an attachment.
     *
     * @param int $post_id The attachment post ID.
     * @return array The offload status details.
     */
    private function getOffloadStatusDetails(int $post_id): array
    {
        if ($this->is_offloaded($post_id)) {
            return [
                'status' => $this->getOffloadedStatus($post_id),
                'color' => 'green',
            ];
        }

        if ($this->has_errors($post_id)) {
            // get url for this settings page: nbs3_media_overview
            $media_overview_page = admin_url('admin.php?page=nbs3_media_overview');
            $status = sprintf(
                /* translators: %s: URL to Media Overview page */
                __('Offload failed - Action required. View details in <a href="%s">Media Overview</a>', 'nobloat-s3-offload'),
                esc_url($media_overview_page)
            );
            return [
                'status' => $status,
                'color' => '#D32F2F',
            ];
        }


        return [
            'status' => __('Not offloaded yet.', 'nobloat-s3-offload'),
            'color' => '#D32F2F',
        ];
    }

    /**
     * Get the offloaded status message.
     *
     * @param int $post_id The attachment post ID.
     * @return string The formatted status message.
     */
    private function getOffloadedStatus(int $post_id): string
    {
        $offloaded_at = get_post_meta($post_id, self::META_OFFLOADED_AT, true);
        $provider = get_post_meta($post_id, self::META_PROVIDER, true);
        $bucket = get_post_meta($post_id, self::META_BUCKET, true);

        /* translators: %s: cloud provider name (e.g., S3) */
        $status = sprintf(__('Offloaded to %s', 'nobloat-s3-offload'), $provider);

        if ($bucket) {
            /* translators: %s: bucket name */
            $status .= sprintf(__(' (Bucket: %s)', 'nobloat-s3-offload'), $bucket);
        }

        if ($offloaded_at) {
            $formatted_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $offloaded_at);
            /* translators: %s: formatted date and time */
            $status .= sprintf(__(' on %s', 'nobloat-s3-offload'), $formatted_date);
        }

        return $status;
    }

    /**
     * Generate the HTML for displaying the offload status.
     *
     * @param array $status_details The offload status details.
     * @return string The generated HTML.
     */
    private function generateStatusHtml(array $status_details): string
    {
        return sprintf(
            '<div style="display: flex; align-items: center; height: 100%%; min-height: 30px;">
                <span style="color: %s;">%s</span>
            </div>',
            esc_attr($status_details['color']),
            wp_kses_post($status_details['status'])
        );
    }

    private function has_errors(int $attachment_id): bool
    {
        $errors = get_post_meta($attachment_id, 'nbs3_error_log', true);
        return !empty($errors);
    }
}
