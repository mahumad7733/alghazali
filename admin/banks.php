<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';

ob_start();
renderAdminPage(requireAdminPage('banks'));
$html = (string) ob_get_clean();
echo $html;
