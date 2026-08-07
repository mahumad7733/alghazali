<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/includes/functions.php';

class AlGhazali_HealthCheck
{
    public static $REQUIRED_EXTENSIONS = [
        'pdo_mysql', 'mbstring', 'json', 'curl', 'gd', 'fileinfo', 'xml', 'zip', 'openssl', 'session', 'filter', 'hash'
    ];

    public static $RECOMMENDED_EXTENSIONS = [
        'intl', 'bcmath', 'imagick', 'redis', 'apcu', 'exif'
    ];

    public static function runAll($saveLog = true, $executorUserId = null)
    {
        ensure_system_admin_tables();
        $checks = [];
        $overall = 'healthy';
        $checks[] = self::checkDb();
        $checks[] = self::checkPhp();
        $checks[] = self::checkApache();
        $checks[] = self::checkExtensions();
        $checks[] = self::checkDiskPermissions();
        $checks[] = self::checkDiskSpace();
        $checks[] = self::checkTablesHealth();
        $checks[] = self::checkBackupFreshness();
        foreach ($checks as $c) {
            if ($c['status'] === 'fail') { $overall = 'critical'; break; }
            if ($c['status'] === 'warn' && $overall !== 'critical') { $overall = 'warning'; }
        }
        if ($saveLog) { self::saveToLogs($checks, $overall, $executorUserId); }
        return ['overall' => $overall, 'components' => $checks];
    }

    private static function saveToLogs(array $components, $overall, $userId)
    {
        global $pdo;
        if (!$pdo) { return; }
        try {
            $stmt = $pdo->prepare("INSERT INTO system_health_logs
                (executor_user_id, overall_status, component, component_status, component_message, component_metrics_json, recommendation)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($components as $c) {
                $stmt->execute([
                    $userId,
                    $overall,
                    $c['component'],
                    $c['status'],
                    $c['message'] ?? null,
                    !empty($c['metrics']) ? json_encode($c['metrics'], JSON_UNESCAPED_UNICODE) : null,
                    $c['recommendation'] ?? null,
                ]);
            }
        } catch (\Throwable $e) { /* ignore */ }
    }

    public static function checkDb()
    {
        global $pdo;
        $result = ['component' => 'db', 'status' => 'ok', 'message' => '', 'metrics' => [], 'recommendation' => null];
        if (!$pdo) {
            $result['status'] = 'fail';
            $result['message'] = 'لا يوجد اتصال بقاعدة البيانات (PDO غير متوفر)';
            $result['recommendation'] = 'تفقد ملف includes/db.php وبيانات الاتصال في إعدادات XAMPP';
            return $result;
        }
        try {
            $start = microtime(true);
            $v = $pdo->query("SELECT VERSION()")->fetchColumn();
            $latencyMs = round((microtime(true) - $start) * 1000, 2);
            $result['metrics'] = ['version' => $v, 'latency_ms' => $latencyMs];
            $result['message'] = "MySQL/MariaDB متصل بنجاح. الإصدار: $v. زمن الاستجابة: $latencyMs ms";
            $tablesCnt = $pdo->query("SHOW TABLES")->rowCount();
            $result['metrics']['tables_count'] = $tablesCnt;
            if ($latencyMs > 500) {
                $result['status'] = 'warn';
                $result['recommendation'] = 'زمن استجابة قاعدة البيانات مرتفع. تفقد الفهارس (Indexes) وحجم الاستعلامات.';
            }
        } catch (\Throwable $e) {
            $result['status'] = 'fail';
            $result['message'] = 'فشل الاتصال بقاعدة البيانات: ' . $e->getMessage();
            $result['recommendation'] = 'تفقد خدمة MariaDB/MySQL في XAMPP Control Panel';
        }
        return $result;
    }

    public static function checkPhp()
    {
        $result = ['component' => 'php', 'status' => 'ok', 'message' => '', 'metrics' => [], 'recommendation' => null];
        $result['metrics']['version'] = PHP_VERSION;
        $result['metrics']['sapi'] = PHP_SAPI;
        $result['metrics']['memory_limit'] = ini_get('memory_limit');
        $result['metrics']['max_execution_time'] = ini_get('max_execution_time');
        $result['metrics']['upload_max_filesize'] = ini_get('upload_max_filesize');
        $result['message'] = "PHP v" . PHP_VERSION . " عبر " . PHP_SAPI;
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $result['status'] = 'warn';
            $result['recommendation'] = 'الترقية إلى PHP 8.1 أو أحدث للحصول على أفضل أداء ودعم أماني.';
        }
        $displayErrors = ini_get('display_errors');
        if ($displayErrors && strtolower((string)$displayErrors) !== 'off' && (string)$displayErrors !== '0') {
            $result['status'] = 'warn';
            $result['recommendation'] = 'تعطيل display_errors في إعدادات PHP لبيئة الإنتاج لحماية البيانات الحساسة.';
        }
        return $result;
    }

