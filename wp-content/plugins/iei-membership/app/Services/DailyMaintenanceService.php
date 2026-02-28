<?php

namespace IEI\Membership\Services;

/**
 * Daily lifecycle maintenance for subscription overdue/lapse transitions.
 */
class DailyMaintenanceService
{
    private ActivityLogger $activityLogger;

    public function __construct(ActivityLogger $activityLogger)
    {
        $this->activityLogger = $activityLogger;
    }

    public function register_hooks(): void
    {
        add_action('iei_daily_maintenance', [$this, 'run_daily_maintenance']);
    }

    public static function schedule_event(): void
    {
        if (! wp_next_scheduled('iei_daily_maintenance')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'iei_daily_maintenance');
        }
    }

    public static function unschedule_event(): void
    {
        $timestamp = wp_next_scheduled('iei_daily_maintenance');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'iei_daily_maintenance');
        }
    }

    /**
     * Execute daily status transitions based on due dates and grace windows.
     */
    public function run_daily_maintenance(): void
    {
        $today = current_time('Y-m-d');
        $settings = $this->settings();
        $gracePeriodDays = max(0, absint($settings['grace_period_days'] ?? 30));

        $this->mark_unpaid_renewals_overdue($today, $gracePeriodDays);
        $this->mark_overdue_as_lapsed($today, $gracePeriodDays);
    }

    private function mark_unpaid_renewals_overdue(string $today, int $gracePeriodDays): void
    {
        if ((new \DateTimeImmutable($today))->format('m-d') !== '07-01') {
            return;
        }

        global $wpdb;

        $subscriptionsTable = $wpdb->prefix . 'iei_subscriptions';
        $membersTable = $wpdb->prefix . 'iei_members';
        $graceUntil = (new \DateTimeImmutable($today))->modify('+' . $gracePeriodDays . ' days')->format('Y-m-d');
        $now = current_time('mysql');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.id AS subscription_id, s.member_id, s.due_date, s.amount_due, s.amount_paid,
                        m.application_id, m.wp_user_id
                 FROM {$subscriptionsTable} s
                 INNER JOIN {$membersTable} m ON m.id = s.member_id
                 WHERE s.status = %s
                   AND DATE_FORMAT(s.due_date, '%%m-%%d') = %s
                   AND COALESCE(s.amount_paid, 0) < COALESCE(s.amount_due, 0)",
                'pending_payment',
                '07-01'
            ),
            ARRAY_A
        );

        if (! is_array($rows) || empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $subscriptionId = (int) $row['subscription_id'];
            $memberId = (int) $row['member_id'];
            $applicationId = isset($row['application_id']) ? (int) $row['application_id'] : null;
            $wpUserId = (int) ($row['wp_user_id'] ?? 0);

            $updated = $wpdb->update(
                $subscriptionsTable,
                [
                    'status' => 'overdue',
                    'grace_until' => $graceUntil,
                    'updated_at' => $now,
                ],
                [
                    'id' => $subscriptionId,
                    'status' => 'pending_payment',
                ],
                ['%s', '%s', '%s'],
                ['%d', '%s']
            );

            if ($updated === false || (int) $updated === 0) {
                continue;
            }

            $this->ensure_member_role_until_grace_end($wpUserId);

            $this->activityLogger->log_member_event($memberId, 'subscription_marked_overdue', [
                'subscription_id' => $subscriptionId,
                'due_date' => (string) ($row['due_date'] ?? ''),
                'grace_until' => $graceUntil,
            ], null, $applicationId);
        }
    }

    private function mark_overdue_as_lapsed(string $today, int $gracePeriodDays): void
    {
        global $wpdb;

        $subscriptionsTable = $wpdb->prefix . 'iei_subscriptions';
        $membersTable = $wpdb->prefix . 'iei_members';
        $now = current_time('mysql');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.id AS subscription_id, s.member_id, s.due_date, s.grace_until,
                        m.application_id, m.wp_user_id
                 FROM {$subscriptionsTable} s
                 INNER JOIN {$membersTable} m ON m.id = s.member_id
                 WHERE s.status = %s",
                'overdue'
            ),
            ARRAY_A
        );

        if (! is_array($rows) || empty($rows)) {
            return;
        }

        $todayObj = new \DateTimeImmutable($today);

        foreach ($rows as $row) {
            $graceUntil = $this->resolve_grace_until((string) ($row['grace_until'] ?? ''), (string) ($row['due_date'] ?? ''), $gracePeriodDays);
            if (! $graceUntil || $todayObj <= $graceUntil) {
                continue;
            }

            $subscriptionId = (int) $row['subscription_id'];
            $memberId = (int) $row['member_id'];
            $applicationId = isset($row['application_id']) ? (int) $row['application_id'] : null;
            $wpUserId = (int) ($row['wp_user_id'] ?? 0);

            $updatedSubscription = $wpdb->update(
                $subscriptionsTable,
                [
                    'status' => 'lapsed',
                    'updated_at' => $now,
                ],
                [
                    'id' => $subscriptionId,
                    'status' => 'overdue',
                ],
                ['%s', '%s'],
                ['%d', '%s']
            );

            if ($updatedSubscription === false || (int) $updatedSubscription === 0) {
                continue;
            }

            $wpdb->update(
                $membersTable,
                [
                    'status' => 'lapsed',
                    'lapsed_at' => $now,
                    'updated_at' => $now,
                ],
                ['id' => $memberId],
                ['%s', '%s', '%s'],
                ['%d']
            );

            $this->downgrade_to_pending_payment_role($wpUserId);

            $this->activityLogger->log_member_event($memberId, 'subscription_lapsed_after_grace', [
                'subscription_id' => $subscriptionId,
                'grace_until' => $graceUntil->format('Y-m-d'),
            ], null, $applicationId);
        }
    }

    private function ensure_member_role_until_grace_end(int $wpUserId): void
    {
        if ($wpUserId <= 0) {
            return;
        }

        $user = get_user_by('id', $wpUserId);
        if (! $user instanceof \WP_User) {
            return;
        }

        if (user_can($user, 'manage_options')) {
            if (! in_array('iei_member', (array) $user->roles, true)) {
                $user->add_role('iei_member');
            }
            return;
        }

        $user->set_role('iei_member');
    }

    private function downgrade_to_pending_payment_role(int $wpUserId): void
    {
        if ($wpUserId <= 0) {
            return;
        }

        $user = get_user_by('id', $wpUserId);
        if (! $user instanceof \WP_User) {
            return;
        }

        if (user_can($user, 'manage_options')) {
            if (! in_array('iei_pending_payment', (array) $user->roles, true)) {
                $user->add_role('iei_pending_payment');
            }
            return;
        }

        $user->set_role('iei_pending_payment');
    }

    private function resolve_grace_until(string $graceUntil, string $dueDate, int $gracePeriodDays): ?\DateTimeImmutable
    {
        $graceUntil = trim($graceUntil);
        if ($graceUntil !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $graceUntil);
            if ($parsed instanceof \DateTimeImmutable) {
                return $parsed;
            }
        }

        $dueDate = trim($dueDate);
        if ($dueDate === '') {
            return null;
        }

        $due = \DateTimeImmutable::createFromFormat('Y-m-d', $dueDate);
        if (! $due instanceof \DateTimeImmutable) {
            return null;
        }

        return $due->modify('+' . $gracePeriodDays . ' days');
    }

    private function settings(): array
    {
        $settings = get_option(IEI_MEMBERSHIP_OPTION_KEY, []);
        if (! is_array($settings)) {
            $settings = [];
        }

        return array_merge(iei_membership_default_settings(), $settings);
    }
}
