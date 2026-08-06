<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/FinanceService.php';

$facadeSource = file_get_contents(__DIR__ . '/../core/FinanceService.php');
if ($facadeSource === false) {
    throw new RuntimeException('Unable to read FinanceService facade');
}
if (str_contains($facadeSource, 'new \\Core\\Finance\\LegacyFinanceGateway')
    || str_contains($facadeSource, 'new LegacyFinanceGateway')) {
    throw new RuntimeException('Facade still constructs LegacyFinanceGateway');
}

$legacy = new ReflectionClass('LegacyFinanceService');
$facade = new ReflectionClass('FinanceService');
foreach ($legacy->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    if ($method->getName() === '__construct') {
        continue;
    }
    if (!$facade->hasMethod($method->getName())) {
        throw new RuntimeException('Facade method missing: ' . $method->getName());
    }
    $facadeMethod = $facade->getMethod($method->getName());
    if ($method->getNumberOfRequiredParameters() !== $facadeMethod->getNumberOfRequiredParameters()
        || $method->getNumberOfParameters() !== $facadeMethod->getNumberOfParameters()) {
        throw new RuntimeException('Facade signature mismatch: ' . $method->getName());
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE facade_acceptance (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
$service = new FinanceService($pdo, 1);
$normalized = $service->normalizeFinancialPayload([
    'sale_total_amount' => 100,
    'discount_amount' => 10,
    'paid_amount' => 25,
]);
if ($normalized['net_amount'] !== 90.0 || $normalized['remaining_amount'] !== 65.0) {
    throw new RuntimeException('Facade normalization result mismatch');
}
$service->executeAtomically(static function () use ($pdo): void {
    $pdo->exec("INSERT INTO facade_acceptance (value) VALUES ('ok')");
});
if ((int)$pdo->query('SELECT COUNT(*) FROM facade_acceptance')->fetchColumn() !== 1) {
    throw new RuntimeException('Facade atomic operation did not commit');
}

echo "Finance phase 5 facade acceptance test: PASS\n";
