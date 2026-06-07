<?php
require_once '../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_id'])) {
    die("يرجى تسجيل الدخول أولاً.");
}

try {
    $pdo->beginTransaction();

    // 1. تصفير جميع الأرصدة الحالية في الجدول الموحد
    $pdo->exec("UPDATE account_balances_unified SET current_balance = 0");

    // 2. إعادة احتساب الأرصدة بناءً على قيود اليومية (journal_lines)
    // نحن نستخدم SUM للديون والائتمانات بناءً على طبيعة الحساب (مدين/دائن)
    $sql = "
        INSERT INTO account_balances_unified (account_id, currency_id, current_balance, opening_balance)
        SELECT 
            jl.account_id, 
            jl.currency_id, 
            SUM(CASE WHEN ua.normal_balance = 'debit' THEN (jl.debit - jl.credit) ELSE (jl.credit - jl.debit) END) as new_balance,
            0
        FROM journal_lines jl
        JOIN unified_accounts ua ON jl.account_id = ua.id
        GROUP BY jl.account_id, jl.currency_id
        ON DUPLICATE KEY UPDATE current_balance = VALUES(current_balance)
    ";
    
    $pdo->exec($sql);

    $pdo->commit();
    echo "تمت إعادة احتساب جميع أرصدة الحسابات بنجاح بناءً على العمليات المحاسبية الفعلية.";
    echo "<br><br><a href='manage_currency_balances.php'>العودة لإدارة الأرصدة</a>";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("خطأ أثناء إعادة الاحتساب: " . $e->getMessage());
}
