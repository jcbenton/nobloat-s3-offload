<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
    'prefix' => 'NBS3Vendor',

    'finders' => [
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->notName('/LICENSE|.*\\.md|.*\\.dist|Makefile/')
            ->exclude([
                'doc',
                'test',
                'test_old',
                'tests',
                'Tests',
                'vendor-bin',
            ])
            ->in('vendor'),
        Finder::create()->append([
            'composer.json',
        ]),
    ],

    'exclude-namespaces' => [
        'NBS3',
    ],

    'exclude-classes' => [
        'WP_Background_Process',
        'WP_Async_Request',
    ],

    'exclude-functions' => [
        // WordPress functions
        'wp_*',
        'get_*',
        'add_*',
        'do_*',
        'apply_*',
        'current_*',
        'is_*',
        'check_*',
        'delete_*',
        'update_*',
        'sanitize_*',
        'esc_*',
        'absint',
        'trailingslashit',
        'untrailingslashit',
        'plugin_dir_path',
        'plugin_dir_url',
        'plugins_url',
        '__',
        '_e',
        '_n',
        '_x',
        'esc_html__',
        'esc_html_e',
        'esc_attr__',
        'esc_attr_e',
        'wp_basename',
    ],

    'exclude-constants' => [
        // WordPress constants
        'ABSPATH',
        'WPINC',
        'WP_CONTENT_DIR',
        'WP_PLUGIN_DIR',
        'MINUTE_IN_SECONDS',
        'HOUR_IN_SECONDS',
        'DAY_IN_SECONDS',
        'WEEK_IN_SECONDS',
        'MONTH_IN_SECONDS',
        'YEAR_IN_SECONDS',
        // Plugin constants
        'NBS3_PATH',
        'NBS3_URL',
        'NBS3_VERSION',
        'NBS3_ENDPOINT',
        'NBS3_REGION',
        'NBS3_BUCKET',
        'NBS3_KEY',
        'NBS3_SECRET',
        'NBS3_DOMAIN',
        'NBS3_PATH_STYLE_ENDPOINT',
    ],

    'patchers' => [
        static function (string $filePath, string $prefix, string $content): string {
            // Fix AWS SDK autoloader
            if (strpos($filePath, 'aws/aws-sdk-php/src/functions.php') !== false) {
                $content = str_replace(
                    "'{$prefix}\\\\Aws\\\\",
                    "'Aws\\\\",
                    $content
                );
            }
            return $content;
        },
    ],
];
