<?php

namespace NBS3\Observers;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Traits\OffloaderTrait;

/**
 * Observer for rewriting image URLs when WordPress requests specific image sizes.
 * This handles wp_get_attachment_image_src() and similar functions that use image_downsize.
 */
class ImageDownsizeObserver implements ObserverInterface
{
    use OffloaderTrait;

    private S3Provider $s3Provider;

    public function __construct(S3Provider $s3Provider)
    {
        $this->s3Provider = $s3Provider;
    }

    public function register(): void
    {
        add_filter('image_downsize', [$this, 'run'], 10, 3);
    }

    /**
     * Filter image_downsize to return CDN URLs for offloaded images.
     *
     * @param bool|array $downsize Whether to short-circuit the image_downsize function.
     * @param int        $id       Attachment ID.
     * @param string|int[] $size   Requested image size name or array of width and height.
     * @return bool|array Array with URL, width, height, is_intermediate, or false to use default.
     */
    public function run($downsize, $id, $size)
    {
        if (!$this->is_offloaded($id)) {
            return $downsize;
        }

        $domain = $this->s3Provider->getDomain();
        if (empty($domain)) {
            return $downsize;
        }

        $meta = wp_get_attachment_metadata($id);
        if (!$meta) {
            return $downsize;
        }

        $subDir = $this->get_attachment_subdir($id);
        $domain = rtrim($domain, '/');
        $base_url = $domain . '/' . ltrim($subDir, '/');

        // Handle 'full' size
        if ($size === 'full' || $size === 'original') {
            if (!empty($meta['file'])) {
                $url = $base_url . basename($meta['file']);
                $width = $meta['width'] ?? 0;
                $height = $meta['height'] ?? 0;
                return [$url, $width, $height, false];
            }
            return $downsize;
        }

        // Handle named sizes (thumbnail, medium, large, etc.)
        if (is_string($size) && !empty($meta['sizes'][$size])) {
            $size_data = $meta['sizes'][$size];
            $url = $base_url . $size_data['file'];
            return [$url, $size_data['width'], $size_data['height'], true];
        }

        // Handle array sizes [width, height] - find best match
        if (is_array($size) && !empty($meta['sizes'])) {
            $requested_width = $size[0];
            $requested_height = $size[1];

            // Find the best matching size
            $best_match = null;
            $best_diff = PHP_INT_MAX;

            foreach ($meta['sizes'] as $size_name => $size_data) {
                $diff = abs($size_data['width'] - $requested_width) + abs($size_data['height'] - $requested_height);
                if ($diff < $best_diff) {
                    $best_diff = $diff;
                    $best_match = $size_data;
                }
            }

            if ($best_match) {
                $url = $base_url . $best_match['file'];
                return [$url, $best_match['width'], $best_match['height'], true];
            }
        }

        // Fall back to full size if no match found
        if (!empty($meta['file'])) {
            $url = $base_url . basename($meta['file']);
            $width = $meta['width'] ?? 0;
            $height = $meta['height'] ?? 0;
            return [$url, $width, $height, false];
        }

        return $downsize;
    }
}
