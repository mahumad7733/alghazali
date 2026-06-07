<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/accounting_functions.php';
require_once '../includes/ServiceFinancialEngine.php';

// Verify CSRF (optional but recommended)
function verify_request() {
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        return false;
    }
    // You can add CSRF check here if needed
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_request()) {
    $action = $_POST['action'] ?? '';

    // Save Umrah Host
    if ($action === 'save_host') {
        try {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
            $host_name = $_POST['host_name'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $address = $_POST['address'] ?? '';
            $national_address = $_POST['national_address'] ?? '';

            if (empty($host_name)) {
                echo json_encode(['status' => 'error', 'message' => 'اسم المستضيف مطلوب']);
                exit();
            }

            if ($id) {
                $stmt = $pdo->prepare("UPDATE umrah_hosts SET host_name=?, phone=?, address=?, national_address=? WHERE id=?");
                $stmt->execute([$host_name, $phone, $address, $national_address, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO umrah_hosts (host_name, phone, address, national_address) VALUES (?, ?, ?, ?)");
                $stmt->execute([$host_name, $phone, $address, $national_address]);
            }

            echo json_encode(['status' => 'success', 'message' => 'تم الحفظ بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    // Delete Umrah Host
    if ($action === 'delete_host') {
        try {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : 0;
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'معرف المستضيف مطلوب']);
                exit();
            }
            $stmt = $pdo->prepare("DELETE FROM umrah_hosts WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'تم الحذف بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    // Save Umrah Guarantor
    if ($action === 'save_guarantor') {
        try {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
            $guarantor_name = $_POST['guarantor_name'] ?? '';
            $identity_type = $_POST['identity_type'] ?? '';
            $identity_number = $_POST['identity_number'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $guarantor_type = $_POST['guarantor_type'] ?? 'individual';
            $address = $_POST['address'] ?? '';

            if (empty($guarantor_name)) {
                echo json_encode(['status' => 'error', 'message' => 'اسم الضامن مطلوب']);
                exit();
            }

            if ($id) {
                $stmt = $pdo->prepare("UPDATE umrah_guarantors SET guarantor_name=?, identity_type=?, identity_number=?, phone=?, guarantor_type=?, address=? WHERE id=?");
                $stmt->execute([$guarantor_name, $identity_type, $identity_number, $phone, $guarantor_type, $address, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO umrah_guarantors (guarantor_name, identity_type, identity_number, phone, guarantor_type, address) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$guarantor_name, $identity_type, $identity_number, $phone, $guarantor_type, $address]);
            }

            echo json_encode(['status' => 'success', 'message' => 'تم الحفظ بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    // Delete Umrah Guarantor
    if ($action === 'delete_guarantor') {
        try {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : 0;
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'معرف الضامن مطلوب']);
                exit();
            }
            $stmt = $pdo->prepare("DELETE FROM umrah_guarantors WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'تم الحذف بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    // Add Umrah Transaction
    if ($action === 'add_umrah') {
        try {
            $pdo->beginTransaction();

            // Validate required fields
            $full_name = $_POST['full_name'] ?? '';
            $passport_number = $_POST['passport_number'] ?? '';
            if (empty($full_name) || empty($passport_number)) {
                throw new Exception('الاسم الكامل ورقم الجواز مطلوبين');
            }

            // Get settings
            $settings = getSettings($pdo);

            // Get current user data
            $stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt_user->execute([$_SESSION['admin_id']]);
            $currentUser = $stmt_user->fetch(PDO::FETCH_ASSOC);

            // Get default status
            $default_status_id = $pdo->query("SELECT id FROM statuses WHERE status_name = 'معاملة جديدة' LIMIT 1")->fetchColumn();
            if (!$default_status_id) {
                $default_status_id = $pdo->query("SELECT id FROM statuses LIMIT 1")->fetchColumn();
            }

            // Extract customer_id and agent_id
            $customer_id = null;
            $agent_id = null;
            $account_id = $_POST['account_id'] ?? null;
            $delivery_type = $_POST['delivery_type'] ?? $_POST['payment_type'] ?? 'cash';
            if ($delivery_type === 'credit') {
                $customer_id = $_POST['customer_id'] ?? null;
                if (!$customer_id && isset($_POST['account_select'])) {
                    // Try to get customer_id from account_select selected option's data-customer-id
                    $customer_id = $_POST['customer_id_hidden'] ?? null;
                }
            } elseif ($delivery_type === 'agent') {
                $agent_id = $_POST['agent_id'] ?? null;
                if (!$agent_id && isset($_POST['account_select'])) {
                    $agent_id = $_POST['agent_id_hidden'] ?? null;
                }
            }

            // Insert into passports
            $insert_passport = $pdo->prepare("
                INSERT INTO passports (
                    full_name, 
                    full_name_en, 
                    passport_number, 
                    passport_issue_date, 
                    passport_expiry_date, 
                    nationality, 
                    gender, 
                    date_of_birth, 
                    phone_number, 
                    operation_date, 
                    service_id, 
                    transaction_type, 
                    service_type, 
                    status_id, 
                    workflow_id, 
                    status_changed_by, 
                    created_by, 
                    branch_id, 
                    agent_id, 
                    customer_id, 
                    supplier_id, 
                    host_id, 
                    guarantor_id, 
                    is_outside_ksa, 
                    visa_number, 
                    visa_issue_date, 
                    visa_expiry_date, 
                    description, 
                    notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $insert_passport->execute([
                $full_name,
                $_POST['full_name_en'] ?? '',
                $passport_number,
                $_POST['passport_issue_date'] ?? null,
                $_POST['passport_expiry_date'] ?? null,
                $_POST['nationality'] ?? '',
                $_POST['gender'] ?? null,
                $_POST['date_of_birth'] ?? null,
                $_POST['phone_number'] ?? '',
                $_POST['invoice_date'] ?? date('Y-m-d'),
                $_POST['service_id'] ?? null,
                'visa',
                'umrah',
                $default_status_id,
                isset($settings['umrah_workflow_enabled']) && $settings['umrah_workflow_enabled'] ? get_workflow_id_by_transaction_type($pdo, 'umrah') : null,
                $_SESSION['admin_id'],
                $_SESSION['admin_id'],
                $_SESSION['branch_id'] ?? null,
                $agent_id,
                $customer_id,
                $_POST['supplier_id'] ?? null,
                $_POST['host_id'] ?? null,
                $_POST['guarantor_id'] ?? null,
                $_POST['is_outside_ksa'] ?? 0,
                $_POST['visa_number'] ?? '',
                $_POST['visa_issue_date'] ?? null,
                $_POST['visa_expiry_date'] ?? null,
                $_POST['description'] ?? '',
                $_POST['notes'] ?? ''
            ]);

            $passport_id = $pdo->lastInsertId();

            // Handle Financial Engine
            $service_id = $_POST['service_id'] ?? null;
            if ($service_id) {
                $financialEngine = new ServiceFinancialEngine($pdo, $_SESSION['admin_id']);
                $financeResults = $financialEngine->processServiceFinance([
                    'service_type'    => 'umrah',
                    'source_id'       => $passport_id,
                    'source_number'   => 'UM-'.$passport_id,
                    'branch_id'       => $_SESSION['branch_id'] ?? null,
                    'customer_id'     => $customer_id,
                    'agent_id'        => $agent_id,
                    'supplier_id'     => $_POST['supplier_id'] ?? null,
                    'sale_price'      => $_POST['total_amount'] ?? 0,
                    'discount'        => $_POST['discount'] ?? 0,
                    'purchase_price'  => $_POST['cost_amount'] ?? 0,
                    'sale_currency_id'=> $_POST['sale_currency_id'] ?? $_POST['currency_id'] ?? 1,
                    'pur_currency_id' => $_POST['main_currency_id'] ?? $_POST['currency_id'] ?? 1,
                    'exchange_rate'   => $_POST['invoice_exchange_rate'] ?? 1,
                    'amount_received' => $_POST['amount_received'] ?? 0,
                    'payment_account_id' => $account_id,
                    'delivery_type'   => $delivery_type,
                    'description'     => 'معاملة عمرة رقم: ' . $passport_id . ' - ' . $full_name,
                    'operation_date'  => $_POST['invoice_date'] ?? date('Y-m-d')
                ]);

                // Update passports with invoice ids
                $update_passport = $pdo->prepare("
                    UPDATE passports 
                    SET sales_invoice_id = ?, purchase_invoice_id = ?, auto_invoice_generated = 1 
                    WHERE id = ?
                ");
                $update_passport->execute([
                    $financeResults['sales_invoice_id'],
                    $financeResults['purchase_invoice_id'] ?? null,
                    $passport_id
                ]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'تم حفظ المعاملة بنجاح']);
            exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }
}

echo json_encode(['success' => false, 'message' => 'طلب غير صالح']);
?>