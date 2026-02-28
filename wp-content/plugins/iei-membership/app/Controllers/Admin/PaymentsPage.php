<?php

namespace IEI\Membership\Controllers\Admin;

use IEI\Membership\Services\PaymentActivationService;
use IEI\Membership\Services\RolesManager;

/**
 * Admin payments queue for marking subscriptions as paid.
 */
class PaymentsPage
{
    private string $menuSlug = 'iei-membership-payments';
    private const VIEW_OUTSTANDING = 'outstanding';
    private const VIEW_COMPLETED = 'completed';
    private const VIEW_ALL = 'all';

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

        $view = $this->current_view();
        $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));
        $rows = $this->get_subscriptions($view, $search);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Payments', 'iei-membership') . '</h1>';
        $this->render_notice();
        $this->render_filters($view, $search);

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Subscription', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Member', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Email', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Status', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Year', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Amount Due', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Amount Paid', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Received At', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Reference', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Action', 'iei-membership') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="11">' . esc_html__('No payment records found for this view.', 'iei-membership') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                $subscriptionId = (int) $row['subscription_id'];
                $amountDue = max(0.0, (float) $row['amount_due'] - (float) $row['amount_paid']);
                $status = (string) ($row['subscription_status'] ?? '');
                $memberId = (int) ($row['member_id'] ?? 0);
                $memberLabel = (string) ($row['display_name'] ?: 'User #' . $row['wp_user_id']);
                $memberUrl = $memberId > 0
                    ? add_query_arg(
                        [
                            'page' => 'iei-membership-members',
                            'member_id' => $memberId,
                        ],
                        admin_url('admin.php')
                    )
                    : '';

                echo '<tr>';
                echo '<td>#' . esc_html((string) $subscriptionId) . '</td>';
                if ($memberUrl !== '') {
                    echo '<td><a href="' . esc_url($memberUrl) . '">' . esc_html($memberLabel) . '</a></td>';
                } else {
                    echo '<td>' . esc_html($memberLabel) . '</td>';
                }
                echo '<td>' . esc_html((string) $row['user_email']) . '</td>';
                echo '<td>' . $this->render_status_chip($status) . '</td>';
                echo '<td>' . esc_html((string) $row['membership_year']) . '</td>';
                echo '<td>AUD ' . esc_html(number_format($amountDue, 2)) . '</td>';
                echo '<td>AUD ' . esc_html(number_format((float) ($row['amount_paid'] ?? 0), 2)) . '</td>';
                echo '<td>' . esc_html((string) (($row['paid_at'] ?? '') ?: '-')) . '</td>';
                echo '<td>' . esc_html((string) (($row['last_payment_reference'] ?? '') ?: '-')) . '</td>';
                echo '<td>';
                if (in_array($status, ['pending_payment', 'overdue', 'lapsed'], true)) {
                    $this->render_mark_paid_form($subscriptionId);
                } else {
                    echo '-';
                }
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

    private function get_subscriptions(string $view, string $search): array
    {
        global $wpdb;

        $subscriptionsTable = $wpdb->prefix . 'iei_subscriptions';
        $membersTable = $wpdb->prefix . 'iei_members';
        $usersTable = $wpdb->users;
        $paymentsTable = $wpdb->prefix . 'iei_payments';

        $statuses = $this->statuses_for_view($view);

        $sql = "SELECT s.id AS subscription_id, s.status AS subscription_status, s.membership_year, s.amount_due, s.amount_paid,
                       s.paid_at,
                       m.id AS member_id, m.wp_user_id,
                       u.display_name, u.user_email,
                       p.reference AS last_payment_reference
                FROM {$subscriptionsTable} s
                INNER JOIN {$membersTable} m ON m.id = s.member_id
                LEFT JOIN {$usersTable} u ON u.ID = m.wp_user_id
                LEFT JOIN {$paymentsTable} p ON p.id = (
                    SELECT p2.id
                    FROM {$paymentsTable} p2
                    WHERE p2.subscription_id = s.id
                    ORDER BY p2.received_at DESC, p2.id DESC
                    LIMIT 1
                )
                WHERE s.status IN ('" . implode("','", array_map('esc_sql', $statuses)) . "')";

        $params = [];

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $sql .= ' AND (
                u.display_name LIKE %s
                OR u.user_email LIKE %s
                OR m.membership_number LIKE %s
                OR p.reference LIKE %s
                OR CAST(s.id AS CHAR) LIKE %s
            )';
            $params = [$like, $like, $like, $like, $like];
        }

        $sql .= ' ORDER BY s.due_date ASC, s.id ASC LIMIT 500';

        if (! empty($params)) {
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($sql, ARRAY_A);
        }

        return is_array($rows) ? $rows : [];
    }

    private function statuses_for_view(string $view): array
    {
        if ($view === self::VIEW_COMPLETED) {
            return ['active'];
        }

        if ($view === self::VIEW_ALL) {
            return ['pending_payment', 'overdue', 'lapsed', 'active'];
        }

        return ['pending_payment', 'overdue', 'lapsed'];
    }

    private function current_view(): string
    {
        $view = sanitize_key(wp_unslash((string) ($_GET['view'] ?? self::VIEW_OUTSTANDING)));
        if (! in_array($view, [self::VIEW_OUTSTANDING, self::VIEW_COMPLETED, self::VIEW_ALL], true)) {
            return self::VIEW_OUTSTANDING;
        }

        return $view;
    }

    private function render_filters(string $view, string $search): void
    {
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:12px 0 16px;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($this->menuSlug) . '" />';

        echo '<select name="view">';
        echo '<option value="' . esc_attr(self::VIEW_OUTSTANDING) . '" ' . selected($view, self::VIEW_OUTSTANDING, false) . '>' . esc_html__('Outstanding (Pending/Overdue/Lapsed)', 'iei-membership') . '</option>';
        echo '<option value="' . esc_attr(self::VIEW_COMPLETED) . '" ' . selected($view, self::VIEW_COMPLETED, false) . '>' . esc_html__('Completed', 'iei-membership') . '</option>';
        echo '<option value="' . esc_attr(self::VIEW_ALL) . '" ' . selected($view, self::VIEW_ALL, false) . '>' . esc_html__('All', 'iei-membership') . '</option>';
        echo '</select> ';

        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Search member, email, subscription, reference', 'iei-membership') . '" style="width:320px;" /> ';
        echo '<button type="submit" class="button">' . esc_html__('Filter', 'iei-membership') . '</button> ';

        if ($search !== '' || $view !== self::VIEW_OUTSTANDING) {
            echo '<a class="button button-secondary" href="' . esc_url($this->list_url()) . '">' . esc_html__('Reset', 'iei-membership') . '</a>';
        }

        echo '</form>';
    }

    private function render_status_chip(string $status): string
    {
        $status = sanitize_key($status);

        $styles = [
            'pending_payment' => [
                'label' => __('pending_payment', 'iei-membership'),
                'style' => 'display:inline-block;padding:4px 10px;border-radius:9999px;background:#ffedd5;color:#9a3412;font-weight:600;text-transform:capitalize;',
            ],
            'overdue' => [
                'label' => __('overdue', 'iei-membership'),
                'style' => 'display:inline-block;padding:4px 10px;border-radius:9999px;background:#fee2e2;color:#991b1b;font-weight:600;text-transform:capitalize;',
            ],
            'lapsed' => [
                'label' => __('lapsed', 'iei-membership'),
                'style' => 'display:inline-block;padding:4px 10px;border-radius:9999px;background:#fee2e2;color:#991b1b;font-weight:600;text-transform:capitalize;',
            ],
            'active' => [
                'label' => __('completed', 'iei-membership'),
                'style' => 'display:inline-block;padding:4px 10px;border-radius:9999px;background:#dcfce7;color:#166534;font-weight:600;text-transform:capitalize;',
            ],
        ];

        $meta = $styles[$status] ?? [
            'label' => $status !== '' ? $status : __('unknown', 'iei-membership'),
            'style' => 'display:inline-block;padding:4px 10px;border-radius:9999px;background:#e5e7eb;color:#374151;font-weight:600;text-transform:capitalize;',
        ];

        return '<span style="' . esc_attr($meta['style']) . '">' . esc_html((string) $meta['label']) . '</span>';
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
