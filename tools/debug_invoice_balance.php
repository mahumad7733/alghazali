<?php
require_once 'includes/db.php';

echo "<h1>تصحيح عرض الرصيد في الفواتير</h1>";

// 1. الحصول على العملة الأساسية
$stmt_base = $pdo->query("SELECT * FROM currencies WHERE is_default = 1");
$base_curr = $stmt_base->fetch(PDO::FETCH_ASSOC);
echo "<h3>العملة الأساسية: " . htmlspecialchars($base_curr['currency_name']) . " (سعر الصرف: " . $base_curr['exchange_rate'] . ")</h3>";

// 2. عرض مثال: المورد الأول مع حركات
echo "<h2>1. مثال: أحد الموردين</h2>";
$stmt_supplier = $pdo->query("
    SELECT ua.*, s.supplier_name 
    FROM unified_accounts ua 
    JOIN suppliers s ON ua.id = s.account_id
    LIMIT 1
");
$supplier = $stmt_supplier->fetch(PDO::FETCH_ASSOC);

if ($supplier) {
    echo "<p><strong>المورد:</strong> " . htmlspecialchars($supplier['supplier_name']) . " (كود حساب: " . $supplier['account_code'] . ")</p>";
    echo "<p><strong>طبيعة الحساب:</strong> " . ($supplier['normal_balance'] === 'debit' ? 'مدين (asset/سند)' : 'دائن (liability/مورد)') . "</p>";
    
    // جلب جميع حركات دفتر اليومية
    $stmt_jl = $pdo->prepare("
        SELECT 
            jl.*, 
            ft.transaction_number, ft.reference_number, ft.transaction_date, ft.status,
            c.currency_code, c.currency_symbol, c.exchange_rate
        FROM journal_lines jl
        JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
        JOIN currencies c ON jl.currency_id = c.id
        WHERE jl.account_id = ?
        ORDER BY jl.id
    ");
    $stmt_jl->execute([$supplier['id']]);
    $jls = $stmt_jl->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($jls) > 0) {
        echo "<h4>القيود اليومية:</h4>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'><tr style='background: #eee;'>
            <th>رقم المعاملة</th><th>تاريخ</th><th>حالة</th><th>العملة</th><th>مدين</th><th>دائن</th><th>الفرق (مدين-دائن)</th><th>الفرق بالعملة الأساسية</th>
        </tr>";
        
        $total_net = 0;
        $total_net_base = 0;
        
        foreach ($jls as $jl) {
            $net = $jl['debit'] - $jl['credit'];
            $net_base = $net * $jl['exchange_rate'];
            $total_net += $net;
            $total_net_base += $net_base;
            
            echo "<tr>
                <td>" . htmlspecialchars($jl['transaction_number']) . "</td>
                <td>" . htmlspecialchars($jl['transaction_date']) . "</td>
                <td>" . htmlspecialchars($jl['status']) . "</td>
                <td>" . htmlspecialchars($jl['currency_code']) . "</td>
                <td style='text-align:right;'>" . number_format($jl['debit'], 2) . "</td>
                <td style='text-align:right;'>" . number_format($jl['credit'], 2) . "</td>
                <td style='text-align:right;'>" . number_format($net, 2) . "</td>
                <td style='text-align:right;'>" . number_format($net_base, 2) . "</td>
            </tr>";
        }
        
        echo "</table>";
        echo "<p><strong>الرصيد الإجمالي بالعملة الأصلية:</strong> " . number_format($total_net, 2) . "</p>";
        echo "<p><strong>الرصيد الإجمالي بالعملة الأساسية:</strong> " . number_format($total_net_base, 2) . "</p>";
        
        // تحديد الحالة
        $normal_balance = $supplier['normal_balance'];
        echo "<p><strong>طبيعة الحساب:</strong> " . htmlspecialchars($normal_balance) . "</p>";
        
        if (abs($total_net_base) < 0.01) {
            echo "<p><strong>الحالة:</strong> متعادل</p>";
        } else {
            if ($normal_balance === 'debit') {
                if ($total_net_base > 0) {
                    echo "<p><strong>الحالة:</strong> عليه (العميل لنا) - Red</p>";
                } else {
                    echo "<p><strong>الحالة:</strong> له (للعميل) - Green</p>";
                }
            } else { // دائن (مورد)
                if ($total_net_base > 0) {
                    echo "<p><strong>الحالة:</strong> له عندنا (للمورد) - Red</p>";
                } else {
                    echo "<p><strong>الحالة:</strong> لنا عنده (لنا) - Green</p>";
                }
            }
        }
        
    } else {
        echo "<p>لا توجد حركات لهذا الحساب.</p>";
    }
}

echo "<hr>";

// 3. مثال: أحد العملاء
echo "<h2>2. مثال: أحد العملاء</h2>";
$stmt_customer = $pdo->query("
    SELECT ua.*, c.customer_name 
    FROM unified_accounts ua 
    JOIN customers c ON ua.id = c.account_id
    LIMIT 1
");
$customer = $stmt_customer->fetch(PDO::FETCH_ASSOC);

if ($customer) {
    echo "<p><strong>العميل:</strong> " . htmlspecialchars($customer['customer_name']) . " (كود حساب: " . $customer['account_code'] . ")</p>";
    echo "<p><strong>طبيعة الحساب:</strong> " . ($customer['normal_balance'] === 'debit' ? 'مدين (asset/سند)' : 'دائن (liability/مورد)') . "</p>";
    
    $stmt_jl = $pdo->prepare("
        SELECT 
            jl.*, 
            ft.transaction_number, ft.reference_number, ft.transaction_date, ft.status,
            c.currency_code, c.currency_symbol, c.exchange_rate
        FROM journal_lines jl
        JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
        JOIN currencies c ON jl.currency_id = c.id
        WHERE jl.account_id = ?
        ORDER BY jl.id
    ");
    $stmt_jl->execute([$customer['id']]);
    $jls = $stmt_jl->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($jls) > 0) {
        echo "<h4>القيود اليومية:</h4>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'><tr style='background: #eee;'>
            <th>رقم المعاملة</th><th>تاريخ</th><th>حالة</th><th>العملة</th><th>مدين</th><th>دائن</th><th>الفرق (مدين-دائن)</th><th>الفرق بالعملة الأساسية</th>
        </tr>";
        
        $total_net = 0;
        $total_net_base = 0;
        
        foreach ($jls as $jl) {
            $net = $jl['debit'] - $jl['credit'];
            $net_base = $net * $jl['exchange_rate'];
            $total_net += $net;
            $total_net_base += $net_base;
            
            echo "<tr>
                <td>" . htmlspecialchars($jl['transaction_number']) . "</td>
                <td>" . htmlspecialchars($jl['transaction_date']) . "</td>
                <td>" . htmlspecialchars($jl['status']) . "</td>
                <td>" . htmlspecialchars($jl['currency_code']) . "</td>
                <td style='text-align:right;'>" . number_format($jl['debit'], 2) . "</td>
                <td style='text-align:right;'>" . number_format($jl['credit'], 2) . "</td>
                <td style='text-align:right;'>" . number_format($net, 2) . "</td>
                <td style='text-align:right;'>" . number_format($net_base, 2) . "</td>
            </tr>";
        }
        
        echo "</table>";
        echo "<p><strong>الرصيد الإجمالي بالعملة الأصلية:</strong> " . number_format($total_net, 2) . "</p>";
        echo "<p><strong>الرصيد الإجمالي بالعملة الأساسية:</strong> " . number_format($total_net_base, 2) . "</p>";
        
        // تحديد الحالة
        $normal_balance = $customer['normal_balance'];
        echo "<p><strong>طبيعة الحساب:</strong> " . htmlspecialchars($normal_balance) . "</p>";
        
        if (abs($total_net_base) < 0.01) {
            echo "<p><strong>الحالة:</strong> متعادل</p>";
        } else {
            if ($normal_balance === 'debit') {
                if ($total_net_base > 0) {
                    echo "<p><strong>الحالة:</strong> عليه (العميل لنا) - Red</p>";
                } else {
                    echo "<p><strong>الحالة:</strong> له (للعميل) - Green</p>";
                }
            } else { // دائن
                if ($total_net_base > 0) {
                    echo "<p><strong>الحالة:</strong> له عندنا - Red</p>";
                } else {
                    echo "<p><strong>الحالة:</strong> لنا عنده - Green</p>";
                }
            }
        }
        
    } else {
        echo "<p>لا توجد حركات لهذا الحساب.</p>";
    }
}
?>