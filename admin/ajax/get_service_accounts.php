<?php
require_once '../../includes/db.php';
require_once '../../includes/functions.php';

$source_type = $_GET['source_type'] ?? '';

// جلب الإعدادات
$settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$config = getServiceInvoiceConfig($source_type, $settings);

header('Content-Type: application/json');
echo json_encode($config);
?>