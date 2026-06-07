<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// This page is deprecated and replaced by unified management in roles.php and users.php
header("Location: roles.php?info=unified_financial_settings");
exit();
?>
