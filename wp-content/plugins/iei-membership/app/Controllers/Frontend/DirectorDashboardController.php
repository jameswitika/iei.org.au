<?php

namespace IEI\Membership\Controllers\Frontend;

use IEI\Membership\Services\ActivityLogger;
use IEI\Membership\Services\BoardDecisionService;
use IEI\Membership\Services\FileStorageService;
use IEI\Membership\Services\RolesManager;

class DirectorDashboardController
{
    private const NONCE_ACTION = 'iei_director_vote_submit';

    private FileStorageService $fileStorageService;
    private ActivityLogger $activityLogger;
    private BoardDecisionService $boardDecisionService;

    public function __construct(
        FileStorageService $fileStorageService,
        ActivityLogger $activityLogger,
        BoardDecisionService $boardDecisionService
    )
    {
        $this->fileStorageService = $fileStorageService;
        $this->activityLogger = $activityLogger;
        $this->boardDecisionService = $boardDecisionService;
    }

    public function register_hooks(): void
    {
        add_shortcode('iei_director_dashboard', [$this, 'render_shortcode']);
    }

    public function render_shortcode(): string
    {
        if (! is_user_logged_in()) {
            $loginUrl = wp_login_url($this->current_url());
            return '<p>' . esc_html__('Please log in to access the director dashboard.', 'iei-membership') . ' <a href="' . esc_url($loginUrl) . '">' . esc_html__('Log in', 'iei-membership') . '</a></p>';
        }

        if (! current_user_can(RolesManager::CAP_DIRECTOR_VOTE)) {
            return '<p>' . esc_html__('You do not have access to the director dashboard.', 'iei-membership') . '</p>';
        }

        $this->maybe_handle_vote_submission();

        $applicationId = absint($_GET['application_id'] ?? 0);
        if ($applicationId > 0) {
            return $this->render_detail($applicationId);
        }

        return $this->render_list();
    }

    private function render_list(): string
    {
        $directorId = get_current_user_id();
        $applications = $this->query_pending_applications_for_director($directorId);

        ob_start();

        $this->render_notice();

        echo '<h2>' . esc_html__('Director Dashboard', 'iei-membership') . '</h2>';
        echo '<p>' . esc_html__('Pending board approval applications assigned to you.', 'iei-membership') . '</p>';

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Application', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Name', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Employer', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Job Position', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Nomination Status', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Your Vote', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Actions', 'iei-membership') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($applications)) {
            echo '<tr><td colspan="7">' . esc_html__('No pending applications at the moment.', 'iei-membership') . '</td></tr>';
        } else {
            foreach ($applications as $application) {
                $detailUrl = add_query_arg(['application_id' => (int) $application['id']], $this->current_url());
                $fullName = trim((string) $application['applicant_first_name'] . ' ' . (string) $application['applicant_last_name']);

                echo '<tr>';
                echo '<td>#' . esc_html((string) $application['id']) . '</td>';
                echo '<td>' . esc_html($fullName !== '' ? $fullName : '-') . '</td>';
                echo '<td>' . esc_html((string) ($application['employer'] ?: '-')) . '</td>';
                echo '<td>' . esc_html((string) ($application['job_position'] ?: '-')) . '</td>';
                echo '<td>' . esc_html((string) ($application['nomination_status'] ?: '-')) . '</td>';
                echo '<td>' . esc_html((string) $application['vote']) . '</td>';
                echo '<td><a class="button button-small" href="' . esc_url($detailUrl) . '">' . esc_html__('View', 'iei-membership') . '</a></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        return (string) ob_get_clean();
    }

    private function render_detail(int $applicationId): string
    {
        $directorId = get_current_user_id();
        $application = $this->get_application_for_director($applicationId, $directorId);

        if (! $application) {
            return '<p>' . esc_html__('Application not found or not assigned to you.', 'iei-membership') . '</p>';
        }

        $this->mark_application_viewed($applicationId, $directorId);
        $application = $this->get_application_for_director($applicationId, $directorId) ?? $application;
        $files = $this->get_application_files($applicationId);

        ob_start();

        $this->render_notice();

        echo '<h2>' . esc_html__('Application Review', 'iei-membership') . ' #' . esc_html((string) $application['id']) . '</h2>';
        echo '<p><a href="' . esc_url(remove_query_arg(['application_id', 'updated'], $this->current_url())) . '">&larr; ' . esc_html__('Back to dashboard', 'iei-membership') . '</a></p>';

        echo '<table class="form-table" role="presentation"><tbody>';
        $this->render_detail_row(__('Name', 'iei-membership'), trim((string) $application['applicant_first_name'] . ' ' . (string) $application['applicant_last_name']));
        $this->render_detail_row(__('Email', 'iei-membership'), (string) $application['applicant_email']);
        $this->render_detail_row(__('Employer', 'iei-membership'), (string) ($application['employer'] ?: '-'));
        $this->render_detail_row(__('Job Position', 'iei-membership'), (string) ($application['job_position'] ?: '-'));
        $this->render_detail_row(__('Nomination Status', 'iei-membership'), (string) ($application['nomination_status'] ?: '-'));
        $this->render_detail_row(__('Membership Type', 'iei-membership'), (string) $application['membership_type']);
        $this->render_detail_row(__('Current Vote', 'iei-membership'), (string) $application['vote']);
        $this->render_detail_row(__('First Viewed At', 'iei-membership'), (string) ($application['viewed_at'] ?: '-'));
        $this->render_detail_row(__('Last Viewed At', 'iei-membership'), (string) ($application['last_viewed_at'] ?: '-'));
        $this->render_detail_row(__('Responded At', 'iei-membership'), (string) ($application['responded_at'] ?: '-'));
        echo '</tbody></table>';

        echo '<h3>' . esc_html__('Files', 'iei-membership') . '</h3>';
        if (empty($files)) {
            echo '<p>' . esc_html__('No files uploaded.', 'iei-membership') . '</p>';
        } else {
            foreach ($files as $file) {
                $this->render_file_preview($file);
            }
        }

        echo '<h3>' . esc_html__('Submit Vote', 'iei-membership') . '</h3>';
        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION, '_iei_director_nonce');
        echo '<input type="hidden" name="iei_director_action" value="submit_vote" />';
        echo '<input type="hidden" name="application_id" value="' . esc_attr((string) $applicationId) . '" />';

        echo '<p>';
        echo '<label><input type="radio" name="vote" value="approved" ' . checked((string) $application['vote'], 'approved', false) . ' required /> ' . esc_html__('Approve', 'iei-membership') . '</label><br />';
        echo '<label><input type="radio" name="vote" value="rejected" ' . checked((string) $application['vote'], 'rejected', false) . ' required /> ' . esc_html__('Reject', 'iei-membership') . '</label>';
        echo '</p>';

        echo '<p>';
        echo '<label for="iei_director_comment">' . esc_html__('Comment (optional)', 'iei-membership') . '</label><br />';
        echo '<textarea id="iei_director_comment" name="comment" rows="4">' . esc_textarea((string) ($application['note'] ?? '')) . '</textarea>';
        echo '</p>';

        echo '<p><button type="submit">' . esc_html__('Save Vote', 'iei-membership') . '</button></p>';
        echo '</form>';

        return (string) ob_get_clean();
    }

