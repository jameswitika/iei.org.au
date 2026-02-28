<?php

namespace IEI\Membership\Services;

/**
 * Handles payment completion workflow and member activation side-effects.
 */
class PaymentActivationService
{
    private ActivityLogger $activityLogger;

    public function __construct(ActivityLogger $activityLogger)
    {
        $this->activityLogger = $activityLogger;
    }

    /**
     * Mark a subscription as paid and transition the member to active state.
     */
    public function mark_subscription_paid(int $subscriptionId, ?int $actorUserId = null, string $reference = '', array $paymentMeta = []): array
    {
        global $wpdb;

        $subscription = $this->get_subscription($subscriptionId);
        if (! $subscription) {
            throw new \RuntimeException('Subscription not found.');
        }

        $member = $this->get_member((int) $subscription['member_id']);
        if (! $member) {
            throw new \RuntimeException('Related member record not found.');
        }

        $memberId = (int) $member['id'];
        $applicationId = isset($member['application_id']) ? (int) $member['application_id'] : null;
        $now = current_time('mysql');
        $amountDue = (float) ($subscription['amount_due'] ?? 0);
        $cycleDates = $this->normalize_subscription_dates($subscription);

        $alreadyPaid = (string) ($subscription['status'] ?? '') === 'active';

        if ($alreadyPaid) {
            $paymentId = $this->insert_payment_record($member, $subscription, $amountDue, $reference, $now, $paymentMeta);
            $membershipNumber = $this->ensure_membership_number($member);

            $this->activityLogger->log_member_event($memberId, 'payment_duplicate_ignored', [
                'subscription_id' => $subscriptionId,
                'payment_id' => $paymentId,
                'gateway' => (string) ($paymentMeta['gateway'] ?? 'bank_transfer'),
            ], $actorUserId, $applicationId);

            return [
                'payment_id' => $paymentId,
                'member_id' => $memberId,
                'membership_number' => $membershipNumber,
                'email_sent' => false,
                'already_paid' => true,
            ];
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'iei_subscriptions',
            [
                'status' => 'active',
                'amount_paid' => $amountDue,
                'paid_at' => $now,
                'start_date' => $cycleDates['start_date'],
                'end_date' => $cycleDates['end_date'],
                'updated_at' => $now,
            ],
            ['id' => $subscriptionId],
            ['%s', '%f', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('Failed to update subscription status.');
        }

        $paymentId = $this->insert_payment_record($member, $subscription, $amountDue, $reference, $now, $paymentMeta);

        $membershipNumber = $this->ensure_membership_number($member);
        $this->activate_member($member, $membershipNumber, $now);
        $this->apply_member_role((int) $member['wp_user_id']);

        $emailSent = $this->send_payment_confirmation((int) $member['wp_user_id'], $membershipNumber, $subscription, $amountDue);

        $this->activityLogger->log_member_event($memberId, 'payment_marked_paid', [
            'subscription_id' => $subscriptionId,
            'payment_id' => $paymentId,
            'amount' => $amountDue,
            'reference_provided' => $reference !== '',
        ], $actorUserId, $applicationId);

        $this->activityLogger->log_member_event($memberId, 'payment_recorded', [
            'subscription_id' => $subscriptionId,
            'payment_id' => $paymentId,
            'gateway' => (string) ($paymentMeta['gateway'] ?? 'bank_transfer'),
            'gateway_reference' => (string) ($paymentMeta['gateway_transaction_id'] ?? ''),
        ], $actorUserId, $applicationId);

        $this->activityLogger->log_member_event($memberId, 'member_activated_after_payment', [
            'membership_number' => $membershipNumber,
            'subscription_id' => $subscriptionId,
            'email_sent' => $emailSent,
        ], $actorUserId, $applicationId);

        $this->activityLogger->log_member_event($memberId, 'membership_activated', [
            'membership_number' => $membershipNumber,
            'subscription_id' => $subscriptionId,
        ], $actorUserId, $applicationId);

        return [
            'payment_id' => $paymentId,
            'member_id' => $memberId,
            'membership_number' => $membershipNumber,
            'email_sent' => $emailSent,
        ];
    }

    private function insert_payment_record(array $member, array $subscription, float $amount, string $reference, string $now, array $paymentMeta = []): int
    {
        global $wpdb;

        $paymentsTable = $wpdb->prefix . 'iei_payments';
        $gatewayReference = sanitize_text_field((string) ($paymentMeta['gateway_transaction_id'] ?? ''));
        $paymentMethod = sanitize_key((string) ($paymentMeta['payment_method'] ?? 'bank_transfer'));
        if ($paymentMethod === '') {
            $paymentMethod = 'bank_transfer';
        }
        $gateway = sanitize_key((string) ($paymentMeta['gateway'] ?? $paymentMethod));
        if ($gateway === '') {
            $gateway = $paymentMethod;
        }
        $currency = strtoupper(sanitize_text_field((string) ($paymentMeta['currency'] ?? 'AUD')));
        if ($currency === '') {
            $currency = 'AUD';
        }

        if ($gatewayReference !== '') {
            $existingByReference = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$paymentsTable} WHERE gateway_transaction_id = %s LIMIT 1",
                    $gatewayReference
                )
            );

