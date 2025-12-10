<?php

namespace NBS3;

use NBS3Vendor\Aws\S3\S3Client;

/**
 * S3 Provider - handles all S3-compatible storage operations
 */
class S3Provider
{
    protected $s3Client;

    public function getClient()
    {
        if ($this->s3Client === null) {
            $endpoint = nbs3_get_credential('endpoint');
            $region = nbs3_get_credential('region') ?: 'us-east-1';
            $key = nbs3_get_credential('key');
            $secret = nbs3_get_credential('secret');
            $path_style = nbs3_get_credential('path_style_endpoint');

            $config = [
                'version' => 'latest',
                'region' => $region,
                'use_aws_shared_config_files' => false,
                'credentials' => [
                    'key' => $key,
                    'secret' => $secret,
                ],
            ];

            // Add endpoint for S3-compatible services (R2, Spaces, MinIO, etc.)
            if (!empty($endpoint)) {
                $config['endpoint'] = nbs3_normalize_url($endpoint);
            }

            // Use path-style endpoints if configured
            if (!empty($path_style)) {
                $config['use_path_style_endpoint'] = true;
            }

            $this->s3Client = new S3Client($config);
        }

        return $this->s3Client;
    }

    public function getBucket()
    {
        return nbs3_get_credential('bucket');
    }

    public function getDomain()
    {
        $domain = nbs3_get_credential('domain');
        if (!empty($domain)) {
            return nbs3_normalize_url($domain);
        }
        return '';
    }

