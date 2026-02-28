<?php

namespace IEI\Membership;

use IEI\Membership\Controllers\Admin\SettingsPage;
use IEI\Membership\Controllers\Admin\ApplicationsPage;
use IEI\Membership\Controllers\Admin\DirectorsPage;
use IEI\Membership\Controllers\Admin\ImportMembersPage;
use IEI\Membership\Controllers\Admin\MembersPage;
use IEI\Membership\Controllers\Admin\PaymentsPage;
use IEI\Membership\Controllers\FileController;
use IEI\Membership\Controllers\Frontend\ApplicationShortcodeController;
use IEI\Membership\Controllers\Frontend\DirectorDashboardController;
use IEI\Membership\Controllers\Frontend\MemberPaymentPortalController;
use IEI\Membership\Services\ActivityLogger;
use IEI\Membership\Services\BoardDecisionService;
use IEI\Membership\Services\DailyMaintenanceService;
use IEI\Membership\Services\FileStorageService;
use IEI\Membership\Services\PaymentActivationService;
use IEI\Membership\Services\RolesManager;

/**
 * Plugin runtime bootstrap: wires services, controllers, menus, and hooks.
 */
class Bootstrap
{
    private ?SettingsPage $settingsPage = null;
    private ?FileController $fileController = null;
    private ?ApplicationShortcodeController $applicationShortcodeController = null;
    private ?DirectorDashboardController $directorDashboardController = null;
    private ?MemberPaymentPortalController $memberPaymentPortalController = null;
    private ?ApplicationsPage $applicationsPage = null;
    private ?DirectorsPage $directorsPage = null;
    private ?MembersPage $membersPage = null;
    private ?PaymentsPage $paymentsPage = null;
    private ?ImportMembersPage $importMembersPage = null;
    private ?DailyMaintenanceService $dailyMaintenanceService = null;

    /**
     * Activation bootstrap: ensure roles, schema, and cron are ready.
     */
    public static function activate(): void
    {
        try {
            RolesManager::register_roles_and_capabilities();
            Migrations\Migrator::migrate();
            DailyMaintenanceService::schedule_event();
        } catch (\Throwable $throwable) {
            error_log('[IEI Membership] Activation bootstrap failed: ' . $throwable->getMessage());
        }
    }

    public static function deactivate(): void
    {
        DailyMaintenanceService::unschedule_event();
    }

    /**
     * Runtime bootstrap for every request after plugin load.
     */
    public function run(): void
    {
        RolesManager::register_roles_and_capabilities();
        RolesManager::register_runtime_filters();

        try {
            Migrations\Migrator::migrate();
        } catch (\Throwable $throwable) {
            error_log('[IEI Membership] Runtime migration failed: ' . $throwable->getMessage());
        }

        DailyMaintenanceService::schedule_event();
        $this->dailyMaintenanceService = new DailyMaintenanceService(new ActivityLogger());
        $this->dailyMaintenanceService->register_hooks();

        $this->settingsPage = new SettingsPage(IEI_MEMBERSHIP_OPTION_KEY);
        $this->settingsPage->register_hooks();
        $this->fileController = new FileController(new FileStorageService());
        $this->fileController->register_hooks();
        $this->applicationsPage = new ApplicationsPage(new FileStorageService(), new ActivityLogger());
        $this->applicationsPage->register_hooks();
        $this->directorsPage = new DirectorsPage();
        $this->directorsPage->register_hooks();
        $this->membersPage = new MembersPage();
        $this->membersPage->register_hooks();
        $this->paymentsPage = new PaymentsPage(new PaymentActivationService(new ActivityLogger()));
        $this->paymentsPage->register_hooks();
        $this->importMembersPage = new ImportMembersPage(new ActivityLogger());
        $this->importMembersPage->register_hooks();
        $this->applicationShortcodeController = new ApplicationShortcodeController(
            new FileStorageService(),
            new ActivityLogger()
        );
        $this->applicationShortcodeController->register_hooks();
        $this->directorDashboardController = new DirectorDashboardController(
            new FileStorageService(),
            new ActivityLogger(),
            new BoardDecisionService(new ActivityLogger())
        );
        $this->directorDashboardController->register_hooks();
        $this->memberPaymentPortalController = new MemberPaymentPortalController();
        $this->memberPaymentPortalController->register_hooks();

        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('current_screen', [$this, 'register_help_tabs']);
        add_filter('login_redirect', [$this, 'handle_login_redirect'], 10, 3);
    }

