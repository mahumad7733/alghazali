<?php

declare(strict_types=1);

function customer_profile_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . ':' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return $cache[$key] = (bool)$stmt->fetchColumn();
}

function customer_profile_sync_service(PDO $pdo, string $table, int $serviceId, array $profileData, array $historyData): ?int
{
    if (!customer_profile_has_column($pdo, $table, 'passport_id')
        || !customer_profile_has_column($pdo, 'customer_service_history', 'passport_id')) {
        return null;
    }
    $passportId = customer_profile_find_or_create($pdo, $profileData);
    $stmt = $pdo->prepare("UPDATE `{$table}` SET passport_id = ? WHERE id = ?");
    $stmt->execute([$passportId, $serviceId]);
    customer_profile_record_service($pdo, array_merge($historyData, ['passport_id' => $passportId, 'service_id' => $serviceId]));
    return $passportId;
}

function customer_profile_search(PDO $pdo, string $term, int $limit = 10): array
{
    $term = trim($term);
    if ($term === '') {
        return [];
    }

    $limit = max(1, min($limit, 25));
    $like = '%' . $term . '%';
    $stmt = $pdo->prepare("SELECT id, full_name, full_name_en, nationality, gender, date_of_birth,
        passport_number, passport_issue_date, passport_expiry_date, phone_number, mobile_number,
        id_type, id_number, id_issue_place, id_issue_date, personal_photo, passport_image,
        exit_image, authorization_image, branch_id
        FROM passports
        WHERE full_name LIKE :name OR full_name_en LIKE :name_en
           OR passport_number LIKE :passport_number OR phone_number LIKE :phone
           OR mobile_number LIKE :mobile OR id_number LIKE :id_number
        ORDER BY CASE WHEN passport_number = :exact_passport THEN 0
                      WHEN phone_number = :exact_phone THEN 1
                      WHEN mobile_number = :exact_mobile THEN 2
                      WHEN id_number = :exact_id THEN 3 ELSE 4 END, full_name
        LIMIT {$limit}");
    $stmt->execute([
        ':name' => $like, ':name_en' => $like, ':passport_number' => $like,
        ':phone' => $like, ':mobile' => $like, ':id_number' => $like,
        ':exact_passport' => $term, ':exact_phone' => $term,
        ':exact_mobile' => $term, ':exact_id' => $term,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function customer_profile_find_or_create(PDO $pdo, array $data): int
{
    $passportId = (int)($data['passport_id'] ?? 0);
    if ($passportId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM passports WHERE id = ? LIMIT 1');
        $stmt->execute([$passportId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('العميل المحدد غير موجود.');
        }
        return $passportId;
    }

    $fullName = trim((string)($data['full_name'] ?? $data['traveler_name'] ?? $data['owner_name'] ?? ''));
    if ($fullName === '') {
        throw new InvalidArgumentException('اسم العميل مطلوب لإنشاء الملف الموحد.');
    }
    $passportNumber = trim((string)($data['passport_number'] ?? '')) ?: null;
    $phone = trim((string)($data['phone_number'] ?? $data['mobile_number'] ?? $data['phone_no'] ?? '')) ?: null;
    $idNumber = trim((string)($data['id_number'] ?? $data['owner_id_no'] ?? '')) ?: null;
    $lookup = $pdo->prepare('SELECT id FROM passports WHERE
        (:passport_number IS NOT NULL AND passport_number = :passport_lookup)
        OR (:phone IS NOT NULL AND (phone_number = :phone_lookup OR mobile_number = :mobile_lookup))
        OR (:id_number IS NOT NULL AND id_number = :id_lookup) ORDER BY id LIMIT 1');
    $lookup->execute([
        ':passport_number' => $passportNumber, ':passport_lookup' => $passportNumber,
        ':phone' => $phone, ':phone_lookup' => $phone, ':mobile_lookup' => $phone,
        ':id_number' => $idNumber, ':id_lookup' => $idNumber,
    ]);
    $existing = (int)($lookup->fetchColumn() ?: 0);
    if ($existing > 0) {
        return $existing;
    }

    $stmt = $pdo->prepare('INSERT INTO passports
        (full_name, full_name_en, nationality, gender, date_of_birth, passport_number,
         passport_issue_date, passport_expiry_date, phone_number, mobile_number,
         id_type, id_number, id_issue_place, id_issue_date, personal_photo,
         passport_image, created_by, branch_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([
        $fullName, $data['full_name_en'] ?? null, $data['nationality'] ?? $data['nationality_name'] ?? null,
        $data['gender'] ?? null, $data['date_of_birth'] ?? null, $passportNumber,
        $data['passport_issue_date'] ?? null, $data['passport_expiry_date'] ?? null,
        $phone, $phone, $data['id_type'] ?? null, $idNumber,
        $data['id_issue_place'] ?? null, $data['id_issue_date'] ?? null,
        $data['personal_photo'] ?? null, $data['passport_image'] ?? null,
        (int)($data['created_by'] ?? $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0),
        $data['branch_id'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

function customer_profile_record_service(PDO $pdo, array $data): void
{
    $stmt = $pdo->prepare('INSERT INTO customer_service_history
        (passport_id, service_type, service_id, service_number, service_date, amount,
         currency_id, status, branch_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE service_number = VALUES(service_number),
        service_date = VALUES(service_date), amount = VALUES(amount), currency_id = VALUES(currency_id),
        status = VALUES(status), branch_id = VALUES(branch_id)');
    $stmt->execute([
        (int)$data['passport_id'], $data['service_type'], (int)$data['service_id'],
        $data['service_number'] ?? null, $data['service_date'] ?? null, $data['amount'] ?? null,
        $data['currency_id'] ?? null, $data['status'] ?? null, $data['branch_id'] ?? null,
        $data['created_by'] ?? $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null,
    ]);
}
