<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // لا تظهر الأخطاء في المتصفح
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

$response = [
    'success' => false,
    'currency_id' => '',
    'purchase_price' => 0,
    'sale_price' => 0,
    'supplier_id' => '',
    'customer_id' => '',
    'agent_id' => '',
    'message' => ''
];

try {
    if (isset($_GET['service_name']) || isset($_GET['service_id'])) {
        $service_id = $_GET['service_id'] ?? null;
        $service_name = $_GET['service_name'] ?? null;

        if (!$service_id && $service_name) {
            $stmt_srv = $pdo->prepare("SELECT id FROM services WHERE service_name = ? LIMIT 1");
            $stmt_srv->execute([$service_name]);
            $service_id = $stmt_srv->fetchColumn();
        }

        if (!$service_id) {
            $response['message'] = 'Service not found.';
            echo json_encode($response);
            exit;
        }

        $agent_id = !empty($_GET['agent_id']) ? $_GET['agent_id'] : null;
        $branch_id = !empty($_GET['branch_id']) ? $_GET['branch_id'] : null;
        $customer_id = !empty($_GET['customer_id']) ? $_GET['customer_id'] : null;
        $supplier_id = !empty($_GET['supplier_id']) ? $_GET['supplier_id'] : null;

        // تحقق من وجود الدالة قبل استدعائها
        if (function_exists('get_service_price_config')) {
            $price_data = get_service_price_config($pdo, $service_id, $agent_id, $branch_id, $customer_id, $supplier_id);

            if ($price_data) {
                $response['success'] = true;
                $response['currency_id'] = $price_data['currency_id'] ?? '';
                $response['purchase_price'] = $price_data['purchase_price'] ?? 0;
                $response['sale_price'] = $price_data['sale_price'] ?? 0;
                $response['supplier_id'] = $price_data['supplier_id'] ?? '';
                $response['customer_id'] = $price_data['customer_id'] ?? '';
                $response['agent_id'] = $price_data['agent_id'] ?? '';
            } else {
                // محاولة جلب السعر من جدول services مباشرة
                $stmt_srv = $pdo->prepare("SELECT price, currency_id FROM services WHERE id = ?");
                $stmt_srv->execute([$service_id]);
                $srv_data = $stmt_srv->fetch(PDO::FETCH_ASSOC);

                if ($srv_data) {
                    $response['success'] = true;
                    $response['currency_id'] = $srv_data['currency_id'];
                    $response['sale_price'] = $srv_data['price'];
                    $response['purchase_price'] = 0;
                } else {
                    $response['message'] = 'No price found.';
                }
            }
        } else {
            $response['message'] = 'Function get_service_price_config not found.';
        }
    } else {
        $response['message'] = 'Missing parameters.';
    }
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
?>

