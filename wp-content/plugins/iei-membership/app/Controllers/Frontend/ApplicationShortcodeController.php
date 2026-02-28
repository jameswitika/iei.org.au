<?php

namespace IEI\Membership\Controllers\Frontend;

use IEI\Membership\Services\ActivityLogger;
use IEI\Membership\Services\FileStorageService;

class ApplicationShortcodeController
{
    private const NONCE_ACTION = 'iei_membership_application_submit';

    private FileStorageService $fileStorageService;
    private ActivityLogger $activityLogger;

    public function __construct(FileStorageService $fileStorageService, ActivityLogger $activityLogger)
    {
        $this->fileStorageService = $fileStorageService;
        $this->activityLogger = $activityLogger;
    }

    public function register_hooks(): void
    {
        add_shortcode('iei_membership_application', [$this, 'render_shortcode']);
    }

    public function render_shortcode(): string
    {
        $result = $this->maybe_handle_submission();
        $errors = $result['errors'];
        $successMessage = $result['success_message'];
        $old = $result['old'];

        $membershipType = isset($old['membership_type']) ? (string) $old['membership_type'] : 'associate';

        ob_start();

        if ($successMessage !== '') {
            echo '<div class="iei-membership-notice iei-membership-notice-success">' . esc_html($successMessage) . '</div>';
        }

        if (! empty($errors)) {
            echo '<div class="iei-membership-notice iei-membership-notice-error"><ul>';
            foreach ($errors as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '<form method="post" enctype="multipart/form-data" class="iei-membership-application-form">';
        wp_nonce_field(self::NONCE_ACTION, '_iei_membership_nonce');
        echo '<input type="hidden" name="iei_membership_action" value="submit_application" />';

        echo '<p style="display:none;">';
        echo '<label for="iei_membership_website">Website</label>';
        echo '<input type="text" id="iei_membership_website" name="iei_membership_website" value="" autocomplete="off" tabindex="-1" />';
        echo '</p>';

        echo '<p>';
        echo '<label for="iei_first_name">' . esc_html__('First Name', 'iei-membership') . '</label><br />';
        echo '<input required type="text" id="iei_first_name" name="first_name" value="' . esc_attr($old['first_name'] ?? '') . '" />';
        echo '</p>';

        echo '<p>';
        echo '<label for="iei_last_name">' . esc_html__('Last Name', 'iei-membership') . '</label><br />';
        echo '<input required type="text" id="iei_last_name" name="last_name" value="' . esc_attr($old['last_name'] ?? '') . '" />';
        echo '</p>';

        echo '<p>';
        echo '<label for="iei_email">' . esc_html__('Email Address', 'iei-membership') . '</label><br />';
        echo '<input required type="email" id="iei_email" name="email" value="' . esc_attr($old['email'] ?? '') . '" />';
        echo '</p>';

        echo '<p>';
        echo '<label for="iei_membership_type">' . esc_html__('Membership Type', 'iei-membership') . '</label><br />';
        echo '<select required id="iei_membership_type" name="membership_type">';
        foreach ($this->allowed_membership_types() as $typeValue => $typeLabel) {
            echo '<option value="' . esc_attr($typeValue) . '" ' . selected($membershipType, $typeValue, false) . '>' . esc_html($typeLabel) . '</option>';
        }
        echo '</select>';
        echo '</p>';

        echo '<p>';
        echo '<label for="iei_employer">' . esc_html__('Employer', 'iei-membership') . '</label><br />';
        echo '<input type="text" id="iei_employer" name="employer" value="' . esc_attr($old['employer'] ?? '') . '" />';
        echo '</p>';

        echo '<p>';
        echo '<label for="iei_job_position">' . esc_html__('Job Position', 'iei-membership') . '</label><br />';
        echo '<input type="text" id="iei_job_position" name="job_position" value="' . esc_attr($old['job_position'] ?? '') . '" />';
        echo '</p>';

        echo '<p>';
        echo '<label for="iei_nomination_status">' . esc_html__('Nomination Status', 'iei-membership') . '</label><br />';
        echo '<select id="iei_nomination_status" name="nomination_status">';
        foreach ($this->nomination_statuses() as $statusValue => $statusLabel) {
            $selected = selected((string) ($old['nomination_status'] ?? 'not_specified'), $statusValue, false);
            echo '<option value="' . esc_attr($statusValue) . '" ' . $selected . '>' . esc_html($statusLabel) . '</option>';
        }
        echo '</select>';
        echo '</p>';

        echo '<p>';
        echo '<label for="iei_application_notes">' . esc_html__('Application Notes', 'iei-membership') . '</label><br />';
        echo '<textarea id="iei_application_notes" name="application_notes" rows="5">' . esc_textarea($old['application_notes'] ?? '') . '</textarea>';
        echo '</p>';

        echo '<p>';
        echo '<label for="iei_application_files">' . esc_html__('Attachments (max 5 files, 5MB each)', 'iei-membership') . '</label><br />';
        echo '<input type="file" id="iei_application_files" name="application_files[]" multiple />';
        echo '</p>';

        echo '<p><button type="submit">' . esc_html__('Submit Application', 'iei-membership') . '</button></p>';
        echo '</form>';

        return (string) ob_get_clean();
    }

    private function maybe_handle_submission(): array
    {
        $response = [
            'errors' => [],
            'success_message' => '',
            'old' => [],
        ];

        if (! isset($_POST['iei_membership_action']) || $_POST['iei_membership_action'] !== 'submit_application') {
            return $response;
        }

        $response['old'] = [
            'first_name' => sanitize_text_field(wp_unslash((string) ($_POST['first_name'] ?? ''))),
            'last_name' => sanitize_text_field(wp_unslash((string) ($_POST['last_name'] ?? ''))),
            'email' => sanitize_email(wp_unslash((string) ($_POST['email'] ?? ''))),
            'membership_type' => sanitize_key(wp_unslash((string) ($_POST['membership_type'] ?? ''))),
            'employer' => sanitize_text_field(wp_unslash((string) ($_POST['employer'] ?? ''))),
            'job_position' => sanitize_text_field(wp_unslash((string) ($_POST['job_position'] ?? ''))),
            'nomination_status' => sanitize_key(wp_unslash((string) ($_POST['nomination_status'] ?? 'not_specified'))),
            'application_notes' => sanitize_textarea_field(wp_unslash((string) ($_POST['application_notes'] ?? ''))),
        ];

        if (! $this->is_valid_nonce()) {
            $response['errors'][] = __('Invalid submission token. Please refresh and try again.', 'iei-membership');
            return $response;
        }

        if (! $this->is_honeypot_clean()) {
            $response['errors'][] = __('Spam check failed.', 'iei-membership');
            return $response;
        }

        $validationErrors = $this->validate_input($response['old']);
        if (! empty($validationErrors)) {
            $response['errors'] = $validationErrors;
            return $response;
        }

        $filesResult = $this->validate_files($_FILES['application_files'] ?? []);
        if (! empty($filesResult['errors'])) {
            $response['errors'] = $filesResult['errors'];
            return $response;
        }

        $applicationId = $this->insert_application($response['old']);
        if ($applicationId <= 0) {
            $response['errors'][] = __('Could not save your application. Please try again.', 'iei-membership');
            return $response;
        }

        $this->activityLogger->log_application_event($applicationId, 'application_submitted', [
            'membership_type' => $response['old']['membership_type'],
        ]);

        $savedFileCount = 0;
        foreach ($filesResult['files'] as $normalizedFile) {
            try {
                $this->fileStorageService->store_application_file(
                    $applicationId,
                    $normalizedFile,
                    'application_attachment',
                    null
                );
                $savedFileCount++;
            } catch (\Throwable $throwable) {
                $this->activityLogger->log_application_event($applicationId, 'application_file_store_failed', [
                    'code' => 'storage_failed',
                ]);
                $response['errors'][] = __('Application saved, but one or more attachments could not be stored.', 'iei-membership');
                break;
            }
        }

        $this->activityLogger->log_application_event($applicationId, 'application_files_saved', [
            'count' => $savedFileCount,
        ]);

        $this->notify_preapproval_officer($applicationId, $response['old']);

        if (empty($response['errors'])) {
            $response['success_message'] = __('Application submitted successfully. Our pre-approval officer will review it shortly.', 'iei-membership');
            $response['old'] = [];
        }

        return $response;
    }

    private function validate_input(array $input): array
    {
        $errors = [];

        if ((string) ($input['first_name'] ?? '') === '') {
            $errors[] = __('First name is required.', 'iei-membership');
        }

        if ((string) ($input['last_name'] ?? '') === '') {
            $errors[] = __('Last name is required.', 'iei-membership');
        }

        if (! is_email((string) ($input['email'] ?? ''))) {
            $errors[] = __('A valid email address is required.', 'iei-membership');
        }

        $membershipType = (string) ($input['membership_type'] ?? '');
        if (! isset($this->allowed_membership_types()[$membershipType])) {
            $errors[] = __('Please select a valid membership type.', 'iei-membership');
        }

        $nominationStatus = (string) ($input['nomination_status'] ?? '');
        if (! isset($this->nomination_statuses()[$nominationStatus])) {
            $errors[] = __('Please select a valid nomination status.', 'iei-membership');
        }

        return $errors;
    }

    private function validate_files(array $fileInput): array
    {
        $result = [
            'errors' => [],
            'files' => [],
        ];

        if (empty($fileInput) || ! isset($fileInput['name']) || ! is_array($fileInput['name'])) {
            return $result;
        }

        $maxFiles = 5;
        $maxFileSize = 5 * 1024 * 1024;
        $allowedExtensions = $this->allowed_extensions();

        $normalized = [];
        $count = count($fileInput['name']);

        for ($index = 0; $index < $count; $index++) {
            $name = (string) ($fileInput['name'][$index] ?? '');
            $error = (int) ($fileInput['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            $tmpName = (string) ($fileInput['tmp_name'][$index] ?? '');
            $size = (int) ($fileInput['size'][$index] ?? 0);
            $type = (string) ($fileInput['type'][$index] ?? '');

            if ($error === UPLOAD_ERR_NO_FILE || $name === '') {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                $result['errors'][] = sprintf(__('File upload failed: %s', 'iei-membership'), $name);
                continue;
            }

            $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            if (! in_array($extension, $allowedExtensions, true)) {
                $result['errors'][] = sprintf(__('File type is not allowed: %s', 'iei-membership'), $name);
                continue;
            }

            if ($size > $maxFileSize) {
                $result['errors'][] = sprintf(__('File exceeds 5MB limit: %s', 'iei-membership'), $name);
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'type' => $type,
                'tmp_name' => $tmpName,
                'error' => $error,
                'size' => $size,
            ];
        }

        if (count($normalized) > $maxFiles) {
            $result['errors'][] = __('You can upload up to 5 files per application.', 'iei-membership');
        }

        if (! empty($result['errors'])) {
            return $result;
        }

        $result['files'] = $normalized;
        return $result;
    }

    private function insert_application(array $input): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'iei_applications';
        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            $table,
            [
                'public_token' => wp_generate_uuid4(),
                'applicant_email' => sanitize_email((string) $input['email']),
                'applicant_first_name' => sanitize_text_field((string) $input['first_name']),
                'applicant_last_name' => sanitize_text_field((string) $input['last_name']),
                'employer' => sanitize_text_field((string) ($input['employer'] ?? '')),
                'job_position' => sanitize_text_field((string) ($input['job_position'] ?? '')),
                'nomination_status' => sanitize_key((string) ($input['nomination_status'] ?? 'not_specified')),
                'membership_type' => sanitize_key((string) $input['membership_type']),
                'status' => 'pending_preapproval',
                'submitted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            ]
        );

        if ($inserted === false) {
            return 0;
        }

        $applicationId = (int) $wpdb->insert_id;

        if (trim((string) ($input['application_notes'] ?? '')) !== '') {
            $this->activityLogger->log_application_event($applicationId, 'application_notes_submitted', [
                'has_notes' => true,
            ]);
        }

        return $applicationId;
    }

    private function notify_preapproval_officer(int $applicationId, array $input): void
    {
        $emails = $this->resolve_preapproval_emails();
        if (empty($emails)) {
            return;
        }

        $subjectTemplate = 'New membership application #{application_id}';
        $bodyTemplate = "A new membership application requires pre-approval.\n\n"
            . "Application ID: {application_id}\n"
            . "Applicant: {first_name} {last_name}\n"
            . "Email: {email}\n"
            . "Membership type: {membership_type}\n";

        $tokens = [
            '{application_id}' => (string) $applicationId,
            '{first_name}' => (string) ($input['first_name'] ?? ''),
            '{last_name}' => (string) ($input['last_name'] ?? ''),
            '{email}' => (string) ($input['email'] ?? ''),
            '{membership_type}' => (string) ($input['membership_type'] ?? ''),
        ];

        $subject = strtr($subjectTemplate, $tokens);
        $body = strtr($bodyTemplate, $tokens);

        $sent = wp_mail($emails, $subject, $body);

        $this->activityLogger->log_application_event($applicationId, 'preapproval_notification_sent', [
            'recipient_count' => count($emails),
            'sent' => (bool) $sent,
            'template_subject' => $subjectTemplate,
        ]);
    }

    private function resolve_preapproval_emails(): array
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

    private function is_valid_nonce(): bool
    {
        $nonce = isset($_POST['_iei_membership_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['_iei_membership_nonce']))
            : '';

        return wp_verify_nonce($nonce, self::NONCE_ACTION) !== false;
    }

    private function is_honeypot_clean(): bool
    {
        $honeypot = isset($_POST['iei_membership_website'])
            ? trim((string) wp_unslash($_POST['iei_membership_website']))
            : '';

        return $honeypot === '';
    }

    private function allowed_extensions(): array
    {
        $settings = get_option(IEI_MEMBERSHIP_OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];

        $extensions = isset($settings['allowed_mime_types']) && is_array($settings['allowed_mime_types'])
            ? $settings['allowed_mime_types']
            : ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

        return array_values(array_unique(array_map(static function ($value): string {
            return strtolower(sanitize_key((string) $value));
        }, $extensions)));
    }

    private function allowed_membership_types(): array
    {
        return [
            'associate' => __('Associate', 'iei-membership'),
            'corporate' => __('Corporate', 'iei-membership'),
            'senior' => __('Senior', 'iei-membership'),
        ];
    }

    private function nomination_statuses(): array
    {
        return [
            'not_specified' => __('Not specified', 'iei-membership'),
            'self_nominated' => __('Self nominated', 'iei-membership'),
            'nominated_by_member' => __('Nominated by member', 'iei-membership'),
        ];
    }
}
