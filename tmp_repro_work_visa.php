<?php
session_id('debug-work-visa');
session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['role_id'] = 1;
$_SESSION['branch_id'] = 1;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/ghazali/admin/work_visa.php';
$_SERVER['PHP_SELF'] = '/ghazali/admin/work_visa.php';
$_GET = [];
chdir('c:/xampp/htdocs/ghazali/admin');
include 'work_visa.php';
