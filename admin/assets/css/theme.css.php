<?php
// =====================================================
// theme.css.php - ملف CSS ديناميكي يولد الألوان حسب الإعدادات
// =====================================================

header('Content-Type: text/css');
// إضافة رؤوس تخزين مؤقت لمدة ساعة لتقليل التحميل
header('Cache-Control: max-age=3600, public');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');

require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';

// جلب إعدادات الألوان
$settings = getSettings($pdo);

// جلب تفضيل المستخدم (إذا كان مسجل الدخول)
$user_theme = 'light';
if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
session_write_close(); // أغلق الجلسة فوراً لمنع تعليق المتصفح (Session Locking)

if ($user_id) {
    try {
        $stmt_pref = $pdo->prepare("SELECT preference_value FROM user_preferences WHERE user_id = ? AND preference_key = 'theme'");
        $stmt_pref->execute([$user_id]);
        $user_theme = $stmt_pref->fetchColumn() ?: ($settings['default_theme'] ?? 'light');
    } catch (PDOException $e) {
        $user_theme = $settings['default_theme'] ?? 'light';
    }
} else {
    $user_theme = $settings['default_theme'] ?? 'light';
}

// :root يجب أن يبقى للوضع النهاري دائماً، حتى يعمل التبديل الفوري بين النهار والليل بدون ألوان عالقة.
$primary = $settings['primary_color_light'] ?? '#1d3dbc';
$secondary = $settings['secondary_color_light'] ?? '#f2a500';
$success = $settings['success_color_light'] ?? '#10b981';
$danger = $settings['danger_color_light'] ?? '#ef4444';
$warning = $settings['warning_color_light'] ?? '#f2a500';
$info = $settings['info_color_light'] ?? '#3b82f6';
$text_color = $settings['text_color_light'] ?? '#0f1f44';
$bg_color = $settings['bg_color_light'] ?? '#f8fbff';
$card_bg = $settings['card_bg_light'] ?? '#ffffff';
$border_color = $settings['border_color_light'] ?? '#dbe2f2';
$is_dark = false;
?>

/* =====================================================
نظام الألوان الديناميكي - تم إنشاؤه تلقائياً
===================================================== */

:root {
--primary-color: <?php echo $primary; ?>;
--primary-rgb: <?php echo hexdec(substr($primary, 1, 2)); ?>, <?php echo hexdec(substr($primary, 3, 2)); ?>, <?php echo hexdec(substr($primary, 5, 2)); ?>;
--secondary-color: <?php echo $secondary; ?>;
--success-color: <?php echo $success; ?>;
--danger-color: <?php echo $danger; ?>;
--warning-color: <?php echo $warning; ?>;
--info-color: <?php echo $info; ?>;
--text-color: <?php echo $text_color; ?>;
--bg-color: <?php echo $bg_color; ?>;
--card-bg: <?php echo $card_bg; ?>;
--border-color: <?php echo $border_color; ?>;
--sidebar-bg: <?php echo $is_dark ? ($settings['sidebar_bg_dark'] ?? ($settings['sidebar_color'] ?? '#111827')) : ($settings['sidebar_bg_light'] ?? ($settings['sidebar_color'] ?? '#ffffff')); ?>;
--sidebar-text: <?php echo $is_dark ? ($settings['sidebar_text_dark'] ?? ($settings['text_color_dark'] ?? '#f3f4f6')) : ($settings['sidebar_text_light'] ?? ($settings['text_color_light'] ?? '#1f2937')); ?>;
--sidebar-icon: <?php echo $is_dark ? ($settings['sidebar_icon_color_dark'] ?? ($settings['sidebar_text_dark'] ?? '#cbd5e1')) : ($settings['sidebar_icon_color_light'] ?? ($settings['sidebar_text_light'] ?? '#475569')); ?>;
--sidebar-border: <?php echo $is_dark ? 'rgba(255,255,255,0.08)' : 'rgba(15,23,42,0.08)'; ?>;
--sidebar-hover-bg: <?php echo $is_dark ? 'rgba(255,255,255,0.08)' : 'rgba(' . hexdec(substr($primary, 1, 2)) . ', ' . hexdec(substr($primary, 3, 2)) . ', ' . hexdec(substr($primary, 5, 2)) . ', 0.12)'; ?>;
}

