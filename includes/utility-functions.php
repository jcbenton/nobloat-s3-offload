<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Include a view file from the plugin directory.
 */
if (!function_exists('nbs3_get_view')) {
    function nbs3_get_view(string $template)
    {
        if (file_exists(NBS3_PATH . 'templates/' . $template . '.php')) {
            include NBS3_PATH . 'templates/' . $template . '.php';
        }
    }
}

/**
 * Normalize and validate a URL, ensuring it has a proper scheme (https://).
 */
if (!function_exists('nbs3_normalize_url')) {
    function nbs3_normalize_url(string $url): string
    {
        if (empty($url)) {
            return '';
        }

        $url = trim($url);

        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        $validated_url = esc_url_raw($url, ['http', 'https']);

        if ($validated_url && filter_var($validated_url, FILTER_VALIDATE_URL)) {
            return $validated_url;
        }

        return '';
    }
}

/**
 * Helper: Get public URL for an attachment
 */
if (!function_exists('nbs3_get_public_url')) {
    function nbs3_get_public_url($attachment_id)
    {
        return wp_get_attachment_url($attachment_id);
    }
}

/**
 * Check if we're on a plugin settings page
 */
if (!function_exists('nbs3_is_settings_page')) {
    function nbs3_is_settings_page($page_name = ''): bool
    {
        $current_screen = get_current_screen();

        if (!$current_screen) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a page check for admin menu, not form processing
        $current_page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

        $plugin_pages = [
            'general' => 'nbs3',
            'media-overview' => 'nbs3_media_overview',
            'documentation' => 'nbs3_documentation'
        ];

        if (!empty($page_name)) {
            if (!isset($plugin_pages[$page_name])) {
                return false;
            }
            return $current_page === $plugin_pages[$page_name];
        }

        return in_array($current_page, array_values($plugin_pages));
    }
}

/**
 * Get bulk offload data.
 */
if (!function_exists('nbs3_get_bulk_offload_data')) {
    function nbs3_get_bulk_offload_data(): array
    {
        $defaults = array(
            'total' => 0,
            'status' => '',
            'processed' => 0,
            'errors' => 0,
            'last_update' => null,
            'oversized_skipped' => 0
        );

        // Try transient first (real-time progress data from background process)
        $transient_data = get_transient('nbs3_bulk_offload_progress');
        if (false !== $transient_data && is_array($transient_data)) {
            return array_merge($defaults, $transient_data);
        }

        // Fallback to option (persistent data)
        $stored_data = get_option('nbs3_bulk_offload_data', array());

        return array_merge($defaults, $stored_data);
    }
}

/**
 * Update bulk offload data.
 */
if (!function_exists('nbs3_update_bulk_offload_data')) {
    function nbs3_update_bulk_offload_data(array $new_data): array
    {
        $allowed_keys = array(
            'total',
            'status',
            'processed',
            'errors',
            'oversized_skipped',
            'last_recovery',
            'last_cleanup',
            'last_update'
        );

        $filtered_new_data = array_intersect_key($new_data, array_flip($allowed_keys));
        $existing_data = nbs3_get_bulk_offload_data();
        $updated_data = array_merge($existing_data, $filtered_new_data);
        $final_data = array_intersect_key($updated_data, array_flip($allowed_keys));

        foreach (array('last_recovery', 'last_cleanup') as $timestamp_key) {
            if (isset($final_data[$timestamp_key])) {
                $final_data[$timestamp_key] = (int) $final_data[$timestamp_key];
            }
        }

        if (!isset($filtered_new_data['last_update'])) {
            $final_data['last_update'] = time();
        } else {
            $final_data['last_update'] = (int) $filtered_new_data['last_update'];
        }

        // Set transient for real-time progress tracking (expires in 1 hour)
        set_transient('nbs3_bulk_offload_progress', $final_data, HOUR_IN_SECONDS);

        // Also update persistent option
        update_option('nbs3_bulk_offload_data', $final_data);
        update_option('nbs3_bulk_offload_last_update', $final_data['last_update']);

        return $final_data;
    }
}

/**
 * Check if media is organized by year and month.
 */
if (!function_exists('nbs3_is_media_organized_by_year_month')) {
    function nbs3_is_media_organized_by_year_month(): bool
    {
        return get_option('uploads_use_yearmonth_folders') ? true : false;
    }
}

/**
 * Sanitize a URL path for S3 prefix usage.
 */
if (!function_exists('nbs3_sanitize_path')) {
    function nbs3_sanitize_path(string $path): string
    {
        // Remove any null bytes and control characters
        $path = preg_replace('/[\x00-\x1F\x7F]/u', '', $path);

        $path = trim($path);

        if (empty($path)) {
            return '';
        }

        // Maximum path length (S3 allows 1024 chars for keys)
        if (strlen($path) > 255) {
            $path = substr($path, 0, 255);
        }

        // Decode any URL encoding to prevent bypass
        $path = urldecode($path);

        // Remove any attempts at path traversal (multiple passes to catch nested attempts)
        $iterations = 0;
        while ((strpos($path, '..') !== false || strpos($path, './') !== false) && $iterations < 10) {
            $path = str_replace(['../', '..\\', '../', '..', './'], '', $path);
            $iterations++;
        }

        // Whitelist allowed characters: alphanumeric, dash, underscore, forward slash
        $path = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $path);

        // Collapse multiple slashes
        $path = preg_replace('#/+#', '/', $path);

        // Remove leading/trailing slashes
        $path = trim($path, '/');

        // Normalize path separators
        $path = wp_normalize_path($path);

        // Final validation - ensure no dangerous patterns remain
        if (preg_match('/\.\./', $path)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Security logging for path traversal attempts
            error_log("NBS3 Security: Dangerous path pattern detected and rejected: {$path}");
            return '';
        }

        return $path;
    }
}

