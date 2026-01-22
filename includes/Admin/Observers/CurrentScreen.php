<?php

namespace NBS3\Admin\Observers;

defined( 'ABSPATH' ) || exit;

use NBS3\Admin\Observers\AdminHeader;
use NBS3\Admin\Observers\AdminFooterTexts;

use NBS3\Interfaces\ObserverInterface;

class CurrentScreen implements ObserverInterface
{
    public function __construct()
    {
        $this->register();
    }

    public function register(): void
    {
        add_action('current_screen', array($this, 'run'));
    }

    public function run($screen)
    {
        if (nbs3_is_settings_page()) :
            AdminHeader::getInstance();
            AdminFooterTexts::getInstance();
        endif;
    }

    // Prevent cloning of the instance
    private function __clone() {}

    // Prevent unserializing of the instance
    public function __wakeup() {}
}
