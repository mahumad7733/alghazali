<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;

final class ContactService
{
    private const TYPES = ['phone', 'whatsapp', 'email', 'location', 'hours', 'custom'];
    private AuditLogger $audit;

    public function __construct(private Database $database)
    {
        $this->audit = new AuditLogger($database);
    }

    /** @return list<array<string, mixed>> */
    public function publicChannels(): array
    {
        return $this->all(
            'SELECT id, type, title_ar, value, description_ar, icon, sort_order FROM contact_channels WHERE status = \'active\' ORDER BY sort_order, id',
            []
        );
    }

    /** @return list<array<string, mixed>> */
    public function adminChannels(): array
    {
        return $this->all(
            'SELECT id, type, title_ar, value, description_ar, icon, sort_order, status, created_at, updated_at FROM contact_channels ORDER BY sort_order, id',
            []
        );
    }

    /** @return array<string, mixed> */
    public function create(array $actor, array $input): array
    {
        $data = $this->payload($input);
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO contact_channels (type, title_ar, value, description_ar, icon, sort_order, status, created_by, updated_by)
             VALUES (:type, :title_ar, :value, :description_ar, :icon, :sort_order, :status, :created_by, :updated_by)'
        );
        $statement->execute($data + ['created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id']]);
        $id = (int) $this->database->pdo()->lastInsertId();
        $this->audit->log((int) $actor['id'], null, 'contact_channel_created', 'contact_channel', $id, null, $data);
        return ['id' => $id] + $data;
    }

    /** @return array<string, mixed> */
    public function update(array $actor, int $id, array $input): array
    {
        $existing = $this->one($id);
        if ($existing === null) { Response::error('قناة التواصل غير موجودة.', 'NOT_FOUND', 404); }
        $data = $this->payload($input, $existing);
        $data['id'] = $id;
        $statement = $this->database->pdo()->prepare(
            'UPDATE contact_channels SET type = :type, title_ar = :title_ar, value = :value, description_ar = :description_ar, icon = :icon, sort_order = :sort_order, status = :status, updated_by = :updated_by WHERE id = :id'
        );
        $statement->execute($data + ['updated_by' => (int) $actor['id']]);
        $this->audit->log((int) $actor['id'], null, 'contact_channel_updated', 'contact_channel', $id, $existing, $data);
        return $data;
    }

    /** @return array<string, mixed> */
    public function delete(array $actor, int $id): array
    {
        $existing = $this->one($id);
        if ($existing === null) { Response::error('قناة التواصل غير موجودة.', 'NOT_FOUND', 404); }
        $statement = $this->database->pdo()->prepare('DELETE FROM contact_channels WHERE id = :id');
        $statement->execute(['id' => $id]);
        $this->audit->log((int) $actor['id'], null, 'contact_channel_deleted', 'contact_channel', $id, $existing, null);
        return ['id' => $id, 'deleted' => true];
    }

    /** @return array<string, mixed> */
    public function updateStatus(array $actor, int $id, array $input): array
    {
        $existing = $this->one($id);
        if ($existing === null) { Response::error('قناة التواصل غير موجودة.', 'NOT_FOUND', 404); }
        $status = (string) ($input['status'] ?? '');
        if (!in_array($status, ['active', 'inactive'], true)) { Response::error('حالة قناة التواصل غير صالحة.', 'VALIDATION_ERROR', 422); }
        $statement = $this->database->pdo()->prepare('UPDATE contact_channels SET status = :status, updated_by = :updated_by WHERE id = :id');
        $statement->execute(['status' => $status, 'updated_by' => (int) $actor['id'], 'id' => $id]);
        $this->audit->log((int) $actor['id'], null, 'contact_channel_status_updated', 'contact_channel', $id, $existing, ['status' => $status]);
        return ['id' => $id, 'status' => $status];
    }

    /** @param array<string, mixed> $input @param array<string, mixed>|null $existing @return array<string, mixed> */
    private function payload(array $input, ?array $existing = null): array
    {
        $type = Security::cleanText($input['type'] ?? ($existing['type'] ?? ''), 40);
        $title = Security::cleanText($input['title_ar'] ?? ($existing['title_ar'] ?? ''), 160);
        $value = Security::cleanText($input['value'] ?? ($existing['value'] ?? ''), 500);
        $descriptionRaw = $input['description_ar'] ?? ($existing['description_ar'] ?? '');
        $iconRaw = $input['icon'] ?? ($existing['icon'] ?? '');
        $description = trim((string) $descriptionRaw);
        $icon = trim((string) $iconRaw);
        if (!in_array($type, self::TYPES, true) || mb_strlen($title) < 2 || $value === '' || mb_strlen($description) > 500 || mb_strlen($icon) > 80) {
            Response::error('بيانات قناة التواصل غير صالحة.', 'VALIDATION_ERROR', 422);
        }
        $sortOrder = filter_var($input['sort_order'] ?? ($existing['sort_order'] ?? 0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 999]]);
        if ($sortOrder === false) { Response::error('ترتيب قناة التواصل غير صالح.', 'VALIDATION_ERROR', 422); }
        $status = (string) ($input['status'] ?? ($existing['status'] ?? 'inactive'));
        if (!in_array($status, ['active', 'inactive'], true)) { Response::error('حالة قناة التواصل غير صالحة.', 'VALIDATION_ERROR', 422); }
        return ['type' => $type, 'title_ar' => $title, 'value' => $value, 'description_ar' => $description === '' ? null : $description, 'icon' => $icon === '' ? null : $icon, 'sort_order' => $sortOrder, 'status' => $status];
    }

    /** @return array<string, mixed>|null */
    private function one(int $id): ?array
    {
        $statement = $this->database->pdo()->prepare('SELECT id, type, title_ar, value, description_ar, icon, sort_order, status, created_at, updated_at FROM contact_channels WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $item = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($item) ? $item : null;
    }

    /** @param array<string, mixed> $params @return list<array<string, mixed>> */
    private function all(string $sql, array $params): array
    {
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
