<?php

namespace IEI\Membership\Controllers\Admin;

use IEI\Membership\Services\ActivityLogger;
use IEI\Membership\Services\RolesManager;

/**
 * Admin CSV importer for onboarding member/user/subscription records.
 */
class ImportMembersPage
{
    private const REQUIRED_COLUMNS = ['email', 'first_name', 'last_name', 'membership_number', 'membership_type', 'membership_year'];

    private string $menuSlug = 'iei-membership-import-members';
    private ActivityLogger $activityLogger;

    public function __construct(ActivityLogger $activityLogger)
    {
        $this->activityLogger = $activityLogger;
    }

    public function register_hooks(): void
    {
        add_action('admin_post_iei_membership_import_members', [$this, 'handle_import_post']);
    }

    public function render(): void
    {
        $this->assert_access();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Import Members (CSV)', 'iei-membership') . '</h1>';
        echo '<p>' . esc_html__('Upload a CSV of active members. Existing records will be reported as duplicates.', 'iei-membership') . '</p>';

        $this->render_notice_report();
        $this->render_upload_form();
        $this->render_required_columns_help();

        echo '</div>';
    }

    public function handle_import_post(): void
    {
        $this->assert_access();
        check_admin_referer('iei_membership_import_members');

        $report = [
            'processed' => 0,
            'created_users' => 0,
            'created_members' => 0,
            'created_subscriptions' => 0,
            'duplicates' => [],
            'errors' => [],
        ];

        $file = $_FILES['members_csv'] ?? null;
        if (! is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $report['errors'][] = __('CSV upload failed. Please choose a valid file.', 'iei-membership');
            $this->store_report_and_redirect($report);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if (! is_uploaded_file($tmpPath)) {
            $report['errors'][] = __('Uploaded CSV payload is invalid.', 'iei-membership');
            $this->store_report_and_redirect($report);
        }

        $handle = fopen($tmpPath, 'rb');
        if (! is_resource($handle)) {
            $report['errors'][] = __('Could not open CSV file for reading.', 'iei-membership');
            $this->store_report_and_redirect($report);
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || empty($header)) {
            fclose($handle);
            $report['errors'][] = __('CSV header row is missing.', 'iei-membership');
            $this->store_report_and_redirect($report);
        }

        $normalizedHeader = array_map([$this, 'normalize_column'], $header);
        $missingColumns = array_diff(self::REQUIRED_COLUMNS, $normalizedHeader);

        if (! empty($missingColumns)) {
            fclose($handle);
            $report['errors'][] = sprintf(
                __('Missing required columns: %s', 'iei-membership'),
                implode(', ', $missingColumns)
            );
            $this->store_report_and_redirect($report);
        }

        $lineNumber = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;

            if (! is_array($row)) {
                continue;
            }

            $rowData = $this->map_row($normalizedHeader, $row);
            if ($this->is_empty_row($rowData)) {
                continue;
            }

            $report['processed']++;
            $validation = $this->validate_row($rowData);
            if (! empty($validation)) {
                $report['errors'][] = 'Line ' . $lineNumber . ': ' . implode('; ', $validation);
                $this->activityLogger->log_system_event('member_import_row_error', [
                    'line' => $lineNumber,
                    'error_count' => count($validation),
                ], get_current_user_id());
                continue;
            }

            try {
                $result = $this->import_row($rowData);

                if ($result['duplicate']) {
                    $report['duplicates'][] = 'Line ' . $lineNumber . ': ' . $result['message'];
                    $this->activityLogger->log_system_event('member_import_row_duplicate', [
                        'line' => $lineNumber,
                        'duplicate_code' => (string) ($result['duplicate_code'] ?? 'unknown'),
                    ], get_current_user_id());
                    continue;
                }

                $report['created_users'] += $result['user_created'] ? 1 : 0;
                $report['created_members'] += $result['member_created'] ? 1 : 0;
                $report['created_subscriptions'] += $result['subscription_created'] ? 1 : 0;

                $this->activityLogger->log_member_event(
                    $result['member_id'],
                    'member_imported_csv',
                    [
                        'membership_year' => (int) $rowData['membership_year'],
                        'user_created' => $result['user_created'],
                        'subscription_created' => $result['subscription_created'],
                    ],
                    get_current_user_id(),
                    null
                );
            } catch (\Throwable $throwable) {
                $report['errors'][] = 'Line ' . $lineNumber . ': ' . $throwable->getMessage();
                $this->activityLogger->log_system_event('member_import_row_exception', [
                    'line' => $lineNumber,
                    'exception' => 'import_row_failed',
                ], get_current_user_id());
            }
        }

        fclose($handle);

        $this->activityLogger->log_system_event('member_import_completed', [
            'processed' => $report['processed'],
            'created_users' => $report['created_users'],
            'created_members' => $report['created_members'],
            'created_subscriptions' => $report['created_subscriptions'],
            'duplicates' => count($report['duplicates']),
            'errors' => count($report['errors']),
        ], get_current_user_id());

        $this->store_report_and_redirect($report);
    }

