<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    return;
}
?>
<div id="nbs3">
    <div class="wrap">
        <h2 class="nbs3-print-notices-after"></h2>
        <form method="post" action="options.php">
            <?php settings_fields('nbs3_media_overview'); ?>
            <?php do_settings_sections('nbs3_media_overview'); ?>
        </form>
    </div>
</div>
