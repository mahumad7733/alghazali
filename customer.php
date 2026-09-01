<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
use App\Includes\Security;
$languageService = $GLOBALS['languageService'];
$publicPage = (string) ($_GET['page'] ?? '');
$publicPage = in_array($publicPage, ['about', 'contact', 'privacy', 'developers', 'bookings'], true) ? $publicPage : '';
$pageTitle = ['about' => 'من نحن | منصة رحلة', 'contact' => 'اتصل بنا | منصة رحلة', 'privacy' => 'السياسة والخصوصية | منصة رحلة', 'developers' => 'مركز المطورين وواجهة API | منصة رحلة', 'bookings' => 'حجوزاتي | منصة رحلة'][$publicPage] ?? 'منصة حجوزات الباصات | احجز رحلتك الآن';
$siteSettings = (new \App\Includes\SiteSettingsService($database))->publicSettings((string) ($languageService->context()['code'] ?? 'ar'));
$languageContext = $languageService->context();
$languageCode = (string) ($languageContext['code'] ?? 'ar');
$languageDirection = (string) ($languageContext['direction'] ?? 'rtl');
$bootstrapCss = (string) ($languageContext['bootstrap_css'] ?? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css');
$iconPath = (string) ($siteSettings['icon_path'] ?? '');
$iconHref = preg_match('#^uploads/[a-z0-9_/-]+\\.(?:jpg|jpeg|png|webp)$#i', $iconPath) === 1 ? Security::escape($iconPath) . '?v=' . rawurlencode((string) time()) : '';
?><!doctype html>
<html lang="<?= Security::escape($languageCode) ?>" dir="<?= Security::escape($languageDirection) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= Security::escape(Security::csrfToken()) ?>">
  <title><?= Security::escape($pageTitle) ?></title>
  <?php if ($iconHref !== ''): ?><link rel="icon" type="image/png" href="<?= $iconHref ?>"><?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= Security::escape($bootstrapCss) ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=20260828-69">
  <link rel="stylesheet" href="assets/css/public-template.css?v=20260828-53">
</head>
<body class="customer-page" data-language-code="<?= Security::escape($languageCode) ?>" data-language-direction="<?= Security::escape($languageDirection) ?>">
  <main id="app" data-role="customer" data-public-page="<?= Security::escape($publicPage) ?>"><section class="card login-gate" aria-live="polite"><h1>منصة رحلتي</h1><p class="muted">جاري تحميل واجهة الحجز...</p></section></main>
  <script src="assets/js/qrcode.min.js?v=1" defer></script>
  <script src="assets/js/trip-sort.js?v=20260824-1" defer></script>
  <script src="assets/js/developer-center.js?v=20260828-1" defer></script>
  <script src="assets/js/public-template.js?v=20260828-48" defer></script>
  <script src="assets/js/i18n.js?v=20260901-account" defer></script>
  <script src="assets/js/app.js?v=20260828-103" defer></script>
</body>
</html>
