<?php
declare(strict_types=1);

// موزع محدود لوحدات الواجهة المنفصلة؛ لا يحتوي كود الشاشات نفسها.
$assets = [
    'subroutes-js' => ['../assets/js/admin-subroutes.js', 'application/javascript; charset=UTF-8'],
    'stations-js' => ['../assets/js/admin-stations.js', 'application/javascript; charset=UTF-8'],
    'main-routes-js' => ['../assets/js/admin-main-routes.js', 'application/javascript; charset=UTF-8'],
    'main-routes-css' => ['../assets/css/admin-main-routes.css', 'text/css; charset=UTF-8'],
];
$asset = (string) ($_GET['asset'] ?? '');
if (!isset($assets[$asset])) {
    http_response_code(404);
    exit('الأصل المطلوب غير موجود.');
}
[$relativePath, $contentType] = $assets[$asset];
$file = realpath(__DIR__ . '/' . $relativePath);
$projectRoot = realpath(__DIR__ . '/..');
if ($file === false || $projectRoot === false || !str_starts_with($file, $projectRoot . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    exit('الأصل المطلوب غير متاح.');
}
header('Content-Type: ' . $contentType);
header('Cache-Control: public, max-age=300');
readfile($file);
