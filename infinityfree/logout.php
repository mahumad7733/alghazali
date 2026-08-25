<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

use App\Includes\Auth;

(new Auth($database))->logout();
header('Cache-Control: no-store, private');
header('Location: login.php', true, 302);
exit;
