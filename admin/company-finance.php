<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';

$context = requireAdminPage('company_finance');
renderAdminPage($context);
