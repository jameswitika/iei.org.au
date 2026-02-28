<?php

namespace IEI\Membership\Controllers\Admin;

use IEI\Membership\Services\ActivityLogger;
use IEI\Membership\Services\FileStorageService;
use IEI\Membership\Services\RolesManager;

class ApplicationsPage
{
    private string $menuSlug = 'iei-membership-applications';
    private FileStorageService $fileStorageService;
    private ActivityLogger $activityLogger;

    public function __construct(FileStorageService $fileStorageService, ActivityLogger $activityLogger)
    {
        $this->fileStorageService = $fileStorageService;
        $this->activityLogger = $activityLogger;
    }

    public function register_hooks(): void
    {
        add_action('admin_post_iei_membership_application_decision', [$this, 'handle_decision_post']);
        add_action('admin_post_iei_membership_application_reset_vote', [$this, 'handle_reset_vote_post']);
        add_action('admin_post_iei_membership_application_send_reminder', [$this, 'handle_send_reminder_post']);
    }

    public function handle_reset_vote_post(): void
    {
        if (! current_user_can(RolesManager::CAP_PREAPPROVE_APPLICATIONS) && ! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to reset votes.', 'iei-membership'), 403);
        }

        $applicationId = absint($_POST['application_id'] ?? 0);
        $directorUserId = absint($_POST['director_user_id'] ?? 0);

        if ($applicationId <= 0 || $directorUserId <= 0) {
            wp_die(esc_html__('Invalid reset request.', 'iei-membership'), 400);
        }

        check_admin_referer('iei_membership_reset_vote_' . $applicationId . '_' . $directorUserId);

        global $wpdb;
        $votesTable = $wpdb->prefix . 'iei_application_votes';
        $now = current_time('mysql');

        $updated = $wpdb->update(
            $votesTable,
            [
                'vote' => 'unanswered',
                'note' => null,
                'responded_at' => null,
                'voted_at' => null,
                'reset_by_user_id' => get_current_user_id(),
                'reset_at' => $now,
                'updated_at' => $now,
            ],
            [
                'application_id' => $applicationId,
                'director_user_id' => $directorUserId,
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s'],
            ['%d', '%d']
        );

        if ($updated === false) {
            $this->redirect_to_detail($applicationId, 'reset_failed');
        }

        $this->activityLogger->log_application_event($applicationId, 'director_vote_reset', [
            'director_user_id' => $directorUserId,
        ], get_current_user_id());

        $this->redirect_to_detail($applicationId, 'vote_reset');
    }

    public function handle_send_reminder_post(): void
    {
        if (! current_user_can(RolesManager::CAP_MANAGE_APPLICATIONS) && ! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to send reminders.', 'iei-membership'), 403);
        }

        $applicationId = absint($_POST['application_id'] ?? 0);
        if ($applicationId <= 0) {
            wp_die(esc_html__('Invalid reminder request.', 'iei-membership'), 400);
        }

        check_admin_referer('iei_membership_send_reminder_' . $applicationId);

        $application = $this->get_application($applicationId);
        if (! $application) {
            wp_die(esc_html__('Application not found.', 'iei-membership'), 404);
        }

        if ((string) $application['status'] !== 'pending_board_approval') {
            $this->redirect_to_detail($applicationId, 'invalid_status');
        }

        $nonResponders = $this->get_unanswered_director_votes($applicationId);
        $sentCount = 0;
        $total = count($nonResponders);

        $subject = __('Director vote reminder', 'iei-membership');
        $body = sprintf(
            "Reminder: Application #%d is still awaiting your board vote.\nPlease log in and submit your response.",
            $applicationId
        );

        foreach ($nonResponders as $row) {
            $email = (string) ($row['user_email'] ?? '');
            $directorId = (int) ($row['director_user_id'] ?? 0);

            if ($directorId <= 0 || RolesManager::is_director_disabled($directorId)) {
                continue;
            }

            if (! is_email($email)) {
                continue;
            }

            if (wp_mail($email, $subject, $body)) {
                $sentCount++;
            }
        }

        $this->activityLogger->log_application_event($applicationId, 'director_reminder_sent', [
            'non_responder_count' => $total,
            'sent_count' => $sentCount,
        ], get_current_user_id());

        $this->redirect_to_detail($applicationId, 'reminder_sent');
    }

    public function render(): void
    {
        $this->assert_list_access();

        $applicationId = absint($_GET['application_id'] ?? 0);
        if ($applicationId > 0) {
            $this->render_detail($applicationId);
            return;
        }

        $this->render_list();
    }

    public function handle_decision_post(): void
    {
        if (! current_user_can(RolesManager::CAP_PREAPPROVE_APPLICATIONS) && ! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'iei-membership'), 403);
        }