    public static function checkApache()
    {
        $result = ['component' => 'apache', 'status' => 'ok', 'message' => '', 'metrics' => [], 'recommendation' => null];
        $sapi = PHP_SAPI;
        $result['metrics']['sapi'] = $sapi;
        if (function_exists('apache_get_version')) {
            $result['metrics']['apache_version'] = apache_get_version();
            $result['message'] = apache_get_version();
            if (function_exists('apache_get_modules')) {
                $mods = apache_get_modules();
                $result['metrics']['modules_count'] = count($mods);
                if (!in_array('mod_rewrite', $mods, true)) {
                    $result['status'] = 'warn';
                    $result['recommendation'] = 'تفعيل mod_rewrite في Apache لدعم ملفات .htaccess والروابط الودية.';
                }
            }
        } else {
            $software = $_SERVER['SERVER_SOFTWARE'] ?? ($_ENV['SERVER_SOFTWARE'] ?? 'Unknown');
            $result['message'] = "Server Software: $software (SAPI: $sapi)";
            if (stripos($software, 'nginx') !== false) {
                $result['message'] .= " — يعمل عبر Nginx";
            }
        }
        return $result;
    }

    public static function checkExtensions()
    {
        $result = ['component' => 'extensions', 'status' => 'ok', 'message' => '', 'metrics' => [], 'recommendation' => null];
        $missing = [];
        foreach (self::$REQUIRED_EXTENSIONS as $ext) {
            if (!extension_loaded($ext)) { $missing[] = $ext; }
        }
        $recommended = [];
        foreach (self::$RECOMMENDED_EXTENSIONS as $ext) {
            if (!extension_loaded($ext)) { $recommended[] = $ext; }
        }
        $result['metrics']['loaded_count'] = count(get_loaded_extensions());
        $result['metrics']['missing_required'] = $missing;
        $result['metrics']['missing_recommended'] = $recommended;
        if (!empty($missing)) {
            $result['status'] = 'fail';
            $result['message'] = 'الامتدادات المطلوبة المفقودة: ' . implode(', ', $missing);
            $result['recommendation'] = 'قم بتفعيل الامتدادات المفقودة في ملف php.ini ثم أعد تشغيل Apache.';
        } else {
            $result['message'] = 'جميع الامتدادات المطلوبة متوفرة.';
            if (!empty($recommended)) {
                $result['status'] = 'warn';
                $result['recommendation'] = 'قم بتفعيل الامتدادات المُوصى بها لتحسين الأداء: ' . implode(', ', $recommended);
            }
        }
        return $result;
    }

    public static function checkDiskPermissions()
    {
        $result = ['component' => 'disk_permissions', 'status' => 'ok', 'message' => '', 'metrics' => [], 'recommendation' => null];
        $paths = [
            BASE_PATH . '/uploads',
            BASE_PATH . '/admin/uploads',
            BASE_PATH . '/sessions',
            BASE_PATH . '/tmp',
            BASE_PATH . '/backups',
        ];
        $issues = [];
        $checked = 0;
        foreach ($paths as $p) {
            if (is_dir($p)) {
                $checked++;
                if (!is_writable($p)) { $issues[] = basename($p) . ' غير قابل للكتابة'; }
            }
        }
        $result['metrics']['checked'] = $checked;
        $result['metrics']['writable_issues'] = $issues;
        if (!empty($issues)) {
            $result['status'] = 'warn';
            $result['message'] = 'مشاكل في صلاحيات الكتابة: ' . implode(' ، ', $issues);
            $result['recommendation'] = 'منح صلاحيات Write للمجلدات المذكورة لمستخدم الخادم (Apache/Nginx).';
        } else {
            $result['message'] = $checked . ' مجلد تم فحص صلاحياته. كلها سليمة.';
        }
        return $result;
    }

    public static function checkDiskSpace()
    {
        $result = ['component' => 'disk_space', 'status' => 'ok', 'message' => '', 'metrics' => [], 'recommendation' => null];
        $free = @disk_free_space(BASE_PATH);
        $total = @disk_total_space(BASE_PATH);
        if ($free === false || $total === false || $total <= 0) {
            $result['status'] = 'warn';
            $result['message'] = 'تعذر قراءة معلومات القرص.';
            return $result;
        }
        $freePct = ($free / $total) * 100;
        $used = $total - $free;
        $result['metrics']['total_bytes'] = $total;
        $result['metrics']['free_bytes'] = $free;
        $result['metrics']['used_bytes'] = $used;
        $result['metrics']['free_pct'] = round($freePct, 2);
        $result['message'] = 'المساحة المتبقية: ' . self::humanBytes($free) . ' من ' . self::humanBytes($total) . ' (' . round($freePct, 1) . '%)';
        if ($freePct < 5) {
            $result['status'] = 'fail';
            $result['recommendation'] = 'مساحة القرص حرجة! قم بحذف الملفات غير الضرورية أو النسخ الاحتياطية القديمة.';
        } elseif ($freePct < 15) {
            $result['status'] = 'warn';
            $result['recommendation'] = 'مساحة القرص منخفضة. فكر في توسيع مساحة التخزين قريباً.';
        }
        return $result;
    }

