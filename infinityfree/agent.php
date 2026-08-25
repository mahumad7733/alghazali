<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
use App\Includes\Security;
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= Security::escape(Security::csrfToken()) ?>">
  <title>لوحة الوكيل | منصة حجوزات الباصات</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css?v=20260825-05">
</head>
<body class="dashboard-page">
  <main id="app" data-role="agent"></main>
  <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js" defer></script>
  <script src="assets/js/app.js?v=20260825-05" defer></script>
</body>
</html>
