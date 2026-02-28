<?php

namespace IEI\Membership\Controllers\Admin;

use IEI\Membership\Services\RolesManager;

class SettingsPage
{
    /**
     * Single settings controller for IEI Membership plugin options.
     *
     * Values are stored as one option array and normalized through sanitize().
     */
    private string $optionKey;
    private string $group = 'iei_membership_settings_group';
    private string $slug = 'iei-membership-settings';

    public function __construct(string $optionKey)
    {
        $this->optionKey = $optionKey;
    }

    public function register_hooks(): void
    {
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function get_slug(): string
    {
        return $this->slug;
    }

    public function get_title(): string
    {
        return __('Settings', 'iei-membership');
    }

    public function render(): void
    {
        if (! current_user_can(RolesManager::CAP_MANAGE_SETTINGS)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'iei-membership'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('IEI Membership Settings', 'iei-membership') . '</h1>';
        echo '<form method="post" action="options.php">';

        settings_fields($this->group);
        do_settings_sections($this->slug);
        submit_button(__('Save Settings', 'iei-membership'));

        echo '</form>';
        echo '</div>';
    }

    public function register_settings(): void
    {
        register_setting($this->group, $this->optionKey, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => iei_membership_default_settings(),
        ]);

        add_settings_section(
            'iei_membership_general',
            __('General Configuration', 'iei-membership'),
            static function (): void {
                echo '<p>' . esc_html__('Core thresholds, pricing, storage, and payment settings.', 'iei-membership') . '</p>';
            },
            $this->slug
        );

        add_settings_section(
            'iei_membership_payments',
            __('Payments', 'iei-membership'),
            static function (): void {
                echo '<p>' . esc_html__('Configure Stripe Checkout, PayPal Smart Buttons, and bank transfer options.', 'iei-membership') . '</p>';
            },
            $this->slug
        );

        $fields = [
            'approval_threshold' => ['Approval threshold', 'number'],
            'rejection_threshold' => ['Rejection threshold', 'number'],
            'grace_period_days' => ['Grace period (days)', 'number'],
            'prorata_cutoff_days' => ['Pro-rata cutoff (days)', 'number'],
            'director_dashboard_page_id' => ['Director dashboard page', 'page_select'],
            'application_thank_you_page_id' => ['Application thank you page', 'page_select'],
            'member_payment_portal_page_id' => ['Membership payment portal page', 'page_select'],
            'member_home_page_id' => ['Member home page', 'page_select'],
            'next_membership_number' => ['Next membership number', 'number'],
            'price_associate' => ['Price: Associate', 'text'],
            'price_corporate' => ['Price: Corporate', 'text'],
            'price_senior' => ['Price: Senior', 'text'],
            'protected_storage_dir' => ['Protected storage directory', 'text'],
            'allowed_mime_types' => ['Allowed mime types (comma-separated extensions)', 'text'],
        ];

        foreach ($fields as $key => [$label, $type]) {
            add_settings_field(
                $key,
                __($label, 'iei-membership'),
                [$this, 'render_field'],
                $this->slug,
                'iei_membership_general',
                [
                    'key' => $key,
                    'type' => $type,
                ]
            );
        }

        $paymentFields = [
            'payments_mode' => ['Payments mode', 'select'],
            'payments_currency' => ['Payments currency', 'text'],
            'payments_methods_enabled' => ['Enabled payment methods', 'checkboxes'],
            'payment_success_page_id' => ['Payment success page', 'page_select'],
            'payment_cancel_page_id' => ['Payment cancel page', 'page_select'],
            'stripe_webhook_url_display' => ['Stripe webhook endpoint', 'webhook_display'],
            'paypal_webhook_url_display' => ['PayPal webhook endpoint', 'webhook_display'],
            'stripe_test_secret_key' => ['Stripe test secret key', 'text'],
            'stripe_test_webhook_signing_secret' => ['Stripe test webhook signing secret', 'text'],
            'stripe_live_secret_key' => ['Stripe live secret key', 'text'],
            'stripe_live_webhook_signing_secret' => ['Stripe live webhook signing secret', 'text'],
            'paypal_sandbox_client_id' => ['PayPal sandbox client ID', 'text'],
            'paypal_sandbox_client_secret' => ['PayPal sandbox client secret', 'text'],
            'paypal_sandbox_webhook_id' => ['PayPal sandbox webhook ID', 'text'],
            'paypal_live_client_id' => ['PayPal live client ID', 'text'],
            'paypal_live_client_secret' => ['PayPal live client secret', 'text'],
            'paypal_live_webhook_id' => ['PayPal live webhook ID', 'text'],
            'paypal_allowed_funding' => ['PayPal allowed funding (comma-separated)', 'text'],
            'paypal_button_style' => ['PayPal button style JSON', 'textarea'],
            'bank_transfer_instructions' => ['Bank transfer instructions', 'textarea'],
        ];

        foreach ($paymentFields as $key => [$label, $type]) {
            add_settings_field(
                $key,
                __($label, 'iei-membership'),
                [$this, 'render_field'],
                $this->slug,
                'iei_membership_payments',
                [
                    'key' => $key,
                    'type' => $type,
                ]
            );
        }
    }

    public function render_field(array $args): void
    {
        $settings = $this->get_settings();
        $key = (string) ($args['key'] ?? '');
        $type = (string) ($args['type'] ?? 'text');

        if ($key === '') {
            return;
        }

        $fieldName = $this->optionKey . '[' . $key . ']';

        if (strpos($key, 'price_') === 0) {
            $priceMap = [
                'price_associate' => 'associate',
                'price_corporate' => 'corporate',
                'price_senior' => 'senior',
            ];

            $priceKey = $priceMap[$key] ?? '';
            $value = $priceKey !== '' ? (string) ($settings['membership_type_prices'][$priceKey] ?? '') : '';
            $fieldName = $this->optionKey . '[' . $key . ']';

            printf(
                '<input type="text" class="regular-text" name="%1$s" value="%2$s" />',
                esc_attr($fieldName),
                esc_attr($value)
            );
            return;
        }

        $value = $settings[$key] ?? '';

        if ($key === 'allowed_mime_types' && is_array($value)) {
            $value = implode(', ', $value);
        }

        if ($key === 'payments_methods_enabled' && is_array($value)) {
            $value = array_values(array_unique(array_map('sanitize_key', $value)));
        }

        if ($key === 'paypal_allowed_funding' && is_array($value)) {
            $value = implode(', ', $value);
        }

        if ($type === 'webhook_display') {
            $hookType = strpos($key, 'stripe_') === 0 ? 'stripe' : 'paypal';
            $url = $this->webhook_url($hookType);
            echo '<code>' . esc_html($url) . '</code>';
            return;
        }

        if ($type === 'select' && $key === 'payments_mode') {
            echo '<select name="' . esc_attr($fieldName) . '">';
            echo '<option value="test" ' . selected((string) $value, 'test', false) . '>' . esc_html__('Test / Sandbox', 'iei-membership') . '</option>';
            echo '<option value="live" ' . selected((string) $value, 'live', false) . '>' . esc_html__('Live / Production', 'iei-membership') . '</option>';
            echo '</select>';
            return;
        }

        if ($type === 'checkboxes' && $key === 'payments_methods_enabled') {
            $selectedMethods = is_array($value) ? $value : [];
            $methods = [
                'stripe_checkout' => __('Stripe Checkout', 'iei-membership'),
                'paypal_smart_buttons' => __('PayPal Smart Buttons', 'iei-membership'),
                'bank_transfer' => __('Bank transfer', 'iei-membership'),
            ];

            echo '<fieldset>';
            foreach ($methods as $methodKey => $methodLabel) {
                $inputId = 'iei_payment_method_' . $methodKey;
                echo '<label for="' . esc_attr($inputId) . '" style="display:block;margin:4px 0;">';
                echo '<input type="checkbox" id="' . esc_attr($inputId) . '" name="' . esc_attr($fieldName) . '[]" value="' . esc_attr($methodKey) . '" ' . checked(in_array($methodKey, $selectedMethods, true), true, false) . ' /> ';
                echo esc_html($methodLabel);
                echo '</label>';
            }
            echo '</fieldset>';
            return;
        }

        if ($type === 'checkbox') {
            printf(
                '<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
                esc_attr($fieldName),
                checked((bool) $value, true, false),
                esc_html__('Yes', 'iei-membership')
            );
            return;
        }

        if ($type === 'page_select') {
            $pages = get_pages([
                'sort_column' => 'post_title',
                'sort_order' => 'ASC',
            ]);

            echo '<select name="' . esc_attr($fieldName) . '">';
            echo '<option value="0">' . esc_html__('— Select a page —', 'iei-membership') . '</option>';
            foreach ($pages as $page) {
                echo '<option value="' . esc_attr((string) $page->ID) . '" ' . selected((int) $value, (int) $page->ID, false) . '>' . esc_html($page->post_title) . '</option>';
            }
            echo '</select>';

            return;
        }

        if ($type === 'textarea') {
            printf(
                '<textarea class="large-text" rows="6" name="%1$s">%2$s</textarea>',
                esc_attr($fieldName),
                esc_textarea((string) $value)
            );
            return;
        }

        if ($type === 'number') {
            printf(
                '<input type="number" min="0" class="small-text" name="%1$s" value="%2$s" />',
                esc_attr($fieldName),
                esc_attr((string) $value)
            );
            return;
        }

        printf(
            '<input type="text" class="regular-text" name="%1$s" value="%2$s" />',
            esc_attr($fieldName),
            esc_attr((string) $value)
        );
    }

    /**
     * Sanitize and normalize the full settings payload before persistence.
     *
     * This method is intentionally strict so downstream services can assume
     * numeric fields are bounded and structural keys always exist.
     */
    public function sanitize($input): array
    {
        if (! current_user_can(RolesManager::CAP_MANAGE_SETTINGS)) {
            return $this->get_settings();
        }

        $input = is_array($input) ? $input : [];
        $defaults = iei_membership_default_settings();

        $approvalThreshold = max(1, absint($input['approval_threshold'] ?? $defaults['approval_threshold']));
        $rejectionThreshold = max(1, absint($input['rejection_threshold'] ?? $defaults['rejection_threshold']));
        $gracePeriodDays = max(0, absint($input['grace_period_days'] ?? $defaults['grace_period_days']));
        $prorataCutoffDays = max(0, absint($input['prorata_cutoff_days'] ?? $defaults['prorata_cutoff_days']));
        $directorDashboardPageId = absint($input['director_dashboard_page_id'] ?? $defaults['director_dashboard_page_id']);
        $applicationThankYouPageId = absint($input['application_thank_you_page_id'] ?? $defaults['application_thank_you_page_id']);
        $memberPaymentPortalPageId = absint($input['member_payment_portal_page_id'] ?? $defaults['member_payment_portal_page_id']);
        $memberHomePageId = absint($input['member_home_page_id'] ?? $defaults['member_home_page_id']);
        $paymentSuccessPageId = absint($input['payment_success_page_id'] ?? $defaults['payment_success_page_id']);
        $paymentCancelPageId = absint($input['payment_cancel_page_id'] ?? $defaults['payment_cancel_page_id']);
        $nextMembershipNumber = max(1, absint($input['next_membership_number'] ?? $defaults['next_membership_number']));

        $associatePrice = $this->sanitize_money($input['price_associate'] ?? $defaults['membership_type_prices']['associate']);
        $corporatePrice = $this->sanitize_money($input['price_corporate'] ?? $defaults['membership_type_prices']['corporate']);
        $seniorPrice = $this->sanitize_money($input['price_senior'] ?? $defaults['membership_type_prices']['senior']);

        $storageDir = sanitize_text_field((string) ($input['protected_storage_dir'] ?? $defaults['protected_storage_dir']));
        $storageDir = '/' . trim($storageDir, '/') . '/';

        $mimeRaw = (string) ($input['allowed_mime_types'] ?? implode(', ', $defaults['allowed_mime_types']));
        $allowedMimeTypes = array_values(array_unique(array_filter(array_map(static function ($value): string {
            return sanitize_key(trim((string) $value));
        }, explode(',', $mimeRaw)))));

        if (empty($allowedMimeTypes)) {
            $allowedMimeTypes = $defaults['allowed_mime_types'];
        }

        $paymentsMode = sanitize_key((string) ($input['payments_mode'] ?? $defaults['payments_mode']));
        if (! in_array($paymentsMode, ['test', 'live'], true)) {
            $paymentsMode = 'test';
        }

        $paymentsCurrency = strtoupper(sanitize_text_field((string) ($input['payments_currency'] ?? $defaults['payments_currency'])));
        if ($paymentsCurrency === '') {
            $paymentsCurrency = 'AUD';
        }

        $paymentMethodsEnabled = $this->normalize_payment_methods($input['payments_methods_enabled'] ?? $defaults['payments_methods_enabled']);
        $bankTransferEnabled = in_array('bank_transfer', $paymentMethodsEnabled, true);

        $paypalAllowedFundingRaw = (string) ($input['paypal_allowed_funding'] ?? '');
        $paypalAllowedFunding = array_values(array_unique(array_filter(array_map(static function ($value): string {
            return sanitize_key(trim((string) $value));
        }, explode(',', $paypalAllowedFundingRaw)))));

        $paypalButtonStyle = $this->sanitize_paypal_button_style((string) ($input['paypal_button_style'] ?? '{}'));

        $bankTransferInstructions = sanitize_textarea_field((string) ($input['bank_transfer_instructions'] ?? ''));

        return [
            'approval_threshold' => $approvalThreshold,
            'rejection_threshold' => $rejectionThreshold,
            'grace_period_days' => $gracePeriodDays,
            'prorata_cutoff_days' => $prorataCutoffDays,
            'director_dashboard_page_id' => $directorDashboardPageId,
            'application_thank_you_page_id' => $applicationThankYouPageId,
            'member_payment_portal_page_id' => $memberPaymentPortalPageId,
            'member_home_page_id' => $memberHomePageId,
            'payment_success_page_id' => $paymentSuccessPageId,
            'payment_cancel_page_id' => $paymentCancelPageId,
            'next_membership_number' => $nextMembershipNumber,
            'membership_type_prices' => [
                'associate' => $associatePrice,
                'corporate' => $corporatePrice,
                'senior' => $seniorPrice,
            ],
            'protected_storage_dir' => $storageDir,
            'allowed_mime_types' => $allowedMimeTypes,
            'payments_mode' => $paymentsMode,
            'payments_currency' => $paymentsCurrency,
            'payments_methods_enabled' => $paymentMethodsEnabled,
            'stripe_test_secret_key' => sanitize_text_field((string) ($input['stripe_test_secret_key'] ?? '')),
            'stripe_test_webhook_signing_secret' => sanitize_text_field((string) ($input['stripe_test_webhook_signing_secret'] ?? '')),
            'stripe_live_secret_key' => sanitize_text_field((string) ($input['stripe_live_secret_key'] ?? '')),
            'stripe_live_webhook_signing_secret' => sanitize_text_field((string) ($input['stripe_live_webhook_signing_secret'] ?? '')),
            'paypal_sandbox_client_id' => sanitize_text_field((string) ($input['paypal_sandbox_client_id'] ?? '')),
            'paypal_sandbox_client_secret' => sanitize_text_field((string) ($input['paypal_sandbox_client_secret'] ?? '')),
            'paypal_sandbox_webhook_id' => sanitize_text_field((string) ($input['paypal_sandbox_webhook_id'] ?? '')),
            'paypal_live_client_id' => sanitize_text_field((string) ($input['paypal_live_client_id'] ?? '')),
            'paypal_live_client_secret' => sanitize_text_field((string) ($input['paypal_live_client_secret'] ?? '')),
            'paypal_live_webhook_id' => sanitize_text_field((string) ($input['paypal_live_webhook_id'] ?? '')),
            'paypal_allowed_funding' => $paypalAllowedFunding,
            'paypal_button_style' => $paypalButtonStyle,
            'bank_transfer_enabled' => $bankTransferEnabled,
            'bank_transfer_instructions' => $bankTransferInstructions,
            'active_gateway' => sanitize_key((string) ($defaults['active_gateway'] ?? 'stripe')),
        ];
    }

    /**
     * Return saved settings merged with defaults, including nested price keys.
     */
    private function get_settings(): array
    {
        $saved = get_option($this->optionKey, []);
        if (! is_array($saved)) {
            $saved = [];
        }

        $defaults = iei_membership_default_settings();
        $savedPrices = isset($saved['membership_type_prices']) && is_array($saved['membership_type_prices'])
            ? $saved['membership_type_prices']
            : [];

        $saved['membership_type_prices'] = array_merge($defaults['membership_type_prices'], $savedPrices);

        if (! isset($saved['payments_methods_enabled']) || ! is_array($saved['payments_methods_enabled'])) {
            $saved['payments_methods_enabled'] = $defaults['payments_methods_enabled'];
        }

        if (! isset($saved['paypal_allowed_funding']) || ! is_array($saved['paypal_allowed_funding'])) {
            $saved['paypal_allowed_funding'] = $defaults['paypal_allowed_funding'];
        }

        return array_merge($defaults, $saved);
    }

    private function normalize_payment_methods($value): array
    {
        $value = is_array($value) ? $value : [];
        $allowed = ['stripe_checkout', 'paypal_smart_buttons', 'bank_transfer'];

        $methods = array_values(array_unique(array_filter(array_map(static function ($method): string {
            return sanitize_key((string) $method);
        }, $value), static function (string $method) use ($allowed): bool {
            return in_array($method, $allowed, true);
        })));

        if (empty($methods)) {
            return ['bank_transfer'];
        }

        return $methods;
    }

    private function sanitize_paypal_button_style(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '{}';
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return '{}';
        }

        return (string) wp_json_encode($decoded);
    }

    private function webhook_url(string $provider): string
    {
        return add_query_arg(['iei_webhook' => $provider], home_url('/'));
    }

    private function sanitize_money($value): float
    {
        return max(0.0, round((float) $value, 2));
    }
}
