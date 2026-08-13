<?php
require_once 'header.php';
require_once '../includes/accounting_functions.php';
require_once '../includes/CurrencyExchange.php';

$currencyExchange = new CurrencyExchange($pdo);
$baseCurrency = $currencyExchange->getBaseCurrency();
$base_currency_id = $baseCurrency['id'] ?? null;

// التحقق من الصلاحية
if (!has_permission('manage_financial_accounts')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

if (isset($_GET['deactivate']) || isset($_GET['delete_permanent'])) {
    $error = "تم تعطيل تنفيذ الإجراءات الحساسة عبر الرابط المباشر. استخدم النماذج الداخلية المحمية فقط.";
}

$catalog_services = $pdo->query("SELECT id, service_code, service_name_ar FROM catalog_services WHERE is_active = 1 AND deleted_at IS NULL ORDER BY sort_order ASC")->fetchAll();

// إضافة مورد جديد
if (isset($_POST['add_supplier_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='suppliers.php';</script>");
    }
    $account_name = $_POST['account_name'];
    $trade_name = trim($_POST['trade_name'] ?? '') ?: null;
    $branch_id = $_POST['branch_id'] ?: null;
    $supplier_phone = $_POST['supplier_phone'] ?? null;
    $address = $_POST['address'] ?? null;
    $link = $_POST['link'] ?? null;
    $opening_balance = $_POST['opening_balance'] ?? 0;
    $currency_id = $_POST['currency_id'] ?? 1;
    $status = $_POST['status'] == 'active' ? 'active' : $_POST['status'];
    $supplier_email = $_POST['supplier_email'] ?? null;
    $supplier_services = $_POST['supplier_services'] ?? [];
    if (!is_array($supplier_services)) $supplier_services = [];

    try {
        $pdo->beginTransaction();

        $parent_stmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101'");
        $parent_stmt->execute();
        $parent_id = $parent_stmt->fetchColumn();

        if (!$parent_id) throw new Exception("الحساب الرئيسي للموردين (21101) غير موجود.");

        $stmt_check_name = $pdo->prepare("SELECT COUNT(*) FROM unified_accounts WHERE account_name_ar = ? AND parent_id = ?");
        $stmt_check_name->execute([$account_name, $parent_id]);
        if ($stmt_check_name->fetchColumn() > 0) {
            throw new Exception("اسم المورد موجود بالفعل.");
        }

        $stmt_last = $pdo->prepare("SELECT MAX(account_code) FROM unified_accounts WHERE parent_id = ? AND account_code LIKE '21101%'");
        $stmt_last->execute([$parent_id]);
        $last_code = $stmt_last->fetchColumn();

        if ($last_code) {
            $new_code = (int)$last_code + 1;
        } else {
            $new_code = "21101001";
        }

        // account_type يستخدم قيم enum المحاسبية؛ المورد حساب التزام وليس تسمية عربية.
        $stmt = $pdo->prepare("INSERT INTO unified_accounts (account_code, account_name_ar, account_type, normal_balance, parent_id, branch_id, account_status) VALUES (?, ?, 'liability', 'credit', ?, ?, ?)");
        $stmt->execute([$new_code, $account_name, $parent_id, $branch_id, $status]);

        $new_account_id = $pdo->lastInsertId();

        if ($base_currency_id) {
            $opening_balance_for_base = $_POST['opening_balance'] ?? 0;
            $stmt_curr = $pdo->prepare("SELECT currency_code, exchange_rate FROM currencies WHERE id = ?");
            $stmt_curr->execute([$base_currency_id]);
            $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
            $currency_code = $curr['currency_code'] ?? '';
            $rate = (float)($curr['exchange_rate'] ?? 1);
            $opening_balance_base = $opening_balance_for_base * $rate;

            $stmt_base_balance = $pdo->prepare("INSERT INTO account_balances_unified (account_id, branch_id, currency_id, currency_code, opening_balance, current_balance, opening_balance_base, current_balance_base, is_frozen, credit_limit, debit_limit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0)");
            $stmt_base_balance->execute([$new_account_id, null, $base_currency_id, $currency_code, $opening_balance_for_base, $opening_balance_for_base, $opening_balance_base, $opening_balance_base]);
        }

        $insertSupplierStmt = $pdo->prepare("INSERT INTO suppliers (supplier_name, trade_name, account_id, supplier_phone, supplier_email, address, link, created_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
        $insertSupplierStmt->execute([$account_name, $trade_name, $new_account_id, $supplier_phone, $supplier_email, $address, $link, $status]);

        $new_supplier_id = $pdo->lastInsertId();

        $currentUserId = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
        if (!empty($supplier_services)) {
            $stmtInsSvc = $pdo->prepare("INSERT IGNORE INTO supplier_services (supplier_id, service_id, is_active, assigned_at, assigned_by) VALUES (?, ?, 1, NOW(), ?)");
            foreach ($supplier_services as $svcId) {
                $stmtInsSvc->execute([$new_supplier_id, (int)$svcId, $currentUserId]);
            }
        }
        // لا تُمنح الخدمات تلقائياً؛ المورد يظهر فقط في الخدمات المختارة صراحةً.

        $pdo->commit();
        echo "<script>location.href='suppliers.php?success=1';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء الإضافة: " . $e->getMessage();
    }
}

// تحديث مورد
if (isset($_POST['update_supplier_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='suppliers.php';</script>");
    }
    $id = $_POST['id'];
    $account_name = $_POST['account_name'];
    $trade_name = trim($_POST['trade_name'] ?? '') ?: null;
    $new_status = $_POST['status'];
    $branch_id = $_POST['branch_id'] ?: null;
    $supplier_phone = $_POST['supplier_phone'] ?? null;
    $supplier_email = $_POST['supplier_email'] ?? null;
    $address = $_POST['address'] ?? null;
    $supplier_services = $_POST['supplier_services'] ?? [];
    if (!is_array($supplier_services)) $supplier_services = [];

    try {
        $pdo->beginTransaction();

        $stmt_get_current = $pdo->prepare("SELECT account_status FROM unified_accounts WHERE id = ?");
        $stmt_get_current->execute([$id]);
        $current_status = $stmt_get_current->fetchColumn();

        if ($new_status === 'closed' && $current_status !== 'closed') {
            $stmt_check_balance = $pdo->prepare("SELECT SUM(current_balance) as total FROM account_balances_unified WHERE account_id = ?");
            $stmt_check_balance->execute([$id]);
            $total_balance = (float)$stmt_check_balance->fetchColumn();
            if ($total_balance != 0) {
                throw new Exception("لا يمكن تغيير الحالة إلى مغلق لأن الرصيد ليس صفرًا.");
            }
        }

        $stmt = $pdo->prepare("UPDATE unified_accounts SET account_name_ar = ?, account_status = ?, branch_id = ? WHERE id = ?");
        $stmt->execute([$account_name, $new_status, $branch_id, $id]);

        $updateSupplierStmt = $pdo->prepare("UPDATE suppliers SET supplier_name = ?, trade_name = ?, supplier_phone = ?, supplier_email = ?, address = ?, status = ?, updated_at = NOW() WHERE account_id = ?");
        $updateSupplierStmt->execute([$account_name, $trade_name, $supplier_phone, $supplier_email, $address, $new_status, $id]);

        $stmtGetSupId = $pdo->prepare("SELECT id FROM suppliers WHERE account_id = ? LIMIT 1");
        $stmtGetSupId->execute([$id]);
        $supplier_id = (int)$stmtGetSupId->fetchColumn();

        if ($supplier_id > 0) {
            $stmtDelSvc = $pdo->prepare("DELETE FROM supplier_services WHERE supplier_id = ?");
            $stmtDelSvc->execute([$supplier_id]);

            $currentUserId = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
            if (!empty($supplier_services)) {
                $stmtInsSvc = $pdo->prepare("INSERT INTO supplier_services (supplier_id, service_id, is_active, assigned_at, assigned_by) VALUES (?, ?, 1, NOW(), ?)");
                foreach ($supplier_services as $svcId) {
                    $stmtInsSvc->execute([$supplier_id, (int)$svcId, $currentUserId]);
                }
            }
        }

        $pdo->commit();
        echo "<script>location.href='suppliers.php?success=2';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء التحديث: " . $e->getMessage();
    }
}

// تحويل إلى خامل عبر POST + CSRF
if (isset($_POST['deactivate_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='suppliers.php';</script>");
    }
    $id = (int)$_POST['deactivate_account'];
    try {
        $stmt = $pdo->prepare("UPDATE unified_accounts SET account_status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);
        echo "<script>location.href='suppliers.php?success=3';</script>";
        exit();
    } catch (Exception $e) {
        $error = "حدث خطأ أثناء التحويل إلى خامل: " . $e->getMessage();
    }
}

// حذف نهائي عبر POST + CSRF
if (isset($_POST['delete_account_permanent'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='suppliers.php';</script>");
    }
    $id = (int)$_POST['delete_account_permanent'];
    try {
        $pdo->beginTransaction();

        // تحقق من أن الرصيد صفر
        $stmt_check_balance = $pdo->prepare("SELECT SUM(current_balance) as total FROM account_balances_unified WHERE account_id = ?");
        $stmt_check_balance->execute([$id]);
        $total_balance = (float)$stmt_check_balance->fetchColumn();
        if ($total_balance != 0) {
            throw new Exception("لا يمكن حذف الحساب نهائيًا لأن الرصيد ليس صفرًا.");
        }

        // التحقق من إمكانية حذف الحساب وعدم وجود حركات مالية مرتبطة
        if (!can_delete_account($id)) {
            throw new Exception("لا يمكن حذف الحساب نهائيًا لوجود عمليات مالية مرتبطة به. يمكنك تغيير حالته إلى خامل بدلاً من ذلك.");
        }

        // حذف الأرصدة المرتبطة بالحساب
        $stmt_del_bal = $pdo->prepare("DELETE FROM account_balances_unified WHERE account_id = ?");
        $stmt_del_bal->execute([$id]);

        // حذف الحساب من شجرة الحسابات الموحدة
        $stmt = $pdo->prepare("DELETE FROM unified_accounts WHERE id = ?");
        $stmt->execute([$id]);

        $pdo->commit();
        echo "<script>location.href='suppliers.php?success=4';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء الحذف النهائي: " . $e->getMessage();
    }
}

$parent_stmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101'");
$parent_stmt->execute();
$suppliers_parent_id = $parent_stmt->fetchColumn();

$where = "WHERE coa.parent_id = ? AND (coa.account_status = 'active' OR coa.account_status = 'dormant')";
$params = [$suppliers_parent_id];
if (!empty($_GET['q'])) {
    $where .= " AND (coa.account_name_ar LIKE ? OR coa.account_code LIKE ? OR s.trade_name LIKE ?)";
    $q = "%" . $_GET['q'] . "%";
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
}

$suppliers_stmt = $pdo->prepare("
    SELECT coa.*, p.account_name_ar as parent_name, b.branch_name,
           s.id as supplier_id, s.supplier_phone, s.supplier_email, s.address, s.link, s.trade_name,
           COALESCE(NULLIF(TRIM(s.trade_name), ''), s.supplier_name) as supplier_display_name
    FROM unified_accounts coa
    LEFT JOIN unified_accounts p ON coa.parent_id = p.id
    LEFT JOIN branches b ON coa.branch_id = b.id
    LEFT JOIN suppliers s ON coa.id = s.account_id
    $where
    ORDER BY coa.account_code ASC
");
$suppliers_stmt->execute($params);
$suppliers = $suppliers_stmt->fetchAll();

$supplier_ids = array_filter(array_column($suppliers, 'supplier_id'));
$supplier_services_map = [];
if (!empty($supplier_ids)) {
    $placeholders = implode(',', array_fill(0, count($supplier_ids), '?'));
    $svcStmt = $pdo->prepare("
        SELECT ss.supplier_id, ss.service_id, cs.service_code, cs.service_name_ar
        FROM supplier_services ss
        JOIN catalog_services cs ON ss.service_id = cs.id
        WHERE ss.supplier_id IN ($placeholders) AND ss.is_active = 1 AND cs.is_active = 1 AND cs.deleted_at IS NULL
        ORDER BY cs.sort_order ASC
    ");
    $svcStmt->execute($supplier_ids);
    foreach ($svcStmt->fetchAll() as $row) {
        $supplier_services_map[$row['supplier_id']][] = $row;
    }
}

// جلب الأرصدة الحقيقية من account_balances_unified
$supplier_account_ids = array_column($suppliers, 'id');
$balances = [];
$total_debit = 0;
$total_credit = 0;
$supplier_totals = []; // to store per supplier totals in base currency
if (!empty($supplier_account_ids)) {
    $placeholders = implode(',', array_fill(0, count($supplier_account_ids), '?'));
    $bal_stmt = $pdo->prepare("
        SELECT
            abu.account_id,
            abu.currency_id,
            c.currency_name,
            c.currency_symbol,
            abu.current_balance,
            abu.current_balance_base,
            ua.normal_balance
        FROM account_balances_unified abu
        JOIN unified_accounts ua ON abu.account_id = ua.id
        LEFT JOIN currencies c ON abu.currency_id = c.id
        WHERE abu.account_id IN ($placeholders)
    ");
    $bal_stmt->execute($supplier_account_ids);
    $result = $bal_stmt->fetchAll();
    foreach ($result as $row) {
        $balances[$row['account_id']][] = $row;

        // Calculate per supplier and overall totals in base currency
        if (!isset($supplier_totals[$row['account_id']])) {
            $supplier_totals[$row['account_id']] = ['debit' => 0, 'credit' => 0];
        }

        $current_balance_base = (float)$row['current_balance_base'];
        if ($row['normal_balance'] === 'debit') {
            if ($current_balance_base > 0) {
                $supplier_totals[$row['account_id']]['debit'] += $current_balance_base;
                $total_debit += $current_balance_base;
            } else {
                $supplier_totals[$row['account_id']]['credit'] += abs($current_balance_base);
                $total_credit += abs($current_balance_base);
            }
        } else { // credit normal balance
            if ($current_balance_base > 0) {
                $supplier_totals[$row['account_id']]['credit'] += $current_balance_base;
                $total_credit += $current_balance_base;
            } else {
                $supplier_totals[$row['account_id']]['debit'] += abs($current_balance_base);
                $total_debit += abs($current_balance_base);
            }
        }
    }
}

$currencies = $pdo->query("SELECT id, currency_name, is_default FROM currencies WHERE is_active = 1")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches")->fetchAll();

$page_title = "إدارة الموردين";
?>

<style>
    .supplier-services-card {
        border: 1px solid rgba(59, 130, 246, 0.16) !important;
        border-radius: 1rem !important;
        background: linear-gradient(145deg, rgba(248, 250, 252, 0.96), rgba(239, 246, 255, 0.82));
        padding: 1rem !important;
    }

    .supplier-services-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.65rem;
    }

    .supplier-service-option {
        min-height: 52px;
        display: flex !important;
        align-items: center;
        gap: 0.55rem;
        padding: 0.65rem 0.75rem !important;
        border: 1px solid rgba(148, 163, 184, 0.26) !important;
        border-radius: 0.85rem !important;
        background: rgba(255, 255, 255, 0.9) !important;
        transition: transform 0.16s ease, border-color 0.16s ease, box-shadow 0.16s ease;
    }

    .supplier-service-option:hover {
        transform: translateY(-1px);
        border-color: rgba(37, 99, 235, 0.5) !important;
        box-shadow: 0 6px 16px rgba(30, 64, 175, 0.1);
    }

    .supplier-service-option .form-check-input {
        width: 2.1rem;
        height: 1.15rem;
        flex: 0 0 auto;
        margin: 0 !important;
    }

    .supplier-service-option .form-check-label {
        line-height: 1.45;
        cursor: pointer;
    }

    .supplier-form-section {
        padding: 1rem;
        margin-bottom: 1rem;
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 1rem;
        background: rgba(248, 250, 252, 0.56);
    }

    @media (max-width: 767.98px) {
        #addSupplierModal .modal-dialog,
        #editSupplierModal .modal-dialog {
            margin: 0.5rem;
        }

        #addSupplierModal .modal-body,
        #editSupplierModal .modal-body {
            padding: 0.85rem !important;
            max-height: calc(100vh - 150px) !important;
        }

        .supplier-services-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.5rem;
        }

        .supplier-service-option {
            min-height: 58px;
            padding: 0.55rem !important;
            font-size: 0.76rem;
        }

        #addSupplierModal .modal-footer,
        #editSupplierModal .modal-footer {
            padding: 0.8rem !important;
            gap: 0.5rem !important;
        }

        #addSupplierModal .modal-footer button,
        #editSupplierModal .modal-footer button {
            flex: 1 1 0;
            padding-inline: 0.8rem !important;
        }
    }

    body.theme-dark .supplier-services-card,
    body.dark-mode .supplier-services-card {
        background: linear-gradient(145deg, rgba(15, 23, 42, 0.96), rgba(30, 41, 59, 0.96));
        border-color: rgba(96, 165, 250, 0.24) !important;
    }

    body.theme-dark .supplier-service-option,
    body.dark-mode .supplier-service-option {
        background: rgba(30, 41, 59, 0.86) !important;
        border-color: rgba(148, 163, 184, 0.22) !important;
    }

    .supplier-page-hero {
        padding: 1.25rem 1.4rem;
        border: 1px solid rgba(59, 130, 246, 0.14);
        border-radius: 1.25rem;
        background: linear-gradient(135deg, #eff6ff 0%, #ffffff 56%, #f8fafc 100%);
        box-shadow: 0 10px 26px rgba(15, 23, 42, 0.06);
    }

    .supplier-list-card {
        overflow: hidden;
        border: 1px solid rgba(148, 163, 184, 0.18) !important;
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.07) !important;
    }

    .supplier-list-toolbar {
        padding: 1rem 1.1rem !important;
        background: linear-gradient(180deg, rgba(248, 250, 252, 0.96), rgba(255, 255, 255, 0.96));
        border-bottom: 1px solid rgba(148, 163, 184, 0.16) !important;
    }

    .supplier-search-box,
    .supplier-advanced-search {
        border: 1px solid rgba(148, 163, 184, 0.24) !important;
        border-radius: 0.85rem !important;
        overflow: hidden;
        background: #fff;
    }

    .supplier-search-box .input-group-text,
    .supplier-search-box .form-control,
    .supplier-advanced-search .form-control {
        min-height: 42px;
        border: 0 !important;
        box-shadow: none !important;
    }

    .supplier-table-wrap {
        padding: 0.45rem;
    }

    #suppliersTable tbody tr {
        transition: background-color 0.16s ease, box-shadow 0.16s ease;
    }

    #suppliersTable tbody tr:hover {
        background: rgba(239, 246, 255, 0.55);
    }

    #suppliersTable td {
        border-color: rgba(148, 163, 184, 0.14) !important;
    }

    .supplier-service-badges {
        max-width: 240px;
        justify-content: center;
    }

    .supplier-service-badges .badge {
        border-radius: 999px;
        padding: 0.42rem 0.62rem;
        font-weight: 700;
    }

    .supplier-actions {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.25rem;
        padding: 0.25rem;
        border-radius: 0.8rem;
        background: rgba(248, 250, 252, 0.8);
    }

    .supplier-actions .btn {
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 0.65rem !important;
    }

    @media (max-width: 767.98px) {
        .supplier-page-hero {
            padding: 1rem;
            border-radius: 1rem;
        }

        .supplier-page-hero .supplier-hero-summary {
            display: grid !important;
            grid-template-columns: 1fr 1fr;
            gap: 0.45rem !important;
            width: 100%;
            margin-top: 0.75rem;
        }

        .supplier-page-hero .supplier-hero-summary > div {
            margin: 0 !important;
            padding: 0.5rem 0.6rem !important;
            font-size: 0.72rem;
            text-align: center;
        }

        .supplier-page-hero .btn {
            width: 100%;
            margin-top: 0.75rem;
        }

        .supplier-list-toolbar {
            padding: 0.8rem !important;
        }

        .supplier-list-toolbar .row > [class*="col-"] {
            width: 100%;
            margin-bottom: 0.55rem;
        }

        .supplier-list-toolbar form {
            display: flex !important;
            width: 100%;
        }

        .supplier-list-toolbar form input {
            flex: 1 1 auto;
            min-width: 0;
        }

        .supplier-table-wrap {
            padding: 0.65rem;
        }

        #suppliersTable,
        #suppliersTable tbody,
        #suppliersTable tr,
        #suppliersTable td {
            display: block;
            width: 100%;
        }

        #suppliersTable thead {
            display: none;
        }

        #suppliersTable tbody tr {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.55rem;
            margin-bottom: 0.75rem;
            padding: 0.75rem;
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 1rem;
            background: #fff;
            box-shadow: 0 5px 14px rgba(15, 23, 42, 0.05);
        }

        #suppliersTable tbody td {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            min-height: 48px;
            padding: 0.35rem 0.45rem !important;
            text-align: start !important;
            border: 0 !important;
        }

        #suppliersTable tbody td::before {
            margin-bottom: 0.18rem;
            color: #64748b;
            font-size: 0.68rem;
            font-weight: 700;
        }

        #suppliersTable tbody td:nth-child(1)::before { content: "كود الحساب"; }
        #suppliersTable tbody td:nth-child(2)::before { content: "الاسم التجاري"; }
        #suppliersTable tbody td:nth-child(3)::before { content: "الاسم المحاسبي"; }
        #suppliersTable tbody td:nth-child(4)::before { content: "الخدمات"; }
        #suppliersTable tbody td:nth-child(5)::before { content: "الفرع"; }
        #suppliersTable tbody td:nth-child(6)::before { content: "الرصيد"; }
        #suppliersTable tbody td:nth-child(7)::before { content: "الحالة"; }
        #suppliersTable tbody td:nth-child(8) {
            grid-column: 1 / -1;
            align-items: stretch;
            padding-top: 0.65rem !important;
            border-top: 1px solid rgba(148, 163, 184, 0.16) !important;
        }
        #suppliersTable tbody td:nth-child(8)::before { content: "الإجراءات"; }

        .supplier-service-badges {
            max-width: none !important;
            justify-content: flex-start !important;
        }

        .supplier-actions {
            width: 100%;
            justify-content: flex-start;
        }

        .supplier-actions .btn {
            width: 38px;
            height: 38px;
        }
    }

    body.theme-dark .supplier-page-hero,
    body.dark-mode .supplier-page-hero {
        background: linear-gradient(135deg, #0f1e35 0%, #111827 60%, #0f172a 100%);
    }

    body.theme-dark .supplier-list-toolbar,
    body.dark-mode .supplier-list-toolbar {
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(17, 24, 39, 0.96));
    }

    body.theme-dark #suppliersTable tbody tr,
    body.dark-mode #suppliersTable tbody tr {
        background: #111827;
    }

    .supplier-balance-summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(150px, 1fr));
        gap: 0.65rem;
        margin-top: 0.75rem;
    }

    .supplier-balance-card {
        position: relative;
        min-width: 170px;
        padding: 0.7rem 0.9rem;
        border: 1px solid transparent;
        border-radius: 0.95rem;
        overflow: hidden;
    }

    .supplier-balance-card::after {
        content: '';
        position: absolute;
        width: 74px;
        height: 74px;
        border-radius: 50%;
        inset-inline-end: -28px;
        inset-block-end: -34px;
        opacity: 0.16;
        background: currentColor;
    }

    .supplier-balance-card .balance-label {
        display: flex;
        align-items: center;
        gap: 0.38rem;
        font-size: 0.72rem;
        font-weight: 700;
        margin-bottom: 0.25rem;
        position: relative;
        z-index: 1;
    }

    .supplier-balance-card .balance-value {
        display: block;
        font-size: 1.08rem;
        line-height: 1.25;
        font-weight: 800;
        letter-spacing: 0.01em;
        position: relative;
        z-index: 1;
    }

    .supplier-balance-card .balance-currency {
        font-size: 0.68rem;
        font-weight: 700;
        opacity: 0.82;
        margin-inline-start: 0.2rem;
    }

    .supplier-balance-card.balance-receivable {
        color: #15803d;
        background: linear-gradient(135deg, rgba(220, 252, 231, 0.96), rgba(240, 253, 244, 0.92));
        border-color: rgba(34, 197, 94, 0.24);
    }

    .supplier-balance-card.balance-payable {
        color: #b91c1c;
        background: linear-gradient(135deg, rgba(254, 226, 226, 0.96), rgba(255, 247, 247, 0.92));
        border-color: rgba(239, 68, 68, 0.24);
    }

    @media (max-width: 767.98px) {
        .supplier-balance-summary {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            width: 100%;
            gap: 0.45rem;
            direction: rtl;
        }

        .supplier-balance-card {
            min-width: 0;
            width: 100%;
            padding: 0.6rem 0.4rem;
            text-align: center;
        }

        .supplier-balance-card .balance-label {
            justify-content: center;
            font-size: 0.66rem;
        }

        .supplier-balance-card .balance-value {
            font-size: 0.88rem;
        }

        .supplier-balance-card .balance-currency {
            display: block;
            margin: 0.12rem 0 0;
        }
    }

    body.theme-dark .supplier-balance-card.balance-receivable,
    body.dark-mode .supplier-balance-card.balance-receivable {
        color: #86efac;
        background: linear-gradient(135deg, rgba(20, 83, 45, 0.54), rgba(22, 101, 52, 0.32));
        border-color: rgba(74, 222, 128, 0.28);
    }

    body.theme-dark .supplier-balance-card.balance-payable,
    body.dark-mode .supplier-balance-card.balance-payable {
        color: #fca5a5;
        background: linear-gradient(135deg, rgba(127, 29, 29, 0.54), rgba(153, 27, 27, 0.32));
        border-color: rgba(248, 113, 113, 0.28);
    }

    /* Ensure modal footer is visible in dark theme */
    #addSupplierModal .modal-footer,
    #editSupplierModal .modal-footer {
        background-color: #f8f9fa !important;
        border-top: 1px solid #dee2e6 !important;
        display: flex !important;
        justify-content: flex-end !important;
        gap: 1rem !important;
        padding: 1.5rem !important;
        position: sticky !important;
        bottom: 0 !important;
        z-index: 1051 !important;
    }

    /* Ensure modal header is visible */
    #addSupplierModal .modal-header,
    #editSupplierModal .modal-header {
        background-color: #0d6efd !important;
        color: white !important;
        position: sticky !important;
        top: 0 !important;
        z-index: 1051 !important;
    }

    #editSupplierModal .modal-header {
        background-color: #ffc107 !important;
        color: #212529 !important;
    }

    #addSupplierModal .modal-footer button,
    #editSupplierModal .modal-footer button {
        z-index: 1060 !important;
        opacity: 1 !important;
        visibility: visible !important;
        position: relative !important;
    }

    /* Make save button more prominent */
    #addSupplierModal .btn-primary,
    #editSupplierModal .btn-warning {
        font-size: 1rem !important;
        padding: 0.75rem 2rem !important;
        box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3) !important;
    }

    /* Make modal body scrollable */
    #addSupplierModal .modal-body,
    #editSupplierModal .modal-body {
        overflow-y: auto !important;
        max-height: calc(90vh - 140px) !important;
    }

    /* Make modal content height better */
    #addSupplierModal .modal-content,
    #editSupplierModal .modal-content {
        max-height: 90vh !important;
    }

    /* Ensure modal dialog is centered and visible */
    #addSupplierModal .modal-dialog,
    #editSupplierModal .modal-dialog {
        margin: 1.75rem auto !important;
    }

    /* Force modal to show correctly in dark mode */
    body.theme-dark #addSupplierModal .modal-content,
    body.theme-dark #editSupplierModal .modal-content {
        background-color: #111827 !important;
    }

    body.theme-dark #addSupplierModal .modal-footer,
    body.theme-dark #editSupplierModal .modal-footer {
        background-color: #0f1e35 !important;
        border-top: 1px solid #1e2d45 !important;
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 supplier-page-hero">
        <div>
            <h3 class="fw-bold mb-1"><i class="fas fa-truck-loading me-2 text-primary"></i> إدارة الموردين</h3>
            <div class="supplier-hero-summary">
                <p class="text-muted small mb-0">إدارة وتعديل حسابات الموردين في شجرة الحسابات</p>
            </div>
        </div>
        <button class="btn btn-primary rounded-pill px-4 shadow-sm supplier-hero-action" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
            <i class="fas fa-plus-circle me-1"></i> إضافة مورد جديد
        </button>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div id="supplierSuccessAlert" class="alert alert-success alert-dismissible fade show supplier-flash-alert">
            <?php
            if ($_GET['success'] == 1) echo "تمت إضافة المورد بنجاح.";
            if ($_GET['success'] == 2) echo "تم تحديث بيانات المورد بنجاح.";
            if ($_GET['success'] == 3) echo "تم تحويل المورد إلى خامل بنجاح.";
            if ($_GET['success'] == 4) echo "تم حذف المورد نهائيًا بنجاح.";
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div id="supplierErrorAlert" class="alert alert-danger alert-dismissible fade show supplier-flash-alert">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card supplier-list-card border-0 shadow-sm rounded-4">
        <div class="card-header supplier-list-toolbar bg-white border-0 py-3">
            <div class="supplier-balance-summary supplier-search-balances">
                <div class="supplier-balance-card balance-receivable">
                    <div class="balance-label"><i class="fas fa-arrow-down"></i><span>إجمالي لنا</span></div>
                    <span class="balance-value"><?php echo number_format($total_debit, 2); ?><span class="balance-currency"><?php echo htmlspecialchars($baseCurrency['currency_name'] ?? ''); ?></span></span>
                </div>
                <div class="supplier-balance-card balance-payable">
                    <div class="balance-label"><i class="fas fa-arrow-up"></i><span>إجمالي علينا</span></div>
                    <span class="balance-value"><?php echo number_format($total_credit, 2); ?><span class="balance-currency"><?php echo htmlspecialchars($baseCurrency['currency_name'] ?? ''); ?></span></span>
                </div>
            </div>
            <div class="row align-items-center">
                <div class="col-md-4">
                    <div class="input-group supplier-search-box">
                        <span class="input-group-text bg-light border-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="search" id="supplierSearch" class="form-control bg-light border-0" placeholder="ابحث باسم المورد أو الكود أو الخدمة..." autocomplete="off">
                    </div>
                </div>

            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive supplier-table-wrap">
                <table class="table table-hover align-middle mb-0 text-center" id="suppliersTable">
                    <thead class="bg-light text-secondary small text-uppercase fw-bold">
                        <tr>
                            <th class="px-4 py-3">كود الحساب</th>
                            <th>الاسم التجاري</th>
                            <th>اسم المورد (محاسبي)</th>
                            <th>الخدمات</th>
                            <th>الفرع</th>
                            <th>الرصيد الحالي</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suppliers as $supplier): ?>
                            <tr>
                                <td class="px-4">
                                    <code class="text-primary fw-bold"><?php echo $supplier['account_code']; ?></code>
                                </td>
                                <td>
                                    <div class="fw-bold text-primary"><?php echo htmlspecialchars($supplier['supplier_display_name'] ?? $supplier['account_name_ar']); ?></div>
                                    <?php if (!empty($supplier['trade_name'])): ?>
                                        <small class="text-success"><i class="fas fa-check-circle me-1"></i>اسم تجاري مفعّل</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small text-muted"><?php echo htmlspecialchars($supplier['account_name_ar']); ?></div>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1 supplier-service-badges">
                                        <?php
                                        $svc_list = $supplier_services_map[$supplier['supplier_id']] ?? [];
                                        if (!empty($svc_list)):
                                            foreach (array_slice($svc_list, 0, 4) as $svc):
                                        ?>
                                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-20 small">
                                                    <?php echo htmlspecialchars($svc['service_name_ar']); ?>
                                                </span>
                                            <?php
                                            endforeach;
                                            if (count($svc_list) > 4):
                                            ?>
                                                <span class="badge bg-secondary bg-opacity-10 text-secondary small">
                                                    +<?php echo count($svc_list) - 4; ?>
                                                </span>
                                            <?php
                                            endif;
                                        else:
                                            ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary small">غير محدد</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border fw-normal">
                                        <i class="fas fa-building me-1 text-muted"></i>
                                        <?php echo htmlspecialchars($supplier['branch_name'] ?? 'عام'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    if (isset($balances[$supplier['id']]) && !empty($balances[$supplier['id']])) {
                                        foreach ($balances[$supplier['id']] as $bal) {
                                            echo '<div class="mb-1 small">' . format_account_balance($bal['current_balance'], $bal['normal_balance'], $bal['currency_name']) . '</div>';
                                        }
                                    } else {
                                        echo '<div class="mb-1 small text-muted">0.00 ' . htmlspecialchars($baseCurrency['currency_name'] ?? '') . '</div>';
                                    }

                                    $cust_debit = $supplier_totals[$supplier['id']]['debit'] ?? 0;
                                    $cust_credit = $supplier_totals[$supplier['id']]['credit'] ?? 0;
                                    if ($cust_debit > 0 || $cust_credit > 0) {
                                        echo '<hr class="my-2">';
                                        if ($cust_debit > 0) {
                                            echo '<div class="small text-success"><i class="fas fa-arrow-down me-1"></i> لنا: ' . number_format($cust_debit, 2) . ' ' . htmlspecialchars($baseCurrency['currency_name'] ?? '') . '</div>';
                                        }
                                        if ($cust_credit > 0) {
                                            echo '<div class="small text-danger"><i class="fas fa-arrow-up me-1"></i> علينا: ' . number_format($cust_credit, 2) . ' ' . htmlspecialchars($baseCurrency['currency_name'] ?? '') . '</div>';
                                        }
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php echo get_account_status_label($supplier['account_status']); ?>
                                </td>
                                <td>
                                    <div class="btn-group supplier-actions">
                                        <a href="account_statement.php?id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-light border-0" title="كشف حساب">
                                            <i class="fas fa-file-invoice-dollar text-primary"></i>
                                        </a>
                                        <button class="btn btn-sm btn-light border-0 edit-supplier"
                                            data-id="<?php echo $supplier['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($supplier['account_name_ar']); ?>"
                                            data-trade-name="<?php echo htmlspecialchars($supplier['trade_name'] ?? ''); ?>"
                                            data-branch="<?php echo $supplier['branch_id']; ?>"
                                            data-status="<?php echo $supplier['account_status']; ?>"
                                            data-phone="<?php echo htmlspecialchars($supplier['supplier_phone'] ?? ''); ?>"
                                            data-email="<?php echo htmlspecialchars($supplier['supplier_email'] ?? ''); ?>"
                                            data-address="<?php echo htmlspecialchars($supplier['address'] ?? ''); ?>"
                                            data-services="<?php echo htmlspecialchars(json_encode(array_column($supplier_services_map[$supplier['supplier_id']] ?? [], 'service_id'))); ?>"
                                            title="تعديل"><i class="fas fa-edit text-warning"></i></button>
                                        <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من تحويل هذا المورد إلى خامل؟')">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="deactivate_account" value="<?php echo $supplier['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-light border-0" title="تحويل إلى خامل"><i class="fas fa-pause text-secondary"></i></button>
                                        </form>
                                        <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذا المورد نهائيًا؟ هذا الإجراء لا يمكن التراجع عنه!')">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="delete_account_permanent" value="<?php echo $supplier['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-light border-0" title="حذف نهائي"><i class="fas fa-trash text-danger"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة مورد -->
<div class="modal fade" id="addSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 d-flex flex-column">
            <form method="POST" class="d-flex flex-column h-100">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-primary text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة مورد جديد</h5>
                    <div class="d-flex gap-2">
                        <button type="submit" name="add_supplier_account" class="btn btn-light text-primary fw-bold">
                            <i class="fas fa-save me-1"></i> حفظ
                        </button>
                        <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body p-4 flex-grow-1 overflow-auto">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">اسم المورد (محاسبي) <span class="text-danger">*</span></label>
                            <input type="text" name="account_name" class="form-control rounded-3" placeholder="مثلاً: شركة التوريد" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">الاسم التجاري <span class="text-info" data-bs-toggle="tooltip" title="الاسم الذي سيظهر للمستخدمين في صفحات الخدمات"><i class="fas fa-info-circle"></i></span></label>
                            <input type="text" name="trade_name" class="form-control rounded-3" placeholder="مثلاً: شركة المتصدر للنقل - اسم المثال: الخطوط الجوية اليمنية">
                            <small class="text-muted">إذا ترك فارغاً سيتم استخدام اسم المورد</small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold small">رقم الهاتف</label>
                            <input type="text" name="supplier_phone" class="form-control rounded-3" placeholder="مثلاً: +967771234567">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold small">البريد الإلكتروني</label>
                            <input type="email" name="supplier_email" class="form-control rounded-3" placeholder="مثلاً: info@example.com">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold small">الفرع المربوط به <span class="text-danger">*</span></label>
                            <select name="branch_id" class="form-select rounded-3" required>
                                <option value="">-- اختر الفرع --</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo $b['branch_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold small">العملة</label>
                            <select name="currency_id" class="form-select rounded-3">
                                <?php foreach ($currencies as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo ($c['id'] == $base_currency_id) ? 'selected' : ''; ?>>
                                        <?php echo $c['currency_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold small">الرصيد الافتتاحي</label>
                            <input type="number" step="0.01" name="opening_balance" class="form-control rounded-3" value="0.00">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">العنوان</label>
                            <textarea name="address" class="form-control rounded-3" rows="2" placeholder="العنوان التفصيلي"></textarea>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">الحالة</label>
                            <select name="status" class="form-select rounded-3">
                                <option value="active">نشط</option>
                                <option value="closed">مغلق (للتصفية)</option>
                                <option value="dormant">راكد (غير مستخدم)</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small mb-2">
                                <i class="fas fa-concierge-bell text-primary me-1"></i> الخدمات التي يقدمها المورد
                                <span class="text-danger">*</span>
                            </label>
                            <div class="card supplier-services-card">
                                <div class="supplier-services-grid">
                                    <?php foreach ($catalog_services as $svc): ?>
                                        <div class="col-md-6 col-lg-4 col-xl-3">
                                            <div class="form-check form-switch form-check-reverse supplier-service-option">
                                                <input class="form-check-input me-3 ms-0 supplier-service-checkbox" type="checkbox"
                                                    name="supplier_services[]"
                                                    value="<?php echo $svc['id']; ?>"
                                                    id="add_svc_<?php echo $svc['service_code']; ?>" checked>
                                                <label class="form-check-label fw-bold small flex-grow-1 text-start" for="add_svc_<?php echo $svc['service_code']; ?>">
                                                    <?php echo htmlspecialchars($svc['service_name_ar']); ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted mt-2 d-block"><i class="fas fa-info-circle me-1"></i> اختيار خدمة واحدة أو أكثر. إذا لم يتم اختيار أي خدمة، سيتم اختيار جميع الخدمات تلقائياً.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light shadow-sm flex-shrink-0">
                    <button type="button" class="btn btn-white rounded-pill px-4 border" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_supplier_account" class="btn btn-primary rounded-pill px-5 fw-bold shadow">حفظ المورد</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل مورد -->
<div class="modal fade" id="editSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 d-flex flex-column">
            <form method="POST" class="d-flex flex-column h-100">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header bg-warning text-dark border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل بيانات المورد</h5>
                    <div class="d-flex gap-2">
                        <button type="submit" name="update_supplier_account" class="btn btn-light text-dark fw-bold">
                            <i class="fas fa-save me-1"></i> حفظ
                        </button>
                        <button type="button" class="btn-close ms-2" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body p-4 flex-grow-1 overflow-auto">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">اسم المورد (محاسبي) <span class="text-danger">*</span></label>
                            <input type="text" name="account_name" id="edit_name" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">الاسم التجاري <span class="text-info" data-bs-toggle="tooltip" title="الاسم الذي سيظهر للمستخدمين في صفحات الخدمات"><i class="fas fa-info-circle"></i></span></label>
                            <input type="text" name="trade_name" id="edit_trade_name" class="form-control rounded-3" placeholder="مثلاً: شركة المتصدر للنقل">
                            <small class="text-muted">إذا ترك فارغاً سيتم استخدام اسم المورد</small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold small">رقم الهاتف</label>
                            <input type="text" name="supplier_phone" id="edit_phone" class="form-control rounded-3" placeholder="مثلاً: +967771234567">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold small">البريد الإلكتروني</label>
                            <input type="email" name="supplier_email" id="edit_email" class="form-control rounded-3" placeholder="مثلاً: info@example.com">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">الفرع المربوط به</label>
                            <select name="branch_id" id="edit_branch" class="form-select rounded-3">
                                <option value="">-- عام (بدون فرع) --</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo $b['branch_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">الحالة</label>
                            <select name="status" id="edit_status" class="form-select rounded-3">
                                <option value="active">نشط</option>
                                <option value="inactive">خامل</option>
                                <option value="closed">مغلق (للتصفية)</option>
                                <option value="dormant">راكد (غير مستخدم)</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">العنوان</label>
                            <textarea name="address" id="edit_address" class="form-control rounded-3" rows="2" placeholder="العنوان التفصيلي"></textarea>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small mb-2">
                                <i class="fas fa-concierge-bell text-primary me-1"></i> الخدمات التي يقدمها المورد
                                <span class="text-danger">*</span>
                            </label>
                            <div class="card supplier-services-card">
                                <div class="supplier-services-grid" id="edit_services_container">
                                    <?php foreach ($catalog_services as $svc): ?>
                                        <div class="col-md-6 col-lg-4 col-xl-3">
                                            <div class="form-check form-switch form-check-reverse supplier-service-option">
                                                <input class="form-check-input me-3 ms-0 edit-supplier-service-checkbox" type="checkbox"
                                                    name="supplier_services[]"
                                                    value="<?php echo $svc['id']; ?>"
                                                    data-service-id="<?php echo $svc['id']; ?>"
                                                    id="edit_svc_<?php echo $svc['service_code']; ?>">
                                                <label class="form-check-label fw-bold small flex-grow-1 text-start" for="edit_svc_<?php echo $svc['service_code']; ?>">
                                                    <?php echo htmlspecialchars($svc['service_name_ar']); ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted mt-2 d-block"><i class="fas fa-info-circle me-1"></i> اختيار خدمة واحدة أو أكثر.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light shadow-sm flex-shrink-0">
                    <button type="button" class="btn btn-white rounded-pill px-4 border" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="update_supplier_account" class="btn btn-warning rounded-pill px-5 fw-bold shadow">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        $('.edit-supplier').click(function() {
            var id = $(this).data('id');
            var name = $(this).data('name');
            var trade_name = $(this).data('trade-name');
            var branch = $(this).data('branch');
            var status = $(this).data('status');
            var phone = $(this).data('phone');
            var email = $(this).data('email');
            var address = $(this).data('address');
            var services = $(this).data('services');

            $('#edit_id').val(id);
            $('#edit_name').val(name);
            $('#edit_trade_name').val(trade_name || '');
            $('#edit_branch').val(branch);
            $('#edit_status').val(status);
            $('#edit_phone').val(phone);
            $('#edit_email').val(email);
            $('#edit_address').val(address);

            $('.edit-supplier-service-checkbox').prop('checked', false);
            if (services && Array.isArray(services) && services.length > 0) {
                services.forEach(function(svc_id) {
                    $('.edit-supplier-service-checkbox[data-service-id="' + svc_id + '"]').prop('checked', true);
                });
            } else {
                $('.edit-supplier-service-checkbox').prop('checked', true);
            }

            $('#editSupplierModal').modal('show');
        });

        $("#supplierSearch").on("input", function() {
            var value = $(this).val().trim().toLowerCase();
            $("#suppliersTable tbody tr").each(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
            });
        });

        // إشعارات مؤقتة: تختفي تلقائياً بعد عرضها لعدة ثوانٍ.
        window.setTimeout(function() {
            $('.supplier-flash-alert').stop(true, true).fadeOut(250, function() {
                $(this).remove();
            });
            if (window.history && window.history.replaceState) {
                var cleanUrl = new URL(window.location.href);
                cleanUrl.searchParams.delete('success');
                cleanUrl.searchParams.delete('error');
                cleanUrl.searchParams.delete('msg');
                window.history.replaceState({}, document.title, cleanUrl.pathname + cleanUrl.search + cleanUrl.hash);
            }
        }, 4200);
    });
</script>

<?php require_once 'footer.php'; ?>
