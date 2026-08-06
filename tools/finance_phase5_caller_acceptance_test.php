<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    throw new RuntimeException('Unable to resolve application root');
}

$applicationRoots = [
    $root . DIRECTORY_SEPARATOR . 'admin',
    $root . DIRECTORY_SEPARATOR . 'api',
    $root . DIRECTORY_SEPARATOR . 'core',
    $root . DIRECTORY_SEPARATOR . 'includes',
];

$violations = [];
foreach ($applicationRoots as $applicationRoot) {
    if (!is_dir($applicationRoot)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($applicationRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $relativePath = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
        if (in_array(str_replace('\\', '/', $relativePath), [
            'core/LegacyFinanceService.php',
            'core/Finance/LegacyFinanceGateway.php',
            'core/Finance/FinancePostingAdapter.php',
            'includes/accounting_functions.php',
        ], true)) {
            continue;
        }

        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException('Unable to read ' . $relativePath);
        }

        if (preg_match('/\bnew\s+(?:LegacyFinanceService|LegacyFinanceGateway)\b/', $source)) {
            $violations[] = $relativePath . ': direct legacy service construction';
        }
        if (preg_match('/(?:require|require_once|include|include_once)\s*\(?[^;]*(?:LegacyFinanceService|LegacyFinanceGateway)/', $source)) {
            $violations[] = $relativePath . ': direct legacy service loading';
        }
        if (preg_match('/\bphp_post_(?:invoice|receipt_voucher|payment_voucher)\s*\(/', $source)
            || preg_match('/CALL\s+sp_create_(?:receipt_voucher|payment_voucher)\b/i', $source)
            || preg_match('/\bphp_recalculate_invoice/', $source)) {
            $violations[] = $relativePath . ': direct financial posting helper call';
        }
    }
}

$facade = file_get_contents($root . DIRECTORY_SEPARATOR . 'core/FinanceService.php');
if ($facade === false || preg_match('/LegacyFinance(Service|Gateway)/', $facade)) {
    $violations[] = 'core/FinanceService.php: facade references legacy implementation';
}

if ($violations !== []) {
    throw new RuntimeException("Phase 5 caller acceptance failed:\n- " . implode("\n- ", $violations));
}

echo "Finance phase 5 caller acceptance test: PASS\n";
