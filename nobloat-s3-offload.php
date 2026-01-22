<?php
/*
 * Plugin Name:       Nobloat S3 Offload
 * Plugin URI:        https://github.com/mailborder/nobloat-s3-offload
 * Description:       Lightweight S3 media offloader for WordPress. Offloads media to any S3-compatible storage.
 * Version:           1.0.7
 * Requires at least: 5.6
 * Requires PHP:      8.1
 * Author:            Jerry Benton
 * Author URI:        https://www.mailborder.com
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       nobloat-s3-offload
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('NBS3')) {
    /**
     * Main NBS3 Plugin Class
     */
    class NBS3
    {
        /** @var \NBS3\Core\Container */
        public $container;

        /** @var string */
        public $version;

        /** @var NBS3\Offloader */
        public $offloader;

        public function __construct()
        {
            $plugin_data = get_file_data(__FILE__, array('Version' => 'Version'));
            $this->version = $plugin_data['Version'];
        }

        public function initialize()
        {
            // Define constants
            $this->define('NBS3', true);
            $this->define('NBS3_PATH', plugin_dir_path(__FILE__));
            $this->define('NBS3_URL', plugin_dir_url(__FILE__));
            $this->define('NBS3_BASENAME', plugin_basename(__FILE__));
            $this->define('NBS3_VERSION', $this->version);

            // Register activation/deactivation hooks (must be done early)
            register_activation_hook(__FILE__, array($this, 'plugin_activated'));
            register_deactivation_hook(__FILE__, array($this, 'plugin_deactivated'));

            // Delay the rest of initialization until plugins_loaded
            // to ensure translations are loaded at the proper time (WP 6.7+)
            add_action('plugins_loaded', array($this, 'late_initialize'));
        }

        public function late_initialize()
        {
            // Set up container
            $this->setup_container();

            // Include files and setup hooks
            $this->include_files();
            $this->setup_hooks();
        }

        private function setup_container()
        {
            // Include autoloader
            if (file_exists(NBS3_PATH . 'vendor/scoper-autoload.php')) {
                require_once NBS3_PATH . 'vendor/scoper-autoload.php';
            } elseif (file_exists(NBS3_PATH . 'vendor/autoload.php')) {
                require_once NBS3_PATH . 'vendor/autoload.php';
            }

            $this->container = new \NBS3\Core\Container();

            // Register S3 provider
            $this->container->register('s3_provider', function ($c) {
                $settings = get_option('nbs3_settings', []);
                $credentials = get_option('nbs3_credentials', []);

                // Check if we have minimum required settings
                $bucket = nbs3_get_credential('bucket');
                if (empty($bucket)) {
                    return null;
                }

                return new \NBS3\S3Provider();
            });

            $this->container->register('offloader', function ($c) {
                if ($c->has('s3_provider') && $c->get('s3_provider') !== null) {
                    return \NBS3\Offloader::get_instance($c->get('s3_provider'));
                }
                return null;
            });

            $this->container->register('settings_page', function ($c) {
                return \NBS3\Admin\GeneralSettings::getInstance();
            });

            $this->container->register('media_overview_page', function ($c) {
                return \NBS3\Admin\MediaOverview::getInstance();
            });

            $this->container->register('bulk_offload_handler', function ($c) {
                return \NBS3\BulkOffloadHandler::get_instance();
            });
        }

        private function setup_hooks()
        {
            // Check if AWS SDK is loaded
            if (!class_exists(NBS3Vendor\Aws\S3\S3Client::class)) {
                add_action('admin_notices', function () {
                    $this->notice(__('AWS SDK for PHP is required for Nobloat S3 Offload.', 'nobloat-s3-offload'), 'error');
                });
                return;
            }

            // Include admin if needed
            if (is_admin()) {
                $this->container->get('settings_page');
                $this->container->get('media_overview_page');
                new \NBS3\Admin\Observers\CurrentScreen();

                add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);
            }

            // Initialize offloader if configured
            if ($this->container->has('offloader') && $this->container->get('offloader') !== null) {
                $this->container->get('offloader')->initializeHooks();
            }

            // Initialize bulk offload handler
            $this->container->get('bulk_offload_handler');

            // Register Bricks observers (only if S3 is configured)
            $this->register_bricks_observers();

            // Register cron schedules
            $this->register_cron_schedules();

            // Register WP CLI commands
            $this->register_cli_commands();
        }

        private function register_bricks_observers()
        {
            // Only register if we have S3 configured
            $s3_provider = $this->container->get('s3_provider');
            if ($s3_provider === null) {
                return;
            }

            // Register the CSS sync observer (handles immediate sync on Bricks CSS generation)
            $sync_observer = new \NBS3\Observers\BricksCssSyncObserver($s3_provider);
            $sync_observer->register();

            // Register the URL rewrite observer (rewrites CSS URLs to CDN)
            $url_rewrite_observer = new \NBS3\Observers\BricksCssUrlRewriteObserver();
            $url_rewrite_observer->register();

            // Register the theme assets sync observer (syncs on plugin/theme updates)
            $theme_assets_observer = new \NBS3\Observers\BricksThemeAssetsSyncObserver($s3_provider);
            $theme_assets_observer->register();

            // Register the cron hook for async theme assets sync
            \NBS3\Observers\BricksThemeAssetsSyncObserver::registerCronHook($s3_provider);
        }

        private function register_cron_schedules()
        {
            // Add custom cron interval for Bricks sync
            add_filter('cron_schedules', function ($schedules) {
                $schedules['nbs3_five_minutes'] = [
                    'interval' => 5 * MINUTE_IN_SECONDS,
                    'display' => __('Every 5 Minutes', 'nobloat-s3-offload'),
                ];
                return $schedules;
            });

            // Handle Bricks sync cron
            add_action('nbs3_bricks_sync_cron', [$this, 'run_bricks_sync_cron']);

            // Handle initial sync cron (triggered when enabling Bricks sync)
            add_action('nbs3_bricks_initial_sync', [$this, 'run_bricks_initial_sync']);

            // Schedule the cron if Bricks sync is enabled and not already scheduled
            if (nbs3_get_setting('sync_bricks_css', false) && nbs3_is_bricks_active()) {
                if (!wp_next_scheduled('nbs3_bricks_sync_cron')) {
                    wp_schedule_event(time(), 'nbs3_five_minutes', 'nbs3_bricks_sync_cron');
                }
            }
        }

        /**
         * Cron job for Bricks CSS sync (handles deletions and catches any missed files).
         */
        public function run_bricks_sync_cron()
        {
            // Skip if sync is disabled
            if (!nbs3_get_setting('sync_bricks_css', false)) {
                // Unschedule the cron if sync is disabled
                wp_clear_scheduled_hook('nbs3_bricks_sync_cron');
                return;
            }

            // Skip if Bricks is not active
            if (!nbs3_is_bricks_active()) {
                return;
            }

            $s3_provider = $this->container->get('s3_provider');
            if ($s3_provider === null) {
                return;
            }

            try {
                $sync_service = new \NBS3\Services\BricksCssSyncService($s3_provider);
                $result = $sync_service->fullSync();

                if ($result['uploaded'] > 0 || $result['deleted'] > 0) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional logging for cron sync results
                    error_log(sprintf(
                        'NBS3 Bricks Cron: Synced %d files, deleted %d files, %d errors',
                        $result['uploaded'],
                        $result['deleted'],
                        $result['errors']
                    ));
                }
            } catch (\Exception $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging
                error_log('NBS3 Bricks Cron Error: ' . $e->getMessage());
            }
        }

        /**
         * Initial sync when Bricks sync is first enabled.
         */
        public function run_bricks_initial_sync()
        {
            $this->run_bricks_sync_cron();
        }

        private function include_files()
        {
            include_once NBS3_PATH . 'includes/utility-functions.php';
        }

        private function register_cli_commands()
        {
            if (defined('WP_CLI') && WP_CLI) {
                WP_CLI::add_command('nbs3 offload', 'NBS3\\CLI\\OffloadCommand');
                WP_CLI::add_command('nbs3 revert', 'NBS3\\CLI\\RevertCommand');
                WP_CLI::add_command('nbs3 sync-bricks', 'NBS3\\CLI\\BricksSyncCommand');
            }
        }

        public function plugin_action_links($links)
        {
            $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=nbs3')) . '">' . __('Settings', 'nobloat-s3-offload') . '</a>';
            array_unshift($links, $settings_link);
            return $links;
        }

        public function plugin_activated()
        {
            if (null === get_option('nbs3_first_activated_version', null)) {
                update_option('nbs3_first_activated_version', NBS3_VERSION, true);
            }
        }

        public function plugin_deactivated()
        {
            wp_clear_scheduled_hook('nbs3_bulk_offload_cron');
            wp_clear_scheduled_hook('nbs3_check_stalled_processes');
            wp_clear_scheduled_hook('nbs3_bricks_sync_cron');
            wp_clear_scheduled_hook('nbs3_bricks_initial_sync');
            delete_option('nbs3_bulk_offload_cancelled');
            delete_option('nbs3_bulk_offload_last_update');
            delete_option('nbs3_bulk_offload_data');
            delete_option('nbs3_last_connection_check');
            // Note: We don't delete nbs3_synced_bricks_files on deactivation
            // to preserve sync state if plugin is reactivated

            if ($this->container && $this->container->has('bulk_offload_handler')) {
                try {
                    $bulk_offload_handler = $this->container->get('bulk_offload_handler');
                    if ($bulk_offload_handler) {
                        remove_filter('cron_schedules', array($bulk_offload_handler, 'add_cron_interval'));
                    }
                } catch (\Exception $e) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging
                    error_log('NBS3: Error during deactivation: ' . $e->getMessage());
                }
            }
        }

        public function define($name, $value = true)
        {
            if (!defined($name)) {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- $name is a method parameter containing prefixed constant names
                define($name, $value);
            }
        }

        public function notice($message, $type = 'info')
        {
            $class = 'notice notice-' . $type;
            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
        }
    }

    function nbs3()
    {
        global $nbs3;

        if (!isset($nbs3)) {
            $nbs3 = new NBS3();
            $nbs3->initialize();
        }
        return $nbs3;
    }

    nbs3();
}
