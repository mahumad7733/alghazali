<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

echo "=== Current enable_postal_services ===\n";
$settings = getSettings($pdo);
echo "enable_postal_services: " . ($settings['enable_postal_services'] ?? 'not set') . "\n";

echo "\n=== Setting enable_postal_services to 0 ===\n";
$stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'general') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
$stmt->execute(['enable_postal_services', 0]);

echo "\n=== Getting settings again ===\n";
$settings2 = getSettings($pdo);
echo "enable_postal_services: " . ($settings2['enable_postal_services'] ?? 'not set') . "\n";

// Reset back to 1
echo "\n=== Resetting enable_postal_services to 1 ===\n";
$stmt->execute(['enable_postal_services', 1]);