.theme-dark,
.dark-mode {
--primary-color: <?php echo $settings['primary_color_dark'] ?? '#3b82f6'; ?>;
--primary-rgb: <?php echo hexdec(substr($settings['primary_color_dark'] ?? '#3b82f6', 1, 2)); ?>, <?php echo hexdec(substr($settings['primary_color_dark'] ?? '#3b82f6', 3, 2)); ?>, <?php echo hexdec(substr($settings['primary_color_dark'] ?? '#3b82f6', 5, 2)); ?>;
--success-color: <?php echo $settings['success_color_dark'] ?? '#10b981'; ?>;
--danger-color: <?php echo $settings['danger_color_dark'] ?? '#ef4444'; ?>;
--warning-color: <?php echo $settings['warning_color_dark'] ?? '#f59e0b'; ?>;
--info-color: <?php echo $settings['info_color_dark'] ?? '#3b82f6'; ?>;
--text-color: <?php echo $settings['text_color_dark'] ?? '#f3f4f6'; ?>;
--bg-color: <?php echo $settings['bg_color_dark'] ?? '#111827'; ?>;
--card-bg: <?php echo $settings['card_bg_dark'] ?? '#1f2937'; ?>;
--border-color: <?php echo $settings['border_color_dark'] ?? '#374151'; ?>;
--sidebar-bg: <?php echo $settings['sidebar_bg_dark'] ?? ($settings['sidebar_color'] ?? '#111827'); ?>;
--sidebar-text: <?php echo $settings['sidebar_text_dark'] ?? ($settings['text_color_dark'] ?? '#f3f4f6'); ?>;
--sidebar-icon: <?php echo $settings['sidebar_icon_color_dark'] ?? ($settings['sidebar_text_dark'] ?? '#cbd5e1'); ?>;
--sidebar-border: rgba(255,255,255,0.08);
--sidebar-hover-bg: rgba(255,255,255,0.08);
}

/* =====================================================
الأنماط الأساسية
===================================================== */

body {
background-color: var(--bg-color);
color: var(--text-color);
transition: background-color 0.3s ease, color 0.3s ease;
}

/* البطاقات */
.card, .modal-content, .dropdown-menu, .list-group-item {
background-color: var(--card-bg) !important;
border-color: var(--border-color) !important;
color: var(--text-color) !important;
}

/* الجداول */
.table {
color: var(--text-color) !important;
}
.table thead th {
background-color: <?php echo $is_dark ? 'rgba(255,255,255,0.05)' : '#f8f9fa'; ?> !important;
border-bottom-color: var(--border-color) !important;
color: var(--text-color) !important;
}
.table tbody tr {
border-bottom-color: var(--border-color) !important;
}
.table tbody tr:hover {
background-color: <?php echo $is_dark ? 'rgba(255,255,255,0.05)' : '#f8f9fa'; ?> !important;
}

/* الأزرار */
.btn-primary {
background-color: var(--primary-color) !important;
border-color: var(--primary-color) !important;
}
.btn-primary:hover {
background-color: <?php echo adjustBrightness($primary, -20); ?> !important;
border-color: <?php echo adjustBrightness($primary, -20); ?> !important;
}
.btn-outline-primary {
color: var(--primary-color) !important;
border-color: var(--primary-color) !important;
}
.btn-outline-primary:hover {
background-color: var(--primary-color) !important;
color: white !important;
}

.btn-secondary {
background-color: var(--secondary-color) !important;
border-color: var(--secondary-color) !important;
}
.btn-success {
background-color: var(--success-color) !important;
border-color: var(--success-color) !important;
}
.btn-danger {
background-color: var(--danger-color) !important;
border-color: var(--danger-color) !important;
}
.btn-warning {
background-color: var(--warning-color) !important;
border-color: var(--warning-color) !important;
color: #1f2937 !important;
}
.btn-info {
background-color: var(--info-color) !important;
border-color: var(--info-color) !important;
color: white !important;
}

/* النماذج */
.form-control, .form-select, .input-group-text {
background-color: <?php echo $is_dark ? 'rgba(255,255,255,0.05)' : 'var(--card-bg)'; ?> !important;
border-color: var(--border-color) !important;
color: var(--text-color) !important;
}
.form-control:focus, .form-select:focus {
background-color: <?php echo $is_dark ? 'rgba(255,255,255,0.08)' : 'var(--card-bg)'; ?> !important;
border-color: var(--primary-color) !important;
box-shadow: 0 0 0 0.2rem rgba(var(--primary-rgb), 0.25) !important;
color: var(--text-color) !important;
}

/* الروابط */
a {
color: var(--primary-color);
}
a:hover {
color: <?php echo adjustBrightness($primary, -30); ?>;
}

