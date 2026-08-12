<?php
require_once __DIR__ . '/includes/db.php';

echo "<h2>تطبيق ترحيل نظام الموردين...</h2>";

$sqlFile = __DIR__ . '/database/migrations/2026_08_11_012_supplier_trade_name_and_services.sql';

if (!file_exists($sqlFile)) {
    die("ملف الترحيل غير موجود: $sqlFile");
}

$sql = file_get_contents($sqlFile);

$statements = array_filter(array_map('trim', explode(";\n", $sql)));

$successCount = 0;
$errorCount = 0;
$errors = [];

foreach ($statements as $index => $stmt) {
    if (empty($stmt) || strpos($stmt, '--') === 0 || strpos($stmt, 'SET @') === 0 || strpos($stmt, 'PREPARE') === 0 || strpos($stmt, 'EXECUTE') === 0 || strpos($stmt, 'DEALLOCATE') === 0) {
        continue;
    }
    try {
        $pdo->exec($stmt);
        $successCount++;
    } catch (Exception $e) {
        $errorCount++;
        $errors[] = "Statement #" . ($index + 1) . ": " . $e->getMessage();
    }
}

echo "<h3>نتيجة التنفيذ:</h3>";
echo "<p><strong>الاستعلامات الناجحة:</strong> $successCount</p>";
echo "<p><strong>الاستعلامات التي فشلت:</strong> $errorCount</p>";

if (!empty($errors)) {
    echo "<h4>الأخطاء (قد تكون طبيعية بسبب Idempotency):</h4>";
    echo "<ul>";
    foreach ($errors as $err) {
        echo "<li>" . htmlspecialchars($err) . "</li>";
    }
    echo "</ul>";
}

echo "<hr>";

echo "<h3>التحقق من الجداول والأعمدة:</h3>";

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM suppliers LIKE 'trade_name'");
    if ($stmt->fetch()) {
        echo "<p style='color:green'>✅ حقل trade_name مضاف بنجاح إلى جدول suppliers</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>❌ خطأ: " . htmlspecialchars($e->getMessage()) . "</p>";
}

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'catalog_services'");
    if ($stmt->fetch()) {
        echo "<p style='color:green'>✅ جدول catalog_services موجود</p>";
        $count = $pdo->query("SELECT COUNT(*) FROM catalog_services")->fetchColumn();
        echo "<p>عدد الخدمات المسجلة: $count</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>❌ خطأ: " . htmlspecialchars($e->getMessage()) . "</p>";
}

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'supplier_services'");
    if ($stmt->fetch()) {
        echo "<p style='color:green'>✅ جدول supplier_services موجود</p>";
        $count = $pdo->query("SELECT COUNT(*) FROM supplier_services")->fetchColumn();
        echo "<p>عدد الروابط المورد-الخدمة: $count</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>❌ خطأ: " . htmlspecialchars($e->getMessage()) . "</p>";
}

try {
    $stmt = $pdo->query("SELECT * FROM catalog_services ORDER BY sort_order");
    $services = $stmt->fetchAll();
    echo "<h4>الخدمات المتاحة:</h4>";
    echo "<table border='1'>";
    echo "<tr><th>Code</th><th>الاسم العربي</th><th>الحالة</th></tr>";
    foreach ($services as $s) {
        echo "<tr>
                <td>{$s['service_code']}</td>
                <td>{$s['service_name_ar']}</td>
                <td>" . ($s['is_active'] ? 'نشط' : 'غير نشط') . "</td>
              </tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color:red'>❌ خطأ: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr><p style='font-weight:bold'>تم الانتهاء من تطبيق الترحيل.</p>";
?>
