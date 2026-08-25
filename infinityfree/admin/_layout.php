<?php
declare(strict_types=1);

use App\Includes\Auth;
use App\Includes\Security;

require_once __DIR__ . '/../includes/bootstrap.php';

/** @return array<string, array{title:string, page:string, permissions:list<string>, any:bool}> */
function adminPages(): array
{
    return [
        'dashboard' => ['title' => 'لوحة الإدارة', 'page' => 'home', 'permissions' => [], 'any' => true],
        'countries' => ['title' => 'الدول', 'page' => 'countries', 'permissions' => ['manage_countries'], 'any' => false],
        'currencies' => ['title' => 'العملات', 'page' => 'currencies', 'permissions' => ['manage_settings'], 'any' => false],
        'exchange_rates' => ['title' => 'أسعار الصرف', 'page' => 'exchange_rates', 'permissions' => ['manage_settings'], 'any' => false],
        'companies' => ['title' => 'الشركات', 'page' => 'companies', 'permissions' => ['manage_companies'], 'any' => false],
        'cities' => ['title' => 'المدن', 'page' => 'cities', 'permissions' => ['manage_countries'], 'any' => false],
        'main_routes' => ['title' => 'المسارات الرئيسية', 'page' => 'routes', 'permissions' => ['manage_routes'], 'any' => false],
        'sub_routes' => ['title' => 'المسارات الفرعية', 'page' => 'route_stops', 'permissions' => ['manage_routes'], 'any' => false],
        'stations' => ['title' => 'محطات التشغيل', 'page' => 'stations', 'permissions' => ['manage_routes'], 'any' => false],
        'buses' => ['title' => 'الباصات', 'page' => 'buses', 'permissions' => ['manage_buses'], 'any' => false],
        'trips' => ['title' => 'الرحلات', 'page' => 'trips', 'permissions' => ['manage_trips'], 'any' => false],
        'bookings' => ['title' => 'الحجوزات', 'page' => 'bookings', 'permissions' => ['view_all_bookings', 'view_company_bookings'], 'any' => true],
        'tickets' => ['title' => 'التذاكر', 'page' => 'tickets', 'permissions' => ['view_all_bookings', 'view_company_bookings'], 'any' => true],
        'customers' => ['title' => 'العملاء', 'page' => 'customers', 'permissions' => ['manage_users'], 'any' => false],
        'agents' => ['title' => 'الوكلاء', 'page' => 'agents', 'permissions' => ['manage_agents'], 'any' => false],
        'agent_balances' => ['title' => 'أرصدة الوكلاء', 'page' => 'wallet', 'permissions' => ['manage_payments'], 'any' => false],
        'agent_finance' => ['title' => 'إعدادات الوكلاء المالية', 'page' => 'agent_finance', 'permissions' => ['manage_agents'], 'any' => false],
        'agent_credit' => ['title' => 'شحن رصيد الوكيل', 'page' => 'agent_credit', 'permissions' => ['manage_agents'], 'any' => false],
        'agent_transactions' => ['title' => 'كشف حساب الوكيل', 'page' => 'agent_transactions', 'permissions' => ['manage_agents'], 'any' => false],
        'financial' => ['title' => 'المالية والحسابات', 'page' => 'transactions', 'permissions' => ['manage_payments'], 'any' => false],
        'reports' => ['title' => 'التقارير', 'page' => 'reports', 'permissions' => ['view_reports'], 'any' => false],
        'users' => ['title' => 'المستخدمون', 'page' => 'manage', 'permissions' => ['manage_users'], 'any' => false],
        'permissions' => ['title' => 'الصلاحيات', 'page' => 'permissions', 'permissions' => ['manage_users'], 'any' => false],
        'settings' => ['title' => 'الإعدادات', 'page' => 'settings', 'permissions' => ['manage_settings'], 'any' => false],
        'contact_messages' => ['title' => 'رسائل اتصل بنا', 'page' => 'contact_messages', 'permissions' => ['manage_settings'], 'any' => false],
        'notifications' => ['title' => 'الإشعارات', 'page' => 'notifications', 'permissions' => [], 'any' => true],
    ];
}

