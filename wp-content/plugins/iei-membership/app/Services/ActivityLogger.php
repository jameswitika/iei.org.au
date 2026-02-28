<?php

namespace IEI\Membership\Services;

/**
 * Centralized writer for application/member/system audit events.
 */
class ActivityLogger
{
    public function log_application_event(int $applicationId, string $eventType, array $context = [], ?int $actorUserId = null): void
    {
        $this->log_event($eventType, $context, $actorUserId, $applicationId, null);
    }

    public function log_member_event(int $memberId, string $eventType, array $context = [], ?int $actorUserId = null, ?int $applicationId = null): void
    {
        $this->log_event($eventType, $context, $actorUserId, $applicationId, $memberId);
    }

    public function log_system_event(string $eventType, array $context = [], ?int $actorUserId = null): void
    {
        $this->log_event($eventType, $context, $actorUserId, null, null);
    }

    /**
     * Persist one normalized event row into the activity log table.
     */
    public function log_event(string $eventType, array $context = [], ?int $actorUserId = null, ?int $applicationId = null, ?int $memberId = null): void
    {
        global $wpdb;

        if ($eventType === '') {
            return;
        }

        $table = $wpdb->prefix . 'iei_activity_log';
        $encodedContext = wp_json_encode($context);

        $wpdb->insert(
            $table,
            [
                'application_id' => $applicationId && $applicationId > 0 ? $applicationId : null,
                'member_id' => $memberId && $memberId > 0 ? $memberId : null,
                'actor_user_id' => $actorUserId,
                'event_type' => sanitize_key($eventType),
                'event_context' => $encodedContext !== false ? $encodedContext : null,
                'created_at' => current_time('mysql'),
            ],
            [
                '%d',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
            ]
        );
    }
}
