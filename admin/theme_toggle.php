<?php
// =====================================================
// theme_toggle.php - زر تبديل الوضع الليلي/النهاري
// =====================================================

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// التحقق من تفعيل زر تبديل الوضع من الإعدادات
$settings_toggle = getSettings($pdo);
$enable_toggle = $settings_toggle['enable_dark_mode_toggle'] ?? 1;

if (!$enable_toggle) {
    return;
}

// جلب الوضع الحالي للمستخدم
$current_user_theme = 'light';
$user_id_toggle = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
if ($user_id_toggle) {
    $stmt_pref = $pdo->prepare("SELECT preference_value FROM user_preferences WHERE user_id = ? AND preference_key = 'theme'");
    $stmt_pref->execute([$user_id_toggle]);
    $current_user_theme = $stmt_pref->fetchColumn() ?: ($settings_toggle['default_theme'] ?? 'light');
} else {
    $current_user_theme = $settings_toggle['default_theme'] ?? 'light';
}
?>

<button id="themeToggleBtn" class="icon-btn" title="تبديل الوضع">
    <i class="fas <?php echo $current_user_theme == 'dark' ? 'fa-sun' : 'fa-moon'; ?>" id="adminThemeIcon"></i>
</button>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggleBtn = document.getElementById('themeToggleBtn');
        if (!toggleBtn) return;

        // جلب الوضع الحالي من localStorage أو من الكوكيز أو من السيرفر
        function getCookie(name) {
            const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
            return match ? decodeURIComponent(match[2]) : null;
        }

        function setThemeCookie(theme) {
            document.cookie = 'theme=' + encodeURIComponent(theme) + '; path=/; max-age=31536000';
        }

        function getSavedTheme() {
            return localStorage.getItem('admin_theme') || localStorage.getItem('theme') || getCookie('theme') || '<?php echo $current_user_theme; ?>';
        }

        function resolveTheme(theme) {
            if (theme === 'system') {
                return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            return theme;
        }

        // تطبيق الوضع على الصفحة
        function applyTheme(theme) {
            const isDark = (theme === 'dark');
            document.body.classList.toggle('theme-dark', isDark);
            document.body.classList.toggle('dark-mode', isDark);
            document.documentElement.setAttribute('data-bs-theme', isDark ? 'dark' : 'light');

            const icon = document.getElementById('adminThemeIcon');
            if (icon) {
                icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
            }

            toggleBtn.title = isDark ? 'الوضع النهاري' : 'الوضع الليلي';
            localStorage.setItem('theme', theme);
            localStorage.setItem('admin_theme', theme);
            setThemeCookie(theme);
        }

        // تحميل الوضع المحفوظ
        const currentTheme = resolveTheme(getSavedTheme());
        applyTheme(currentTheme);

        // عند النقر على الزر
        toggleBtn.addEventListener('click', function() {
            const newTheme = document.body.classList.contains('theme-dark') || document.body.classList.contains('dark-mode') ? 'light' : 'dark';
            applyTheme(newTheme);

            // إرسال الطلب إلى السيرفر لحفظ التفضيل
            const formData = new FormData();
            formData.append('theme', newTheme);
            formData.append('csrf_token', <?php echo function_exists('generate_csrf_token') ? json_encode(generate_csrf_token()) : '""'; ?>);

            fetch('ajax_save_theme.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            }).catch(() => {
                // تجاهل إخفاق الحفظ إذا لم يكن المستخدم مسجلاً
            });
        });
    });
</script>

<style>
    .icon-btn {
        background: transparent;
        border: 1px solid var(--border-color);
        color: var(--text-color);
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: 0.3s;
    }

    .icon-btn:hover {
        background-color: rgba(var(--primary-rgb), 0.1);
        color: var(--primary-color);
    }
</style>
