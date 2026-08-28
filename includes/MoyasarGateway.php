<?php
declare(strict_types=1);

namespace App\Includes;

use RuntimeException;

final class MoyasarGateway implements PaymentGatewayInterface
{
    /** @param array<string,mixed> $settings */
    public function __construct(private array $settings)
    {
    }

    public function code(): string
    {
        return 'moyasar';
    }

    public function isConfigured(): bool
    {
        return (string) ($this->settings['secret'] ?? '') !== ''
            && (string) ($this->settings['base_url'] ?? '') !== '';
    }

    public function createHostedInvoice(array $payload, string $idempotencyKey): array
    {
        $this->assertConfigured();
        $body = [
            'amount' => (int) ($payload['amount_minor'] ?? 0),
            'currency' => strtoupper((string) ($payload['currency_code'] ?? '')),
            'description' => mb_substr((string) ($payload['description'] ?? 'حجز رحلة عبر منصة رحلة'), 0, 255),
            'callback_url' => (string) ($payload['callback_url'] ?? ''),
            'success_url' => (string) ($payload['success_url'] ?? ''),
            'back_url' => (string) ($payload['back_url'] ?? ''),
            'expired_at' => (string) ($payload['expired_at'] ?? ''),
        ];
        $body = array_filter($body, static fn (mixed $value): bool => $value !== '');
        $metadata = array_filter((array) ($payload['metadata'] ?? []), static fn (mixed $value): bool => is_string($value) && $value !== '');
        if ($metadata !== []) $body['metadata'] = $metadata;
        // Moyasar documents given_id for payment creation, not as a guaranteed
        // idempotency field for hosted invoices. Local PaymentService deduplication
        // is therefore authoritative and this method never retries an unknown result.
        return $this->request('POST', '/invoices', $body);

    }

    public function fetchPayment(string $providerPaymentId): array
    {
        $this->assertConfigured();
        if (!preg_match('/^[A-Za-z0-9_-]{8,160}$/', $providerPaymentId)) {
            throw new RuntimeException('معرّف الدفع الخارجي غير صالح.');
        }
        return $this->request('GET', '/payments/' . rawurlencode($providerPaymentId));
    }

    public function refund(string $providerPaymentId, ?int $amountMinor, string $idempotencyKey): array
    {
        $this->assertConfigured();
        if (!preg_match('/^[A-Za-z0-9_-]{8,160}$/', $providerPaymentId)) {
            throw new RuntimeException('معرّف الدفع الخارجي غير صالح.');
        }
        $body = $amountMinor === null ? [] : ['amount' => $amountMinor];
        return $this->request('POST', '/payments/' . rawurlencode($providerPaymentId) . '/refund', $body, ['X-Rihla-Idempotency-Key: ' . $idempotencyKey]);
    }

    public function parseWebhook(string $rawPayload, array $headers): array
    {
        $payload = json_decode($rawPayload, true);
        if (!is_array($payload)) {
            throw new RuntimeException('حمولة webhook غير صالحة.');
        }
        $configuredSecret = (string) ($this->settings['webhook_secret'] ?? '');
        $receivedSecret = (string) ($payload['secret_token'] ?? ($headers['x-webhook-secret'] ?? $headers['x-moyasar-secret'] ?? ''));
        $valid = $configuredSecret !== '' && $receivedSecret !== '' && hash_equals($configuredSecret, $receivedSecret);
        $eventId = trim((string) ($payload['id'] ?? $payload['event_id'] ?? ''));
        $eventType = trim((string) ($payload['type'] ?? $payload['event_type'] ?? ''));
        if ($eventId === '') {
            throw new RuntimeException('webhook بلا معرّف حدث.');
        }
        return ['valid' => $valid, 'event_id' => $eventId, 'event_type' => $eventType, 'payload' => $payload];
    }

    /** @param array<string,mixed> $body @param list<string> $extraHeaders @return array<string,mixed> */
    private function request(string $method, string $path, array $body = [], array $extraHeaders = []): array
    {
        $url = rtrim((string) $this->settings['base_url'], '/') . $path;
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode((string) $this->settings['secret'] . ':'),
            ...$extraHeaders,
        ];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('تعذر بدء اتصال بوابة الدفع.');
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== []) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $error !== '') {
            throw new RuntimeException('تعذر الاتصال ببوابة الدفع.');
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('أعادت بوابة الدفع استجابة غير مفهومة.');
        }
        if ($status < 200 || $status >= 300) {
            $message = trim((string) ($decoded['message'] ?? $decoded['error'] ?? 'فشل طلب بوابة الدفع.'));
            throw new RuntimeException(mb_substr($message, 0, 240));
        }
        return $decoded;
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('لم يتم إعداد بيانات اعتماد بوابة الدفع على الخادم.');
        }
    }
}
