<?php

namespace NBS3\Admin;

use \Exception;

class MediaOverview
{
    private static $instance = null;

    private function __construct()
    {
        $this->register();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function register()
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'initialize']);
        add_action('wp_ajax_nbs3_download_errors_csv', array($this, 'handle_download_errors_csv'));
    }

    public function initialize()
    {
        $this->add_media_overview_fields();
        $this->add_media_overview_sections();
    }

    public function add_menu()
    {
        add_submenu_page(
            'nbs3',
            __('Media Overview', 'nobloat-s3-offload'),
            __('Media Overview', 'nobloat-s3-offload'),
            'manage_options',
            'nbs3_media_overview',
            [$this, 'media_overview_page_view']
        );
    }

    public function media_overview_page_view()
    {
        nbs3_get_view('admin/media_overview');
    }

    private function add_media_overview_sections()
    {
        add_settings_section(
            'media_overview',
            __('Media Library Overview', 'nobloat-s3-offload'),
            function () {
                echo '<p>' . esc_html__('Get a comprehensive overview of all media files in your WordPress library. Easily identify any files that haven\'t been offloaded to the Cloud and offload them in bulk.', 'nobloat-s3-offload') . '</p></div>';
            },
            'nbs3_media_overview',
            [
                'before_section' => '<div class="nbs3-section nbs3-media-overview"><div class="nbs3-section-header">',
                'after_section' => '</div>',
            ]
        );

        add_settings_section(
            'media_bulk_offload',
            __('Bulk Offload', 'nobloat-s3-offload'),
            function () {
                echo '<p>' . esc_html__('Bulk offload your unoffloaded media files to cloud storage. This process frees up your server storage and boosts your website\'s performance in one go!', 'nobloat-s3-offload') . '</p></div>';
            },
            'nbs3_media_overview',
            [
                'before_section' => '<div class="nbs3-section nbs3-media-overview"><div class="nbs3-section-header">',
                'after_section' => '</div>',
            ]
        );
    }

    private function add_media_overview_fields()
    {
        add_settings_field(
            'total_media_files',
            __('Total Media Files', 'nobloat-s3-offload'),
            [$this, 'total_media_files_field'],
            'nbs3_media_overview',
            'media_overview',
            [
                'class' => 'nbs3-field nbs3-non-offloaded-media',
            ]
        );

        add_settings_field(
            'offloaded_media',
            __('Offloaded Media', 'nobloat-s3-offload'),
            [$this, 'offloaded_media_field'],
            'nbs3_media_overview',
            'media_overview',
            [
                'class' => 'nbs3-field nbs3-non-offloaded-media',
            ]
        );

        add_settings_field(
            'non_offloaded_media',
            __('Non-Offloaded Media', 'nobloat-s3-offload'),
            [$this, 'non_offloaded_media_field'],
            'nbs3_media_overview',
            'media_overview',
            [
                'class' => 'nbs3-field nbs3-non-offloaded-media',
            ]
        );

        add_settings_field(
            'offload_errors',
            __('Offload Errors', 'nobloat-s3-offload'),
            [$this, 'offload_errors_field'],
            'nbs3_media_overview',
            'media_overview',
            [
                'class' => 'nbs3-field nbs3-non-offloaded-media',
            ]
        );

        add_settings_field(
            'bulk_offload_media',
            __('Bulk Offload Existing Media', 'nobloat-s3-offload'),
            [$this, 'bulk_offload_media_field'],
            'nbs3_media_overview',
            'media_bulk_offload',
            [
                'class' => 'nbs3-field nbs3-bulk-offload-media',
            ]
        );
    }

    public function total_media_files_field()
    {
        $total_attachments = wp_count_attachments();
        $total_count = array_sum((array) $total_attachments);

        echo '<p class="nbs3-stat">';
        echo '<span class="nbs3-stat-number">' . esc_html(number_format_i18n($total_count)) . ' </span>';
        echo '<span class="nbs3-stat-label">' . esc_html__('Media Attachments', 'nobloat-s3-offload') . '</span>';
        echo '</p>';
        echo '<p class="description">' . esc_html__('Total number of media files stored on your server.', 'nobloat-s3-offload') . '</p>';
    }

    public function offloaded_media_field()
    {
        $offloaded_count = nbs3_get_offloaded_media_items_count();

        echo '<p class="nbs3-stat">';
        echo '<span class="nbs3-stat-number">' . esc_html(number_format_i18n($offloaded_count)) . ' </span>';
        echo '<span class="nbs3-stat-label">' . esc_html__('Media Attachments', 'nobloat-s3-offload') . '</span>';
        echo '</p>';
        echo '<p class="description">' . esc_html__('Number of media files successfully moved to cloud storage.', 'nobloat-s3-offload') . '</p>';
    }

    public function non_offloaded_media_field()
    {
        $non_offloaded_count = nbs3_get_unoffloaded_media_items_count();

        echo '<p class="nbs3-stat">';
        echo '<span class="nbs3-stat-number">' . esc_html(number_format_i18n($non_offloaded_count)) . ' </span>';
        if ($non_offloaded_count === 0) {
            echo '<span class="nbs3-stat-label">' . esc_html__('Media Attachments', 'nobloat-s3-offload') . '</span>';
        } elseif ($non_offloaded_count === 1) {
            echo '<span class="nbs3-stat-label">' . esc_html__('Media Attachment', 'nobloat-s3-offload') . '</span>';
        } else {
            echo '<span class="nbs3-stat-label">' . esc_html__('Media Attachments', 'nobloat-s3-offload') . '</span>';
        }
        echo '</p>';

        if ($non_offloaded_count === 0) {
            echo '<p class="description">' . esc_html__('Great job! All your media files are offloaded to cloud storage.', 'nobloat-s3-offload') . '</p>';
        } elseif ($non_offloaded_count === 1) {
            echo '<p class="description">' . esc_html__('There is 1 media file still stored on your local server.', 'nobloat-s3-offload') . '</p>';
            echo '<p class="description">' . esc_html__('This file can be offloaded to free up local storage space.', 'nobloat-s3-offload') . '</p>';
        } else {
            echo '<p class="description">' . esc_html__('These files can be offloaded to free up local storage space.', 'nobloat-s3-offload') . '</p>';
        }
    }

    public function offload_errors_field()
    {
        $attachments_with_errors = $this->get_attachments_with_errors();
        $count = count($attachments_with_errors);

        $nonce = wp_create_nonce('nbs3_download_errors_csv');
        $download_url = admin_url('admin-ajax.php?action=nbs3_download_errors_csv&nonce=' . $nonce);

        echo '<p>' . esc_html__('Number of attachments with errors:', 'nobloat-s3-offload') . ' <strong>' . esc_html($count) . '</strong></p>';

        if ($count > 0) {
            echo '<p><a href="' . esc_url($download_url) . '" class="button button-secondary">' . esc_html__('Download Errors CSV', 'nobloat-s3-offload') . '</a></p>';
        }
    }

    private function get_attachments_with_errors()
    {
        global $wpdb;
        $meta_key = 'nbs3_error_log';

        $query = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
            $meta_key
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above, querying postmeta for specific meta_key
        return $wpdb->get_col($query);
    }

    public function handle_download_errors_csv()
    {
        try {
            if (!current_user_can('manage_options')) {
                throw new Exception('You do not have sufficient permissions to access this page.');
            }

            if (!check_admin_referer('nbs3_download_errors_csv', 'nonce')) {
                throw new Exception('Invalid nonce. Please try again.');
            }

            $attachments_with_errors = $this->get_attachments_with_errors();

            if (empty($attachments_with_errors)) {
                throw new Exception('No attachments with errors found.');
            }

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="nbs3_errors.csv"');

            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new Exception('Unable to create output stream.');
            }

            fputcsv($output, array('Attachment ID', 'Attachment Title', 'Errors'));

            foreach ($attachments_with_errors as $attachment_id) {
                $attachment = get_post($attachment_id);
                if (!$attachment) {
                    continue; // Skip if attachment doesn't exist
                }
                $errors = get_post_meta($attachment_id, 'nbs3_error_log', true);
                $errors_string = is_array($errors) ? implode("; \n", $errors) : $errors;

                fputcsv($output, array(
                    $attachment_id,
                    $attachment->post_title,
                    $errors_string
                ));
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Using php://output stream for CSV generation, WP_Filesystem not applicable
            fclose($output);
            exit;
        } catch (Exception $e) {
            status_header(500);
            wp_die('Error generating CSV: ' . esc_html($e->getMessage()), 'Error', array('response' => 500));
        }
    }


    public function bulk_offload_media_field()
    {
        // WP CLI Notice
        echo '<div style="background: #f0f6fc; border: 1px solid #c3d4e7; border-radius: 4px; padding: 12px; margin-bottom: 20px;">';
        echo '<p style="margin: 0 0 8px 0;"><strong style="color: #0073aa;">💡 ' . esc_html__('Use WP CLI for Large Operations', 'nobloat-s3-offload') . '</strong></p>';
        echo '<p style="margin: 0 0 8px 0; font-size: 13px;">' . esc_html__('For sites with hundreds or thousands of media files, our WP CLI command offers superior performance and control:', 'nobloat-s3-offload') . '</p>';
        echo '<p style="margin: 0 0 8px 0;"><code style="background: #fff; padding: 2px 6px; border-radius: 3px; font-family: monospace;">wp nbs3 offload</code></p>';
        echo '</div>';

        echo '<p class="description"><strong>' . esc_html__('Note:', 'nobloat-s3-offload') . '</strong> ';
        echo esc_html__('This web-based bulk offload supports up to 50 media attachments. For larger operations with hundreds or thousands of files, we recommend using WP CLI commands which provide better performance and reliability for large-scale operations.', 'nobloat-s3-offload') . '</p><br />';

        $bulk_offload_data = nbs3_get_bulk_offload_data();
        $count = nbs3_get_unoffloaded_media_items_count();
        $is_offloading = $bulk_offload_data['status'] === 'processing';
        $progress = ($is_offloading && $bulk_offload_data['total'] > 0) ? ($bulk_offload_data['processed'] / $bulk_offload_data['total']) * 100 : 0;

        if ($count > 0 || $is_offloading) {
            if (!$is_offloading) {
                echo '<p>' . sprintf(
                    /* translators: %d: number of files */
                    esc_html(_n(
                        'You have %d file still stored on your server.',
                        'You have %d files still stored on your server.',
                        $count,
                        'nobloat-s3-offload'
                    )),
                    intval($count)
                ) . '</p>';
                echo '<p class="description">' . esc_html__('Offload them to cloud storage now to free up space and enhance your website\'s performance.', 'nobloat-s3-offload') . '</p>';
                echo '<button type="button" id="bulk-offload-button" class="button">' . esc_html__('Offload Now', 'nobloat-s3-offload') . '</button>';
            }

            $display_style = $is_offloading ? 'block' : 'none';
            $progress_width = $is_offloading ? $progress : 0;
            $progress_text = $is_offloading ? round($progress) . '%' : '0%';
            if ($is_offloading && $bulk_offload_data['total'] == 0) {
                $progress_text = __('Preparing...', 'nobloat-s3-offload');
            }

            $progress_status = $is_offloading ? 'processing' : 'idle';
            $processed = $bulk_offload_data['processed'] ?? 0;
            $total = $bulk_offload_data['total'] ?? 0;

            echo '<div id="progress-container" style="display: ' . esc_attr($display_style) . '; margin-top: 20px;" data-status="' . esc_attr($progress_status) . '">';
            echo '<p id="progress-title" style="font-size: 16px; font-weight: bold;">' .
                sprintf(
                    /* translators: %1$s: processed count span, %2$s: total count span */
                    esc_html__('Offloading media files to cloud storage (%1$s of %2$s)', 'nobloat-s3-offload'),
                    '<span id="processed-count">' . esc_html($processed) . '</span>',
                    '<span id="total-count">' . esc_html($total) . '</span>'
                ) .
                '</p>';
            echo '    <div class="progress-bar-container" style="width: 100%; background-color: #e0e0e0; padding: 3px; border-radius: 3px;">';
            printf('        <div id="offload-progress" style="width: %.1f%%; height: 20px; background-color: #0073aa; border-radius: 2px; transition: width 0.5s;"></div>', esc_html($progress_width));
            echo '    </div>';
            printf('    <p id="progress-text" style="margin-top: 10px; font-weight: bold;">%s</p>', esc_html($progress_text));
            if (get_option("nbs3_bulk_offload_cancelled") === false) {
                echo '<button type="button" id="bulk-offload-cancel-button" class="button">' . esc_html__('Cancel', 'nobloat-s3-offload') . '</button>';
            } else {
                echo '<p>' . esc_html__('Canceling the bulk offload process…', 'nobloat-s3-offload') . '</p>';
            }
            echo '</div>';
        } else {
            echo '<p>' . esc_html__('All media files are currently stored in the cloud.', 'nobloat-s3-offload') . '</p>';
        }
    }
}
