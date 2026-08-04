<?php
require_once __DIR__ . '/includes/db.php';

$test_settings = [
    'enable_postal_services' => 0,
    'enable_hajj' => 0,
    'enable_crm' => 0
];

echo "=== Testing save ===" . PHP_EOL;
foreach ($test_settings as $key => $value) {
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'general') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $result = $stmt->execute([$key, $value]);
    echo "Saved $key = $value: " . ($result ? "OK" : "FAILED") . PHP_EOL;
}

echo PHP_EOL . "=== Current values ===" . PHP_EOL;
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('enable_postal_services', 'enable_hajj', 'enable_crm')");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['setting_key']} = {$row['setting_value']}" . PHP_EOL;
}
?>