/* شريط التنقل */
.navbar {
background-color: var(--card-bg) !important;
border-bottom: 1px solid var(--border-color) !important;
}
.navbar-brand, .nav-link {
color: var(--text-color) !important;
}
.nav-link:hover, .nav-link.active {
color: var(--primary-color) !important;
background-color: <?php echo $is_dark ? 'rgba(255,255,255,0.05)' : 'rgba(var(--primary-rgb), 0.1)'; ?> !important;
}

/* القائمة الجانبية */
.sidebar {
background-color: var(--sidebar-bg) !important;
border-right: 1px solid var(--sidebar-border) !important;
color: var(--sidebar-text) !important;
}
.sidebar .nav-link, .sidebar-menu a {
color: var(--sidebar-text) !important;
padding: 0.75rem 1rem;
border-radius: 0.75rem;
}
.sidebar .sidebar-section-label {
color: <?php echo $is_dark ? '#94a3b8' : '#6b7280'; ?> !important;
text-transform: uppercase;
letter-spacing: 0.05em;
font-size: 0.78rem;
margin: 1rem 0 0.5rem;
}
.sidebar .nav-link:hover,
.sidebar .nav-link.active,
.sidebar-menu a:hover,
.sidebar-menu a.active {
background-color: var(--sidebar-hover-bg) !important;
color: var(--primary-color) !important;
}
.sidebar .nav-link.active,
.sidebar-menu a.active {
box-shadow: inset 0 0 0 1px rgba(var(--primary-rgb), 0.18), 0 0 0 1px rgba(255,255,255,0.05);
}
.sidebar-header, .user-panel {
background-color: <?php echo $is_dark ? 'rgba(255,255,255,0.04)' : 'rgba(15,23,42,0.04)'; ?> !important;
border-color: var(--sidebar-border) !important;
color: var(--sidebar-text) !important;
}
.sidebar-header h5, .user-name {
color: var(--sidebar-text) !important;
}
.sidebar-menu a .menu-icon {
width: 2.4rem;
height: 2.4rem;
min-width: 2.4rem;
display: inline-flex;
align-items: center;
justify-content: center;
border-radius: 0.75rem;
background-color: <?php echo $is_dark ? 'rgba(255,255,255,0.05)' : 'rgba(15,23,42,0.06)'; ?> !important;
color: var(--sidebar-icon) !important;
}
.sidebar-menu a.active .menu-icon {
background-color: var(--primary-color) !important;
color: #ffffff !important;
}
.sidebar-menu a:hover .menu-icon {
background-color: rgba(var(--primary-rgb), 0.15) !important;
color: var(--primary-color) !important;
}
.sidebar-menu a.text-danger,
.sidebar-menu a.text-danger .menu-icon {
color: #ef4444 !important;
}
.sidebar-menu a.text-danger:hover {
background-color: rgba(239,68,68,0.12) !important;
}

/* التنبيهات */
.alert-primary {
background-color: rgba(var(--primary-rgb), 0.1) !important;
border-color: var(--primary-color) !important;
color: var(--text-color) !important;
}
.alert-success {
background-color: rgba(16, 185, 129, 0.1) !important;
border-color: var(--success-color) !important;
color: var(--text-color) !important;
}
.alert-danger {
background-color: rgba(239, 68, 68, 0.1) !important;
border-color: var(--danger-color) !important;
color: var(--text-color) !important;
}
.alert-warning {
background-color: rgba(245, 158, 11, 0.1) !important;
border-color: var(--warning-color) !important;
color: var(--text-color) !important;
}
.alert-info {
background-color: rgba(59, 130, 246, 0.1) !important;
border-color: var(--info-color) !important;
color: var(--text-color) !important;
}

/* علامات التبويب */
.nav-tabs {
border-bottom-color: var(--border-color) !important;
}
.nav-tabs .nav-link {
color: var(--text-color) !important;
}
.nav-tabs .nav-link.active {
background-color: var(--card-bg) !important;
border-color: var(--border-color) !important;
border-bottom-color: var(--card-bg) !important;
color: var(--primary-color) !important;
}

/* شريط التمرير المخصص */
::-webkit-scrollbar {
width: 8px;
height: 8px;
}
::-webkit-scrollbar-track {
background: var(--bg-color);
}
::-webkit-scrollbar-thumb {
background: var(--secondary-color);
border-radius: 4px;
}
::-webkit-scrollbar-thumb:hover {
background: var(--primary-color);
}

/* تأثيرات إضافية */
.transition-all {
transition: all 0.3s ease;
}

.card-hover:hover {
transform: translateY(-5px);
box-shadow: 0 10px 20px rgba(0,0,0,0.1);
}

