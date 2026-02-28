<?php

namespace IEI\Membership\Controllers\Admin;

use IEI\Membership\Services\RolesManager;

/**
 * Admin management for director user assignment and enable/disable state.
 */
class DirectorsPage
{
    private string $menuSlug = 'iei-membership-directors';

    public function register_hooks(): void
    {
        add_action('admin_post_iei_membership_add_director', [$this, 'handle_add_director']);
        add_action('admin_post_iei_membership_toggle_director', [$this, 'handle_toggle_director']);
    }

    public function render(): void
    {
        $this->assert_access();

        $directors = get_users([
            'role' => 'iei_director',
            'orderby' => 'user_registered',
            'order' => 'DESC',
            'fields' => ['ID', 'user_login', 'display_name', 'user_email', 'user_registered'],
        ]);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Directors', 'iei-membership') . '</h1>';

        $this->render_notice();
        $this->render_add_form();

        echo '<hr />';
        echo '<h2>' . esc_html__('Director List', 'iei-membership') . '</h2>';
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Username', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Name', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Email', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Registered', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Disabled', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Actions', 'iei-membership') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($directors)) {
            echo '<tr><td colspan="7">' . esc_html__('No directors found.', 'iei-membership') . '</td></tr>';
        } else {
            foreach ($directors as $director) {
                $userId = (int) $director->ID;
                $disabled = RolesManager::is_director_disabled($userId);

                echo '<tr>';
                echo '<td>' . esc_html((string) $userId) . '</td>';
                echo '<td>' . esc_html((string) $director->user_login) . '</td>';
                echo '<td>' . esc_html((string) $director->display_name) . '</td>';
                echo '<td>' . esc_html((string) $director->user_email) . '</td>';
                echo '<td>' . esc_html((string) $director->user_registered) . '</td>';
                echo '<td>' . esc_html($disabled ? __('Yes', 'iei-membership') : __('No', 'iei-membership')) . '</td>';
                echo '<td>';
                $this->render_toggle_form($userId, $disabled);
                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function handle_add_director(): void
    {
        $this->assert_access();

        check_admin_referer('iei_membership_add_director');

        $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
        $displayName = sanitize_text_field(wp_unslash((string) ($_POST['display_name'] ?? '')));

        if (! is_email($email)) {
            $this->redirect_with_notice('add_invalid_email');
        }

        $existingUser = get_user_by('email', $email);
        $isNewUser = false;

        if ($existingUser) {
            $userId = (int) $existingUser->ID;
            $user = new \WP_User($userId);
            $user->set_role('iei_director');

            if ($displayName !== '') {
                wp_update_user([
                    'ID' => $userId,
                    'display_name' => $displayName,
                ]);
            }
        } else {
            $username = $email;
            $password = wp_generate_password(24, true, true);
            $userId = wp_create_user($username, $password, $email);

            if (is_wp_error($userId)) {
                $this->redirect_with_notice('add_failed');
            }

            $isNewUser = true;
            wp_update_user([
                'ID' => (int) $userId,
                'role' => 'iei_director',
                'display_name' => $displayName !== '' ? $displayName : $email,
            ]);
        }

        delete_user_meta((int) $userId, RolesManager::DIRECTOR_DISABLED_META_KEY);
        $this->send_password_set_email((int) $userId, $isNewUser);

        $this->redirect_with_notice($isNewUser ? 'add_created' : 'add_existing_promoted');
    }

    public function handle_toggle_director(): void
    {
        $this->assert_access();

        $userId = absint($_POST['user_id'] ?? 0);
        $toggle = sanitize_key(wp_unslash((string) ($_POST['toggle'] ?? '')));

        if ($userId <= 0 || ! in_array($toggle, ['disable', 'enable'], true)) {
            $this->redirect_with_notice('toggle_invalid');
        }

        check_admin_referer('iei_membership_toggle_director_' . $userId);

        $user = get_user_by('id', $userId);
        if (! $user || ! in_array('iei_director', (array) $user->roles, true)) {
            $this->redirect_with_notice('toggle_invalid');
        }

        if ($toggle === 'disable') {
            update_user_meta($userId, RolesManager::DIRECTOR_DISABLED_META_KEY, '1');
            $this->redirect_with_notice('director_disabled');
        }

        delete_user_meta($userId, RolesManager::DIRECTOR_DISABLED_META_KEY);
        $this->redirect_with_notice('director_enabled');
    }

    private function render_add_form(): void
    {
        echo '<h2>' . esc_html__('Add Director', 'iei-membership') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="iei_membership_add_director" />';
        wp_nonce_field('iei_membership_add_director');

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr>';
        echo '<th scope="row"><label for="iei_director_email">' . esc_html__('Email', 'iei-membership') . '</label></th>';
        echo '<td><input type="email" required class="regular-text" id="iei_director_email" name="email" /></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="iei_director_display_name">' . esc_html__('Display Name', 'iei-membership') . '</label></th>';
        echo '<td><input type="text" class="regular-text" id="iei_director_display_name" name="display_name" /></td>';
        echo '</tr>';
        echo '</tbody></table>';

        submit_button(__('Add Director', 'iei-membership'));
        echo '</form>';
    }

    private function render_toggle_form(int $userId, bool $disabled): void
    {
        $toggle = $disabled ? 'enable' : 'disable';
        $label = $disabled ? __('Enable', 'iei-membership') : __('Disable', 'iei-membership');

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="iei_membership_toggle_director" />';
        echo '<input type="hidden" name="user_id" value="' . esc_attr((string) $userId) . '" />';
        echo '<input type="hidden" name="toggle" value="' . esc_attr($toggle) . '" />';
        wp_nonce_field('iei_membership_toggle_director_' . $userId);
        echo '<button type="submit" class="button button-small">' . esc_html($label) . '</button>';
        echo '</form>';
    }

    private function send_password_set_email(int $userId, bool $isNewUser): void
    {
        $user = get_user_by('id', $userId);
        if (! $user instanceof \WP_User) {
            return;
        }

        if ($isNewUser && function_exists('wp_send_new_user_notifications')) {
            wp_send_new_user_notifications($userId, 'user');
            return;
        }

        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            return;
        }

        $resetUrl = network_site_url('wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode($user->user_login), 'login');
        $subject = __('Set your IEI Director password', 'iei-membership');
        $message = "You have been assigned as an IEI Director.\n\n";
        $message .= "Set your password using this link:\n";
        $message .= $resetUrl . "\n";

        wp_mail($user->user_email, $subject, $message);
    }

    private function render_notice(): void
    {
        $updated = sanitize_key(wp_unslash((string) ($_GET['updated'] ?? '')));
        if ($updated === '') {
            return;
        }

        $messages = [
            'add_created' => __('Director user created and password set email sent.', 'iei-membership'),
            'add_existing_promoted' => __('Existing user set as director and password set email sent.', 'iei-membership'),
            'add_invalid_email' => __('Please provide a valid email address.', 'iei-membership'),
            'add_failed' => __('Could not create director user.', 'iei-membership'),
            'director_disabled' => __('Director disabled from portal access.', 'iei-membership'),
            'director_enabled' => __('Director re-enabled for portal access.', 'iei-membership'),
            'toggle_invalid' => __('Invalid director toggle request.', 'iei-membership'),
        ];

        if (! isset($messages[$updated])) {
            return;
        }

        $isError = in_array($updated, ['add_invalid_email', 'add_failed', 'toggle_invalid'], true);
        $class = $isError ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($messages[$updated]) . '</p></div>';
    }

    private function redirect_with_notice(string $notice): void
    {
        $url = add_query_arg(['updated' => $notice], $this->list_url());
        wp_safe_redirect($url);
        exit;
    }

    private function list_url(): string
    {
        return admin_url('admin.php?page=' . $this->menuSlug);
    }

    private function assert_access(): void
    {
        if (! current_user_can(RolesManager::CAP_MANAGE_DIRECTORS) && ! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage directors.', 'iei-membership'), 403);
        }
    }
}