    public function register_admin_menu(): void
    {
        $capability = RolesManager::CAP_MANAGE_MEMBERSHIP;
        $menuSlug = 'iei-membership';

        add_menu_page(
            __('IEI Membership', 'iei-membership'),
            __('IEI Membership', 'iei-membership'),
            $capability,
            $menuSlug,
            [$this, 'render_membership_dashboard'],
            'dashicons-id',
            56
        );

        $submenus = [
            'applications' => [
                'title' => __('Applications', 'iei-membership'),
                'capability' => RolesManager::CAP_MANAGE_APPLICATIONS,
                'callback' => [$this, 'render_applications_page'],
            ],
            'directors' => [
                'title' => __('Directors', 'iei-membership'),
                'capability' => RolesManager::CAP_MANAGE_DIRECTORS,
                'callback' => [$this, 'render_directors_page'],
            ],
            'members' => [
                'title' => __('Members', 'iei-membership'),
                'capability' => RolesManager::CAP_MANAGE_MEMBERS,
                'callback' => [$this, 'render_members_page'],
            ],
            'subscriptions' => [
                'title' => __('Subscriptions', 'iei-membership'),
                'capability' => RolesManager::CAP_MANAGE_SUBSCRIPTIONS,
                'callback' => [$this, 'render_subscriptions_page'],
            ],
            'payments' => [
                'title' => __('Payments', 'iei-membership'),
                'capability' => RolesManager::CAP_MANAGE_PAYMENTS,
                'callback' => [$this, 'render_payments_page'],
            ],
            'settings' => [
                'title' => __('Settings', 'iei-membership'),
                'capability' => RolesManager::CAP_MANAGE_SETTINGS,
                'callback' => $this->settingsPage ? [$this->settingsPage, 'render'] : [$this, 'render_placeholder_page'],
            ],
            'activity-log' => [
                'title' => __('Activity Log', 'iei-membership'),
                'capability' => RolesManager::CAP_VIEW_ACTIVITY_LOG,
                'callback' => [$this, 'render_activity_log_page'],
            ],
            'import-members' => [
                'title' => __('Import Members (CSV)', 'iei-membership'),
                'capability' => RolesManager::CAP_IMPORT_MEMBERS,
                'callback' => [$this, 'render_import_members_page'],
            ],
        ];

        foreach ($submenus as $slug => $menuConfig) {
            add_submenu_page(
                $menuSlug,
                $menuConfig['title'],
                $menuConfig['title'],
                $menuConfig['capability'],
                $menuSlug . '-' . $slug,
                $menuConfig['callback']
            );
        }
    }

    public function render_membership_dashboard(): void
    {
        $this->assert_capability(RolesManager::CAP_MANAGE_MEMBERSHIP);
        $this->render_scaffold_page(__('IEI Membership', 'iei-membership'));
    }

    public function render_placeholder_page(): void
    {
        $this->assert_capability(RolesManager::CAP_MANAGE_MEMBERSHIP);
        $this->render_scaffold_page(__('IEI Membership', 'iei-membership'));
    }

    public function render_applications_page(): void
    {
        $this->assert_capability(RolesManager::CAP_MANAGE_APPLICATIONS);

        if ($this->applicationsPage) {
            $this->applicationsPage->render();
            return;
        }

        $this->render_scaffold_page(__('Applications', 'iei-membership'));
    }

    public function render_directors_page(): void
    {
        $this->assert_capability(RolesManager::CAP_MANAGE_DIRECTORS);

        if ($this->directorsPage) {
            $this->directorsPage->render();
            return;
        }

        $this->render_scaffold_page(__('Directors', 'iei-membership'));
    }

    public function render_members_page(): void
    {
        $this->assert_capability(RolesManager::CAP_MANAGE_MEMBERS);

        if ($this->membersPage) {
            $this->membersPage->render();
            return;
        }

        $this->render_scaffold_page(__('Members', 'iei-membership'));
    }