body:not(.theme-dark):not(.dark-mode) {
--bs-body-bg: var(--bg-color);
--bs-body-color: var(--text-color);
--bs-border-color: var(--border-color);
--bs-tertiary-bg: #f8fbff;
--bs-secondary-color: #64748b;
background-color: var(--bg-color) !important;
color: var(--text-color) !important;
}

body.theme-dark,
body.dark-mode {
--bs-body-bg: var(--bg-color);
--bs-body-color: var(--text-color);
--bs-border-color: var(--border-color);
--bs-tertiary-bg: #0f1e35;
--bs-secondary-color: #94a3b8;
background-color: var(--bg-color) !important;
color: var(--text-color) !important;
color-scheme: dark;
}

body.theme-dark .main-wrapper,
body.theme-dark .content-body,
body.dark-mode .main-wrapper,
body.dark-mode .content-body {
background-color: var(--bg-color) !important;
color: var(--text-color) !important;
}

body.theme-dark .top-navbar,
body.dark-mode .top-navbar {
background: var(--bg-color) !important;
border-bottom-color: var(--border-color) !important;
}

body.theme-dark .card,
body.theme-dark .card-body,
body.theme-dark .modal-content,
body.theme-dark .dropdown-menu,
body.theme-dark .list-group-item,
body.dark-mode .card,
body.dark-mode .card-body,
body.dark-mode .modal-content,
body.dark-mode .dropdown-menu,
body.dark-mode .list-group-item {
background-color: var(--card-bg) !important;
border-color: var(--border-color) !important;
color: var(--text-color) !important;
}

body.theme-dark .card-header,
body.theme-dark .card-footer,
body.theme-dark .modal-header,
body.theme-dark .modal-footer,
body.dark-mode .card-header,
body.dark-mode .card-footer,
body.dark-mode .modal-header,
body.dark-mode .modal-footer {
background-color: color-mix(in srgb, var(--card-bg) 82%, var(--bg-color)) !important;
border-color: var(--border-color) !important;
color: var(--text-color) !important;
}

body.theme-dark .form-control,
body.theme-dark .form-select,
body.theme-dark .input-group-text,
body.theme-dark .select2-container--default .select2-selection--single,
body.theme-dark .select2-container--default .select2-selection--multiple,
body.dark-mode .form-control,
body.dark-mode .form-select,
body.dark-mode .input-group-text,
body.dark-mode .select2-container--default .select2-selection--single,
body.dark-mode .select2-container--default .select2-selection--multiple {
background-color: #0f1e35 !important;
border-color: var(--border-color) !important;
color: var(--text-color) !important;
}

body.theme-dark .form-control:focus,
body.theme-dark .form-select:focus,
body.dark-mode .form-control:focus,
body.dark-mode .form-select:focus {
background-color: #10213d !important;
border-color: var(--primary-color) !important;
box-shadow: 0 0 0 .16rem rgba(var(--primary-rgb), .22) !important;
color: var(--text-color) !important;
}

body.theme-dark .table,
body.dark-mode .table {
--bs-table-bg: var(--card-bg);
--bs-table-color: var(--text-color);
--bs-table-border-color: var(--border-color);
--bs-table-striped-bg: #0f1e35;
--bs-table-striped-color: var(--text-color);
--bs-table-hover-bg: #162032;
--bs-table-hover-color: #ffffff;
color: var(--text-color) !important;
border-color: var(--border-color) !important;
}

body.theme-dark .table > :not(caption) > * > *,
body.dark-mode .table > :not(caption) > * > * {
background-color: var(--bs-table-bg) !important;
color: var(--bs-table-color) !important;
border-color: var(--bs-table-border-color) !important;
}

body.theme-dark .table thead th,
body.theme-dark .table thead tr > *,
body.dark-mode .table thead th,
body.dark-mode .table thead tr > * {
background-color: #0f1e35 !important;
color: #cbd5e1 !important;
}

body.theme-dark .text-muted,
body.dark-mode .text-muted {
color: #94a3b8 !important;
}

body.theme-dark .text-dark,
body.theme-dark .text-body,
body.dark-mode .text-dark,
body.dark-mode .text-body {
color: var(--text-color) !important;
}

body.theme-dark .bg-white,
body.theme-dark .bg-light,
body.dark-mode .bg-white,
body.dark-mode .bg-light {
background-color: var(--card-bg) !important;
color: var(--text-color) !important;
}

body.theme-dark .btn-light,
body.theme-dark .btn-outline-secondary,
body.dark-mode .btn-light,
body.dark-mode .btn-outline-secondary {
background-color: #1e2d45 !important;
border-color: #2d3f5c !important;
color: var(--text-color) !important;
}
