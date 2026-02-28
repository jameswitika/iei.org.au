<?php

namespace IEI\Membership\Controllers\Frontend;

use IEI\Membership\Services\ActivityLogger;
use IEI\Membership\Services\FileStorageService;

/**
 * Public application form shortcode and submission handler.
 */
class ApplicationShortcodeController
{
    private const NONCE_ACTION = 'iei_membership_application_submit';
    private const THANK_YOU_QUERY_ARG = 'iei_application_submitted';

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

    /**
     * Render form and display validation/success state for current request.
     */
    public function render_shortcode(): string
    {
        $result = $this->maybe_handle_submission();
        $errors = $result['errors'];
        $old = $result['old'];

        $membershipType = isset($old['membership_type']) ? (string) $old['membership_type'] : 'associate';
        $nominationStatus = isset($old['nomination_status']) ? (string) $old['nomination_status'] : 'self_nominated';
        $contactUrl = home_url('/contact-us/');

        ob_start();

        if ($this->should_show_thank_you()) {
            echo $this->render_thank_you_template();
            return (string) ob_get_clean();
        }

        if (! empty($errors)) {
            echo '<div class="iei-membership-notice iei-membership-notice-error"><ul>';
            foreach ($errors as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '<style>
            .iei-app-form{max-width:980px}
            .iei-app-form .iei-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
            .iei-app-form .iei-grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
            .iei-app-form .iei-full{grid-column:1/-1}
            .iei-app-form label{font-weight:600;display:block;margin-bottom:6px}
            .iei-app-form input[type=text],.iei-app-form input[type=email],.iei-app-form select,.iei-app-form textarea{width:100%;padding:10px 12px;border:1px solid #d0d7de;border-radius:8px;box-sizing:border-box}
            .iei-app-form textarea{min-height:110px}
            .iei-app-form .iei-section{margin:18px 0}
            .iei-app-form .iei-card{padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#fff}
            .iei-app-form .iei-help{font-size:13px;color:#555;margin-top:6px}
            @media (max-width:900px){.iei-app-form .iei-grid,.iei-app-form .iei-grid-2{grid-template-columns:1fr}}
        </style>';

        echo '<div class="iei-app-form">';
        echo '<p>' . esc_html__('Please fill out the form below to apply to become a member. If your application is successful, you will be notified and sent a link to make your payment for membership. If you have any questions, please use our contact us form to get in contact.', 'iei-membership') . ' <a href="' . esc_url($contactUrl) . '">' . esc_html__('Contact us', 'iei-membership') . '</a>.</p>';

        echo '<form method="post" enctype="multipart/form-data" class="iei-membership-application-form">';
        wp_nonce_field(self::NONCE_ACTION, '_iei_membership_nonce');
        echo '<input type="hidden" name="iei_membership_action" value="submit_application" />';

        echo '<p style="display:none;">';
        echo '<label for="iei_membership_website">Website</label>';
        echo '<input type="text" id="iei_membership_website" name="iei_membership_website" value="" autocomplete="off" tabindex="-1" />';
        echo '</p>';

        echo '<div class="iei-grid iei-section">';
        echo '<div><label for="iei_first_name">' . esc_html__('First Name', 'iei-membership') . '</label><input required type="text" id="iei_first_name" name="first_name" value="' . esc_attr($old['first_name'] ?? '') . '" /></div>';
        echo '<div><label for="iei_middle_name">' . esc_html__('Middle Name', 'iei-membership') . '</label><input type="text" id="iei_middle_name" name="middle_name" value="' . esc_attr($old['middle_name'] ?? '') . '" /></div>';
        echo '<div><label for="iei_last_name">' . esc_html__('Last Name', 'iei-membership') . '</label><input required type="text" id="iei_last_name" name="last_name" value="' . esc_attr($old['last_name'] ?? '') . '" /></div>';
        echo '<div class="iei-full"><label for="iei_address_1">' . esc_html__('Address Line 1', 'iei-membership') . '</label><input required type="text" id="iei_address_1" name="address_line_1" value="' . esc_attr($old['address_line_1'] ?? '') . '" /></div>';
        echo '<div class="iei-full"><label for="iei_address_2">' . esc_html__('Address Line 2', 'iei-membership') . '</label><input type="text" id="iei_address_2" name="address_line_2" value="' . esc_attr($old['address_line_2'] ?? '') . '" /></div>';
        echo '<div><label for="iei_suburb">' . esc_html__('Suburb', 'iei-membership') . '</label><input required type="text" id="iei_suburb" name="suburb" value="' . esc_attr($old['suburb'] ?? '') . '" /></div>';
        echo '<div><label for="iei_state">' . esc_html__('State', 'iei-membership') . '</label><select required id="iei_state" name="state"><option value="">' . esc_html__('Select state', 'iei-membership') . '</option>';
        foreach ($this->australian_states() as $stateCode => $stateLabel) {
            echo '<option value="' . esc_attr($stateCode) . '" ' . selected((string) ($old['state'] ?? ''), $stateCode, false) . '>' . esc_html($stateLabel) . '</option>';
        }
        echo '</select></div>';
        echo '<div><label for="iei_postcode">' . esc_html__('Postcode', 'iei-membership') . '</label><input required type="text" id="iei_postcode" name="postcode" value="' . esc_attr($old['postcode'] ?? '') . '" /></div>';
        echo '<div><label for="iei_phone">' . esc_html__('Phone', 'iei-membership') . '</label><input type="text" id="iei_phone" name="phone" value="' . esc_attr($old['phone'] ?? '') . '" /></div>';
        echo '<div><label for="iei_mobile">' . esc_html__('Mobile', 'iei-membership') . '</label><input type="text" id="iei_mobile" name="mobile" value="' . esc_attr($old['mobile'] ?? '') . '" /></div>';
        echo '<div><label for="iei_email">' . esc_html__('Email Address', 'iei-membership') . '</label><input required type="email" id="iei_email" name="email" value="' . esc_attr($old['email'] ?? '') . '" /></div>';
        echo '<div><label for="iei_employer">' . esc_html__('Employer', 'iei-membership') . '</label><input type="text" id="iei_employer" name="employer" value="' . esc_attr($old['employer'] ?? '') . '" /></div>';
        echo '<div><label for="iei_job_position">' . esc_html__('Job Position', 'iei-membership') . '</label><input type="text" id="iei_job_position" name="job_position" value="' . esc_attr($old['job_position'] ?? '') . '" /></div>';
        echo '<div><label for="iei_membership_type">' . esc_html__('Membership Type', 'iei-membership') . '</label><select required id="iei_membership_type" name="membership_type">';
        foreach ($this->allowed_membership_types() as $typeValue => $typeLabel) {
            echo '<option value="' . esc_attr($typeValue) . '" ' . selected($membershipType, $typeValue, false) . '>' . esc_html($typeLabel) . '</option>';
        }
        echo '</select></div>';
        echo '</div>';

        echo '<div class="iei-section iei-card">';
        echo '<h3 style="margin-top:0;">' . esc_html__('NOMINATED BY –', 'iei-membership') . '</h3>';
        echo '<p style="margin-top:0;">' . esc_html__('Nominated By - I, the undersigned member, hereby nominate the applicant above for admission as a member of the Institute of Electrical Inspectors Australia', 'iei-membership') . '</p>';
        echo '<label for="iei_nomination_status">' . esc_html__('Nomination Status', 'iei-membership') . '</label>';
        echo '<select id="iei_nomination_status" name="nomination_status">';
        foreach ($this->nomination_statuses() as $statusValue => $statusLabel) {
            $selected = selected($nominationStatus, $statusValue, false);
            echo '<option value="' . esc_attr($statusValue) . '" ' . $selected . '>' . esc_html($statusLabel) . '</option>';
        }
        echo '</select>';
        echo '<p class="iei-help">' . esc_html__('Select "Self nominated" if you do not know a member that can nominate you. The IEI Secretary will organise this for you.', 'iei-membership') . '</p>';

        $showNominator = $nominationStatus === 'nominated_by_member';
        echo '<div id="iei_nominator_fields" class="iei-grid-2" style="margin-top:10px;' . ($showNominator ? '' : 'display:none;') . '">';
        echo '<div><label for="iei_nominating_member_number">' . esc_html__('Nominating Member Number', 'iei-membership') . '</label><input type="text" id="iei_nominating_member_number" name="nominating_member_number" value="' . esc_attr($old['nominating_member_number'] ?? '') . '" /></div>';
        echo '<div><label for="iei_nominating_member_name">' . esc_html__('Nominating Member Name (Full)', 'iei-membership') . '</label><input type="text" id="iei_nominating_member_name" name="nominating_member_name" value="' . esc_attr($old['nominating_member_name'] ?? '') . '" /></div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="iei-section iei-card">';
        echo '<h3 style="margin-top:0;">' . esc_html__('DECLARATION BY APPLICANT', 'iei-membership') . '</h3>';
        echo '<p>' . esc_html__('I desire to become a member of the Institute of Electrical Inspectors, confirm that the information provided on this form is true and correct and if admitted to membership agree to support and protect the professional status, rights and responsibilities of the Institute of Electrical Inspectors and abide by the Constitution of the Institute and the IEI members Code of Conduct.', 'iei-membership') . '</p>';
        echo '<label for="iei_signature_text">' . esc_html__('Signature (Type Full Name)', 'iei-membership') . '</label>';
        echo '<input required type="text" id="iei_signature_text" name="signature_text" value="' . esc_attr($old['signature_text'] ?? '') . '" />';
        echo '</div>';

        echo '<div class="iei-section">';
        echo '<label for="iei_application_notes">' . esc_html__('Application Notes', 'iei-membership') . '</label>';
        echo '<textarea id="iei_application_notes" name="application_notes" rows="5">' . esc_textarea($old['application_notes'] ?? '') . '</textarea>';
        echo '</div>';

        echo '<div class="iei-section">';
        echo '<label for="iei_application_files">' . esc_html__('Attachments (max 5 files, 5MB each)', 'iei-membership') . '</label>';
        echo '<input type="file" id="iei_application_files" name="application_files[]" multiple />';
        echo '</div>';

        echo '<p><button type="submit">' . esc_html__('Submit Application', 'iei-membership') . '</button></p>';
        echo '</form>';
        echo '</div>';

        echo '<script>
            (function(){
                var nomination = document.getElementById("iei_nomination_status");
                var nominatorFields = document.getElementById("iei_nominator_fields");
                if(!nomination || !nominatorFields){ return; }
                function toggleNominator(){
                    var show = nomination.value === "nominated_by_member";
                    nominatorFields.style.display = show ? "grid" : "none";
                }
                nomination.addEventListener("change", toggleNominator);
                toggleNominator();
            })();
        </script>';

        return (string) ob_get_clean();
    }

    private function maybe_handle_submission(): array
    {
        $response = [
            'errors' => [],
            'old' => [],
        ];

        if (! isset($_POST['iei_membership_action']) || $_POST['iei_membership_action'] !== 'submit_application') {
            return $response;
        }

        $response['old'] = [
            'first_name' => sanitize_text_field(wp_unslash((string) ($_POST['first_name'] ?? ''))),
            'middle_name' => sanitize_text_field(wp_unslash((string) ($_POST['middle_name'] ?? ''))),
            'last_name' => sanitize_text_field(wp_unslash((string) ($_POST['last_name'] ?? ''))),
            'address_line_1' => sanitize_text_field(wp_unslash((string) ($_POST['address_line_1'] ?? ''))),
            'address_line_2' => sanitize_text_field(wp_unslash((string) ($_POST['address_line_2'] ?? ''))),
            'suburb' => sanitize_text_field(wp_unslash((string) ($_POST['suburb'] ?? ''))),
            'state' => sanitize_key(wp_unslash((string) ($_POST['state'] ?? ''))),
            'postcode' => sanitize_text_field(wp_unslash((string) ($_POST['postcode'] ?? ''))),
            'phone' => sanitize_text_field(wp_unslash((string) ($_POST['phone'] ?? ''))),
            'mobile' => sanitize_text_field(wp_unslash((string) ($_POST['mobile'] ?? ''))),
            'email' => sanitize_email(wp_unslash((string) ($_POST['email'] ?? ''))),
            'membership_type' => sanitize_key(wp_unslash((string) ($_POST['membership_type'] ?? ''))),
            'employer' => sanitize_text_field(wp_unslash((string) ($_POST['employer'] ?? ''))),
            'job_position' => sanitize_text_field(wp_unslash((string) ($_POST['job_position'] ?? ''))),
            'nomination_status' => sanitize_key(wp_unslash((string) ($_POST['nomination_status'] ?? 'self_nominated'))),
            'nominating_member_number' => sanitize_text_field(wp_unslash((string) ($_POST['nominating_member_number'] ?? ''))),
            'nominating_member_name' => sanitize_text_field(wp_unslash((string) ($_POST['nominating_member_name'] ?? ''))),
            'signature_text' => sanitize_text_field(wp_unslash((string) ($_POST['signature_text'] ?? ''))),
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
            $this->redirect_after_successful_submission();
        }

        return $response;
    }

    /**
     * Post/Redirect/Get after successful submission to prevent form re-posts.
     */
    private function redirect_after_successful_submission(): void
    {
        $redirectUrl = $this->thank_you_url();
        if (! headers_sent()) {
            wp_safe_redirect($redirectUrl);
            exit;
        }
    }

    /**
     * Build thank-you URL with a flag that switches shortcode output to template mode.
     */
    private function thank_you_url(): string
    {
        return add_query_arg([self::THANK_YOU_QUERY_ARG => '1'], $this->thank_you_base_url());
    }

    /**
     * Resolve destination page for successful submit redirect from settings.
     */
    private function thank_you_base_url(): string
    {
        $settings = get_option(IEI_MEMBERSHIP_OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];
        $pageId = absint($settings['application_thank_you_page_id'] ?? 0);

        if ($pageId > 0) {
            $url = get_permalink($pageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return $this->current_url();
    }

    /**
     * Determine whether shortcode should show thank-you template instead of form.
     */
    private function should_show_thank_you(): bool
    {
        return (string) ($_GET[self::THANK_YOU_QUERY_ARG] ?? '') === '1';
    }

    /**
     * Render post-submission user guidance shown after successful application.
     */
    private function render_thank_you_template(): string
    {
        ob_start();

        echo '<div class="iei-membership-thank-you">';
        echo '<h2>' . esc_html__('Thank you for your application', 'iei-membership') . '</h2>';
        echo '<p>' . esc_html__('Your membership application has been submitted successfully.', 'iei-membership') . '</p>';
        echo '<p>' . esc_html__('What happens next:', 'iei-membership') . '</p>';
        echo '<ul>';
        echo '<li>' . esc_html__('Our pre-approval officer will review your application and attachments.', 'iei-membership') . '</li>';
        echo '<li>' . esc_html__('If pre-approved, your application is sent to the board for director voting.', 'iei-membership') . '</li>';
        echo '<li>' . esc_html__('If approved, you will receive an email with account setup and payment instructions.', 'iei-membership') . '</li>';
        echo '<li>' . esc_html__('After payment is receipted, your membership is activated and you can access the member portal.', 'iei-membership') . '</li>';
        echo '</ul>';
        echo '</div>';

        return (string) ob_get_clean();
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

        if ((string) ($input['address_line_1'] ?? '') === '') {
            $errors[] = __('Address Line 1 is required.', 'iei-membership');
        }

        if ((string) ($input['suburb'] ?? '') === '') {
            $errors[] = __('Suburb is required.', 'iei-membership');
        }

        $state = (string) ($input['state'] ?? '');
        if (! isset($this->australian_states()[$state])) {
            $errors[] = __('Please select a valid Australian state.', 'iei-membership');
        }

        if ((string) ($input['postcode'] ?? '') === '') {
            $errors[] = __('Postcode is required.', 'iei-membership');
        }

        if ((string) ($input['phone'] ?? '') === '' && (string) ($input['mobile'] ?? '') === '') {
            $errors[] = __('Please provide at least a phone or mobile number.', 'iei-membership');
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

        if ($nominationStatus === 'nominated_by_member') {
            if ((string) ($input['nominating_member_number'] ?? '') === '') {
                $errors[] = __('Nominating member number is required when nominated by a member.', 'iei-membership');
            }
            if ((string) ($input['nominating_member_name'] ?? '') === '') {
                $errors[] = __('Nominating member name is required when nominated by a member.', 'iei-membership');
            }
        }

        if ((string) ($input['signature_text'] ?? '') === '') {
            $errors[] = __('Signature is required.', 'iei-membership');
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
                'applicant_middle_name' => sanitize_text_field((string) ($input['middle_name'] ?? '')),
                'applicant_last_name' => sanitize_text_field((string) $input['last_name']),
                'address_line_1' => sanitize_text_field((string) ($input['address_line_1'] ?? '')),
                'address_line_2' => sanitize_text_field((string) ($input['address_line_2'] ?? '')),
                'suburb' => sanitize_text_field((string) ($input['suburb'] ?? '')),
                'state' => sanitize_key((string) ($input['state'] ?? '')),
                'postcode' => sanitize_text_field((string) ($input['postcode'] ?? '')),
                'phone' => sanitize_text_field((string) ($input['phone'] ?? '')),
                'mobile' => sanitize_text_field((string) ($input['mobile'] ?? '')),
                'employer' => sanitize_text_field((string) ($input['employer'] ?? '')),
                'job_position' => sanitize_text_field((string) ($input['job_position'] ?? '')),
                'nomination_status' => sanitize_key((string) ($input['nomination_status'] ?? 'self_nominated')),
                'nominating_member_number' => sanitize_text_field((string) ($input['nominating_member_number'] ?? '')),
                'nominating_member_name' => sanitize_text_field((string) ($input['nominating_member_name'] ?? '')),
                'signature_text' => sanitize_text_field((string) ($input['signature_text'] ?? '')),
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

        $reviewUrl = add_query_arg(
            [
                'page' => 'iei-membership-applications',
                'application_id' => $applicationId,
            ],
            admin_url('admin.php')
        );

        $subjectTemplate = 'New membership application #{application_id}';
        $bodyTemplate = "A new membership application requires pre-approval.\n\n"
            . "Application ID: {application_id}\n"
            . "Applicant: {first_name} {last_name}\n"
            . "Email: {email}\n"
            . "Membership type: {membership_type}\n"
            . "Review URL: {review_url}\n";

        $tokens = [
            '{application_id}' => (string) $applicationId,
            '{first_name}' => (string) ($input['first_name'] ?? ''),
            '{last_name}' => (string) ($input['last_name'] ?? ''),
            '{email}' => (string) ($input['email'] ?? ''),
            '{membership_type}' => (string) ($input['membership_type'] ?? ''),
            '{review_url}' => $reviewUrl,
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
            'self_nominated' => __('Self nominated', 'iei-membership'),
            'nominated_by_member' => __('Nominated by member', 'iei-membership'),
        ];
    }

    private function australian_states(): array
    {
        return [
            'ACT' => __('ACT', 'iei-membership'),
            'NSW' => __('NSW', 'iei-membership'),
            'NT' => __('NT', 'iei-membership'),
            'QLD' => __('QLD', 'iei-membership'),
            'SA' => __('SA', 'iei-membership'),
            'TAS' => __('TAS', 'iei-membership'),
            'VIC' => __('VIC', 'iei-membership'),
            'WA' => __('WA', 'iei-membership'),
        ];
    }

    private function current_url(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';

        return esc_url_raw($scheme . $host . $requestUri);
    }
}