        $applicationId = absint($_POST['application_id'] ?? 0);
        $decision = sanitize_key(wp_unslash((string) ($_POST['decision'] ?? '')));
        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['_wpnonce'] ?? '')));

        if ($applicationId <= 0 || ! in_array($decision, ['preapprove', 'reject'], true)) {
            wp_die(esc_html__('Invalid application action request.', 'iei-membership'), 400);
        }

        if (! wp_verify_nonce($nonce, 'iei_membership_decision_' . $applicationId)) {
            wp_die(esc_html__('Invalid nonce.', 'iei-membership'), 403);
        }

        $application = $this->get_application($applicationId);
        if (! $application) {
            wp_die(esc_html__('Application not found.', 'iei-membership'), 404);
        }

        if ((string) $application['status'] !== 'pending_preapproval') {
            $this->redirect_to_detail($applicationId, 'invalid_status');
        }

        try {
            if ($decision === 'preapprove') {
                $this->preapprove_application($applicationId, $application);
                $this->redirect_to_detail($applicationId, 'preapproved');
            }

            $this->reject_application($applicationId);
            $this->redirect_to_detail($applicationId, 'rejected');
        } catch (\Throwable $throwable) {
            error_log('[IEI Membership] Application moderation failed: ' . $throwable->getMessage());
            $this->redirect_to_detail($applicationId, 'action_failed');
        }
    }

    private function render_list(): void
    {
        $status = sanitize_key(wp_unslash((string) ($_GET['status'] ?? '')));
        $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));

        $applications = $this->query_applications($status, $search);
        $counts = $this->status_counts();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Applications', 'iei-membership') . '</h1>';

        $this->render_notice();
        $this->render_filter_links($status, $counts);
        $this->render_search_form($status, $search);

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Applicant', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Email', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Membership Type', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Status', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Submitted', 'iei-membership') . '</th>';
        echo '<th>' . esc_html__('Actions', 'iei-membership') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($applications)) {
            echo '<tr><td colspan="7">' . esc_html__('No applications found.', 'iei-membership') . '</td></tr>';
        } else {
            foreach ($applications as $application) {
                $detailUrl = $this->detail_url((int) $application['id']);
                $name = trim((string) $application['applicant_first_name'] . ' ' . (string) $application['applicant_last_name']);

                echo '<tr>';
                echo '<td>' . esc_html((string) $application['id']) . '</td>';
                echo '<td>' . esc_html($name) . '</td>';
                echo '<td>' . esc_html((string) $application['applicant_email']) . '</td>';
                echo '<td>' . esc_html(ucfirst((string) $application['membership_type'])) . '</td>';
                echo '<td>' . esc_html((string) $application['status']) . '</td>';
                echo '<td>' . esc_html((string) $application['submitted_at']) . '</td>';
                echo '<td><a class="button button-small" href="' . esc_url($detailUrl) . '">' . esc_html__('View', 'iei-membership') . '</a></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function render_detail(int $applicationId): void
    {
        $application = $this->get_application($applicationId);
        if (! $application) {
            wp_die(esc_html__('Application not found.', 'iei-membership'), 404);
        }

        $files = $this->get_application_files($applicationId);
        $activity = $this->get_application_activity($applicationId);
        $votes = $this->get_application_votes($applicationId);
        $canModerate = current_user_can(RolesManager::CAP_PREAPPROVE_APPLICATIONS) || current_user_can('manage_options');

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Application Detail', 'iei-membership') . ' #' . esc_html((string) $application['id']) . '</h1>';
        echo '<p><a href="' . esc_url($this->list_url()) . '">&larr; ' . esc_html__('Back to Applications', 'iei-membership') . '</a></p>';

        $this->render_notice();

        echo '<table class="form-table" role="presentation"><tbody>';
        $this->render_detail_row(__('First Name', 'iei-membership'), (string) $application['applicant_first_name']);
        $this->render_detail_row(__('Last Name', 'iei-membership'), (string) $application['applicant_last_name']);
        $this->render_detail_row(__('Email', 'iei-membership'), (string) $application['applicant_email']);
        $this->render_detail_row(__('Membership Type', 'iei-membership'), ucfirst((string) $application['membership_type']));
        $this->render_detail_row(__('Status', 'iei-membership'), (string) $application['status']);
        $this->render_detail_row(__('Submitted At', 'iei-membership'), (string) $application['submitted_at']);
        $this->render_detail_row(__('Public Token', 'iei-membership'), (string) $application['public_token']);
        echo '</tbody></table>';

        if ($canModerate && (string) $application['status'] === 'pending_preapproval') {
            echo '<hr />';
            echo '<h2>' . esc_html__('Actions', 'iei-membership') . '</h2>';
            echo '<div style="display:flex; gap:10px;">';
            $this->render_action_form($applicationId, 'preapprove', __('Pre-Approve', 'iei-membership'), 'button button-primary');
            $this->render_action_form($applicationId, 'reject', __('Reject', 'iei-membership'), 'button');
            echo '</div>';
        }

        if ((string) $application['status'] === 'pending_board_approval') {
            echo '<hr />';
            echo '<h2>' . esc_html__('Board Actions', 'iei-membership') . '</h2>';
            $this->render_send_reminder_form($applicationId);
        }

        echo '<hr />';
        echo '<h2>' . esc_html__('Director Votes', 'iei-membership') . '</h2>';
        if (empty($votes)) {
            echo '<p>' . esc_html__('No director votes found.', 'iei-membership') . '</p>';
        } else {
            echo '<table class="widefat fixed striped"><thead><tr>';
            echo '<th>' . esc_html__('Director', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Vote', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Responded At', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Reset At', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Reset By', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Action', 'iei-membership') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($votes as $vote) {
                $directorId = (int) ($vote['director_user_id'] ?? 0);
                $canResetVote = $canModerate && (string) $application['status'] === 'pending_board_approval' && (string) ($vote['vote'] ?? '') !== 'unanswered';

                echo '<tr>';
                echo '<td>' . esc_html((string) ($vote['display_name'] ?: $vote['user_email'] ?: ('User #' . $directorId))) . '</td>';
                echo '<td>' . esc_html((string) ($vote['vote'] ?? 'unanswered')) . '</td>';
                echo '<td>' . esc_html((string) (($vote['responded_at'] ?? '') ?: '-')) . '</td>';
                echo '<td>' . esc_html((string) (($vote['reset_at'] ?? '') ?: '-')) . '</td>';
                echo '<td>' . esc_html((string) (($vote['reset_by_display_name'] ?? '') ?: '-')) . '</td>';
                echo '<td>';
                if ($canResetVote) {
                    $this->render_reset_vote_form($applicationId, $directorId);
                } else {
                    echo '-';
                }
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '<hr />';
        echo '<h2>' . esc_html__('Files', 'iei-membership') . '</h2>';
        if (empty($files)) {
            echo '<p>' . esc_html__('No files uploaded.', 'iei-membership') . '</p>';
        } else {
            foreach ($files as $file) {
                $this->render_file_preview($file);
            }
        }

        echo '<hr />';
        echo '<h2>' . esc_html__('Activity Timeline', 'iei-membership') . '</h2>';
        if (empty($activity)) {
            echo '<p>' . esc_html__('No activity yet.', 'iei-membership') . '</p>';
        } else {
            echo '<table class="widefat fixed striped"><thead><tr>';
            echo '<th>' . esc_html__('When', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Event', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Actor', 'iei-membership') . '</th>';
            echo '<th>' . esc_html__('Context', 'iei-membership') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($activity as $entry) {
                $context = (string) ($entry['event_context'] ?? '');
                $decoded = json_decode($context, true);
                $contextDisplay = is_array($decoded) ? wp_json_encode($decoded) : $context;

                echo '<tr>';
                echo '<td>' . esc_html((string) $entry['created_at']) . '</td>';
                echo '<td>' . esc_html((string) $entry['event_type']) . '</td>';
                echo '<td>' . esc_html((string) ($entry['actor_user_id'] ?? '-')) . '</td>';
                echo '<td><code>' . esc_html((string) $contextDisplay) . '</code></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }

    private function preapprove_application(int $applicationId, array $application): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'iei_applications';
        $updated = $wpdb->update(
            $table,
            [
                'status' => 'pending_board_approval',
                'preapproval_officer_user_id' => get_current_user_id(),
                'preapproval_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            [
                'id' => $applicationId,
            ],
            [
                '%s',
                '%d',
                '%s',
                '%s',
            ],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('Failed to pre-approve application.');
        }

        $this->activityLogger->log_application_event($applicationId, 'application_preapproved', [
            'status' => 'pending_board_approval',
        ], get_current_user_id());

        $directors = $this->get_directors();
        $voteRows = $this->create_or_reset_vote_rows($applicationId, $directors);

        $this->activityLogger->log_application_event($applicationId, 'director_votes_prepared', [
            'director_count' => count($directors),
            'vote_rows_written' => $voteRows,
        ], get_current_user_id());

        $sentCount = $this->notify_directors_for_board_review($applicationId, $application, $directors);

        $this->activityLogger->log_application_event($applicationId, 'board_review_notification_sent', [
            'director_count' => count($directors),
            'sent_count' => $sentCount,
        ], get_current_user_id());
    }

    private function reject_application(int $applicationId): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'iei_applications';
        $updated = $wpdb->update(
            $table,
            [
                'status' => 'rejected_preapproval',
                'preapproval_officer_user_id' => get_current_user_id(),
                'preapproval_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            [
                'id' => $applicationId,
            ],
            [
                '%s',
                '%d',
                '%s',
                '%s',
            ],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('Failed to reject application.');
        }

        $this->activityLogger->log_application_event($applicationId, 'application_rejected_preapproval', [
            'status' => 'rejected_preapproval',
        ], get_current_user_id());
    }

    private function create_or_reset_vote_rows(int $applicationId, array $directors): int
    {
        global $wpdb;

        if (empty($directors)) {
            return 0;
        }

        $table = $wpdb->prefix . 'iei_application_votes';
        $now = current_time('mysql');
        $written = 0;

        foreach ($directors as $director) {
            $directorId = (int) $director->ID;

                $sql = "INSERT INTO {$table} (application_id, director_user_id, vote, viewed_at, last_viewed_at, responded_at, reset_by_user_id, reset_at, voted_at, note, created_at, updated_at)
                    VALUES (%d, %d, %s, NULL, NULL, NULL, NULL, NULL, NULL, NULL, %s, %s)
                    ON DUPLICATE KEY UPDATE vote = VALUES(vote), viewed_at = NULL, last_viewed_at = NULL, responded_at = NULL, reset_by_user_id = NULL, reset_at = NULL, voted_at = NULL, note = NULL, updated_at = VALUES(updated_at)";

            $result = $wpdb->query(
                $wpdb->prepare(
                    $sql,
                    $applicationId,
                    $directorId,
                    'unanswered',
                    $now,
                    $now
                )
            );

            if ($result !== false) {
                $written++;
            }
        }

        return $written;
    }

    private function notify_directors_for_board_review(int $applicationId, array $application, array $directors): int
    {
        if (empty($directors)) {
            return 0;
        }

        $subjectTemplate = 'Board review required: application #{application_id}';
        $bodyTemplate = "A membership application is now pending board approval.\n\n"
            . "Application ID: {application_id}\n"
            . "Applicant: {first_name} {last_name}\n"
            . "Membership type: {membership_type}\n"
            . "Review URL: {review_url}\n";

        $reviewUrl = $this->detail_url($applicationId);
        $tokens = [
            '{application_id}' => (string) $applicationId,
            '{first_name}' => (string) ($application['applicant_first_name'] ?? ''),
            '{last_name}' => (string) ($application['applicant_last_name'] ?? ''),
            '{membership_type}' => (string) ($application['membership_type'] ?? ''),
            '{review_url}' => $reviewUrl,
        ];

        $subject = strtr($subjectTemplate, $tokens);
        $body = strtr($bodyTemplate, $tokens);
        $sent = 0;

        foreach ($directors as $director) {
            $email = (string) ($director->user_email ?? '');
            if (! is_email($email)) {
                continue;
            }

            if (wp_mail($email, $subject, $body)) {
                $sent++;
            }
        }

        return $sent;
    }

    private function get_directors(): array
    {
        $directors = get_users([
            'role' => 'iei_director',
            'fields' => ['ID', 'user_email'],
        ]);

        if (! is_array($directors)) {
            return [];
        }

        return array_values(array_filter($directors, static function ($director): bool {
            return ! RolesManager::is_director_disabled((int) $director->ID);
        }));
    }

    private function query_applications(string $status, string $search): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'iei_applications';
        $where = ['1=1'];
        $params = [];

        if ($status !== '' && in_array($status, $this->statuses(), true)) {
            $where[] = 'status = %s';
            $params[] = $status;
        }

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(applicant_email LIKE %s OR applicant_first_name LIKE %s OR applicant_last_name LIKE %s OR public_token LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT id, public_token, applicant_email, applicant_first_name, applicant_last_name, membership_type, status, submitted_at
                FROM {$table}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY submitted_at DESC, id DESC
                LIMIT 200";

        if (! empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private function get_application(int $applicationId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'iei_applications';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $applicationId),
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

    private function get_application_activity(int $applicationId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'iei_activity_log';
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id, actor_user_id, event_type, event_context, created_at FROM {$table} WHERE application_id = %d ORDER BY created_at DESC, id DESC", $applicationId),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    private function get_application_votes(int $applicationId): array
    {
        global $wpdb;

        $votesTable = $wpdb->prefix . 'iei_application_votes';
        $usersTable = $wpdb->users;

        $sql = "SELECT v.*, u.user_email, u.display_name,
                       reset_user.display_name AS reset_by_display_name
                FROM {$votesTable} v
                LEFT JOIN {$usersTable} u ON u.ID = v.director_user_id
                LEFT JOIN {$usersTable} reset_user ON reset_user.ID = v.reset_by_user_id
                WHERE v.application_id = %d
                ORDER BY u.display_name ASC, v.id ASC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $applicationId), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private function get_unanswered_director_votes(int $applicationId): array
    {
        global $wpdb;

        $votesTable = $wpdb->prefix . 'iei_application_votes';
        $usersTable = $wpdb->users;

        $sql = "SELECT v.director_user_id, u.user_email
                FROM {$votesTable} v
                INNER JOIN {$usersTable} u ON u.ID = v.director_user_id
                WHERE v.application_id = %d
                  AND v.vote = %s";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $applicationId, 'unanswered'),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    private function status_counts(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'iei_applications';
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A);

        $counts = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $counts[(string) $row['status']] = (int) $row['total'];
            }
        }

        return $counts;
    }

    private function render_filter_links(string $activeStatus, array $counts): void
    {
        echo '<ul class="subsubsub">';

        $allCount = array_sum($counts);
        $allClass = $activeStatus === '' ? ' class="current"' : '';
        echo '<li><a' . $allClass . ' href="' . esc_url($this->list_url()) . '">' . esc_html__('All', 'iei-membership') . ' <span class="count">(' . esc_html((string) $allCount) . ')</span></a> | </li>';

        $statuses = $this->statuses();
        $lastKey = array_key_last($statuses);
        foreach ($statuses as $status) {
            $class = $activeStatus === $status ? ' class="current"' : '';
            $url = add_query_arg(['status' => $status], $this->list_url());
            $count = $counts[$status] ?? 0;
            echo '<li><a' . $class . ' href="' . esc_url($url) . '">' . esc_html($status) . ' <span class="count">(' . esc_html((string) $count) . ')</span></a>';
            if ($status !== $lastKey) {
                echo ' | ';
            }
            echo '</li>';
        }

        echo '</ul>';
    }

    private function render_search_form(string $status, string $search): void
    {
        echo '<form method="get" style="margin:12px 0 16px;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($this->menuSlug) . '" />';
        if ($status !== '') {
            echo '<input type="hidden" name="status" value="' . esc_attr($status) . '" />';
        }
        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Search applicant/email/token', 'iei-membership') . '" /> ';
        echo '<button type="submit" class="button">' . esc_html__('Search', 'iei-membership') . '</button>';
        echo '</form>';
    }

    private function render_notice(): void
    {
        $updated = sanitize_key(wp_unslash((string) ($_GET['updated'] ?? '')));
        if ($updated === '') {
            return;
        }

        $messages = [
            'preapproved' => __('Application pre-approved and sent for board review.', 'iei-membership'),
            'rejected' => __('Application rejected.', 'iei-membership'),
            'invalid_status' => __('Application action is not allowed in the current status.', 'iei-membership'),
            'action_failed' => __('Application action failed. Please review logs and try again.', 'iei-membership'),
            'vote_reset' => __('Director vote reset to unanswered.', 'iei-membership'),
            'reset_failed' => __('Could not reset the director vote.', 'iei-membership'),
            'reminder_sent' => __('Reminder emails sent to non-responders.', 'iei-membership'),
        ];

        if (! isset($messages[$updated])) {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$updated]) . '</p></div>';
    }

    private function render_detail_row(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    private function render_action_form(int $applicationId, string $decision, string $buttonLabel, string $buttonClass): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="iei_membership_application_decision" />';
        echo '<input type="hidden" name="application_id" value="' . esc_attr((string) $applicationId) . '" />';
        echo '<input type="hidden" name="decision" value="' . esc_attr($decision) . '" />';
        wp_nonce_field('iei_membership_decision_' . $applicationId);
        echo '<button class="' . esc_attr($buttonClass) . '" type="submit">' . esc_html($buttonLabel) . '</button>';
        echo '</form>';
    }

    private function render_reset_vote_form(int $applicationId, int $directorUserId): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="iei_membership_application_reset_vote" />';
        echo '<input type="hidden" name="application_id" value="' . esc_attr((string) $applicationId) . '" />';
        echo '<input type="hidden" name="director_user_id" value="' . esc_attr((string) $directorUserId) . '" />';
        wp_nonce_field('iei_membership_reset_vote_' . $applicationId . '_' . $directorUserId);
        echo '<button class="button button-small" type="submit">' . esc_html__('Reset to unanswered', 'iei-membership') . '</button>';
        echo '</form>';
    }

    private function render_send_reminder_form(int $applicationId): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:14px;">';
        echo '<input type="hidden" name="action" value="iei_membership_application_send_reminder" />';
        echo '<input type="hidden" name="application_id" value="' . esc_attr((string) $applicationId) . '" />';
        wp_nonce_field('iei_membership_send_reminder_' . $applicationId);
        echo '<button class="button button-secondary" type="submit">' . esc_html__('Send reminder to non-responders', 'iei-membership') . '</button>';
        echo '</form>';
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

    private function statuses(): array
    {
        return [
            'pending_preapproval',
            'rejected_preapproval',
            'pending_board_approval',
            'approved',
            'rejected_board',
            'payment_pending',
            'paid_active',
        ];
    }

    private function list_url(): string
    {
        return admin_url('admin.php?page=' . $this->menuSlug);
    }

    private function detail_url(int $applicationId): string
    {
        return add_query_arg(['application_id' => $applicationId], $this->list_url());
    }

    private function redirect_to_detail(int $applicationId, string $updated): void
    {
        $url = add_query_arg(
            [
                'application_id' => $applicationId,
                'updated' => $updated,
            ],
            $this->list_url()
        );

        wp_safe_redirect($url);
        exit;
    }

    private function assert_list_access(): void
    {
        if (! current_user_can(RolesManager::CAP_MANAGE_APPLICATIONS) && ! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access applications.', 'iei-membership'), 403);
        }
    }
}
