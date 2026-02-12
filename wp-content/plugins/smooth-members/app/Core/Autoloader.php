<?php

namespace SmoothMembers\Core;

/**
 * Lightweight PSR-4 style autoloader for the SmoothMembers namespace.
 */
class Autoloader
{
    /**
     * Register the autoload callback with SPL.
     */
    public function register(): void
    {
        spl_autoload_register([$this, 'autoload']);
    }

    /**
     * Resolve and include class files under the app directory.
     *
     * @param string $class Fully qualified class name.
     */
    private function autoload(string $class): void
    {
        $prefix = 'SmoothMembers\\';

        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $path = SMOOTH_MEMBERS_PATH . 'app/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($path)) {
            require_once $path;
        }
    }
}
