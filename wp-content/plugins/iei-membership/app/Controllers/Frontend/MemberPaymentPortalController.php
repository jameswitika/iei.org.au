<?php

namespace IEI\Membership\Controllers\Frontend;

use IEI\Membership\Services\RolesManager;

class MemberPaymentPortalController
{
    public function register_hooks(): void
    {
        add_shortcode('iei_member_payment_portal', [$this, 'render_shortcode']);
    }

    public function render_shortcode(): string
    {
        if (! is_user_logged_in()) {
            $loginUrl = wp_login_url($this->current_url());
            return '<p>' . esc_html__('Please log in to access your payment portal.', 'iei-membership') . ' <a href="' . esc_url($loginUrl) . '">' . esc_html__('Log in', 'iei-membership') . '</a></p>';
        }

        $userId = get_current_user_id();
        $member = $this->get_member_by_user_id($userId);

        if (! $this->can_access_portal($member)) {
            return '<p>' . esc_html__('You do not have access to this payment portal.', 'iei-membership') . '</p>';
        }

        $subscription = $member ? $this->get_next_due_subscription((int) $member['id']) : null;
        $settings = $this->settings();

        ob_start();

        echo '<h2>' . esc_html__('Membership Payment Portal', 'iei-membership') . '</h2>';

        if (! $subscription) {
            echo '<p>' . esc_html__('No pending payment was found for your account.', 'iei-membership') . '</p>';
        } else {
            $amountDue = max(0.0, (float) $subscription['amount_due'] - (float) $subscription['amount_paid']);

            echo '<table class="form-table" role="presentation"><tbody>';
            echo '<tr><th>' . esc_html__('Subscription Year', 'iei-membership') . '</th><td>' . esc_html((string) $subscription['membership_year']) . '</td></tr>';
            echo '<tr><th>' . esc_html__('Status', 'iei-membership') . '</th><td>' . esc_html((string) $subscription['status']) . '</td></tr>';
            echo '<tr><th>' . esc_html__('Due Date', 'iei-membership') . '</th><td>' . esc_html((string) $subscription['due_date']) . '</td></tr>';
            echo '<tr><th>' . esc_html__('Amount Due', 'iei-membership') . '</th><td><strong>AUD ' . esc_html(number_format($amountDue, 2)) . '</strong></td></tr>';
            echo '</tbody></table>';
        }

        echo '<h3>' . esc_html__('Bank Transfer', 'iei-membership') . '</h3>';
        if (! empty($settings['bank_transfer_instructions'])) {
            echo '<p>' . nl2br(esc_html((string) $settings['bank_transfer_instructions'])) . '</p>';
        } else {
            echo '<p>' . esc_html__('Bank transfer instructions will be provided by the administrator.', 'iei-membership') . '</p>';
        }

        echo '<h3>' . esc_html__('Online Gateway (Placeholder)', 'iei-membership') . '</h3>';
        echo '<p>' . esc_html__('Stripe/PayPal integration placeholder. Online payment is not enabled in this version.', 'iei-membership') . '</p>';

        return (string) ob_get_clean();
    }

    private function can_access_portal(?array $member): bool
    {
        if (current_user_can(RolesManager::CAP_ACCESS_PAYMENT_PORTAL)) {
            return true;
        }

        if (! $member) {
            return false;
        }

        return in_array((string) ($member['status'] ?? ''), ['pending_payment', 'lapsed'], true);
    }

    private function get_member_by_user_id(int $userId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}iei_members WHERE wp_user_id = %d LIMIT 1", $userId),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function get_next_due_subscription(int $memberId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$wpdb->prefix}iei_subscriptions
                 WHERE member_id = %d
                   AND status IN ('pending_payment', 'overdue', 'lapsed')
                 ORDER BY due_date ASC, id ASC
                 LIMIT 1",
                $memberId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function settings(): array
    {
        $settings = get_option(IEI_MEMBERSHIP_OPTION_KEY, []);
        if (! is_array($settings)) {
            $settings = [];
        }

        return array_merge(iei_membership_default_settings(), $settings);
    }

    private function current_url(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';

        return esc_url_raw($scheme . $host . $requestUri);
    }
}