    public static function checkTablesHealth()
    {
        global $pdo;
        $result = ['component' => 'tables', 'status' => 'ok', 'message' => '', 'metrics' => [], 'recommendation' => null];
        if (!$pdo) {
            $result['status'] = 'skip';
            return $result;
        }
        try {
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $checked = 0;
            $issues = [];
            foreach (array_slice($tables, 0, 30) as $t) {
                try {
                    $chk = $pdo->query("CHECK TABLE `$t`")->fetchAll(PDO::FETCH_ASSOC);
                    $checked++;
                    foreach ($chk as $r) {
                        $msgType = strtolower($r['Msg_type'] ?? '');
                        $msgText = strtolower($r['Msg_text'] ?? '');
                        if (in_array($msgType, ['error','warning'], true) && $msgText !== 'ok' && $msgText !== 'table is already up to date') {
                            $issues[] = "$t: " . ($r['Msg_text'] ?? 'unknown issue');
                        }
                    }
                } catch (\Throwable $e) { $issues[] = "$t: " . $e->getMessage(); }
            }
            $result['metrics']['total_tables'] = count($tables);
            $result['metrics']['checked_sample'] = $checked;
            $result['metrics']['issues'] = $issues;
            if (!empty($issues)) {
                $result['status'] = 'warn';
                $result['message'] = 'تم اكتشاف ' . count($issues) . ' مشكلة في فحص الجداول.';
                $result['recommendation'] = 'قم بتشغيل REPAIR TABLE أو OPTIMIZE TABLE للجداول التي بها مشاكل.';
            } else {
                $result['message'] = "الفحص الصحي لـ $checked جدولاً ناجح. كلها سليمة (من أصل " . count($tables) . " جدولاً في قاعدة البيانات).";
            }
        } catch (\Throwable $e) {
            $result['status'] = 'warn';
            $result['message'] = $e->getMessage();
        }
        return $result;
    }

    public static function checkBackupFreshness()
    {
        ensure_system_admin_tables();
        global $pdo;
        $result = ['component' => 'backup_freshness', 'status' => 'ok', 'message' => '', 'metrics' => [], 'recommendation' => null];
        $last = null;
        if ($pdo) {
            try {
                $q = $pdo->query("SELECT * FROM backup_records WHERE status = 'success' ORDER BY started_at DESC LIMIT 1");
                $last = $q->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (\Throwable $e) { $last = null; }
        }
        $backupFolders = [
            BASE_PATH . '/backups',
            BASE_PATH . '/admin/backups',
            BASE_PATH . '/db_backups',
        ];
        $lastFileDate = null;
        $lastFileName = null;
        foreach ($backupFolders as $folder) {
            if (!is_dir($folder)) { continue; }
            $files = glob($folder . '/*.{sql,sql.gz,zip,gz}', GLOB_BRACE);
            if (!is_array($files)) { continue; }
            foreach ($files as $f) {
                $mt = filemtime($f);
                if ($mt && ($lastFileDate === null || $mt > $lastFileDate)) {
                    $lastFileDate = $mt;
                    $lastFileName = basename($f);
                }
            }
        }
        $result['metrics']['last_db_record'] = $last;
        $result['metrics']['last_file'] = $lastFileName ? ['name' => $lastFileName, 'date' => date('Y-m-d H:i:s', $lastFileDate)] : null;
        $latestTimestamp = null;
        if ($last) { $latestTimestamp = strtotime($last['started_at']); }
        if ($lastFileDate && ($latestTimestamp === null || $lastFileDate > $latestTimestamp)) { $latestTimestamp = $lastFileDate; }
        if ($latestTimestamp === null) {
            $result['status'] = 'warn';
            $result['message'] = 'لا توجد سجلات لنسخ احتياطية ناجحة سابقة.';
            $result['recommendation'] = 'قم بإنشاء أول نسخة احتياطية فورية عبر صفحة إدارة النسخ الاحتياطية.';
        } else {
            $daysOld = (time() - $latestTimestamp) / 86400;
            $result['metrics']['days_since_last'] = round($daysOld, 2);
            $result['message'] = 'آخر نسخة احتياطية: ' . date('Y-m-d H:i:s', $latestTimestamp) . ' (قبل ' . round($daysOld, 1) . ' يوم)';
            if ($daysOld > 7) {
                $result['status'] = 'warn';
                $result['recommendation'] = 'آخر نسخة احتياطية قديمة (أكثر من أسبوع). حدث جدولة النسخ الاحتياطية التلقائية.';
            } elseif ($daysOld > 2) {
                $result['status'] = 'ok';
            }
        }
        return $result;
    }

    public static function humanBytes($bytes, $precision = 2)
    {
        $bytes = max(0, (int)$bytes);
        $units = ['B','KB','MB','GB','TB','PB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    public static function recentHistory($limit = 50)
    {
        ensure_system_admin_tables();
        global $pdo;
        if (!$pdo) { return []; }
        $sql = "SELECT h.*, u.username, u.full_name
                FROM system_health_logs h
                LEFT JOIN users u ON u.id = h.executor_user_id
                ORDER BY h.executed_at DESC
                LIMIT " . (int)$limit;
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
