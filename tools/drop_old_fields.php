<?php
$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db   = 'ghazali';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ تم الاتصال بقاعدة البيانات!\n\n";
    
    // Tables and their old fields to drop
    $tablesToClean = [
        'family_visit_requests' => ['sale_price', 'cost_price', 'currency_id', 'payment_type', 'revenue_entry_id', 'cost_entry_id'],
        'family_visit_individuals' => ['agent_price', 'branch_price', 'sale_price'],
        'bus_flight_bookings' => ['sale_price', 'cost_price', 'currency_id', 'payment_type', 'payment_status', 'amount_received', 'discount', 'tax_rate', 'tax_amount', 'net_amount', 'revenue_entry_id', 'cost_entry_id'],
        'passport_transactions' => ['sale_price', 'cost_price', 'currency_id', 'payment_type', 'payment_status', 'amount_received'],
        'passports' => ['revenue_entry_id', 'cost_entry_id'],
    ];
    
    foreach ($tablesToClean as $table => $fields) {
        echo "=== فحص الجدول: $table\n";
        foreach ($fields as $field) {
            // Check if column exists
            $check = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$field'");
            if ($check->fetch()) {
                echo "  ⏳ حذف الحقل: $field\n";
                try {
                    $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$field`");
                    echo "  ✅ تم حذف الحقل بنجاح!\n";
                } catch (Exception $e) {
                    echo "  ⚠️ لم يتم الحذف: " . $e->getMessage() . "\n";
                }
            } else {
                echo "  ℹ️ الحقل $field غير موجود، تخطي.\n";
            }
        }
        echo "\n";
    }
    
    echo "\n🎉 تم الانتهاء من تنظيف جميع الحقول القديمة!\n";
    
} catch (PDOException $e) {
    echo "❌ خطأ: " . $e->getMessage() . "\n";
}
