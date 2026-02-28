<?php

namespace IEI\Membership\Migrations;

class Migrator
{
    public static function migrate(): void
    {
        global $wpdb;

        if (! isset($wpdb)) {
            return;
        }

        if (self::is_up_to_date($wpdb)) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach (self::get_table_sql($wpdb) as $sql) {
            dbDelta($sql);
        }

        update_option(IEI_MEMBERSHIP_DB_VERSION_OPTION_KEY, IEI_MEMBERSHIP_DB_VERSION, false);
    }

    private static function is_up_to_date(\wpdb $wpdb): bool
    {
        $storedVersion = (string) get_option(IEI_MEMBERSHIP_DB_VERSION_OPTION_KEY, '');

        if ($storedVersion !== IEI_MEMBERSHIP_DB_VERSION) {
            return false;
        }

        foreach (array_keys(self::table_names($wpdb)) as $tableName) {
            $existingTable = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName));
            if ($existingTable !== $tableName) {
                return false;
            }
        }

        return true;
    }

    private static function table_names(\wpdb $wpdb): array
    {
        return [
            $wpdb->prefix . 'iei_applications' => true,
            $wpdb->prefix . 'iei_application_files' => true,
            $wpdb->prefix . 'iei_application_votes' => true,
            $wpdb->prefix . 'iei_members' => true,
            $wpdb->prefix . 'iei_subscriptions' => true,
            $wpdb->prefix . 'iei_payments' => true,
            $wpdb->prefix . 'iei_activity_log' => true,
        ];
    }

    private static function get_table_sql(\wpdb $wpdb): array
    {
        $collate = $wpdb->get_charset_collate();

        $applications = $wpdb->prefix . 'iei_applications';
        $applicationFiles = $wpdb->prefix . 'iei_application_files';
        $applicationVotes = $wpdb->prefix . 'iei_application_votes';
        $members = $wpdb->prefix . 'iei_members';
        $subscriptions = $wpdb->prefix . 'iei_subscriptions';
        $payments = $wpdb->prefix . 'iei_payments';
        $activityLog = $wpdb->prefix . 'iei_activity_log';

        return [
            "CREATE TABLE {$applications} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_token CHAR(36) NOT NULL,
                applicant_email VARCHAR(190) NOT NULL,
                applicant_first_name VARCHAR(100) NOT NULL,
                applicant_last_name VARCHAR(100) NOT NULL,
                employer VARCHAR(190) NULL,
                job_position VARCHAR(190) NULL,
                nomination_status VARCHAR(50) NULL,
                membership_type VARCHAR(50) NOT NULL,
                preapproval_officer_user_id BIGINT UNSIGNED NULL,
                preapproval_at DATETIME NULL,
                board_finalised_at DATETIME NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'pending_preapproval',
                submitted_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uq_public_token (public_token),
                KEY idx_status (status),
                KEY idx_applicant_email (applicant_email),
                KEY idx_submitted_at (submitted_at)
            ) {$collate};",
            "CREATE TABLE {$applicationFiles} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                application_id BIGINT UNSIGNED NOT NULL,
                file_label VARCHAR(120) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                storage_filename VARCHAR(255) NOT NULL,
                mime_type VARCHAR(120) NOT NULL,
                file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uploaded_by_user_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uq_storage_filename (storage_filename),
                KEY idx_application_id (application_id),
                KEY idx_mime_type (mime_type)
            ) {$collate};",
            "CREATE TABLE {$applicationVotes} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                application_id BIGINT UNSIGNED NOT NULL,
                director_user_id BIGINT UNSIGNED NOT NULL,
                vote VARCHAR(20) NOT NULL DEFAULT 'unanswered',
                viewed_at DATETIME NULL,
                last_viewed_at DATETIME NULL,
                responded_at DATETIME NULL,
                reset_by_user_id BIGINT UNSIGNED NULL,
                reset_at DATETIME NULL,
                voted_at DATETIME NULL,
                note TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uq_application_director (application_id, director_user_id),
                KEY idx_vote (vote),
                KEY idx_application_vote (application_id, vote),
                KEY idx_director_user_id (director_user_id)
            ) {$collate};",
            "CREATE TABLE {$members} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_user_id BIGINT UNSIGNED NOT NULL,
                application_id BIGINT UNSIGNED NULL,
                membership_number VARCHAR(40) NULL,
                membership_type VARCHAR(50) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
                approved_at DATETIME NULL,
                activated_at DATETIME NULL,
                lapsed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uq_wp_user_id (wp_user_id),
                UNIQUE KEY uq_membership_number (membership_number),
                KEY idx_status (status),
                KEY idx_application_id (application_id)
            ) {$collate};",
            "CREATE TABLE {$subscriptions} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                member_id BIGINT UNSIGNED NOT NULL,
                membership_year SMALLINT UNSIGNED NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                amount_due DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
                due_date DATE NOT NULL,
                paid_at DATETIME NULL,
                grace_until DATE NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uq_member_year (member_id, membership_year),
                KEY idx_status (status),
                KEY idx_due_date (due_date),
                KEY idx_member_status (member_id, status)
            ) {$collate};",
            "CREATE TABLE {$payments} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                member_id BIGINT UNSIGNED NOT NULL,
                subscription_id BIGINT UNSIGNED NULL,
                application_id BIGINT UNSIGNED NULL,
                amount DECIMAL(10,2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'AUD',
                payment_method VARCHAR(40) NOT NULL DEFAULT 'bank_transfer',
                gateway VARCHAR(40) NULL,
                gateway_transaction_id VARCHAR(190) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                reference VARCHAR(100) NULL,
                received_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uq_gateway_transaction (gateway_transaction_id),
                KEY idx_member_id (member_id),
                KEY idx_subscription_id (subscription_id),
                KEY idx_application_id (application_id),
                KEY idx_status (status),
                KEY idx_received_at (received_at)
            ) {$collate};",
            "CREATE TABLE {$activityLog} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                application_id BIGINT UNSIGNED NULL,
                member_id BIGINT UNSIGNED NULL,
                actor_user_id BIGINT UNSIGNED NULL,
                event_type VARCHAR(80) NOT NULL,
                event_context LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY idx_application_id (application_id),
                KEY idx_member_id (member_id),
                KEY idx_actor_user_id (actor_user_id),
                KEY idx_event_type (event_type),
                KEY idx_created_at (created_at)
            ) {$collate};",
        ];
    }
}