    /**
     * Upload a file to S3
     */
    public function uploadFile($file, $key)
    {
        $client = $this->getClient();
        try {
            $params = [
                'Bucket' => $this->getBucket(),
                'Key' => $key,
                'SourceFile' => $file,
            ];

            // Only add ACL if configured (for legacy buckets that allow ACLs)
            // Default is 'none' which relies on bucket policy for access control
            $acl = nbs3_get_setting('s3_object_acl', 'none');
            if ($acl !== 'none') {
                $params['ACL'] = $acl;
            }

            $result = $client->putObject($params);
            return $client->getObjectUrl($this->getBucket(), $key);
        } catch (\Exception $e) {
            error_log("NBS3: Error uploading file to S3: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Check if an object exists in the bucket
     */
    public function objectExists(string $key): bool
    {
        $client = $this->getClient();
        try {
            $client->headObject([
                'Bucket' => $this->getBucket(),
                'Key' => $key,
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check the connection to S3
     */
    public function checkConnection()
    {
        $client = $this->getClient();
        try {
            $result = $client->headBucket([
                'Bucket' => $this->getBucket(),
                '@http' => [
                    'timeout' => 5,
                ],
            ]);
            return true;
        } catch (\Exception $e) {
            error_log("NBS3: Error checking connection: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Delete an attachment from S3
     */
    public function deleteAttachment($attachment_id)
    {
        try {
            $key = $this->getAttachmentKey($attachment_id);
            $this->deleteS3Object($key);

            $metadata = wp_get_attachment_metadata($attachment_id);

            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                $base_dir = trailingslashit(dirname($key));
                $this->deleteAttachmentSizes($metadata, $base_dir);
                $this->deleteImageBackupSizes($attachment_id, $base_dir);
            }

            return true;
        } catch (\Exception $e) {
            error_log("NBS3: Error deleting file from S3: {$e->getMessage()}");
            return false;
        }
    }

    private function deleteAttachmentSizes(array $metadata, string $base_dir): void
    {
        $sizes = $metadata['sizes'];

        foreach ($sizes as $size => $sizeinfo) {
            $size_files = $this->getFilesFromSizeData($sizeinfo);

            foreach ($size_files as $size_file) {
                $thumbnail_key = $base_dir . $size_file;
                $this->deleteS3Object($thumbnail_key);
            }
        }

        if (!empty($metadata['original_image'])) {
            $original_image = $base_dir . $metadata['original_image'];
            $this->deleteS3Object($original_image);
        }

        $root_source_files = $this->getRootSourceFiles($metadata);
        foreach ($root_source_files as $source_file) {
            $source_key = $base_dir . $source_file;
            $this->deleteS3Object($source_key);
        }
    }

    private function getFilesFromSizeData(array $sizeData): array
    {
        $files = [];

        if (!empty($sizeData['file'])) {
            $files[] = $sizeData['file'];
        }

        if (!empty($sizeData['sources']) && is_array($sizeData['sources'])) {
            foreach ($sizeData['sources'] as $source) {
                if (!empty($source['file']) && !in_array($source['file'], $files, true)) {
                    $files[] = $source['file'];
                }
            }
        }

        return $files;
    }

    private function getRootSourceFiles(array $metadata): array
    {
        $files = [];

        if (!empty($metadata['sources']) && is_array($metadata['sources'])) {
            $mainFile = $metadata['file'] ?? '';
            foreach ($metadata['sources'] as $source) {
                if (!empty($source['file']) && $source['file'] !== $mainFile) {
                    $files[] = $source['file'];
                }
            }
        }

        return $files;
    }

    private function deleteImageBackupSizes($attachment_id, $base_dir)
    {
        $backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);

        if (!is_array($backup_sizes)) {
            return;
        }

        foreach ($backup_sizes as $size => $sizeinfo) {
            $backup_key = $base_dir . $sizeinfo['file'];
            $this->deleteS3Object($backup_key);
        }
    }

    private function getAttachmentKey(int $attachment_id): string
    {
        $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        $nbs3_path = get_post_meta($attachment_id, 'nbs3_path', true);

        if (empty($attached_file)) {
            throw new \Exception("Unable to find attached file for attachment ID {$attachment_id}");
        }

        $file_name = basename($attached_file);
        $file_name = sanitize_file_name($file_name);

        if (empty($file_name)) {
            throw new \Exception("Invalid file name for attachment ID {$attachment_id}");
        }

        if (!empty($nbs3_path)) {
            $nbs3_path = nbs3_sanitize_path($nbs3_path);
        }

        if (empty($nbs3_path)) {
            return $file_name;
        }

        return trailingslashit($nbs3_path) . $file_name;
    }

    private function deleteS3Object(string $key): void
    {
        $client = $this->getClient();
        $bucket = $this->getBucket();

        $client->deleteObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);
    }

    /**
     * Render credentials form fields
     */
    public function credentialsField()
    {
        $fields = [
            ['name' => 'endpoint', 'label' => __('S3 Endpoint', 'nobloat-s3-offload'), 'type' => 'text', 'placeholder' => 'https://s3.amazonaws.com', 'description' => __('Leave empty for AWS S3, or enter custom endpoint for S3-compatible services.', 'nobloat-s3-offload')],
            ['name' => 'region', 'label' => __('Region', 'nobloat-s3-offload'), 'type' => 'text', 'placeholder' => 'us-east-1', 'default' => 'us-east-1'],
            ['name' => 'bucket', 'label' => __('Bucket Name', 'nobloat-s3-offload'), 'type' => 'text', 'placeholder' => 'my-bucket'],
            ['name' => 'key', 'label' => __('Access Key', 'nobloat-s3-offload'), 'type' => 'text', 'placeholder' => 'AKIAIOSFODNN7EXAMPLE'],
            ['name' => 'secret', 'label' => __('Secret Key', 'nobloat-s3-offload'), 'type' => 'password', 'placeholder' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'],
            ['name' => 'domain', 'label' => __('Custom Domain (CDN)', 'nobloat-s3-offload'), 'type' => 'text', 'placeholder' => 'https://cdn.example.com', 'description' => __('Optional. Custom domain or CDN URL for serving files.', 'nobloat-s3-offload')],
            ['name' => 'path_style_endpoint', 'label' => __('Use Path-Style Endpoint', 'nobloat-s3-offload'), 'type' => 'checkbox', 'description' => __('Enable for MinIO or other S3-compatible services that require path-style URLs.', 'nobloat-s3-offload')],
        ];

        echo $this->getCredentialsFieldHTML($fields);
    }

    protected function renderCredentialField($field_name, $field_label, $field_type = 'text', $placeholder = '', $description = '', $default = '')
    {
        $constant_name = 'NBS3_' . strtoupper($field_name);
        $is_constant_defined = nbs3_credential_exists_in_config($constant_name);
        $field_value = nbs3_get_credential($field_name);

        if (($field_value === null || $field_value === '') && !empty($default)) {
            $field_value = $default;
        }

        $input_name = "nbs3_credentials[{$field_name}]";
        $input_id = "nbs3_credential_{$field_name}";
        $disabled = $is_constant_defined ? 'disabled readonly' : '';
        $disabled_class = $is_constant_defined ? 'nbs3-field-disabled' : '';

        $display_value = $field_value;
        if ($is_constant_defined && $field_type === 'password' && !empty($field_value)) {
            $display_value = str_repeat('•', min(strlen($field_value), 20));
        }

        if ($field_type === 'checkbox') {
            $checked = !empty($field_value) ? checked(1, $field_value, false) : '';
            $html = '<div class="nbs3-credential-field nbs3-checkbox-option ' . esc_attr($disabled_class) . '">';
            $html .= '<input type="checkbox" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" value="1" ' . $checked . ' ' . $disabled . ' />';
            $html .= '<label for="' . esc_attr($input_id) . '">' . esc_html($field_label) . '</label>';

            if (!empty($description)) {
                $html .= '<p class="description">' . esc_html($description) . '</p>';
            }

            if ($is_constant_defined) {
                $html .= '<p class="description">' . sprintf(
                    esc_html__('Set in %s', 'nobloat-s3-offload'),
                    '<code>wp-config.php</code>'
                ) . '</p>';
            }

            $html .= '</div>';
            return $html;
        }

        $html = '<div class="nbs3-credential-field ' . esc_attr($disabled_class) . '">';
        $html .= '<label for="' . esc_attr($input_id) . '">' . esc_html($field_label) . '</label>';

        if ($field_type === 'password' && !$is_constant_defined) {
            $html .= '<div class="nbs3-password-field-wrapper">';
            $html .= '<input type="password" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" value="' . esc_attr($field_value) . '" placeholder="' . esc_attr($placeholder) . '" class="regular-text nbs3-password-input" ' . $disabled . ' />';
            $html .= '<button type="button" class="button nbs3-toggle-password" aria-label="' . esc_attr__('Toggle password visibility', 'nobloat-s3-offload') . '">';
            $html .= '<span class="dashicons dashicons-visibility"></span>';
            $html .= '</button>';
            $html .= '</div>';
        } else {
            $html .= '<input type="' . esc_attr($field_type) . '" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" value="' . esc_attr($display_value) . '" placeholder="' . esc_attr($placeholder) . '" class="regular-text" ' . $disabled . ' />';
        }

        if (!empty($description)) {
            $html .= '<p class="description">' . esc_html($description) . '</p>';
        }

        if ($is_constant_defined) {
            $html .= '<p class="description">' . sprintf(
                esc_html__('Set in %s', 'nobloat-s3-offload'),
                '<code>wp-config.php</code>'
            ) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    public function getCredentialsFieldHTML($credentialFields)
    {
        $html = '<div class="nbs3-credentials-container">';

        $html .= '<div class="nbs3-credentials-info notice notice-info inline">';
        $html .= '<p>' . sprintf(
            esc_html__('%s Credentials can be set here or in %s for security. Constants take priority.', 'nobloat-s3-offload'),
            '<strong>' . esc_html__('Tip:', 'nobloat-s3-offload') . '</strong>',
            '<code>wp-config.php</code>'
        ) . '</p>';
        $html .= '</div>';

        $html .= '<div class="nbs3-credential-fields">';
        foreach ($credentialFields as $field) {
            $html .= $this->renderCredentialField(
                $field['name'],
                $field['label'],
                $field['type'] ?? 'text',
                $field['placeholder'] ?? '',
                $field['description'] ?? '',
                $field['default'] ?? ''
            );
        }
        $html .= '</div>';

        $html .= '<div class="nbs3-credentials-actions">';
        $html .= '<button type="submit" class="button button-primary nbs3-save-credentials">';
        $html .= '<span class="dashicons dashicons-saved"></span> ';
        $html .= esc_html__('Save Credentials', 'nobloat-s3-offload');
        $html .= '</button>';
        $html .= '<button type="button" class="button nbs3_js_test_connection">';
        $html .= esc_html__('Test Connection', 'nobloat-s3-offload');
        $html .= '</button>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }
}
