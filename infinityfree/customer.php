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
  <title>منصة حجوزات الباصات | احجز رحلتك الآن</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css">
  <link rel="stylesheet" href="assets/css/app.css?v=20260825-06">
  <link rel="stylesheet" href="assets/css/public-template.css?v=20260825-02">
</head>
<body class="customer-page">
  <main id="app" data-role="customer"></main>
  <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js" defer></script>
  <script src="assets/js/trip-sort.js?v=20260824-1" defer></script>
  <script src="assets/js/public-template.js?v=20260825-02" defer></script>
  <script src="assets/js/app.js?v=20260825-06" defer></script>
</body>
</html>
