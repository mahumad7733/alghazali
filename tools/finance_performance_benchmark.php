<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/FinanceService.php';
require_once __DIR__ . '/../core/LegacyFinanceService.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$legacy = new LegacyFinanceService($pdo, 1);
$facade = new FinanceService($pdo, 1);
$iterations = 1000;
$payload = ['sale_total_amount' => 100, 'discount_amount' => 10, 'paid_amount' => 25];

$measure = static function (callable $callback, int $count): float {
    $start = hrtime(true);
    for ($i = 0; $i < $count; $i++) {
        $callback();
    }
    return (hrtime(true) - $start) / 1_000_000;
};

$legacyMs = $measure(static fn() => $legacy->normalizeFinancialPayload($payload), $iterations);
$facadeMs = $measure(static fn() => $facade->normalizeFinancialPayload($payload), $iterations);

printf("iterations=%d\nlegacy_normalize_ms=%.3f\nfacade_normalize_ms=%.3f\n", $iterations, $legacyMs, $facadeMs);
