<?php

namespace IEI\Membership\Services;

/**
 * Finalizes board outcomes and provisions downstream member/payment records.
 */
class BoardDecisionService
{
    private ActivityLogger $activityLogger;

    public function __construct(ActivityLogger $activityLogger)
    {
        $this->activityLogger = $activityLogger;
    }

    /**
     * Recompute board vote totals and finalize application when threshold is reached.
     */
    public function evaluate_after_vote(int $applicationId, ?int $actorUserId = null): array
    {
        global $wpdb;

        $application = $this->get_application($applicationId);
        if (! $application) {
            return ['finalized' => false, 'status' => null];
        }

        if ((string) $application['status'] !== 'pending_board_approval') {
            return ['finalized' => false, 'status' => (string) $application['status']];
        }

        $thresholds = $this->thresholds();
        $counts = $this->vote_counts($applicationId);

        $this->activityLogger->log_application_event($applicationId, 'board_vote_counts_recomputed', [
            'approvals' => $counts['approvals'],
            'rejections' => $counts['rejections'],
            'approval_threshold' => $thresholds['approval_threshold'],
            'rejection_threshold' => $thresholds['rejection_threshold'],
        ], $actorUserId);

        if ($counts['approvals'] >= $thresholds['approval_threshold']) {
            $this->finalize_approved($application, $actorUserId);
            return ['finalized' => true, 'status' => 'approved'];
        }

        if ($counts['rejections'] >= $thresholds['rejection_threshold']) {
            $this->finalize_rejected($application, $actorUserId);
            return ['finalized' => true, 'status' => 'rejected_board'];
        }

        return ['finalized' => false, 'status' => 'pending_board_approval'];
    }

    private function finalize_approved(array $application, ?int $actorUserId): void
    {
        global $wpdb;

        $applicationId = (int) $application['id'];
        $now = current_time('mysql');
        $applicationsTable = $wpdb->prefix . 'iei_applications';

        $updated = $wpdb->update(
            $applicationsTable,
            [
                'status' => 'approved',
                'board_finalised_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $applicationId,
                'status' => 'pending_board_approval',
            ],
            ['%s', '%s', '%s'],
            ['%d', '%s']
        );

        if ($updated === false) {
            throw new \RuntimeException('Failed to finalize approved application status.');
        }

        if ((int) $updated === 0) {
            return;
        }

        $this->activityLogger->log_application_event($applicationId, 'board_application_approved', [
            'status' => 'approved',
        ], $actorUserId);

        $wpUserId = $this->ensure_applicant_wp_user($application);
        $member = $this->ensure_member_row($application, $wpUserId);

        $createdSubscription = $this->ensure_pending_subscription_for_new_member($application, $member);
        $this->activityLogger->log_application_event($applicationId, 'subscription_pending_payment_prepared', [
            'created' => $createdSubscription['created'],
            'membership_year' => $createdSubscription['membership_year'],
            'amount_due' => $createdSubscription['amount_due'],
        ], $actorUserId);

        $emails = $this->send_approval_emails($application, $wpUserId, $createdSubscription['amount_due']);
        $this->activityLogger->log_application_event($applicationId, 'approval_notifications_sent', [
            'applicant_sent' => $emails['applicant_sent'],
            'stuart_recipients' => $emails['stuart_recipients'],
            'stuart_sent_count' => $emails['stuart_sent_count'],
        ], $actorUserId);
    }

    private function finalize_rejected(array $application, ?int $actorUserId): void
    {
        global $wpdb;

        $applicationId = (int) $application['id'];
        $now = current_time('mysql');
        $applicationsTable = $wpdb->prefix . 'iei_applications';

        $updated = $wpdb->update(
            $applicationsTable,
            [
                'status' => 'rejected_board',
                'board_finalised_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $applicationId,
                'status' => 'pending_board_approval',
            ],
            ['%s', '%s', '%s'],
            ['%d', '%s']
        );

        if ($updated === false) {
            throw new \RuntimeException('Failed to finalize rejected application status.');
        }

        if ((int) $updated === 0) {
            return;
        }

        $this->activityLogger->log_application_event($applicationId, 'board_application_rejected', [
            'status' => 'rejected_board',
        ], $actorUserId);

        $sent = $this->send_board_rejection_email($application);

        $this->activityLogger->log_application_event($applicationId, 'board_rejection_email_processed', [
            'sent' => $sent,
        ], $actorUserId);
    }

    private function send_board_rejection_email(array $application): bool
    {
        $email = sanitize_email((string) ($application['applicant_email'] ?? ''));
        if (! is_email($email)) {
            return false;
        }

        $subject = __('Update on Your Membership Application', 'iei-membership');
        $body = "Thank you for your application.\n\n"
            . "After review by the board, we are unable to progress your application to the next stage at this time.\n\n"
            . "If you believe additional information may assist your application, please contact us.\n\n"
            . "We appreciate your interest.";

        return (bool) wp_mail($email, $subject, $body);
    }

