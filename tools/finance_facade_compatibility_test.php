<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/FinanceService.php';
require_once __DIR__ . '/../core/LegacyFinanceService.php';

$legacy = new ReflectionClass('LegacyFinanceService');
$facade = new ReflectionClass('FinanceService');
$legacyMethods = [];
$facadeMethods = [];
foreach ($legacy->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    if ($method->getName() !== '__construct') {
        $legacyMethods[$method->getName()] = $method;
    }
}
foreach ($facade->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    if ($method->getName() !== '__construct') {
        $facadeMethods[$method->getName()] = $method;
    }
}
$missing = array_values(array_diff(array_keys($legacyMethods), array_keys($facadeMethods)));
if ($missing) {
    throw new RuntimeException('Facade is missing legacy methods: ' . implode(', ', $missing));
}
foreach ($legacyMethods as $name => $legacyMethod) {
    $facadeMethod = $facadeMethods[$name];
    if ($legacyMethod->getNumberOfRequiredParameters() !== $facadeMethod->getNumberOfRequiredParameters()
        || $legacyMethod->getNumberOfParameters() !== $facadeMethod->getNumberOfParameters()) {
        throw new RuntimeException("Facade signature mismatch for {$name}");
    }
}
echo "Finance facade compatibility test: PASS\n";
