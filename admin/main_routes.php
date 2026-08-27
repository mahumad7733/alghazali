<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
ob_start();
renderAdminPage(requireAdminPage('main_routes'));
$html = (string) ob_get_clean();
$html = str_replace('</head>', '<link rel="stylesheet" href="assets.php?asset=main-routes-css&v=20260826-14"></head>', $html);
$html = str_replace('</body>', '<script src="assets.php?asset=main-routes-js&v=20260826-17" defer></script></body>', $html);
echo $html;
