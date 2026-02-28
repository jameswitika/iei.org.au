<?php

namespace IEI\Membership\Services;

/**
 * PayPal Orders API wrapper for Smart Buttons flows and webhook verification.
 */
class PayPalGatewayService
{
    public function client_id(array $settings): string
    {
        return $this->credentials($settings)['client_id'];
    }

    public function create_order(array $settings, array $payload): array
    {
        $amount = number_format((float) ($payload['amount'] ?? 0), 2, '.', '');

        $requestBody = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => strtoupper((string) ($payload['currency'] ?? 'AUD')),
                    'value' => $amount,
                ],
                'invoice_id' => (string) ($payload['invoice_id'] ?? ''),
                'custom_id' => (string) ($payload['custom_id'] ?? ''),
                'description' => (string) ($payload['description'] ?? 'IEI Membership Payment'),
            ]],
            'application_context' => [
                'return_url' => (string) ($payload['return_url'] ?? home_url('/')),
                'cancel_url' => (string) ($payload['cancel_url'] ?? home_url('/')),
                'user_action' => 'PAY_NOW',
            ],
        ];

        return $this->request($settings, 'POST', '/v2/checkout/orders', $requestBody);
    }

    public function capture_order(array $settings, string $orderId): array
    {
        return $this->request($settings, 'POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', []);
    }

    public function get_order(array $settings, string $orderId): array
    {
        return $this->request($settings, 'GET', '/v2/checkout/orders/' . rawurlencode($orderId), null);
    }

    public function verify_webhook(array $settings, array $headers, array $event): bool
    {
        $webhookId = $this->webhook_id($settings);
        if ($webhookId === '') {
            return false;
        }

        $verification = [
            'transmission_id' => (string) ($headers['paypal-transmission-id'] ?? ''),
            'transmission_time' => (string) ($headers['paypal-transmission-time'] ?? ''),
            'cert_url' => (string) ($headers['paypal-cert-url'] ?? ''),
            'auth_algo' => (string) ($headers['paypal-auth-algo'] ?? ''),
            'transmission_sig' => (string) ($headers['paypal-transmission-sig'] ?? ''),
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ];

        foreach (['transmission_id', 'transmission_time', 'cert_url', 'auth_algo', 'transmission_sig'] as $required) {
            if ($verification[$required] === '') {
                return false;
            }
        }

        $response = $this->request($settings, 'POST', '/v1/notifications/verify-webhook-signature', $verification);
        $status = strtoupper((string) ($response['verification_status'] ?? ''));

        return $status === 'SUCCESS';
    }

    private function request(array $settings, string $method, string $path, ?array $body): array
    {
        $token = $this->access_token($settings);
        $base = $this->base_url($settings);

        $args = [
            'method' => strtoupper($method),
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($base . $path, $args);
        if (is_wp_error($response)) {
            throw new \RuntimeException('PayPal API request failed: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $parsed = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($parsed)) {
            throw new \RuntimeException('Unexpected PayPal API response.');
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('PayPal API returned non-success status.');
        }

        return $parsed;
    }

    private function access_token(array $settings): string
    {
        $credentials = $this->credentials($settings);
        if ($credentials['client_id'] === '' || $credentials['client_secret'] === '') {
            throw new \RuntimeException('PayPal credentials are not configured.');
        }

        $response = wp_remote_post($this->base_url($settings) . '/v1/oauth2/token', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($credentials['client_id'] . ':' . $credentials['client_secret']),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'client_credentials',
            ],
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('PayPal auth failed: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || ! is_array($json) || empty($json['access_token'])) {
            throw new \RuntimeException('Failed to fetch PayPal access token.');
        }

        return (string) $json['access_token'];
    }

    private function credentials(array $settings): array
    {
        $live = ((string) ($settings['payments_mode'] ?? 'test')) === 'live';

        return [
            'client_id' => trim((string) ($settings[$live ? 'paypal_live_client_id' : 'paypal_sandbox_client_id'] ?? '')),
            'client_secret' => trim((string) ($settings[$live ? 'paypal_live_client_secret' : 'paypal_sandbox_client_secret'] ?? '')),
        ];
    }

    private function webhook_id(array $settings): string
    {
        $live = ((string) ($settings['payments_mode'] ?? 'test')) === 'live';
        return trim((string) ($settings[$live ? 'paypal_live_webhook_id' : 'paypal_sandbox_webhook_id'] ?? ''));
    }

    private function base_url(array $settings): string
    {
        return ((string) ($settings['payments_mode'] ?? 'test')) === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }
}
