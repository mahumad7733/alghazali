<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/system_error_audit.php';

class AlGhazali_ErrorTracking
{
    /** Apply only whitelisted, reversible fixes and always create a backup first. */
    public static function attemptSafeAutoFix($errorId, $userId = null)
    {
        global $pdo;
        if (!$pdo) return ['success' => false, 'message' => 'قاعدة البيانات غير متاحة حالياً.'];
        $q = $pdo->prepare("SELECT id, message, file FROM system_error_audit WHERE id = ? LIMIT 1");
        $q->execute([(int)$errorId]);
        $error = $q->fetch(PDO::FETCH_ASSOC);
        if (!$error) return ['success' => false, 'message' => 'لم يتم العثور على سجل الخطأ.'];

        $file = str_replace('\\', '/', (string)($error['file'] ?? ''));
        $root = str_replace('\\', '/', BASE_PATH);
        // Historical logs may contain the previous project folder name. Resolve
        // only known application roots so we never write outside this project.
        if (strpos($file, $root) !== 0 && preg_match('#/(admin|includes)/(.+)$#i', $file, $pathMatch)) {
            $file = $root . '/' . $pathMatch[1] . '/' . $pathMatch[2];
        }
        $relative = ltrim(str_replace($root, '', $file), '/');
        $message = (string)($error['message'] ?? '');
        $rules = [];
        if (basename($file) === 'index.php' && str_ends_with($relative, 'admin/index.php') && str_contains($message, 'workflow_name')) {
            $rules[] = ['$recent_workflows = $pdo->query("SELECT * FROM workflows WHERE is_active = 1 ORDER BY created_at DESC LIMIT 5")->fetchAll();', '$recent_workflows = $pdo->query("SELECT w.*, w.name AS workflow_name FROM workflows w WHERE w.is_active = 1 ORDER BY w.created_at DESC LIMIT 5")->fetchAll();'];
        }
        if (basename($file) === 'index.php' && str_ends_with($relative, 'admin/system_admin/index.php') && str_contains($message, 'apache_version')) {
            $rules[] = ["\$stats['apache_version'] ?", "!empty(\$stats['apache_version']) ?"];
        }
        if (basename($file) === 'UserActivityMonitor.php' && str_contains($message, 'email')) {
            $rules[] = ['u.email, u.phone_number, r.role_name as role_name', 'NULL AS email, NULL AS phone_number, r.name AS role_name'];
        }
        if (basename($file) === 'passports.php' && str_contains($message, 'created_at')) {
            $rules[] = ["date('H:i', strtotime(\$p['created_at']))", "((\$p['created_at'] ?? null) ? date('H:i', strtotime(\$p['created_at'])) : '---')"];
            $rules[] = ["date('Y-m-d', strtotime(\$p['created_at']))", "((\$p['created_at'] ?? null) ? date('Y-m-d', strtotime(\$p['created_at'])) : '---')"];
        }
        if (basename($file) === 'passports.php' && str_contains($message, 'creator_name')) {
            $rules[] = ["\$p['creator_name'] ?: '---'", "(\$p['creator_name'] ?? null) ?: '---'"];
        }
        if (!$rules || !is_file($file) || strpos($file, $root) !== 0) return ['success' => false, 'message' => 'لا يوجد إصلاح آمن معتمد لهذا الخطأ. استخدم التحليل الذكي لمعرفة الخطوة التالية.'];
        $source = file_get_contents($file);
        $updated = $source;
        foreach ($rules as $rule) $updated = str_replace($rule[0], $rule[1], $updated);
        if ($updated === $source) return ['success' => false, 'message' => 'الإصلاح الآمن مطبق مسبقاً أو أن الكود الحالي لا يطابق قاعدة الإصلاح.'];

        $backupDir = BASE_PATH . '/storage/system_admin_fixes';
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true)) return ['success' => false, 'message' => 'تعذر إنشاء مجلد النسخ الاحتياطية.'];
        $backup = $backupDir . '/' . basename($file) . '.' . date('Ymd_His') . '.bak';
        if (@file_put_contents($backup, $source) === false || @file_put_contents($file, $updated) === false) return ['success' => false, 'message' => 'تعذر حفظ النسخة الاحتياطية أو تطبيق الإصلاح.'];
        self::updateErrorStatus([(int)$errorId], 'resolved', $userId, 'تم تطبيق إصلاح آمن. النسخة الاحتياطية: ' . $backup);
        return ['success' => true, 'message' => 'تم تطبيق الإصلاح الآمن. أُنشئت نسخة احتياطية في: ' . $backup];
    }

    public static function computeFingerprint($message, $file, $line)
    {
        $cleanMessage = preg_replace('/\d+/', 'N', (string)$message);
        $cleanMessage = preg_replace("/'[^']*'/", '?', $cleanMessage);
        $cleanMessage = preg_replace('/"[^"]*"/', '?', $cleanMessage);
        return hash('sha256', $cleanMessage . '|' . basename((string)$file) . '|' . (int)$line);
    }

    public static function updateOccurrencesOnLog(&$context = [])
    {
        ensure_system_admin_tables();
        global $pdo;
        if (!$pdo) { return null; }
        try {
            if (empty($context['fingerprint']) || empty($context['message']) || empty($context['file'])) { return null; }
            $fp = $context['fingerprint'];
            $q = $pdo->prepare("SELECT id FROM system_error_audit WHERE error_fingerprint = ? ORDER BY id ASC LIMIT 1");
            $q->execute([$fp]);
            $existing = $q->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $pdo->prepare("UPDATE system_error_audit SET occurrences = occurrences + 1, last_occurred_at = NOW() WHERE id = ?")
                    ->execute([(int)$existing['id']]);
                return (int)$existing['id'];
            }
            return null;
        } catch (\Throwable $e) { return null; }
    }

    public static function listGrouped($filters = [])
    {
        ensure_system_admin_tables();
        global $pdo;
        if (!$pdo) { return []; }
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['level'])) { $where[] = "level IN (" . implode(',', array_fill(0, count((array)$filters['level']), '?')) . ")"; $params = array_merge($params, (array)$filters['level']); }
        if (!empty($filters['status'])) { $where[] = "status = ?"; $params[] = $filters['status']; }
        if (!empty($filters['exclude_resolved']) && empty($filters['status'])) { $where[] = "status <> 'resolved'"; }
        if (!empty($filters['priority'])) { $where[] = "priority = ?"; $params[] = $filters['priority']; }
        if (!empty($filters['from_date'])) { $where[] = "created_at >= ?"; $params[] = $filters['from_date']; }
        if (!empty($filters['to_date'])) { $where[] = "created_at <= ?"; $params[] = $filters['to_date']; }
        if (!empty($filters['search'])) { $where[] = "(message LIKE ? OR file LIKE ? OR url LIKE ?)"; $s = '%' . $filters['search'] . '%'; array_push($params, $s, $s, $s); }
        $sql = "SELECT MIN(id) AS representative_id, error_fingerprint, file, line, level, status, priority,
                    COUNT(*) as group_count,
                    MAX(occurrences) as occurrences,
                    MAX(created_at) as last_seen_at,
                    MIN(created_at) as first_seen_at,
                    SUBSTRING(MAX(message), 1, 200) as sample_message
                FROM system_error_audit
                WHERE " . implode(' AND ', $where) . "
                GROUP BY error_fingerprint, file, line, level, status, priority
                ORDER BY group_count DESC
                LIMIT 500";
        $q = $pdo->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function updateErrorStatus($errorIds, $newStatus, $userId = null, $notes = null, $assigneeId = null)
    {
        ensure_system_admin_tables();
        global $pdo;
        if (!$pdo) { return 0; }
        $ids = array_map('intval', (array)$errorIds);
        if (empty($ids)) { return 0; }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $updates = ["status = ?"];
        $params = [$newStatus];
        if (in_array($newStatus, ['resolved'], true)) {
            $updates[] = "resolved_at = NOW()";
            if ($userId) { $updates[] = "resolved_by = ?"; $params[] = $userId; }
        }
        if ($notes !== null) { $updates[] = "repair_notes = ?"; $params[] = $notes; }
        if ($assigneeId !== null) { $updates[] = "assignee_id = ?"; $params[] = $assigneeId; }
        $params = array_merge($params, $ids);
        $sql = "UPDATE system_error_audit SET " . implode(', ', $updates) . " WHERE id IN ($placeholders)";
        $q = $pdo->prepare($sql);
        $q->execute($params);
        return $q->rowCount();
    }

    public static function listErrorsForFingerprint($fingerprint, $limit = 50)
    {
        global $pdo;
        if (!$pdo) { return []; }
        $q = $pdo->prepare("SELECT * FROM system_error_audit WHERE error_fingerprint = ? ORDER BY created_at DESC LIMIT " . (int)$limit);
        $q->execute([$fingerprint]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function addRepairNote($errorId, $note, $userId)
    {
        global $pdo;
        if (!$pdo) { return false; }
        try {
            $existing = $pdo->prepare("SELECT repair_notes FROM system_error_audit WHERE id = ?");
            $existing->execute([$errorId]);
            $prev = $existing->fetchColumn() ?: '';
            $append = "\n[#" . (int)$userId . " @ " . date('Y-m-d H:i:s') . "] " . $note;
            $stmt = $pdo->prepare("UPDATE system_error_audit SET repair_notes = ? WHERE id = ?");
            $stmt->execute([trim($prev . $append), $errorId]);
            return true;
        } catch (\Throwable $e) { return false; }
    }
}
