<?php

namespace IEI\Membership\Controllers;

use IEI\Membership\Services\FileStorageService;
use IEI\Membership\Services\RolesManager;

/**
 * Serves protected application files through authorized streaming endpoint.
 */
class FileController
{
    private FileStorageService $fileStorageService;

    public function __construct(FileStorageService $fileStorageService)
    {
        $this->fileStorageService = $fileStorageService;
    }

    public function register_hooks(): void
    {
        add_action('admin_post_iei_membership_stream_file', [$this, 'stream_file']);
    }

    /**
     * Validate request, authorize user, then stream a protected file response.
     */
    public function stream_file(): void
    {
        if (! is_user_logged_in()) {
            auth_redirect();
        }

        $fileId = absint($_GET['file_id'] ?? 0);
        if ($fileId <= 0) {
            wp_die(esc_html__('Invalid file request.', 'iei-membership'), 400);
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (! wp_verify_nonce($nonce, 'iei_membership_stream_file_' . $fileId)) {
            wp_die(esc_html__('Invalid file nonce.', 'iei-membership'), 403);
        }

        $file = $this->fileStorageService->get_file_record($fileId);
        if (! $file) {
            wp_die(esc_html__('File not found.', 'iei-membership'), 404);
        }

        if (! $this->can_current_user_access_file($file)) {
            wp_die(esc_html__('You do not have permission to access this file.', 'iei-membership'), 403);
        }

        $absolutePath = $this->fileStorageService->get_absolute_file_path((string) $file['storage_filename']);
        if (! file_exists($absolutePath) || ! is_readable($absolutePath)) {
            wp_die(esc_html__('Stored file is missing.', 'iei-membership'), 404);
        }

        $mimeType = $this->normalize_mime((string) ($file['mime_type'] ?? 'application/octet-stream'));
        $originalFilename = sanitize_file_name((string) ($file['original_filename'] ?? basename($absolutePath)));
        $extension = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
        $disposition = $this->resolve_disposition($mimeType, $extension);
        $fileSize = (int) filesize($absolutePath);

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Content-Type: ' . $mimeType, true);
        header('Content-Length: ' . max(0, $fileSize), true);
        header('Content-Disposition: ' . $disposition . '; filename="' . $originalFilename . '"', true);
        header('Content-Transfer-Encoding: binary', true);

        $this->stream_from_disk($absolutePath);
        exit;
    }

    private function can_current_user_access_file(array $file): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        if (current_user_can(RolesManager::CAP_PREAPPROVE_APPLICATIONS)) {
            return true;
        }

        if (current_user_can(RolesManager::CAP_DIRECTOR_VOTE)) {
            return $this->is_director_assigned_to_application((int) ($file['application_id'] ?? 0), get_current_user_id());
        }

        return false;
    }

    private function is_director_assigned_to_application(int $applicationId, int $directorUserId): bool
    {
        if ($applicationId <= 0 || $directorUserId <= 0) {
            return false;
        }

        global $wpdb;

        $votesTable = $wpdb->prefix . 'iei_application_votes';
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$votesTable} WHERE application_id = %d AND director_user_id = %d LIMIT 1",
                $applicationId,
                $directorUserId
            )
        );

        return ! empty($exists);
    }

    private function resolve_disposition(string $mimeType, string $extension): string
    {
        if ($extension === 'doc' || $extension === 'docx') {
            return 'attachment';
        }

        if ($mimeType === 'application/pdf' || strpos($mimeType, 'image/') === 0) {
            return 'inline';
        }

        return 'attachment';
    }

    private function normalize_mime(string $mimeType): string
    {
        $mimeType = trim($mimeType);
        if ($mimeType === '') {
            return 'application/octet-stream';
        }

        return sanitize_mime_type($mimeType) ?: 'application/octet-stream';
    }

    private function stream_from_disk(string $path): void
    {
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            wp_die(esc_html__('Unable to open file for reading.', 'iei-membership'), 500);
        }

        while (! feof($handle)) {
            echo fread($handle, 8192);
            @flush();
        }

        fclose($handle);
    }
}
