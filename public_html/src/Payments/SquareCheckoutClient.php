<?php

declare(strict_types=1);

namespace App\Payments;

final class SquareCheckoutClient
{
    public function __construct(private array $config)
    {
    }

    private function baseUrl(): string
    {
        $env = (string) ($this->config['environment'] ?? 'sandbox');
        if ($env === 'production') {
            return 'https://connect.squareup.com';
        }
        return 'https://connect.squareupsandbox.com';
    }

    private function accessToken(): string
    {
        return (string) ($this->config['access_token'] ?? '');
    }

    private function version(): string
    {
        return (string) ($this->config['version'] ?? '2026-01-22');
    }

    private function request(string $method, string $path, ?array $jsonBody = null): array
    {
        $token = $this->accessToken();
        if ($token === '') {
            throw new \RuntimeException('Square access token is not configured.');
        }

        $url = rtrim($this->baseUrl(), '/') . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize curl.');
        }

        $headers = [
            'Square-Version: ' . $this->version(),
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new \RuntimeException('Failed to encode Square request body.');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Square request failed: ' . $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Square returned invalid JSON (HTTP ' . $status . ').');
        }

        if ($status < 200 || $status >= 300) {
            $msg = 'Square request failed (HTTP ' . $status . ').';
            if (isset($data['errors'][0]['detail'])) {
                $msg .= ' ' . (string) $data['errors'][0]['detail'];
            }
            throw new \RuntimeException($msg);
        }

        return $data;
    }

    private function buildQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return $pairs ? ('?' . implode('&', $pairs)) : '';
    }

    public function createQuickPayLink(
        string $name,
        int $amountCents,
        string $currency,
        string $locationId,
        string $redirectUrl,
        ?string $buyerEmail = null,
        ?string $paymentNote = null,
    ): array {
        $body = [
            'idempotency_key' => bin2hex(random_bytes(16)),
            'quick_pay' => [
                'name' => $name,
                'price_money' => [
                    'amount' => $amountCents,
                    'currency' => $currency,
                ],
                'location_id' => $locationId,
            ],
            'checkout_options' => [
                'redirect_url' => $redirectUrl,
            ],
        ];

        if ($buyerEmail !== null && trim($buyerEmail) !== '') {
            $body['pre_populated_data'] = [
                'buyer_email' => $buyerEmail,
            ];
        }

        if ($paymentNote !== null && trim($paymentNote) !== '') {
            $body['payment_note'] = $paymentNote;
        }

        return $this->request('POST', '/v2/online-checkout/payment-links', $body);
    }

    public function createOrderPaymentLink(
        string $locationId,
        array $lineItems,
        ?string $referenceId,
        string $redirectUrl,
        ?string $buyerEmail = null,
        ?string $paymentNote = null,
    ): array {
        $body = [
            'idempotency_key' => bin2hex(random_bytes(16)),
            'order' => [
                'location_id' => $locationId,
                'line_items' => $lineItems,
            ],
            'checkout_options' => [
                'redirect_url' => $redirectUrl,
            ],
        ];

        if ($referenceId !== null && trim($referenceId) !== '') {
            $body['order']['reference_id'] = $referenceId;
        }

        if ($buyerEmail !== null && trim($buyerEmail) !== '') {
            $body['pre_populated_data'] = [
                'buyer_email' => $buyerEmail,
            ];
        }

        if ($paymentNote !== null && trim($paymentNote) !== '') {
            $body['payment_note'] = $paymentNote;
        }

        return $this->request('POST', '/v2/online-checkout/payment-links', $body);
    }

    public function listPayments(array $query = []): array
    {
        return $this->request('GET', '/v2/payments' . $this->buildQuery($query));
    }

    public function getPayment(string $paymentId): array
    {
        return $this->request('GET', '/v2/payments/' . rawurlencode($paymentId));
    }

    public function getOrder(string $orderId): array
    {
        return $this->request('GET', '/v2/orders/' . rawurlencode($orderId));
    }
}
