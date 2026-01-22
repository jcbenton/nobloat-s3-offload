<?php

namespace NBS3\Observers;

defined( 'ABSPATH' ) || exit;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Traits\OffloaderTrait;

class PostContentImageTagObserver implements ObserverInterface
{
    use OffloaderTrait;

    /**
     * @var S3Provider
     */
    private S3Provider $s3Provider;

    /**
     * The base URL for uploads.
     *
     * @var string
     */
    private string $upload_base_url;

    /**
     * Constructor.
     *
     * @param S3Provider $s3Provider
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
        add_filter('wp_content_img_tag', [$this, 'run'], 10, 3);
    }

    /**
     * Modify the image srcset.
     * @param array $sources
     * @param array $size_array
     * @param array $image_src
     * @param array $image_meta
     * @param int $attachment_id
     * @return array
     */
    public function run($filtered_image, $context, $attachment_id)
    {
        if (!$this->is_offloaded($attachment_id)) {
            return $filtered_image;
        }

        $src_attr = $this->get_image_src($filtered_image);
        if (empty($src_attr)) {
            return $filtered_image;
        }

        $offloaded_image_url = wp_get_attachment_url($attachment_id);
        $filtered_image = str_replace($src_attr, $offloaded_image_url, $filtered_image);

        return $filtered_image;
    }

    private function get_image_src($image_tag)
    {
        $src = '';

        if (preg_match('/src=[\'"]?([^\'" >]+)[\'"]?/i', $image_tag, $matches)) {
            $src = $matches[1];
        }

        return $src;
    }
}
