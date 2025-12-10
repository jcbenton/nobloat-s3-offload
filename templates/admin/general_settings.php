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
        <?php settings_errors('nbs3_messages'); ?>
        <form method="post" action="options.php">
            <?php settings_fields('nbs3'); ?>
            <?php do_settings_sections('nbs3'); ?>
            <?php submit_button(); ?>
        </form>
    </div>
</div>
