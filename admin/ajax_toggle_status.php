<?php
/**
 * تغيير حالة الكيانات (تفعيل/تعطيل) بشكل سريع
 */
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    die("Unauthorized");
}

$entity = $_GET['entity'] ?? '';
$id = intval($_GET['id'] ?? 0);
$status = $_GET['status'] ?? '';

$allowed_entities = ['users', 'branches', 'agents', 'employees', 'customers', 'suppliers'];
$allowed_statuses = ['active', 'inactive', 'closed'];

if (in_array($entity, $allowed_entities) && $id > 0 && in_array($status, $allowed_statuses)) {
    try {
        $stmt = $pdo->prepare("UPDATE $entity SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        
        // تسجيل في سجل العمليات
        log_audit($pdo, 'update_status', $entity, $id, null, ['status' => $status], "تغيير الحالة إلى $status عبر التبديل السريع");
        
        // العودة للصفحة السابقة
        header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit();
    } catch (PDOException $e) {
        die("Error updating status: " . $e->getMessage());
    }
} else {
    die("Invalid request parameters");
}
