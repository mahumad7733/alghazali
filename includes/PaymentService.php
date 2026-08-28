<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;
use RuntimeException;

final class PaymentService
{
    /** @var array<string,mixed> */
    private array $appConfig;
    private InvoiceService $invoices;

    public function __construct(private Database $database, array $appConfig = [])
    {
        $this->appConfig = $appConfig;
        $this->invoices = new InvoiceService();
    }

    /** @return array<string,mixed> */
    public function publicOptions(): array
    {
        try {
            $pdo = $this->database->pdo();
            $items = $pdo->query("SELECT provider_code, environment, display_name_ar, is_enabled, public_key, config_json FROM payment_gateway_settings WHERE is_enabled = 1 ORDER BY provider_code, environment")->fetchAll();
        } catch (\Throwable) {
            return ['providers' => []];
        }
        $providers = [];
        foreach ($items as $row) {
            $config = json_decode((string) ($row['config_json'] ?? ''), true);
            $config = is_array($config) ? $config : [];
            $providers[] = [
                'provider_code' => (string) $row['provider_code'],
                'environment' => (string) $row['environment'],
                'display_name_ar' => (string) $row['display_name_ar'],
                'public_key' => (string) ($row['public_key'] ?? ''),
                'methods' => array_values(array_filter(array_map('strval', (array) ($config['methods'] ?? [])))),
            ];
        }
        return ['providers' => $providers];
    }

    /** @return array<string,mixed> */
    public function publicTaxSettings(): array
    {
        try {
            $row = $this->one($this->database->pdo(), 'SELECT vat_enabled, vat_rate, tax_label_ar, invoice_mode, zatca_integration_enabled FROM tax_settings WHERE id = 1 LIMIT 1', []);
        } catch (\Throwable) {
            return ['enabled' => 0, 'rate' => null, 'label_ar' => null, 'invoice_mode' => 'none', 'zatca_integration_enabled' => 0];
        }
        return ['enabled' => (int) ($row['vat_enabled'] ?? 0), 'rate' => $row['vat_rate'] !== null ? (string) $row['vat_rate'] : null, 'label_ar' => $row['tax_label_ar'] !== null ? (string) $row['tax_label_ar'] : null, 'invoice_mode' => (string) ($row['invoice_mode'] ?? 'none'), 'zatca_integration_enabled' => (int) ($row['zatca_integration_enabled'] ?? 0)];
    }

