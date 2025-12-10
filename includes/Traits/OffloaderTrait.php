<?php

namespace NBS3\Traits;

trait OffloaderTrait
{
    private function get_path_prefix(): string
    {
        $settings = get_option('nbs3_settings', []);
        $prefix_active = $settings['path_prefix_active'] ?? false;
        $path_prefix = $settings['path_prefix'] ?? '';

        if (!$prefix_active || empty($path_prefix)) {
            return '';
        }

        return trailingslashit(nbs3_sanitize_path($path_prefix));
    }

    private function get_object_version($attachment_id)
    {
        $existing_version = get_post_meta($attachment_id, 'nbs3_object_version', true);
        if ($existing_version) {
            return trailingslashit($existing_version);
        }

        $settings = get_option('nbs3_settings');
        $object_versioning = isset($settings['object_versioning']) ? $settings['object_versioning'] : '0';

        if (!$object_versioning) {
            return '';
        }

        if (!nbs3_is_media_organized_by_year_month()) {
            $new_version = gmdate('YmdHis');
        } else {
            $new_version = gmdate('dHis');
        }

        update_post_meta($attachment_id, 'nbs3_object_version', $new_version);

        return trailingslashit($new_version);
    }

    public function get_attachment_subdir($attachment_id)
    {
        if ($this->is_offloaded($attachment_id)) {
            return get_post_meta($attachment_id, 'nbs3_path', true);
        }

        $object_version = $this->get_object_version($attachment_id);
        $path_prefix = $this->get_path_prefix();

        $metadata = wp_get_attachment_metadata($attachment_id);
        $file_path = get_attached_file($attachment_id);

        if (isset($metadata['file'])) {
            $dirname = nbs3_is_media_organized_by_year_month() ? trailingslashit(dirname($metadata['file'])) : "";
            return $path_prefix . $dirname . $object_version;
        }

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        $relative_path = str_replace($base_dir . '/', '', $file_path);

        $path_parts = explode('/', trim($relative_path, '/'), 3);

        $response = '';
        if (count($path_parts) >= 2 && is_numeric($path_parts[0]) && is_numeric($path_parts[1])) {
            $response = trailingslashit($path_parts[0] . '/' . $path_parts[1]);
        }

        return $path_prefix . $response . $object_version;
    }

    private function uniqueMetaDataSizes($sizes)
    {
        $uniqueSizes = [];
        $dimensionMap = [];

        foreach ($sizes as $name => $sizeInfo) {
            $dimension = $sizeInfo['width'] . 'x' . $sizeInfo['height'];

            if (!isset($dimensionMap[$dimension])) {
                $dimensionMap[$dimension] = $name;
                $uniqueSizes[$name] = $sizeInfo;
            } else {
                $existingName = $dimensionMap[$dimension];
                if ($sizeInfo['filesize'] > $uniqueSizes[$existingName]['filesize']) {
                    unset($uniqueSizes[$existingName]);
                    $dimensionMap[$dimension] = $name;
                    $uniqueSizes[$name] = $sizeInfo;
                }
            }
        }

        return $uniqueSizes;
    }

    private function is_offloaded($post_id)
    {
        return (bool) get_post_meta($post_id, 'nbs3_offloaded', true);
    }

    private function shouldDeleteLocal()
    {
        $settings = get_option('nbs3_settings');
        $retention_policy = isset($settings['retention_policy']) ? $settings['retention_policy'] : '0';

        return intval((string) $retention_policy);
    }

    private function shouldDeleteCloudFiles($post)
    {
        $settings = get_option('nbs3_settings');
        $mirror_delete = false;
        if (isset($settings['mirror_delete'])) {
            $mirror_delete = $settings['mirror_delete'] == '1';
        }

        return $mirror_delete && $post->post_type === 'attachment' && $this->is_offloaded($post->ID);
    }
}
