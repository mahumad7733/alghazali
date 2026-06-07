<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$type = 'family_visit';
$page_title = 'إدارة الزيارات العائلية';
$permission_prefix = 'family_visit';

if (!has_permission($permission_prefix . '_view')) {
    header('Location: index.php?error=no_permission');
    exit();
}

// جلب بيانات المستخدم الحالي
$stmt_user = $pdo->prepare("
    SELECT u.*, r.name as role_name 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    WHERE u.id = ?
");
$stmt_user->execute([$_SESSION['admin_id']]);
$currentUser = $stmt_user->fetch();

// نظام العزل والفلترة
$where_clauses = ["1=1"];
$params = [];

// جلب فلاتر البحث من الرابط (للمدراء)
$agent_filter = $_GET['agent_filter'] ?? '';
$branch_filter = $_GET['branch_filter'] ?? '';

// التحقق من هوية المستخدم لفرض العزل
$is_super_user = in_array(strtolower($currentUser['role_name'] ?? ''), ['admin', 'developer', 'accountant', 'relayer']) || has_permission('view_all_agents_branches');
$can_view_all = has_permission('view_all_passports') || has_permission('view_all_agents_branches');
$is_agent = (($currentUser['role_name'] ?? '') === 'agent');
$is_branch = (($currentUser['role_name'] ?? '') === 'branch');

if (!$is_super_user && !$can_view_all) {
    if (!empty($currentUser['agent_id'])) {
        $agent_filter = $currentUser['agent_id'];
        $branch_filter = '';
    } elseif (!empty($currentUser['branch_id'])) {
        $branch_filter = $currentUser['branch_id'];
        $agent_filter = $_GET['agent_filter'] ?? '';
    }
}

if (!empty($agent_filter)) {
    $where_clauses[] = "r.agent_id = ?";
    $params[] = $agent_filter;
}
if (!empty($branch_filter)) {
    $where_clauses[] = "r.branch_id = ?";
    $params[] = $branch_filter;
}

if (!empty($_GET['status_filter'])) {
    $where_clauses[] = "r.status_id = ?";
    $params[] = intval($_GET['status_filter']);
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// الاستعلام الرئيسي للطلبات
$requests_stmt = $pdo->prepare("
    SELECT r.*, s.status_name, s.status_color, 
           ag.agent_name, br.branch_name,
           (SELECT COUNT(*) FROM family_visit_individuals WHERE request_id = r.id) as individuals_count,
           inv.net_amount as total_price, inv.cost_amount as total_cost, inv.amount_received as total_paid,
           inv.id as sales_invoice_id, inv.invoice_status as sales_status,
           pur.id as purchase_invoice_id, pur.invoice_status as purchase_status
    FROM family_visit_requests r
    LEFT JOIN statuses s ON r.status_id = s.id
    LEFT JOIN agents ag ON r.agent_id = ag.id
    LEFT JOIN branches br ON r.branch_id = br.id
    LEFT JOIN invoices inv ON (
        inv.id = r.sales_invoice_id 
        OR inv.id = r.invoice_id 
        OR (inv.source_type = 'FamilyVisit' AND inv.source_id = r.id AND inv.invoice_category = 'sales')
    )
    LEFT JOIN invoices pur ON (
        pur.id = r.purchase_invoice_id 
        OR (pur.source_type = 'FamilyVisit' AND pur.source_id = r.id AND pur.invoice_category = 'purchase')
    )
    $where_sql
    ORDER BY r.created_at DESC
");
$requests_stmt->execute($params);
$requests = $requests_stmt->fetchAll();

// حذف طلب
if (isset($_GET['delete_id'])) {
    if (has_permission($permission_prefix . '_delete')) {
        $pdo->prepare("DELETE FROM family_visit_requests WHERE id = ?")->execute([$_GET['delete_id']]);
        header('Location: family_visit.php?success=deleted');
        exit();
    }
}

// جلب البيانات المساعدة
$statuses = $pdo->query("SELECT * FROM statuses")->fetchAll();
$relationships = $pdo->query("SELECT * FROM family_relationships WHERE status = 'active'")->fetchAll();
$agents = $pdo->query("SELECT id, agent_name FROM agents WHERE status = 'active'")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches WHERE status = 'active'")->fetchAll();

// جلب العملات
$currencies = $pdo->query("SELECT id, currency_name, is_default FROM currencies WHERE is_active = 1 ORDER BY currency_name")->fetchAll();
// جلب الموردين مع حساباتهم
$p_supp = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101'");
$p_supp->execute();
$p_id = $p_supp->fetchColumn();
$suppliers_with_codes = [];
if ($p_id) {
    $s_stmt = $pdo->prepare("SELECT coa.*, (SELECT id FROM suppliers WHERE account_id = coa.id LIMIT 1) as supplier_id FROM unified_accounts coa WHERE coa.parent_id = ? AND coa.account_status = 'active' ORDER BY coa.account_code ASC");
    $s_stmt->execute([$p_id]);
    while ($row = $s_stmt->fetch()) {
        $row['display_name'] = $row['account_code'] . ' - ' . $row['account_name_ar'];
        $suppliers_with_codes[] = $row;
    }
}

// الكيانات مع حساباتها الموحدة (نفس bus_flight_bookings.php)
$customers_entities = $pdo->query("
    SELECT c.id as id, c.account_id as account_id, c.full_name as name, ua.account_code
    FROM customers c
    JOIN unified_accounts ua ON c.account_id = ua.id
    WHERE c.status = 'active' AND c.deleted_at IS NULL
    ORDER BY c.full_name ASC
")->fetchAll();

$agents_entities = $pdo->query("
    SELECT a.id, a.agent_name as name, a.account_id as account_id, acc.account_code
    FROM agents a
    JOIN unified_accounts acc ON a.account_id = acc.id
    WHERE a.status = 'active' AND a.deleted_at IS NULL
    ORDER BY a.agent_name ASC
")->fetchAll();

$cashboxes_entities = $pdo->query("
    SELECT id as account_id, account_name_ar as name, account_code 
    FROM unified_accounts 
    WHERE account_code LIKE '101%' AND account_code != '101' AND account_status = 'active' 
    ORDER BY account_name_ar ASC
")->fetchAll();
$cash_accounts = $cashboxes_entities;

$banks_entities = $pdo->query("
    SELECT id as account_id, account_name_ar as name, account_code 
    FROM unified_accounts 
    WHERE account_code LIKE '102%' AND account_code != '102' AND account_status = 'active' 
    ORDER BY account_name_ar ASC
")->fetchAll();
$bank_accounts = $banks_entities;

$branches_accounts = $pdo->query("SELECT id, branch_name as account_name FROM branches WHERE deleted_at IS NULL AND status = 'active' ORDER BY branch_name ASC")->fetchAll();

// جلب إحصائيات الحالات للزيارة العائلية
$stats_on_clauses = ["s.id = r.status_id"];
$stats_params = [];
if (!empty($agent_filter)) { $stats_on_clauses[] = "r.agent_id = ?"; $stats_params[] = $agent_filter; }
if (!empty($branch_filter)) { $stats_on_clauses[] = "r.branch_id = ?"; $stats_params[] = $branch_filter; }
$stats_on_sql = implode(" AND ", $stats_on_clauses);

$status_stats_stmt = $pdo->prepare("
    SELECT 
        s.id, s.status_name, s.status_color,
        COUNT(r.id) as total,
        COUNT(CASE WHEN DATE(r.created_at) = CURDATE() THEN 1 END) as today,
        COUNT(CASE WHEN r.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as this_week,
        COUNT(CASE WHEN MONTH(r.created_at) = MONTH(CURDATE()) AND YEAR(r.created_at) = YEAR(CURDATE()) THEN 1 END) as this_month,
        COUNT(CASE WHEN MONTH(r.created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(r.created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) THEN 1 END) as last_month
    FROM statuses s
    LEFT JOIN family_visit_requests r ON $stats_on_sql
    GROUP BY s.id, s.status_name, s.status_color
    ORDER BY s.id ASC
");
$status_stats_stmt->execute($stats_params);
$status_stats = $status_stats_stmt->fetchAll();

require_once 'header.php';
?>

<style>
    @media (max-width: 768px) {
        .page-header-actions {
            flex-direction: column !important;
            align-items: stretch !important;
            width: 100%;
        }
        .header-controls {
            flex-direction: column !important;
            width: 100%;
        }
        .header-controls form, .header-controls .input-group, .header-controls button {
            width: 100% !important;
        }
        .mini-card {
            min-width: 140px !important;
            padding: 10px !important;
        }
        .stat-value {
            font-size: 1.2rem !important;
        }
        .stat-label {
            font-size: 0.8rem !important;
        }
        .table-responsive {
            border: 0;
        }
        .table thead {
            display: none;
        }
        .table tbody tr {
            display: block;
            margin-bottom: 1rem;
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            padding: 10px;
        }
        .table tbody td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 15px !important;
            border: 0;
            text-align: left;
        }
        .table tbody td::before {
            content: attr(data-label);
            font-weight: bold;
            color: #666;
            margin-right: 10px;
        }
        .table tbody td.text-center {
            justify-content: center;
        }
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3 page-header-actions">
        <h3 class="fw-bold mb-0"><i class="fas fa-users me-2 text-info"></i> <?php echo $page_title; ?></h3>
        
        <div class="d-flex gap-2 align-items-center header-controls">
            <form method="GET" class="d-flex gap-2 align-items-center">
                <?php if ($is_super_user || $can_view_all): ?>
                <select name="agent_filter" class="form-select form-select-sm rounded-pill shadow-sm border-0" style="width: 150px;" onchange="this.form.submit()">
                    <option value="">كل الوكلاء</option>
                    <?php foreach($agents as $ag): ?>
                        <option value="<?php echo $ag['id']; ?>" <?php echo $agent_filter == $ag['id'] ? 'selected' : ''; ?>><?php echo $ag['agent_name']; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="branch_filter" class="form-select form-select-sm rounded-pill shadow-sm border-0" style="width: 150px;" onchange="this.form.submit()">
                    <option value="">كل الفروع</option>
                    <?php foreach($branches as $br): ?>
                        <option value="<?php echo $br['id']; ?>" <?php echo $branch_filter == $br['id'] ? 'selected' : ''; ?>><?php echo $br['branch_name']; ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </form>

            <div class="input-group" style="width: 250px;">
                <span class="input-group-text bg-white border-0 shadow-sm rounded-start-pill"><i class="fas fa-search text-muted"></i></span>
                <input type="text" id="tableSearch" class="form-control border-0 shadow-sm rounded-end-pill" placeholder="بحث سريع...">
            </div>
            
            <?php if (has_permission($permission_prefix . '_create')): ?>
            <button type="button" class="btn btn-info text-white rounded-pill px-4 shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#addRequestModal">
                <i class="fas fa-plus-circle me-2"></i> طلب جديد
            </button>
            <?php endif; ?>
        </div>
    </div>



    <!-- الإحصائيات (البطاقات العلوية) -->
    <div class="row g-2 mb-4 overflow-auto flex-nowrap pb-3 custom-scrollbar px-1">
        <!-- بطاقة الإجمالي العام -->
        <div class="col-auto">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-primary text-white h-100 mini-card position-relative overflow-hidden" style="min-width: 200px;">
                <div class="position-absolute end-0 top-0 opacity-10" style="font-size: 3.5rem; transform: translate(15%, -15%);"><i class="fas fa-globe"></i></div>
                <div class="stat-label text-white opacity-75">إجمالي الطلبات</div>
                <div class="stat-value mb-2"><?php echo count($requests); ?></div>
                <div class="sub-stat text-white opacity-75 mt-auto">اليوم: <span class="fw-bold"><?php echo array_sum(array_column($status_stats, 'today')); ?></span></div>
                <a href="family_visit.php" class="stretched-link"></a>
            </div>
        </div>

        <?php foreach($status_stats as $stat): 
            $isActive = isset($_GET['status_filter']) && $_GET['status_filter'] == $stat['id'];
        ?>
        <div class="col-auto">
            <div class="card border-0 shadow-sm rounded-4 p-3 h-100 mini-card transition-all <?php echo $isActive ? 'ring-2 ring-primary shadow-lg' : ''; ?>"
                 style="min-width: 180px; border-top: 4px solid <?php echo $stat['status_color']; ?> !important;">
                <div class="stat-label text-truncate"><?php echo $stat['status_name']; ?></div>
                <div class="stat-value mb-2" style="color: <?php echo $stat['status_color']; ?>;"><?php echo $stat['total']; ?></div>
                
                <div class="d-flex justify-content-between align-items-center mt-auto">
                    <div class="sub-stat">اليوم: <span class="sub-stat-value"><?php echo $stat['today']; ?></span></div>
                    <?php 
                    $diff = $stat['this_month'] - $stat['last_month'];
                    if ($diff != 0):
                        $color = $diff > 0 ? 'text-success' : 'text-danger';
                        $icon = $diff > 0 ? 'fa-caret-up' : 'fa-caret-down';
                    ?>
                    <div class="sub-stat <?php echo $color; ?>"><i class="fas <?php echo $icon; ?>"></i> <?php echo abs($diff); ?></div>
                    <?php endif; ?>
                </div>
                <a href="family_visit.php?status_filter=<?php echo $stat['id']; ?><?php echo !empty($agent_filter) ? '&agent_filter='.$agent_filter : ''; ?><?php echo !empty($branch_filter) ? '&branch_filter='.$branch_filter : ''; ?>" class="stretched-link"></a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- جدول الطلبات -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">رقم المستند</th>
                            <th>صاحب الطلب</th>
                            <th>الأفراد</th>
                            <th>تكلفة الفاتورة</th>
                            <th>صافي الفاتورة (net_amount)</th>
                            <th>الحالة</th>
                            <th>التاريخ</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($requests as $r): ?>
                        <tr>
                            <td data-label="رقم المستند" class="px-4 fw-bold text-primary"><?php echo h($r['document_no']); ?></td>
                            <td data-label="صاحب الطلب">
                                <div class="fw-bold"><?php echo h($r['owner_name']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($r['owner_id_no']); ?></small>
                            </td>
                            <td data-label="الأفراد"><span class="badge bg-info bg-opacity-10 text-info px-3"><?php echo $r['individuals_count']; ?> أفراد</span></td>
                            <td data-label="تكلفة الفاتورة" class="fw-bold text-primary"><?php echo number_format($r['total_cost'], 2); ?></td>
                            <td data-label="صافي الفاتورة" class="fw-bold text-success"><?php echo number_format($r['total_price'], 2); ?></td>
                            <td data-label="الحالة">
                                <span class="badge rounded-pill" style="background-color: <?php echo $r['status_color']; ?>; color: #fff;">
                                    <?php echo htmlspecialchars($r['status_name']); ?>
                                </span>
                            </td>
                            <td data-label="التاريخ" class="small text-muted"><?php echo date('Y-m-d', strtotime($r['created_at'])); ?></td>
                            <td data-label="الإجراءات" class="text-center">
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-outline-info view-request" data-id="<?php echo $r['id']; ?>" title="عرض"><i class="fas fa-eye"></i></button>
                                    <button class="btn btn-sm btn-outline-primary edit-request" data-id="<?php echo $r['id']; ?>" title="تعديل"><i class="fas fa-edit"></i></button>
                                    <a href="?delete_id=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('هل أنت متأكد من حذف الطلب وكافة الأفراد التابعين له؟')"><i class="fas fa-trash"></i></a>
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

<!-- Modal إضافة طلب جديد -->
<div class="modal fade" id="addRequestModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form id="addRequestForm" method="POST" enctype="multipart/form-data" action="process_family_visit.php?action=add">
                <div class="modal-header bg-info text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة طلب زيارة عائلية</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- القسم الأول: بيانات الطلب -->
                    <div class="section-title mb-3"><i class="fas fa-file-invoice text-info"></i> بيانات الطلب الأساسية</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">رقم المستند</label>
                            <input type="text" name="document_no" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">تاريخ الإصدار</label>
                            <input type="date" name="issue_date" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">نوع التاريخ</label>
                            <select name="date_type" class="form-select rounded-3">
                                <option value="gregorian">ميلادي</option>
                                <option value="hijri">هجري</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">اسم صاحب الطلب</label>
                            <input type="text" name="owner_name" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">رقم السجل/الإقامة</label>
                            <input type="text" name="owner_id_no" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">رقم الجوال</label>
                            <input type="text" name="phone_no" class="form-control rounded-3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">العنوان</label>
                            <input type="text" name="address" class="form-control rounded-3">
                        </div>
                        <!-- Always show branch_id and agent_id as hidden fields based on current user -->
                        <?php if (!empty($currentUser['agent_id'])): ?>
                            <input type="hidden" name="agent_id" id="main_agent_id" value="<?php echo $currentUser['agent_id']; ?>">
                        <?php endif; ?>
                        <?php if (!empty($currentUser['branch_id'])): ?>
                            <input type="hidden" name="branch_id" id="main_branch_id" value="<?php echo $currentUser['branch_id']; ?>">
                        <?php endif; ?>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">صورة الإقامة</label>
                            <input type="file" name="iqama_image" class="form-control rounded-3" accept="image/*">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">مستند الزيارة (PDF)</label>
                            <input type="file" name="document_pdf" class="form-control rounded-3" accept="application/pdf,image/*">
                        </div>
                    </div>

                    <!-- القسم الثاني: الأفراد -->
                    <div class="section-title d-flex justify-content-between align-items-center mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span><i class="fas fa-user-friends text-info"></i> بيانات الأفراد</span>
                            <div class="btn-group shadow-sm rounded-pill overflow-hidden">
                                <button type="button" class="btn btn-sm btn-outline-primary px-3" id="applyDefaultPriceBtn" title="تطبيق السعر الافتراضي">
                                    <i class="fas fa-download me-1"></i> تنزيل السعر
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- نموذج إدخال الفرد (Form Area) -->
                    <div class="bg-light p-3 rounded-4 mb-3 border border-2 border-info border-opacity-10 position-relative shadow-sm">
                        <!-- Pricing Info Badge -->
                        <div id="pricing_info_badge" class="position-absolute top-0 start-50 translate-middle-x badge bg-white border text-primary shadow-sm px-3 py-2 d-none" style="margin-top: -10px; z-index: 5;">
                            <i class="fas fa-tag me-1"></i> التسعيرة: 
                            <span id="target_purchase_label" class="fw-bold me-2">0.00</span> 
                            <span id="target_sale_label" class="fw-bold text-success me-2">0.00</span>
                            <span id="target_currency_label" class="small"></span>
                        </div>

                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label x-small fw-bold">الاسم الكامل</label>
                                <input type="text" id="entry_name" class="form-control form-control-sm rounded-2 border-0 shadow-sm" placeholder="اسم الفرد...">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label x-small fw-bold">رقم الجواز</label>
                                <input type="text" id="entry_passport" class="form-control form-control-sm rounded-2 border-0 shadow-sm" placeholder="رقم الجواز...">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label x-small fw-bold">الصلة</label>
                                <select id="entry_relationship" class="form-select form-select-sm rounded-2 border-0 shadow-sm">
                                    <option value="">اختر...</option>
                                    <?php foreach($relationships as $rel): ?>
                                    <option value="<?php echo $rel['id']; ?>" data-name="<?php echo $rel['name_ar']; ?>"><?php echo $rel['name_ar']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label x-small fw-bold">الجنس</label>
                                <select id="entry_gender" class="form-select form-select-sm rounded-2 border-0 shadow-sm">
                                    <option value="male">ذكر</option>
                                    <option value="female">أنثى</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label x-small fw-bold">تاريخ الميلاد</label>
                                <input type="date" id="entry_dob" class="form-control form-control-sm rounded-2 border-0 shadow-sm">
                            </div>
                            <div class="col-md-1">
                                <label class="form-label x-small fw-bold">العمر</label>
                                <input type="number" id="entry_age" class="form-control form-control-sm rounded-2 bg-white shadow-sm border-0" readonly>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label x-small fw-bold">سعر الشراء</label>
                                <input type="number" step="0.01" id="entry_purchase" class="form-control form-control-sm rounded-2 border-0 shadow-sm fw-bold text-primary">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label x-small fw-bold">سعر البيع</label>
                                <input type="number" step="0.01" id="entry_sale" class="form-control form-control-sm rounded-2 border-0 shadow-sm fw-bold text-success">
                            </div>
                            <div class="col-md-7 d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" id="clearEntryBtn">
                                    تفريغ <i class="fas fa-eraser ms-1"></i>
                                </button>
                                <button type="button" class="btn btn-info text-white rounded-pill px-4 shadow-sm fw-bold" id="pushToListBtn">
                                    إنزال الفرد <i class="fas fa-arrow-down ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive mb-3 shadow-sm rounded-3 overflow-hidden">
                        <table class="table table-bordered table-hover align-middle mb-0" id="individualsTable">
                            <thead class="bg-primary text-white x-small">
                                <tr class="fw-bold">
                                    <th class="border-0">الاسم</th>
                                    <th class="border-0">رقم الجواز</th>
                                    <th class="border-0">الصلة</th>
                                    <th class="border-0">الجنس</th>
                                    <th class="border-0">تاريخ الميلاد</th>
                                    <th class="border-0">العمر</th>
                                    <th class="border-0">تكلفة البند</th>
                                    <th class="border-0">مبلغ البند</th>
                                    <th class="border-0 text-center" style="width: 80px;">إجراء</th>
                                </tr>
                            </thead>
                            <tbody id="individualsList" class="x-small">
                                <!-- سيتم إضافة الأفراد هنا ديناميكياً -->
                            </tbody>
                            <tfoot class="bg-light fw-bold x-small">
                                <tr>
                                    <td colspan="6" class="text-end">الإجمالي:</td>
                                    <td id="totalPurchasePrice" class="text-primary fw-bold">0.00</td>
                                    <td id="totalSalePrice" class="text-success fw-bold">0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- القسم الثالث: البيانات المالية الموحدة -->
                    <?php
                    $current_invoice = [
                        'invoice_date' => date('Y-m-d'),
                        'branch_id' => $_SESSION['branch_id'] ?? null,
                        'source_type' => 'الزيارة العائلية',
                        'delivery_type' => 'cash',
                        'total_amount' => 0,
                        'discount' => 0,
                        'cost_amount' => 0,
                        'amount_received' => 0,
                        'currency_id' => 1,
                        'description' => ''
                    ];
                    $financial_fields_select2_parent = '#addRequestModal';
                    $financial_fields_show_service_select = false;
                    include '../includes/financial_fields.php';
                    ?>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">ملاحظات عامة</label>
                        <textarea name="notes" class="form-control rounded-3" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-info text-white rounded-pill px-5 fw-bold">حفظ الطلب</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Template للفرد الجديد -->
<template id="individualRowTemplate">
    <tr class="individual-row">
        <td class="display-name"></td>
        <td class="display-passport"></td>
        <td class="display-relationship"></td>
        <td class="display-gender"></td>
        <td class="display-dob"></td>
        <td class="display-age text-center"></td>
        <td class="display-purchase fw-bold text-primary"></td>
        <td class="display-sale fw-bold text-success"></td>
        <td class="text-center p-0">
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-link text-primary edit-individual" title="تعديل"><i class="fas fa-edit"></i></button>
                <button type="button" class="btn btn-link text-danger remove-individual" title="حذف"><i class="fas fa-times"></i></button>
            </div>
            <!-- Hidden inputs for form submission -->
            <input type="hidden" name="ind_name[]" class="input-name">
            <input type="hidden" name="ind_passport[]" class="input-passport">
            <input type="hidden" name="ind_relationship[]" class="input-relationship">
            <input type="hidden" name="ind_gender[]" class="input-gender">
            <input type="hidden" name="ind_dob[]" class="input-dob">
            <input type="hidden" name="ind_age[]" class="input-age">
            <input type="hidden" name="ind_cost_amount[]" class="input-purchase purchase-price-input">
            <input type="hidden" name="ind_line_total_amount[]" class="input-sale sale-price-input">
        </td>
    </tr>
    <tr class="requirements-row bg-light bg-opacity-50">
        <td colspan="9">
            <div class="requirements-container p-2 small text-muted">
                <span class="me-2"><i class="fas fa-tasks me-1"></i> المتطلبات:</span>
                <div class="requirements-list d-inline-flex flex-wrap gap-2">
                    <!-- سيتم تحميل المتطلبات هنا -->
                </div>
            </div>
        </td>
    </tr>
</template>

<!-- Modal عرض التفاصيل وتغيير الحالة -->
<div class="modal fade" id="viewRequestModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-light border-0 py-3">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-eye me-2"></i> تفاصيل طلب الزيارة العائلية</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="viewRequestContent">
                <!-- سيتم تحميل المحتوى هنا عبر AJAX -->
                <div class="text-center py-5">
                    <div class="spinner-border text-info" role="status"></div>
                    <div class="mt-2 text-muted">جاري تحميل البيانات...</div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?php echo json_encode(generate_csrf_token()); ?>;
document.addEventListener('DOMContentLoaded', function() {
    const individualsList = document.getElementById('individualsList');
    const template = document.getElementById('individualRowTemplate');
    const pushToListBtn = document.getElementById('pushToListBtn');
    const clearEntryBtn = document.getElementById('clearEntryBtn');

    // حقول الإدخال (Entry Fields)
    const entryName = document.getElementById('entry_name');
    const entryPassport = document.getElementById('entry_passport');
    const entryRelationship = document.getElementById('entry_relationship');
    const entryGender = document.getElementById('entry_gender');
    const entryDob = document.getElementById('entry_dob');
    const entryAge = document.getElementById('entry_age');
    const entryPurchase = document.getElementById('entry_purchase');
    const entrySale = document.getElementById('entry_sale');

    function calculateTotals() {
        let totalPurchase = 0;
        let totalSale = 0;
        let names = [];
        
        const purchaseInputs = document.querySelectorAll('.purchase-price-input');
        const saleInputs = document.querySelectorAll('.sale-price-input');
        const nameInputs = document.querySelectorAll('.input-name');
        
        purchaseInputs.forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) totalPurchase += val;
        });
        
        saleInputs.forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) totalSale += val;
        });

        nameInputs.forEach(input => {
            if (input.value.trim() !== '') {
                names.push(input.value.trim());
            }
        });
        
        document.getElementById('totalPurchasePrice').innerText = totalPurchase.toFixed(2);
        document.getElementById('totalSalePrice').innerText = totalSale.toFixed(2);
        document.getElementById('sale_price').value = totalSale.toFixed(2);
        document.getElementById('purchase_price').value = totalPurchase.toFixed(2);

        // تحديث حقل البيان تلقائياً
        const descriptionInput = document.getElementById('description');
        if (descriptionInput && names.length > 0) {
            const count = names.length;
            const namesStr = names.join(' - ');
            descriptionInput.value = `معاملة زيارة عائلية لعدد (${count}) أفراد: ${namesStr}`;
        } else if (descriptionInput) {
            descriptionInput.value = '';
        }
    }

    // حساب العمر تلقائياً عند تغيير تاريخ الميلاد في النموذج
    entryDob.addEventListener('input', function() {
        if (this.value) {
            const dob = new Date(this.value);
            const diff = Date.now() - dob.getTime();
            const ageDate = new Date(diff);
            const age = Math.abs(ageDate.getUTCFullYear() - 1970);
            entryAge.value = age;
        }
    });

    // تفريغ حقول الإدخال
    function clearForm() {
        entryName.value = '';
        entryPassport.value = '';
        entryRelationship.value = '';
        entryGender.value = 'male';
        entryDob.value = '';
        entryAge.value = '';
        // لا نفرغ الأسعار لتسهيل إدخال الفرد التالي بنفس السعر
    }

    clearEntryBtn.addEventListener('click', clearForm);

    // إنزال الفرد إلى الجدول
    pushToListBtn.addEventListener('click', function() {
        if (!entryName.value || !entryPassport.value || !entryRelationship.value) {
            alert('يرجى إكمال بيانات الاسم والجواز والصلة');
            return;
        }

        const clone = template.content.cloneNode(true);
        const row = clone.querySelector('.individual-row');
        
        // تعبئة البيانات المرئية
        row.querySelector('.display-name').innerText = entryName.value;
        row.querySelector('.display-passport').innerText = entryPassport.passport || entryPassport.value;
        row.querySelector('.display-relationship').innerText = entryRelationship.options[entryRelationship.selectedIndex].text;
        row.querySelector('.display-gender').innerText = entryGender.options[entryGender.selectedIndex].text;
        row.querySelector('.display-dob').innerText = entryDob.value;
        row.querySelector('.display-age').innerText = entryAge.value;
        row.querySelector('.display-purchase').innerText = parseFloat(entryPurchase.value || 0).toFixed(2);
        row.querySelector('.display-sale').innerText = parseFloat(entrySale.value || 0).toFixed(2);

        // تعبئة الحقول المخفية (لإرسالها للسيرفر)
        row.querySelector('.input-name').value = entryName.value;
        row.querySelector('.input-passport').value = entryPassport.value;
        row.querySelector('.input-relationship').value = entryRelationship.value;
        row.querySelector('.input-gender').value = entryGender.value;
        row.querySelector('.input-dob').value = entryDob.value;
        row.querySelector('.input-age').value = entryAge.value;
        row.querySelector('.input-purchase').value = entryPurchase.value;
        row.querySelector('.input-sale').value = entrySale.value;

        individualsList.appendChild(clone);
        calculateTotals();
        clearForm();
        
        // تحديث المتطلبات للسطر المضاف حديثاً
        const lastRow = individualsList.lastElementChild.previousElementSibling;
        updateRequirements(lastRow);
    });

    // إجراءات الجدول (تعديل وحذف)
    individualsList.addEventListener('click', function(e) {
        // حذف
        if (e.target.closest('.remove-individual')) {
            const row = e.target.closest('.individual-row');
            const reqRow = row.nextElementSibling;
            row.remove();
            if (reqRow && reqRow.classList.contains('requirements-row')) reqRow.remove();
            calculateTotals();
        }
        
        // تعديل (سحب البيانات للنموذج)
        if (e.target.closest('.edit-individual')) {
            const row = e.target.closest('.individual-row');
            
            // سحب البيانات للنموذج العلوي
            entryName.value = row.querySelector('.input-name').value;
            entryPassport.value = row.querySelector('.input-passport').value;
            entryRelationship.value = row.querySelector('.input-relationship').value;
            entryGender.value = row.querySelector('.input-gender').value;
            entryDob.value = row.querySelector('.input-dob').value;
            entryAge.value = row.querySelector('.input-age').value;
            entryPurchase.value = row.querySelector('.input-purchase').value;
            entrySale.value = row.querySelector('.input-sale').value;

            // حذف السطر من الجدول بعد سحبه للتعديل
            const reqRow = row.nextElementSibling;
            row.remove();
            if (reqRow && reqRow.classList.contains('requirements-row')) reqRow.remove();
            calculateTotals();
            
            // التركيز على حقل الاسم
            entryName.focus();
        }
    });

    window.updatePaymentLogic = function() {
        const paymentType = $('#payment_type').val();
        const accountSelect = $('#account_id');
        const accountLabel = $('#account_label');
        const amountReceived = $('#amount_received');

        // تفريغ الحسابات
        accountSelect.empty().append('<option value="">اختر الحساب...</option>');
        
        // إعادة تعيين الحقول المخفية
        $('#customer_id_hidden').val('');
        $('#agent_id_hidden').val('');
        $('#branch_id_hidden').val('');

        let accounts = [];
        let label = 'الحساب';

        if (paymentType === 'cash') {
            accounts = <?php echo json_encode($cashboxes_entities); ?>;
            label = 'الصندوق (نقد)';
            amountReceived.prop('readonly', false).removeClass('bg-light');
        } else if (paymentType === 'credit') {
            accounts = <?php echo json_encode($customers_entities); ?>;
            label = 'العميل (آجل)';
            amountReceived.val('0.00').prop('readonly', true).addClass('bg-light');
        } else if (paymentType === 'agent') {
            accounts = <?php echo json_encode($agents_entities); ?>;
            label = 'الوكيل (آجل)';
            amountReceived.val('0.00').prop('readonly', true).addClass('bg-light');
        } else if (paymentType === 'branch') {
            accounts = <?php echo json_encode($branches_accounts); ?>;
            label = 'الفرع (آجل)';
            amountReceived.val('0.00').prop('readonly', true).addClass('bg-light');
        } else if (paymentType === 'bank_transfer') {
            accounts = <?php echo json_encode($banks_entities); ?>;
            label = 'البنك (تحويل)';
            amountReceived.prop('readonly', false).removeClass('bg-light');
        }

        accountLabel.text(label);
        accounts.forEach(acc => {
            const displayName = acc.account_code ? `${acc.account_code} - ${acc.name || acc.account_name}` : (acc.name || acc.account_name);
            const value = paymentType === 'cash' || paymentType === 'bank_transfer' ? acc.account_id : acc.id;
            const customerId = paymentType === 'credit' ? acc.id : '';
            const agentId = paymentType === 'agent' ? acc.id : '';
            
            accountSelect.append(`<option value="${value}" data-customer-id="${customerId}" data-agent-id="${agentId}">${displayName}</option>`);
        });
    };

    window.loadPricesForSelectedAccount = async function() {
        const paymentType = $('#payment_type').val();
        const selectedId = $('#account_id').val();
        const pricingBadge = document.getElementById('pricing_info_badge');

        // تعبئة الحقل المخفي المناسب بناءً على نوع الدفع
        $('#customer_id_hidden').val(paymentType === 'credit' ? selectedId : '');
        $('#agent_id_hidden').val(paymentType === 'agent' ? selectedId : '');
        $('#branch_id_hidden').val(paymentType === 'branch' ? selectedId : '');

        let url = `ajax_family_visit.php?action=get_service_price`;
        if (selectedId) {
            if (paymentType === 'credit') url += `&customer_id=${selectedId}`;
            else if (paymentType === 'agent') url += `&agent_id=${selectedId}`;
            else if (paymentType === 'branch') url += `&branch_id=${selectedId}`;
        }

        try {
            const res = await fetch(url);
            const result = await res.json();
            
            if (result.status === 'success') {
                const data = result.data;
                const purchasePrice = data.purchase_price;
                const salePrice = data.sale_price;
                const currencySymbol = data.currency_symbol;

                // تحديث نموذج الإدخال (Entry Fields)
                if (entryPurchase) entryPurchase.value = purchasePrice;
                if (entrySale) entrySale.value = salePrice;

                // إظهار بادج التسعيرة
                if (pricingBadge) {
                    pricingBadge.classList.remove('d-none');
                    document.getElementById('target_purchase_label').innerText = purchasePrice.toFixed(2);
                    document.getElementById('target_sale_label').innerText = salePrice.toFixed(2);
                    document.getElementById('target_currency_label').innerText = currencySymbol;
                }
                
                // تحديث التيمبلت للأسطر القادمة
                const purchaseInputTpl = template.content.querySelector('.purchase-price-input');
                const saleInputTpl = template.content.querySelector('.sale-price-input');
                if (purchaseInputTpl) purchaseInputTpl.value = purchasePrice;
                if (saleInputTpl) saleInputTpl.value = salePrice;
            } else {
                if (pricingBadge) pricingBadge.classList.add('d-none');
            }
        } catch (err) {
            console.error('Error loading prices:', err);
        }
    };

    // عند فتح مودال الإضافة، تأكد من تحديث منطق الدفع وجلب الأسعار الافتراضية
    const addRequestModal = document.getElementById('addRequestModal');
    if (addRequestModal) {
        addRequestModal.addEventListener('shown.bs.modal', function () {
            updatePaymentLogic();
            loadPricesForSelectedAccount(); // جلب السعر الافتراضي العام عند الفتح
        });
    }

    // دالة جلب السعر التلقائي
    window.updateServicePrices = async function() {
        const agentId = document.getElementById('main_agent_id')?.value;
        const branchId = document.getElementById('main_branch_id')?.value;
        const pricingBadge = document.getElementById('pricing_info_badge');
        
        if (!agentId && !branchId) {
            pricingBadge?.classList.add('d-none');
            return;
        }

        try {
            const res = await fetch(`ajax_family_visit.php?action=get_service_price&agent_id=${agentId}&branch_id=${branchId}`);
            const result = await res.json();
            
            if (result.status === 'success') {
                const data = result.data;
                const purchasePrice = data.purchase_price;
                const salePrice = data.sale_price;
                const currencySymbol = data.currency_symbol;

                // تحديث المدخلات في نموذج الإدخال
                if (entryPurchase) entryPurchase.value = purchasePrice;
                if (entrySale) entrySale.value = salePrice;

                // تحديث جميع المدخلات الحالية في الجدول (إذا رغب المستخدم في تطبيق السعر على الكل)
                // ملاحظة: هذا السلوك يعتمد على رغبة المستخدم، سنتركه لزر "تنزيل السعر" فقط لتجنب تغيير أسعار تم إدخالها يدوياً فجأة

                // إظهار بادج التسعيرة
                if (pricingBadge) {
                    pricingBadge.classList.remove('d-none');
                    document.getElementById('target_purchase_label').innerText = purchasePrice.toFixed(2);
                    document.getElementById('target_sale_label').innerText = salePrice.toFixed(2);
                    document.getElementById('target_currency_label').innerText = currencySymbol;
                }
                
                // تحديث التيمبلت ليكون السعر الافتراضي للأسطر الجديدة
                const purchaseInputTpl = template.content.querySelector('.purchase-price-input');
                const saleInputTpl = template.content.querySelector('.sale-price-input');
                if (purchaseInputTpl) purchaseInputTpl.value = purchasePrice;
                if (saleInputTpl) saleInputTpl.value = salePrice;
            }
        } catch (err) {
            console.error('Error loading prices:', err);
        }
    }

    function addRow() {
        if (!template) return;
        const clone = template.content.cloneNode(true);
        individualsList.appendChild(clone);
        calculateTotals();
    }

    if (addIndividualBtn) {
        addRow(); // إضافة أول صف تلقائياً
        addIndividualBtn.onclick = addRow;
    }

    // زر إضافة فرد مع مضاعفة السعر
    const addIndividualDoubleBtn = document.getElementById('addIndividualDoubleBtn');
    if (addIndividualDoubleBtn) {
        addIndividualDoubleBtn.onclick = async function() {
            const agentId = document.getElementById('main_agent_id')?.value;
            const branchId = document.getElementById('main_branch_id')?.value;
            
            let price = 0;
            if (agentId || branchId) {
                try {
                    const res = await fetch(`ajax_family_visit.php?action=get_service_price&agent_id=${agentId}&branch_id=${branchId}`);
                    const result = await res.json();
                    if (result.status === 'success') {
                        price = parseFloat(result.data.price) * 2; // مضاعفة السعر
                    }
                } catch (err) { console.error('Error fetching price:', err); }
            }

            // إضافة السطر مع السعر المزدوج
            if (!template) return;
            const clone = template.content.cloneNode(true);
            const purchaseInput = clone.querySelector('.purchase-price-input');
            const saleInput = clone.querySelector('.sale-price-input');
            
            if (purchaseInput) purchaseInput.value = price.toFixed(2);
            if (saleInput) saleInput.value = price.toFixed(2);
            
            individualsList.appendChild(clone);
            calculateTotals();
        };
    }

    // زر تنزيل السعر على جميع الأفراد
    const applyPriceBtn = document.getElementById('applyDefaultPriceBtn');
    if (applyPriceBtn) {
        applyPriceBtn.onclick = function() {
            const agentId = document.getElementById('main_agent_id')?.value;
            const branchId = document.getElementById('main_branch_id')?.value;
            
            if (!agentId && !branchId) {
                alert('يرجى اختيار الوكيل أو الفرع أولاً');
                return;
            }
            
            updateServicePrices();
        };
    }

    // جلب الأسعار تلقائياً عند تغيير الوكيل أو الفرع
    const agentSelect = document.querySelector('select[name="agent_id"]');
    const branchSelect = document.querySelector('select[name="branch_id"]');
    
    async function loadServicePrices() {
        const agentId = agentSelect ? agentSelect.value : '';
        const branchId = branchSelect ? branchSelect.value : '';
        
        try {
            const res = await fetch(`ajax_family_visit.php?action=get_service_price&agent_id=${agentId}&branch_id=${branchId}`);
            const result = await res.json();
            
            if (result.status === 'success') {
                const price = result.data.price;
                document.querySelectorAll('.purchase-price-input').forEach(input => input.value = price);
                document.querySelectorAll('.sale-price-input').forEach(input => input.value = price);
                calculateTotals();
            }
        } catch (err) { console.error('Error loading prices:', err); }
    }

    if (agentSelect) agentSelect.addEventListener('change', loadServicePrices);
    if (branchSelect) branchSelect.addEventListener('change', loadServicePrices);

    // تنفيذ أولي لجلب الأسعار إذا كان هناك وكيل أو فرع مختار مسبقاً
    if ((agentSelect && agentSelect.value) || (branchSelect && branchSelect.value)) {
        loadServicePrices();
    }

    if (individualsList) {
        individualsList.addEventListener('click', function(e) {
            if (e.target.closest('.remove-individual')) {
                const row = e.target.closest('.individual-row');
                const reqRow = row.nextElementSibling;
                row.remove();
                if (reqRow && reqRow.classList.contains('requirements-row')) reqRow.remove();
                calculateTotals();
            }
        });

        individualsList.addEventListener('input', function(e) {
            if (e.target.classList.contains('purchase-price-input') || e.target.classList.contains('sale-price-input')) {
                calculateTotals();
            }
            if (e.target.classList.contains('dob-input')) {
                const dob = new Date(e.target.value);
                const ageInput = e.target.closest('tr').querySelector('.age-input');
                if (dob) {
                    const diff = Date.now() - dob.getTime();
                    const ageDate = new Date(diff);
                    const age = Math.abs(ageDate.getUTCFullYear() - 1970);
                    ageInput.value = age;
                    updateRequirements(e.target.closest('tr'));
                }
            }
        });

        individualsList.addEventListener('change', function(e) {
            if (e.target.classList.contains('relationship-select') || e.target.classList.contains('gender-select')) {
                updateRequirements(e.target.closest('tr'));
            }
        });
    }

    async function updateRequirements(row) {
        const relId = row.querySelector('.relationship-select').value;
        const gender = row.querySelector('.gender-select').value;
        const age = row.querySelector('.age-input').value;
        const reqList = row.nextElementSibling.querySelector('.requirements-list');

        if (!relId) {
            reqList.innerHTML = '---';
            return;
        }

        try {
            const res = await fetch(`ajax_family_visit.php?action=get_requirements&relationship_id=${relId}&gender=${gender}&age=${age}`);
            const data = await res.json();
            if (data.length > 0) {
                reqList.innerHTML = data.map(req => `
                    <div class="form-check form-check-inline mb-0">
                        <input class="form-check-input" type="checkbox" checked disabled>
                        <label class="form-check-label x-small ${req.is_mandatory ? 'fw-bold text-dark' : ''}">
                            ${req.requirement_name} ${req.is_mandatory ? '<span class="text-danger">*</span>' : ''}
                        </label>
                    </div>
                `).join('');
            } else {
                reqList.innerHTML = '<span class="x-small">لا توجد متطلبات خاصة</span>';
            }
        } catch (err) {
            console.error('Error loading requirements:', err);
        }
    }

    // عرض تفاصيل الطلب
    document.querySelectorAll('.view-request').forEach(btn => {
        btn.onclick = async function() {
            const id = this.dataset.id;
            const modal = new bootstrap.Modal(document.getElementById('viewRequestModal'));
            modal.show();
            
            const content = document.getElementById('viewRequestContent');
            content.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-info"></div></div>';

            try {
                const res = await fetch(`ajax_family_visit.php?action=get_request_details&id=${id}`);
                const result = await res.json();
                
                if (result.status === 'success') {
                    const req = result.data;
                    content.innerHTML = `
                        <div class="row g-4 text-end" dir="rtl">
                            <!-- بيانات الطلب -->
                            <div class="col-md-4 border-start">
                                <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="fas fa-file-alt me-1 text-info"></i> بيانات المستند</h6>
                                <div class="mb-2"><span class="text-muted small">رقم المستند:</span> <span class="fw-bold">${req.document_no}</span></div>
                                <div class="mb-2"><span class="text-muted small">تاريخ الإصدار:</span> <span>${req.issue_date}</span></div>
                                <div class="mb-2"><span class="text-muted small">صاحب الطلب:</span> <span class="fw-bold">${req.owner_name}</span></div>
                                <div class="mb-2"><span class="text-muted small">رقم السجل:</span> <span>${req.owner_id_no}</span></div>
                                <div class="mb-2"><span class="text-muted small">الجوال:</span> <span>${req.phone_no || '---'}</span></div>
                                <div class="mb-2"><span class="text-muted small">الحالة الحالية:</span> 
                                    <span class="badge rounded-pill" style="background-color: ${req.status_color}; color: #fff;">${req.status_name}</span>
                                </div>
                                <hr>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">تغيير حالة الطلب بالكامل:</label>
                                    <select class="form-select form-select-sm rounded-3 update-request-status" data-id="${req.id}">
                                        ${<?php echo json_encode($statuses); ?>.map(s => `
                                            <option value="${s.id}" ${s.id == req.status_id ? 'selected' : ''}>${s.status_name}</option>
                                        `).join('')}
                                    </select>
                                </div>
                                <hr>
                                <div class="mb-3 bg-light p-2 rounded-3">
                                    <h6 class="fw-bold small mb-2"><i class="fas fa-passport me-1"></i> بيانات التأشيرة (للطلب)</h6>
                                    <div class="mb-2">
                                        <label class="small text-muted">رقم التأشيرة:</label>
                                        <input type="text" class="form-control form-control-sm visa-no-input" value="${req.visa_no || ''}" data-id="${req.id}">
                                    </div>
                                    <div class="mb-2">
                                        <label class="small text-muted">مدة التأشيرة (يوم):</label>
                                        <input type="number" class="form-control form-control-sm visa-duration-input" value="${req.visa_duration || 30}" data-id="${req.id}">
                                    </div>
                                    <button class="btn btn-sm btn-info text-white w-100 save-visa-info" data-id="${req.id}">حفظ بيانات التأشيرة</button>
                                </div>
                                <div class="d-grid gap-2">
                                    ${req.iqama_image ? `<a href="../assets/uploads/family_visits/${req.iqama_image}" target="_blank" class="btn btn-sm btn-outline-info"><i class="fas fa-image me-1"></i> عرض صورة الإقامة</a>` : ''}
                                    ${req.document_pdf ? `<a href="../assets/uploads/family_visits/${req.document_pdf}" target="_blank" class="btn btn-sm btn-outline-dark"><i class="fas fa-file-pdf me-1"></i> عرض مستند الزيارة</a>` : ''}
                                </div>
                            </div>
                            
                            <!-- بيانات الأفراد -->
                            <div class="col-md-8">
                                <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="fas fa-user-friends me-1 text-info"></i> بيانات الأفراد (${req.individuals.length})</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle">
                                        <thead class="bg-light x-small">
                                            <tr>
                                                <th>الاسم</th>
                                                <th>الجواز</th>
                                                <th>الصلة</th>
                                                <th>الحالة</th>
                                                <th>سعر الشراء</th>
                                                <th>سعر البيع</th>
                                                <th class="text-center">إجراء</th>
                                            </tr>
                                        </thead>
                                        <tbody class="small">
                                            ${req.individuals.map(ind => `
                                                <tr>
                                                    <td class="fw-bold">${ind.full_name}</td>
                                                    <td>${ind.passport_no}</td>
                                                    <td>${ind.relationship_name}</td>
                                                    <td><span class="badge bg-light text-dark border">${ind.individual_status}</span></td>
                                                    <td class="text-primary fw-bold">${parseFloat(ind.purchase_price || ind.agent_price).toFixed(2)}</td>
                                                    <td class="text-success fw-bold">${parseFloat(ind.sale_price).toFixed(2)}</td>
                                                    <td class="text-center">
                                                        <button class="btn btn-sm btn-link p-0 update-ind-status" data-id="${ind.id}"><i class="fas fa-sync-alt"></i></button>
                                                    </td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mt-3 p-3 bg-light rounded-3 small">
                                    <div class="fw-bold mb-1 text-muted">ملاحظات:</div>
                                    <div>${req.notes || 'لا توجد ملاحظات'}</div>
                                </div>
                            </div>
                        </div>
                    `;
                }
            } catch (err) {
                content.innerHTML = '<div class="alert alert-danger">خطأ في تحميل البيانات.</div>';
            }
        };
    });

    // زر التعديل (فتح مودال التفاصيل حالياً كحل مؤقت للمراجعة والتعديل)
    document.querySelectorAll('.edit-request').forEach(btn => {
        btn.onclick = function() {
            const id = this.dataset.id;
            // توجيه لزر العرض لأن واجهة العرض تدعم التعديل (الحالة، التأشيرة)
            const viewBtn = document.querySelector(`.view-request[data-id="${id}"]`);
            if (viewBtn) viewBtn.click();
        };
    });

    // تحديث حالة الطلب عبر AJAX
    document.addEventListener('change', async function(e) {
        if (e.target.classList.contains('update-request-status')) {
            const id = e.target.dataset.id;
            const statusId = e.target.value;
            if (confirm('هل تريد تغيير حالة الطلب وكافة الأفراد التابعين له؟')) {
                try {
                    const body = new URLSearchParams({ id, status_id: statusId, csrf_token: CSRF_TOKEN });
                    const res = await fetch('ajax_family_visit.php?action=update_request_status', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body
                    });
                    const result = await res.json();
                    if (result.status === 'success') location.reload();
                    else alert(result.message);
                } catch (err) { alert('خطأ في الاتصال بالسيرفر'); }
            }
        }
    });
    // حفظ بيانات التأشيرة
    document.addEventListener('click', async function(e) {
        if (e.target.classList.contains('save-visa-info')) {
            const id = e.target.dataset.id;
            const visaNo = document.querySelector('.visa-no-input').value;
            const duration = document.querySelector('.visa-duration-input').value;
            
            try {
                const body = new URLSearchParams({ id, visa_no: visaNo, duration, csrf_token: CSRF_TOKEN });
                const res = await fetch('ajax_family_visit.php?action=update_visa_info', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body
                });
                const result = await res.json();
                if (result.status === 'success') {
                    alert('تم حفظ بيانات التأشيرة بنجاح');
                    location.reload();
                } else {
                    alert(result.message);
                }
            } catch (err) { alert('خطأ في الاتصال بالسيرفر'); }
        }
    });
    // تفعيل منطق الدفع عند التحميل
    if (typeof window.updatePaymentLogic === 'function') {
        window.updatePaymentLogic();
    }
});
</script>

<style>
    .section-title { font-size: 1rem; font-weight: 700; color: #1e293b; border-bottom: 2px solid #f1f5f9; padding-bottom: 0.5rem; }
    .x-small { font-size: 0.75rem; }
    .table-sm td, .table-sm th { padding: 0.3rem; }

    /* تحسينات التصميم والوضع الليلي للبطاقات */
    .transition-all { transition: all 0.3s ease; }
    .ring-2 { box-shadow: 0 0 0 2px var(--primary-color); }
    .custom-scrollbar::-webkit-scrollbar { height: 4px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 4px; }
    
    .mini-card {
        min-width: 180px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border: 1px solid rgba(0,0,0,0.05) !important;
        background: #fff;
    }
    .mini-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.08) !important;
    }
    .stat-label { font-size: 0.75rem; font-weight: 700; color: #64748b; margin-bottom: 2px; }
    .stat-value { font-size: 1.5rem; font-weight: 800; line-height: 1; }
    .sub-stat { font-size: 0.65rem; color: #94a3b8; }
    .sub-stat-value { font-weight: 700; color: #1e293b; }
    
    body.theme-dark .stat-label { color: #94a3b8; }
    body.theme-dark .sub-stat-value { color: #e2e8f0; }
    body.theme-dark .mini-card { background: #111827 !important; border-color: #1e2d45 !important; }
</style>

<?php require_once 'footer.php'; ?>
