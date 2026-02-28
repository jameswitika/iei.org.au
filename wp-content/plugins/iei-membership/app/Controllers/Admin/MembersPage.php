<?php

namespace IEI\Membership\Controllers\Admin;

use IEI\Membership\Services\RolesManager;

/**
 * Admin members UI for searchable listing and member detail inspection.
 */
class MembersPage
{
    private string $menuSlug = 'iei-membership-members';

    public function register_hooks(): void
    {
    }

    public function render(): void
    {
        $this->assert_access();

        $memberId = absint($_GET['member_id'] ?? 0);
        if ($memberId > 0) {
            $this->render_detail($memberId);
            return;
        }

        $this->render_list();
    }

    /**
     * Render searchable members list with latest subscription snapshot columns.
     */
    private function render_list(): void
    {
        $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));
        $status = sanitize_key(wp_unslash((string) ($_GET['status'] ?? '')));
        $rows = $this->query_members($search, $status);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Members', 'iei-membership') . '</h1>';
        echo '<p>' . esc_html__('Search and review member records, including current subscription status.', 'iei-membership') . '</p>';

        $this->render_filters($search, $status);

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Member #', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Name', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Email', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Type', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Member Status', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Current Subscription', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Year', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Actions', 'iei-membership') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="8">' . esc_html__('No members found.', 'iei-membership') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                $memberId = (int) $row['id'];
                $detailUrl = add_query_arg(['member_id' => $memberId], $this->list_url());
                $fullName = trim((string) ($row['display_name'] ?? ''));
                if ($fullName === '') {
                    $fullName = trim((string) ($row['applicant_first_name'] ?? '') . ' ' . (string) ($row['applicant_last_name'] ?? ''));
                }

                echo '<tr>';
                echo '<td>' . esc_html((string) ($row['membership_number'] ?: '-')) . '</td>';
                echo '<td>' . esc_html($fullName !== '' ? $fullName : '-') . '</td>';
                echo '<td>' . esc_html((string) ($row['user_email'] ?: $row['applicant_email'] ?: '-')) . '</td>';
                echo '<td>' . esc_html((string) ($row['membership_type'] ?: '-')) . '</td>';
                echo '<td>' . esc_html((string) ($row['member_status'] ?: '-')) . '</td>';
                echo '<td>' . esc_html((string) ($row['subscription_status'] ?: '-')) . '</td>';
                echo '<td>' . esc_html((string) ($row['membership_year'] ?: '-')) . '</td>';
                echo '<td><a class="button button-small" href="' . esc_url($detailUrl) . '">' . esc_html__('View', 'iei-membership') . '</a></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function render_filters(string $search, string $status): void
    {
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:12px 0 16px;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($this->menuSlug) . '" />';
        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Search name, email, or membership #', 'iei-membership') . '" style="width:320px;" /> ';
        echo '<select name="status">';
        echo '<option value="">' . esc_html__('All statuses', 'iei-membership') . '</option>';
        foreach (['pending_payment', 'active', 'lapsed'] as $value) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($status, $value, false) . '>' . esc_html($value) . '</option>';
        }
        echo '</select> ';
        echo '<button type="submit" class="button">' . esc_html__('Filter', 'iei-membership') . '</button> ';

        if ($search !== '' || $status !== '') {
            echo '<a class="button button-secondary" href="' . esc_url($this->list_url()) . '">' . esc_html__('Reset', 'iei-membership') . '</a>';
        }

        echo '</form>';
    }

    /**
     * Render profile/subscription/activity detail for a single member.
     */
    private function render_detail(int $memberId): void
    {
        $row = $this->get_member($memberId);
        if (! $row) {
            wp_die(esc_html__('Member not found.', 'iei-membership'), 404);
        }

        $latestSubscription = $this->latest_subscription($memberId);
        $payments = $this->payment_history($memberId);
        $activities = $this->member_activities($memberId, isset($row['application_id']) ? (int) $row['application_id'] : 0);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Member Details', 'iei-membership') . '</h1>';
        echo '<p><a href="' . esc_url($this->list_url()) . '">&larr; ' . esc_html__('Back to Members', 'iei-membership') . '</a></p>';

        echo '<h2>' . esc_html__('Member Profile', 'iei-membership') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->detail_row(__('Member ID', 'iei-membership'), (string) ($row['id'] ?? '-'));
        $this->detail_row(__('Membership Number', 'iei-membership'), (string) ($row['membership_number'] ?: '-'));
        $this->detail_row(__('Name', 'iei-membership'), (string) ($row['display_name'] ?: trim((string) ($row['applicant_first_name'] ?? '') . ' ' . (string) ($row['applicant_last_name'] ?? '')) ?: '-'));
        $this->detail_row(__('Email', 'iei-membership'), (string) ($row['user_email'] ?: $row['applicant_email'] ?: '-'));
        $this->detail_row(__('Membership Type', 'iei-membership'), (string) ($row['membership_type'] ?: '-'));
        $this->detail_row(__('Member Status', 'iei-membership'), (string) ($row['member_status'] ?: '-'));
        $this->detail_row(__('Approved At', 'iei-membership'), (string) ($row['approved_at'] ?: '-'));
        $this->detail_row(__('Activated At', 'iei-membership'), (string) ($row['activated_at'] ?: '-'));
        $this->detail_row(__('Lapsed At', 'iei-membership'), (string) ($row['lapsed_at'] ?: '-'));
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Current Subscription', 'iei-membership') . '</h2>';
        if (! $latestSubscription) {
            echo '<p>' . esc_html__('No subscription record found for this member.', 'iei-membership') . '</p>';
        } else {
            echo '<table class="form-table" role="presentation"><tbody>';
            $this->detail_row(__('Subscription ID', 'iei-membership'), (string) ($latestSubscription['id'] ?? '-'));
            $this->detail_row(__('Membership Year', 'iei-membership'), (string) ($latestSubscription['membership_year'] ?? '-'));
            $this->detail_row(__('Status', 'iei-membership'), (string) ($latestSubscription['status'] ?? '-'));
            $this->detail_row(__('Amount Due', 'iei-membership'), 'AUD ' . number_format((float) ($latestSubscription['amount_due'] ?? 0), 2));
            $this->detail_row(__('Amount Paid', 'iei-membership'), 'AUD ' . number_format((float) ($latestSubscription['amount_paid'] ?? 0), 2));
            $this->detail_row(__('Due Date', 'iei-membership'), (string) ($latestSubscription['due_date'] ?? '-'));
            $this->detail_row(__('Paid At', 'iei-membership'), (string) ($latestSubscription['paid_at'] ?? '-'));
            $this->detail_row(__('Grace Until', 'iei-membership'), (string) ($latestSubscription['grace_until'] ?? '-'));
            echo '</tbody></table>';
        }

        echo '<h2>' . esc_html__('Payment History', 'iei-membership') . '</h2>';
        if (empty($payments)) {
            echo '<p>' . esc_html__('No payment records found for this member.', 'iei-membership') . '</p>';
        } else {
            echo '<table class="widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Payment ID', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Subscription', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Year', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Amount', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Method', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Status', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Reference', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Received At', 'iei-membership') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($payments as $payment) {
                $paymentId = (int) ($payment['id'] ?? 0);
                $subscriptionId = (int) ($payment['subscription_id'] ?? 0);
                $membershipYear = (string) (($payment['membership_year'] ?? '') ?: '-');
                $amount = (float) ($payment['amount'] ?? 0);
                $currency = (string) (($payment['currency'] ?? '') ?: 'AUD');
                $method = (string) (($payment['payment_method'] ?? '') ?: '-');
                $status = (string) (($payment['status'] ?? '') ?: 'pending');
                $reference = (string) (($payment['reference'] ?? '') ?: '-');
                $receivedAt = (string) (($payment['received_at'] ?? '') ?: '-');

                echo '<tr>';
                echo '<td>#' . esc_html((string) $paymentId) . '</td>';
                echo '<td>' . esc_html($subscriptionId > 0 ? ('#' . $subscriptionId) : '-') . '</td>';
                echo '<td>' . esc_html($membershipYear) . '</td>';
                echo '<td>' . esc_html($currency) . ' ' . esc_html(number_format($amount, 2)) . '</td>';
                echo '<td>' . esc_html($method) . '</td>';
                echo '<td>' . $this->render_payment_status_chip($status) . '</td>';
                echo '<td>' . esc_html($reference) . '</td>';
                echo '<td>' . esc_html($receivedAt) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '<h2>' . esc_html__('Recent Activity', 'iei-membership') . '</h2>';
        if (empty($activities)) {
            echo '<p>' . esc_html__('No activity found for this member yet.', 'iei-membership') . '</p>';
        } else {
            echo '<table class="widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('When', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Event', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Actor', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Context', 'iei-membership') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($activities as $activity) {
                $context = $this->render_context((string) ($activity['event_context'] ?? ''));
                echo '<tr>';
                echo '<td>' . esc_html((string) ($activity['created_at'] ?? '-')) . '</td>';
                echo '<td>' . esc_html((string) ($activity['event_type'] ?? '-')) . '</td>';
                echo '<td>' . esc_html((string) ($activity['actor_name'] ?: '-')) . '</td>';
                echo '<td><code style="white-space:pre-wrap; word-break:break-word;">' . esc_html($context) . '</code></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }

    private function detail_row(string $label, string $value): void
    {
        echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    private function query_members(string $search, string $status): array
    {
        global $wpdb;

        $membersTable = $wpdb->prefix . 'iei_members';
        $usersTable = $wpdb->users;
        $applicationsTable = $wpdb->prefix . 'iei_applications';
        $subscriptionsTable = $wpdb->prefix . 'iei_subscriptions';

        $sql = "SELECT m.id, m.membership_number, m.membership_type, m.status AS member_status, m.application_id,
                       m.approved_at, m.activated_at, m.lapsed_at,
                       u.display_name, u.user_email,
                       a.applicant_first_name, a.applicant_last_name, a.applicant_email,
                       s.status AS subscription_status, s.membership_year
                FROM {$membersTable} m
                LEFT JOIN {$usersTable} u ON u.ID = m.wp_user_id
                LEFT JOIN {$applicationsTable} a ON a.id = m.application_id
                LEFT JOIN {$subscriptionsTable} s ON s.id = (
                    SELECT s2.id
                    FROM {$subscriptionsTable} s2
                    WHERE s2.member_id = m.id
                    ORDER BY s2.membership_year DESC, s2.id DESC
                    LIMIT 1
                )
                WHERE 1=1";

        $params = [];

        if ($status !== '' && in_array($status, ['pending_payment', 'active', 'lapsed'], true)) {
            $sql .= ' AND m.status = %s';
            $params[] = $status;
        }

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $sql .= ' AND (
                m.membership_number LIKE %s
                OR u.display_name LIKE %s
                OR u.user_email LIKE %s
                OR a.applicant_first_name LIKE %s
                OR a.applicant_last_name LIKE %s
                OR a.applicant_email LIKE %s
            )';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= ' ORDER BY m.updated_at DESC, m.id DESC LIMIT 500';

        if (! empty($params)) {
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($sql, ARRAY_A);
        }

        return is_array($rows) ? $rows : [];
    }

    private function get_member(int $memberId): ?array
    {
        global $wpdb;

        $membersTable = $wpdb->prefix . 'iei_members';
        $usersTable = $wpdb->users;
        $applicationsTable = $wpdb->prefix . 'iei_applications';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT m.id, m.wp_user_id, m.application_id, m.membership_number, m.membership_type,
                        m.status AS member_status, m.approved_at, m.activated_at, m.lapsed_at,
                        u.display_name, u.user_email,
                        a.applicant_first_name, a.applicant_last_name, a.applicant_email
                 FROM {$membersTable} m
                 LEFT JOIN {$usersTable} u ON u.ID = m.wp_user_id
                 LEFT JOIN {$applicationsTable} a ON a.id = m.application_id
                 WHERE m.id = %d
                 LIMIT 1",
                $memberId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function latest_subscription(int $memberId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$wpdb->prefix}iei_subscriptions
                 WHERE member_id = %d
                 ORDER BY membership_year DESC, id DESC
                 LIMIT 1",
                $memberId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function payment_history(int $memberId): array
    {
        global $wpdb;

        $paymentsTable = $wpdb->prefix . 'iei_payments';
        $subscriptionsTable = $wpdb->prefix . 'iei_subscriptions';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.subscription_id, p.amount, p.currency, p.payment_method, p.status,
                        p.reference, p.received_at, p.created_at,
                        s.membership_year
                 FROM {$paymentsTable} p
                 LEFT JOIN {$subscriptionsTable} s ON s.id = p.subscription_id
                 WHERE p.member_id = %d
                 ORDER BY COALESCE(p.received_at, p.created_at) DESC, p.id DESC
                 LIMIT 200",
                $memberId
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    private function render_payment_status_chip(string $status): string
    {
        $status = sanitize_key($status);

        $styles = [
            'paid' => [
                'label' => __('paid', 'iei-membership'),
                'style' => 'display:inline-block;padding:4px 10px;border-radius:9999px;background:#dcfce7;color:#166534;font-weight:600;text-transform:capitalize;',
            ],
            'pending' => [
                'label' => __('pending', 'iei-membership'),
                'style' => 'display:inline-block;padding:4px 10px;border-radius:9999px;background:#ffedd5;color:#9a3412;font-weight:600;text-transform:capitalize;',
            ],
            'failed' => [
                'label' => __('failed', 'iei-membership'),
                'style' => 'display:inline-block;padding:4px 10px;border-radius:9999px;background:#fee2e2;color:#991b1b;font-weight:600;text-transform:capitalize;',
            ],
        ];

        $meta = $styles[$status] ?? [
            'label' => $status !== '' ? $status : __('unknown', 'iei-membership'),
            'style' => 'display:inline-block;padding:4px 10px;border-radius:9999px;background:#e5e7eb;color:#374151;font-weight:600;text-transform:capitalize;',
        ];

        return '<span style="' . esc_attr($meta['style']) . '">' . esc_html((string) $meta['label']) . '</span>';
    }

    private function member_activities(int $memberId, int $applicationId): array
    {
        global $wpdb;

        $activityTable = $wpdb->prefix . 'iei_activity_log';
        $usersTable = $wpdb->users;

        if ($applicationId > 0) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT l.created_at, l.event_type, l.event_context, l.actor_user_id,
                            u.display_name AS actor_name
                     FROM {$activityTable} l
                     LEFT JOIN {$usersTable} u ON u.ID = l.actor_user_id
                     WHERE l.member_id = %d OR l.application_id = %d
                     ORDER BY l.created_at DESC, l.id DESC
                     LIMIT 50",
                    $memberId,
                    $applicationId
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT l.created_at, l.event_type, l.event_context, l.actor_user_id,
                            u.display_name AS actor_name
                     FROM {$activityTable} l
                     LEFT JOIN {$usersTable} u ON u.ID = l.actor_user_id
                     WHERE l.member_id = %d
                     ORDER BY l.created_at DESC, l.id DESC
                     LIMIT 50",
                    $memberId
                ),
                ARRAY_A
            );
        }

        return is_array($rows) ? $rows : [];
    }

    private function render_context(string $context): string
    {
        $context = trim($context);
        if ($context === '') {
            return '-';
        }

        $decoded = json_decode($context, true);
        if (is_array($decoded)) {
            $encoded = wp_json_encode($decoded, JSON_UNESCAPED_SLASHES);
            return is_string($encoded) && $encoded !== '' ? $encoded : $context;
        }

        return $context;
    }

    private function list_url(): string
    {
        return admin_url('admin.php?page=' . $this->menuSlug);
    }

    private function assert_access(): void
    {
        if (
            ! current_user_can(RolesManager::CAP_MANAGE_MEMBERS)
            && ! current_user_can(RolesManager::CAP_PREAPPROVE_APPLICATIONS)
            && ! current_user_can('manage_options')
        ) {
            wp_die(esc_html__('You do not have permission to view members.', 'iei-membership'), 403);
        }
    }
}
