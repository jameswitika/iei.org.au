<?php

namespace IEI\Membership\Controllers\Frontend;

use IEI\Membership\Services\ActivityLogger;
use IEI\Membership\Services\PayPalGatewayService;
use IEI\Membership\Services\PaymentActivationService;
use IEI\Membership\Services\RolesManager;
use IEI\Membership\Services\StripeGatewayService;

/**
 * Frontend payment portal supporting Stripe Checkout, PayPal Smart Buttons, and bank transfer.
 */
class MemberPaymentPortalController
{
    private PaymentActivationService $paymentActivationService;
    private StripeGatewayService $stripeGatewayService;
    private PayPalGatewayService $payPalGatewayService;
    private ActivityLogger $activityLogger;

    public function __construct(
        PaymentActivationService $paymentActivationService,
        StripeGatewayService $stripeGatewayService,
        PayPalGatewayService $payPalGatewayService,
        ActivityLogger $activityLogger
    ) {
        $this->paymentActivationService = $paymentActivationService;
        $this->stripeGatewayService = $stripeGatewayService;
        $this->payPalGatewayService = $payPalGatewayService;
        $this->activityLogger = $activityLogger;
    }

    public function register_hooks(): void
    {
        add_shortcode('iei_member_payment_portal', [$this, 'render_shortcode']);
        add_action('admin_post_iei_stripe_create_checkout', [$this, 'handle_stripe_checkout_post']);
        add_action('wp_ajax_iei_paypal_create_order', [$this, 'handle_paypal_create_order']);
        add_action('wp_ajax_iei_paypal_capture_order', [$this, 'handle_paypal_capture_order']);
        add_action('init', [$this, 'maybe_handle_webhook_request']);
    }

    public function render_shortcode(): string
    {
        if (! is_user_logged_in()) {
            $loginUrl = wp_login_url($this->current_url());
            return '<p>' . esc_html__('Please log in to access your payment portal.', 'iei-membership') . ' <a href="' . esc_url($loginUrl) . '">' . esc_html__('Log in', 'iei-membership') . '</a></p>';
        }

        $context = $this->current_payment_context();
        if (isset($context['error'])) {
            return '<p>' . esc_html((string) $context['error']) . '</p>';
        }

        $member = $context['member'];
        $subscription = $context['subscription'];
        $amountDue = $context['amount_due'];
        $settings = $this->settings();
        $methods = $this->enabled_methods($settings);
        $notice = sanitize_key(wp_unslash((string) ($_GET['iei_payment_notice'] ?? '')));

        ob_start();

        echo '<h2>' . esc_html__('Membership Payment Portal', 'iei-membership') . '</h2>';
        $this->render_notice($notice);

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th>' . esc_html__('Subscription Year', 'iei-membership') . '</th><td>' . esc_html((string) $subscription['membership_year']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Status', 'iei-membership') . '</th><td>' . esc_html((string) $subscription['status']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Due Date', 'iei-membership') . '</th><td>' . esc_html((string) $subscription['due_date']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Amount Due', 'iei-membership') . '</th><td><strong>' . esc_html($this->currency($settings)) . ' ' . esc_html(number_format($amountDue, 2)) . '</strong></td></tr>';
        echo '</tbody></table>';

        if ($amountDue <= 0) {
            echo '<p>' . esc_html__('No payment is currently due for your account.', 'iei-membership') . '</p>';
            return (string) ob_get_clean();
        }

        if (empty($methods)) {
            echo '<p>' . esc_html__('No payment methods are currently enabled. Please contact the administrator.', 'iei-membership') . '</p>';
            return (string) ob_get_clean();
        }

        if (in_array('stripe_checkout', $methods, true)) {
            $this->render_stripe_button($subscription);
        }

        if (in_array('paypal_smart_buttons', $methods, true)) {
            $this->render_paypal_buttons($settings, $subscription);
        }

        if (in_array('bank_transfer', $methods, true)) {
            $this->render_bank_transfer($settings);
        }

