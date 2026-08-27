<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;
use RuntimeException;

final class OtpService
{
    private const CHANNELS = ['whatsapp', 'sms', 'email'];
    private const PURPOSES = ['registration', 'login', 'phone_change', 'email_change', 'test'];

    public function __construct(private Database $database)
    {
        $this->ensureSchema();
    }

    /** @return array<string,mixed> */
    public function publicSettings(): array
    {
        $settings = $this->loadSettings();
        return [
            'enabled' => (int) $settings['enabled'],
            'code_length' => (int) $settings['code_length'],
            'ttl_minutes' => (int) $settings['ttl_minutes'],
            'resend_after_seconds' => (int) $settings['resend_after_seconds'],
            'max_attempts' => (int) $settings['max_attempts'],
            'channels' => [
                'whatsapp' => (int) $settings['whatsapp_enabled'],
                'sms' => (int) $settings['sms_enabled'],
                'email' => (int) $settings['email_enabled'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function adminSettings(): array
    {
        $settings = $this->loadSettings();
        $provider = $this->loadProviderSettings();
        foreach (['whatsapp_api_token', 'sms_api_key', 'smtp_password'] as $secret) {
            $provider[$secret . '_configured'] = trim((string) ($provider[$secret] ?? '')) !== '' ? 1 : 0;
            unset($provider[$secret]);
        }
        return ['general' => $settings, 'providers' => $provider];
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function updateSettings(array $input, array $actor): array
    {
        $pdo = $this->database->pdo();
        $enabled = $this->boolInput($input['enabled'] ?? 0);
        $codeLength = max(4, min(8, (int) ($input['code_length'] ?? 6)));
        $ttl = max(1, min(60, (int) ($input['ttl_minutes'] ?? 5)));
        $resend = max(15, min(3600, (int) ($input['resend_after_seconds'] ?? 60)));
        $maxAttempts = max(1, min(10, (int) ($input['max_attempts'] ?? 5)));
        $hour = max(1, min(50, (int) ($input['max_sends_per_hour'] ?? 5)));
        $day = max($hour, min(200, (int) ($input['max_sends_per_day'] ?? 20)));
        $channels = [
            'whatsapp_enabled' => $this->boolInput($input['whatsapp_enabled'] ?? 0),
            'sms_enabled' => $this->boolInput($input['sms_enabled'] ?? 0),
            'email_enabled' => $this->boolInput($input['email_enabled'] ?? 0),
        ];
        $pdo->prepare('UPDATE otp_settings SET enabled = :enabled, code_length = :code_length, ttl_minutes = :ttl_minutes, resend_after_seconds = :resend_after_seconds, max_attempts = :max_attempts, max_sends_per_hour = :hour, max_sends_per_day = :day, whatsapp_enabled = :whatsapp_enabled, sms_enabled = :sms_enabled, email_enabled = :email_enabled, updated_by_user_id = :updated_by WHERE id = 1')->execute([
            'enabled' => $enabled, 'code_length' => $codeLength, 'ttl_minutes' => $ttl, 'resend_after_seconds' => $resend,
            'max_attempts' => $maxAttempts, 'hour' => $hour, 'day' => $day, ...$channels, 'updated_by' => (int) ($actor['id'] ?? 0) ?: null,
        ]);
        $provider = $this->loadProviderSettings();
        $secretMap = ['whatsapp_api_token' => 'whatsapp_api_token', 'sms_api_key' => 'sms_api_key', 'smtp_password' => 'smtp_password'];
        $providerFields = [
            'whatsapp_provider' => $this->nullableText($input['whatsapp_provider'] ?? null, 80),
            'whatsapp_api_url' => $this->nullableUrl($input['whatsapp_api_url'] ?? null),
            'whatsapp_phone_number_id' => $this->nullableText($input['whatsapp_phone_number_id'] ?? null, 180),
            'whatsapp_template_name' => $this->nullableText($input['whatsapp_template_name'] ?? null, 180),
            'whatsapp_language' => $this->nullableText($input['whatsapp_language'] ?? 'ar', 40) ?? 'ar',
            'sms_provider' => $this->nullableText($input['sms_provider'] ?? null, 80),
            'sms_api_url' => $this->nullableUrl($input['sms_api_url'] ?? null),
            'sms_sender_id' => $this->nullableText($input['sms_sender_id'] ?? null, 120),
            'smtp_host' => $this->nullableText($input['smtp_host'] ?? null, 255),
            'smtp_port' => max(1, min(65535, (int) ($input['smtp_port'] ?? 587))),
            'smtp_username' => $this->nullableText($input['smtp_username'] ?? null, 255),
            'smtp_encryption' => in_array((string) ($input['smtp_encryption'] ?? 'tls'), ['none', 'tls', 'ssl'], true) ? (string) $input['smtp_encryption'] : 'tls',
            'from_email' => filter_var(trim((string) ($input['from_email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: null,
            'from_name' => $this->nullableText($input['from_name'] ?? null, 180),
        ];
        $params = ['updated_by' => (int) ($actor['id'] ?? 0) ?: null];
        $sets = [];
        foreach ($providerFields as $column => $value) { $sets[] = "{$column} = :{$column}"; $params[$column] = $value; }
        foreach ($secretMap as $column => $key) {
            $secret = trim((string) ($input[$key] ?? ''));
            if ($secret !== '') { $sets[] = "{$column} = :{$column}"; $params[$column] = $this->encryptSecret($secret); }
        }
        $sets[] = 'updated_by_user_id = :updated_by';
        $pdo->prepare('UPDATE otp_provider_settings SET ' . implode(', ', $sets) . ' WHERE id = 1')->execute($params);
        return $this->adminSettings();
    }

    /** @return list<array<string,string>> */
    public function availableChannels(?string $email, ?string $phone): array
    {
        $settings = $this->loadSettings();
        if ((int) $settings['enabled'] !== 1) return [];
        $available = [];
        if ($phone !== null && $this->normalizePhone($phone) !== null) {
            if ((int) $settings['whatsapp_enabled'] === 1) $available[] = ['channel' => 'whatsapp', 'label' => 'واتساب'];
            if ((int) $settings['sms_enabled'] === 1) $available[] = ['channel' => 'sms', 'label' => 'رسالة نصية'];
        }
        if ($email !== null && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false && (int) $settings['email_enabled'] === 1) {
            $available[] = ['channel' => 'email', 'label' => 'البريد الإلكتروني'];
        }
        return $available;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function startRegistration(array $input): array
    {
        $fullName = Security::cleanText($input['full_name'] ?? null, 180);
        $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $phone = $this->normalizePhone((string) ($input['phone'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $countryId = filter_var($input['country_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $cityId = filter_var($input['city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($email === false || $phone === null || !$countryId || !$cityId) Response::error('تحقق من الاسم والبريد ورقم الهاتف والدولة والمدينة.', 'VALIDATION_ERROR', 422);
        PasswordPolicy::validate($this->database, $password);
        $pdo = $this->database->pdo();
        $existing = $pdo->prepare('SELECT id FROM users WHERE email = :email OR email = :email2 OR phone = :phone OR phone = :phone2 LIMIT 1');
        $existing->execute(['email' => $email, 'email2' => mb_strtolower($email), 'phone' => $phone, 'phone2' => (string) ($input['phone'] ?? '')]);
        if ($existing->fetch()) Response::error('البريد الإلكتروني أو رقم الهاتف مسجل مسبقًا.', 'DUPLICATE_ACCOUNT', 409);
        $country = $pdo->prepare('SELECT id FROM countries WHERE id = :id AND is_active = 1'); $country->execute(['id' => $countryId]);
        $city = $pdo->prepare('SELECT id FROM cities WHERE id = :id AND country_id = :country_id AND is_active = 1'); $city->execute(['id' => $cityId, 'country_id' => $countryId]);
        if (!$country->fetch() || !$city->fetch()) Response::error('الدولة أو المدينة المختارة غير متاحة.', 'VALIDATION_ERROR', 422);
        $channel = $this->selectRequestedChannel($input['channel'] ?? null, (string) $email, $phone);
        $destination = $channel === 'email' ? (string) $email : $phone;
        $challenge = $this->createChallenge('registration', $channel, $destination, [
            'full_name' => $fullName, 'email' => (string) $email, 'phone' => $phone,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'country_id' => (int) $countryId, 'city_id' => (int) $cityId,
        ]);
        return $challenge;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function startLogin(array $input): array
    {
        $identifier = trim((string) ($input['identifier'] ?? $input['email'] ?? $input['phone'] ?? ''));
        if ($identifier === '') Response::error('أدخل البريد الإلكتروني أو رقم الهاتف.', 'VALIDATION_ERROR', 422);
        $normalizedPhone = $this->normalizePhone($identifier);
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare('SELECT id, email, phone, status FROM users WHERE email = :email OR username = :username OR phone = :phone OR phone = :raw_phone LIMIT 1');
        $statement->execute(['email' => $identifier, 'username' => $identifier, 'phone' => $normalizedPhone ?? $identifier, 'raw_phone' => $identifier]);
        $user = $statement->fetch();
        if (!is_array($user) || (string) $user['status'] !== 'active') Response::error('تعذر بدء التحقق بهذه البيانات.', 'OTP_UNAVAILABLE', 422);
        $email = filter_var((string) ($user['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string) $user['email'] : null;
        $phone = $this->normalizePhone((string) ($user['phone'] ?? ''));
        $channel = $this->selectRequestedChannel($input['channel'] ?? null, $email ?? '', $phone);
        $destination = $channel === 'email' ? ($email ?? '') : ($phone ?? '');
        return $this->createChallenge('login', $channel, $destination, ['user_id' => (int) $user['id'], 'email' => $email, 'phone' => $phone]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function verify(array $input, ?Auth $auth = null): array
    {
        $challengeId = trim((string) ($input['challenge_id'] ?? ''));
        $code = trim((string) ($input['code'] ?? ''));
        if (!preg_match('/^[0-9]{4,8}$/', $code) || !preg_match('/^[a-f0-9]{64}$/', $challengeId)) Response::error('رمز التحقق غير صحيح.', 'OTP_INVALID', 422);
        $pdo = $this->database->pdo();
        $row = $this->one($pdo, 'SELECT * FROM otp_challenges WHERE challenge_id = :challenge_id LIMIT 1', ['challenge_id' => $challengeId]);
        if (!$row) Response::error('رمز التحقق غير صحيح أو لم يعد متاحًا.', 'OTP_INVALID', 422);
        if ((string) $row['status'] !== 'sent') {
            $message = (string) $row['status'] === 'expired' ? 'انتهت صلاحية رمز التحقق، يرجى طلب رمز جديد.' : ((string) $row['status'] === 'locked' ? 'انتهت المحاولات المسموح بها، يرجى طلب رمز جديد.' : 'رمز التحقق غير صحيح أو تم استخدامه سابقًا.');
            Response::error($message, 'OTP_NOT_ACTIVE', 422);
        }
        if (strtotime((string) $row['expires_at']) < time()) { $this->markStatus($challengeId, 'expired', 'expired'); Response::error('انتهت صلاحية رمز التحقق، يرجى طلب رمز جديد.', 'OTP_EXPIRED', 422); }
        $settings = $this->loadSettings();
        if ((int) $row['attempts'] >= (int) $settings['max_attempts']) { $this->markStatus($challengeId, 'locked', 'max_attempts'); Response::error('عدد المحاولات المسموح بها انتهى.', 'OTP_LOCKED', 429); }
        $pdo->prepare('UPDATE otp_challenges SET attempts = attempts + 1 WHERE challenge_id = :id')->execute(['id' => $challengeId]);
        if (!hash_equals((string) $row['code_hash'], hash('sha256', $code))) {
            $newAttempts = (int) $row['attempts'] + 1;
            if ($newAttempts >= (int) $settings['max_attempts']) $this->markStatus($challengeId, 'locked', 'max_attempts');
            Response::error($newAttempts >= (int) $settings['max_attempts'] ? 'عدد المحاولات المسموح بها انتهى.' : 'رمز التحقق غير صحيح.', $newAttempts >= (int) $settings['max_attempts'] ? 'OTP_LOCKED' : 'OTP_INVALID', $newAttempts >= (int) $settings['max_attempts'] ? 429 : 422);
        }
        $this->database->transaction(function (PDO $tx) use ($challengeId): void {
            $tx->prepare("UPDATE otp_challenges SET status = 'verified', used_at = NOW(), verified_at = NOW() WHERE challenge_id = :id AND status = 'sent'")->execute(['id' => $challengeId]);
        });
        $purpose = (string) $row['purpose'];
        if ($purpose === 'registration') {
            if (!$auth) $auth = new Auth($this->database);
            $payload = $this->one($pdo, 'SELECT full_name, email, phone, password_hash, country_id, city_id FROM otp_registration_payloads WHERE challenge_id = :id LIMIT 1', ['id' => $challengeId]);
            if (!$payload) Response::error('انتهت بيانات التسجيل، ابدأ التسجيل من جديد.', 'OTP_REGISTRATION_EXPIRED', 422);
            $user = $auth->registerCustomerAfterOtp($payload);
            $pdo->prepare('DELETE FROM otp_registration_payloads WHERE challenge_id = :id')->execute(['id' => $challengeId]);
            return ['user' => $user, 'purpose' => $purpose];
        }
        if ($purpose === 'login') {
            if (!$auth) $auth = new Auth($this->database);
            $user = $auth->loginAfterOtp((int) $row['user_id']);
            return ['user' => $user, 'purpose' => $purpose];
        }
        return ['purpose' => $purpose, 'verified' => true];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function resend(array $input): array
    {
        $challengeId = trim((string) ($input['challenge_id'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $challengeId)) Response::error('جلسة التحقق غير صالحة.', 'OTP_INVALID', 422);
        $pdo = $this->database->pdo();
        $row = $this->one($pdo, 'SELECT * FROM otp_challenges WHERE challenge_id = :id LIMIT 1', ['id' => $challengeId]);
        if (!$row || (string) $row['status'] !== 'sent') Response::error('انتهت جلسة التحقق، اطلب رمزًا جديدًا.', 'OTP_NOT_ACTIVE', 422);
        $settings = $this->loadSettings();
        if ((int) ($settings[(string) $row['channel'] . '_enabled'] ?? 0) !== 1) Response::error('قناة الإرسال هذه غير مفعلة حاليًا.', 'OTP_CHANNEL_UNAVAILABLE', 422);
        $remaining = ((int) $settings['resend_after_seconds']) - (time() - strtotime((string) $row['last_sent_at']));
        if ($remaining > 0) Response::error("يمكنك طلب رمز جديد بعد {$remaining} ثانية.", 'OTP_RESEND_WAIT', 429, ['retry_after' => $remaining]);
        $this->enforceRateLimit((string) $row['destination_hash'], (string) $row['ip_address'], (string) $row['channel']);
        $code = $this->generateCode((int) $settings['code_length']);
        try {
            $this->deliver((string) $row['channel'], (string) $row['destination'], $code);
        } catch (RuntimeException) {
            Response::error('تعذر إعادة إرسال رمز التحقق عبر القناة المختارة، يمكنك المحاولة لاحقًا أو اختيار قناة أخرى.', 'OTP_DELIVERY_FAILED', 502);
        }
        $expiresAt = date('Y-m-d H:i:s', time() + ((int) $settings['ttl_minutes'] * 60));
        $pdo->prepare('UPDATE otp_challenges SET code_hash = :hash, expires_at = :expires_at, last_sent_at = NOW(), send_count = send_count + 1, attempts = 0, failure_reason = NULL WHERE challenge_id = :id')->execute(['hash' => hash('sha256', $code), 'expires_at' => $expiresAt, 'id' => $challengeId]);
        return ['challenge_id' => $challengeId, 'channel' => (string) $row['channel'], 'expires_at' => $expiresAt, 'resend_after_seconds' => (int) $settings['resend_after_seconds']];
    }

    /** @return array<string,mixed> */
    public function status(string $challengeId): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $challengeId)) Response::error('جلسة التحقق غير صالحة.', 'OTP_INVALID', 422);
        $row = $this->one($this->database->pdo(), 'SELECT challenge_id, purpose, channel, status, attempts, expires_at, created_at, verified_at, used_at FROM otp_challenges WHERE challenge_id = :id LIMIT 1', ['id' => $challengeId]);
        if (!$row) Response::error('جلسة التحقق غير موجودة.', 'OTP_NOT_FOUND', 404);
        if ((string) $row['status'] === 'sent' && strtotime((string) $row['expires_at']) < time()) { $this->markStatus($challengeId, 'expired', 'expired'); $row['status'] = 'expired'; }
        return $row;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function sendTest(array $input, array $actor): array
    {
        $channel = (string) ($input['channel'] ?? '');
        if (!in_array($channel, self::CHANNELS, true)) Response::error('قناة OTP غير صالحة.', 'VALIDATION_ERROR', 422);
        $destination = trim((string) ($input['destination'] ?? ''));
        if ($channel === 'email' && filter_var($destination, FILTER_VALIDATE_EMAIL) === false) Response::error('أدخل بريدًا إلكترونيًا صالحًا للاختبار.', 'VALIDATION_ERROR', 422);
        if ($channel !== 'email') { $destination = $this->normalizePhone($destination) ?? ''; if ($destination === '') Response::error('أدخل رقمًا دوليًا صالحًا للاختبار.', 'VALIDATION_ERROR', 422); }
        $challenge = $this->createChallenge('test', $channel, $destination, ['user_id' => (int) ($actor['id'] ?? 0)]);
        return ['challenge_id' => $challenge['challenge_id'], 'channel' => $channel, 'destination' => $this->maskDestination($destination, $channel), 'expires_at' => $challenge['expires_at']];
    }

    /** @return array<string,mixed> */
    public function logs(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $statement = $this->database->pdo()->query('SELECT o.id, o.challenge_id, o.user_id, o.purpose, o.channel, o.destination, o.status, o.attempts, o.send_count, o.ip_address, o.expires_at, o.verified_at, o.used_at, o.created_at, u.full_name FROM otp_challenges o LEFT JOIN users u ON u.id = o.user_id ORDER BY o.id DESC LIMIT ' . $limit);
        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $row['destination'] = $this->maskDestination((string) $row['destination'], (string) $row['channel']);
            $row['challenge_id'] = substr((string) $row['challenge_id'], 0, 8) . '…';
            $row['status_label'] = ['sent' => 'تم الإرسال', 'verified' => 'تم التحقق', 'expired' => 'منتهي', 'failed' => 'فشل', 'locked' => 'تجاوز المحاولات'][(string) $row['status']] ?? (string) $row['status'];
            $row['channel_label'] = ['whatsapp' => 'واتساب', 'sms' => 'رسالة نصية', 'email' => 'البريد الإلكتروني'][(string) $row['channel']] ?? (string) $row['channel'];
            $items[] = $row;
        }
        return ['items' => $items];
    }

    public function normalizePhone(string $value, string $defaultCountry = '+967'): ?string
    {
        $digits = strtr(trim($value), ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        $digits = preg_replace('/[^0-9+]/', '', $digits) ?? '';
        if (str_starts_with($digits, '00')) $digits = '+' . substr($digits, 2);
        if (!str_starts_with($digits, '+') && preg_match('/^7[0-9]{8}$/', $digits) === 1) $digits = $defaultCountry . $digits;
        if (preg_match('/^\+[1-9][0-9]{7,14}$/', $digits) !== 1) return null;
        return $digits;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function createChallenge(string $purpose, string $channel, string $destination, array $payload = []): array
    {
        $settings = $this->loadSettings();
        if ((int) $settings['enabled'] !== 1) Response::error('التحقق عبر OTP غير مفعّل حاليًا.', 'OTP_DISABLED', 503);
        if ((int) ($settings[$channel . '_enabled'] ?? 0) !== 1) Response::error('قناة الإرسال المختارة غير مفعلة حاليًا.', 'OTP_CHANNEL_UNAVAILABLE', 422);
        $destination = $channel === 'email' ? mb_strtolower(trim($destination)) : ($this->normalizePhone($destination) ?? '');
        if (($channel === 'email' && filter_var($destination, FILTER_VALIDATE_EMAIL) === false) || ($channel !== 'email' && $destination === '')) Response::error('بيانات الإرسال غير صالحة.', 'VALIDATION_ERROR', 422);
        $this->enforceRateLimit(hash('sha256', $destination), (string) ($_SERVER['REMOTE_ADDR'] ?? ''), $channel);
        $code = $this->generateCode((int) $settings['code_length']);
        $challengeId = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + ((int) $settings['ttl_minutes'] * 60));
        try {
            $this->deliver($channel, $destination, $code);
        } catch (RuntimeException $exception) {
            $this->insertChallenge($challengeId, null, $purpose, $channel, $destination, $code, $expiresAt, 'failed', $exception->getMessage());
            Response::error('تعذر إرسال رمز التحقق عبر القناة المختارة، يمكنك اختيار طريقة أخرى.', 'OTP_DELIVERY_FAILED', 502);
        }
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;
        $this->insertChallenge($challengeId, $userId, $purpose, $channel, $destination, $code, $expiresAt, 'sent', null);
        if ($purpose === 'registration') {
            $this->database->pdo()->prepare('INSERT INTO otp_registration_payloads (challenge_id, full_name, email, phone, password_hash, country_id, city_id) VALUES (:id, :full_name, :email, :phone, :password_hash, :country_id, :city_id)')->execute([
                'id' => $challengeId, 'full_name' => $payload['full_name'], 'email' => $payload['email'], 'phone' => $payload['phone'], 'password_hash' => $payload['password_hash'], 'country_id' => $payload['country_id'], 'city_id' => $payload['city_id'],
            ]);
        }
        return ['challenge_id' => $challengeId, 'channel' => $channel, 'destination' => $this->maskDestination($destination, $channel), 'expires_at' => $expiresAt, 'resend_after_seconds' => (int) $settings['resend_after_seconds'], 'available_channels' => $this->availableChannels($payload['email'] ?? ($channel === 'email' ? $destination : null), $payload['phone'] ?? ($channel !== 'email' ? $destination : null))];
    }

    private function insertChallenge(string $challengeId, ?int $userId, string $purpose, string $channel, string $destination, string $code, string $expiresAt, string $status, ?string $failure): void
    {
        $this->database->pdo()->prepare('INSERT INTO otp_challenges (challenge_id, user_id, purpose, channel, destination, destination_hash, code_hash, status, ip_address, user_agent, expires_at, failure_reason) VALUES (:challenge_id, :user_id, :purpose, :channel, :destination, :destination_hash, :code_hash, :status, :ip_address, :user_agent, :expires_at, :failure_reason)')->execute([
            'challenge_id' => $challengeId, 'user_id' => $userId ?: null, 'purpose' => $purpose, 'channel' => $channel, 'destination' => $destination, 'destination_hash' => hash('sha256', $destination), 'code_hash' => hash('sha256', $code), 'status' => $status, 'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? '') ?: null, 'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null, 'expires_at' => $expiresAt, 'failure_reason' => $failure,
        ]);
    }

    private function enforceRateLimit(string $destinationHash, string $ip, string $channel): void
    {
        $settings = $this->loadSettings();
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare('SELECT COUNT(*) FROM otp_challenges WHERE destination_hash = :destination_hash AND channel = :channel AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $statement->execute(['destination_hash' => $destinationHash, 'channel' => $channel]);
        if ((int) $statement->fetchColumn() >= (int) $settings['max_sends_per_hour']) Response::error('تم تجاوز الحد الأقصى لطلبات التحقق خلال الساعة.', 'OTP_RATE_LIMITED', 429);
        if ($ip !== '') {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM otp_challenges WHERE ip_address = :ip AND channel = :channel AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
            $statement->execute(['ip' => $ip, 'channel' => $channel]);
            if ((int) $statement->fetchColumn() >= (int) $settings['max_sends_per_day']) Response::error('تم تجاوز الحد الأقصى لطلبات التحقق اليوم.', 'OTP_RATE_LIMITED', 429);
        }
    }

    private function selectRequestedChannel(mixed $requested, string $email, ?string $phone): string
    {
        $requested = trim((string) $requested);
        $available = array_column($this->availableChannels($email !== '' ? $email : null, $phone), 'channel');
        if ($requested !== '' && in_array($requested, $available, true)) return $requested;
        if ($requested !== '') Response::error('قناة الإرسال المختارة غير مفعلة أو غير متاحة.', 'OTP_CHANNEL_UNAVAILABLE', 422);
        if ($available === []) Response::error('لا توجد قناة تحقق مفعلة لهذه البيانات.', 'OTP_CHANNEL_UNAVAILABLE', 422);
        return (string) $available[0];
    }

    private function deliver(string $channel, string $destination, string $code): void
    {
        $provider = $this->loadProviderSettings(true);
        $message = "رمز التحقق لمنصة رحلة هو {$code}. صالح لمدة " . (int) $this->loadSettings()['ttl_minutes'] . ' دقائق. لا تشاركه مع أي شخص.';
        if ($channel === 'email') { $this->sendEmail($provider, $destination, $message); return; }
        if ($channel === 'sms') { $this->sendHttpProvider($provider, 'sms', $destination, $message, $code); return; }
        $this->sendHttpProvider($provider, 'whatsapp', $destination, $message, $code);
    }

    /** @param array<string,mixed> $provider */
    private function sendHttpProvider(array $provider, string $kind, string $destination, string $message, string $code): void
    {
        $url = trim((string) ($provider[$kind . '_api_url'] ?? ''));
        $token = $this->decryptSecret((string) ($provider[$kind === 'sms' ? 'sms_api_key' : 'whatsapp_api_token'] ?? ''));
        if ($url === '' || $token === '') throw new RuntimeException('لم يتم إعداد مزود الإرسال.');
        $payload = $kind === 'whatsapp' && strtolower((string) ($provider['whatsapp_provider'] ?? '')) === 'meta'
            ? ['messaging_product' => 'whatsapp', 'to' => $destination, 'type' => 'text', 'text' => ['body' => $message]]
            : ['to' => $destination, 'phone' => $destination, 'message' => $message, 'text' => $message, 'code' => $code, 'sender_id' => $provider['sms_sender_id'] ?? null, 'phone_number_id' => $provider['whatsapp_phone_number_id'] ?? null, 'template_name' => $provider['whatsapp_template_name'] ?? null, 'language' => $provider['whatsapp_language'] ?? 'ar'];
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $token, 'X-API-Key: ' . $token];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true]);
        $response = curl_exec($ch); $error = curl_error($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($response === false || $error !== '' || $status < 200 || $status >= 300) throw new RuntimeException('فشل مزود الإرسال.');
    }

    /** @param array<string,mixed> $provider */
    private function sendEmail(array $provider, string $to, string $message): void
    {
        $from = filter_var((string) ($provider['from_email'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$from) throw new RuntimeException('لم يتم إعداد بريد المرسل.');
        $host = trim((string) ($provider['smtp_host'] ?? ''));
        if ($host === '') {
            $ok = @mail($to, 'رمز التحقق | منصة رحلة', $message, 'From: ' . $from . "\r\nContent-Type: text/plain; charset=UTF-8");
            if (!$ok) throw new RuntimeException('تعذر إرسال البريد.');
            return;
        }
        $port = (int) ($provider['smtp_port'] ?? 587); $encryption = (string) ($provider['smtp_encryption'] ?? 'tls');
        $transport = $encryption === 'ssl' ? 'ssl://' . $host . ':' . $port : 'tcp://' . $host . ':' . $port;
        $socket = @stream_socket_client($transport, $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) throw new RuntimeException('تعذر الاتصال بخادم البريد.');
        stream_set_timeout($socket, 10); $this->smtpExpect($socket, 220); $this->smtpCommand($socket, 'EHLO rihla.local', 250);
        if ($encryption === 'tls') { $this->smtpCommand($socket, 'STARTTLS', 220); if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($socket); throw new RuntimeException('تعذر تفعيل تشفير البريد.'); } $this->smtpCommand($socket, 'EHLO rihla.local', 250); }
        $username = trim((string) ($provider['smtp_username'] ?? '')); $password = $this->decryptSecret((string) ($provider['smtp_password'] ?? ''));
        if ($username !== '') { $this->smtpCommand($socket, 'AUTH LOGIN', 334); $this->smtpCommand($socket, base64_encode($username), 334); $this->smtpCommand($socket, base64_encode($password), 235); }
        $this->smtpCommand($socket, 'MAIL FROM:<' . $from . '>', 250); $this->smtpCommand($socket, 'RCPT TO:<' . $to . '>', 250); $this->smtpCommand($socket, 'DATA', 354);
        $fromName = (string) ($provider['from_name'] ?? 'منصة رحلة'); $subject = 'رمز التحقق | منصة رحلة';
        $headers = 'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . ">\r\nTo: <{$to}>\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n";
        fwrite($socket, $headers . $message . "\r\n.\r\n"); $this->smtpExpect($socket, 250); fwrite($socket, "QUIT\r\n"); fclose($socket);
    }

    private function smtpCommand($socket, string $command, int $expected): void { fwrite($socket, $command . "\r\n"); $this->smtpExpect($socket, $expected); }
    private function smtpExpect($socket, int $expected): void { $line = fgets($socket, 1024); $code = (int) substr((string) $line, 0, 3); if ($code !== $expected) throw new RuntimeException('استجاب خادم البريد برفض العملية.'); }

    private function generateCode(int $length): string { $max = (10 ** $length) - 1; return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT); }
    private function markStatus(string $id, string $status, string $reason): void { $this->database->pdo()->prepare('UPDATE otp_challenges SET status = :status, failure_reason = :reason WHERE challenge_id = :id AND status = \'sent\'')->execute(['status' => $status, 'reason' => $reason, 'id' => $id]); }
    private function boolInput(mixed $value): int { return in_array($value, [1, '1', true, 'on', 'yes'], true) ? 1 : 0; }
    private function nullableText(mixed $value, int $max): ?string { $value = trim((string) $value); return $value === '' ? null : mb_substr($value, 0, $max); }
    private function nullableUrl(mixed $value): ?string { $value = trim((string) $value); if ($value === '') return null; if (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $value)) Response::error('رابط مزود الإرسال غير صالح.', 'VALIDATION_ERROR', 422); return mb_substr($value, 0, 500); }
    private function maskDestination(string $value, string $channel): string { if ($channel === 'email') { [$name, $domain] = array_pad(explode('@', $value, 2), 2, ''); return mb_substr($name, 0, 2) . '•••@' . $domain; } return mb_substr($value, 0, 4) . '••••' . mb_substr($value, -2); }
    private function encryptionKey(): string { global $appConfig; return hash('sha256', (string) ($appConfig['otp_encryption_key'] ?? (($appConfig['session_name'] ?? 'rihla') . '|' . ($appConfig['app_url'] ?? '') . '|' . ($appConfig['database']['name'] ?? ''))), true); }
    private function encryptSecret(string $value): string { $iv = random_bytes(16); $cipher = openssl_encrypt($value, 'AES-256-CBC', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv); return base64_encode($iv . ($cipher === false ? '' : $cipher)); }
    private function decryptSecret(string $value): string { if ($value === '') return ''; $raw = base64_decode($value, true); if ($raw === false || strlen($raw) < 17) return ''; $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $this->encryptionKey(), OPENSSL_RAW_DATA, substr($raw, 0, 16)); return $plain === false ? '' : $plain; }
    /** @return array<string,mixed> */ private function loadSettings(): array { $row = $this->one($this->database->pdo(), 'SELECT * FROM otp_settings WHERE id = 1', []); return $row ?: ['id'=>1,'enabled'=>0,'code_length'=>6,'ttl_minutes'=>5,'resend_after_seconds'=>60,'max_attempts'=>5,'max_sends_per_hour'=>5,'max_sends_per_day'=>20,'whatsapp_enabled'=>0,'sms_enabled'=>0,'email_enabled'=>1]; }
    /** @return array<string,mixed> */ private function loadProviderSettings(bool $decrypt = false): array { $row = $this->one($this->database->pdo(), 'SELECT * FROM otp_provider_settings WHERE id = 1', []) ?: ['id'=>1]; if ($decrypt) foreach (['whatsapp_api_token','sms_api_key','smtp_password'] as $key) $row[$key] = $this->decryptSecret((string) ($row[$key] ?? '')); return $row; }
    /** @return array<string,mixed>|null */ private function one(PDO $pdo, string $sql, array $params): ?array { $statement = $pdo->prepare($sql); $statement->execute($params); $row = $statement->fetch(); return is_array($row) ? $row : null; }

    private function ensureSchema(): void
    {
        $pdo = $this->database->pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS otp_settings (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, enabled TINYINT(1) NOT NULL DEFAULT 0, code_length TINYINT UNSIGNED NOT NULL DEFAULT 6, ttl_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5, resend_after_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 60, max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5, max_sends_per_hour TINYINT UNSIGNED NOT NULL DEFAULT 5, max_sends_per_day SMALLINT UNSIGNED NOT NULL DEFAULT 20, whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 0, sms_enabled TINYINT(1) NOT NULL DEFAULT 0, email_enabled TINYINT(1) NOT NULL DEFAULT 1, updated_by_user_id BIGINT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT fk_otp_settings_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec('INSERT IGNORE INTO otp_settings (id) VALUES (1)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS otp_provider_settings (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, whatsapp_provider VARCHAR(80) NULL, whatsapp_api_url VARCHAR(500) NULL, whatsapp_api_token TEXT NULL, whatsapp_phone_number_id VARCHAR(180) NULL, whatsapp_template_name VARCHAR(180) NULL, whatsapp_language VARCHAR(40) NOT NULL DEFAULT 'ar', sms_provider VARCHAR(80) NULL, sms_api_url VARCHAR(500) NULL, sms_api_key TEXT NULL, sms_sender_id VARCHAR(120) NULL, smtp_host VARCHAR(255) NULL, smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587, smtp_username VARCHAR(255) NULL, smtp_password TEXT NULL, smtp_encryption ENUM('none','tls','ssl') NOT NULL DEFAULT 'tls', from_email VARCHAR(190) NULL, from_name VARCHAR(180) NULL, updated_by_user_id BIGINT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT fk_otp_provider_settings_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec('INSERT IGNORE INTO otp_provider_settings (id) VALUES (1)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS otp_challenges (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, challenge_id CHAR(64) NOT NULL, user_id BIGINT UNSIGNED NULL, purpose ENUM('registration','login','phone_change','email_change','test') NOT NULL, channel ENUM('whatsapp','sms','email') NOT NULL, destination VARCHAR(190) NOT NULL, destination_hash CHAR(64) NOT NULL, code_hash CHAR(64) NOT NULL, status ENUM('sent','verified','expired','failed','locked') NOT NULL DEFAULT 'sent', attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, send_count SMALLINT UNSIGNED NOT NULL DEFAULT 1, ip_address VARCHAR(45) NULL, user_agent VARCHAR(500) NULL, last_sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NOT NULL, used_at DATETIME NULL, verified_at DATETIME NULL, failure_reason VARCHAR(190) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_otp_challenge_id (challenge_id), KEY idx_otp_destination_created (destination_hash, created_at), KEY idx_otp_ip_created (ip_address, created_at), KEY idx_otp_status_expires (status, expires_at), KEY idx_otp_user_created (user_id, created_at), CONSTRAINT fk_otp_challenges_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS otp_registration_payloads (challenge_id CHAR(64) NOT NULL PRIMARY KEY, full_name VARCHAR(180) NOT NULL, email VARCHAR(190) NOT NULL, phone VARCHAR(32) NOT NULL, password_hash VARCHAR(255) NOT NULL, country_id BIGINT UNSIGNED NOT NULL, city_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_otp_registration_challenge FOREIGN KEY (challenge_id) REFERENCES otp_challenges(challenge_id) ON DELETE CASCADE, CONSTRAINT fk_otp_registration_country FOREIGN KEY (country_id) REFERENCES countries(id), CONSTRAINT fk_otp_registration_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
