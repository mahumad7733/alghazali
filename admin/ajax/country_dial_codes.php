<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $rows = $pdo->query('SELECT id, country_name, country_code, dial_code FROM countries WHERE dial_code IS NOT NULL AND dial_code <> \'\' ORDER BY country_name')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'countries' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'تعذر تحميل مفاتيح الدول.'], JSON_UNESCAPED_UNICODE);
}
