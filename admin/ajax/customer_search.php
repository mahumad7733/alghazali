<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/customer_profile.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $term = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($term) < 2) {
        echo json_encode(['success' => true, 'customers' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['success' => true, 'customers' => customer_profile_search($pdo, $term)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'تعذر تنفيذ البحث.'], JSON_UNESCAPED_UNICODE);
}
