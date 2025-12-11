<?php

declare(strict_types=1);

function nbs3_get_admin_page_url(string $page): string
{
    return get_admin_url(null, "admin.php?page={$page}");
}

$nbs3_menu_items = [
    'general' => [
        'title' => __('General Settings', 'nobloat-s3-offload'),
        'url' => nbs3_get_admin_page_url('nbs3'),
    ],
    'media-overview' => [
        'title' => __('Media Overview', 'nobloat-s3-offload'),
        'url' => nbs3_get_admin_page_url('nbs3_media_overview'),
    ],
    'aws-guide' => [
        'title' => __('AWS Guide', 'nobloat-s3-offload'),
        'url' => nbs3_get_admin_page_url('nbs3_aws_guide'),
    ],
    'documentation' => [
        'title' => __('Documentation', 'nobloat-s3-offload'),
        'url' => nbs3_get_admin_page_url('nbs3_documentation'),
    ],
    'about' => [
        'title' => __('About', 'nobloat-s3-offload'),
        'url' => nbs3_get_admin_page_url('nbs3_about'),
    ],
];

function nbs3_generate_menu_item(array $item, string $page): string
{
    $class = nbs3_is_settings_page($page) ? 'active' : '';
    return sprintf(
        '<a href="%s" class="%s">%s</a>',
        esc_url($item['url']),
        esc_attr($class),
        esc_html($item['title'])
    );
}
?>

<div class="nbs3-menu">
    <nav>
        <?php foreach ($nbs3_menu_items as $nbs3_slug => $nbs3_item) : ?>
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- nbs3_generate_menu_item() escapes internally
            echo nbs3_generate_menu_item($nbs3_item, $nbs3_slug);
            ?>
        <?php endforeach; ?>
    </nav>
</div>