    private function import_row(array $rowData): array
    {
        global $wpdb;

        $email = sanitize_email((string) $rowData['email']);
        $firstName = sanitize_text_field((string) $rowData['first_name']);
        $lastName = sanitize_text_field((string) $rowData['last_name']);
        $membershipNumber = sanitize_text_field((string) $rowData['membership_number']);
        $membershipType = sanitize_key((string) $rowData['membership_type']);
        $membershipYear = absint($rowData['membership_year']);

        $membersTable = $wpdb->prefix . 'iei_members';
        $memberByNumber = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$membersTable} WHERE membership_number = %s LIMIT 1", $membershipNumber)
        );

        if ($memberByNumber) {
            return [
                'duplicate' => true,
                'duplicate_code' => 'membership_number_exists',
                'message' => 'Membership number already exists (' . $membershipNumber . ')',
            ];
        }

        $existingUser = get_user_by('email', $email);
        $userCreated = false;
        if ($existingUser instanceof \WP_User) {
            $wpUserId = (int) $existingUser->ID;
        } else {
            $password = wp_generate_password(24, true, true);
            $userId = wp_create_user($email, $password, $email);
            if (is_wp_error($userId) || (int) $userId <= 0) {
                throw new \RuntimeException('Failed to create WordPress user for ' . $email);
            }

            $wpUserId = (int) $userId;
            $userCreated = true;

            wp_update_user([
                'ID' => $wpUserId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'display_name' => trim($firstName . ' ' . $lastName) ?: $email,
            ]);
        }

        $wpUser = get_user_by('id', $wpUserId);
        if ($wpUser instanceof \WP_User) {
            if (user_can($wpUser, 'manage_options')) {
                if (! in_array('iei_member', (array) $wpUser->roles, true)) {
                    $wpUser->add_role('iei_member');
                }
            } else {
                $wpUser->set_role('iei_member');
            }
        }

        $memberByUser = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$membersTable} WHERE wp_user_id = %d LIMIT 1", $wpUserId),
            ARRAY_A
        );

        if (is_array($memberByUser)) {
            return [
                'duplicate' => true,
                'duplicate_code' => 'user_already_member',
                'message' => 'Member already exists for email ' . $email,
            ];
        }

        $now = current_time('mysql');
        $insertedMember = $wpdb->insert(
            $membersTable,
            [
                'wp_user_id' => $wpUserId,
                'application_id' => null,
                'membership_number' => $membershipNumber,
                'membership_type' => $membershipType,
                'status' => 'active',
                'approved_at' => $now,
                'activated_at' => $now,
                'lapsed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($insertedMember === false) {
            throw new \RuntimeException('Failed to insert member record for ' . $email);
        }

        $memberId = (int) $wpdb->insert_id;
        $subscriptionCreated = $this->create_active_subscription_if_missing($memberId, $membershipType, $membershipYear);

        return [
            'duplicate' => false,
            'duplicate_code' => '',
            'message' => '',
            'user_created' => $userCreated,
            'member_created' => true,
            'subscription_created' => $subscriptionCreated,
            'member_id' => $memberId,
        ];
    }

    private function create_active_subscription_if_missing(int $memberId, string $membershipType, int $membershipYear): bool
    {
        global $wpdb;

        $subscriptionsTable = $wpdb->prefix . 'iei_subscriptions';
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$subscriptionsTable} WHERE member_id = %d AND membership_year = %d LIMIT 1",
                $memberId,
                $membershipYear
            )
        );

        if ($existing) {
            return false;
        }

        $prices = $this->membership_prices();
        $amountDue = isset($prices[$membershipType]) ? (float) $prices[$membershipType] : 145.0;
        $amountDue = max(0.0, round($amountDue, 2));

        $startDate = ($membershipYear - 1) . '-07-01';
        $endDate = $membershipYear . '-06-30';
        $dueDate = ($membershipYear - 1) . '-07-01';
        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            $subscriptionsTable,
            [
                'member_id' => $memberId,
                'membership_year' => $membershipYear,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'amount_due' => $amountDue,
                'amount_paid' => $amountDue,
                'status' => 'active',
                'due_date' => $dueDate,
                'paid_at' => $now,
                'grace_until' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            throw new \RuntimeException('Failed to create active subscription record.');
        }

        return true;
    }

    private function validate_row(array $rowData): array
    {
        $errors = [];

        if (! is_email((string) ($rowData['email'] ?? ''))) {
            $errors[] = 'Invalid email';
        }

        if (trim((string) ($rowData['first_name'] ?? '')) === '') {
            $errors[] = 'First name is required';
        }

        if (trim((string) ($rowData['last_name'] ?? '')) === '') {
            $errors[] = 'Last name is required';
        }

        if (trim((string) ($rowData['membership_number'] ?? '')) === '') {
            $errors[] = 'Membership number is required';
        }

        $membershipType = sanitize_key((string) ($rowData['membership_type'] ?? ''));
        if (! in_array($membershipType, ['associate', 'corporate', 'senior'], true)) {
            $errors[] = 'Membership type must be one of: associate, corporate, senior';
        }

        $membershipYear = absint($rowData['membership_year'] ?? 0);
        if ($membershipYear < 2000 || $membershipYear > 2100) {
            $errors[] = 'Membership year is invalid';
        }

        return $errors;
    }

    private function map_row(array $header, array $row): array
    {
        $mapped = [];
        foreach ($header as $index => $column) {
            if ($column === '') {
                continue;
            }
            $mapped[$column] = isset($row[$index]) ? trim((string) $row[$index]) : '';
        }

        return $mapped;
    }

    private function is_empty_row(array $rowData): bool
    {
        foreach ($rowData as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalize_column($value): string
    {
        return sanitize_key(str_replace([' ', '-'], '_', strtolower(trim((string) $value))));
    }

    private function membership_prices(): array
    {
        $settings = get_option(IEI_MEMBERSHIP_OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];

        $prices = isset($settings['membership_type_prices']) && is_array($settings['membership_type_prices'])
            ? $settings['membership_type_prices']
            : [];

        return array_merge([
            'associate' => 145,
            'corporate' => 145,
            'senior' => 70,
        ], $prices);
    }

    private function render_upload_form(): void
    {
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="iei_membership_import_members" />';
        wp_nonce_field('iei_membership_import_members');
        echo '<p><input type="file" name="members_csv" accept=".csv,text/csv" required /></p>';
        submit_button(__('Import CSV', 'iei-membership'));
        echo '</form>';
    }

    private function render_required_columns_help(): void
    {
        echo '<h2>' . esc_html__('Required Columns', 'iei-membership') . '</h2>';
        echo '<p><code>' . esc_html(implode(', ', self::REQUIRED_COLUMNS)) . '</code></p>';
    }

    private function render_notice_report(): void
    {
        $updated = sanitize_key(wp_unslash((string) ($_GET['updated'] ?? '')));
        if ($updated !== 'import_completed') {
            return;
        }

        $report = get_transient($this->report_transient_key());
        delete_transient($this->report_transient_key());

        if (! is_array($report)) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Import report could not be loaded.', 'iei-membership') . '</p></div>';
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>';
        echo esc_html(sprintf(
            'Processed: %d | Users created: %d | Members created: %d | Subscriptions created: %d',
            (int) ($report['processed'] ?? 0),
            (int) ($report['created_users'] ?? 0),
            (int) ($report['created_members'] ?? 0),
            (int) ($report['created_subscriptions'] ?? 0)
        ));
        echo '</p></div>';

        if (! empty($report['duplicates'])) {
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Duplicates', 'iei-membership') . '</strong></p><ul>';
            foreach ((array) $report['duplicates'] as $duplicate) {
                echo '<li>' . esc_html((string) $duplicate) . '</li>';
            }
            echo '</ul></div>';
        }

        if (! empty($report['errors'])) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('Errors', 'iei-membership') . '</strong></p><ul>';
            foreach ((array) $report['errors'] as $error) {
                echo '<li>' . esc_html((string) $error) . '</li>';
            }
            echo '</ul></div>';
        }
    }

    private function store_report_and_redirect(array $report): void
    {
        set_transient($this->report_transient_key(), $report, 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['updated' => 'import_completed'], $this->list_url()));
        exit;
    }

    private function report_transient_key(): string
    {
        return 'iei_import_report_' . get_current_user_id();
    }

    private function list_url(): string
    {
        return admin_url('admin.php?page=' . $this->menuSlug);
    }

    private function assert_access(): void
    {
        if (! current_user_can(RolesManager::CAP_IMPORT_MEMBERS) && ! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to import members.', 'iei-membership'), 403);
        }
    }
}
