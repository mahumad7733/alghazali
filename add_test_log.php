<?php
// No output headers, just CLI script
require_once __DIR__ . '/includes/db.php';

echo "Adding test activity log...\n";

// Get first user
$stmt = $pdo->query("SELECT id FROM users LIMIT 1");
$user = $stmt->fetch();

if ($user) {
    $insert = $pdo->prepare("
        INSERT INTO user_activity_logs 
        (user_id, activity_type, activity_description, ip_address, user_agent, device_type, browser, os, timezone)
        VALUES 
        (?, 'login', 'تسجيل دخول تجريبي من سكريبت', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0', 'desktop', 'Firefox', 'Windows', 'Asia/Riyadh')
    ");
    $insert->execute([$user['id']]);
    echo "✅ Done! Added test log for user ID: {$user['id']}\n";
} else {
    echo "❌ No users found in database!\n";
}
?>
