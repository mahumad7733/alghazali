<?php
/**
 * سكربت اختبار سريع: تنفى ensure_system_admin_tables()
 * للتحقق من إنشاء الجداول بدون أخطاء SQL.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

echo "<pre>";
echo "=== بدء اختبار إنشاء جداول إدارة النظام ===\n";
try {
    ensure_system_admin_tables();
    echo "✅ تم تنفيذ ensure_system_admin_tables() بنجاح بدون أخطاء!\n\n";

    // قائمة الجداول المطلوبة
    $required_tables = [
        'system_error_audit',
        'security_vulnerabilities',
        'security_events',
        'system_performance_logs',
        'system_health_logs',
        'backup_records',
        'financial_transaction_audit',
        'login_attempts',
    ];

    global $pdo;
    $all_exists = true;
    foreach ($required_tables as $tbl) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tbl]);
        if ($stmt->rowCount() > 0) {
            echo "✅ الجدول `$tbl` موجود\n";
        } else {
            echo "❌ الجدول `$tbl` غير موجود!\n";
            $all_exists = false;
        }
    }

    echo "\n=== النتيجة النهائية ===\n";
    if ($all_exists) {
        echo "🎉 جميع الجداول تم إنشاؤها بنجاح!\n";
    } else {
        echo "⚠️ بعض الجداول غير موجودة.\n";
    }

    // فحص أعمدة system_error_audit الجديدة
    echo "\n=== فحص أعمدة system_error_audit ===\n";
    $cols_stmt = $pdo->query("SHOW COLUMNS FROM system_error_audit");
    $cols = $cols_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $required_cols = ['error_fingerprint', 'occurrences', 'priority', 'status', 'ticket_ref', 'repair_notes', 'environment'];
    foreach ($required_cols as $c) {
        if (in_array($c, $cols)) {
            echo "✅ العمود `$c` موجود\n";
        } else {
            echo "❌ العمود `$c` غير موجود!\n";
        }
    }

} catch (\Throwable $e) {
    echo "❌ خطأ أثناء الاختبار: " . $e->getMessage() . "\n";
    echo "الملف: " . $e->getFile() . " السطر: " . $e->getLine() . "\n";
    echo "التتبع:\n" . $e->getTraceAsString() . "\n";
}
echo "</pre>";
