<?php
// =====================================================
// ajax_save_theme.php - حفظ وضعية اللون (فاتح/داكن/النظام)
// =====================================================

require_once '../includes/db.php';
require_once '../includes/security.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
rate_limit('ajax_save_theme', 20, 60);
require_csrf();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'غير مصرح لك']);
    exit();
}

$user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'];
$theme = $_POST['theme'] ?? 'light';

if (!in_array($theme, ['light', 'dark', 'system'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'الوضعية غير صحيحة']);
    exit();
}

try {
    $stmt = $pdo->prepare("INSERT INTO user_preferences (user_id, preference_key, preference_value) 
                           VALUES (?, 'theme', ?) 
                           ON DUPLICATE KEY UPDATE preference_value = ?");
    $stmt->execute([$user_id, $theme, $theme]);
    
    echo json_encode(['status' => 'success', 'theme' => $theme]);
} catch (PDOException $e) {
    json_exception_response($e);
}
?>