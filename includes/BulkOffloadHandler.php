<?php

namespace NBS3;

use NBS3\Services\BulkMediaOffloader;
use NBS3\S3Provider;

class BulkOffloadHandler
{
    protected $process_all;

    private static $instance = null;

    public static function get_instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct()
    {
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_nbs3_check_bulk_offload_progress', array($this, 'get_progress'));
        add_action('wp_ajax_nbs3_start_bulk_offload', array($this, 'bulk_offload'));
        add_action('wp_ajax_nbs3_cancel_bulk_offload', array($this, 'cancel_bulk_offload'));
        add_action('nbs3_cleanup_orphaned_queue', array($this, 'cleanup_orphaned_queue'));
    }

    public function init()
    {
        try {
            $bucket = nbs3_get_credential('bucket');

            if (empty($bucket)) {
                return;
            }

            $s3Provider = new S3Provider();
            $this->process_all = new BulkMediaOffloader($s3Provider);
            add_action($this->process_all->get_identifier() . '_cancelled', array($this, 'process_is_cancelled'));

            add_filter('cron_schedules', [$this, 'add_cron_interval']);
            add_action('nbs3_check_stalled_processes', [$this, 'check_stalled_processes']);

            if (!wp_next_scheduled('nbs3_check_stalled_processes')) {
                wp_schedule_event(time(), 'nbs3_fifteen_min', 'nbs3_check_stalled_processes');
            }
        } catch (\Exception $e) {
            error_log('NBS3 - Error: ' . $e->getMessage());
        }
    }

    public function add_cron_interval($schedules)
    {
        $schedules['nbs3_fifteen_min'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Every 15 minutes', 'nobloat-s3-offload')
        ];
        return $schedules;
    }

    public function check_stalled_processes()
    {
        if (!$this->process_all instanceof BulkMediaOffloader) {
            return;
        }

        $process_lock = get_site_transient($this->process_all->get_identifier() . '_process_lock');
        $bulk_data = nbs3_get_bulk_offload_data();
        $last_update = isset($bulk_data['last_update']) ? (int) $bulk_data['last_update'] : 0;

        if (0 === $last_update) {
            $last_update = (int) get_option('nbs3_bulk_offload_last_update', 0);
        }
        $current_time = time();

        if ($process_lock && ($current_time - $last_update) > 600) {
            $bulk_data = nbs3_get_bulk_offload_data();

            error_log(sprintf(
                'NBS3: Detected stalled bulk offload process. Lock time: %s, Last update: %s, Processed: %d/%d',
                date('Y-m-d H:i:s', $process_lock),
                date('Y-m-d H:i:s', $last_update),
                $bulk_data['processed'] ?? 0,
                $bulk_data['total'] ?? 0
            ));

            $this->force_unlock_process();

            nbs3_update_bulk_offload_data([
                'status' => 'recovered_from_stall',
                'last_recovery' => $current_time
            ]);

            wp_schedule_single_event(time() + 60, 'nbs3_cleanup_orphaned_queue');
        }

        if ($last_update > 0 && ($current_time - $last_update) > 3600) {
            $bulk_data = nbs3_get_bulk_offload_data();

            if (isset($bulk_data['status']) && in_array($bulk_data['status'], ['processing', 'starting'])) {
                error_log('NBS3: Cleaning up very old bulk offload process (>1 hour)');

                $this->force_unlock_process();
                nbs3_update_bulk_offload_data([
                    'status' => 'timeout_cleanup',
                    'last_cleanup' => $current_time
                ]);
            }
        }
    }

    private function force_unlock_process()
    {
        if (!$this->process_all instanceof BulkMediaOffloader) {
            return;
        }

        delete_site_transient($this->process_all->get_identifier() . '_process_lock');
        delete_site_transient($this->process_all->get_identifier() . '_batch_lock');
        delete_option('nbs3_bulk_offload_cancelled');
        wp_clear_scheduled_hook($this->process_all->get_identifier() . '_cron');

        if ($this->process_all->is_queued() && !$this->process_all->is_processing()) {
            $this->process_all->dispatch();
        }
    }

