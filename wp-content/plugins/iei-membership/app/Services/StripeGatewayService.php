<?php

namespace IEI\Membership\Services;

/**
 * Stripe Checkout API wrapper using Stripe official HTTPS endpoints.
 */
class StripeGatewayService
{
    public function create_checkout_session(array $settings, array $payload): array
    {
        $secretKey = $this->secret_key($settings);
        if ($secretKey === '') {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }

        $body = [
            'mode' => 'payment',
            'success_url' => (string) ($payload['success_url'] ?? ''),
            'cancel_url' => (string) ($payload['cancel_url'] ?? ''),
            'customer_email' => (string) ($payload['customer_email'] ?? ''),
            'line_items[0][price_data][currency]' => strtolower((string) ($payload['currency'] ?? 'AUD')),
            'line_items[0][price_data][product_data][name]' => (string) ($payload['description'] ?? 'IEI Membership Payment'),
            'line_items[0][price_data][unit_amount]' => (string) ((int) ($payload['amount_cents'] ?? 0)),
            'line_items[0][quantity]' => '1',
        ];

        $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];
        foreach ($metadata as $key => $value) {
            $body['metadata[' . sanitize_key((string) $key) . ']'] = (string) $value;
        }

        $response = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $secretKey,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Stripe request failed: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300 || ! is_array($json) || empty($json['id'])) {
            throw new \RuntimeException('Stripe checkout session creation failed.');
        }

        return $json;
    }

    public function construct_verified_event(array $settings, string $payload, string $signatureHeader): ?array
    {
        $secret = $this->webhook_secret($settings);
        if ($secret === '' || $signatureHeader === '' || $payload === '') {
            return null;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $segment) {
            $segment = trim($segment);
            if (strpos($segment, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $segment, 2);
            $parts[trim($key)][] = trim($value);
        }

        $timestamp = isset($parts['t'][0]) ? (string) $parts['t'][0] : '';
        $v1Sigs = isset($parts['v1']) && is_array($parts['v1']) ? $parts['v1'] : [];

        if ($timestamp === '' || empty($v1Sigs)) {
            return null;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        $verified = false;
        foreach ($v1Sigs as $candidate) {
            if (hash_equals($expected, (string) $candidate)) {
                $verified = true;
                break;
            }
        }

        if (! $verified) {
            return null;
        }

        $event = json_decode($payload, true);
        return is_array($event) ? $event : null;
    }

    private function secret_key(array $settings): string
    {
        $mode = (string) ($settings['payments_mode'] ?? 'test');
        if ($mode === 'live') {
            return trim((string) ($settings['stripe_live_secret_key'] ?? ''));
        }

        return trim((string) ($settings['stripe_test_secret_key'] ?? ''));
    }

    private function webhook_secret(array $settings): string
    {
        $mode = (string) ($settings['payments_mode'] ?? 'test');
        if ($mode === 'live') {
            return trim((string) ($settings['stripe_live_webhook_signing_secret'] ?? ''));
        }

        return trim((string) ($settings['stripe_test_webhook_signing_secret'] ?? ''));
    }
}
