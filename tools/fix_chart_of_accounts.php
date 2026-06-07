<?php
require_once 'includes/db.php';

echo "<h1>Fixing Chart of Accounts (Standard Structure)</h1>";

try {
    $pdo->beginTransaction();

    // Step 1: Insert intermediate accounts if they don't exist!
    $intermediateAccounts = [
        ['11101', 'الصناديق', 'asset', 'debit', null, 'active'],
        ['11102', 'البنوك', 'asset', 'debit', null, 'active'],
        ['11103', 'النقد الأجنبي', 'asset', 'debit', null, 'active'],
        ['11201', 'العملاء', 'asset', 'debit', null, 'active'],
        ['11202', 'حسابات الفروع', 'asset', 'debit', null, 'active'],
        ['11203', 'الوكلاء', 'asset', 'debit', null, 'active'],
        ['11204', 'دفعات مقدمة للموردين', 'asset', 'debit', null, 'active'],
        ['11301', 'السلف والعهد', 'asset', 'debit', null, 'active'],
        ['12101', 'الأثاث', 'asset', 'debit', null, 'active'],
        ['12102', 'أجهزة الكمبيوتر', 'asset', 'debit', null, 'active'],
        ['12103', 'السيارات', 'asset', 'debit', null, 'active'],
        ['12104', 'الديكورات والتجهيزات', 'asset', 'debit', null, 'active'],
        ['12201', 'مجمع الإهلاك', 'asset', 'credit', null, 'active'],
        ['21101', 'الموردين', 'liability', 'credit', null, 'active'],
        ['21102', 'دفعات مقدمة من العملاء', 'liability', 'credit', null, 'active'],
        ['21103', 'مستحقات الموظفين', 'liability', 'credit', null, 'active'],
        ['21104', 'ضريبة القيمة المضافة', 'liability', 'credit', null, 'active'],
        ['21105', 'المصروفات المستحقة', 'liability', 'credit', null, 'active'],
        ['30201', 'أرباح مرحلة', 'equity', 'credit', null, 'active'],
        ['30301', 'ملخص الدخل السنوي', 'equity', 'credit', null, 'active'],
        ['40101', 'إيرادات الخدمات', 'income', 'credit', null, 'active'],
        ['40102', 'إيرادات العمولات', 'income', 'credit', null, 'active'],
        ['40201', 'أرباح فروقات العملة', 'income', 'credit', null, 'active'],
        ['50101', 'تكاليف الخدمات', 'expense', 'debit', null, 'active'],
        ['50201', 'الرواتب والأجور', 'expense', 'debit', null, 'active'],
        ['50202', 'الإيجارات', 'expense', 'debit', null, 'active'],
        ['50203', 'الكهرباء والمياه', 'expense', 'debit', null, 'active'],
        ['50204', 'الإنترنت والاتصالات', 'expense', 'debit', null, 'active'],
        ['50205', 'التسويق والإعلانات', 'expense', 'debit', null, 'active'],
        ['50206', 'النقل والمحروقات', 'expense', 'debit', null, 'active'],
        ['50207', 'الصيانة', 'expense', 'debit', null, 'active'],
        ['50208', 'الضيافة', 'expense', 'debit', null, 'active'],
        ['50209', 'الإهلاك', 'expense', 'debit', null, 'active'],
        ['50301', 'خسائر فروقات العملة', 'expense', 'debit', null, 'active']
    ];

    // First, get the parent IDs!
    $id_111 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '111'")->fetchColumn();
    $id_112 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '112'")->fetchColumn();
    $id_113 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '113'")->fetchColumn();
    $id_121 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '121'")->fetchColumn();
    $id_122 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '122'")->fetchColumn();
    $id_211 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '211'")->fetchColumn();
    $id_302 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '302'")->fetchColumn();
    $id_303 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '303'")->fetchColumn();
    $id_401 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '401'")->fetchColumn();
    $id_402 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '402'")->fetchColumn();
    $id_501 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '501'")->fetchColumn();
    $id_502 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '502'")->fetchColumn();
    $id_503 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '503'")->fetchColumn();

    // Assign parent IDs based on account code prefix
    $parentIdMap = [
        '1110' => $id_111,
        '11101' => null,
        '11102' => null,
        '11103' => null,
        '1120' => $id_112,
        '11201' => null,
        '11202' => null,
        '11203' => null,
        '11204' => null,
        '1130' => $id_113,
        '1210' => $id_121,
        '1220' => $id_122,
        '2110' => $id_211,
        '3020' => $id_302,
        '3030' => $id_303,
        '4010' => $id_401,
        '4020' => $id_402,
        '5010' => $id_501,
        '5020' => $id_502,
        '5030' => $id_503
    ];

    foreach ($intermediateAccounts as $acc) {
        list($code, $name, $type, $normalBalance, $dummyParent, $status) = $acc;
        
        // Determine parent ID based on code length
        if (substr($code, 0, 5) === '11101') {
            $parentId = $id_111;
        } else if (substr($code, 0, 5) === '11102') {
            $parentId = $id_111;
        } else if (substr($code, 0, 5) === '11103') {
            $parentId = $id_111;
        } else if (substr($code, 0, 5) === '11201') {
            $parentId = $id_112;
        } else if (substr($code, 0, 5) === '11202') {
            $parentId = $id_112;
        } else if (substr($code, 0, 5) === '11203') {
            $parentId = $id_112;
        } else if (substr($code, 0, 5) === '11204') {
            $parentId = $id_112;
        } else if (substr($code, 0, 4) === '1130') {
            $parentId = $id_113;
        } else if (substr($code, 0, 4) === '1210') {
            $parentId = $id_121;
        } else if (substr($code, 0, 4) === '1220') {
            $parentId = $id_122;
        } else if (substr($code, 0, 4) === '2110') {
            $parentId = $id_211;
        } else if (substr($code, 0, 4) === '3020') {
            $parentId = $id_302;
        } else if (substr($code, 0, 4) === '3030') {
            $parentId = $id_303;
        } else if (substr($code, 0, 4) === '4010') {
            $parentId = $id_401;
        } else if (substr($code, 0, 4) === '4020') {
            $parentId = $id_402;
        } else if (substr($code, 0, 4) === '5010') {
            $parentId = $id_501;
        } else if (substr($code, 0, 4) === '5020') {
            $parentId = $id_502;
        } else if (substr($code, 0, 4) === '5030') {
            $parentId = $id_503;
        } else {
            $parentId = null;
        }

        // Check if account exists
        $checkStmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
        $checkStmt->execute([$code]);
        if (!$checkStmt->fetch()) {
            echo "<p>Adding account: $code - $name</p>";
            $insertStmt = $pdo->prepare("
                INSERT INTO unified_accounts (account_code, account_name_ar, account_type, normal_balance, parent_id, account_status)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([$code, $name, $type, $normalBalance, $parentId, $status]);
            $accId = $pdo->lastInsertId();
            
            // Activate base currency for this new account if base currency is set!
            if ($accId) {
                $baseCurrencyQuery = $pdo->query("SELECT id FROM currencies WHERE is_default = 1 LIMIT 1");
                $baseCurrencyId = $baseCurrencyQuery->fetchColumn();
                if ($baseCurrencyId) {
                    $stmtBaseBalance = $pdo->prepare("
                        INSERT INTO account_balances_unified (account_id, currency_id, opening_balance, current_balance, is_frozen) 
                        VALUES (?, ?, 0, 0, 0)
                    ");
                    $stmtBaseBalance->execute([$accId, $baseCurrencyId]);
                }
            }
        } else {
            echo "<p>Updating account: $code - $name</p>";
            $updateStmt = $pdo->prepare("
                UPDATE unified_accounts 
                SET account_name_ar = ?, parent_id = ?, account_status = ? 
                WHERE account_code = ?
            ");
            $updateStmt->execute([$name, $parentId, $status, $code]);
        }
    }

    // Now, move existing boxes under 11101, existing banks under 11102!
    $id_11101 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '11101'")->fetchColumn();
    $id_11102 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '11102'")->fetchColumn();

    echo "<p>Moving existing boxes under 11101...</p>";
    $stmtMoveBoxes = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code LIKE '101%' AND account_code != '101'");
    $stmtMoveBoxes->execute([$id_11101]);

    echo "<p>Moving existing banks under 11102...</p>";
    $stmtMoveBanks = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code LIKE '102%' AND account_code != '102'");
    $stmtMoveBanks->execute([$id_11102]);

    // Fix remaining top-level structure!
    echo "<p>Fixing remaining top-level accounts...</p>";
    $id_1 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '1'")->fetchColumn();
    $id_11 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '11'")->fetchColumn();
    $id_2 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '2'")->fetchColumn();
    $id_21 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '21'")->fetchColumn();
    $id_3 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '3'")->fetchColumn();
    $id_4 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '4'")->fetchColumn();
    $id_5 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '5'")->fetchColumn();
    
    $stmtFix12 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '12'");
    $stmtFix12->execute([$id_1]);

    $stmtFix111 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '111'");
    $stmtFix111->execute([$id_11]);

    $stmtFix112 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '112'");
    $stmtFix112->execute([$id_11]);

    $stmtFix113 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '113'");
    $stmtFix113->execute([$id_11]);

    $stmtFix114 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '114'");
    $stmtFix114->execute([$id_11]);

    $stmtFix121 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '121'");
    $stmtFix121->execute([$id_12]);

    $stmtFix122 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '122'");
    $stmtFix122->execute([$id_12]);

    $stmtFix21 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '21'");
    $stmtFix21->execute([$id_2]);

    $stmtFix211 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '211'");
    $stmtFix211->execute([$id_21]);

    $stmtFix301 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '301'");
    $stmtFix301->execute([$id_3]);

    $stmtFix302 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '302'");
    $stmtFix302->execute([$id_3]);

    $stmtFix303 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '303'");
    $stmtFix303->execute([$id_3]);

    $stmtFix401 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '401'");
    $stmtFix401->execute([$id_4]);

    $stmtFix402 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '402'");
    $stmtFix402->execute([$id_4]);

    $stmtFix501 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '501'");
    $stmtFix501->execute([$id_5]);

    $stmtFix502 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '502'");
    $stmtFix502->execute([$id_5]);

    $stmtFix503 = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = '503'");
    $stmtFix503->execute([$id_5]);

    $pdo->commit();
    echo "<h2 style='color:green'>✅ Chart of accounts fixed successfully!</h2>";
    echo "<p><a href='admin/financial_accounts.php?repair_tree=1'>Click here to repair the account tree (optional)</a></p>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "<h2 style='color:red'>❌ Error: " . $e->getMessage() . "</h2>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>