<?php
declare(strict_types=1);

use App\Classes\Database;

/** @var array<string, mixed> $config */
$config = require __DIR__ . '/config.php';

return new Database($config['database']);