    private function maybe_handle_vote_submission(): void
    {
        if (! isset($_POST['iei_director_action']) || $_POST['iei_director_action'] !== 'submit_vote') {
            return;
        }

        if (! current_user_can(RolesManager::CAP_DIRECTOR_VOTE)) {
            $this->redirect_with_notice('forbidden', absint($_POST['application_id'] ?? 0));
        }

        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['_iei_director_nonce'] ?? '')));
        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            $this->redirect_with_notice('invalid_nonce', absint($_POST['application_id'] ?? 0));
        }

        $applicationId = absint($_POST['application_id'] ?? 0);
        $vote = sanitize_key(wp_unslash((string) ($_POST['vote'] ?? '')));
        $comment = sanitize_textarea_field(wp_unslash((string) ($_POST['comment'] ?? '')));

        if ($applicationId <= 0 || ! in_array($vote, ['approved', 'rejected'], true)) {
            $this->redirect_with_notice('invalid_vote', $applicationId);
        }

        $directorId = get_current_user_id();
        $application = $this->get_application_for_director($applicationId, $directorId);
        if (! $application) {
            $this->redirect_with_notice('not_found', $applicationId);
        }

        if ((string) $application['status'] !== 'pending_board_approval') {
            $this->redirect_with_notice('invalid_status', $applicationId);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'iei_application_votes';
        $now = current_time('mysql');

        $updated = $wpdb->update(
            $table,
            [
                'vote' => $vote,
                'note' => $comment,
                'voted_at' => $now,
                'responded_at' => $now,
                'updated_at' => $now,
            ],
            [
                'application_id' => $applicationId,
                'director_user_id' => $directorId,
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            ],
            [
                '%d',
                '%d',
            ]
        );

        if ($updated === false) {
            $this->redirect_with_notice('save_failed', $applicationId);
        }

        $this->activityLogger->log_application_event($applicationId, 'director_vote_submitted', [
            'vote' => $vote,
            'has_comment' => $comment !== '',
        ], $directorId);

        $result = $this->boardDecisionService->evaluate_after_vote($applicationId, $directorId);

        if (! empty($result['finalized']) && $result['status'] === 'approved') {
            $this->redirect_with_notice('application_finalized_approved', $applicationId);
        }

        if (! empty($result['finalized']) && $result['status'] === 'rejected_board') {
            $this->redirect_with_notice('application_finalized_rejected', $applicationId);
        }

        $this->redirect_with_notice('vote_saved', $applicationId);
    }

    private function mark_application_viewed(int $applicationId, int $directorId): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'iei_application_votes';
        $now = current_time('mysql');

        $sql = "UPDATE {$table}
                SET viewed_at = IF(viewed_at IS NULL, %s, viewed_at),
                    last_viewed_at = %s,
                    updated_at = %s
                WHERE application_id = %d AND director_user_id = %d";

        $wpdb->query($wpdb->prepare($sql, $now, $now, $now, $applicationId, $directorId));

        $this->activityLogger->log_application_event($applicationId, 'director_application_viewed', [], $directorId);
    }

    private function query_pending_applications_for_director(int $directorId): array
    {
        global $wpdb;

        $applicationsTable = $wpdb->prefix . 'iei_applications';
        $votesTable = $wpdb->prefix . 'iei_application_votes';

        $sql = "SELECT a.id, a.applicant_first_name, a.applicant_last_name, a.employer, a.job_position,
                       a.nomination_status, a.status, v.vote, v.responded_at, v.last_viewed_at
                FROM {$applicationsTable} a
                INNER JOIN {$votesTable} v ON v.application_id = a.id
                WHERE v.director_user_id = %d
                  AND a.status = %s
                ORDER BY a.submitted_at ASC, a.id ASC";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $directorId, 'pending_board_approval'),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    private function get_application_for_director(int $applicationId, int $directorId): ?array
    {
        global $wpdb;

        $applicationsTable = $wpdb->prefix . 'iei_applications';
        $votesTable = $wpdb->prefix . 'iei_application_votes';

        $sql = "SELECT a.*, v.vote, v.note, v.viewed_at, v.last_viewed_at, v.responded_at
                FROM {$applicationsTable} a
                INNER JOIN {$votesTable} v ON v.application_id = a.id
                WHERE a.id = %d AND v.director_user_id = %d
                LIMIT 1";

        $row = $wpdb->get_row(
            $wpdb->prepare($sql, $applicationId, $directorId),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function get_application_files(int $applicationId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'iei_application_files';
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE application_id = %d ORDER BY id ASC", $applicationId),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    private function render_file_preview(array $file): void
    {
        $fileId = (int) ($file['id'] ?? 0);
        if ($fileId <= 0) {
            return;
        }

        $url = $this->fileStorageService->get_stream_url($fileId);
        $filename = (string) ($file['original_filename'] ?? 'file');
        $mimeType = (string) ($file['mime_type'] ?? 'application/octet-stream');
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $isImage = strpos($mimeType, 'image/') === 0;
        $isPdf = $mimeType === 'application/pdf' || $extension === 'pdf';
        $isDoc = in_array($extension, ['doc', 'docx'], true);

        echo '<div style="margin-bottom:18px; padding:12px; border:1px solid #ddd;">';
        echo '<p><strong>' . esc_html($filename) . '</strong> (' . esc_html($mimeType) . ')</p>';
        echo '<p><a class="button button-secondary" target="_blank" href="' . esc_url($url) . '">' . esc_html__('Open File', 'iei-membership') . '</a></p>';

        if ($isImage) {
            echo '<img src="' . esc_url($url) . '" alt="' . esc_attr($filename) . '" style="max-width:100%; height:auto; border:1px solid #eee;" />';
        } elseif ($isPdf) {
            echo '<iframe title="' . esc_attr($filename) . '" src="' . esc_url($url) . '" style="width:100%; min-height:560px; border:1px solid #eee;"></iframe>';
        } elseif ($isDoc) {
            echo '<p>' . esc_html__('Preview is disabled for DOC/DOCX. File opens as download.', 'iei-membership') . '</p>';
        }

        echo '</div>';
    }

    private function render_detail_row(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value !== '' ? $value : '-') . '</td></tr>';
    }

    private function redirect_with_notice(string $notice, int $applicationId = 0): void
    {
        $args = ['updated' => $notice];
        if ($applicationId > 0) {
            $args['application_id'] = $applicationId;
        }

        $url = add_query_arg($args, remove_query_arg(['updated'], $this->current_url()));
        wp_safe_redirect($url);
        exit;
    }

    private function render_notice(): void
    {
        $updated = sanitize_key(wp_unslash((string) ($_GET['updated'] ?? '')));
        if ($updated === '') {
            return;
        }

        $messages = [
            'vote_saved' => __('Your vote has been saved.', 'iei-membership'),
            'application_finalized_approved' => __('Your vote was saved and the application has been approved by threshold.', 'iei-membership'),
            'application_finalized_rejected' => __('Your vote was saved and the application has been rejected by threshold.', 'iei-membership'),
            'invalid_nonce' => __('Invalid token. Please try again.', 'iei-membership'),
            'invalid_vote' => __('Invalid vote request.', 'iei-membership'),
            'not_found' => __('Application not found.', 'iei-membership'),
            'invalid_status' => __('This application is not open for voting.', 'iei-membership'),
            'save_failed' => __('Could not save your vote.', 'iei-membership'),
            'forbidden' => __('You do not have permission to vote.', 'iei-membership'),
        ];

        if (! isset($messages[$updated])) {
            return;
        }

        $isError = in_array($updated, ['invalid_nonce', 'invalid_vote', 'not_found', 'invalid_status', 'save_failed', 'forbidden'], true);
        $class = $isError ? 'iei-membership-notice iei-membership-notice-error' : 'iei-membership-notice iei-membership-notice-success';
        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($messages[$updated]) . '</p></div>';
    }

    private function current_url(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';

        return esc_url_raw($scheme . $host . $requestUri);
    }
}