    private function ensure_applicant_wp_user(array $application): int
    {
        $email = sanitize_email((string) ($application['applicant_email'] ?? ''));
        if (! is_email($email)) {
            throw new \RuntimeException('Applicant email is invalid.');
        }

        $existing = get_user_by('email', $email);
        if ($existing instanceof \WP_User) {
            $this->apply_pending_payment_role($existing);
            return (int) $existing->ID;
        }

        $password = wp_generate_password(24, true, true);
        $userId = wp_create_user($email, $password, $email);
        if (is_wp_error($userId) || (int) $userId <= 0) {
            throw new \RuntimeException('Failed to create applicant user.');
        }

        wp_update_user([
            'ID' => (int) $userId,
            'display_name' => trim((string) $application['applicant_first_name'] . ' ' . (string) $application['applicant_last_name']),
            'first_name' => (string) ($application['applicant_first_name'] ?? ''),
            'last_name' => (string) ($application['applicant_last_name'] ?? ''),
        ]);

        $user = get_user_by('id', (int) $userId);
        if ($user instanceof \WP_User) {
            $this->apply_pending_payment_role($user);
        }

        return (int) $userId;
    }

    private function apply_pending_payment_role(\WP_User $user): void
    {
        if (user_can($user, 'manage_options')) {
            if (! in_array('iei_pending_payment', (array) $user->roles, true)) {
                $user->add_role('iei_pending_payment');
            }
            return;
        }

        $user->set_role('iei_pending_payment');
    }

