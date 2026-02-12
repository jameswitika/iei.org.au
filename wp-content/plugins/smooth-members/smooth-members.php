<?php
/**
 * Plugin Name: Smooth Members
 * Description: Membership management plugin using an MVC structure.
 * Version: 1.0.0
 * Author: Smooth Members
 * Text Domain: smooth-members
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Plugin bootstrap constants.
 *
 * - SMOOTH_MEMBERS_FILE: Absolute path to this file.
 * - SMOOTH_MEMBERS_PATH: Absolute directory path for plugin includes.
 * - SMOOTH_MEMBERS_URL:  Public URL path for assets.
 */
define('SMOOTH_MEMBERS_FILE', __FILE__);
define('SMOOTH_MEMBERS_PATH', plugin_dir_path(__FILE__));
define('SMOOTH_MEMBERS_URL', plugin_dir_url(__FILE__));

require_once SMOOTH_MEMBERS_PATH . 'app/Core/Autoloader.php';

/**
 * Register autoloading and boot plugin services.
 */
$autoloader = new SmoothMembers\Core\Autoloader();
$autoloader->register();

SmoothMembers\Core\Plugin::boot();
