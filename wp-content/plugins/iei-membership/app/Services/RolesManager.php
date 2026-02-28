<?php

namespace IEI\Membership\Services;

class RolesManager
{
    public const DIRECTOR_DISABLED_META_KEY = 'iei_director_disabled';

    public const CAP_MANAGE_MEMBERSHIP = 'iei_manage_membership';
    public const CAP_MANAGE_SETTINGS = 'iei_manage_settings';
    public const CAP_MANAGE_APPLICATIONS = 'iei_manage_applications';
    public const CAP_MANAGE_DIRECTORS = 'iei_manage_directors';
    public const CAP_MANAGE_MEMBERS = 'iei_manage_members';
    public const CAP_MANAGE_SUBSCRIPTIONS = 'iei_manage_subscriptions';
    public const CAP_MANAGE_PAYMENTS = 'iei_manage_payments';
    public const CAP_VIEW_ACTIVITY_LOG = 'iei_view_activity_log';
    public const CAP_IMPORT_MEMBERS = 'iei_import_members';
    public const CAP_PREAPPROVE_APPLICATIONS = 'iei_preapprove_applications';
    public const CAP_DIRECTOR_VOTE = 'iei_director_vote';
    public const CAP_ACCESS_MEMBER_PORTAL = 'iei_access_member_portal';
    public const CAP_ACCESS_PAYMENT_PORTAL = 'iei_access_payment_portal';

    public static function register_roles_and_capabilities(): void
    {
        $allManagementCaps = self::management_capabilities();

        add_role('iei_preapproval_officer', __('IEI Pre-Approval Officer', 'iei-membership'), [
            'read' => true,
            self::CAP_PREAPPROVE_APPLICATIONS => true,
            self::CAP_MANAGE_MEMBERSHIP => true,
            self::CAP_MANAGE_APPLICATIONS => true,
            self::CAP_MANAGE_PAYMENTS => true,
            self::CAP_VIEW_ACTIVITY_LOG => true,
        ]);

        add_role('iei_director', __('IEI Director', 'iei-membership'), [
            'read' => true,
            self::CAP_DIRECTOR_VOTE => true,
            self::CAP_VIEW_ACTIVITY_LOG => true,
        ]);

        add_role('iei_member', __('IEI Member', 'iei-membership'), [
            'read' => true,
            self::CAP_ACCESS_MEMBER_PORTAL => true,
        ]);

        add_role('iei_pending_payment', __('IEI Pending Payment', 'iei-membership'), [
            'read' => true,
            self::CAP_ACCESS_PAYMENT_PORTAL => true,
        ]);

        self::ensure_role_caps('iei_preapproval_officer', [
            self::CAP_PREAPPROVE_APPLICATIONS,
            self::CAP_MANAGE_MEMBERSHIP,
            self::CAP_MANAGE_APPLICATIONS,
            self::CAP_MANAGE_PAYMENTS,
            self::CAP_VIEW_ACTIVITY_LOG,
        ]);

        self::ensure_role_caps('iei_director', [
            self::CAP_DIRECTOR_VOTE,
            self::CAP_VIEW_ACTIVITY_LOG,
        ]);

        self::ensure_role_caps('iei_member', [
            self::CAP_ACCESS_MEMBER_PORTAL,
        ]);

        self::ensure_role_caps('iei_pending_payment', [
            self::CAP_ACCESS_PAYMENT_PORTAL,
        ]);

        self::ensure_role_caps('administrator', $allManagementCaps);
    }

    public static function register_runtime_filters(): void
    {
        add_filter('user_has_cap', [self::class, 'filter_user_caps_for_disabled_directors'], 10, 4);
    }

    public static function management_capabilities(): array
    {
        return [
            self::CAP_MANAGE_MEMBERSHIP,
            self::CAP_MANAGE_SETTINGS,
            self::CAP_MANAGE_APPLICATIONS,
            self::CAP_MANAGE_DIRECTORS,
            self::CAP_MANAGE_MEMBERS,
            self::CAP_MANAGE_SUBSCRIPTIONS,
            self::CAP_MANAGE_PAYMENTS,
            self::CAP_VIEW_ACTIVITY_LOG,
            self::CAP_IMPORT_MEMBERS,
        ];
    }

    private static function ensure_role_caps(string $roleName, array $caps): void
    {
        $role = get_role($roleName);
        if (! $role) {
            return;
        }

        foreach ($caps as $cap) {
            $role->add_cap($cap);
        }
    }

    public static function is_director_disabled(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return get_user_meta($userId, self::DIRECTOR_DISABLED_META_KEY, true) === '1';
    }

    public static function filter_user_caps_for_disabled_directors(array $allcaps, array $caps, array $args, \WP_User $user): array
    {
        if (! $user instanceof \WP_User || empty($user->ID)) {
            return $allcaps;
        }

        if (! in_array('iei_director', (array) $user->roles, true)) {
            return $allcaps;
        }

        if (! self::is_director_disabled((int) $user->ID)) {
            return $allcaps;
        }

        $allcaps[self::CAP_DIRECTOR_VOTE] = false;
        $allcaps[self::CAP_VIEW_ACTIVITY_LOG] = false;

        return $allcaps;
    }
}
