<?php

namespace SmoothMembers\Core;

use SmoothMembers\Controllers\AdminMenuController;

/**
 * Central plugin lifecycle coordinator.
 */
class Plugin
{
    /**
     * Boot plugin services and register WordPress hooks.
     */
    public static function boot(): void
    {
        $adminMenuController = new AdminMenuController();
        $adminMenuController->register();
    }
}
