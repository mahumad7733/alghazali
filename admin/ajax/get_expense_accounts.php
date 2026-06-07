<?php
require_once '../../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// جلب حسابات المصاريف (التي تبدأ بـ 4 أو حسب شجرة الحسابات)
$stmt = $pdo->query("SELECT id, account_name_ar as name FROM unified_accounts WHERE account_code LIKE '4%' AND is_active = 1");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($accounts);
?>