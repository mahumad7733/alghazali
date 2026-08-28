<?php
declare(strict_types=1);

namespace App\Includes;

interface PaymentGatewayInterface
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createHostedInvoice(array $payload, string $idempotencyKey): array;

    /** @return array<string,mixed> */
    public function fetchPayment(string $providerPaymentId): array;

    /** @return array<string,mixed> */
    public function refund(string $providerPaymentId, ?int $amountMinor, string $idempotencyKey): array;

    /** @return array<string,mixed> */
    public function parseWebhook(string $rawPayload, array $headers): array;

    public function isConfigured(): bool;

    public function code(): string;
}
