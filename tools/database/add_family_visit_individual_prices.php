<?php
/**
 * إضافة أعمدة أسعار الأفراد إلى family_visit_individuals.
 * الترحيل idempotent ويمكن تشغيله أكثر من مرة دون تكرار الأعمدة.
 */
require_once __DIR__ . '/../../includes/db.php';

$table = 'family_visit_individuals';
$definitions = [
    'agent_price' => "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER age",
    'branch_price' => "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER agent_price",
    'sale_price' => "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER branch_price",
];

$existing = [];
$stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
    $existing[$column['Field']] = true;
}

$added = [];
foreach ($definitions as $column => $definition) {
    if (isset($existing[$column])) {
        continue;
    }
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    $added[] = $column;
}

$result = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
$columns = array_map(static fn(array $row): string => $row['Field'], $result);
echo json_encode([
    'status' => 'success',
    'database' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
    'table' => $table,
    'added' => $added,
    'price_columns_present' => array_values(array_intersect(['agent_price', 'branch_price', 'sale_price'], $columns)),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
