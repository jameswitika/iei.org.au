<?php

namespace IEI\Membership\Controllers\Admin;

use IEI\Membership\Services\PaymentActivationService;
use IEI\Membership\Services\RolesManager;

class PaymentsPage
{
    private string $menuSlug = 'iei-membership-payments';
    private PaymentActivationService $paymentActivationService;

    public function __construct(PaymentActivationService $paymentActivationService)
    {
        $this->paymentActivationService = $paymentActivationService;
    }

    public function register_hooks(): void
    {
        add_action('admin_post_iei_membership_mark_subscription_paid', [$this, 'handle_mark_paid']);
    }

    public function render(): void
    {
        $this->assert_access();
        $rows = $this->get_due_subscriptions();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Payments', 'iei-membership') . '</h1>';
        $this->render_notice();

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Subscription', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Member', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Email', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Status', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Year', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Amount Due', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Action', 'iei-membership') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="7">' . esc_html__('No due subscriptions found.', 'iei-membership') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                $subscriptionId = (int) $row['subscription_id'];
                $amountDue = max(0.0, (float) $row['amount_due'] - (float) $row['amount_paid']);

                echo '<tr>';
                echo '<td>#' . esc_html((string) $subscriptionId) . '</td>';
                echo '<td>' . esc_html((string) ($row['display_name'] ?: 'User #' . $row['wp_user_id'])) . '</td>';
                echo '<td>' . esc_html((string) $row['user_email']) . '</td>';
                echo '<td>' . esc_html((string) $row['subscription_status']) . '</td>';
                echo '<td>' . esc_html((string) $row['membership_year']) . '</td>';
                echo '<td>AUD ' . esc_html(number_format($amountDue, 2)) . '</td>';
                echo '<td>';
                $this->render_mark_paid_form($subscriptionId);
                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function handle_mark_paid(): void
    {
        $this->assert_access();

        $subscriptionId = absint($_POST['subscription_id'] ?? 0);
        $reference = sanitize_text_field(wp_unslash((string) ($_POST['reference'] ?? '')));

        if ($subscriptionId <= 0) {
            $this->redirect_with_notice('mark_invalid');
        }

        check_admin_referer('iei_membership_mark_paid_' . $subscriptionId);

        try {
            $this->paymentActivationService->mark_subscription_paid($subscriptionId, get_current_user_id(), $reference);
            $this->redirect_with_notice('mark_paid_success');
        } catch (\Throwable $throwable) {
            error_log('[IEI Membership] Mark paid failed: ' . $throwable->getMessage());
            $this->redirect_with_notice('mark_paid_failed');
        }
    }

    private function get_due_subscriptions(): array
    {
        global $wpdb;

        $subscriptionsTable = $wpdb->prefix . 'iei_subscriptions';
        $membersTable = $wpdb->prefix . 'iei_members';
        $usersTable = $wpdb->users;

        $sql = "SELECT s.id AS subscription_id, s.status AS subscription_status, s.membership_year, s.amount_due, s.amount_paid,
                       m.id AS member_id, m.wp_user_id,
                       u.display_name, u.user_email
                FROM {$subscriptionsTable} s
                INNER JOIN {$membersTable} m ON m.id = s.member_id
                LEFT JOIN {$usersTable} u ON u.ID = m.wp_user_id
                WHERE s.status IN ('pending_payment', 'overdue', 'lapsed')
                ORDER BY s.due_date ASC, s.id ASC
                LIMIT 500";

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private function render_mark_paid_form(int $subscriptionId): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="iei_membership_mark_subscription_paid" />';
        echo '<input type="hidden" name="subscription_id" value="' . esc_attr((string) $subscriptionId) . '" />';
        echo '<input type="text" name="reference" placeholder="' . esc_attr__('Reference (optional)', 'iei-membership') . '" style="margin-bottom:6px; width:180px;" /> <br />';
        wp_nonce_field('iei_membership_mark_paid_' . $subscriptionId);
        echo '<button type="submit" class="button button-primary button-small">' . esc_html__('Mark Paid (Bank Transfer)', 'iei-membership') . '</button>';
        echo '</form>';
    }

    private function render_notice(): void
    {
        $updated = sanitize_key(wp_unslash((string) ($_GET['updated'] ?? '')));
        if ($updated === '') {
            return;
        }

        $messages = [
            'mark_paid_success' => __('Subscription marked paid and membership activated.', 'iei-membership'),
            'mark_paid_failed' => __('Could not mark the subscription as paid.', 'iei-membership'),
            'mark_invalid' => __('Invalid payment action request.', 'iei-membership'),
        ];

        if (! isset($messages[$updated])) {
            return;
        }

        $isError = in_array($updated, ['mark_paid_failed', 'mark_invalid'], true);
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
        if (
            ! current_user_can(RolesManager::CAP_MANAGE_PAYMENTS)
            && ! current_user_can(RolesManager::CAP_PREAPPROVE_APPLICATIONS)
            && ! current_user_can('manage_options')
        ) {
            wp_die(esc_html__('You do not have permission to manage payments.', 'iei-membership'), 403);
        }
    }
}
