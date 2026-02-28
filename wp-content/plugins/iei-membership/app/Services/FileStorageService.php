<?php

namespace IEI\Membership\Services;

/**
 * Handles protected file persistence and metadata registration.
 */
class FileStorageService
{
    /**
     * Validate and store an uploaded application file in protected storage.
     */
    public function store_application_file(int $applicationId, array $uploadedFile, string $fileLabel = '', ?int $uploadedByUserId = null): int
    {
        if ($applicationId <= 0) {
            throw new \RuntimeException('Invalid application id.');
        }

        if (! isset($uploadedFile['tmp_name'], $uploadedFile['name'])) {
            throw new \RuntimeException('Invalid upload payload.');
        }

        if (! empty($uploadedFile['error'])) {
            throw new \RuntimeException('Upload failed with error code: ' . (int) $uploadedFile['error']);
        }

        $tmpPath = (string) $uploadedFile['tmp_name'];
        if (! is_uploaded_file($tmpPath)) {
            throw new \RuntimeException('Uploaded file source is invalid.');
        }

        $originalFilename = sanitize_file_name((string) $uploadedFile['name']);
        if ($originalFilename === '') {
            throw new \RuntimeException('Uploaded filename is invalid.');
        }

        $filetype = wp_check_filetype_and_ext($tmpPath, $originalFilename);
        $extension = strtolower((string) ($filetype['ext'] ?? pathinfo($originalFilename, PATHINFO_EXTENSION)));
        $mimeType = (string) ($filetype['type'] ?? 'application/octet-stream');

        if (! $this->is_allowed_extension($extension)) {
            throw new \RuntimeException('This file type is not allowed.');
        }

        $storageDirectory = $this->get_storage_directory();
        $this->ensure_storage_directory($storageDirectory);

        $storageFilename = $this->generate_storage_filename($extension, $storageDirectory);
        $destinationPath = $storageDirectory . DIRECTORY_SEPARATOR . $storageFilename;

        if (! move_uploaded_file($tmpPath, $destinationPath)) {
            throw new \RuntimeException('Failed to move uploaded file to protected storage.');
        }

        $this->harden_file_permissions($destinationPath);

        $fileSize = file_exists($destinationPath) ? (int) filesize($destinationPath) : 0;
        $insertId = $this->insert_file_metadata(
            $applicationId,
            $fileLabel,
            $originalFilename,
            $storageFilename,
            $mimeType,
            $fileSize,
            $uploadedByUserId
        );

        if ($insertId <= 0) {
            @unlink($destinationPath);
            throw new \RuntimeException('Failed to persist file metadata.');
        }

        return $insertId;
    }

    public function get_file_record(int $fileId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'iei_application_files';
        $record = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $fileId),
            ARRAY_A
        );

        return is_array($record) ? $record : null;
    }

    public function get_absolute_file_path(string $storageFilename): string
    {
        return $this->get_storage_directory() . DIRECTORY_SEPARATOR . basename($storageFilename);
    }

    public function get_stream_url(int $fileId): string
    {
        return add_query_arg(
            [
                'action' => 'iei_membership_stream_file',
                'file_id' => $fileId,
                '_wpnonce' => wp_create_nonce('iei_membership_stream_file_' . $fileId),
            ],
            admin_url('admin-post.php')
        );
    }

    private function insert_file_metadata(
        int $applicationId,
        string $fileLabel,
        string $originalFilename,
        string $storageFilename,
        string $mimeType,
        int $fileSize,
        ?int $uploadedByUserId
    ): int {
        global $wpdb;

        $table = $wpdb->prefix . 'iei_application_files';
        $inserted = $wpdb->insert(
            $table,
            [
                'application_id' => $applicationId,
                'file_label' => sanitize_text_field($fileLabel),
                'original_filename' => $originalFilename,
                'storage_filename' => $storageFilename,
                'mime_type' => sanitize_text_field($mimeType),
                'file_size_bytes' => max(0, $fileSize),
                'uploaded_by_user_id' => $uploadedByUserId ?: null,
                'created_at' => current_time('mysql'),
            ],
            [
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
                '%s',
            ]
        );

        if ($inserted === false) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    private function get_storage_directory(): string
    {
        $settings = get_option(IEI_MEMBERSHIP_OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];

        $defaultDir = '/wp-content/protected-folder/iei-membership/';
        $relativeDir = isset($settings['protected_storage_dir'])
            ? (string) $settings['protected_storage_dir']
            : $defaultDir;

        $relativeDir = '/' . trim($relativeDir, '/') . '/';

        return rtrim(ABSPATH, '/\\') . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
    }

    private function ensure_storage_directory(string $directory): void
    {
        if (! is_dir($directory)) {
            wp_mkdir_p($directory);
        }

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new \RuntimeException('Protected storage directory is not writable.');
        }

        $htaccessPath = $directory . DIRECTORY_SEPARATOR . '.htaccess';
        if (! file_exists($htaccessPath)) {
            file_put_contents($htaccessPath, "Deny from all\n");
        }

        $indexPath = $directory . DIRECTORY_SEPARATOR . 'index.php';
        if (! file_exists($indexPath)) {
            file_put_contents($indexPath, "<?php\n// Silence is golden.\n");
        }
    }

    private function generate_storage_filename(string $extension, string $directory): string
    {
        $extension = strtolower(trim($extension));
        $extensionSuffix = $extension !== '' ? '.' . $extension : '';

        do {
            $filename = wp_generate_uuid4() . $extensionSuffix;
            $path = $directory . DIRECTORY_SEPARATOR . $filename;
        } while (file_exists($path));

        return $filename;
    }

    private function is_allowed_extension(string $extension): bool
    {
        $settings = get_option(IEI_MEMBERSHIP_OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];

        $allowed = isset($settings['allowed_mime_types']) && is_array($settings['allowed_mime_types'])
            ? $settings['allowed_mime_types']
            : ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

        $normalized = array_values(array_unique(array_map(static function ($value): string {
            return strtolower(sanitize_key((string) $value));
        }, $allowed)));

        return in_array(strtolower($extension), $normalized, true);
    }

    private function harden_file_permissions(string $filePath): void
    {
        if (function_exists('chmod')) {
            @chmod($filePath, 0640);
        }
    }
}
