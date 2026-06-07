<?php
require_once '../includes/db.php';

echo "<h1 style='text-align: center; font-family: Arial, sans-serif;'>محتوى جدول الخدمات الحالي (services)</h1>";

// Get all services
$stmt = $pdo->query("SELECT * FROM services ORDER BY id ASC");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($services) === 0) {
    echo "<p style='text-align: center; color: #666; font-family: Arial, sans-serif;'>لا توجد خدمات في الجدول حالياً.</p>";
} else {
    echo "<div style='overflow-x: auto; margin: 20px; font-family: Arial, sans-serif;'>";
    echo "<table style='width: 100%; border-collapse: collapse; border: 1px solid #ddd;'>";
    
    // Table header
    echo "<thead><tr style='background-color: #007bff; color: white;'>";
    foreach (array_keys($services[0]) as $col) {
        echo "<th style='padding: 12px; text-align: right; border: 1px solid #ddd;'>" . htmlspecialchars($col) . "</th>";
    }
    echo "</tr></thead>";
    
    // Table body
    echo "<tbody>";
    foreach ($services as $row) {
        echo "<tr style='border-bottom: 1px solid #ddd;'>";
        foreach ($row as $value) {
            echo "<td style='padding: 10px; text-align: right; border-right: 1px solid #ddd;'>" . htmlspecialchars($value ?? '-') . "</td>";
        }
        echo "</tr>";
    }
    echo "</tbody></table></div>";
}

// Show table structure
echo "<hr><h2 style='text-align: center; font-family: Arial, sans-serif;'>بنية جدول الخدمات الحالي</h2>";
$stmt_desc = $pdo->query("DESCRIBE services");
$columns = $stmt_desc->fetchAll(PDO::FETCH_ASSOC);

echo "<div style='overflow-x: auto; margin: 20px; font-family: Arial, sans-serif;'>";
echo "<table style='width: 100%; border-collapse: collapse; border: 1px solid #ddd;'>";
echo "<thead><tr style='background-color: #28a745; color: white;'>";
echo "<th style='padding: 12px; text-align: right; border: 1px solid #ddd;'>اسم الحقل</th>";
echo "<th style='padding: 12px; text-align: right; border: 1px solid #ddd;'>النوع</th>";
echo "<th style='padding: 12px; text-align: right; border: 1px solid #ddd;'>NULL؟</th>";
echo "<th style='padding: 12px; text-align: right; border: 1px solid #ddd;'>المفتاح</th>";
echo "<th style='padding: 12px; text-align: right; border: 1px solid #ddd;'>القيمة الافتراضية</th>";
echo "<th style='padding: 12px; text-align: right; border: 1px solid #ddd;'>إضافي</th>";
echo "</tr></thead>";

echo "<tbody>";
foreach ($columns as $col) {
    echo "<tr style='border-bottom: 1px solid #ddd;'>";
    echo "<td style='padding: 10px; text-align: right; border-right: 1px solid #ddd;'><strong>" . htmlspecialchars($col['Field']) . "</strong></td>";
    echo "<td style='padding: 10px; text-align: right; border-right: 1px solid #ddd;'>" . htmlspecialchars($col['Type']) . "</td>";
    echo "<td style='padding: 10px; text-align: right; border-right: 1px solid #ddd;'>" . htmlspecialchars($col['Null']) . "</td>";
    echo "<td style='padding: 10px; text-align: right; border-right: 1px solid #ddd;'>" . htmlspecialchars($col['Key']) . "</td>";
    echo "<td style='padding: 10px; text-align: right; border-right: 1px solid #ddd;'>" . htmlspecialchars($col['Default']) . "</td>";
    echo "<td style='padding: 10px; text-align: right; border-right: 1px solid #ddd;'>" . htmlspecialchars($col['Extra']) . "</td>";
    echo "</tr>";
}
echo "</tbody></table></div>";
?>