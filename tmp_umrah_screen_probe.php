<?php
require __DIR__ . '/includes/db.php';

$name = $argv[1] ?? '';
if ($name === '') {
    echo json_encode(['success' => false, 'message' => 'name required'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(1);
}

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.full_name,
        p.transaction_type,
        p.service_type,
        p.sales_invoice_id,
        p.purchase_invoice_id,
        s.invoice_number AS sales_invoice_number,
        s.invoice_status AS sales_status,
        s.delivery_type AS sales_delivery_type,
        pur.invoice_number AS purchase_invoice_number,
        pur.invoice_status AS purchase_status
    FROM passports p
    LEFT JOIN invoices s ON s.id = p.sales_invoice_id
    LEFT JOIN invoices pur ON pur.id = p.purchase_invoice_id
    WHERE p.full_name = ?
    ORDER BY p.id DESC
    LIMIT 1
");
$stmt->execute([$name]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => (bool)$row,
    'record' => $row ?: null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