    private function ensure_member_row(array $application, int $wpUserId): array
    {
        global $wpdb;

        $membersTable = $wpdb->prefix . 'iei_members';
        $applicationId = (int) $application['id'];
        $now = current_time('mysql');

        $member = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$membersTable} WHERE wp_user_id = %d LIMIT 1", $wpUserId),
            ARRAY_A
        );

        if (is_array($member)) {
            $wpdb->update(
                $membersTable,
                [
                    'application_id' => $applicationId,
                    'membership_type' => sanitize_key((string) ($application['membership_type'] ?? 'associate')),
                    'status' => 'pending_payment',
                    'approved_at' => $member['approved_at'] ?: $now,
                    'updated_at' => $now,
                ],
                ['id' => (int) $member['id']],
                ['%d', '%s', '%s', '%s', '%s'],
                ['%d']
            );

            $member['is_new_member'] = false;
            return $member;
        }

        $inserted = $wpdb->insert(
            $membersTable,
            [
                'wp_user_id' => $wpUserId,
                'application_id' => $applicationId,
                'membership_number' => null,
                'membership_type' => sanitize_key((string) ($application['membership_type'] ?? 'associate')),
                'status' => 'pending_payment',
                'approved_at' => $now,
                'activated_at' => null,
                'lapsed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            throw new \RuntimeException('Failed to create member row.');
        }

        return [
            'id' => (int) $wpdb->insert_id,
            'is_new_member' => true,
        ];
    }

    private function ensure_pending_subscription_for_new_member(array $application, array $member): array
    {
        if (empty($member['is_new_member'])) {
            return [
                'created' => false,
                'membership_year' => null,
                'amount_due' => 0.0,
            ];
        }

        global $wpdb;

        $memberId = (int) $member['id'];
        $subscriptionsTable = $wpdb->prefix . 'iei_subscriptions';
        $pricing = $this->calculate_prorata_amount((string) ($application['membership_type'] ?? 'associate'));

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$subscriptionsTable} WHERE member_id = %d AND membership_year = %d LIMIT 1",
                $memberId,
                $pricing['membership_year']
            )
        );

        if ($exists) {
            return [
                'created' => false,
                'membership_year' => $pricing['membership_year'],
                'amount_due' => (float) $pricing['amount_due'],
            ];
        }

        $inserted = $wpdb->insert(
            $subscriptionsTable,
            [
                'member_id' => $memberId,
                'membership_year' => $pricing['membership_year'],
                'start_date' => $pricing['start_date'],
                'end_date' => $pricing['end_date'],
                'amount_due' => $pricing['amount_due'],
                'amount_paid' => 0,
                'status' => 'pending_payment',
                'due_date' => $pricing['due_date'],
                'paid_at' => null,
                'grace_until' => null,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            throw new \RuntimeException('Failed to create pending subscription row.');
        }

        return [
            'created' => true,
            'membership_year' => $pricing['membership_year'],
            'amount_due' => (float) $pricing['amount_due'],
        ];
    }

    private function calculate_prorata_amount(string $membershipType): array
    {
        $settings = $this->settings();
        $prices = isset($settings['membership_type_prices']) && is_array($settings['membership_type_prices'])
            ? $settings['membership_type_prices']
            : [];

        $basePrice = isset($prices[$membershipType]) ? (float) $prices[$membershipType] : 145.0;
        $basePrice = max(0.0, round($basePrice, 2));

        $today = new \DateTimeImmutable(current_time('Y-m-d'));
        $year = (int) $today->format('Y');
        $june30Current = new \DateTimeImmutable($year . '-06-30');
        $cycleEnd = $today <= $june30Current ? $june30Current : new \DateTimeImmutable(($year + 1) . '-06-30');

        $cutoffDays = max(0, absint($settings['prorata_cutoff_days'] ?? 15));
        $daysRemaining = (int) $today->diff($cycleEnd)->format('%a');

        $effectiveEnd = $cycleEnd;
        $amountDue = $basePrice;

        if ($daysRemaining > $cutoffDays) {
            $monthsRemaining = (($cycleEnd->format('Y') - $today->format('Y')) * 12)
                + ((int) $cycleEnd->format('n') - (int) $today->format('n')) + 1;
            $monthsRemaining = max(1, min(12, $monthsRemaining));
            $amountDue = round($basePrice * ($monthsRemaining / 12), 2);
        } else {
            $effectiveEnd = $cycleEnd->modify('+1 year');
            $amountDue = $basePrice;
        }

        return [
            'membership_year' => (int) $effectiveEnd->format('Y'),
            'start_date' => $today->format('Y-m-d'),
            'end_date' => $effectiveEnd->format('Y-m-d'),
            'due_date' => $today->format('Y-m-d'),
            'amount_due' => $amountDue,
        ];
    }

    private function send_approval_emails(array $application, int $wpUserId, float $amountDue): array
    {
        $settings = $this->settings();
        $applicantEmail = sanitize_email((string) ($application['applicant_email'] ?? ''));
        $applicantSent = false;

        $setPasswordLink = $this->build_password_set_link($wpUserId);
        $subject = __('Your IEI Membership Application Was Approved', 'iei-membership');
        $body = "Your application has been approved by the board.\n\n"
            . "Please complete your account/password setup: {$setPasswordLink}\n"
            . "Amount due: AUD " . number_format($amountDue, 2) . "\n\n";

        if (! empty($settings['bank_transfer_instructions'])) {
            $body .= "Bank transfer instructions:\n" . (string) $settings['bank_transfer_instructions'] . "\n";
        }

        if (is_email($applicantEmail)) {
            $applicantSent = (bool) wp_mail($applicantEmail, $subject, $body);
        }

        $stuartEmails = $this->preapproval_officer_emails();
        $stuartSubject = __('Application approved and moved to payment pending', 'iei-membership');
        $applicantName = trim(
            (string) ($application['applicant_first_name'] ?? '') . ' ' . (string) ($application['applicant_last_name'] ?? '')
        );
        $applicantCompany = trim((string) ($application['employer'] ?? ''));

        $stuartBody = "Application #" . (int) $application['id'] . " approved. Applicant is now pending payment.\n\n";
        $stuartBody .= 'Applicant Name: ' . ($applicantName !== '' ? $applicantName : 'Not provided') . "\n";
        $stuartBody .= 'Company: ' . ($applicantCompany !== '' ? $applicantCompany : 'Not provided') . "\n";
        $stuartBody .= 'Membership Type: ' . (string) ($application['membership_type'] ?? 'Not provided') . "\n";

        $stuartSentCount = 0;
        foreach ($stuartEmails as $email) {
            if (wp_mail($email, $stuartSubject, $stuartBody)) {
                $stuartSentCount++;
            }
        }

        return [
            'applicant_sent' => $applicantSent,
            'stuart_recipients' => count($stuartEmails),
            'stuart_sent_count' => $stuartSentCount,
        ];
    }

    private function build_password_set_link(int $userId): string
    {
        $user = get_user_by('id', $userId);
        if (! $user instanceof \WP_User) {
            return wp_login_url();
        }

        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            return wp_login_url();
        }

        return network_site_url('wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode($user->user_login), 'login');
    }

    private function preapproval_officer_emails(): array
    {
        $users = get_users([
            'role' => 'iei_preapproval_officer',
            'fields' => ['user_email'],
        ]);

        $emails = [];
        foreach ($users as $user) {
            if (! empty($user->user_email) && is_email($user->user_email)) {
                $emails[] = $user->user_email;
            }
        }

        if (empty($emails)) {
            $adminEmail = get_option('admin_email');
            if (is_email($adminEmail)) {
                $emails[] = $adminEmail;
            }
        }

        return array_values(array_unique($emails));
    }

    private function thresholds(): array
    {
        $settings = $this->settings();

        return [
            'approval_threshold' => max(1, absint($settings['approval_threshold'] ?? 7)),
            'rejection_threshold' => max(1, absint($settings['rejection_threshold'] ?? 7)),
        ];
    }

    private function settings(): array
    {
        $settings = get_option(IEI_MEMBERSHIP_OPTION_KEY, []);
        if (! is_array($settings)) {
            $settings = [];
        }

        return array_merge(iei_membership_default_settings(), $settings);
    }

    private function vote_counts(int $applicationId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'iei_application_votes';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN vote = 'approved' THEN 1 ELSE 0 END) AS approvals,
                    SUM(CASE WHEN vote = 'rejected' THEN 1 ELSE 0 END) AS rejections
                 FROM {$table}
                 WHERE application_id = %d",
                $applicationId
            ),
            ARRAY_A
        );

        return [
            'approvals' => isset($row['approvals']) ? (int) $row['approvals'] : 0,
            'rejections' => isset($row['rejections']) ? (int) $row['rejections'] : 0,
        ];
    }

    private function get_application(int $applicationId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'iei_applications';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $applicationId),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }
}