        return (string) ob_get_clean();
    }

    public function handle_stripe_checkout_post(): void
    {
        if (! is_user_logged_in()) {
            wp_die(esc_html__('You must be logged in.', 'iei-membership'), 403);
        }

        check_admin_referer('iei_stripe_checkout');

        $context = $this->current_payment_context();
        if (isset($context['error'])) {
            $this->redirect_portal('checkout_invalid');
        }

        $settings = $this->settings();
        if (! in_array('stripe_checkout', $this->enabled_methods($settings), true)) {
            $this->redirect_portal('checkout_disabled');
        }

        $member = $context['member'];
        $subscription = $context['subscription'];
        $amountDue = $context['amount_due'];

        try {
            $session = $this->stripeGatewayService->create_checkout_session($settings, [
                'amount_cents' => (int) round($amountDue * 100),
                'currency' => $this->currency($settings),
                'description' => 'IEI Membership Payment',
                'customer_email' => wp_get_current_user()->user_email,
                'success_url' => add_query_arg(['iei_payment_notice' => 'success'], $this->success_url($settings)),
                'cancel_url' => add_query_arg(['iei_payment_notice' => 'cancelled'], $this->cancel_url($settings)),
                'metadata' => [
                    'subscription_id' => (int) $subscription['id'],
                    'member_id' => (int) $member['id'],
                    'wp_user_id' => get_current_user_id(),
                ],
            ]);

            $sessionId = (string) ($session['id'] ?? '');
            $this->record_payment_attempt((int) $member['id'], (int) $subscription['id'], 'stripe', 'stripe', $sessionId, 'pending', $amountDue, $this->currency($settings));

            $redirectUrl = (string) ($session['url'] ?? '');
            if ($redirectUrl === '') {
                $this->redirect_portal('checkout_failed');
            }

            wp_safe_redirect($redirectUrl);
            exit;
        } catch (\Throwable $throwable) {
            $this->activityLogger->log_member_event((int) $member['id'], 'stripe_checkout_create_failed', [
                'subscription_id' => (int) $subscription['id'],
            ], get_current_user_id(), (int) ($member['application_id'] ?? 0));
            $this->redirect_portal('checkout_failed');
        }
    }

    public function handle_paypal_create_order(): void
    {
        if (! is_user_logged_in()) {
            wp_send_json_error(['message' => 'Not authenticated'], 403);
        }

        if (! check_ajax_referer('iei_paypal_portal', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $context = $this->current_payment_context();
        if (isset($context['error'])) {
            wp_send_json_error(['message' => (string) $context['error']], 400);
        }

        $settings = $this->settings();
        if (! in_array('paypal_smart_buttons', $this->enabled_methods($settings), true)) {
            wp_send_json_error(['message' => 'PayPal is disabled'], 400);
        }

        $member = $context['member'];
        $subscription = $context['subscription'];
        $amountDue = $context['amount_due'];

        try {
            $order = $this->payPalGatewayService->create_order($settings, [
                'amount' => $amountDue,
                'currency' => $this->currency($settings),
                'invoice_id' => 'IEI-SUB-' . (int) $subscription['id'],
                'custom_id' => (string) ((int) $subscription['id']),
                'description' => 'IEI Membership Payment',
                'return_url' => $this->success_url($settings),
                'cancel_url' => $this->cancel_url($settings),
            ]);

            $orderId = (string) ($order['id'] ?? '');
            if ($orderId === '') {
                wp_send_json_error(['message' => 'Unable to create order'], 500);
            }

            $this->record_payment_attempt((int) $member['id'], (int) $subscription['id'], 'paypal', 'paypal', $orderId, 'pending', $amountDue, $this->currency($settings));

            wp_send_json_success(['id' => $orderId]);
        } catch (\Throwable $throwable) {
            $this->activityLogger->log_member_event((int) $member['id'], 'paypal_order_create_failed', [
                'subscription_id' => (int) $subscription['id'],
            ], get_current_user_id(), (int) ($member['application_id'] ?? 0));
            wp_send_json_error(['message' => 'Unable to create PayPal order'], 500);
        }
    }

    public function handle_paypal_capture_order(): void
    {
        if (! is_user_logged_in()) {
            wp_send_json_error(['message' => 'Not authenticated'], 403);
        }

        if (! check_ajax_referer('iei_paypal_portal', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $orderId = sanitize_text_field(wp_unslash((string) ($_POST['order_id'] ?? '')));
        if ($orderId === '') {
            wp_send_json_error(['message' => 'Order ID is required'], 400);
        }

        $context = $this->current_payment_context();
        if (isset($context['error'])) {
            wp_send_json_error(['message' => (string) $context['error']], 400);
        }

        $settings = $this->settings();
        $member = $context['member'];

        try {
            $capture = $this->payPalGatewayService->capture_order($settings, $orderId);
            $status = strtoupper((string) ($capture['status'] ?? ''));
            if ($status !== 'COMPLETED') {
                $this->record_gateway_failure((int) $member['id'], 'paypal_capture_not_completed', ['order_id' => $orderId]);
                wp_send_json_error(['message' => 'Payment was not completed'], 400);
            }

            $details = $this->extract_paypal_capture_details($capture);
            if ((int) $details['subscription_id'] <= 0) {
                $details = $this->hydrate_paypal_subscription_from_order($settings, $orderId, $details);
            }

            $result = $this->process_verified_gateway_payment(
                (int) $details['subscription_id'],
                (float) $details['amount'],
                (string) $details['currency'],
                'paypal',
                (string) $details['reference'],
                get_current_user_id(),
                ['source' => 'paypal_capture']
            );

            if (! $result['ok']) {
                wp_send_json_error(['message' => $result['message']], 400);
            }

            wp_send_json_success(['status' => 'paid']);
        } catch (\Throwable $throwable) {
            $this->record_gateway_failure((int) $member['id'], 'paypal_capture_failed', ['order_id' => $orderId]);
            wp_send_json_error(['message' => 'Unable to capture PayPal order'], 500);
        }
    }

    public function maybe_handle_webhook_request(): void
    {
        $provider = sanitize_key(wp_unslash((string) ($_GET['iei_webhook'] ?? '')));
        if (! in_array($provider, ['stripe', 'paypal'], true)) {
            return;
        }

        if ($provider === 'stripe') {
            $this->handle_stripe_webhook();
        }

        $this->handle_paypal_webhook();
    }

    private function handle_stripe_webhook(): void
    {
        $settings = $this->settings();
        $payload = file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? (string) $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
        $event = $this->stripeGatewayService->construct_verified_event($settings, (string) $payload, $signature);

        if (! is_array($event)) {
            $this->json_response(['ok' => false], 400);
        }

        if ((string) ($event['type'] ?? '') !== 'checkout.session.completed') {
            $this->json_response(['ok' => true], 200);
        }

        $session = isset($event['data']['object']) && is_array($event['data']['object']) ? $event['data']['object'] : [];
        $metadata = isset($session['metadata']) && is_array($session['metadata']) ? $session['metadata'] : [];

        $subscriptionId = absint($metadata['subscription_id'] ?? 0);
        $paymentStatus = (string) ($session['payment_status'] ?? '');
        if ($subscriptionId <= 0 || $paymentStatus !== 'paid') {
            $this->json_response(['ok' => true], 200);
        }

        $amount = ((int) ($session['amount_total'] ?? 0)) / 100;
        $currency = strtoupper((string) ($session['currency'] ?? $this->currency($settings)));
        $reference = (string) ($session['id'] ?? '');

        $this->process_verified_gateway_payment($subscriptionId, $amount, $currency, 'stripe', $reference, null, ['source' => 'stripe_webhook']);
        $this->json_response(['ok' => true], 200);
    }

    private function handle_paypal_webhook(): void
    {
        $settings = $this->settings();
        $payload = (string) file_get_contents('php://input');
        $event = json_decode($payload, true);

        if (! is_array($event)) {
            $this->json_response(['ok' => false], 400);
        }

        $headers = $this->paypal_headers_from_server();
        if (! $this->payPalGatewayService->verify_webhook($settings, $headers, $event)) {
            $this->json_response(['ok' => false], 400);
        }

        if ((string) ($event['event_type'] ?? '') !== 'PAYMENT.CAPTURE.COMPLETED') {
            $this->json_response(['ok' => true], 200);
        }

        $resource = isset($event['resource']) && is_array($event['resource']) ? $event['resource'] : [];
        $details = [
            'subscription_id' => absint($resource['custom_id'] ?? 0),
            'amount' => (float) ($resource['amount']['value'] ?? 0),
            'currency' => strtoupper((string) ($resource['amount']['currency_code'] ?? $this->currency($settings))),
            'reference' => (string) ($resource['id'] ?? ''),
        ];

        if ((int) $details['subscription_id'] <= 0) {
            $invoiceId = (string) ($resource['invoice_id'] ?? '');
            $details['subscription_id'] = $this->subscription_id_from_invoice($invoiceId);
        }

        if ((int) $details['subscription_id'] <= 0) {
            $orderId = (string) ($resource['supplementary_data']['related_ids']['order_id'] ?? '');
            if ($orderId !== '') {
                $details = $this->hydrate_paypal_subscription_from_order($settings, $orderId, $details);
            }
        }

        if ((int) $details['subscription_id'] > 0) {
            $this->process_verified_gateway_payment(
                (int) $details['subscription_id'],
                (float) $details['amount'],
                (string) $details['currency'],
                'paypal',
                (string) $details['reference'],
                null,
                ['source' => 'paypal_webhook']
            );
        }

        $this->json_response(['ok' => true], 200);
    }

    private function process_verified_gateway_payment(
        int $subscriptionId,
        float $amount,
        string $currency,
        string $gateway,
        string $gatewayReference,
        ?int $actorUserId,
        array $context
    ): array {
        $subscription = $this->get_subscription($subscriptionId);
        if (! $subscription) {
            return ['ok' => false, 'message' => 'Subscription not found'];
        }

        $member = $this->get_member((int) $subscription['member_id']);
        if (! $member) {
            return ['ok' => false, 'message' => 'Member not found'];
        }

        $expected = max(0.0, (float) $subscription['amount_due'] - (float) $subscription['amount_paid']);
        if ($expected > 0 && abs($expected - $amount) > 0.01) {
            $this->activityLogger->log_member_event((int) $member['id'], 'payment_amount_mismatch', [
                'subscription_id' => $subscriptionId,
                'expected' => $expected,
                'received' => $amount,
                'gateway' => $gateway,
                'gateway_reference' => $gatewayReference,
                'source' => (string) ($context['source'] ?? ''),
            ], $actorUserId, (int) ($member['application_id'] ?? 0));

            $this->record_payment_attempt((int) $member['id'], $subscriptionId, $gateway, $gateway, $gatewayReference, 'failed', $amount, $currency);
            return ['ok' => false, 'message' => 'Amount mismatch'];
        }

        $reference = strtoupper($gateway) . ':' . $gatewayReference;
        $this->paymentActivationService->mark_subscription_paid($subscriptionId, $actorUserId, $reference, [
            'payment_method' => $gateway,
            'gateway' => $gateway,
            'gateway_transaction_id' => $gatewayReference,
            'currency' => $currency,
        ]);

        return ['ok' => true, 'message' => 'Paid'];
    }

    private function render_stripe_button(array $subscription): void
    {
        echo '<h3>' . esc_html__('Pay by card (Stripe)', 'iei-membership') . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('iei_stripe_checkout');
        echo '<input type="hidden" name="action" value="iei_stripe_create_checkout" />';
        echo '<input type="hidden" name="subscription_id" value="' . esc_attr((string) ((int) $subscription['id'])) . '" />';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Pay by card (Stripe)', 'iei-membership') . '</button>';
        echo '</form>';
    }

    private function render_paypal_buttons(array $settings, array $subscription): void
    {
        $clientId = $this->payPalGatewayService->client_id($settings);
        if ($clientId === '') {
            echo '<p>' . esc_html__('PayPal is enabled but not configured.', 'iei-membership') . '</p>';
            return;
        }

        $currency = $this->currency($settings);
        $funding = isset($settings['paypal_allowed_funding']) && is_array($settings['paypal_allowed_funding'])
            ? array_values(array_filter(array_map('sanitize_key', $settings['paypal_allowed_funding'])))
            : [];
        $style = json_decode((string) ($settings['paypal_button_style'] ?? '{}'), true);
        if (! is_array($style)) {
            $style = [];
        }

        $sdkUrl = add_query_arg([
            'client-id' => $clientId,
            'currency' => $currency,
            'intent' => 'capture',
        ], 'https://www.paypal.com/sdk/js');

        echo '<h3>' . esc_html__('Pay with PayPal', 'iei-membership') . '</h3>';
        echo '<div id="iei-paypal-buttons"></div>';
        echo '<script src="' . esc_url($sdkUrl) . '"></script>';
        echo '<script>'
            . 'window.ieiPaypalConfig = ' . wp_json_encode([
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('iei_paypal_portal'),
                'subscriptionId' => (int) $subscription['id'],
                'allowedFunding' => $funding,
                'style' => $style,
            ]) . ';'
            . '(function(){'
            . 'if(!window.paypal || !window.ieiPaypalConfig){return;}'
            . 'var cfg = window.ieiPaypalConfig;'
            . 'var options = {'
            . 'createOrder: function(){'
            . 'return fetch(cfg.ajaxUrl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams({action:"iei_paypal_create_order",nonce:cfg.nonce,subscription_id:String(cfg.subscriptionId)})}).then(function(r){return r.json();}).then(function(data){if(!data.success){throw new Error((data.data&&data.data.message)||"Order create failed");} return data.data.id;});'
            . '},'
            . 'onApprove: function(data){'
            . 'return fetch(cfg.ajaxUrl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams({action:"iei_paypal_capture_order",nonce:cfg.nonce,order_id:String(data.orderID),subscription_id:String(cfg.subscriptionId)})}).then(function(r){return r.json();}).then(function(res){if(!res.success){throw new Error((res.data&&res.data.message)||"Capture failed");} window.location.href=window.location.href.split("?")[0]+"?iei_payment_notice=success";});'
            . '},'
            . 'onError: function(){ window.location.href=window.location.href.split("?")[0]+"?iei_payment_notice=paypal_error"; }'
            . '};'
            . 'if(cfg.style && Object.keys(cfg.style).length){ options.style = cfg.style; }'
            . 'if(cfg.allowedFunding && cfg.allowedFunding.length){ options.enableFunding = cfg.allowedFunding; }'
            . 'paypal.Buttons(options).render("#iei-paypal-buttons");'
            . '})();'
            . '</script>';
    }

    private function render_bank_transfer(array $settings): void
    {
        echo '<h3>' . esc_html__('Bank transfer', 'iei-membership') . '</h3>';
        if (! empty($settings['bank_transfer_instructions'])) {
            echo '<p>' . nl2br(esc_html((string) $settings['bank_transfer_instructions'])) . '</p>';
        } else {
            echo '<p>' . esc_html__('Bank transfer instructions will be provided by the administrator.', 'iei-membership') . '</p>';
        }
        echo '<p>' . esc_html__('Your membership will be activated after payment is confirmed by an administrator.', 'iei-membership') . '</p>';
    }

    private function render_notice(string $notice): void
    {
        $map = [
            'success' => __('Payment received. Your membership will update shortly.', 'iei-membership'),
            'cancelled' => __('Payment was cancelled.', 'iei-membership'),
            'checkout_failed' => __('Unable to start checkout. Please try again.', 'iei-membership'),
            'checkout_invalid' => __('No payable subscription was found.', 'iei-membership'),
            'checkout_disabled' => __('Stripe checkout is not enabled.', 'iei-membership'),
            'paypal_error' => __('PayPal payment could not be completed.', 'iei-membership'),
        ];

        if (! isset($map[$notice])) {
            return;
        }

        echo '<div class="iei-membership-notice iei-membership-notice-info"><p>' . esc_html($map[$notice]) . '</p></div>';
    }

    private function current_payment_context(): array
    {
        $userId = get_current_user_id();
        $member = $this->get_member_by_user_id($userId);

        if (! $this->can_access_portal($member)) {
            return ['error' => __('You do not have access to this payment portal.', 'iei-membership')];
        }

        if (! $member) {
            return ['error' => __('No member record found for your account.', 'iei-membership')];
        }

        $subscription = $this->get_next_due_subscription((int) $member['id']);
        if (! $subscription) {
            return ['error' => __('No pending payment was found for your account.', 'iei-membership')];
        }

        $amountDue = max(0.0, (float) $subscription['amount_due'] - (float) $subscription['amount_paid']);

        return [
            'member' => $member,
            'subscription' => $subscription,
            'amount_due' => $amountDue,
        ];
    }

    private function can_access_portal(?array $member): bool
    {
        $user = wp_get_current_user();
        if ($user instanceof \WP_User && in_array('iei_pending_payment', (array) $user->roles, true)) {
            return true;
        }

        if (! $member) {
            return false;
        }

        return in_array((string) ($member['status'] ?? ''), ['pending_payment', 'lapsed'], true);
    }

    private function enabled_methods(array $settings): array
    {
        $configured = isset($settings['payments_methods_enabled']) && is_array($settings['payments_methods_enabled'])
            ? $settings['payments_methods_enabled']
            : [];

        $methods = array_values(array_unique(array_filter(array_map('sanitize_key', $configured), static function (string $method): bool {
            return in_array($method, ['stripe_checkout', 'paypal_smart_buttons', 'bank_transfer'], true);
        })));

        if ((bool) ($settings['bank_transfer_enabled'] ?? false) && ! in_array('bank_transfer', $methods, true)) {
            $methods[] = 'bank_transfer';
        }

        return $methods;
    }

    private function record_payment_attempt(int $memberId, int $subscriptionId, string $method, string $gateway, string $reference, string $status, float $amount, string $currency): void
    {
        global $wpdb;

        $now = current_time('mysql');
        $paymentsTable = $wpdb->prefix . 'iei_payments';

        if ($reference !== '') {
            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$paymentsTable} WHERE gateway_transaction_id = %s LIMIT 1",
                    $reference
                )
            );

            if ($existing) {
                $wpdb->update(
                    $paymentsTable,
                    [
                        'status' => $status,
                        'amount' => $amount,
                        'currency' => strtoupper($currency),
                        'updated_at' => $now,
                    ],
                    ['id' => (int) $existing],
                    ['%s', '%f', '%s', '%s'],
                    ['%d']
                );
                return;
            }
        }

        $member = $this->get_member($memberId);
        $wpdb->insert(
            $paymentsTable,
            [
                'member_id' => $memberId,
                'subscription_id' => $subscriptionId,
                'application_id' => isset($member['application_id']) ? (int) $member['application_id'] : null,
                'amount' => $amount,
                'currency' => strtoupper($currency),
                'payment_method' => sanitize_key($method),
                'gateway' => sanitize_key($gateway),
                'gateway_transaction_id' => $reference !== '' ? sanitize_text_field($reference) : null,
                'status' => sanitize_key($status),
                'reference' => sanitize_text_field($reference),
                'received_at' => $status === 'paid' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    private function record_gateway_failure(int $memberId, string $eventType, array $context): void
    {
        $member = $this->get_member($memberId);
        $this->activityLogger->log_member_event($memberId, $eventType, $context, get_current_user_id(), (int) ($member['application_id'] ?? 0));
    }

    private function extract_paypal_capture_details(array $capture): array
    {
        $purchaseUnit = isset($capture['purchase_units'][0]) && is_array($capture['purchase_units'][0]) ? $capture['purchase_units'][0] : [];
        $payments = isset($purchaseUnit['payments']['captures'][0]) && is_array($purchaseUnit['payments']['captures'][0])
            ? $purchaseUnit['payments']['captures'][0]
            : [];

        $subscriptionId = absint($purchaseUnit['custom_id'] ?? 0);
        if ($subscriptionId <= 0) {
            $subscriptionId = $this->subscription_id_from_invoice((string) ($purchaseUnit['invoice_id'] ?? ''));
        }

        return [
            'subscription_id' => $subscriptionId,
            'amount' => (float) ($payments['amount']['value'] ?? ($purchaseUnit['amount']['value'] ?? 0)),
            'currency' => strtoupper((string) ($payments['amount']['currency_code'] ?? ($purchaseUnit['amount']['currency_code'] ?? 'AUD'))),
            'reference' => (string) ($payments['id'] ?? ($capture['id'] ?? '')),
        ];
    }

    private function hydrate_paypal_subscription_from_order(array $settings, string $orderId, array $details): array
    {
        $order = $this->payPalGatewayService->get_order($settings, $orderId);
        $purchaseUnit = isset($order['purchase_units'][0]) && is_array($order['purchase_units'][0]) ? $order['purchase_units'][0] : [];

        if ((int) ($details['subscription_id'] ?? 0) <= 0) {
            $details['subscription_id'] = absint($purchaseUnit['custom_id'] ?? 0);
        }
        if ((int) ($details['subscription_id'] ?? 0) <= 0) {
            $details['subscription_id'] = $this->subscription_id_from_invoice((string) ($purchaseUnit['invoice_id'] ?? ''));
        }
        if ((float) ($details['amount'] ?? 0) <= 0) {
            $details['amount'] = (float) ($purchaseUnit['amount']['value'] ?? 0);
        }
        if ((string) ($details['currency'] ?? '') === '') {
            $details['currency'] = strtoupper((string) ($purchaseUnit['amount']['currency_code'] ?? 'AUD'));
        }

        return $details;
    }

    private function subscription_id_from_invoice(string $invoiceId): int
    {
        if (preg_match('/IEI-SUB-(\d+)/', $invoiceId, $matches)) {
            return absint($matches[1]);
        }

        return 0;
    }

    private function get_member_by_user_id(int $userId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}iei_members WHERE wp_user_id = %d LIMIT 1", $userId),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function get_member(int $memberId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}iei_members WHERE id = %d LIMIT 1", $memberId),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function get_next_due_subscription(int $memberId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$wpdb->prefix}iei_subscriptions
                 WHERE member_id = %d
                   AND status IN ('pending_payment', 'overdue', 'lapsed')
                 ORDER BY due_date ASC, id ASC
                 LIMIT 1",
                $memberId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function get_subscription(int $subscriptionId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}iei_subscriptions WHERE id = %d LIMIT 1", $subscriptionId),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function settings(): array
    {
        $settings = get_option(IEI_MEMBERSHIP_OPTION_KEY, []);
        if (! is_array($settings)) {
            $settings = [];
        }

        return array_merge(iei_membership_default_settings(), $settings);
    }

    private function currency(array $settings): string
    {
        $currency = strtoupper((string) ($settings['payments_currency'] ?? 'AUD'));
        return $currency !== '' ? $currency : 'AUD';
    }

    private function success_url(array $settings): string
    {
        $pageId = absint($settings['payment_success_page_id'] ?? 0);
        if ($pageId > 0) {
            $url = get_permalink($pageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return $this->current_url();
    }

    private function cancel_url(array $settings): string
    {
        $pageId = absint($settings['payment_cancel_page_id'] ?? 0);
        if ($pageId > 0) {
            $url = get_permalink($pageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return $this->current_url();
    }

    private function redirect_portal(string $notice): void
    {
        $url = add_query_arg(['iei_payment_notice' => sanitize_key($notice)], $this->current_url());
        wp_safe_redirect($url);
        exit;
    }

    private function paypal_headers_from_server(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_PAYPAL_') !== 0) {
                continue;
            }

            $normalized = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$normalized] = (string) $value;
        }

        return $headers;
    }

    private function json_response(array $payload, int $status = 200): void
    {
        if (! headers_sent()) {
            status_header($status);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo wp_json_encode($payload);
        exit;
    }

    private function current_url(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';

        return esc_url_raw($scheme . $host . $requestUri);
    }
}