    /** @return array<string,mixed> */
    public function adminTaxSettings(): array
    {
        $row = $this->one($this->database->pdo(), 'SELECT * FROM tax_settings WHERE id = 1 LIMIT 1', []) ?? [];
        return ['vat_enabled' => (int) ($row['vat_enabled'] ?? 0), 'vat_rate' => $row['vat_rate'] !== null ? (string) $row['vat_rate'] : '', 'tax_label_ar' => (string) ($row['tax_label_ar'] ?? ''), 'invoice_mode' => (string) ($row['invoice_mode'] ?? 'none'), 'zatca_integration_enabled' => (int) ($row['zatca_integration_enabled'] ?? 0), 'supplier_snapshot_json' => (string) ($row['supplier_snapshot_json'] ?? '')];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $input @return array<string,mixed> */
    public function updateTaxSettings(array $actor, array $input): array
    {
        $enabled = !empty($input['vat_enabled']) ? 1 : 0;
        $rateInput = trim((string) ($input['vat_rate'] ?? ''));
        if ($enabled && ($rateInput === '' || !is_numeric($rateInput) || (float) $rateInput < 0 || (float) $rateInput > 100)) {
            throw new RuntimeException('أدخل نسبة ضريبة صالحة بين 0 و100 أو أبقِ الضريبة معطلة.');
        }
        $rate = $enabled ? number_format((float) $rateInput, 4, '.', '') : null;
        $invoiceMode = strtolower(trim((string) ($input['invoice_mode'] ?? 'none')));
        if (!in_array($invoiceMode, ['none', 'simplified', 'tax'], true)) $invoiceMode = 'none';
        $zatcaEnabled = !empty($input['zatca_integration_enabled']) ? 1 : 0;
        if ($zatcaEnabled) throw new RuntimeException('تكامل ZATCA يبقى مغلقًا حتى اكتمال بيانات المكلف وشهادة/onboarding ومراجعة مختص سعودي.');
        $label = Security::cleanText($input['tax_label_ar'] ?? '', 160) ?: null;
        $supplier = trim((string) ($input['supplier_snapshot_json'] ?? ''));
        if ($supplier !== '') {
            $decoded = json_decode($supplier, true);
            if (!is_array($decoded)) throw new RuntimeException('بيانات المورد يجب أن تكون JSON صالحة.');
            $supplier = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } else {
            $supplier = null;
        }
        $stmt = $this->database->pdo()->prepare('UPDATE tax_settings SET vat_enabled = :enabled, vat_rate = :rate, tax_label_ar = :label, invoice_mode = :mode, zatca_integration_enabled = 0, supplier_snapshot_json = :supplier, updated_by_user_id = :user_id WHERE id = 1');
        $stmt->execute(['enabled' => $enabled, 'rate' => $rate, 'label' => $label, 'mode' => $invoiceMode, 'supplier' => $supplier, 'user_id' => (int) $actor['id']]);
        return $this->adminTaxSettings();
    }

    /** @return array<string,mixed> */
    public function adminSettings(): array
    {
        $rows = $this->database->pdo()->query('SELECT id, provider_code, environment, display_name_ar, is_enabled, public_key, secret_ciphertext, webhook_secret_ciphertext, config_json, updated_at FROM payment_gateway_settings ORDER BY provider_code, environment')->fetchAll();
        $items = [];
        foreach ($rows as $row) {
            $config = json_decode((string) ($row['config_json'] ?? ''), true);
            $config = is_array($config) ? $config : [];
            $items[] = [
                'id' => (int) $row['id'],
                'provider_code' => (string) $row['provider_code'],
                'environment' => (string) $row['environment'],
                'display_name_ar' => (string) $row['display_name_ar'],
                'is_enabled' => (int) $row['is_enabled'],
                'public_key' => (string) ($row['public_key'] ?? ''),
                'has_secret' => (string) ($row['secret_ciphertext'] ?? '') !== '',
                'secret_masked' => SecretVault::mask(SecretVault::decrypt((string) ($row['secret_ciphertext'] ?? ''))),
                'has_webhook_secret' => (string) ($row['webhook_secret_ciphertext'] ?? '') !== '',
                'webhook_secret_masked' => SecretVault::mask(SecretVault::decrypt((string) ($row['webhook_secret_ciphertext'] ?? ''))),
                'base_url' => (string) ($config['base_url'] ?? 'https://api.moyasar.com/v1'),
                'methods' => array_values(array_filter(array_map('strval', (array) ($config['methods'] ?? [])))),
                'updated_at' => (string) $row['updated_at'],
            ];
        }
        return ['items' => $items, 'secret_storage_configured' => SecretVault::isConfigured()];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $input @return array<string,mixed> */
    public function updateSettings(array $actor, array $input): array
    {
        $provider = strtolower(trim((string) ($input['provider_code'] ?? 'moyasar')));
        $environment = strtolower(trim((string) ($input['environment'] ?? 'sandbox')));
        if ($provider !== 'moyasar' || !in_array($environment, ['sandbox', 'live'], true)) {
            throw new RuntimeException('مزود الدفع أو البيئة غير مدعومين.');
        }
        $name = Security::cleanText($input['display_name_ar'] ?? 'Moyasar', 160) ?: 'Moyasar';
        $publicKey = trim((string) ($input['public_key'] ?? ''));
        $baseUrl = trim((string) ($input['base_url'] ?? 'https://api.moyasar.com/v1'));
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($baseUrl), 'https://')) {
            throw new RuntimeException('عنوان API يجب أن يكون HTTPS صالحًا.');
        }
        $methods = array_values(array_filter(array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), (array) ($input['methods'] ?? [])), static fn (string $value): bool => preg_match('/^[a-z0-9_-]{2,32}$/', $value) === 1));
        $pdo = $this->database->pdo();
        $existing = $this->one($pdo, 'SELECT * FROM payment_gateway_settings WHERE provider_code = :provider AND environment = :environment', ['provider' => $provider, 'environment' => $environment]);
        $secretCiphertext = (string) ($existing['secret_ciphertext'] ?? '');
        $webhookCiphertext = (string) ($existing['webhook_secret_ciphertext'] ?? '');
        $secret = trim((string) ($input['secret_key'] ?? ''));
        $webhookSecret = trim((string) ($input['webhook_secret'] ?? ''));
        if ($secret !== '') $secretCiphertext = SecretVault::encrypt($secret);
        if ($webhookSecret !== '') $webhookCiphertext = SecretVault::encrypt($webhookSecret);
        $enabled = !empty($input['is_enabled']) ? 1 : 0;
        if ($enabled && ($secretCiphertext === '' || $webhookCiphertext === '')) {
            throw new RuntimeException('لا يمكن تفعيل المزود قبل حفظ مفتاح API وسر webhook على الخادم.');
        }
        $configJson = json_encode(['base_url' => $baseUrl, 'methods' => $methods], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($existing === null) {
            $stmt = $pdo->prepare('INSERT INTO payment_gateway_settings (provider_code, environment, display_name_ar, is_enabled, public_key, secret_ciphertext, webhook_secret_ciphertext, config_json, updated_by_user_id) VALUES (:provider, :environment, :name, :enabled, :public_key, :secret, :webhook, :config, :user_id)');
            $stmt->execute(['provider' => $provider, 'environment' => $environment, 'name' => $name, 'enabled' => $enabled, 'public_key' => $publicKey ?: null, 'secret' => $secretCiphertext ?: null, 'webhook' => $webhookCiphertext ?: null, 'config' => $configJson, 'user_id' => (int) $actor['id']]);
        } else {
            $stmt = $pdo->prepare('UPDATE payment_gateway_settings SET display_name_ar = :name, is_enabled = :enabled, public_key = :public_key, secret_ciphertext = :secret, webhook_secret_ciphertext = :webhook, config_json = :config, updated_by_user_id = :user_id WHERE id = :id');
            $stmt->execute(['name' => $name, 'enabled' => $enabled, 'public_key' => $publicKey ?: null, 'secret' => $secretCiphertext ?: null, 'webhook' => $webhookCiphertext ?: null, 'config' => $configJson, 'user_id' => (int) $actor['id'], 'id' => (int) $existing['id']]);
        }
        return $this->adminSettings();
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function createHostedCheckout(array $actor, int $bookingId): array
    {
        $booking = $this->bookingForActor($actor, $bookingId);
        if ($booking === null) throw new RuntimeException('الحجز غير موجود أو لا تملك صلاحية الوصول إليه.');
        if ((string) $booking['status'] !== 'pending' || strtotime((string) $booking['held_until']) <= time()) {
            throw new RuntimeException('انتهت مهلة الحجز أو لم يعد بانتظار الدفع.');
        }
        $environment = strtolower((string) ($this->appConfig['environment'] ?? 'production')) === 'production' ? 'live' : 'sandbox';
        $settings = $this->activeSettings($environment);
        if ($settings === null) throw new RuntimeException('لا توجد بوابة دفع مفعلة لهذه البيئة.');
        $gateway = $this->gateway($settings);
        $currencyCode = strtoupper((string) $booking['currency_code']);
        $minor = $this->toMinor((string) $booking['total_amount'], (int) $booking['decimal_places']);
        if ($minor < 100) throw new RuntimeException('قيمة الدفع أقل من الحد الأدنى المقبول للبوابة.');
        $existing = $this->one($this->database->pdo(), "SELECT * FROM payment_attempts WHERE booking_id = :booking_id AND provider_code = :provider AND state IN ('created','initiated','processing') ORDER BY id DESC LIMIT 1", ['booking_id' => $bookingId, 'provider' => $gateway->code()]);
        if ($existing !== null && (string) ($existing['checkout_url'] ?? '') !== '') {
            return $this->attemptView($existing);
        }
        if ($existing !== null && strtotime((string) $existing['created_at']) > time() - 300) {
            throw new RuntimeException('يوجد طلب دفع قيد المعالجة. انتظر قليلًا ثم تحقق من الحالة بدل إنشاء طلب جديد.');
        }
        $idempotencyKey = $this->uuid4();
        $pdo = $this->database->pdo();
        $stmt = $pdo->prepare('INSERT INTO payment_attempts (booking_id, provider_code, attempt_type, idempotency_key, state, amount, amount_minor, currency_id, currency_code, expires_at) VALUES (:booking_id, :provider, :type, :key, \'processing\', :amount, :minor, :currency_id, :currency_code, :expires_at)');
        $stmt->execute(['booking_id' => $bookingId, 'provider' => $gateway->code(), 'type' => 'hosted_invoice', 'key' => $idempotencyKey, 'amount' => $booking['total_amount'], 'minor' => $minor, 'currency_id' => (int) $booking['currency_id'], 'currency_code' => $currencyCode, 'expires_at' => $booking['held_until']]);
        $attemptId = (int) $pdo->lastInsertId();
        try {
            $appUrl = rtrim((string) ($this->appConfig['app_url'] ?? ''), '/');
            if ($appUrl === '' || !filter_var($appUrl, FILTER_VALIDATE_URL)) throw new RuntimeException('يجب إعداد app_url عام وصالح قبل تشغيل الدفع المستضاف.');
            $response = $gateway->createHostedInvoice([
                'amount_minor' => $minor,
                'currency_code' => $currencyCode,
                'description' => 'حجز رحلة ' . (string) $booking['booking_number'],
                'callback_url' => $appUrl . '/api/v1/index.php?route=payments/webhook/moyasar',
                'success_url' => $appUrl . '/customer.php?payment_return=' . rawurlencode($idempotencyKey),
                'back_url' => $appUrl . '/customer.php?payment_cancelled=1',
                'expired_at' => (new \DateTimeImmutable((string) $booking['held_until']))->format(DATE_ATOM),
            ], $idempotencyKey);
            $invoiceId = trim((string) ($response['id'] ?? ''));
            $checkoutUrl = trim((string) ($response['url'] ?? ''));
            if ($invoiceId === '' || !filter_var($checkoutUrl, FILTER_VALIDATE_URL)) throw new RuntimeException('لم تُرجع البوابة رابط دفع مستضافًا صالحًا.');
            $paymentId = $this->ensurePaymentRow($booking, $gateway->code(), (string) ($settings['environment'] ?? $environment), $invoiceId, $response);
            $update = $pdo->prepare("UPDATE payment_attempts SET payment_id = :payment_id, provider_invoice_id = :invoice_id, state = 'initiated', provider_status = :provider_status, checkout_url = :checkout_url, response_json = :response_json, updated_at = NOW() WHERE id = :id");
            $update->execute(['payment_id' => $paymentId, 'invoice_id' => $invoiceId, 'provider_status' => (string) ($response['status'] ?? 'initiated'), 'checkout_url' => $checkoutUrl, 'response_json' => $this->safeJson($response), 'id' => $attemptId]);
            return $this->attemptView($this->one($pdo, 'SELECT * FROM payment_attempts WHERE id = :id', ['id' => $attemptId]) ?? []);
        } catch (\Throwable $e) {
            $pdo->prepare("UPDATE payment_attempts SET state = 'failed', last_error = :error, updated_at = NOW() WHERE id = :id")->execute(['error' => mb_substr($e->getMessage(), 0, 500), 'id' => $attemptId]);
            throw $e;
        }
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function attemptStatusByKey(array $actor, string $idempotencyKey): array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', trim($idempotencyKey))) throw new RuntimeException('معرّف محاولة الدفع غير صالح.');
        $attempt = $this->one($this->database->pdo(), 'SELECT pa.*, b.booking_number, b.customer_id, b.agent_id, b.company_id, b.payment_status FROM payment_attempts pa INNER JOIN bookings b ON b.id = pa.booking_id WHERE pa.idempotency_key = :key LIMIT 1', ['key' => trim($idempotencyKey)]);
        if ($attempt === null || !$this->actorCanSeeBooking($actor, $attempt)) throw new RuntimeException('محاولة الدفع غير موجودة.');
        return $this->attemptView($attempt);
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function attemptStatus(array $actor, int $attemptId): array
    {
        $attempt = $this->one($this->database->pdo(), 'SELECT pa.*, b.booking_number, b.customer_id, b.agent_id, b.company_id, b.payment_status FROM payment_attempts pa INNER JOIN bookings b ON b.id = pa.booking_id WHERE pa.id = :id', ['id' => $attemptId]);
        if ($attempt === null || !$this->actorCanSeeBooking($actor, $attempt)) throw new RuntimeException('محاولة الدفع غير موجودة.');
        return $this->attemptView($attempt);
    }

    /** @return array<string,mixed> */
    public function handleWebhook(string $providerCode, string $rawPayload, array $headers = []): array
    {
        $settings = $this->activeSettingsForProvider($providerCode, 'sandbox');
        if ($settings === null) $settings = $this->activeSettingsForProvider($providerCode, 'live');
        if ($settings === null) throw new RuntimeException('بوابة webhook غير مفعلة.');
        $gateway = $this->gateway($settings);
        $parsed = $gateway->parseWebhook($rawPayload, $headers);
        $pdo = $this->database->pdo();
        $payloadJson = $this->safeJson($parsed['payload']);
        $hash = hash('sha256', $rawPayload);
        $existingEvent = $this->one($pdo, 'SELECT processing_status, payload_hash FROM payment_webhook_events WHERE provider_code = :provider AND event_id = :event_id LIMIT 1', ['provider' => $providerCode, 'event_id' => $parsed['event_id']]);
        if ($existingEvent !== null && (string) $existingEvent['processing_status'] === 'processed' && hash_equals((string) $existingEvent['payload_hash'], $hash)) {
            return ['event_id' => $parsed['event_id'], 'status' => 'duplicate'];
        }
        $insert = $pdo->prepare('INSERT INTO payment_webhook_events (provider_code, event_id, event_type, signature_valid, payload_hash, payload_json, processing_status) VALUES (:provider, :event_id, :type, :valid, :hash, :payload, :status) ON DUPLICATE KEY UPDATE attempt_count = attempt_count + 1, signature_valid = VALUES(signature_valid), payload_hash = VALUES(payload_hash), payload_json = VALUES(payload_json), processing_status = VALUES(processing_status), last_error = NULL');
        $insert->execute(['provider' => $providerCode, 'event_id' => $parsed['event_id'], 'type' => $parsed['event_type'] ?: null, 'valid' => $parsed['valid'] ? 1 : 0, 'hash' => $hash, 'payload' => $payloadJson, 'status' => $parsed['valid'] ? 'received' : 'rejected']);
        if (!$parsed['valid']) throw new RuntimeException('فشل تحقق webhook.');
        $data = is_array($parsed['payload']['data'] ?? null) ? $parsed['payload']['data'] : $parsed['payload'];
        $providerInvoiceId = trim((string) ($data['invoice_id'] ?? $data['id'] ?? ''));
        $providerPaymentId = trim((string) ($data['payment_id'] ?? ($data['payments'][0]['id'] ?? '')));
        $attempt = $this->one($pdo, 'SELECT * FROM payment_attempts WHERE provider_code = :provider AND (provider_invoice_id = :invoice_id OR provider_payment_id = :payment_id) ORDER BY id DESC LIMIT 1', ['provider' => $providerCode, 'invoice_id' => $providerInvoiceId ?: '__none__', 'payment_id' => $providerPaymentId ?: '__none__']);
        if ($attempt === null) {
            $pdo->prepare("UPDATE payment_webhook_events SET processing_status = 'ignored', processed_at = NOW() WHERE provider_code = :provider AND event_id = :event_id")->execute(['provider' => $providerCode, 'event_id' => $parsed['event_id']]);
            return ['event_id' => $parsed['event_id'], 'status' => 'ignored'];
        }
        $providerStatus = strtolower(trim((string) ($data['status'] ?? ($data['payments'][0]['status'] ?? ''))));
        $state = $this->mapProviderState($parsed['event_type'], $providerStatus);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE payment_attempts SET provider_payment_id = COALESCE(NULLIF(:payment_id, \'\'), provider_payment_id), provider_status = :provider_status, state = :state, response_json = :response_json, completed_at = CASE WHEN :state2 IN (\'paid\',\'failed\',\'expired\',\'refunded\') THEN NOW() ELSE completed_at END, updated_at = NOW() WHERE id = :id')->execute(['payment_id' => $providerPaymentId, 'provider_status' => $providerStatus ?: $parsed['event_type'], 'state' => $state, 'state2' => $state, 'response_json' => $payloadJson, 'id' => (int) $attempt['id']]);
            $payment = $this->one($pdo, 'SELECT * FROM payments WHERE id = :id FOR UPDATE', ['id' => $attempt['payment_id']]);
            if ($payment !== null) {
                $newStatus = $state === 'paid' ? 'completed' : ($state === 'failed' ? 'failed' : ($state === 'refunded' ? 'refunded' : 'pending'));
                $internal = $state === 'paid' ? 'paid' : ($state === 'refunded' ? 'refunded' : $state);
                $pdo->prepare('UPDATE payments SET provider_payment_id = COALESCE(NULLIF(:provider_payment_id, \'\'), provider_payment_id), provider_invoice_id = COALESCE(NULLIF(:provider_invoice_id, \'\'), provider_invoice_id), provider_status = :provider_status, status = :status, internal_state = :internal_state, metadata_json = :metadata, updated_at = NOW() WHERE id = :id')->execute(['provider_payment_id' => $providerPaymentId, 'provider_invoice_id' => $providerInvoiceId, 'provider_status' => $providerStatus ?: $parsed['event_type'], 'status' => $newStatus, 'internal_state' => $internal, 'metadata' => $payloadJson, 'id' => (int) $payment['id']]);
                if ($state === 'paid') {
                    $bookingUpdate = $pdo->prepare("UPDATE bookings SET payment_status = 'paid', updated_at = NOW() WHERE id = :id AND status = 'pending' AND held_until > NOW()");
                    $bookingUpdate->execute(['id' => (int) $attempt['booking_id']]);
                    if ($bookingUpdate->rowCount() > 0 || (string) ($attempt['payment_status'] ?? '') === 'paid') $this->invoices->issueForPayment($pdo, (int) $payment['id']);
                }
                if ($state === 'refunded') $pdo->prepare("UPDATE bookings SET payment_status = 'refunded', updated_at = NOW() WHERE id = :id")->execute(['id' => (int) $attempt['booking_id']]);
            }
            $pdo->prepare("UPDATE payment_webhook_events SET processing_status = 'processed', processed_at = NOW(), last_error = NULL WHERE provider_code = :provider AND event_id = :event_id")->execute(['provider' => $providerCode, 'event_id' => $parsed['event_id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->prepare("UPDATE payment_webhook_events SET processing_status = 'failed', last_error = :error WHERE provider_code = :provider AND event_id = :event_id")->execute(['error' => mb_substr($e->getMessage(), 0, 500), 'provider' => $providerCode, 'event_id' => $parsed['event_id']]);
            throw $e;
        }
        return ['event_id' => $parsed['event_id'], 'status' => $state];
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function refund(array $actor, int $paymentId, ?string $amount, string $reason): array
    {
        $pdo = $this->database->pdo();
        $payment = $this->one($pdo, 'SELECT p.*, b.booking_number, b.company_id FROM payments p INNER JOIN bookings b ON b.id = p.booking_id WHERE p.id = :id', ['id' => $paymentId]);
        if ($payment === null) throw new RuntimeException('الدفع غير موجود.');
        $currency = $this->one($pdo, 'SELECT decimal_places, code FROM currencies WHERE id = :id', ['id' => (int) $payment['currency_id']]);
        if ($currency === null) throw new RuntimeException('عملة الدفع غير موجودة.');
        $totalMinor = $this->toMinor((string) $payment['amount'], (int) $currency['decimal_places']);
        $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE payment_id = :payment_id AND status IN (\'pending\',\'completed\')');
        $sumStmt->execute(['payment_id' => $paymentId]);
        $refundedAmount = (float) $sumStmt->fetchColumn();
        $requestedAmount = $amount === null || trim($amount) === '' ? (string) $payment['amount'] : trim($amount);
        if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $requestedAmount) || (float) $requestedAmount <= 0) throw new RuntimeException('قيمة الاسترداد غير صالحة.');
        if ((float) $requestedAmount > ((float) $payment['amount'] - $refundedAmount + 0.00001)) throw new RuntimeException('قيمة الاسترداد تتجاوز المتبقي القابل للاسترداد.');
        $providerId = trim((string) ($payment['provider_payment_id'] ?? ''));
        if ($providerId === '') throw new RuntimeException('هذا الدفع لا يملك معرّفًا لدى مزود خارجي، ولا يمكن تنفيذ استرداد إلكتروني له من هذه الشاشة.');
        $paymentEnvironment = strtolower((string) ($payment['gateway_environment'] ?? ''));
        if (!in_array($paymentEnvironment, ['sandbox', 'live'], true)) $paymentEnvironment = strtolower((string) ($this->appConfig['environment'] ?? 'production')) === 'production' ? 'live' : 'sandbox';
        $settings = $this->activeSettings($paymentEnvironment);
        if ($settings === null) throw new RuntimeException('بوابة الدفع غير مفعلة في بيئة هذه العملية.');
        $gateway = $this->gateway($settings);
        $key = $this->uuid4();
        $stmt = $pdo->prepare('INSERT INTO refunds (payment_id, currency_id, amount, status, provider_payment_id, idempotency_key, reason_ar, approved_by_user_id, net_refund_amount) VALUES (:payment_id, :currency_id, :amount, \'pending\', :provider_payment_id, :key, :reason, :user_id, :amount)');
        $stmt->execute(['payment_id' => $paymentId, 'currency_id' => (int) $payment['currency_id'], 'amount' => $requestedAmount, 'provider_payment_id' => $providerId, 'key' => $key, 'reason' => Security::cleanText($reason ?: 'استرداد بناءً على طلب الإدارة', 500), 'user_id' => (int) $actor['id']]);
        $refundId = (int) $pdo->lastInsertId();
        try {
            $response = $gateway->refund($providerId, $this->toMinor($requestedAmount, (int) $currency['decimal_places']), $key);
            $providerStatus = strtolower((string) ($response['status'] ?? ''));
            $completed = in_array($providerStatus, ['refunded', 'paid', 'captured', 'completed'], true);
            $feeAmount = $this->minorToDecimal((int) ($response['fee'] ?? 0), (int) $currency['decimal_places']);
            $netRefund = number_format(max(0, (float) $requestedAmount - (float) $feeAmount), 2, '.', '');
            $pdo->prepare('UPDATE refunds SET status = :status, provider_refund_id = :provider_refund_id, gateway_fee_amount = :fee, net_refund_amount = :net, refunded_at = CASE WHEN :completed = 1 THEN NOW() ELSE refunded_at END WHERE id = :id')->execute(['status' => $completed ? 'completed' : 'pending', 'provider_refund_id' => (string) ($response['id'] ?? ''), 'fee' => $feeAmount, 'net' => $netRefund, 'completed' => $completed ? 1 : 0, 'id' => $refundId]);
            if ($completed) {
                $newTotal = $refundedAmount + (float) $requestedAmount;
                $isFull = $newTotal + 0.00001 >= (float) $payment['amount'];
                $pdo->prepare('UPDATE payments SET internal_state = :internal_state, status = :status, updated_at = NOW() WHERE id = :id')->execute(['internal_state' => $isFull ? 'refunded' : 'partially_refunded', 'status' => $isFull ? 'refunded' : 'completed', 'id' => $paymentId]);
                if ($isFull) $pdo->prepare("UPDATE bookings SET payment_status = 'refunded', updated_at = NOW() WHERE id = :id")->execute(['id' => (int) $payment['booking_id']]);
            }
            return ['refund_id' => $refundId, 'status' => $completed ? 'completed' : 'pending', 'provider_status' => $providerStatus];
        } catch (\Throwable $e) {
            $pdo->prepare("UPDATE refunds SET status = 'failed', failure_reason = :reason WHERE id = :id")->execute(['reason' => mb_substr($e->getMessage(), 0, 500), 'id' => $refundId]);
            throw $e;
        }
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function adminInvoices(array $actor, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->database->pdo()->query('SELECT i.id, i.invoice_number, i.booking_id, i.payment_id, i.invoice_type, i.status, i.subtotal_amount, i.tax_amount, i.total_amount, i.tax_rate, i.issued_at, i.created_at, b.booking_number, cu.code AS currency_code FROM invoices i LEFT JOIN bookings b ON b.id = i.booking_id INNER JOIN currencies cu ON cu.id = i.currency_id ORDER BY i.id DESC LIMIT ' . $limit)->fetchAll();
        return ['items' => $rows];
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function adminPayments(array $actor, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->database->pdo()->query('SELECT p.id, p.booking_id, b.booking_number, p.amount, cu.code AS currency_code, p.payment_method, p.payment_channel, p.gateway_provider, p.provider_payment_id, p.provider_invoice_id, p.provider_status, p.status, p.internal_state, p.provider_fee_amount, p.provider_net_amount, p.created_at, p.updated_at FROM payments p INNER JOIN bookings b ON b.id = p.booking_id INNER JOIN currencies cu ON cu.id = p.currency_id ORDER BY p.id DESC LIMIT ' . $limit)->fetchAll();
        return ['items' => $rows];
    }

    /** @param array<string,mixed> $settings */
    private function gateway(array $settings): PaymentGatewayInterface
    {
        $provider = (string) ($settings['provider_code'] ?? '');
        if ($provider === 'moyasar') {
            $config = json_decode((string) ($settings['config_json'] ?? ''), true);
            $config = is_array($config) ? $config : [];
            return new MoyasarGateway(['secret' => SecretVault::decrypt((string) ($settings['secret_ciphertext'] ?? '')), 'webhook_secret' => SecretVault::decrypt((string) ($settings['webhook_secret_ciphertext'] ?? '')), 'base_url' => (string) ($config['base_url'] ?? 'https://api.moyasar.com/v1')]);
        }
        throw new RuntimeException('محول مزود الدفع غير موجود.');
    }

    /** @return array<string,mixed>|null */
    private function activeSettings(string $environment): ?array
    {
        return $this->activeSettingsForProvider('moyasar', $environment);
    }

    /** @return array<string,mixed>|null */
    private function activeSettingsForProvider(string $provider, string $environment): ?array
    {
        $row = $this->one($this->database->pdo(), 'SELECT * FROM payment_gateway_settings WHERE provider_code = :provider AND environment = :environment AND is_enabled = 1 LIMIT 1', ['provider' => $provider, 'environment' => $environment]);
        if ($row === null) return null;
        $gateway = $this->gateway($row);
        return $gateway->isConfigured() ? $row : null;
    }

    /** @param array<string,mixed> $booking @return int */
    private function ensurePaymentRow(array $booking, string $provider, string $environment, string $invoiceId, array $response): int
    {
        $pdo = $this->database->pdo();
        $existing = $this->one($pdo, 'SELECT id FROM payments WHERE booking_id = :booking_id AND provider_invoice_id = :invoice_id LIMIT 1', ['booking_id' => (int) $booking['id'], 'invoice_id' => $invoiceId]);
        if ($existing !== null) return (int) $existing['id'];
        $bookingPayment = $this->one($pdo, 'SELECT id FROM payments WHERE booking_id = :booking_id ORDER BY id DESC LIMIT 1', ['booking_id' => (int) $booking['id']]);
        if ($bookingPayment !== null) {
            $update = $pdo->prepare("UPDATE payments SET payment_method = 'card', payment_channel = :channel, gateway_provider = :provider, gateway_environment = :environment, provider_invoice_id = :invoice_id, provider_status = :provider_status, status = 'pending', internal_state = 'initiated', provider_currency_code = :currency_code, metadata_json = :metadata, updated_at = NOW() WHERE id = :id");
            $update->execute(['channel' => $provider, 'provider' => $provider, 'environment' => $environment, 'invoice_id' => $invoiceId, 'provider_status' => (string) ($response['status'] ?? 'initiated'), 'currency_code' => (string) $booking['currency_code'], 'metadata' => $this->safeJson($response), 'id' => (int) $bookingPayment['id']]);
            return (int) $bookingPayment['id'];
        }
        $stmt = $pdo->prepare("INSERT INTO payments (booking_id, currency_id, amount, payment_method, payment_channel, gateway_provider, gateway_environment, provider_invoice_id, provider_status, status, internal_state, provider_currency_code, metadata_json) VALUES (:booking_id, :currency_id, :amount, 'card', :channel, :provider, :invoice_id, :provider_status, 'pending', 'initiated', :currency_code, :metadata)");
        $stmt->execute(['booking_id' => (int) $booking['id'], 'currency_id' => (int) $booking['currency_id'], 'amount' => $booking['total_amount'], 'channel' => $provider, 'provider' => $provider, 'environment' => $environment, 'invoice_id' => $invoiceId, 'provider_status' => (string) ($response['status'] ?? 'initiated'), 'currency_code' => (string) $booking['currency_code'], 'metadata' => $this->safeJson($response)]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $attempt @return array<string,mixed> */
    private function attemptView(array $attempt): array
    {
        return ['id' => (int) $attempt['id'], 'booking_id' => (int) $attempt['booking_id'], 'booking_number' => (string) ($attempt['booking_number'] ?? ''), 'state' => (string) $attempt['state'], 'provider_status' => (string) ($attempt['provider_status'] ?? ''), 'checkout_url' => (string) ($attempt['checkout_url'] ?? ''), 'amount' => (string) $attempt['amount'], 'currency_code' => (string) $attempt['currency_code'], 'expires_at' => (string) ($attempt['expires_at'] ?? ''), 'created_at' => (string) $attempt['created_at']];
    }

    /** @param array<string,mixed> $actor @return array<string,mixed>|null */
    private function bookingForActor(array $actor, int $bookingId): ?array
    {
        $row = $this->one($this->database->pdo(), 'SELECT b.*, cu.code AS currency_code, cu.decimal_places, b.customer_id, b.agent_id, b.company_id FROM bookings b INNER JOIN currencies cu ON cu.id = b.currency_id WHERE b.id = :id LIMIT 1', ['id' => $bookingId]);
        if ($row === null || !$this->actorCanSeeBooking($actor, $row)) return null;
        return $row;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $row */
    private function actorCanSeeBooking(array $actor, array $row): bool
    {
        if (in_array('super_admin', (array) ($actor['roles'] ?? []), true)) return true;
        if (($actor['customer_id'] ?? null) !== null && (int) $row['customer_id'] === (int) $actor['customer_id']) return true;
        if (($actor['agent_id'] ?? null) !== null && (int) $row['agent_id'] === (int) $actor['agent_id']) return true;
        return ($actor['company_id'] ?? null) !== null && (int) $row['company_id'] === (int) $actor['company_id'] && in_array('view_company_bookings', (array) ($actor['permissions'] ?? []), true);
    }

    private function mapProviderState(string $eventType, string $status): string
    {
        $value = strtolower($eventType . ' ' . $status);
        if (str_contains($value, 'refund')) return 'refunded';
        if (str_contains($value, 'paid') || str_contains($value, 'captured') || str_contains($value, 'verified')) return 'paid';
        if (str_contains($value, 'fail') || str_contains($value, 'cancel') || str_contains($value, 'void')) return 'failed';
        if (str_contains($value, 'expired')) return 'expired';
        return 'processing';
    }

    private function toMinor(string $amount, int $places): int
    {
        $amount = trim($amount);
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $amount, $m) !== 1) throw new RuntimeException('قيمة مالية غير صالحة.');
        $fraction = str_pad(substr((string) ($m[2] ?? ''), 0, $places), $places, '0');
        return ((int) $m[1] * (10 ** $places)) + (int) ($fraction === '' ? 0 : $fraction);
    }

    private function minorToDecimal(int $minor, int $places): string
    {
        if ($places === 0) return (string) $minor;
        $factor = 10 ** $places;
        return number_format($minor / $factor, $places, '.', '');
    }

    /** @param array<string,mixed> $value */
    private function safeJson(array $value): string
    {
        unset($value['secret_token']);
        if (isset($value['data']) && is_array($value['data'])) unset($value['data']['secret_token']);
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** @param array<string,mixed> $params @return array<string,mixed>|null */
    private function one(PDO $pdo, string $sql, array $params = []): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }
}
