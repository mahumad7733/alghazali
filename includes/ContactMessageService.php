<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;

final class ContactMessageService
{
    private const STATUSES = ['new', 'in_progress', 'resolved', 'failed', 'cancelled'];

    private AuditLogger $audit;

    public function __construct(private Database $database)
    {
        $this->audit = new AuditLogger($database);
    }

    /** @return array<string, mixed> */
    public function createPublic(array $input): array
    {
        if (trim((string) ($input['website'] ?? '')) !== '') {
            Response::error('تعذر إرسال الرسالة.', 'VALIDATION_ERROR', 422);
        }

        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $recent = $this->one(
            'SELECT COUNT(*) AS total FROM contact_messages WHERE ip_address = :ip AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)',
            ['ip' => $ip]
        );
        if ((int) ($recent['total'] ?? 0) >= 5) {
            Response::error('تم تجاوز الحد المؤقت لإرسال الرسائل. حاول بعد دقائق.', 'RATE_LIMITED', 429);
        }

        $data = $this->publicPayload($input);
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO contact_messages (full_name, phone, email, subject, message, status, ip_address, user_agent)
             VALUES (:full_name, :phone, :email, :subject, :message, \'new\', :ip_address, :user_agent)'
        );
        $statement->execute($data + [
            'ip_address' => $ip !== '' ? $ip : null,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
        ]);

        return ['id' => (int) $this->database->pdo()->lastInsertId(), 'status' => 'new'];
    }

    /** @return list<array<string, mixed>> */
    public function adminMessages(): array
    {
        return $this->all(
            'SELECT id, full_name, phone, email, subject, message, status, ip_address, read_at, created_at, updated_at
             FROM contact_messages ORDER BY FIELD(status, \'new\', \'in_progress\', \'resolved\', \'failed\', \'cancelled\'), created_at DESC, id DESC'
        );
    }

    /** @return array<string, mixed> */
    public function updateStatus(array $actor, int $id, array $input): array
    {
        $existing = $this->one('SELECT id, full_name, subject, status FROM contact_messages WHERE id = :id LIMIT 1', ['id' => $id]);
        if ($existing === null) {
            Response::error('رسالة التواصل غير موجودة.', 'NOT_FOUND', 404);
        }
        $status = (string) ($input['status'] ?? '');
        if (!in_array($status, self::STATUSES, true)) {
            Response::error('حالة رسالة التواصل غير صالحة.', 'VALIDATION_ERROR', 422);
        }

        $statement = $this->database->pdo()->prepare(
            'UPDATE contact_messages SET status = :status, read_at = COALESCE(read_at, NOW()) WHERE id = :id'
        );
        $statement->execute(['status' => $status, 'id' => $id]);
        $this->audit->log((int) $actor['id'], null, 'contact_message_status_updated', 'contact_message', $id, $existing, ['status' => $status]);
        return ['id' => $id, 'status' => $status];
    }

    /** @param array<string, mixed> $input @return array<string, string> */
    private function publicPayload(array $input): array
    {
        $name = $this->text($input['full_name'] ?? '', 2, 120);
        $phone = $this->text($input['phone'] ?? '', 5, 40);
        $emailRaw = trim((string) ($input['email'] ?? ''));
        $email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
        $subject = $this->text($input['subject'] ?? '', 3, 180);
        $message = $this->text($input['message'] ?? '', 5, 5000);
        if ($email === false) {
            Response::error('البريد الإلكتروني غير صالح.', 'VALIDATION_ERROR', 422);
        }
        return ['full_name' => $name, 'phone' => $phone, 'email' => (string) $email, 'subject' => $subject, 'message' => $message];
    }

    private function text(mixed $value, int $minLength, int $maxLength): string
    {
        $clean = trim((string) $value);
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';
        if (mb_strlen($clean) < $minLength || mb_strlen($clean) > $maxLength) {
            Response::error('تحقق من الحقول المطلوبة في نموذج التواصل.', 'VALIDATION_ERROR', 422);
        }
        return Security::cleanText($clean, $maxLength);
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);
        $item = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($item) ? $item : null;
    }

    /** @return list<array<string, mixed>> */
    private function all(string $sql): array
    {
        $statement = $this->database->pdo()->query($sql);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