/**
 * Clear bulk offload data.
 */
if (!function_exists('nbs3_clear_bulk_offload_data')) {
    function nbs3_clear_bulk_offload_data(): void
    {
        delete_transient('nbs3_bulk_offload_progress');
        delete_option('nbs3_bulk_offload_data');
    }
}

/**
 * Get the count of unoffloaded media items.
 */
if (!function_exists('nbs3_get_unoffloaded_media_items_count')) {
    function nbs3_get_unoffloaded_media_items_count(): int
    {
        global $wpdb;

        $query = "SELECT COUNT(*) FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'nbs3_offloaded'
            WHERE p.post_type = 'attachment'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Complex JOIN query counting unoffloaded attachments
        return (int) $wpdb->get_var($query);
    }
}

if (!function_exists('nbs3_get_offloaded_media_items_count')) {
    function nbs3_get_offloaded_media_items_count(): int
    {
        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND p.post_status != %s
            AND pm.meta_key = %s
            AND pm.meta_value != %s",
            array(
                'attachment',
                'trash',
                'nbs3_offloaded',
                ''
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        return (int) $wpdb->get_var($query);
    }
}

/**
 * Check if a credential constant exists in wp-config.php
 */
if (!function_exists('nbs3_credential_exists_in_config')) {
    function nbs3_credential_exists_in_config(string $constant_name): bool
    {
        return defined($constant_name);
    }
}

/**
 * Get credential from constants or saved options.
 * Constants take priority over saved options.
 */
if (!function_exists('nbs3_get_credential')) {
    function nbs3_get_credential(string $field_name): string
    {
        $constant_name = 'NBS3_' . strtoupper($field_name);

        if (defined($constant_name)) {
            $value = constant($constant_name);
            return is_string($value) ? $value : '';
        }

        $credentials = get_option('nbs3_credentials', []);
        if (isset($credentials[$field_name])) {
            return $credentials[$field_name];
        }

        return '';
    }
}

/**
 * Save credentials to options.
 */
if (!function_exists('nbs3_save_credentials')) {
    function nbs3_save_credentials(array $credentials): bool
    {
        return update_option('nbs3_credentials', $credentials);
    }
}

/**
 * Get all credentials.
 */
if (!function_exists('nbs3_get_credentials')) {
    function nbs3_get_credentials(): array
    {
        return get_option('nbs3_credentials', []);
    }
}

/**
 * Get a plugin setting value.
 */
if (!function_exists('nbs3_get_setting')) {
    function nbs3_get_setting(string $key, $default = null)
    {
        $settings = get_option('nbs3_settings', []);
        return isset($settings[$key]) ? $settings[$key] : $default;
    }
}

/**
 * Update a plugin setting value.
 */
if (!function_exists('nbs3_update_setting')) {
    function nbs3_update_setting(string $key, $value): bool
    {
        $settings = get_option('nbs3_settings', []);
        $settings[$key] = $value;
        return update_option('nbs3_settings', $settings);
    }
}

/**
 * Get all plugin settings.
 */
if (!function_exists('nbs3_get_settings')) {
    function nbs3_get_settings(): array
    {
        $defaults = [
            's3_object_acl' => 'none',
            'auto_offload_uploads' => 1,
            'local_file_retention' => 0,
            'sync_bricks_css' => 0,
        ];
        $settings = get_option('nbs3_settings', []);
        return array_merge($defaults, $settings);
    }
}

/**
 * Check if Bricks Builder is active.
 */
if (!function_exists('nbs3_is_bricks_active')) {
    function nbs3_is_bricks_active(): bool
    {
        return defined('BRICKS_VERSION');
    }
}

/**
 * Get the Bricks CSS directory path.
 */
if (!function_exists('nbs3_get_bricks_css_path')) {
    function nbs3_get_bricks_css_path(): string
    {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir']) . 'bricks/css';
    }
}

/**
 * Get Bricks CSS sync status.
 */
if (!function_exists('nbs3_get_bricks_sync_status')) {
    function nbs3_get_bricks_sync_status(): array
    {
        $synced_files = get_option('nbs3_synced_bricks_files', []);
        $local_path = nbs3_get_bricks_css_path();

        $local_files = [];
        if (is_dir($local_path)) {
            $files = glob($local_path . '/*.css');
            foreach ($files as $file) {
                $local_files[basename($file)] = filemtime($file);
            }
        }

        $synced_count = 0;
        $pending_count = 0;

        foreach ($local_files as $file => $mtime) {
            if (isset($synced_files[$file]) && $synced_files[$file]['mtime'] >= $mtime) {
                $synced_count++;
            } else {
                $pending_count++;
            }
        }

        return [
            'synced' => $synced_count,
            'pending' => $pending_count,
            'total' => count($local_files),
        ];
    }
}

/**
 * Get copyright text for admin footer.
 */
if (!function_exists('nbs3_get_copyright_text')) {
    function nbs3_get_copyright_text(): string
    {
        return sprintf(
            /* translators: %s: plugin version number */
            __('Nobloat S3 Offload v%s', 'nobloat-s3-offload'),
            NBS3_VERSION
        );
    }
}
