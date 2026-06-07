<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? '';
header('Content-Type: application/json; charset=utf-8');
rate_limit('ajax_family_visit:' . $action, 60, 60);
require_csrf_for_actions(['update_request_status', 'update_visa_info', 'process_transition']);

if ($action === 'get_requirements') {
    $relationship_id = $_GET['relationship_id'] ?? null;
    $age = $_GET['age'] ?? null;
    $gender = $_GET['gender'] ?? null;

    if (!$relationship_id) {
        echo json_encode([]);
        exit();
    }

    $sql = "SELECT * FROM family_requirements WHERE relationship_id = ?";
    $params = [$relationship_id];

    if ($age !== null) {
        $sql .= " AND min_age <= ? AND max_age >= ?";
        $params[] = $age;
        $params[] = $age;
    }

    if ($gender) {
        $sql .= " AND (gender = ? OR gender = 'both')";
        $params[] = $gender;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

elseif ($action === 'get_request_details') {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
        exit();
    }

    // Get Request
    $stmt = $pdo->prepare("
        SELECT r.*, s.status_name, s.status_color, 
               ag.agent_name, br.branch_name, u.username as creator_name
        FROM family_visit_requests r
        LEFT JOIN statuses s ON r.status_id = s.id
        LEFT JOIN agents ag ON r.agent_id = ag.id
        LEFT JOIN branches br ON r.branch_id = br.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.id = ?
    ");
    $stmt->execute([$id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    // تنفيذ التحقق من عزل البيانات (Data Isolation Security)
    if ($request) {
        // التحقق من صلاحيات عرض الطلب (عزل البيانات)
        $stmt_u = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt_u->execute([$_SESSION['admin_id']]);
        $currU = $stmt_u->fetch();

        $is_super_user = in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'developer']);
        $can_view_all = has_permission('view_all_passports');
        
        if (!$is_super_user && !$can_view_all) {
            if (!empty($currU['agent_id']) && $request['agent_id'] != $currU['agent_id']) {
                echo json_encode(['status' => 'error', 'message' => 'ليس لديك صلاحية عرض هذا الطلب (مرتبط بوكيل آخر)']);
                exit();
            }
            if (!empty($currU['branch_id']) && $request['branch_id'] != $currU['branch_id']) {
                echo json_encode(['status' => 'error', 'message' => 'ليس لديك صلاحية عرض هذا الطلب (مرتبط بفرع آخر)']);
                exit();
            }
        }
    }

    if (!$request) {
        echo json_encode(['status' => 'error', 'message' => 'Request not found']);
        exit();
    }

    // Get Individuals
    $stmt_ind = $pdo->prepare("
        SELECT i.*, rel.name_ar as relationship_name, s.status_name as individual_status
        FROM family_visit_individuals i
        LEFT JOIN family_relationships rel ON i.relationship_id = rel.id
        LEFT JOIN statuses s ON i.status_id = s.id
        WHERE i.request_id = ?
    ");
    $stmt_ind->execute([$id]);
    $request['individuals'] = $stmt_ind->fetchAll(PDO::FETCH_ASSOC);

    // Get Attachments for each individual
    foreach ($request['individuals'] as &$ind) {
        $stmt_att = $pdo->prepare("SELECT * FROM family_individual_attachments WHERE individual_id = ?");
        $stmt_att->execute([$ind['id']]);
        $ind['attachments'] = $stmt_att->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['status' => 'success', 'data' => $request]);
}

elseif ($action === 'get_service_price') {
    $service_id = 5; // ID خدمة زيارة الأسرة في جدول services
    $branch_id = $_GET['branch_id'] ?? null;
    $agent_id = $_GET['agent_id'] ?? null;
    $customer_id = $_GET['customer_id'] ?? null;
    $supplier_id = $_GET['supplier_id'] ?? null;

    // إذا لم يتم تحديد أي جهة، استخدام معلومات المستخدم الحالي (الوكيل أو الفرع)
    if (!$agent_id && !$branch_id && !$customer_id && !$supplier_id) {
        $stmt_u = $pdo->prepare("SELECT agent_id, branch_id FROM users WHERE id = ?");
        $stmt_u->execute([$_SESSION['admin_id']]);
        $u_info = $stmt_u->fetch();
        $agent_id = $u_info['agent_id'];
        $branch_id = $u_info['branch_id'];
    }

    try {
        $price = get_service_price_config($pdo, $service_id, $agent_id, $branch_id, $customer_id, $supplier_id);

        if ($price) {
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'price' => (float) $price['purchase_price'],
                    'purchase_price' => (float) $price['purchase_price'],
                    'sale_price' => (float) $price['sale_price'],
                    'currency_id' => $price['currency_id'],
                    'currency_name' => $price['currency_name'],
                    'currency_symbol' => $price['currency_symbol'],
                    'agent_price' => (float) ($price['agent_price'] ?? 0),
                    'branch_price' => (float) ($price['branch_price'] ?? 0),
                    'target_type' => $price['target_type']
                ]
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'لم يتم إعداد سعر الخدمة لهذه الحالة، يرجى التحقق من إعدادات الأسعار']);
        }
    } catch (Exception $e) {
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ داخلي في النظام']);
    }
}

elseif ($action === 'update_request_status') {
    $id = $_POST['id'] ?? $_GET['id'] ?? null;
    $status_id = $_POST['status_id'] ?? $_GET['status_id'] ?? null;

    if (!$id || !$status_id) {
        echo json_encode(['status' => 'error', 'message' => 'Missing ID or Status']);
        exit();
    }

    try {
        $pdo->beginTransaction();

        // Update Request
        $stmt = $pdo->prepare("UPDATE family_visit_requests SET status_id = ? WHERE id = ?");
        $stmt->execute([$status_id, $id]);

        // Update all individuals in this request
        $stmt_ind = $pdo->prepare("UPDATE family_visit_individuals SET status_id = ? WHERE request_id = ?");
        $stmt_ind->execute([$status_id, $id]);

        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ داخلي في النظام']);
    }
}

elseif ($action === 'update_visa_info') {
    $id = $_POST['id'] ?? $_GET['id'] ?? null;
    $visa_no = $_POST['visa_no'] ?? $_GET['visa_no'] ?? '';
    $duration = intval($_POST['duration'] ?? $_GET['duration'] ?? 30);

    if (!$id) {
        echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
        exit();
    }

    $expiry_date = date('Y-m-d', strtotime("+$duration days"));

    $stmt = $pdo->prepare("UPDATE family_visit_requests SET visa_no = ?, visa_duration = ?, visa_expiry_date = ? WHERE id = ?");
    $stmt->execute([$visa_no, $duration, $expiry_date, $id]);

    echo json_encode(['status' => 'success']);
}
?>

