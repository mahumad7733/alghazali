<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
use App\Includes\Security;
$languageContext = $languageService->context();
$languageCode = (string) ($languageContext['code'] ?? 'ar');
$languageDirection = (string) ($languageContext['direction'] ?? 'rtl');
$bootstrapCss = (string) ($languageContext['bootstrap_css'] ?? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css');
?><!doctype html>
<html lang="<?= Security::escape($languageCode) ?>" dir="<?= Security::escape($languageDirection) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= Security::escape(Security::csrfToken()) ?>">
  <title>لوحة الوكيل | منصة حجوزات الباصات</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= Security::escape($bootstrapCss) ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=20260828-69">
</head>
<body class="dashboard-page" data-api-base="api/v1" data-language-code="<?= Security::escape($languageCode) ?>" data-language-direction="<?= Security::escape($languageDirection) ?>">
  <main id="app" data-role="agent"></main>
  <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js" defer></script>
  <script src="assets/js/i18n.js?v=20260828-2" defer></script>
  <script src="assets/js/app.js?v=20260828-103" defer></script>
</body>
</html>
