<?php
// Compatibility entry point: System Administration uses the existing admin login.
$adminLogin = dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/system_admin/login.php');
header('Location: ' . rtrim($adminLogin, '/\\') . '/../login.php');
exit;
