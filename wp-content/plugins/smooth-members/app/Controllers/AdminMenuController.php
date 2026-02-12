<?php

namespace SmoothMembers\Controllers;

/**
 * Registers Smooth Members admin menus and page render callbacks.
 */
class AdminMenuController
{
    /**
     * Attach WordPress admin menu registration hook.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenus']);
    }

    /**
     * Register top-level menu and submenu pages.
     *
     * Top level:
     * - Page title: Smooth Members
     * - Menu label: Members
     * - Slug: smooth-members
     */
    public function registerMenus(): void
    {
        add_menu_page(
            'Smooth Members',
            'Members',
            'manage_options',
            'smooth-members',
            [$this, 'renderMembersPage'],
            'dashicons-groups',
            56
        );

        add_submenu_page(
            'smooth-members',
            'Members',
            'Members',
            'manage_options',
            'smooth-members',
            [$this, 'renderMembersPage']
        );

        add_submenu_page(
            'smooth-members',
            'Memberships',
            'Memberships',
            'manage_options',
            'smooth-members-memberships',
            [$this, 'renderMembershipsPage']
        );

        add_submenu_page(
            'smooth-members',
            'Subscriptions',
            'Subscriptions',
            'manage_options',
            'smooth-members-subscriptions',
            [$this, 'renderSubscriptionsPage']
        );

        add_submenu_page(
            'smooth-members',
            'Settings',
            'Settings',
            'manage_options',
            'smooth-members-settings',
            [$this, 'renderSettingsPage']
        );
    }

    /**
     * Render Members page.
     */
    public function renderMembersPage(): void
    {
        $this->renderView('members');
    }

    /**
     * Render Memberships page.
     */
    public function renderMembershipsPage(): void
    {
        $this->renderView('memberships');
    }

    /**
     * Render Subscriptions page.
     */
    public function renderSubscriptionsPage(): void
    {
        $this->renderView('subscriptions');
    }

    /**
     * Render Settings page.
     */
    public function renderSettingsPage(): void
    {
        $this->renderView('settings');
    }

    /**
     * Render an admin view template if present.
     *
     * @param string $view View filename without extension.
     */
    private function renderView(string $view): void
    {
        $viewPath = SMOOTH_MEMBERS_PATH . 'app/Views/Admin/' . $view . '.php';

        if (file_exists($viewPath)) {
            require $viewPath;
        }
    }
}
