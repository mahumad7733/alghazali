<?php
ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    error_log('ajax_work_visa.php: PDO is not initialized after loading db.php');
    if (ob_get_length()) ob_clean();
    echo json_encode([
        'status' => 'error',
        'message' => 'ظغظآظآظآ ظآظقظآظغظآظآظق ظآظقظآظآظآظآ ظآظقظآظعظآظق ظآظغ. ظعظآظآ،ظق ظآظقظقظآ­ظآظثظقظآ ظقظآظآ­ظقظآ.'
    ]);
    exit();
}

// session_start() is already called in includes/functions.php
$user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;

if (!$user_id) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

// ظآ،ظقظآ ظآظعظآظق ظآظغ ظآظقظقظآظغظآظآظق ظآظقظآ­ظآظقظع
$stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt_user->execute([$user_id]);
$currentUser = $stmt_user->fetch();

$action = $_GET['action'] ?? '';
if (!empty($action)) {
    if (ob_get_length()) ob_clean();
}
header('Content-Type: application/json; charset=utf-8');
rate_limit('ajax_work_visa:' . $action, 60, 60);
require_csrf_for_actions([
    'approve_finance',
    'post_finance',
    'process_transition',
    'relayer_verify_item',
    'update_checklist',
    'add_relayer_note',
    'mark_resolved',
    'mark_notifs_read',
    'mark_single_notif_read'
]);

