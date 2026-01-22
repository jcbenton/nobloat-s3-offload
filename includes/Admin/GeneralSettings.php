<?php
namespace NBS3\Admin;

defined( 'ABSPATH' ) || exit;

use NBS3\S3Provider;

class GeneralSettings
{
    private static $instance = null;

    private function __construct()
    {
        $this->register_hooks();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function register_hooks()
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'initialize']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_nbs3_test_connection', [$this, 'check_connection_ajax']);
        add_action('wp_ajax_nbs3_save_general_settings', [$this, 'save_general_settings_ajax']);
        add_action('wp_ajax_nbs3_save_credentials', [$this, 'save_credentials_ajax']);
        add_action('wp_ajax_nbs3_sync_bricks_now', [$this, 'sync_bricks_ajax']);
        add_action('wp_ajax_nbs3_remove_bricks_from_s3', [$this, 'remove_bricks_from_s3_ajax']);
        add_action('wp_ajax_nbs3_get_bricks_status', [$this, 'get_bricks_status_ajax']);
        add_action('wp_ajax_nbs3_sync_bricks_theme_assets', [$this, 'sync_bricks_theme_assets_ajax']);
        add_action('wp_ajax_nbs3_remove_bricks_theme_assets', [$this, 'remove_bricks_theme_assets_ajax']);
    }

    public function initialize()
    {
        register_setting('nbs3', 'nbs3_settings', [
            'sanitize_callback' => [$this, 'sanitize']
        ]);

        register_setting('nbs3', 'nbs3_credentials', [
            'sanitize_callback' => [$this, 'sanitize_credentials']
        ]);

        $this->add_settings_section();
        $this->add_credentials_field();
        $this->add_s3_acl_field();
        $this->add_auto_offload_field();
        $this->add_retention_policy_field();
        $this->add_path_prefix_field();
        $this->add_object_versioning_field();
        $this->add_mirror_delete_field();

        // Bricks integration (only if Bricks is active)
        if (nbs3_is_bricks_active()) {
            $this->add_bricks_section();
            $this->add_bricks_sync_field();
            $this->add_bricks_theme_assets_field();
        }
    }

    private function add_settings_section()
    {
        add_settings_section(
            'cloud_provider',
            __('S3 Connection', 'nobloat-s3-offload'),
            function () {
                echo '<p>' . esc_html__('Configure your S3-compatible storage credentials.', 'nobloat-s3-offload') . '</p></div>';
            },
            'nbs3',
            [
                'before_section' => '<div class="nbs3-section nbs3-cloud-provider-settings"><div class="nbs3-section-header">',
                'after_section' => '</div>',
            ]
        );

        add_settings_section(
            'general_settings',
            __('General Settings', 'nobloat-s3-offload'),
            function () {
                echo '<p>' . esc_html__('Configure the core options for managing and offloading your media files.', 'nobloat-s3-offload') . '</p></div>';
            },
            'nbs3',
            [
                'before_section' => '<div class="nbs3-section nbs3-general-settings"><div class="nbs3-section-header">',
                'after_section' => '</div>',
            ]
        );
    }

    private function add_credentials_field()
    {
        add_settings_field(
            'nbs3_credentials',
            __('Credentials', 'nobloat-s3-offload'),
            [$this, 'credentials_field'],
            'nbs3',
            'cloud_provider',
            [
                'class' => 'nbs3-field nbs3-cloud-provider-credentials',
            ]
        );
    }

    private function add_s3_acl_field()
    {
        add_settings_field(
            's3_object_acl',
            __('Object Permissions', 'nobloat-s3-offload'),
            [$this, 's3_acl_field'],
            'nbs3',
            'cloud_provider',
            [
                'class' => 'nbs3-field nbs3-s3-acl',
            ]
        );
    }

    private function add_bricks_section()
    {
        add_settings_section(
            'bricks_integration',
            __('Bricks Integration', 'nobloat-s3-offload'),
            function () {
                echo '<p>' . esc_html__('Sync Bricks Builder CSS files to S3 for CDN delivery.', 'nobloat-s3-offload') . '</p></div>';
            },
            'nbs3',
            [
                'before_section' => '<div class="nbs3-section nbs3-bricks-integration"><div class="nbs3-section-header">',
                'after_section' => '</div>',
            ]
        );
    }

    private function add_bricks_sync_field()
    {
        add_settings_field(
            'sync_bricks_css',
            __('Bricks CSS Sync', 'nobloat-s3-offload'),
            [$this, 'bricks_sync_field'],
            'nbs3',
            'bricks_integration',
            [
                'class' => 'nbs3-field nbs3-bricks-sync',
            ]
        );
    }

    private function add_bricks_theme_assets_field()
    {
        add_settings_field(
            'sync_bricks_theme_assets',
            __('Bricks Theme Assets', 'nobloat-s3-offload'),
            [$this, 'bricks_theme_assets_field'],
            'nbs3',
            'bricks_integration',
            [
                'class' => 'nbs3-field nbs3-bricks-theme-assets',
            ]
        );
    }

    private function add_retention_policy_field()
    {
        add_settings_field(
            'retention_policy',
            __('Retention Policy', 'nobloat-s3-offload'),
            [$this, 'retention_policy_field'],
            'nbs3',
            'general_settings',
            [
                'class' => 'nbs3-field nbs3-retention_policy',
            ]
        );
    }

    private function add_mirror_delete_field()
    {
        add_settings_field(
            'mirror_delete',
            __('Mirror Delete', 'nobloat-s3-offload'),
            [$this, 'mirror_delete_field'],
            'nbs3',
            'general_settings',
            [
                'class' => 'nbs3-field nbs3-mirror-delete',
            ]
        );
    }

    private function add_auto_offload_field()
    {
        add_settings_field(
            'auto_offload_uploads',
            __('Auto-Offload Media', 'nobloat-s3-offload'),
            [$this, 'auto_offload_field'],
            'nbs3',
            'general_settings',
            [
                'class' => 'nbs3-field nbs3-auto-offload',
            ]
        );
    }

    private function add_object_versioning_field()
    {
        add_settings_field(
            'object_versioning',
            __('File Versioning', 'nobloat-s3-offload'),
            [$this, 'object_versioning_field'],
            'nbs3',
            'general_settings',
            [
                'class' => 'nbs3-field nbs3-object-versioning',
            ]
        );
    }

    private function add_path_prefix_field()
    {
        add_settings_field(
            'path_prefix',
            __('Custom Path Prefix', 'nobloat-s3-offload'),
            [$this, 'path_prefix_field'],
            'nbs3',
            'general_settings',
            [
                'class' => 'nbs3-field nbs3-path-prefix',
            ]
        );
    }

    public function credentials_field()
    {
        $s3Provider = new S3Provider();
        $s3Provider->credentialsField();
    }

    public function s3_acl_field()
    {
        $options = get_option('nbs3_settings');
        $s3_object_acl = isset($options['s3_object_acl']) ? $options['s3_object_acl'] : 'none';

        echo '<div class="nbs3-radio-group">';

        echo '<div class="nbs3-radio-option">';
        echo '<input type="radio" id="s3_acl_none" name="nbs3_settings[s3_object_acl]" value="none" ' . checked('none', $s3_object_acl, false) . '/>';
        echo '<label for="s3_acl_none">' . esc_html__('None (Bucket Policy)', 'nobloat-s3-offload') . '</label>';
        echo '<p class="description">' . esc_html__('Recommended. Access is controlled by your S3 bucket policy. Works with modern S3 buckets that have ACLs disabled.', 'nobloat-s3-offload') . '</p>';
        echo '</div>';

        echo '<div class="nbs3-radio-option">';
        echo '<input type="radio" id="s3_acl_public" name="nbs3_settings[s3_object_acl]" value="public-read" ' . checked('public-read', $s3_object_acl, false) . '/>';
        echo '<label for="s3_acl_public">' . esc_html__('Public Read (ACL)', 'nobloat-s3-offload') . '</label>';
        echo '<p class="description">' . esc_html__('Set public-read ACL on each uploaded object. Only works if your bucket allows ACLs.', 'nobloat-s3-offload') . '</p>';
        echo '</div>';

        echo '<div class="nbs3-radio-option">';
        echo '<input type="radio" id="s3_acl_private" name="nbs3_settings[s3_object_acl]" value="private" ' . checked('private', $s3_object_acl, false) . '/>';
        echo '<label for="s3_acl_private">' . esc_html__('Private (ACL)', 'nobloat-s3-offload') . '</label>';
        echo '<p class="description">' . esc_html__('Set private ACL on each uploaded object. Use with CloudFront signed URLs.', 'nobloat-s3-offload') . '</p>';
        echo '</div>';

        echo '</div>';
    }

    public function bricks_sync_field()
    {
        $options = get_option('nbs3_settings');
        $sync_bricks_css = isset($options['sync_bricks_css']) ? intval($options['sync_bricks_css']) : 0;
        $status = nbs3_get_bricks_sync_status();

        echo '<div class="nbs3-checkbox-option">';
        echo '<input type="checkbox" id="sync_bricks_css" name="nbs3_settings[sync_bricks_css]" value="1" ' . checked(1, $sync_bricks_css, false) . '/>';
        echo '<label for="sync_bricks_css">' . esc_html__('Sync Bricks CSS to S3', 'nobloat-s3-offload') . '</label>';
        echo '<p class="description">' . esc_html__('Automatically upload Bricks-generated CSS files to S3 and serve via CDN.', 'nobloat-s3-offload') . '</p>';
        echo '</div>';

        // Status display
        echo '<div class="nbs3-bricks-status" style="margin-top: 15px; padding: 12px; background: #f0f0f1; border-radius: 4px;">';
        echo '<p style="margin: 0 0 8px 0;"><strong>' . esc_html__('Status:', 'nobloat-s3-offload') . '</strong></p>';
        echo '<p style="margin: 0;" id="nbs3-bricks-status-text">';
        printf(
            /* translators: %1$d: number synced, %2$d: number pending, %3$d: total number */
            esc_html__('%1$d synced, %2$d pending, %3$d total', 'nobloat-s3-offload'),
            intval($status['synced']),
            intval($status['pending']),
            intval($status['total'])
        );
        echo '</p>';
        echo '</div>';

        // Action buttons
        echo '<div class="nbs3-bricks-actions" style="margin-top: 15px;">';
        echo '<button type="button" class="button" id="nbs3-sync-bricks-now">' . esc_html__('Sync Now', 'nobloat-s3-offload') . '</button> ';
        echo '<button type="button" class="button" id="nbs3-remove-bricks-s3" style="color: #b32d2e;">' . esc_html__('Remove from S3', 'nobloat-s3-offload') . '</button>';
        echo '<span id="nbs3-bricks-action-status" style="margin-left: 10px;"></span>';
        echo '</div>';

        // WP-CLI info
        echo '<div style="margin-top: 15px;">';
        echo '<p class="description">' . esc_html__('For large operations, use WP-CLI:', 'nobloat-s3-offload') . ' <code>wp nbs3 sync-bricks</code></p>';
        echo '</div>';
    }

    public function bricks_theme_assets_field()
    {
        $options = get_option('nbs3_settings');
        $sync_theme_assets = isset($options['sync_bricks_theme_assets']) ? intval($options['sync_bricks_theme_assets']) : 0;

        // Get status
        $s3Provider = new S3Provider();
        $service = new \NBS3\Services\BricksThemeAssetsSyncService($s3Provider);
        $status = $service->getStatus();

        echo '<div class="nbs3-checkbox-option">';
        echo '<input type="checkbox" id="sync_bricks_theme_assets" name="nbs3_settings[sync_bricks_theme_assets]" value="1" ' . checked(1, $sync_theme_assets, false) . '/>';
        echo '<label for="sync_bricks_theme_assets">' . esc_html__('Sync Bricks Theme Assets to S3', 'nobloat-s3-offload') . '</label>';
        echo '<p class="description">' . esc_html__('Upload Bricks theme static assets (CSS, JS, fonts) to S3. Re-syncs automatically when any plugin or theme is updated.', 'nobloat-s3-offload') . '</p>';
        echo '</div>';

        // Status display
        echo '<div class="nbs3-bricks-theme-assets-status" style="margin-top: 15px; padding: 12px; background: #f0f0f1; border-radius: 4px;">';
        echo '<p style="margin: 0 0 8px 0;"><strong>' . esc_html__('Status:', 'nobloat-s3-offload') . '</strong></p>';
        echo '<p style="margin: 0;" id="nbs3-bricks-theme-assets-status-text">';
        printf(
            /* translators: %1$d: number synced, %2$d: number pending, %3$d: total number of files */
            esc_html__('%1$d synced, %2$d pending, %3$d total files', 'nobloat-s3-offload'),
            intval($status['synced']),
            intval($status['pending']),
            intval($status['total'])
        );
        echo '</p>';
        echo '</div>';

        // Action buttons
        echo '<div class="nbs3-bricks-theme-assets-actions" style="margin-top: 15px;">';
        echo '<button type="button" class="button" id="nbs3-sync-bricks-theme-assets-now">' . esc_html__('Sync Now', 'nobloat-s3-offload') . '</button> ';
        echo '<button type="button" class="button" id="nbs3-remove-bricks-theme-assets-s3" style="color: #b32d2e;">' . esc_html__('Remove from S3', 'nobloat-s3-offload') . '</button>';
        echo '<span id="nbs3-bricks-theme-assets-action-status" style="margin-left: 10px;"></span>';
        echo '</div>';

        // Info about auto-sync
        echo '<div style="margin-top: 15px;">';
        echo '<p class="description">' . esc_html__('Theme assets auto-sync whenever any plugin or theme is updated.', 'nobloat-s3-offload') . '</p>';
        echo '</div>';
    }

    public function path_prefix_field()
    {
        $options = get_option('nbs3_settings');
        $path_prefix = isset($options['path_prefix']) ? $options['path_prefix'] : "wp-content/uploads/";
        $path_prefix_Active = isset($options['path_prefix_active']) ? $options['path_prefix_active'] : 0;
        echo '<div class="nbs3-checkbox-option">';
        echo '<input type="checkbox" id="path_prefix_active" name="nbs3_settings[path_prefix_active]" value="1" ' . checked(1, $path_prefix_Active, false) . '/>';
        echo '<label for="path_prefix_active">' . esc_html__('Use Custom Path Prefix', 'nobloat-s3-offload') . '</label>';
        echo '<p class="description"><input type="text" id="path_prefix" name="nbs3_settings[path_prefix]" value="' . esc_attr($path_prefix) . '"' . ($path_prefix_Active ? '' : ' disabled') . '/></p>';
        echo '<p class="description">' . esc_html__('Add a common prefix to organize offloaded media files from this site in your cloud storage bucket.', 'nobloat-s3-offload') . '</p>';
        echo '</div>';
    }

    public function object_versioning_field()
    {
        $options = get_option('nbs3_settings');
        $object_versioning = isset($options['object_versioning']) ? $options['object_versioning'] : 0;

        echo '<div class="nbs3-checkbox-option">';
        echo '<input type="checkbox" id="object_versioning" name="nbs3_settings[object_versioning]" value="1" ' . checked(1, $object_versioning, false) . '/>';
        echo '<label for="object_versioning">' . esc_html__('Add Version to Bucket Path', 'nobloat-s3-offload') . '</label>';
        echo '<p class="description">' . esc_html__('Automatically add unique timestamps to your media file paths to ensure the latest versions are always delivered.', 'nobloat-s3-offload') . '</p>';
        echo '</div>';
    }

    public function mirror_delete_field()
    {
        $options = get_option('nbs3_settings');
        $mirror_delete = isset($options['mirror_delete']) ? intval($options['mirror_delete']) : 0;
        echo '<div class="nbs3-checkbox-option">';
        echo '<input type="checkbox" id="mirror_delete" name="nbs3_settings[mirror_delete]" value="1" ' . checked(1, $mirror_delete, false) . '/>';
        echo '<label for="mirror_delete">' . esc_html__('Sync Deletion with Cloud Storage', 'nobloat-s3-offload') . '</label>';
        echo '<p class="description">' . esc_html__('When enabled, deleting a media file in WordPress will also remove it from your cloud storage.', 'nobloat-s3-offload') . '</p>';
        echo '</div>';
    }

    public function auto_offload_field()
    {
        $options = get_option('nbs3_settings');
        $auto_offload_uploads = isset($options['auto_offload_uploads']) ? intval($options['auto_offload_uploads']) : 1;
        echo '<div class="nbs3-checkbox-option">';
        echo '<input type="checkbox" id="auto_offload_uploads" name="nbs3_settings[auto_offload_uploads]" value="1" ' . checked(1, $auto_offload_uploads, false) . '/>';
        echo '<label for="auto_offload_uploads">' . esc_html__('Upload files to cloud storage', 'nobloat-s3-offload') . '</label>';
        echo '<p class="description">' . esc_html__('Automatically send new uploads to cloud storage.', 'nobloat-s3-offload') . '</p>';
        echo '</div>';
    }

    public function retention_policy_field()
    {
        $options = get_option('nbs3_settings');
        $retention_policy = isset($options['retention_policy']) ? intval($options['retention_policy']) : 0;

        echo '<div class="nbs3-radio-group">';

        echo '<div class="nbs3-radio-option">';
        echo '<input type="radio" id="retention_policy" name="nbs3_settings[retention_policy]" value="0" ' . checked(0, $retention_policy, false) . '/>';
        echo '<label for="retention_policy_none">' . esc_html__('Retain Local Files', 'nobloat-s3-offload') . '</label>';
        echo '<p class="description">' . esc_html__('Keep all files on your local server after offloading to the cloud.', 'nobloat-s3-offload') . '</p>';
        echo '</div>';

        echo '<div class="nbs3-radio-option">';
        echo '<input type="radio" id="retention_policy_cloud" name="nbs3_settings[retention_policy]" value="1" ' . checked(1, $retention_policy, false) . '/>';
        echo '<label for="retention_policy_cloud">' . esc_html__('Smart Local Cleanup', 'nobloat-s3-offload') . '</label>';
        echo '<p class="description">' . esc_html__('Remove local copies after cloud offloading, but keep the original file as a backup.', 'nobloat-s3-offload') . '</p>';
        echo '</div>';

        echo '<div class="nbs3-radio-option">';
        echo '<input type="radio" id="retention_policy_all" name="nbs3_settings[retention_policy]" value="2" ' . checked(2, $retention_policy, false) . '/>';
        echo '<label for="retention_policy_all">' . esc_html__('Full Cloud Migration', 'nobloat-s3-offload') . '</label>';
        echo '<p class="description">' . esc_html__('Remove all local files, including originals, after successful cloud offloading.', 'nobloat-s3-offload') . '</p>';
        echo '</div>';

        echo '</div>';
    }

    public function sanitize($options)
    {
        if (!current_user_can('manage_options')) {
            return get_option('nbs3_settings', []);
        }

        $sanitized = [
            'retention_policy' => 0,
            'object_versioning' => 0,
            'path_prefix' => '',
            'mirror_delete' => 0,
            'path_prefix_active' => 0,
            'auto_offload_uploads' => 1,
            's3_object_acl' => 'none',
            'sync_bricks_css' => 0,
            'sync_bricks_theme_assets' => 0,
        ];

        $sanitized['retention_policy'] = isset($options['retention_policy']) ? intval($options['retention_policy']) : 0;
        $sanitized['retention_policy'] = in_array($sanitized['retention_policy'], [0, 1, 2], true) ? $sanitized['retention_policy'] : 0;
        $sanitized['object_versioning'] = isset($options['object_versioning']) && (int) $options['object_versioning'] === 1 ? 1 : 0;
        $sanitized['path_prefix'] = isset($options['path_prefix']) ? nbs3_sanitize_path($options['path_prefix']) : '';
        $sanitized['mirror_delete'] = isset($options['mirror_delete']) && (int) $options['mirror_delete'] === 1 ? 1 : 0;
        $sanitized['path_prefix_active'] = isset($options['path_prefix_active']) && (int) $options['path_prefix_active'] === 1 ? 1 : 0;
        $sanitized['auto_offload_uploads'] = isset($options['auto_offload_uploads']) && (int) $options['auto_offload_uploads'] === 1 ? 1 : 0;

        // S3 ACL setting
        $valid_acls = ['none', 'public-read', 'private'];
        $sanitized['s3_object_acl'] = isset($options['s3_object_acl']) && in_array($options['s3_object_acl'], $valid_acls, true)
            ? $options['s3_object_acl']
            : 'none';

        // Bricks sync setting
        $old_bricks_setting = nbs3_get_setting('sync_bricks_css', 0);
        $sanitized['sync_bricks_css'] = isset($options['sync_bricks_css']) && (int) $options['sync_bricks_css'] === 1 ? 1 : 0;

        // Auto-sync if enabling and under threshold
        if ($sanitized['sync_bricks_css'] && !$old_bricks_setting && nbs3_is_bricks_active()) {
            $status = nbs3_get_bricks_sync_status();
            if ($status['pending'] > 0 && $status['total'] < 50) {
                // Schedule immediate sync
                wp_schedule_single_event(time(), 'nbs3_bricks_initial_sync');
            }
        }

        // Bricks theme assets sync setting
        $sanitized['sync_bricks_theme_assets'] = isset($options['sync_bricks_theme_assets']) && (int) $options['sync_bricks_theme_assets'] === 1 ? 1 : 0;

        add_settings_error(
            'nbs3_messages',
            'nbs3_message',
            __('Settings Saved', 'nobloat-s3-offload'),
            'updated'
        );

        return $sanitized;
    }

    public function sanitize_credentials($credentials)
    {
        if (!current_user_can('manage_options')) {
            return get_option('nbs3_credentials', []);
        }

        if (empty($credentials) || !is_array($credentials)) {
            return get_option('nbs3_credentials', []);
        }

        $sanitized = [];
        $checkbox_fields = ['path_style_endpoint'];

        foreach ($credentials as $field_name => $field_value) {
            $constant_name = 'NBS3_' . strtoupper($field_name);
            if (defined($constant_name)) {
                continue;
            }

            if (in_array($field_name, ['endpoint', 'domain'])) {
                $sanitized[$field_name] = nbs3_normalize_url($field_value);
            } elseif (in_array($field_name, ['key', 'secret'])) {
                $sanitized[$field_name] = sanitize_text_field($field_value);
            } elseif (in_array($field_name, $checkbox_fields)) {
                $sanitized[$field_name] = ($field_value === '1' || $field_value === 1) ? 1 : 0;
            } else {
                $sanitized[$field_name] = sanitize_text_field($field_value);
            }
        }

        foreach ($checkbox_fields as $checkbox_field) {
            $constant_name = 'NBS3_' . strtoupper($checkbox_field);
            if (defined($constant_name)) {
                continue;
            }
            if (!isset($credentials[$checkbox_field])) {
                $sanitized[$checkbox_field] = 0;
            }
        }

        return $sanitized;
    }

    public function add_settings_page()
    {
        add_menu_page(
            __('Nobloat S3 Offload', 'nobloat-s3-offload'),
            __('S3 Offload', 'nobloat-s3-offload'),
            'manage_options',
            'nbs3',
            [$this, 'general_settings_page_view'],
            'dashicons-cloud',
            100
        );

        add_submenu_page(
            'nbs3',
            __('General Settings', 'nobloat-s3-offload'),
            __('General Settings', 'nobloat-s3-offload'),
            'manage_options',
            'nbs3',
            [$this, 'general_settings_page_view']
        );

        // AWS Guide and Documentation added with priority 99 to ensure correct order
        add_action('admin_menu', [$this, 'add_additional_submenus'], 99);
    }

    public function add_additional_submenus()
    {
        add_submenu_page(
            'nbs3',
            __('AWS Guide', 'nobloat-s3-offload'),
            __('AWS Guide', 'nobloat-s3-offload'),
            'manage_options',
            'nbs3_aws_guide',
            [$this, 'aws_guide_page_view']
        );

        add_submenu_page(
            'nbs3',
            __('Documentation', 'nobloat-s3-offload'),
            __('Documentation', 'nobloat-s3-offload'),
            'manage_options',
            'nbs3_documentation',
            [$this, 'documentation_page_view']
        );

        add_submenu_page(
            'nbs3',
            __('About', 'nobloat-s3-offload'),
            __('About', 'nobloat-s3-offload'),
            'manage_options',
            'nbs3_about',
            [$this, 'about_page_view']
        );
    }

    public function aws_guide_page_view()
    {
        nbs3_get_view('admin/aws-guide');
    }

    public function documentation_page_view()
    {
        nbs3_get_view('admin/documentation');
    }

    public function about_page_view()
    {
        nbs3_get_view('admin/about');
    }

    public function general_settings_page_view()
    {
        nbs3_get_view('admin/general_settings');
    }

    public function enqueue_scripts()
    {
        if (!nbs3_is_settings_page()) {
            return;
        }

        if (nbs3_is_settings_page('general')) {
            wp_enqueue_script('nbs3_settings', NBS3_URL . 'assets/js/nbs3_settings.js', [], NBS3_VERSION, true);
            wp_localize_script('nbs3_settings', 'nbs3_ajax_object', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('nbs3_test_connection'),
                'save_general_nonce' => wp_create_nonce('nbs3_save_general_settings'),
                'save_credentials_nonce' => wp_create_nonce('nbs3_save_credentials'),
                'bricks_sync_nonce' => wp_create_nonce('nbs3_bricks_sync'),
                'bricks_remove_nonce' => wp_create_nonce('nbs3_bricks_remove'),
                'bricks_status_nonce' => wp_create_nonce('nbs3_bricks_status'),
                'bricks_theme_assets_sync_nonce' => wp_create_nonce('nbs3_bricks_theme_assets_sync'),
                'bricks_theme_assets_remove_nonce' => wp_create_nonce('nbs3_bricks_theme_assets_remove'),
            ]);
        }

        if (nbs3_is_settings_page('media-overview')) {
            wp_enqueue_script('nbs3_bulkoffload', NBS3_URL . 'assets/js/nbs3_bulkoffload.js', [], NBS3_VERSION, true);
            wp_localize_script('nbs3_bulkoffload', 'nbs3_ajax_object', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'bulk_offload_nonce' => wp_create_nonce('nbs3_bulk_offload')
            ]);
        }

        wp_enqueue_style('nbs3_admin', NBS3_URL . 'assets/css/admin.css', [], NBS3_VERSION);

        if (is_rtl()) {
            wp_enqueue_style('nbs3_admin_rtl', NBS3_URL . 'assets/css/admin-rtl.css', ['nbs3_admin'], NBS3_VERSION);
        }
    }

    public function check_connection_ajax()
    {
        $current_time = current_time('d/m/Y - h:i A');
        $response_data = [
            'last_check' => $current_time
        ];

        if (!$this->verify_security_nonce('security_nonce', 'nbs3_test_connection')) {
            $response_data['message'] = __('Invalid nonce!', 'nobloat-s3-offload');
            wp_send_json_error($response_data);
        }

        $bucket = nbs3_get_credential('bucket');
        if (empty($bucket)) {
            $response_data['message'] = __('Please configure your S3 credentials first.', 'nobloat-s3-offload');
            wp_send_json_error($response_data);
        }

        try {
            $s3Provider = new S3Provider();
            $connection_result = $s3Provider->checkConnection();

            update_option('nbs3_last_connection_check', $current_time);

            if ($connection_result) {
                $response_data['message'] = __('Connection successful!', 'nobloat-s3-offload');
                wp_send_json_success($response_data);
            } else {
                $response_data['message'] = __('Connection failed!', 'nobloat-s3-offload');
                wp_send_json_error($response_data, 401);
            }
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for connection failures
            error_log('NBS3 Connection Error: ' . $e->getMessage());
            update_option('nbs3_last_connection_check', $current_time);

            $error_msg = $e->getMessage();
            $safe_message = '';

            if (strpos($error_msg, 'InvalidAccessKeyId') !== false) {
                $safe_message = __('Invalid Access Key ID. Please check your credentials.', 'nobloat-s3-offload');
            } elseif (strpos($error_msg, 'SignatureDoesNotMatch') !== false) {
                $safe_message = __('Invalid Secret Access Key. Please check your credentials.', 'nobloat-s3-offload');
            } elseif (strpos($error_msg, 'NoSuchBucket') !== false) {
                $safe_message = __('Bucket not found. Please check the bucket name.', 'nobloat-s3-offload');
            } elseif (strpos($error_msg, 'AccessDenied') !== false) {
                $safe_message = __('Access denied. Please check your credentials and bucket permissions.', 'nobloat-s3-offload');
            } elseif (strpos($error_msg, 'PermanentRedirect') !== false || strpos($error_msg, 'region') !== false) {
                $safe_message = __('Region mismatch. Please check your region setting matches the bucket location.', 'nobloat-s3-offload');
            } elseif (strpos($error_msg, 'Could not resolve host') !== false || strpos($error_msg, 'cURL error') !== false) {
                $safe_message = __('Could not connect to S3. Please check your endpoint and region settings.', 'nobloat-s3-offload');
            } else {
                // Show a truncated version of the actual error for debugging (sanitized)
                $safe_message = __('Connection failed: ', 'nobloat-s3-offload') . wp_kses(substr($error_msg, 0, 300), []);
            }

            $response_data['message'] = $safe_message;
            wp_send_json_error($response_data, 500);
        }
    }

    public function save_general_settings_ajax()
    {
        if (!$this->verify_security_nonce('security_nonce', 'nbs3_save_general_settings')) {
            wp_send_json_error([
                'message' => __('Invalid security token!', 'nobloat-s3-offload')
            ]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'nobloat-s3-offload')
            ]);
        }

        // Validate input structure
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_security_nonce() above
        if (!isset($_POST['nbs3_settings']) || !is_array($_POST['nbs3_settings'])) {
            wp_send_json_error([
                'message' => __('Invalid settings data format.', 'nobloat-s3-offload')
            ]);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above, data sanitized by $this->sanitize()
        $settings = wp_unslash($_POST['nbs3_settings']);

        // Additional validation after unslashing
        if (!is_array($settings)) {
            wp_send_json_error([
                'message' => __('Invalid settings data structure.', 'nobloat-s3-offload')
            ]);
        }

        $sanitized_settings = $this->sanitize($settings);
        update_option('nbs3_settings', $sanitized_settings);

        wp_send_json_success([
            'message' => __('Settings saved successfully!', 'nobloat-s3-offload')
        ]);
    }

    public function save_credentials_ajax()
    {
        if (!$this->verify_security_nonce('security_nonce', 'nbs3_save_credentials')) {
            wp_send_json_error([
                'message' => __('Invalid security token!', 'nobloat-s3-offload')
            ]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'nobloat-s3-offload')
            ]);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above, data sanitized by $this->sanitize_credentials()
        $credentials = isset($_POST['nbs3_credentials']) ? wp_unslash($_POST['nbs3_credentials']) : [];
        $sanitized_credentials = $this->sanitize_credentials($credentials);
        update_option('nbs3_credentials', $sanitized_credentials);

        wp_send_json_success([
            'message' => __('Credentials saved successfully!', 'nobloat-s3-offload')
        ]);
    }

    /**
     * AJAX handler for syncing Bricks CSS files to S3.
     */
    public function sync_bricks_ajax()
    {
        if (!$this->verify_security_nonce('security_nonce', 'nbs3_bricks_sync')) {
            wp_send_json_error([
                'message' => __('Invalid security token!', 'nobloat-s3-offload')
            ]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'nobloat-s3-offload')
            ]);
        }

        if (!nbs3_is_bricks_active()) {
            wp_send_json_error([
                'message' => __('Bricks Builder is not active.', 'nobloat-s3-offload')
            ]);
        }

        $bucket = nbs3_get_credential('bucket');
        if (empty($bucket)) {
            wp_send_json_error([
                'message' => __('Please configure your S3 credentials first.', 'nobloat-s3-offload')
            ]);
        }

        try {
            $s3Provider = new S3Provider();
            $syncService = new \NBS3\Services\BricksCssSyncService($s3Provider);
            $result = $syncService->fullSync();

            $status = nbs3_get_bricks_sync_status();

            $message = sprintf(
                /* translators: %1$d: number of files uploaded, %2$d: number of files deleted */
                __('Sync completed. %1$d uploaded, %2$d deleted.', 'nobloat-s3-offload'),
                $result['uploaded'],
                $result['deleted']
            );
            wp_send_json_success([
                'message' => $message,
                'uploaded' => $result['uploaded'],
                'deleted' => $result['deleted'],
                'errors' => $result['errors'],
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for sync failures
            error_log('NBS3 Bricks Sync Error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => __('Sync failed. Check your server error logs for details.', 'nobloat-s3-offload')
            ]);
        }
    }

    /**
     * AJAX handler for removing Bricks CSS files from S3.
     */
    public function remove_bricks_from_s3_ajax()
    {
        if (!$this->verify_security_nonce('security_nonce', 'nbs3_bricks_remove')) {
            wp_send_json_error([
                'message' => __('Invalid security token!', 'nobloat-s3-offload')
            ]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'nobloat-s3-offload')
            ]);
        }

        if (!nbs3_is_bricks_active()) {
            wp_send_json_error([
                'message' => __('Bricks Builder is not active.', 'nobloat-s3-offload')
            ]);
        }

        $bucket = nbs3_get_credential('bucket');
        if (empty($bucket)) {
            wp_send_json_error([
                'message' => __('Please configure your S3 credentials first.', 'nobloat-s3-offload')
            ]);
        }

        try {
            $s3Provider = new S3Provider();
            $syncService = new \NBS3\Services\BricksCssSyncService($s3Provider);
            $result = $syncService->removeAllFromS3();

            $status = nbs3_get_bricks_sync_status();

            $message = sprintf(
                /* translators: %d: number of files deleted */
                __('Removed %d files from S3.', 'nobloat-s3-offload'),
                $result['deleted']
            );
            wp_send_json_success([
                'message' => $message,
                'deleted' => $result['deleted'],
                'errors' => $result['errors'],
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging S3 sync failures
            error_log('NBS3 Bricks Remove Error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => __('Removal failed. Check your server error logs for details.', 'nobloat-s3-offload')
            ]);
        }
    }

    /**
     * AJAX handler for getting Bricks CSS sync status.
     */
    public function get_bricks_status_ajax()
    {
        if (!$this->verify_security_nonce('security_nonce', 'nbs3_bricks_status')) {
            wp_send_json_error([
                'message' => __('Invalid security token!', 'nobloat-s3-offload')
            ]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'nobloat-s3-offload')
            ]);
        }

        $status = nbs3_get_bricks_sync_status();

        $status_text = sprintf(
            /* translators: %1$d: number synced, %2$d: number pending, %3$d: total number */
            __('%1$d synced, %2$d pending, %3$d total', 'nobloat-s3-offload'),
            $status['synced'],
            $status['pending'],
            $status['total']
        );
        wp_send_json_success([
            'status' => $status,
            'status_text' => $status_text,
        ]);
    }

    /**
     * AJAX handler for syncing Bricks theme assets to S3.
     */
    public function sync_bricks_theme_assets_ajax()
    {
        if (!$this->verify_security_nonce('security_nonce', 'nbs3_bricks_theme_assets_sync')) {
            wp_send_json_error([
                'message' => __('Invalid security token!', 'nobloat-s3-offload')
            ]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'nobloat-s3-offload')
            ]);
        }

        if (!nbs3_is_bricks_active()) {
            wp_send_json_error([
                'message' => __('Bricks Builder is not active.', 'nobloat-s3-offload')
            ]);
        }

        $bucket = nbs3_get_credential('bucket');
        if (empty($bucket)) {
            wp_send_json_error([
                'message' => __('Please configure your S3 credentials first.', 'nobloat-s3-offload')
            ]);
        }

        try {
            $s3Provider = new S3Provider();
            $syncService = new \NBS3\Services\BricksThemeAssetsSyncService($s3Provider);
            $result = $syncService->fullSync();
            $status = $syncService->getStatus();

            $message = sprintf(
                /* translators: %1$d: files uploaded, %2$d: files skipped, %3$d: files deleted */
                __('Sync completed. %1$d uploaded, %2$d skipped, %3$d deleted.', 'nobloat-s3-offload'),
                $result['uploaded'],
                $result['skipped'],
                $result['deleted']
            );
            wp_send_json_success([
                'message' => $message,
                'uploaded' => $result['uploaded'],
                'skipped' => $result['skipped'],
                'deleted' => $result['deleted'],
                'errors' => $result['errors'],
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for sync failures
            error_log('NBS3 Bricks Theme Assets Sync Error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => __('Sync failed. Check your server error logs for details.', 'nobloat-s3-offload')
            ]);
        }
    }

    /**
     * AJAX handler for removing Bricks theme assets from S3.
     */
    public function remove_bricks_theme_assets_ajax()
    {
        if (!$this->verify_security_nonce('security_nonce', 'nbs3_bricks_theme_assets_remove')) {
            wp_send_json_error([
                'message' => __('Invalid security token!', 'nobloat-s3-offload')
            ]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'nobloat-s3-offload')
            ]);
        }

        if (!nbs3_is_bricks_active()) {
            wp_send_json_error([
                'message' => __('Bricks Builder is not active.', 'nobloat-s3-offload')
            ]);
        }

        $bucket = nbs3_get_credential('bucket');
        if (empty($bucket)) {
            wp_send_json_error([
                'message' => __('Please configure your S3 credentials first.', 'nobloat-s3-offload')
            ]);
        }

        try {
            $s3Provider = new S3Provider();
            $syncService = new \NBS3\Services\BricksThemeAssetsSyncService($s3Provider);
            $result = $syncService->removeAllFromS3();
            $status = $syncService->getStatus();

            $message = sprintf(
                /* translators: %d: number of files deleted */
                __('Removed %d files from S3.', 'nobloat-s3-offload'),
                $result['deleted']
            );
            wp_send_json_success([
                'message' => $message,
                'deleted' => $result['deleted'],
                'errors' => $result['errors'],
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging S3 sync failures
            error_log('NBS3 Bricks Theme Assets Remove Error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => __('Removal failed. Check your server error logs for details.', 'nobloat-s3-offload')
            ]);
        }
    }

    private function verify_security_nonce($name, $action)
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce values don't need unslashing
        $security_nonce = isset($_POST[$name]) ? sanitize_text_field($_POST[$name]) : '';
        return wp_verify_nonce($security_nonce, $action);
    }

    private function __clone() {}
    public function __wakeup() {}
}
