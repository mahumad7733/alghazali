<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/FinanceService.php';

$serviceDefinitions = [
    'Core\\Finance\\InvoiceService' => [
        'createInvoiceDraft',
        'postInvoice',
        'recalculateInvoicePaymentStatus',
    ],
    'Core\\Finance\\ReceiptService' => [
        'createReceiptVoucherDraft',
        'allocatePayment',
        'postReceiptVoucher',
        'receiveInvoicePayment',
    ],
    'Core\\Finance\\PaymentService' => [
        'createPaymentVoucherDraft',
        'postPaymentVoucher',
    ],
    'Core\\Finance\\ExpenseService' => [
        'createExpenseVoucherDraft',
        'postExpenseVoucher',
        'processExpenseApproval',
    ],
    'Core\\Finance\\JournalService' => [
        'processServiceOperation',
    ],
    'Core\\Finance\\BalanceService' => [
        'getOrCreateDefaultCashCustomer',
    ],
];

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$finance = new FinanceService($pdo, 1);
$facadeReflection = new ReflectionClass($finance);

foreach ($serviceDefinitions as $className => $methods) {
    if (!class_exists($className)) {
        throw new RuntimeException('Missing service class: ' . $className);
    }

    $propertyName = lcfirst((new ReflectionClass($className))->getShortName());
    if (!$facadeReflection->hasProperty($propertyName)) {
        throw new RuntimeException('Facade is missing service dependency: ' . $propertyName);
    }

    $property = $facadeReflection->getProperty($propertyName);
    $property->setAccessible(true);
    if (!$property->getValue($finance) instanceof $className) {
        throw new RuntimeException('Facade dependency has wrong type: ' . $propertyName);
    }

    $serviceReflection = new ReflectionClass($className);
    foreach ($methods as $method) {
        if (!$serviceReflection->hasMethod($method) || !$serviceReflection->getMethod($method)->isPublic()) {
            throw new RuntimeException("Missing public {$method} on {$className}");
        }
    }
}

$serviceSource = '';
foreach (glob(__DIR__ . '/../core/Finance/*Service.php') ?: [] as $file) {
    $contents = file_get_contents($file);
    if ($contents !== false) {
        $serviceSource .= $contents;
    }
}
if (str_contains($serviceSource, 'LegacyFinanceService') || str_contains($serviceSource, 'LegacyFinanceGateway')) {
    throw new RuntimeException('Migrated services still reference the legacy implementation');
}

echo "Finance phase 6 service migration acceptance test: PASS\n";