if ($action === 'get_work_visa_details') {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT p.*, prof.name_ar as profession_name, br.branch_name, ag.agent_name, cust.full_name as customer_name,
                   s.status_name, COALESCE(s.status_color, '#6c757d') as status_color,
                   COALESCE(u.full_name, u.username) as creator_name,
                   c.currency_name, c.currency_symbol,
                   CONCAT(batch.batch_day, ' - ', batch.batch_month_name, ' - ', batch.batch_year) as batch_name
            FROM passports p
            LEFT JOIN professions prof ON p.profession_id = prof.id
            LEFT JOIN branches br ON p.branch_id = br.id
            LEFT JOIN agents ag ON p.agent_id = ag.id
            LEFT JOIN customers cust ON p.customer_id = cust.id
            LEFT JOIN statuses s ON p.status_id = s.id
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN invoices inv ON p.invoice_id = inv.id
            LEFT JOIN currencies c ON inv.currency_id = c.id
            LEFT JOIN batches batch ON p.batch_id = batch.id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        // ظآظقظغظآ­ظقظق ظقظق  ظآظقظآظقظآظآ­ظعظآ (Data Isolation Security)
        if ($data) {
            $is_super_user = in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'developer']);
            $can_view_all = has_permission('view_all_passports');

            if (!$is_super_user && !$can_view_all) {
                if (!empty($currentUser['agent_id']) && $data['agent_id'] != $currentUser['agent_id']) {
                    throw new Exception('ظقظعظآ ظقظآظعظئ ظآظقظآظآ­ظعظآ ظقظآظآظآ ظقظآظق ظآظقظقظآظآظقظقظآ');
                }
                if (!empty($currentUser['branch_id']) && $data['branch_id'] != $currentUser['branch_id']) {
                    throw new Exception('ظقظعظآ ظقظآظعظئ ظآظقظآظآ­ظعظآ ظقظآظآظآ ظقظآظآظقظقظآظغ ظقظآظآ ظآظقظعظآظآ');
                }
            }
        }

        if ($data) {
            // Get current step settings
            $stmt_step = $pdo->prepare("SELECT show_checklist FROM workflow_steps WHERE id = ?");
            $stmt_step->execute([$data['status_id']]);
            $step_settings = $stmt_step->fetch();
            $data['show_checklist'] = $step_settings['show_checklist'] ?? 0;

            // 1. Check if it's part of a group
            $stmt_members = $pdo->prepare("SELECT id, full_name, passport_number, status_id FROM passports WHERE parent_id = ? OR (id = ? AND parent_id IS NOT NULL)");
            $stmt_members->execute([$id, $id]);
            $data['group_members'] = $stmt_members->fetchAll(PDO::FETCH_ASSOC);

            // 2. Checklist
            $stmt_check = $pdo->prepare("
                SELECT pr.id as requirement_id, pr.requirement_name,
                       COALESCE(wvc.is_completed, 0) as is_completed,
                       COALESCE(wvc.relayer_verified, 0) as relayer_verified,
                       wvc.verified_at,
                       COALESCE(u.full_name, u.username) as verifier_name
                FROM profession_requirements pr
                LEFT JOIN work_visa_checklist wvc ON pr.id = wvc.requirement_id AND wvc.passport_id = ?
                LEFT JOIN users u ON wvc.verified_by = u.id
                WHERE pr.profession_id = ?
                GROUP BY pr.id
            ");
            $stmt_check->execute([$id, $data['profession_id'] ?? 0]);
            $data['checklist'] = $stmt_check->fetchAll(PDO::FETCH_ASSOC);

            // 3. Audit Logs
            $stmt_logs = $pdo->prepare("
                SELECT tsl.*, s_old.status_name as old_status, s_new.status_name as new_status,
                       COALESCE(u.full_name, u.username) as changer_name,
                       r.name as role_name
                FROM transaction_status_logs tsl
                LEFT JOIN statuses s_old ON tsl.old_status_id = s_old.id
                LEFT JOIN statuses s_new ON tsl.new_status_id = s_new.id
                LEFT JOIN users u ON tsl.changed_by = u.id
                LEFT JOIN roles r ON tsl.changed_role_id = r.id
                WHERE tsl.transaction_id = ?
                ORDER BY tsl.changed_at DESC
            ");
            $stmt_logs->execute([$id]);
            $data['audit_logs'] = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);

            // 4. Workflow Transitions
            $transitions = [];
            $all_steps = [];
            $current_step_id = null;
            $workflow = get_workflow_for_transaction($data['transaction_type'] ?? 'work_visa', $data['branch_id'] ?? null);
            if ($workflow) {
                if (!empty($data['status_id']) || (isset($data['status_id']) && $data['status_id'] == 0)) {
                    $stmt_step = $pdo->prepare("SELECT id FROM workflow_steps WHERE workflow_id = ? AND status_id = ?");
                    $stmt_step->execute([$workflow['id'], $data['status_id']]);
                    $current_step_id = $stmt_step->fetchColumn();

                    if ($current_step_id) {
                        $role_id = $_SESSION['role_id'] ?? $currentUser['role_id'] ?? null;
                        $transitions = get_allowed_transitions($workflow['id'], $current_step_id, $role_id, $user_id);

                        // ظغظآظعظعظآ ظآظقظآظق ظغظقظآظقظآظغ ظآظق ظآظظظق ظآظقظق ظآ­ظآظقظآ ظآظقظثظآظآظآظق (ظقظق ظآ ظآظآظآظآظآ ظآظقظآ ظآظقظغظآظئظعظآ ظآظآظآ ظئظآظق ظغ ظقظئظغظقظقظآ)
                        if (is_array($transitions) && count($transitions) > 0) {
                            $all_verified = true;
                            if (empty($data['checklist'])) {
                                $all_verified = false; // ظقظآ ظغظثظآ،ظآ ظثظآظآظآظق ظآظآظقظآظق ظآظث ظقظق ظغظغظآ،ظقظآ ظآظآظآ
                            } else {
                                foreach ($data['checklist'] as $item) {
                                    if ($item['relayer_verified'] == 0) {
                                        $all_verified = false;
                                        break;
                                    }
                                }
                            }

                            if ($all_verified) {
                                // ظآظآظآ ظئظآظق ظغ ظئظق ظآظقظثظآظآظآظق ظقظآ¤ظئظآظآظإ ظق ظآ­ظآظع ظآظآ "ظآظقظآ ظغظآظئظعظآ ظآظآظغظقظآظق ظثظآظآظآظق"
                                $transitions = array_filter($transitions, function ($tr) {
                                    return strpos($tr['to_step_name'], 'ظآظقظآ ظغظآظئظعظآ ظآظآظغظقظآظق ظثظآظآظآظق') === false &&
                                        strpos($tr['to_step_name'], 'ظغظآظقظعظق ظقظقظعظآظآ ظآظقظآظآظعظآظع') === false;
                                });
                                // ظآظآظآظآظآ ظغظآظعظعظق  ظآظقظقظعظآظغظعظآ­ ظآظآظآ ظآظقظعظقظغظآظآ ظقظآظقظآظق  ظآظقظق json_encode ظئظقظآظعظثظعظآ ظثظقظعظآ ظئظآظآظق 
                                $transitions = array_values($transitions);
                            }
                        }
                    }
                }

                // ظآ،ظقظآ ظآ،ظقظعظآ ظآظقظآظآظثظآظغ ظآظآظآ ظئظآظق  ظآظقظقظآظغظآظآظق ظقظآظعظق ظآظقظآظآ­ظعظآ (ظآظآظقظق /ظآظعظعظقظثظآظآ)
                if (in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'developer', 'ظقظآظعظآ', 'ظقظآظآظقظآ،'])) {
                    $stmt_all = $pdo->prepare("SELECT id, step_name, status_id FROM workflow_steps WHERE workflow_id = ? ORDER BY sort_order ASC");
                    $stmt_all->execute([$workflow['id']]);
                    $all_steps = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            $data['transitions'] = is_array($transitions) ? $transitions : [];
            $data['all_workflow_steps'] = $all_steps;
            $data['current_step_id'] = $current_step_id;

            // 4.5 ظآظقظغظآ­ظقظق ظقظق  ظثظآ،ظثظآ ظآظقظآظآظغ ظآظآظغظقظآظآ ظقظآظقظقظآ
            $stmt_pending = $pdo->prepare("
                SELECT ar.*, ws_to.step_name as to_step_name
                FROM workflow_approval_requests ar
                JOIN workflow_steps ws_to ON ar.to_step_id = ws_to.id
                WHERE ar.passport_id = ? AND ar.status = 'pending'
                LIMIT 1
            ");
            $stmt_pending->execute([$id]);
            $data['pending_approval'] = $stmt_pending->fetch(PDO::FETCH_ASSOC) ?: null;

            // 5. Financial Data
            if (has_permission('work_visa_financial_view')) {
                // ظآظآظغظآظآظآظق ظآ،ظآظثظق ظآظقظقظآظغظق ظآظآظغ ظآظقظآ،ظآظعظآ ظقظآظآظآظآظآ ظقظآظقظآظق  ظآظقظآظآظغظقظآظآظآ
                $stmt_paid = $pdo->prepare("SELECT SUM(total_amount) FROM documents WHERE reference_id = ? AND reference_type = 'work_visa' AND document_type = 'Receipt_Voucher'");
                $stmt_paid->execute([$id]);
                $data['paid_amount'] = $stmt_paid->fetchColumn() ?: 0;

                $stmt_last_pay = $pdo->prepare("SELECT total_amount as amount, document_number as receipt_number, document_date as date FROM documents WHERE reference_id = ? AND reference_type = 'work_visa' AND document_type = 'Receipt_Voucher' ORDER BY document_date DESC, id DESC LIMIT 1");
                $stmt_last_pay->execute([$id]);
                $last_pay = $stmt_last_pay->fetch(PDO::FETCH_ASSOC);
                $data['last_payment'] = $last_pay ?: null;

                if (!empty($data['agent_id'])) {
                    $stmt_acc = $pdo->prepare("SELECT ua.id, ua.account_name_ar as account_name FROM unified_accounts ua JOIN agents a ON ua.id = a.account_id WHERE a.id = ? LIMIT 1");
                    $stmt_acc->execute([$data['agent_id']]);
                    $data['linked_account'] = $stmt_acc->fetch(PDO::FETCH_ASSOC) ?: null;
                } elseif (!empty($data['branch_id'])) {
                    $stmt_acc = $pdo->prepare("SELECT ua.id, ua.account_name_ar as account_name FROM unified_accounts ua JOIN branches b ON ua.id = b.account_id WHERE b.id = ? LIMIT 1");
                    $stmt_acc->execute([$data['branch_id']]);
                    $data['linked_account'] = $stmt_acc->fetch(PDO::FETCH_ASSOC) ?: null;
                } else {
                    $data['linked_account'] = null;
                }
            } else {
                $data['paid_amount'] = 0;
                $data['last_payment'] = null;
                $data['linked_account'] = null;
            }

            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'data' => $data]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Transaction not found']);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'ظ­ظظ ظظظ ظظظعع عع ظععظظع']);
    }
} elseif ($action === 'approve_finance') {
    if (!has_permission('work_visa_accounts_approve')) {
        echo json_encode(['status' => 'error', 'message' => 'No permission']);
        exit();
    }
    $id = $_POST['id'];
    try {
        $stmt = $pdo->prepare("UPDATE passports SET is_financial_approved = 1, financial_approved_at = NOW(), financial_approved_by = ? WHERE id = ?");
        $stmt->execute([$user_id, $id]);
        echo json_encode(['status' => 'success', 'message' => 'ظغظق ظآظقظآظآظغظقظآظآ ظآظقظقظآظقظع ظآظق ظآ،ظآظآ­']);
    } catch (Exception $e) {
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'ظ­ظظ ظظظ ظظظعع عع ظععظظع']);
    }
} elseif ($action === 'post_finance') {
        if (!has_permission('work_visa_financial_post')) {
            echo json_encode(['status' => 'error', 'message' => 'ععظ عظعع ظعظظ­عظ ظعظظظ­عع ظععظعع']);
            exit;
        }
        $id = intval($_POST['id']);
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM passports WHERE id = ?");
            $stmt->execute([$id]);
            $p = $stmt->fetch();

            if (!$p) throw new Exception("ظععظظععظ ظعظ ععظ،عظظ");
            
            $user_id = $_SESSION['admin_id'];
            $posted_count = 0;

            // 1. ظظظ­عع عظظعظظ ظعظعظ
            if ($p['invoice_id']) {
                $stmt_inv = $pdo->prepare("SELECT invoice_status FROM invoices WHERE id = ?");
                $stmt_inv->execute([$p['invoice_id']]);
                if ($stmt_inv->fetchColumn() === 'draft') {
                    php_post_invoice($pdo, $p['invoice_id'], $user_id);
                    $posted_count++;
                }
            }

            // 2. ظظظ­عع عظظعظظ ظعظظظظ (ظع عظ،ظظ)
            $stmt_pur = $pdo->prepare("SELECT id, invoice_status FROM invoices WHERE source_type = 'ظظظعظظ ظعع' AND source_id = ? AND invoice_category = 'purchase'");
            $stmt_pur->execute([$id]);
            while ($pur = $stmt_pur->fetch()) {
                if ($pur['invoice_status'] === 'draft') {
                    php_post_invoice($pdo, $pur['id'], $user_id);
                    $posted_count++;
                }
            }

            // ظظ­ظعظ ظ­ظعظ ظععظظععظ
            $stmt_upd = $pdo->prepare("UPDATE passports SET is_posted = 1, financial_posted_at = NOW(), financial_posted_by = ? WHERE id = ?");
            $stmt_upd->execute([$user_id, $id]);

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => "ظع ظظظ­عع $posted_count ععظظعظ ظعظ،ظظ­"]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        } elseif ($action === 'get_step_fields') {
    $step_id = $_GET['step_id'] ?? null;
    if (!$step_id) {
        echo json_encode(['status' => 'error', 'message' => 'Step ID missing']);
        exit();
    }

    $fields = get_step_fields($step_id);
    // ظآ،ظقظآ ظآظعظآظق ظآظغ ظآظقظآ­ظقظثظق (labels, types)
    // ظقظق ظآ ظق ظعظغظآظآ ظقظآظعظثظعظآ ظآظآظعظآظآ ظقظق  ظآظآظقظآظظ ظآظقظآ­ظقظثظقظإ ظثظآظق ظقظثظق ظآظغظآ­ظثظعظقظقظآ ظقظقظآظقظثظقظآظغ ظآظآظآ
    $field_info = [];
    foreach ($fields as $field) {
        $field = trim($field);
        if (empty($field)) continue;

        switch ($field) {
            case 'batch_no':
                $field_info[] = ['name' => $field, 'label' => 'ظآظقظق ظآظقظآظقظآ / ظآظقظآظآظغظآ', 'type' => 'text', 'required' => true];
                break;
            case 'request_date':
                $field_info[] = ['name' => $field, 'label' => 'ظغظآظآظعظآ ظآظقظآظقظآ', 'type' => 'date', 'required' => true];
                break;
            case 'main_branch_delivery_date':
                $field_info[] = ['name' => $field, 'label' => 'ظغظآظآظعظآ ظآظقظغظآظقظعظق ظقظقظعظآظآ ظآظقظآظآظعظآظع', 'type' => 'date', 'required' => true];
                break;
            case 'received_date':
                $field_info[] = ['name' => $field, 'label' => 'ظغظآظآظعظآ ظآظقظآظآظغظقظآظق', 'type' => 'date', 'required' => true];
                break;
            case 'sent_to_embassy_date':
                $field_info[] = ['name' => $field, 'label' => 'ظغظآظآظعظآ ظآظقظغظآظقظعظق ظقظقظآظعظآظآظآ', 'type' => 'date', 'required' => true];
                break;
            case 'embassy_exit_date':
                $field_info[] = ['name' => $field, 'label' => 'ظغظآظآظعظآ ظآظقظآظقظآ', 'type' => 'date', 'required' => true];
                break;
            case 'arrival_office_date':
                $field_info[] = ['name' => $field, 'label' => 'ظغظآظآظعظآ ظآظقظثظآظثظق ظآظقظقظئظغظآ', 'type' => 'date', 'required' => true];
                break;
            case 'visa_no':
                $field_info[] = ['name' => $field, 'label' => 'ظآظقظق ظآظقظغظآظآظعظآظآ', 'type' => 'text', 'required' => true];
                break;
            case 'visa_issue_date':
                $field_info[] = ['name' => $field, 'label' => 'ظغظآظآظعظآ ظآظآظآظآظآ ظآظقظغظآظآظعظآظآ', 'type' => 'date', 'required' => true];
                break;
            case 'visa_expiry_date':
                $field_info[] = ['name' => $field, 'label' => 'ظغظآظآظعظآ ظآظق ظغظقظآظظ ظآظقظغظآظآظعظآظآ', 'type' => 'date', 'required' => true];
                break;
            case 'transport_delivery_date':
                $field_info[] = ['name' => $field, 'label' => 'ظغظآظآظعظآ ظآظقظغظآظقظعظق ظقظآظآظئظآ ظآظقظق ظقظق', 'type' => 'date', 'required' => true];
                break;
            case 'delivery_date':
                $field_info[] = ['name' => $field, 'label' => 'ظغظآظآظعظآ ظآظقظغظآظقظعظق ظقظقظآظقظعظق', 'type' => 'date', 'required' => true];
                break;
            case 'customer_receiver_name':
                $field_info[] = ['name' => $field, 'label' => 'ظآظآظق ظآظقظآظقظعظق ظآظقظقظآظغظقظق', 'type' => 'text', 'required' => true];
                break;
            case 'cancellation_reason':
                $field_info[] = ['name' => $field, 'label' => 'ظآظآظآ ظآظقظآظقظظظآظظ', 'type' => 'textarea', 'required' => true];
                break;
            case 'reject_reason':
                $field_info[] = ['name' => $field, 'label' => 'ظآظآظآ ظآظقظآظعظآ', 'type' => 'textarea', 'required' => true];
                break;

            // ظقظقظآ­ظعظآظآ ظآظقظق ظآظقظغظثظآظعظق ظقظآ ظآظع ظغظآظقظعظآظغ ظقظآظعظقظآ
            case 'visa_number':
                $field_info[] = ['name' => 'visa_no', 'label' => 'ظآظقظق ظآظقظغظآظآظعظآظآ', 'type' => 'text', 'required' => true];
                break;
            case 'office_name':
                $field_info[] = ['name' => 'arrival_office_date', 'label' => 'ظغظآظآظعظآ ظثظآظثظق ظآظقظقظئظغظآ', 'type' => 'date', 'required' => true];
                break;
        }
    }

    echo json_encode(['status' => 'success', 'fields' => $field_info]);
    exit();
} elseif ($action === 'process_transition') {
    $passport_ids = $_POST['passport_id'] ?? null;
    $to_step_id = $_POST['to_step_id'] ?? null;
    $notes = $_POST['notes'] ?? '';

    if (empty($passport_ids)) {
        echo json_encode(['status' => 'error', 'message' => 'ظعظآظآ،ظق ظآظآظغظعظآظآ ظقظآظآظقظقظآ ظثظآظآ­ظآظآ ظآظقظق ظآظقظآظقظق']);
        exit();
    }

    // ظغظآظئظآ ظقظق  ظآظق ظقظآ ظقظآظعظثظعظآ
    if (!is_array($passport_ids)) $passport_ids = [$passport_ids];
    $first_passport_id = $passport_ids[0];

    if (!$to_step_id) {
        echo json_encode(['status' => 'error', 'message' => 'Missing data']);
        exit();
    }

    // ظآ،ظقظآ ظآظقظقظآظآ­ظقظآ ظآظقظآ­ظآظقظعظآ ظقظقظآ،ظثظآظآ (ظق ظآظآظآ ظآظقظآظثظق ظئظآظآظآظآ ظقظقظغظآ­ظقظق ظقظق  ظآظقظآظق ظغظقظآظق)
    $stmt_curr = $pdo->prepare("SELECT status_id, full_name, passport_number FROM passports WHERE id = ?");
    $stmt_curr->execute([$first_passport_id]);
    $passport = $stmt_curr->fetch();
    if (!$passport) {
        echo json_encode(['status' => 'error', 'message' => 'ظآظقظقظآظآظقظقظآ ظظظعظآ ظقظثظآ،ظثظآظآ']);
        exit();
    }
    $from_step_id = $passport['status_id'];

    // ظآظقظغظآ­ظقظق ظقظق  ظقظآظآظآظآ ظآظقظآظق ظغظقظآظق ظثظقظق ظغظغظآظقظآ ظآظآظغظقظآظآ
    $stmt_trans = $pdo->prepare("SELECT require_approval FROM workflow_transitions WHERE from_step_id = ? AND to_step_id = ? LIMIT 1");
    $stmt_trans->execute([$from_step_id, $to_step_id]);
    $require_approval = $stmt_trans->fetchColumn();

    // ظآ،ظقظآ ظآظقظآ­ظقظثظق ظآظقظقظآظقظثظآظآ ظقظقظقظآظآ­ظقظآ
    $required_fields = get_step_fields($to_step_id);
    $extra_data = [];
    if (!empty($required_fields)) {
        foreach ($required_fields as $field) {
            if (isset($_POST[$field])) {
                $extra_data[$field] = $_POST[$field];
            }
        }
    }

    if ($require_approval) {
        // ظآظقظغظآ­ظقظق ظقظق  ظثظآ،ظثظآ ظآظقظآ ظقظآظقظق ظقظآظآظقظآظق ظقظق ظعظآ ظآظقظقظآظآظقظقظآ ظثظآظقظثظآ،ظقظآ
        $stmt_check = $pdo->prepare("SELECT id FROM workflow_approval_requests WHERE passport_id = ? AND to_step_id = ? AND status = 'pending' LIMIT 1");
        $stmt_check->execute([$first_passport_id, $to_step_id]);
        if ($stmt_check->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'ظقظق ظآظئ ظآظقظآ ظآظآظغظقظآظآ ظقظآظقظق ظآظآظقظعظآظق ظقظقظآظق ظآظقظقظآظآظقظقظآ']);
            exit();
        }

        // ظآظق ظآظآظظ ظآظقظآ ظآظآظغظقظآظآ ظقظئظق ظقظآظآظقظقظآ ظقظآظغظآظآظآ
        try {
            $pdo->beginTransaction();
            foreach ($passport_ids as $id) {
                // ظآ،ظقظآ ظآظعظآظق ظآظغ ظئظق ظآ،ظثظآظآ ظقظقظآظآظآظآظآظآظغ
                $stmt_p = $pdo->prepare("SELECT full_name, status_id FROM passports WHERE id = ?");
                $stmt_p->execute([$id]);
                $p_data = $stmt_p->fetch();

                $stmt_app = $pdo->prepare("INSERT INTO workflow_approval_requests (passport_id, from_step_id, to_step_id, requested_by, requested_role_id, notes, extra_data) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt_app->execute([$id, $p_data['status_id'], $to_step_id, $user_id, $_SESSION['role_id'] ?? null, $notes, json_encode($extra_data)]);
                $request_id = $pdo->lastInsertId();

                // ظآظآظآظآظق ظآظآظآظآظآ ظقظقظقظآظآظآظظ ظقظئظق ظآظقظآ
                $title = "ظآظقظآ ظآظآظغظقظآظآ ظآظق ظغظقظآظق ظآظعظآ ظآظقظق";
                $message = "ظقظق ظآظئ ظآظقظآ ظآظآظغظقظآظآ ظآ،ظآظعظآ ظقظقظقظآظآظقظقظآ (" . $p_data['full_name'] . ").\nظعظآظآ،ظق ظقظآظآظآ،ظآظآ ظآظقظآظقظآ ظثظآظقظقظثظآظعظقظآ ظآظقظعظق.";
                $link = "workflow_approvals.php?id=" . $request_id;

                $stmt_admins = $pdo->query("SELECT id FROM users WHERE role_id = 1 OR role = 'admin'");
                $admins = $stmt_admins->fetchAll();

                foreach ($admins as $admin) {
                    $stmt_n = $pdo->prepare("INSERT INTO notifications (user_id, title, message, link, type, created_by) VALUES (?, ?, ?, ?, 'warning', ?)");
                    $stmt_n->execute([$admin['id'], $title, $message, $link, $user_id]);
                }
            }
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'ظغظق ظآظآظآظآظق ظآظقظآ ظآظقظآظآظغظقظآظآ ظقظقظقظآظعظآ ظآظق ظآ،ظآظآ­']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log(basename(__FILE__) . ': ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'ظ­ظظ ظظظ ظظظعع عع ظععظظع']);
        }
    } else {
        // ظغظآظآ­ظعظق ظقظآظآظآظآ (ظآظآظقظآ change_transaction_status ظغظآظآظق ظآظقظقظآظعظثظعظآ)
        if (change_transaction_status($passport_ids, $to_step_id, $user_id, $notes, $extra_data)) {
            echo json_encode(['status' => 'success', 'message' => 'ظغظق ظق ظقظق ظآظقظقظآظآظقظقظآ ظآظق ظآ،ظآظآ­']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ظعظآظق ظق ظقظق ظآظقظقظآظآظقظقظآ']);
        }
    }
    exit();
} elseif ($action === 'get_full_details') {
    // Deleted this block because it's replaced by get_work_visa_details JSON response
    exit();
} elseif ($action === 'relayer_verify_item') {
    $passport_id = $_POST['passport_id'];
    $requirement_id = $_POST['requirement_id'];
    $verified = $_POST['verified']; // 1 or 0

    try {
        // ظآظآظآ ظئظآظق  ظعظآ­ظآظثظق ظآظقظغظآظآظآ،ظآ ظآظق  ظآظقظغظآظئظعظآ (verified = 0)
        if ($verified == 0) {
            // ظآظقظغظآ­ظقظق ظقظق  ظآظقظآظقظآظآ­ظعظآ: ظقظق ظعظقظقظئ ظآظقظآظآ­ظعظآ ظآظقظغظآظآظعظقظغ ظآظث ظقظق ظقظث ظقظآظعظآ/ظقظآظآظقظآ،ظغ
            $can_revert = has_permission('work_visa_edit_verified_docs') ||
                in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'developer', 'ظقظآظعظآ', 'ظقظآظآظقظآ،']);

            if (!$can_revert) {
                echo json_encode(['status' => 'error', 'message' => 'ظقظعظآ ظقظآظعظئ ظآظقظآظآ­ظعظآ ظآظقظغظآظآظآ،ظآ ظآظق  ظغظآظئظعظآ ظآظقظثظآظعظقظآ. ظعظآظآ،ظق ظآظقظغظثظآظآظق ظقظآ ظآظقظقظآظعظآ ظآظث ظآظقظقظآظآظقظآ،.']);
                exit();
            }
        }

        $stmt = $pdo->prepare("
            UPDATE work_visa_checklist
            SET relayer_verified = ?, verified_by = ?, verified_at = NOW()
            WHERE passport_id = ? AND requirement_id = ?
        ");
        $stmt->execute([$verified, $user_id, $passport_id, $requirement_id]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'ظ­ظظ ظظظ ظظظعع عع ظععظظع']);
    }
} elseif ($action === 'update_checklist') {
    $passport_id = $_POST['passport_id'];
    $checklist_items = $_POST['checklist'] ?? []; // Array of {requirement_id, is_completed}

    $pdo->beginTransaction();
    try {
        foreach ($checklist_items as $item) {
            $stmt = $pdo->prepare("
                INSERT INTO work_visa_checklist (passport_id, requirement_id, is_completed, verified_by, verified_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE is_completed = VALUES(is_completed), verified_by = VALUES(verified_by), verified_at = NOW()
            ");
            $stmt->execute([$passport_id, $item['requirement_id'], $item['is_completed'], $user_id]);
        }
        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'ظ­ظظ ظظظ ظظظعع عع ظععظظع']);
    }
} elseif ($action === 'add_relayer_note') {
    $passport_id = $_POST['passport_id'];
    $note = $_POST['note'];

    try {
        $pdo->beginTransaction();

        // ظغظآ­ظآظعظآ ظقظقظآظآ­ظآظآظغ ظآظقظآ،ظثظآظآ ظثظغظآظعظعظق ظقظآ ظئظظظعظآ ظقظآ­ظقظثظقظآ
        $stmt = $pdo->prepare("UPDATE passports SET relayer_notes = ?, is_resolved = 0 WHERE id = ?");
        $stmt->execute([$note, $passport_id]);

        // ظآ،ظقظآ ظآظعظآظق ظآظغ ظآظقظآ،ظثظآظآ ظقظآظآظآظآظق ظآظآظآظآظآ
        $stmt_p = $pdo->prepare("SELECT passport_number, agent_id, branch_id, full_name FROM passports WHERE id = ?");
        $stmt_p->execute([$passport_id]);
        $passport = $stmt_p->fetch();

        if ($passport) {
            $title = "ظقظقظآظآ­ظآظآ ظآ،ظآظعظآظآ ظقظق  ظآظقظقظآظآ­ظق";
            $message = "ظقظآظق ظآظقظقظآظآ­ظق ظآظآظآظآظعظآ ظقظقظآظآ­ظآظآ ظآظقظق ظآظقظقظآظآظقظقظآ (" . $passport['full_name'] . ") - ظآظقظق ظآظقظآ،ظثظآظآ: " . $passport['passport_number'] . ".\nظآظقظقظقظآظآ­ظآظآ: " . $note;
            $link = "work_visa.php?id=" . $passport_id;

            $stmt_n = $pdo->prepare("INSERT INTO notifications (agent_id, branch_id, title, message, link, type, created_by) VALUES (?, ?, ?, ?, ?, 'danger', ?)");
            $stmt_n->execute([$passport['agent_id'], $passport['branch_id'], $title, $message, $link, $user_id]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'ظغظق ظآظآظآظآظق ظآظقظقظقظآظآ­ظآظآ ظثظآظقظآظآظآظآظآ ظآظق ظآ،ظآظآ­']);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'ظ­ظظ ظظظ ظظظعع عع ظععظظع']);
    }
} elseif ($action === 'mark_resolved') {
    $id = $_POST['id'];
    try {
        $stmt = $pdo->prepare("UPDATE passports SET is_resolved = 1 WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'ظ­ظظ ظظظ ظظظعع عع ظععظظع']);
    }
} elseif ($action === 'get_new_notifications') {
    header('Content-Type: application/json');
    $agent_id = $_SESSION['agent_id'] ?? null;
    $branch_id = $_SESSION['branch_id'] ?? null;
    $last_id = $_GET['last_id'] ?? 0;

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM notifications
            WHERE (agent_id = ? OR branch_id = ? OR user_id = ?)
            AND id > ?
            ORDER BY id DESC
        ");
        $stmt->execute([$agent_id, $branch_id, $user_id, $last_id]);
        $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'notifications' => $notifs]);
    } catch (Exception $e) {
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'ظ­ظظ ظظظ ظظظعع عع ظععظظع']);
    }
    exit();
} elseif ($action === 'get_notifications') {
    $agent_id = $_SESSION['agent_id'] ?? null;
    $branch_id = $_SESSION['branch_id'] ?? null;

    $sql = "SELECT * FROM notifications WHERE (agent_id = ? OR branch_id = ?) AND is_read = 0 ORDER BY created_at DESC LIMIT 5";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$agent_id, $branch_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($notifications);
} elseif ($action === 'mark_notifs_read') {
    $agent_id = $_SESSION['agent_id'] ?? null;
    $branch_id = $_SESSION['branch_id'] ?? null;
    $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
    $user_role = $_SESSION['role'] ?? null;

    // ظآظق ظآظظ ظآظآظغظآظقظآظق ظقظآ­ظآظق  ظقظقظآظآظآظآظآظآظغ
    $where_conditions = [];
    $params = [];

    if ($user_id) {
        $where_conditions[] = "user_id = ?";
        $params[] = $user_id;
    }

    if ($user_role) {
        $where_conditions[] = "role_id = ?";
        $params[] = $user_role;
    }

    if ($agent_id) {
        $where_conditions[] = "agent_id = ?";
        $params[] = $agent_id;
    }

    if ($branch_id) {
        $where_conditions[] = "branch_id = ?";
        $params[] = $branch_id;
    }

    // ظآظآظآ ظقظق ظعظئظق  ظقظق ظآظئ ظآظآظثظآظإ ظآ­ظآظآ ظآ،ظقظعظآ ظآظقظآظآظآظآظآظآظغ (ظقظقظقظآظآظآظظ)
    if (empty($where_conditions)) {
        $where_clause = "1=1";
        $params = [];
    } else {
        $where_clause = implode(" OR ", $where_conditions);
    }

    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE ($where_clause) AND is_read = 0");
    if ($stmt->execute($params)) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
} elseif ($action === 'mark_single_notif_read') {
    $notif_id = $_GET['notif_id'] ?? null;
    if (!$notif_id) {
        echo json_encode(['status' => 'error', 'message' => 'Missing notification ID']);
        exit();
    }

    // ظآظقظغظآ­ظقظق ظقظق  ظآظق  ظآظقظآظآظآظآظآ ظقظثظآ،ظق ظقظقظآظآ ظآظقظقظآظغظآظآظق ظقظآظق ظغظآ­ظآظعظآظق ظئظقظقظآظثظظ
    $agent_id = $_SESSION['agent_id'] ?? null;
    $branch_id = $_SESSION['branch_id'] ?? null;
    $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
    $user_role = $_SESSION['role'] ?? null;

    $where_conditions = [];
    $params = [$notif_id]; // notif_id ظآظثظقظآظق

    if ($user_id) {
        $where_conditions[] = "user_id = ?";
        $params[] = $user_id;
    }

    if ($user_role) {
        $where_conditions[] = "role_id = ?";
        $params[] = $user_role;
    }

    if ($agent_id) {
        $where_conditions[] = "agent_id = ?";
        $params[] = $agent_id;
    }

    if ($branch_id) {
        $where_conditions[] = "branch_id = ?";
        $params[] = $branch_id;
    }

    if (empty($where_conditions)) {
        $where_clause = "1=1";
        $params = [$notif_id];
    } else {
        $where_clause = implode(" OR ", $where_conditions);
    }

    // ظآظقظغظآ­ظقظق ظقظق  ظثظآ،ظثظآ ظآظقظآظآظآظآظآ
    $check_stmt = $pdo->prepare("SELECT id FROM notifications WHERE id = ? AND ($where_clause)");
    $check_stmt->execute($params);
    if ($check_stmt->fetch()) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND is_read = 0");
        if ($stmt->execute([$notif_id])) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Notification not found or not accessible']);
    }
} elseif ($action === 'get_profession_requirements') {
    $profession_id = $_GET['profession_id'] ?? null;
    if (!$profession_id) {
        echo json_encode(['requirements' => [], 'rules' => null]);
        exit();
    }

    // ظآ،ظقظآ ظآظقظقظغظآظقظآظآظغ
    $stmt_req = $pdo->prepare("SELECT id, requirement_name FROM profession_requirements WHERE profession_id = ?");
    $stmt_req->execute([$profession_id]);
    $requirements = $stmt_req->fetchAll(PDO::FETCH_ASSOC);

    // ظآ،ظقظآ ظآظقظقظثظآظآظآ (ظآظقظآظقظآظإ ظآظقظآظقظآظآ­ظعظآ)
    $stmt_rules = $pdo->prepare("SELECT * FROM work_visa_rules WHERE profession_id = ?");
    $stmt_rules->execute([$profession_id]);
    $rules = $stmt_rules->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'requirements' => $requirements,
        'rules' => $rules
    ]);
    exit();
} elseif ($action === 'get_service_price') {
    $service_id = $_GET['service_id'] ?? null;
    $branch_id = !empty($_GET['branch_id']) ? $_GET['branch_id'] : null;
    $agent_id = !empty($_GET['agent_id']) ? $_GET['agent_id'] : null;

    if (!$service_id) {
        echo json_encode(['status' => 'error', 'message' => 'Service ID is required']);
        exit();
    }

    try {
        $target = normalize_service_target($pdo, $agent_id, $branch_id);
        $price = get_service_price_config($pdo, $service_id, $target['agent_id'], $target['branch_id']);

        if ($price) {
            echo json_encode([
                'status' => 'success',
                'purchase_price' => (float) $price['purchase_price'],
                'sale_price' => (float) $price['sale_price'],
                'default_sale_price' => (float) $price['sale_price'],
                'agent_price' => (float) ($price['agent_price'] ?? 0),
                'branch_price' => (float) ($price['branch_price'] ?? 0),
                'currency_id' => $price['currency_id'],
                'currency_name' => $price['currency_name'] ?? '',
                'currency_symbol' => $price['currency_symbol'] ?? '',
                'target_type' => $price['target_type'],
                'user_role' => $_SESSION['role'] ?? ''
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ظقظق ظعظغظق ظآظقظآظآظثظآ ظآظقظق ظغظآظآظعظآ ظعظآظآظق ظقظقظآظآظقظآ']);
        }
    } catch (Exception $e) {
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'ظ­ظظ ظظظ ظظظعع عع ظععظظع']);
    }
    exit();
}