    public function render_subscriptions_page(): void
    {
        $this->assert_capability(RolesManager::CAP_MANAGE_SUBSCRIPTIONS);
        $this->render_scaffold_page(__('Subscriptions', 'iei-membership'));
    }

    public function render_payments_page(): void
    {
        $this->assert_capability(RolesManager::CAP_MANAGE_PAYMENTS);

        if ($this->paymentsPage) {
            $this->paymentsPage->render();
            return;
        }

        $this->render_scaffold_page(__('Payments', 'iei-membership'));
    }

    public function render_activity_log_page(): void
    {
        $this->assert_capability(RolesManager::CAP_VIEW_ACTIVITY_LOG);
        $this->render_scaffold_page(__('Activity Log', 'iei-membership'));
    }

    public function render_import_members_page(): void
    {
        $this->assert_capability(RolesManager::CAP_IMPORT_MEMBERS);

        if ($this->importMembersPage) {
            $this->importMembersPage->render();
            return;
        }

        $this->render_scaffold_page(__('Import Members (CSV)', 'iei-membership'));
    }

    private function assert_capability(string $capability): void
    {
        if (! current_user_can($capability)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'iei-membership'));
        }
    }

    private function render_scaffold_page(string $title): void
    {
        echo '<div class="wrap"><h1>';
        echo esc_html($title);
        echo '</h1><p>';
        echo esc_html__('Scaffold page. Business logic is not implemented yet.', 'iei-membership');
        echo '</p></div>';
    }

    public function register_help_tabs(): void
    {
        if (! is_admin() || ! function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if (! $screen || ! isset($screen->id)) {
            return;
        }

        $screenId = (string) $screen->id;
        if (strpos($screenId, 'iei-membership') === false) {
            return;
        }

        $screen->add_help_tab([
            'id' => 'iei_membership_qa_checklist',
            'title' => __('QA Checklist', 'iei-membership'),
            'content' =>
                '<p><strong>' . esc_html__('Security & Workflow QA', 'iei-membership') . '</strong></p>'
                . '<ul>'
                . '<li>' . esc_html__('Verify all admin actions require capability checks and nonces (applications, directors, payments, import).', 'iei-membership') . '</li>'
                . '<li>' . esc_html__('Verify frontend submission actions require nonces and reject invalid requests (application form, director vote).', 'iei-membership') . '</li>'
                . '<li>' . esc_html__('Verify file streaming blocks unauthorized users and allows only admin/stuart/assigned director.', 'iei-membership') . '</li>'
                . '<li>' . esc_html__('Verify activity logs do not store storage paths, raw upload locations, or unnecessary PII values.', 'iei-membership') . '</li>'
                . '<li>' . esc_html__('Verify reminder/reset/payment/import actions create timeline/audit log events.', 'iei-membership') . '</li>'
                . '<li>' . esc_html__('Verify role transitions: pending_payment -> member on paid, member -> pending_payment on lapse.', 'iei-membership') . '</li>'
                . '</ul>',
        ]);
    }

    /**
     * Route users to role-appropriate frontend destinations after login.
     *
     * Priority order:
     * 1) Directors with voting capability -> director dashboard page.
     * 2) Membership users -> payment portal or member home, based on status.
     * 3) Anything else -> preserve the original WordPress redirect.
     */
    public function handle_login_redirect(string $redirectTo, string $requestedRedirectTo, $user): string
    {
        if (! $user instanceof \WP_User) {
            return $redirectTo;
        }

        if (in_array('iei_director', (array) $user->roles, true)) {
            if (method_exists($user, 'has_cap') && $user->has_cap(RolesManager::CAP_DIRECTOR_VOTE)) {
                return $this->director_dashboard_url();
            }
        }

        $membershipRedirect = $this->membership_login_redirect_url($user);
        if ($membershipRedirect !== '') {
            return $membershipRedirect;
        }

        return $redirectTo;
    }

    /**
     * Resolve membership-specific login destination from member and subscription state.
     */
    private function membership_login_redirect_url(\WP_User $user): string
    {
        $member = $this->get_member_by_wp_user_id((int) $user->ID);
        if (! $member) {
            return '';
        }

        $subscriptionStatus = $this->latest_subscription_status((int) $member['id']);
        $memberStatus = (string) ($member['status'] ?? '');
        $roles = (array) $user->roles;

        $isPendingPayment = in_array('iei_pending_payment', $roles, true)
            || $memberStatus === 'pending_payment'
            || $subscriptionStatus === 'pending_payment';

        if ($isPendingPayment) {
            return $this->member_payment_portal_url();
        }

        $hasCurrentMembership = in_array('iei_member', $roles, true)
            || $memberStatus === 'active'
            || in_array($subscriptionStatus, ['active', 'overdue'], true);

        if ($hasCurrentMembership) {
            return $this->member_home_url();
        }

        return '';
    }

    /**
     * Resolve director dashboard URL using configured page first, then shortcode discovery.
     */
    private function director_dashboard_url(): string
    {
        $settings = get_option(IEI_MEMBERSHIP_OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];
        $pageId = absint($settings['director_dashboard_page_id'] ?? 0);

        if ($pageId > 0) {
            $url = get_permalink($pageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        global $wpdb;

        $postsTable = $wpdb->posts;
        $shortcodePageId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID
                 FROM {$postsTable}
                 WHERE post_type = %s
                   AND post_status = %s
                   AND post_content LIKE %s
                 ORDER BY ID ASC
                 LIMIT 1",
                'page',
                'publish',
                '%[iei_director_dashboard%'
            )
        );

        if (! empty($shortcodePageId)) {
            $url = get_permalink((int) $shortcodePageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return home_url('/');
    }

    /**
     * Resolve payment portal URL using configured page first, then shortcode discovery.
     */
    private function member_payment_portal_url(): string
    {
        $settings = get_option(IEI_MEMBERSHIP_OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];
        $pageId = absint($settings['member_payment_portal_page_id'] ?? 0);

        if ($pageId > 0) {
            $url = get_permalink($pageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        $shortcodePageId = $this->first_published_page_with_shortcode('[iei_member_payment_portal');
        if ($shortcodePageId > 0) {
            $url = get_permalink($shortcodePageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return home_url('/');
    }

    /**
     * Resolve member-home URL from settings, with a stable public-path fallback.
     */
    private function member_home_url(): string
    {
        $settings = get_option(IEI_MEMBERSHIP_OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];
        $pageId = absint($settings['member_home_page_id'] ?? 0);

        if ($pageId > 0) {
            $url = get_permalink($pageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return home_url('/member-portal/');
    }

    /**
     * Fetch plugin member row for a WordPress user.
     */
    private function get_member_by_wp_user_id(int $wpUserId): ?array
    {
        if ($wpUserId <= 0) {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}iei_members WHERE wp_user_id = %d LIMIT 1", $wpUserId),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Return the latest known subscription status for redirect decisions.
     */
    private function latest_subscription_status(int $memberId): string
    {
        if ($memberId <= 0) {
            return '';
        }

        global $wpdb;

        $status = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status
                 FROM {$wpdb->prefix}iei_subscriptions
                 WHERE member_id = %d
                 ORDER BY membership_year DESC, id DESC
                 LIMIT 1",
                $memberId
            )
        );

        return is_string($status) ? sanitize_key($status) : '';
    }

    /**
     * Find the first published page containing a shortcode prefix.
     *
     * Prefix matching allows attributes in shortcodes, e.g. [shortcode foo="bar"].
     */
    private function first_published_page_with_shortcode(string $shortcodePrefix): int
    {
        if ($shortcodePrefix === '') {
            return 0;
        }

        global $wpdb;

        $postsTable = $wpdb->posts;
        $pageId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID
                 FROM {$postsTable}
                 WHERE post_type = %s
                   AND post_status = %s
                   AND post_content LIKE %s
                 ORDER BY ID ASC
                 LIMIT 1",
                'page',
                'publish',
                '%' . $wpdb->esc_like($shortcodePrefix) . '%'
            )
        );

        return $pageId ? (int) $pageId : 0;
    }
}
