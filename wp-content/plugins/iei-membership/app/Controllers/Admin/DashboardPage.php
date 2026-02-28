<?php

namespace IEI\Membership\Controllers\Admin;

use IEI\Membership\Services\RolesManager;

class DashboardPage
{
    public function render(): void
    {
        if (! current_user_can(RolesManager::CAP_MANAGE_MEMBERSHIP)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'iei-membership'));
        }

        $tiles = $this->tile_counts();
        $attention = $this->needs_attention();
        $activity = $this->recent_activity();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('IEI Membership Dashboard', 'iei-membership') . '</h1>';

        echo '<div style="display:grid;grid-template-columns:repeat(4,minmax(180px,1fr));gap:12px;max-width:1100px;">';
        $this->tile(__('Pending Pre-Approval', 'iei-membership'), (int) $tiles['pending_preapproval'], admin_url('admin.php?page=iei-membership-applications&status=pending_preapproval'));
        $this->tile(__('Pending Board Approval', 'iei-membership'), (int) $tiles['pending_board_approval'], admin_url('admin.php?page=iei-membership-applications&status=pending_board_approval'));
        $this->tile(__('Payment Pending', 'iei-membership'), (int) $tiles['payment_pending'], admin_url('admin.php?page=iei-membership-payments&view=outstanding'));
        $this->tile(__('Overdue (within grace)', 'iei-membership'), (int) $tiles['overdue_within_grace'], admin_url('admin.php?page=iei-membership-payments&view=outstanding'));
        echo '</div>';

        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;max-width:1200px;margin-top:18px;align-items:start;">';

        echo '<div>';
        echo '<h2>' . esc_html__('Needs attention (top 10)', 'iei-membership') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Type', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Item', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Detail', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Age', 'iei-membership') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($attention)) {
            echo '<tr><td colspan="4">' . esc_html__('No items currently require attention.', 'iei-membership') . '</td></tr>';
        } else {
            foreach ($attention as $row) {
                echo '<tr>';
                echo '<td>' . esc_html((string) $row['type']) . '</td>';
                echo '<td><a href="' . esc_url((string) $row['url']) . '">' . esc_html((string) $row['label']) . '</a></td>';
                echo '<td>' . esc_html((string) $row['detail']) . '</td>';
                echo '<td>' . esc_html((string) $row['age']) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';

        echo '<div>';
        echo '<h2>' . esc_html__('Recent activity (last 10)', 'iei-membership') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('When', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Event', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Application', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Member', 'iei-membership') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($activity)) {
            echo '<tr><td colspan="4">' . esc_html__('No recent activity found.', 'iei-membership') . '</td></tr>';
        } else {
            foreach ($activity as $row) {
                $applicationId = (int) ($row['application_id'] ?? 0);
                $memberId = (int) ($row['member_id'] ?? 0);
                $appText = $applicationId > 0 ? '#' . $applicationId : '-';
                $memberText = $memberId > 0 ? '#' . $memberId : '-';

                echo '<tr>';
                echo '<td>' . esc_html((string) ($row['created_at'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['event_type'] ?? '')) . '</td>';
                echo '<td>' . esc_html($appText) . '</td>';
                echo '<td>' . esc_html($memberText) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    private function tile(string $label, int $count, string $url): void
    {
        echo '<a href="' . esc_url($url) . '" style="text-decoration:none;color:inherit;">';
        echo '<div style="border:1px solid #dcdcde;border-radius:8px;padding:14px;background:#fff;min-height:90px;">';
        echo '<div style="font-size:13px;color:#50575e;">' . esc_html($label) . '</div>';
        echo '<div style="font-size:28px;font-weight:600;line-height:1.2;margin-top:6px;">' . esc_html((string) $count) . '</div>';
        echo '</div>';
        echo '</a>';
    }

    private function tile_counts(): array
    {
        global $wpdb;

        $applicationsTable = $wpdb->prefix . 'iei_applications';
        $subscriptionsTable = $wpdb->prefix . 'iei_subscriptions';
        $membersTable = $wpdb->prefix . 'iei_members';
        $today = current_time('Y-m-d');
        $grace = $this->grace_period_days();

        $pendingPreapproval = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$applicationsTable} WHERE status = %s",
            'pending_preapproval'
        ));

        $pendingBoard = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$applicationsTable} WHERE status = %s",
            'pending_board_approval'
        ));

        $paymentPending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$membersTable} WHERE status = %s",
            'pending_payment'
        ));

        $overdueWithinGrace = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$subscriptionsTable}
             WHERE COALESCE(amount_paid, 0) < COALESCE(amount_due, 0)
               AND due_date < %s
               AND DATE_ADD(due_date, INTERVAL %d DAY) >= %s",
            $today,
            $grace,
            $today
        ));

        return [
            'pending_preapproval' => $pendingPreapproval,
            'pending_board_approval' => $pendingBoard,
            'payment_pending' => $paymentPending,
            'overdue_within_grace' => $overdueWithinGrace,
        ];
    }

    private function needs_attention(): array
    {
        global $wpdb;

        $applicationsTable = $wpdb->prefix . 'iei_applications';
        $votesTable = $wpdb->prefix . 'iei_application_votes';
        $subscriptionsTable = $wpdb->prefix . 'iei_subscriptions';
        $membersTable = $wpdb->prefix . 'iei_members';
        $usersTable = $wpdb->users;
        $today = current_time('Y-m-d');
        $grace = $this->grace_period_days();

        $pendingPreapproval = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, applicant_first_name, applicant_last_name, submitted_at,
                        DATEDIFF(%s, DATE(submitted_at)) AS age_days
                 FROM {$applicationsTable}
                 WHERE status = %s
                 ORDER BY submitted_at ASC
                 LIMIT 4",
                $today,
                'pending_preapproval'
            ),
            ARRAY_A
        );

        $pendingBoard = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.id, a.applicant_first_name, a.applicant_last_name, a.submitted_at,
                        DATEDIFF(%s, DATE(a.submitted_at)) AS age_days,
                        SUM(CASE WHEN v.vote = 'unanswered' THEN 1 ELSE 0 END) AS unanswered_count
                 FROM {$applicationsTable} a
                 LEFT JOIN {$votesTable} v ON v.application_id = a.id
                 WHERE a.status = %s
                 GROUP BY a.id
                 ORDER BY unanswered_count DESC, a.submitted_at ASC
                 LIMIT 4",
                $today,
                'pending_board_approval'
            ),
            ARRAY_A
        );

        $overdueMembers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.id AS subscription_id, s.member_id, s.due_date,
                        m.wp_user_id,
                        u.display_name,
                        DATEDIFF(DATE_ADD(s.due_date, INTERVAL %d DAY), %s) AS grace_days_left,
                        DATEDIFF(%s, s.due_date) AS age_days
                 FROM {$subscriptionsTable} s
                 INNER JOIN {$membersTable} m ON m.id = s.member_id
                 LEFT JOIN {$usersTable} u ON u.ID = m.wp_user_id
                 WHERE COALESCE(s.amount_paid, 0) < COALESCE(s.amount_due, 0)
                   AND s.due_date < %s
                   AND DATE_ADD(s.due_date, INTERVAL %d DAY) >= %s
                 ORDER BY grace_days_left ASC, s.due_date ASC
                 LIMIT 4",
                $grace,
                $today,
                $today,
                $today,
                $grace,
                $today
            ),
            ARRAY_A
        );

        $rows = [];

        if (is_array($pendingPreapproval)) {
            foreach ($pendingPreapproval as $item) {
                $name = trim((string) ($item['applicant_first_name'] ?? '') . ' ' . (string) ($item['applicant_last_name'] ?? ''));
                $rows[] = [
                    'priority' => 1,
                    'type' => __('Pre-Approval', 'iei-membership'),
                    'label' => $name !== '' ? $name : ('#' . (int) $item['id']),
                    'detail' => __('Oldest pending pre-approval', 'iei-membership'),
                    'age' => max(0, (int) ($item['age_days'] ?? 0)) . 'd',
                    'url' => admin_url('admin.php?page=iei-membership-applications&application_id=' . (int) $item['id']),
                ];
            }
        }

        if (is_array($pendingBoard)) {
            foreach ($pendingBoard as $item) {
                $name = trim((string) ($item['applicant_first_name'] ?? '') . ' ' . (string) ($item['applicant_last_name'] ?? ''));
                $rows[] = [
                    'priority' => 2,
                    'type' => __('Board', 'iei-membership'),
                    'label' => $name !== '' ? $name : ('#' . (int) $item['id']),
                    'detail' => sprintf(__('Unanswered votes: %d', 'iei-membership'), (int) ($item['unanswered_count'] ?? 0)),
                    'age' => max(0, (int) ($item['age_days'] ?? 0)) . 'd',
                    'url' => admin_url('admin.php?page=iei-membership-applications&application_id=' . (int) $item['id']),
                ];
            }
        }

        if (is_array($overdueMembers)) {
            foreach ($overdueMembers as $item) {
                $display = trim((string) ($item['display_name'] ?? ''));
                if ($display === '') {
                    $display = 'Member #' . (int) ($item['member_id'] ?? 0);
                }

                $daysLeft = (int) ($item['grace_days_left'] ?? 0);
                $rows[] = [
                    'priority' => 3,
                    'type' => __('Overdue', 'iei-membership'),
                    'label' => $display,
                    'detail' => sprintf(__('Grace days left: %d', 'iei-membership'), $daysLeft),
                    'age' => max(0, (int) ($item['age_days'] ?? 0)) . 'd',
                    'url' => admin_url('admin.php?page=iei-membership-payments&view=outstanding&s=' . rawurlencode((string) ((int) ($item['subscription_id'] ?? 0)))),
                ];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            if ((int) $a['priority'] !== (int) $b['priority']) {
                return (int) $a['priority'] <=> (int) $b['priority'];
            }

            $aAge = (int) preg_replace('/[^0-9]/', '', (string) ($a['age'] ?? '0'));
            $bAge = (int) preg_replace('/[^0-9]/', '', (string) ($b['age'] ?? '0'));
            return $bAge <=> $aAge;
        });

        return array_slice($rows, 0, 10);
    }

    private function recent_activity(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'iei_activity_log';
        $rows = $wpdb->get_results(
            "SELECT created_at, event_type, application_id, member_id
             FROM {$table}
             ORDER BY created_at DESC, id DESC
             LIMIT 10",
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    private function grace_period_days(): int
    {
        $settings = get_option(IEI_MEMBERSHIP_OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];

        return max(0, absint($settings['grace_period_days'] ?? 30));
    }
}