    public function bulk_offload()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Permission denied', 'nobloat-s3-offload')
            ], 403);
        }

        if (!check_ajax_referer('nbs3_bulk_offload', 'bulk_offload_nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed', 'nobloat-s3-offload')
            ], 403);
        }

        $ready = $this->ensure_process_ready();
        if (is_wp_error($ready)) {
            wp_send_json_error([
                'message' => $ready->get_error_message(),
                'code' => $ready->get_error_code(),
            ], 400);
        }

        try {
            $this->handle_all();
            $bulk_offload_data = nbs3_get_bulk_offload_data();

            wp_send_json_success([
                'total' => $bulk_offload_data['total'],
            ]);
        } catch (\Exception $e) {
            error_log('NBS3 Bulk Offload Error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function get_progress()
    {
        if (!check_ajax_referer('nbs3_bulk_offload', 'bulk_offload_nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'nobloat-s3-offload')], 403);
            return;
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied', 'nobloat-s3-offload')], 403);
            return;
        }

        $bulk_offload_data = nbs3_get_bulk_offload_data();
        $is_bulk_offload_cancelled = get_option("nbs3_bulk_offload_cancelled");
        wp_send_json_success([
            'processed' => $bulk_offload_data['processed'],
            'total' => $bulk_offload_data['total'],
            'status' => $is_bulk_offload_cancelled ? "cancelled" : $bulk_offload_data['status'],
            'errors' => $bulk_offload_data['errors'] ?? 0,
            'oversized_skipped' => $bulk_offload_data['oversized_skipped'] ?? 0,
        ]);
    }

    protected function handle_all()
    {
        $ready = $this->ensure_process_ready();
        if (is_wp_error($ready)) {
            throw new \Exception($ready->get_error_message());
        }

        $names = $this->get_unoffloaded_attachments();

        foreach ($names as $name) {
            $this->process_all->push_to_queue($name);
        }

        $this->process_all->save()->dispatch();
    }

    protected function get_unoffloaded_attachments($batch_size = 50)
    {
        global $wpdb;

        $max_batch_size_mb = 150;
        $current_batch_size = 0;
        $filtered_attachments = [];
        $oversized_files = 0;

        $query = $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'nbs3_offloaded'
            LEFT JOIN {$wpdb->postmeta} em ON p.ID = em.post_id AND em.meta_key = 'nbs3_error_log'
            WHERE p.post_type = 'attachment'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
            AND em.meta_id IS NULL
            ORDER BY p.post_date ASC
            LIMIT %d",
            $batch_size * 2
        );

        $normal_attachments = $wpdb->get_col($query);

        foreach ($normal_attachments as $attachment_id) {
            if (count($filtered_attachments) >= $batch_size) {
                break;
            }

            if (false === apply_filters('nbs3_should_offload_attachment', true, $attachment_id)) {
                continue;
            }

            $file_path = get_attached_file($attachment_id);
            if (file_exists($file_path)) {
                $file_size = filesize($file_path) / (1024 * 1024);

                if ($file_size > 10) {
                    $error_msg = sprintf(
                        __('File exceeds maximum size (%s MB) for bulk processing', 'nobloat-s3-offload'),
                        '10'
                    );
                    update_post_meta($attachment_id, 'nbs3_error_log', $error_msg);
                    $oversized_files++;
                    continue;
                }

                if (($current_batch_size + $file_size) <= $max_batch_size_mb) {
                    $filtered_attachments[] = $attachment_id;
                    $current_batch_size += $file_size;
                } else {
                    break;
                }
            } else {
                $filtered_attachments[] = $attachment_id;
            }
        }

        $remaining_slots = $batch_size - count($filtered_attachments);

        if ($remaining_slots > 0) {
            $error_query = $wpdb->prepare(
                "SELECT p.ID
                FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm_error ON (p.ID = pm_error.post_id AND pm_error.meta_key = 'nbs3_error_log')
                LEFT JOIN {$wpdb->postmeta} pm_offload ON (p.ID = pm_offload.post_id AND pm_offload.meta_key = 'nbs3_offloaded')
                WHERE p.post_type = 'attachment'
                AND (pm_offload.meta_value IS NULL OR pm_offload.meta_value = '')
                AND pm_error.meta_value IS NOT NULL
                AND pm_error.meta_value != ''
                ORDER BY p.post_date ASC
                LIMIT %d",
                $remaining_slots * 2
            );

            $error_attachments = $wpdb->get_col($error_query);

            foreach ($error_attachments as $attachment_id) {
                if (count($filtered_attachments) >= $batch_size) {
                    break;
                }

                if (false === apply_filters('nbs3_should_offload_attachment', true, $attachment_id)) {
                    continue;
                }

                $file_path = get_attached_file($attachment_id);
                if (file_exists($file_path)) {
                    $file_size = filesize($file_path) / (1024 * 1024);

                    if ($file_size > 100) {
                        continue;
                    }

                    if (($current_batch_size + $file_size) <= $max_batch_size_mb) {
                        $filtered_attachments[] = $attachment_id;
                        $current_batch_size += $file_size;
                    } else {
                        break;
                    }
                } else {
                    $filtered_attachments[] = $attachment_id;
                }
            }
        }

        $attachment_count = count($filtered_attachments);
        if ($attachment_count > 0) {
            nbs3_update_bulk_offload_data(array(
                'total' => $attachment_count,
                'status' => 'processing',
                'processed' => 0,
                'errors' => 0,
                'oversized_skipped' => $oversized_files
            ));
        } else {
            nbs3_clear_bulk_offload_data();
        }

        return $filtered_attachments;
    }

    public function cancel_bulk_offload()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Permission denied', 'nobloat-s3-offload')
            ], 403);
        }

        if (!check_ajax_referer('nbs3_bulk_offload', 'bulk_offload_nonce', false)) {
            wp_send_json_error([
                'message' => __('Invalid nonce', 'nobloat-s3-offload')
            ], 403);
        }

        $ready = $this->ensure_process_ready();
        if (is_wp_error($ready)) {
            wp_send_json_error([
                'message' => $ready->get_error_message(),
                'code' => $ready->get_error_code(),
            ], 400);
        }

        $this->process_all->cancel();

        update_option("nbs3_bulk_offload_cancelled", true);

        wp_send_json_success([
            "message" => __('Bulk offload cancelled successfully.', 'nobloat-s3-offload')
        ]);
    }

    public function process_is_cancelled()
    {
        nbs3_update_bulk_offload_data([
            'status' => 'cancelled'
        ]);
        delete_option("nbs3_bulk_offload_cancelled");
    }

    public function cleanup_orphaned_queue()
    {
        if (!$this->process_all instanceof BulkMediaOffloader) {
            return;
        }

        $batches = $this->process_all->get_batches();
        $has_pending_items = false;

        foreach ($batches as $batch) {
            if (empty($batch->data)) {
                $this->process_all->delete($batch->key);
                continue;
            }

            $has_pending_items = true;
        }

        if ($has_pending_items && !$this->process_all->is_processing()) {
            $this->process_all->dispatch();
        }
    }

    private function ensure_process_ready()
    {
        if ($this->process_all instanceof BulkMediaOffloader) {
            return true;
        }

        return new \WP_Error(
            'nbs3_offloader_unavailable',
            __('Bulk offload requires S3 credentials. Please configure them before trying again.', 'nobloat-s3-offload')
        );
    }

    public function bulk_offload_cron_healthcheck()
    {
        $this->process_all->handle_cron_healthcheck();
        wp_send_json_success();
    }
}
