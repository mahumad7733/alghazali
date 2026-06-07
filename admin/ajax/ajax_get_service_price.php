<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

$service_id = isset($_GET['service_id']) ? intval($_GET['service_id']) : 0;
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : null;
$agent_id = isset($_GET['agent_id']) ? intval($_GET['agent_id']) : null;
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : null;
$supplier_id = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : null;

// If values are 0 (from empty string), set to null
if ($branch_id === 0) $branch_id = null;
if ($agent_id === 0) $agent_id = null;
if ($customer_id === 0) $customer_id = null;
if ($supplier_id === 0) $supplier_id = null;

if (!$service_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid service ID']);
    exit();
}

// Function to get service price
function get_service_price($pdo, $service_id, $branch_id = null, $agent_id = null, $customer_id = null, $supplier_id = null) {
    // Priority order:
    // 1. Supplier-specific (if supplier_id provided)
    // 2. Customer-specific (if customer_id provided)
    // 3. Agent-specific
    // 4. Branch-specific
    // 5. Global default
    
    $prices = [];
    
    // Check supplier-specific first (for cost price)
    if ($supplier_id) {
        $stmt = $pdo->prepare("SELECT * FROM service_prices WHERE service_id = ? AND supplier_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$service_id, $supplier_id]);
        $supplier_price = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($supplier_price) {
            $prices['supplier'] = $supplier_price;
        }
    }
    
    // Check customer-specific (for sale price)
    if ($customer_id) {
        $stmt = $pdo->prepare("SELECT * FROM service_prices WHERE service_id = ? AND customer_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$service_id, $customer_id]);
        $customer_price = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($customer_price) {
            $prices['customer'] = $customer_price;
        }
    }
    
    // Check agent-specific
    if ($agent_id) {
        $stmt = $pdo->prepare("SELECT * FROM service_prices WHERE service_id = ? AND agent_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$service_id, $agent_id]);
        $agent_price = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($agent_price) {
            $prices['agent'] = $agent_price;
        }
    }
    
    // Check branch-specific
    if ($branch_id) {
        $stmt = $pdo->prepare("SELECT * FROM service_prices WHERE service_id = ? AND branch_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$service_id, $branch_id]);
        $branch_price = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($branch_price) {
            $prices['branch'] = $branch_price;
        }
    }
    
    // Get global default - handle both NULL and empty string
    $stmt = $pdo->prepare("SELECT * FROM service_prices WHERE service_id = ? AND (branch_id IS NULL OR branch_id = '') AND (agent_id IS NULL OR agent_id = '') AND (customer_id IS NULL OR customer_id = '') AND (supplier_id IS NULL OR supplier_id = '') AND status = 'active' LIMIT 1");
    $stmt->execute([$service_id]);
    $global_price = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($global_price) {
        $prices['global'] = $global_price;
    }
    
    // Determine which price to use
    $sale_price = 0;
    $purchase_price = 0;
    $currency_id = null;
    
    // Use customer price for sale if available
    if (isset($prices['customer'])) {
        $sale_price = $prices['customer']['default_sale_price'];
        $currency_id = $prices['customer']['currency_id'];
    } elseif (isset($prices['agent'])) {
        $sale_price = $prices['agent']['default_sale_price'];
        $currency_id = $prices['agent']['currency_id'];
    } elseif (isset($prices['branch'])) {
        $sale_price = $prices['branch']['default_sale_price'];
        $currency_id = $prices['branch']['currency_id'];
    } elseif (isset($prices['global'])) {
        $sale_price = $prices['global']['default_sale_price'];
        $currency_id = $prices['global']['currency_id'];
    }
    
    // Use supplier price for cost if available
    if (isset($prices['supplier'])) {
        $purchase_price = $prices['supplier']['agent_price'] ?: $prices['supplier']['branch_price'];
        if (!$currency_id) {
            $currency_id = $prices['supplier']['currency_id'];
        }
    } elseif (isset($prices['agent'])) {
        $purchase_price = $prices['agent']['agent_price'] ?: $prices['agent']['branch_price'];
    } elseif (isset($prices['branch'])) {
        $purchase_price = $prices['branch']['agent_price'] ?: $prices['branch']['branch_price'];
    } elseif (isset($prices['global'])) {
        $purchase_price = $prices['global']['agent_price'] ?: $prices['global']['branch_price'];
    }
    
    return [
        'sale_price' => $sale_price,
        'purchase_price' => $purchase_price,
        'currency_id' => $currency_id,
        'debug' => [
            'service_id' => $service_id,
            'branch_id' => $branch_id,
            'agent_id' => $agent_id,
            'customer_id' => $customer_id,
            'supplier_id' => $supplier_id,
            'prices' => $prices,
            'global_price' => $global_price
        ]
    ];
}

$result = get_service_price($pdo, $service_id, $branch_id, $agent_id, $customer_id, $supplier_id);
$result['success'] = true;

echo json_encode($result);
exit();