/** @param array<string, mixed> $user */
function adminForbidden(array $user, string $title): never
{
    http_response_code(403); header('Cache-Control: no-store, private');
    ?><!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>403 | غير مصرح</title><link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;900&display=swap" rel="stylesheet"><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f1f5f9;color:#0f172a;font-family:Tajawal,sans-serif}.card{max-width:460px;margin:20px;padding:42px 34px;border-radius:22px;background:#fff;text-align:center;box-shadow:0 20px 45px #0f172a18}.code{color:#1e3a8a;font-size:4rem;font-weight:900}.card h1{margin:0 0 10px;font-size:1.35rem}.card p{color:#64748b;line-height:1.9}.card a{display:inline-block;margin-top:14px;padding:12px 18px;border-radius:11px;background:#1e3a8a;color:#fff;text-decoration:none;font-weight:900}</style></head><body><section class="card"><div class="code">403</div><h1>ليس لديك صلاحية للوصول إلى <?= Security::escape($title) ?></h1><p>يتم التحقق من الصلاحية في الخادم لحماية بيانات المنصة. يمكنك العودة إلى لوحة التحكم المتاحة لحسابك.</p><a href="admin.php">العودة إلى لوحة التحكم</a></section></body></html><?php exit;
}

/** @return array<string, mixed> */
function requireAdminPage(string $key): array
{
    global $database;
    $page = adminPages()[$key] ?? null;
    if ($page === null) { http_response_code(404); exit('الصفحة غير موجودة.'); }
    $auth = new Auth($database); $user = $auth->currentUser();
    if ($user === null) { header('Location: ../login.php?return=' . rawurlencode('admin/' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'admin.php')))); exit; }
    $roles = $user['roles'] ?? [];
    if (array_intersect($roles, ['super_admin', 'company_admin', 'booking_officer', 'accountant', 'support']) === []) { adminForbidden($user, $page['title']); }
    if (in_array($key, ['users', 'permissions'], true) && !in_array('super_admin', $roles, true)) {
        adminForbidden($user, $page['title']);
    }
    if (!in_array('super_admin', $roles, true) && $page['permissions'] !== []) {
        $granted = array_values(array_intersect($page['permissions'], $user['permissions'] ?? []));
        $allowed = $page['any'] ? $granted !== [] : count($granted) === count($page['permissions']);
        if (!$allowed) { adminForbidden($user, $page['title']); }
    }
    return ['user' => $user, 'page' => $page];
}

/** @param array<string, mixed> $context */
function renderAdminPage(array $context): void
{
    global $database;
    $page = $context['page']; header('Cache-Control: no-store, private');
    $siteSettings = (new \App\Includes\SiteSettingsService($database))->publicSettings();
    $iconPath = (string) ($siteSettings['icon_path'] ?? '');
    $iconHref = preg_match('#^uploads/[a-z0-9_/-]+\\.(?:jpg|jpeg|png|webp)$#i', $iconPath) === 1 ? '../' . Security::escape($iconPath) . '?v=' . rawurlencode((string) time()) : '';
    $loadSubroutesModule = $page['page'] === 'route_stops';
    ?><!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="<?= Security::escape(Security::csrfToken()) ?>"><title><?= Security::escape($page['title']) ?> | منصة حجوزات الباصات</title><?= $iconHref !== '' ? '<link rel="icon" type="image/png" href="' . $iconHref . '">' : '' ?><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"><link rel="stylesheet" href="../assets/css/app.css?v=20260825-29"><link rel="stylesheet" href="assets.php?asset=main-routes-css&v=20260825-07"></head><body class="dashboard-page" data-api-base="../api/v1"><main id="app" data-role="admin" data-admin-page="<?= Security::escape($page['page']) ?>"></main><script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js" defer></script><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script><script src="../assets/js/app.js?v=20260825-39" defer></script><?= $loadSubroutesModule ? '<script src="assets.php?asset=subroutes-js&v=20260825-10" defer></script>' : '' ?><script src="assets.php?asset=stations-js&v=20260825-06" defer></script><script src="assets.php?asset=main-routes-js&v=20260825-07" defer></script></body></html><?php
}
