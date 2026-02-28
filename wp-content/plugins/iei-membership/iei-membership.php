<?php
/**
 * Plugin Name: IEI Membership
 * Plugin URI: https://iei.org.au
 * Description: IEI membership management plugin scaffold.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: IEI
 * Text Domain: iei-membership
 */

if (! defined('ABSPATH')) {
    exit;
}

define('IEI_MEMBERSHIP_VERSION', '0.1.0');
define('IEI_MEMBERSHIP_DB_VERSION', '1.2.0');
define('IEI_MEMBERSHIP_FILE', __FILE__);
define('IEI_MEMBERSHIP_PATH', plugin_dir_path(__FILE__));
define('IEI_MEMBERSHIP_URL', plugin_dir_url(__FILE__));
define('IEI_MEMBERSHIP_OPTION_KEY', 'iei_membership_settings');
define('IEI_MEMBERSHIP_DB_VERSION_OPTION_KEY', 'iei_membership_db_version');

/**
 * Lightweight autoloader for IEI Membership namespaced classes.
 */
spl_autoload_register(static function (string $class): void {
    $namespace = 'IEI\\Membership\\';

    if (strpos($class, $namespace) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($namespace));
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
    $file = IEI_MEMBERSHIP_PATH . 'app' . DIRECTORY_SEPARATOR . $relativePath;

    if (is_readable($file)) {
        require_once $file;
    }
});

/**
 * Default plugin settings used on first install and as merge fallbacks.
 */
function iei_membership_default_settings(): array
{
    return [
        'approval_threshold' => 7,
        'rejection_threshold' => 7,
        'grace_period_days' => 30,
        'prorata_cutoff_days' => 15,
        'membership_type_prices' => [
            'associate' => 145,
            'corporate' => 145,
            'senior' => 70,
        ],
        'protected_storage_dir' => '/wp-content/protected-folder/iei-membership/',
        'allowed_mime_types' => ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'],
        'bank_transfer_enabled' => true,
        'bank_transfer_instructions' => '',
        'active_gateway' => 'stripe',
        'director_dashboard_page_id' => 0,
        'member_payment_portal_page_id' => 0,
        'member_home_page_id' => 0,
        'next_membership_number' => 1,
    ];
}

register_activation_hook(__FILE__, static function (): void {
    if (get_option(IEI_MEMBERSHIP_OPTION_KEY) === false) {
        add_option(IEI_MEMBERSHIP_OPTION_KEY, iei_membership_default_settings());
    }

    if (class_exists('IEI\\Membership\\Bootstrap')) {
        IEI\Membership\Bootstrap::activate();
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    if (class_exists('IEI\\Membership\\Bootstrap')) {
        IEI\Membership\Bootstrap::deactivate();
    }
});

add_action('init', static function (): void {
    load_plugin_textdomain(
        'iei-membership',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );

    if (! class_exists('IEI\\Membership\\Bootstrap')) {
        return;
    }

    $plugin = new IEI\Membership\Bootstrap();
    $plugin->run();
}, 20);
