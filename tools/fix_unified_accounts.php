<?php
require_once 'includes/db.php';

echo "<h1>إصلاح مشاكل شجرة الحسابات</h1>";

try {
    $pdo->beginTransaction();

    // --------------------------------------------------------------
    // 1. تصحيح حسابات العملاء (من asset إلى receivable)
    // --------------------------------------------------------------
    echo "<h3>1. تصحيح حسابات العملاء (من asset إلى receivable)...</h3>";
    $update_customers = $pdo->prepare("
        UPDATE unified_accounts 
        SET account_type = 'receivable' 
        WHERE parent_id IN (
            SELECT id FROM unified_accounts WHERE account_code = '11201'
        )
    ");
    $result = $update_customers->execute();
    echo "<p>✅ تم تحديث " . $update_customers->rowCount() . " حساب عميل</p>";

    // --------------------------------------------------------------
    // 2. تصحيح حسابات الموردين (من liability إلى payable)
    // --------------------------------------------------------------
    echo "<h3>2. تصحيح حسابات الموردين (من liability إلى payable)...</h3>";
    $update_suppliers = $pdo->prepare("
        UPDATE unified_accounts 
        SET account_type = 'payable' 
        WHERE parent_id IN (
            SELECT id FROM unified_accounts WHERE account_code = '21101'
        )
    ");
    $result = $update_suppliers->execute();
    echo "<p>✅ تم تحديث " . $update_suppliers->rowCount() . " حساب مورد</p>";

    // --------------------------------------------------------------
    // 3. إلغاء تنشيط الحسابات المكررة
    // --------------------------------------------------------------
    echo "<h3>3. إلغاء تنشيط الحسابات المكررة...";
    $deactivate_duplicates = $pdo->prepare("
        UPDATE unified_accounts 
        SET account_status = 'inactive' 
        WHERE account_code IN ('400005', '500005')
    ");
    $result = $deactivate_duplicates->execute();
    echo "<p>✅ تم إلغاء تنشيط " . $deactivate_duplicates->rowCount() . " حساب مكرر</p>";

    // --------------------------------------------------------------
    // 4. عرض الحالة النهائية للتأكيد
    // --------------------------------------------------------------
    echo "<h3>4. حالة الحسابات بعد الإصلاح:</h3>";

    $query_check = $pdo->prepare("
        SELECT id, account_code, account_name_ar, account_type, account_status, parent_id
        FROM unified_accounts
        ORDER BY account_code
    ");
    $query_check->execute();
    $accounts = $query_check->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-top:20px;'>";
    echo "<tr style='background-color: #f2f2f2;'>
            <th>ID</th>
            <th>الكود</th>
            <th>الاسم</th>
            <th>النوع</th>
            <th>الحالة</th>
            <th>أب (ID)</th>
          </tr>";

    foreach ($accounts as $acc) {
        echo "<tr>
                <td>" . $acc['id'] . "</td>
                <td>" . htmlspecialchars($acc['account_code']) . "</td>
                <td>" . htmlspecialchars($acc['account_name_ar']) . "</td>
                <td>" . htmlspecialchars($acc['account_type']) . "</td>
                <td>" . htmlspecialchars($acc['account_status']) . "</td>
                <td>" . ($acc['parent_id'] ?: 'NULL') . "</td>
              </tr>";
    }

    echo "</table>";

    $pdo->commit();
    echo "<h2 style='color: green; margin-top:30px;'>✅ تم إصلاح جميع المشاكل بنجاح!</h2>";
    echo "<p><a href='admin/financial_accounts.php'>الرجوع إلى شجرة الحسابات</a></p>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<h2 style='color: red;'>❌ خطأ: " . $e->getMessage() . "</h2>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>