            if ($existingByReference) {
                return (int) $existingByReference;
            }
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$paymentsTable} WHERE subscription_id = %d AND status = %s ORDER BY id DESC LIMIT 1",
                (int) $subscription['id'],
                'paid'
            )
        );

        if ($existing) {
            return (int) $existing;
        }

        $inserted = $wpdb->insert(
            $paymentsTable,
            [
                'member_id' => (int) $member['id'],
                'subscription_id' => (int) $subscription['id'],
                'application_id' => isset($member['application_id']) ? (int) $member['application_id'] : null,
                'amount' => $amount,
                'currency' => $currency,
                'payment_method' => $paymentMethod,
                'gateway' => $gateway,
                'gateway_transaction_id' => $gatewayReference !== '' ? $gatewayReference : null,
                'status' => 'paid',
                'reference' => sanitize_text_field($reference),
                'received_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            throw new \RuntimeException('Failed to create payment record.');
        }

        return (int) $wpdb->insert_id;
    }

    private function activate_member(array $member, string $membershipNumber, string $now): void
    {
        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'iei_members',
            [
                'status' => 'active',
                'membership_number' => $membershipNumber,
                'activated_at' => $member['activated_at'] ?: $now,
                'updated_at' => $now,
            ],
            ['id' => (int) $member['id']],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('Failed to activate member record.');
        }
    }

    private function apply_member_role(int $wpUserId): void
    {
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

    /**
     * Send activation email after payment is marked paid.
     *
     * Email includes membership details and a destination URL that prefers
     * configured member-home page settings.
     */
    private function send_payment_confirmation(int $wpUserId, string $membershipNumber, array $subscription, float $amount): bool
    {
        $user = get_user_by('id', $wpUserId);
        if (! $user instanceof \WP_User || ! is_email($user->user_email)) {
            return false;
        }

        $displayName = trim((string) $user->display_name);
        if ($displayName === '') {
            $displayName = trim((string) $user->user_firstname);
        }
        if ($displayName === '') {
            $displayName = trim((string) $user->user_login);
        }

        $portalUrl = $this->member_home_or_login_url();

        $subject = __('IEI Membership Activated', 'iei-membership');
        $message = 'Welcome to IEI';
        if ($displayName !== '') {
            $message .= ', ' . $displayName;
        }
        $message .= "!\n\n";
        $message .= "Your payment has been received and your IEI membership is now active.\n\n";
        $message .= 'Membership number: ' . $membershipNumber . "\n";
        $message .= 'Amount paid: AUD ' . number_format($amount, 2) . "\n";
        $message .= 'Subscription period: ' . (string) ($subscription['start_date'] ?? '-') . ' to ' . (string) ($subscription['end_date'] ?? '-') . "\n";
        $message .= "\n";
        $message .= "Visit your member area: {$portalUrl}\n";

        return (bool) wp_mail($user->user_email, $subject, $message);
    }

    /**
     * Resolve member destination URL for email content.
     */
    private function member_home_or_login_url(): string
    {
        $settings = get_option(IEI_MEMBERSHIP_OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];
        $memberHomePageId = absint($settings['member_home_page_id'] ?? 0);

        if ($memberHomePageId > 0) {
            $url = get_permalink($memberHomePageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        $fallbackMemberPortal = home_url('/member-portal/');
        if (is_string($fallbackMemberPortal) && $fallbackMemberPortal !== '') {
            return $fallbackMemberPortal;
        }

        return wp_login_url();
    }

    /**
     * Ensure member has a unique membership number and return it.
     */
    private function ensure_membership_number(array $member): string
    {
        $current = trim((string) ($member['membership_number'] ?? ''));
        if ($current !== '') {
            return $current;
        }

        $next = $this->next_membership_number();

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'iei_members',
            [
                'membership_number' => $next,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => (int) $member['id']],
            ['%s', '%s'],
            ['%d']
        );

        return $next;
    }

    /**
     * Compute next membership number using both configured counter and DB state.
     *
     * The larger of configured-next and detected-max+1 is used to avoid
     * duplicates when settings are lowered or data is imported.
     */
    private function next_membership_number(): string
    {
        global $wpdb;

        $settings = get_option(IEI_MEMBERSHIP_OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];
        $configuredNext = max(1, absint($settings['next_membership_number'] ?? 1));

        $membersTable = $wpdb->prefix . 'iei_members';
        $numbers = $wpdb->get_col("SELECT membership_number FROM {$membersTable} WHERE membership_number IS NOT NULL AND membership_number <> ''");

        $max = 0;
        if (is_array($numbers)) {
            foreach ($numbers as $number) {
                $number = (string) $number;
                if (preg_match('/(\d+)$/', $number, $matches)) {
                    $max = max($max, (int) $matches[1]);
                }
            }
        }

        $nextNumber = max($configuredNext, $max + 1);
        $this->persist_next_membership_number($nextNumber + 1, $settings);

        return 'IEI-' . str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Persist the next numeric value to settings after assigning a member number.
     */
    private function persist_next_membership_number(int $nextNumber, array $settings = []): void
    {
        $settings = is_array($settings) ? $settings : [];
        $settings['next_membership_number'] = max(1, $nextNumber);
        update_option(IEI_MEMBERSHIP_OPTION_KEY, $settings);
    }

    private function get_subscription(int $subscriptionId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}iei_subscriptions WHERE id = %d LIMIT 1", $subscriptionId),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function get_member(int $memberId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}iei_members WHERE id = %d LIMIT 1", $memberId),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function normalize_subscription_dates(array $subscription): array
    {
        $start = (string) ($subscription['start_date'] ?? '');
        $end = (string) ($subscription['end_date'] ?? '');

        if ($this->is_valid_date($start) && $this->is_valid_date($end) && $start <= $end) {
            return [
                'start_date' => $start,
                'end_date' => $end,
            ];
        }

        $membershipYear = absint($subscription['membership_year'] ?? 0);
        if ($membershipYear <= 0) {
            $today = new \DateTimeImmutable(current_time('Y-m-d'));
            $membershipYear = (int) $today->format('Y');
        }

        return [
            'start_date' => ($membershipYear - 1) . '-07-01',
            'end_date' => $membershipYear . '-06-30',
        ];
    }

    private function is_valid_date(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
