<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/includes/system_admin/ErrorTracking.php';

/**
 * Local, explainable error analyzer. It deliberately does not execute generated
 * code or edit arbitrary files; fixes are delegated to the safe whitelist.
 */
class AlGhazali_ErrorAiAnalyzer
{
    public static function analyzeById($errorId)
    {
        global $pdo;
        if (!$pdo) {
            return ['success' => false, 'message' => 'قاعدة البيانات غير متاحة حالياً.'];
        }
        $stmt = $pdo->prepare('SELECT * FROM system_error_audit WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$errorId]);
        $error = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$error) {
            return ['success' => false, 'message' => 'لم يتم العثور على سجل الخطأ.'];
        }
        return self::analyze($error);
    }

    public static function analyze(array $error)
    {
        $message = trim((string)($error['message'] ?? ''));
        $file = str_replace('\\', '/', (string)($error['file'] ?? ''));
        $line = (int)($error['line'] ?? 0);
        $text = strtolower($message . ' ' . $file);
        $analysis = [
            'success' => true,
            'title' => 'تحليل ذكي محلي',
            'summary' => 'تم تحليل نمط الخطأ وسياقه دون تعديل الملفات.',
            'cause' => 'السبب الدقيق يحتاج مراجعة السطر والسياق البرمجي.',
            'recommendation' => 'افتح الملف عند السطر المحدد، اختبر التعديل على نسخة احتياطية، ثم راقب تكرار الخطأ.',
            'severity' => self::severity($error),
            'confidence' => 55,
            'auto_fix_available' => false,
            'file' => $file,
            'line' => $line,
        ];

        $patterns = [
            ['undefined array key', 'مفتاح مصفوفة غير موجود', 'الشفرة تقرأ مفتاحاً قد لا يرجعه الاستعلام أو قد لا يصل من الطلب.', 'استخدم ?? أو array_key_exists بعد التأكد من مصدر البيانات.', 96],
            ['undefined variable', 'متغير غير معرّف', 'المتغير يُستخدم قبل تهيئته أو خارج النطاق الذي أُنشئ فيه.', 'هيّئ المتغير بقيمة افتراضية قبل الاستخدام وراجع مسار التنفيذ.', 95],
            ['unknown column', 'عمود غير موجود في قاعدة البيانات', 'الاستعلام يستخدم اسماً لا يطابق مخطط الجدول الحالي.', 'راجع SHOW COLUMNS للجدول، ثم استخدم الاسم الصحيح أو alias متوافقاً.', 98],
            ['column not found', 'عمود غير موجود في قاعدة البيانات', 'الاستعلام يطلب عموداً غير موجود في الجدول أو alias غير صحيح.', 'طابق أسماء الأعمدة مع مخطط قاعدة البيانات قبل تعديل الاستعلام.', 98],
            ['syntax error', 'خطأ في تركيب PHP', 'يوجد قوس أو فاصلة أو صياغة غير مكتملة في الملف.', 'شغّل php -l على الملف، ثم أصلح أول خطأ يظهر قبل معالجة الأخطاء التالية.', 97],
            ['call to undefined function', 'دالة غير موجودة', 'تم استدعاء دالة غير محملة أو باسم غير صحيح.', 'تحقق من require_once واسم الدالة والامتداد المطلوب في PHP.', 92],
            ['unknown column', 'عدم تطابق بين الكود وقاعدة البيانات', 'الرسالة تشير إلى عمود غير موجود في الجدول الحالي.', 'لا تنشئ قاعدة بيانات جديدة؛ طابق الاستعلام مع الجداول الحالية أولاً.', 99],
            ['permission denied', 'مشكلة صلاحيات ملفات', 'PHP لا يملك صلاحية القراءة أو الكتابة في المسار المطلوب.', 'تحقق من صلاحيات المجلد وسجل خادم Apache قبل تغيير الصلاحيات.', 90],
            ['csrf', 'فشل حماية CSRF', 'الطلب لا يحتوي رمز الحماية أو أن الرمز انتهت صلاحيته.', 'أضف رمز CSRF للنموذج وتحقق منه قبل تنفيذ العملية.', 94],
            ['access denied', 'محاولة وصول غير مصرح بها', 'المستخدم الحالي لا يملك الصلاحية المطلوبة أو انتهت الجلسة.', 'تحقق من جلسة الدخول والصلاحية الحالية دون تجاوز نظام الصلاحيات.', 91],
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern[0])) {
                $analysis['title'] = $pattern[1];
                $analysis['summary'] = 'تم التعرف على نمط معروف في الرسالة ومكان الخطأ.';
                $analysis['cause'] = $pattern[2];
                $analysis['recommendation'] = $pattern[3];
                $analysis['confidence'] = $pattern[4];
                break;
            }
        }

        $fix = self::safeFixAvailability($error);
        $analysis['auto_fix_available'] = $fix['available'];
        $analysis['auto_fix_reason'] = $fix['reason'];
        $analysis['next_step'] = $fix['available']
            ? 'يوجد إصلاح آمن مطابق. سيُنشئ النظام نسخة احتياطية قبل التطبيق.'
            : 'لا يوجد إصلاح آلي موثوق لهذا النمط؛ لا يتم تعديل الملف تلقائياً.';
        return $analysis;
    }

    private static function safeFixAvailability(array $error)
    {
        $message = (string)($error['message'] ?? '');
        $file = str_replace('\\', '/', (string)($error['file'] ?? ''));
        $known = [
            'admin/index.php|workflow_name',
            'admin/system_admin/index.php|apache_version',
            'UserActivityMonitor.php|email',
            'passports.php|created_at',
            'passports.php|creator_name',
        ];
        foreach ($known as $rule) {
            [$name, $needle] = explode('|', $rule, 2);
            if (str_ends_with($file, $name) && str_contains($message, $needle)) {
                return ['available' => true, 'reason' => 'يوجد إصلاح آمن معتمد لهذا الخطأ.'];
            }
        }
        return ['available' => false, 'reason' => 'لا توجد قاعدة إصلاح آمنة معتمدة لهذا الخطأ.'];
    }

    private static function severity(array $error)
    {
        $level = strtoupper((string)($error['level'] ?? 'ERROR'));
        return in_array($level, ['CRITICAL', 'EMERGENCY'], true) ? 'حرج' : ($level === 'WARNING' ? 'تحذير' : 'خطأ');
    }
}
