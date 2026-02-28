<?php

namespace IEI\Membership;

use IEI\Membership\Controllers\Admin\SettingsPage;
use IEI\Membership\Controllers\Admin\ApplicationsPage;
use IEI\Membership\Controllers\Admin\DirectorsPage;
use IEI\Membership\Controllers\Admin\ImportMembersPage;
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

class Bootstrap
{
    private ?SettingsPage $settingsPage = null;
    private ?FileController $fileController = null;
    private ?ApplicationShortcodeController $applicationShortcodeController = null;
    private ?DirectorDashboardController $directorDashboardController = null;
    private ?MemberPaymentPortalController $memberPaymentPortalController = null;
    private ?ApplicationsPage $applicationsPage = null;
    private ?DirectorsPage $directorsPage = null;
    private ?PaymentsPage $paymentsPage = null;
    private ?ImportMembersPage $importMembersPage = null;
    private ?DailyMaintenanceService $dailyMaintenanceService = null;

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
}
