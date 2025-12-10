<?php

namespace NBS3\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

interface ObserverInterface
{
    public function register(): void;
}
