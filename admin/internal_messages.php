<?php
ob_start();
// debug helpers
error_reporting(E_ALL);
ini_set('display_errors', 0);
if (session_status() === PHP_SESSION_NONE) session_start();
// indicate file loaded
//echo "<!-- internal_messages_new.php loaded -->";

require_once '../includes/db.php';

// التأكد من وجود الإعدادات اللازمة
try {
    require_once '../includes/functions.php';
    $settings = getSettings($pdo);
    
    // التأكد من وجود المفاتيح المطلوبة في system_settings
    $required_keys = [
        'edit_delete_time_limit' => 5,
        'disappear_after_read_seconds' => 10
    ];
    
    foreach ($required_keys as $key => $default) {
        if (!isset($settings[$key])) {
            $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'messages')")
                ->execute([$key, $default]);
        }
    }
} catch (Exception $e) {
    // تجاهل الأخطاء البسيطة هنا
}

try {
    // جدول الرسائل
    $pdo->query("SELECT is_disappeared FROM internal_messages LIMIT 1");
} catch (Exception $e) {
    $pdo->query("ALTER TABLE `internal_messages` ADD `read_at` DATETIME NULL DEFAULT NULL");
    $pdo->query("ALTER TABLE `internal_messages` ADD `is_disappeared` TINYINT(1) DEFAULT 0");
    $pdo->query("ALTER TABLE `internal_messages` ADD `original_message` TEXT NULL DEFAULT NULL");
}

try {
    // جدول رسائل المجموعات
    $pdo->query("SELECT original_message FROM group_messages LIMIT 1");
} catch (Exception $e) {
    $pdo->query("ALTER TABLE `group_messages` ADD `original_message` TEXT NULL DEFAULT NULL");
}

$current_user_id = $_SESSION['admin_id'] ?? null;
$upload_dir = '../assets/uploads/chat/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// جلب دور المستخدم الحالي
$user_role = $_SESSION['role'] ?? 'editor';
$is_admin = ($user_role === 'admin' || $user_role === 'developer');

// معالجة طلبات AJAX قبل تحميل الهيدر
if (isset($_GET['action'])) {
    error_reporting(0); // Disable all errors for AJAX
    ini_set('display_errors', 0); // Disable error display for AJAX
    if (ob_get_level()) {
        ob_end_clean(); // Clean any existing output buffers
    }
    if (!$current_user_id) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not logged in']);
        exit;
    }
    $action = $_GET['action'];
    header('Content-Type: application/json');

    // إرسال رسالة فردية
    if ($action == 'send') {
        $receiver_id = (int)$_POST['receiver_id'];
        $message = $_POST['message'] ?? '';
        $image_path = null;

        // منع المستخدم من إرسال رسائل لنفسه
        if ($receiver_id == $current_user_id) {
            echo json_encode(['status' => 'error', 'message' => 'لا يمكنك إرسال رسالة لنفسك']);
            exit();
        }

        if (!empty($_FILES['chat_image']['name'])) {
            $ext = pathinfo($_FILES['chat_image']['name'], PATHINFO_EXTENSION);
            $image_name = time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['chat_image']['tmp_name'], $upload_dir . $image_name)) {
                $image_path = 'assets/uploads/chat/' . $image_name;
            }
        }

        if ($receiver_id > 0 && (!empty($message) || $image_path)) {
            $stmt = $pdo->prepare("INSERT INTO internal_messages (sender_id, receiver_id, message, image_path, is_disappeared) VALUES (?, ?, ?, ?, 0)");
            $stmt->execute([$current_user_id, $receiver_id, $message, $image_path]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'بيانات ناقصة']);
        }
        exit();
    }

    // إرسال رسالة جماعية
    if ($action == 'send_group') {
        $group_id = (int)$_POST['group_id'];
        $message = $_POST['message'] ?? '';
        $image_path = null;

        // التحقق من أن المستخدم عضو في المجموعة
        $member_check = $pdo->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
        $member_check->execute([$group_id, $current_user_id]);
        if (!$member_check->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'أنت لست عضواً في هذه المجموعة']);
            exit();
        }

        if (!empty($_FILES['chat_image']['name'])) {
            $ext = pathinfo($_FILES['chat_image']['name'], PATHINFO_EXTENSION);
            $image_name = time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['chat_image']['tmp_name'], $upload_dir . $image_name)) {
                $image_path = 'assets/uploads/chat/' . $image_name;
            }
        }

        if (!empty($message) || $image_path) {
            $stmt = $pdo->prepare("INSERT INTO group_messages (group_id, sender_id, message, image_path) VALUES (?, ?, ?, ?)");
            $stmt->execute([$group_id, $current_user_id, $message, $image_path]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'بيانات ناقصة']);
        }
        exit();
    }

    // تحديث إعدادات التنبيهات
    if ($action == 'toggle_notification') {
        $type = $_POST['type']; // sound or notification
        $enabled = (int)$_POST['enabled'];

        $update_field = ($type == 'sound') ? 'sound_enabled' : 'notification_enabled';
        $stmt = $pdo->prepare("UPDATE notification_settings SET $update_field = ? WHERE user_id = ?");
        $stmt->execute([$enabled, $current_user_id]);

        echo json_encode(['status' => 'success']);
        exit();
    }

    // جلب قائمة المستخدمين (بدون المستخدم الحالي)
    if ($action == 'get_users') {
        $users = $pdo->prepare("
            SELECT u.id, u.username, u.full_name, u.profile_image, u.is_online, u.last_seen,
                   (SELECT COUNT(*) FROM internal_messages im 
                    WHERE im.sender_id = u.id AND im.receiver_id = ? 
                    AND im.is_read = 0 
                    AND (im.is_deleted_for_all = 0 OR im.is_deleted_for_all IS NULL)
                    AND (im.is_disappeared = 0 OR im.is_disappeared IS NULL)) as unread_count
            FROM users u
            WHERE u.id != ?
            ORDER BY u.is_online DESC, u.last_seen DESC, u.full_name ASC
        ");
        try {
            $users->execute([$current_user_id, $current_user_id]);
            $user_list = $users->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Query error: " . $e->getMessage());
            echo json_encode(['users' => [], 'message' => 'Database error']);
            exit();
        }
        echo json_encode(['users' => $user_list]);
        exit();
    }

    // جلب قائمة المجموعات
    if ($action == 'get_groups') {
        $sql = $is_admin ? 
            "SELECT mg.*, u.full_name as creator_name, 
                   (SELECT COUNT(*) FROM group_messages gm WHERE gm.group_id = mg.id AND gm.is_deleted = 0) as msg_count 
            FROM message_groups mg 
            JOIN users u ON mg.created_by = u.id 
            ORDER BY mg.created_at DESC" :
            "SELECT mg.*, u.full_name as creator_name,
                   (SELECT COUNT(*) FROM group_messages gm WHERE gm.group_id = mg.id AND gm.is_deleted = 0) as msg_count 
            FROM message_groups mg 
            JOIN users u ON mg.created_by = u.id 
            JOIN group_members gmb ON mg.id = gmb.group_id 
            WHERE gmb.user_id = ? 
            ORDER BY mg.created_at DESC";
            
        $groups = $pdo->prepare($sql);
        if ($is_admin) {
            $groups->execute();
        } else {
            $groups->execute([$current_user_id]);
        }
        $group_list = $groups->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['groups' => $group_list]);
        exit();
    }

    // جلب معلومات مجموعة محددة
    if ($action == 'get_group_info') {
        $group_id = (int)$_GET['group_id'];
        $stmt = $pdo->prepare("SELECT * FROM message_groups WHERE id = ?");
        $stmt->execute([$group_id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($group) {
            // جلب قائمة الأعضاء مع حالاتهم
            $members_stmt = $pdo->prepare("
                SELECT u.id, u.full_name, u.username, u.is_online, u.last_seen 
                FROM group_members gm 
                JOIN users u ON gm.user_id = u.id 
                WHERE gm.group_id = ?
            ");
            $members_stmt->execute([$group_id]);
            $members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $online_count = 0;
            $total_count = count($members);
            $now = new DateTime();
            
            foreach ($members as $m) {
                $lastSeen = new DateTime($m['last_seen']);
                $diff = $now->getTimestamp() - $lastSeen->getTimestamp();
                if ($m['is_online'] == 1 && $diff < 300) { // 5 minutes
                    $online_count++;
                }
            }
            
            $group['members'] = array_column($members, 'id');
            $group['total_members'] = $total_count;
            $group['online_members'] = $online_count;
            
            echo json_encode(['status' => 'success', 'group' => $group]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'المجموعة غير موجودة']);
        }
        exit();
    }

    // تحديث مجموعة (للمدير أو منشئ المجموعة)
    if ($action == 'update_group') {
        $group_id = (int)$_POST['group_id'];
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $members = $_POST['members'] ?? [];

        // التحقق من الصلاحيات
        $check = $pdo->prepare("SELECT created_by FROM message_groups WHERE id = ?");
        $check->execute([$group_id]);
        $creator_id = $check->fetchColumn();

        if (!$is_admin && $creator_id != $current_user_id) {
            echo json_encode(['status' => 'error', 'message' => 'ليس لديك صلاحية لتعديل هذه المجموعة']);
            exit();
        }

        if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'اسم المجموعة مطلوب']);
            exit();
        }

        // تحديث البيانات الأساسية
        $stmt = $pdo->prepare("UPDATE message_groups SET name = ?, description = ? WHERE id = ?");
        $stmt->execute([$name, $description, $group_id]);

        // تحديث الأعضاء: حذف الكل ثم إعادة الإضافة (أو يمكن استخدام مقارنة المصفوفات)
        $pdo->prepare("DELETE FROM group_members WHERE group_id = ?")->execute([$group_id]);
        
        if (!empty($members) && is_array($members)) {
            $member_stmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
            foreach ($members as $member_id) {
                $member_stmt->execute([$group_id, (int)$member_id]);
            }
        }
        
        // التأكد من بقاء المنشئ كعضو
        $pdo->prepare("INSERT IGNORE INTO group_members (group_id, user_id) VALUES (?, ?)")->execute([$group_id, $creator_id]);

        echo json_encode(['status' => 'success']);
        exit();
    }

    // حذف مجموعة
    if ($action == 'delete_group') {
        if (!$is_admin) {
            echo json_encode(['status' => 'error', 'message' => 'فقط المسؤول يمكنه حذف المجموعات']);
            exit();
        }
        $group_id = (int)$_POST['group_id'];
        $pdo->prepare("DELETE FROM message_groups WHERE id = ?")->execute([$group_id]);
        $pdo->prepare("DELETE FROM group_members WHERE group_id = ?")->execute([$group_id]);
        $pdo->prepare("DELETE FROM group_messages WHERE group_id = ?")->execute([$group_id]);
        echo json_encode(['status' => 'success']);
        exit();
    }

    // إنشاء مجموعة جديدة (للمدير والمطور فقط)
    if ($action == 'create_group') {
        if (!$is_admin) {
            echo json_encode(['status' => 'error', 'message' => 'ليس لديك صلاحية لإنشاء مجموعات']);
            exit();
        }

        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $members = $_POST['members'] ?? [];

        if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'اسم المجموعة مطلوب']);
            exit();
        }

        $stmt = $pdo->prepare("INSERT INTO message_groups (name, description, created_by) VALUES (?, ?, ?)");
        $stmt->execute([$name, $description, $current_user_id]);
        $group_id = $pdo->lastInsertId();

        // إضافة الأعضاء
        if (!empty($members) && is_array($members)) {
            $member_stmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
            foreach ($members as $member_id) {
                $member_stmt->execute([$group_id, (int)$member_id]);
            }
        }

        // إضافة المنشئ كعضو
        $member_stmt = $pdo->prepare("INSERT IGNORE INTO group_members (group_id, user_id) VALUES (?, ?)");
        $member_stmt->execute([$group_id, $current_user_id]);

        echo json_encode(['status' => 'success', 'group_id' => $group_id]);
        exit();
    }

    // جلب رسائل المحادثة
    if ($action == 'fetch') {
        $chat_user_id = (int)$_GET['user'];
        $u1 = isset($_GET['u1']) ? (int)$_GET['u1'] : $current_user_id;

        // منع جلب الرسائل من المستخدم لنفسه
        if ($chat_user_id == $current_user_id) {
            echo json_encode(['messages' => []]);
            exit();
        }

        $settings = getSettings($pdo);
        $disappear_seconds = (int)($settings['disappear_after_read_seconds'] ?? 10);

        // تحديث وقت القراءة للرسائل التي استلمتها أنا الآن ولم يتم تسجيل وقت قراءتها
        if ($u1 == $current_user_id) {
            $pdo->prepare("UPDATE internal_messages SET is_read = 1, read_at = NOW() WHERE receiver_id = ? AND sender_id = ? AND is_read = 0")->execute([$current_user_id, $chat_user_id]);
            // إصلاح الرسائل القديمة التي قُرأت ولكن ليس لها وقت قراءة
            $pdo->prepare("UPDATE internal_messages SET read_at = created_at WHERE receiver_id = ? AND sender_id = ? AND is_read = 1 AND read_at IS NULL")->execute([$current_user_id, $chat_user_id]);
        }

        // تحديث حالة "الاختفاء" لجميع الرسائل المقروءة في هذه المحادثة (المرسلة والمستلمة)
        // يتم وضع العلامة دائماً إذا كان الخيار مفعلاً، ولكن الفلترة تتم في الاستعلام اللاحق بناءً على رتبة المستخدم
        if ($settings && $settings['auto_delete_messages']) {
            $pdo->prepare("
                UPDATE internal_messages 
                SET is_disappeared = 1 
                WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
                AND is_read = 1 AND read_at IS NOT NULL
                AND (NOW() > DATE_ADD(read_at, INTERVAL $disappear_seconds SECOND))
            ")->execute([$current_user_id, $chat_user_id, $chat_user_id, $current_user_id]);
        }

        $where_clause = "WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))";
        $params = [$u1, $chat_user_id, $chat_user_id, $u1];

        // إذا لم يكن المستخدم مديراً، قم بإخفاء الرسائل المختفية
        if (!$is_admin) {
            $where_clause .= " AND (is_disappeared = 0 OR is_disappeared IS NULL)";
        }

        // تصفية الرسائل المحذوفة مع مراعاة القيم NULL
        $where_clause .= " AND (is_deleted_for_all = 0 OR is_deleted_for_all IS NULL) AND (
            (sender_id = ? AND (is_deleted_by_sender = 0 OR is_deleted_by_sender IS NULL)) OR 
            (receiver_id = ? AND (is_deleted_by_receiver = 0 OR is_deleted_by_receiver IS NULL))
        )";
        $params[] = $current_user_id;
        $params[] = $current_user_id;

        $msg_stmt = $pdo->prepare("SELECT * FROM internal_messages $where_clause ORDER BY created_at ASC");
        $msg_stmt->execute($params);
        $messages = $msg_stmt->fetchAll();

        $output = [];
        foreach ($messages as $msg) {
            // ملاحظة: لا يوجد حقل is_deleted في internal_messages، نستخدم الحقول الأخرى المفلترة في SQL
            
            $sender_info = $pdo->prepare("SELECT username, full_name, profile_image FROM users WHERE id = ?");
            $sender_info->execute([$msg['sender_id']]);
            $sender = $sender_info->fetch();
            
            $display_name = (!empty($sender['full_name'])) ? $sender['full_name'] : $sender['username'];

            $output[] = [
                'id' => $msg['id'],
                'sender_id' => $msg['sender_id'],
                'message' => $msg['message'],
                'image_path' => $msg['image_path'],
                'is_read' => $msg['is_read'],
                'is_edited' => $msg['is_edited'],
                'created_at' => $msg['created_at'],
                'sender_name' => $display_name,
                'sender_image' => $sender['profile_image'],
                'is_own' => ($msg['sender_id'] == $current_user_id)
            ];
        }

        echo json_encode(['messages' => $output]);
        exit();
    }

    // تعديل رسالة مجموعة
    if ($action == 'edit_group_message') {
        $message_id = (int)$_POST['message_id'];
        $new_message = $_POST['message'] ?? '';

        if ($message_id > 0 && !empty($new_message)) {
            $check = $pdo->prepare("SELECT sender_id, created_at FROM group_messages WHERE id = ?");
            $check->execute([$message_id]);
            $msg = $check->fetch();

            if ($msg && $msg['sender_id'] == $current_user_id) {
                // التحقق من الوقت المسموح للتعديل
                $settings = getSettings($pdo);
                $time_limit = $settings['edit_delete_time_limit'] ?? 5;
                $msg_time = new DateTime($msg['created_at']);
                $now = new DateTime();
                $diff = $now->getTimestamp() - $msg_time->getTimestamp();

                if ($diff <= ($time_limit * 60)) {
                    // حفظ الرسالة الأصلية قبل التعديل لأول مرة
                    $original_check = $pdo->prepare("SELECT original_message FROM group_messages WHERE id = ?");
                    $original_check->execute([$message_id]);
                    if (empty($original_check->fetchColumn())) {
                        $current_msg = $pdo->prepare("SELECT message FROM group_messages WHERE id = ?");
                        $current_msg->execute([$message_id]);
                        $old_text = $current_msg->fetchColumn();
                        $pdo->prepare("UPDATE group_messages SET original_message = ? WHERE id = ?")->execute([$old_text, $message_id]);
                    }

                    $stmt = $pdo->prepare("UPDATE group_messages SET message = ?, is_edited = 1, edited_at = NOW() WHERE id = ?");
                    $stmt->execute([$new_message, $message_id]);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => "عذراً، انتهت مهلة التعديل ($time_limit دقائق)"]);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'غير مصرح لك بتعديل هذه الرسالة']);
            }
        }
        exit();
    }

    // حذف رسالة مجموعة
    if ($action == 'delete_group_message') {
        $message_id = (int)$_POST['message_id'];
        $type = $_POST['type'] ?? 'for_everyone'; 

        if ($message_id > 0) {
            $check = $pdo->prepare("SELECT sender_id, created_at FROM group_messages WHERE id = ?");
            $check->execute([$message_id]);
            $msg = $check->fetch();
            
            if ($msg) {
                if ($type == 'for_me') {
                    echo json_encode(['status' => 'error', 'message' => 'الحذف الشخصي في المجموعات غير متوفر حالياً']);
                } else {
                    if ($msg['sender_id'] == $current_user_id || $is_admin) {
                        // التحقق من الوقت المسموح للحذف (للمستخدم العادي فقط، المدير يستثنى)
                        if (!$is_admin) {
                            $settings = getSettings($pdo);
                            $time_limit = $settings['edit_delete_time_limit'] ?? 5;
                            $msg_time = new DateTime($msg['created_at']);
                            $now = new DateTime();
                            $diff = $now->getTimestamp() - $msg_time->getTimestamp();

                            if ($diff > ($time_limit * 60)) {
                                echo json_encode(['status' => 'error', 'message' => "عذراً، انتهت مهلة الحذف ($time_limit دقائق)"]);
                                exit();
                            }
                        }

                        $stmt = $pdo->prepare("UPDATE group_messages SET is_deleted = 1 WHERE id = ?");
                        $stmt->execute([$message_id]);
                        echo json_encode(['status' => 'success']);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'غير مصرح لك بحذف هذه الرسالة']);
                    }
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'الرسالة غير موجودة']);
            }
        }
        exit();
    }

    // تعديل رسالة
    if ($action == 'edit_message') {
        $message_id = (int)$_POST['message_id'];
        $new_message = $_POST['message'] ?? '';

        if ($message_id > 0 && !empty($new_message)) {
            $check = $pdo->prepare("SELECT sender_id, created_at FROM internal_messages WHERE id = ?");
            $check->execute([$message_id]);
            $msg = $check->fetch();

            if ($msg && $msg['sender_id'] == $current_user_id) {
                // التحقق من الوقت المسموح للتعديل
                $settings = getSettings($pdo);
                $time_limit = $settings['edit_delete_time_limit'] ?? 5;
                $msg_time = new DateTime($msg['created_at']);
                $now = new DateTime();
                $diff = $now->getTimestamp() - $msg_time->getTimestamp();

                if ($diff <= ($time_limit * 60)) {
                    // حفظ الرسالة الأصلية
                    $original_check = $pdo->prepare("SELECT original_message FROM internal_messages WHERE id = ?");
                    $original_check->execute([$message_id]);
                    if (empty($original_check->fetchColumn())) {
                        $current_msg = $pdo->prepare("SELECT message FROM internal_messages WHERE id = ?");
                        $current_msg->execute([$message_id]);
                        $old_text = $current_msg->fetchColumn();
                        $pdo->prepare("UPDATE internal_messages SET original_message = ? WHERE id = ?")->execute([$old_text, $message_id]);
                    }

                    $stmt = $pdo->prepare("UPDATE internal_messages SET message = ?, is_edited = 1, edited_at = NOW() WHERE id = ?");
                    $stmt->execute([$new_message, $message_id]);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => "عذراً، انتهت مهلة التعديل ($time_limit دقائق)"]);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'غير مصرح لك بتعديل هذه الرسالة']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'بيانات ناقصة']);
        }
        exit();
    }

    // حذف رسالة
    if ($action == 'delete_message') {
        $message_id = (int)$_POST['message_id'];
        $type = $_POST['type'] ?? 'for_me'; 

        if ($message_id > 0) {
            $check = $pdo->prepare("SELECT sender_id, receiver_id, created_at FROM internal_messages WHERE id = ?");
            $check->execute([$message_id]);
            $msg = $check->fetch();
            
            if ($msg) {
                if ($type == 'for_everyone') {
                    if ($msg['sender_id'] == $current_user_id) {
                        // التحقق من الوقت المسموح للحذف لدى الجميع
                        $settings = getSettings($pdo);
                        $time_limit = $settings['edit_delete_time_limit'] ?? 5;
                        $msg_time = new DateTime($msg['created_at']);
                        $now = new DateTime();
                        $diff = $now->getTimestamp() - $msg_time->getTimestamp();

                        if ($diff <= ($time_limit * 60)) {
                            $stmt = $pdo->prepare("UPDATE internal_messages SET is_deleted_for_all = 1 WHERE id = ?");
                            $stmt->execute([$message_id]);
                            echo json_encode(['status' => 'success']);
                        } else {
                            echo json_encode(['status' => 'error', 'message' => "عذراً، انتهت مهلة الحذف لدى الجميع ($time_limit دقائق)"]);
                        }
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'لا يمكنك حذف هذه الرسالة للطرفين']);
                    }
                } else {
                    // حذف لدي (مسموح دائماً)
                    if ($msg['sender_id'] == $current_user_id) {
                        $stmt = $pdo->prepare("UPDATE internal_messages SET is_deleted_by_sender = 1 WHERE id = ?");
                        $stmt->execute([$message_id]);
                    } else if ($msg['receiver_id'] == $current_user_id) {
                        $stmt = $pdo->prepare("UPDATE internal_messages SET is_deleted_by_receiver = 1 WHERE id = ?");
                        $stmt->execute([$message_id]);
                    }
                    echo json_encode(['status' => 'success']);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'الرسالة غير موجودة']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'بيانات ناقصة']);
        }
        exit();
    }

    // جلب رسائل المجموعة
    if ($action == 'fetch_group') {
        $group_id = (int)$_GET['group'];

        // التحقق من أن المستخدم عضو في المجموعة
        if (!$is_admin) {
            $member_check = $pdo->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
            $member_check->execute([$group_id, $current_user_id]);
            if (!$member_check->fetch()) {
                echo json_encode(['messages' => []]);
                exit();
            }
        }

        $msg_stmt = $pdo->prepare("SELECT * FROM group_messages WHERE group_id = ? AND is_deleted = 0 ORDER BY created_at ASC");
        $msg_stmt->execute([$group_id]);
        $messages = $msg_stmt->fetchAll();

        $output = [];
        foreach ($messages as $msg) {
            $sender_info = $pdo->prepare("SELECT username, full_name, profile_image FROM users WHERE id = ?");
            $sender_info->execute([$msg['sender_id']]);
            $sender = $sender_info->fetch();
            
            $display_name = (!empty($sender['full_name'])) ? $sender['full_name'] : $sender['username'];

            $output[] = [
                'id' => $msg['id'],
                'sender_id' => $msg['sender_id'],
                'message' => $msg['message'],
                'image_path' => $msg['image_path'],
                'created_at' => $msg['created_at'],
                'sender_name' => $display_name,
                'sender_image' => $sender['profile_image'],
                'is_own' => ($msg['sender_id'] == $current_user_id),
                'is_edited' => $msg['is_edited'] ?? 0
            ];
        }

        echo json_encode(['messages' => $output]);
        exit();
    }

    // جلب عدد الرسائل غير المقروءة (للهيدر والفوتر)
    if ($action == 'get_unread_count' || $action == 'get_unread_counts') {
        $unread_stmt = $pdo->prepare("SELECT COUNT(*) as unread_count FROM internal_messages WHERE receiver_id = ? AND is_read = 0");
        $unread_stmt->execute([$current_user_id]);
        $unread_count = $unread_stmt->fetchColumn();
        
        if ($action == 'get_unread_counts') {
            echo json_encode([
                'status' => 'success',
                'counts' => [
                    'internal' => (int)$unread_count
                ]
            ]);
        } else {
            echo json_encode(['status' => 'success', 'unread_count' => (int)$unread_count]);
        }
        exit();
    }

    // جلب آخر رسالة غير مقروءة
    if ($action == 'get_latest_message') {
        $latest_stmt = $pdo->prepare("
            SELECT im.*, u.full_name, u.username
            FROM internal_messages im
            JOIN users u ON im.sender_id = u.id
            WHERE im.receiver_id = ? AND im.is_read = 0
            ORDER BY im.created_at DESC
            LIMIT 1
        ");
        $latest_stmt->execute([$current_user_id]);
        $message = $latest_stmt->fetch();
        if ($message) {
            echo json_encode([
                'status' => 'success',
                'message' => [
                    'id' => $message['id'],
                    'full_name' => $message['full_name'],
                    'username' => $message['username'],
                    'message' => $message['message'],
                    'created_at' => $message['created_at']
                ]
            ]);
        } else {
            echo json_encode(['status' => 'success', 'message' => null]);
        }
        exit();
    }
}

if (!$current_user_id) {
    header('Location: login.php');
    exit();
}

// تحديث حالة الاتصال وآخر ظهور
$pdo->prepare("UPDATE users SET last_seen = NOW(), is_online = 1 WHERE id = ?")->execute([$current_user_id]);

$current_user_id = $_SESSION['admin_id'];

require_once 'header.php';

// جلب إعدادات التنبيهات للمستخدم الحالي
$notification_settings = $pdo->prepare("SELECT * FROM notification_settings WHERE user_id = ?");
$notification_settings->execute([$current_user_id]);
$settings = $notification_settings->fetch();
?>

<div class="container-fluid chat-app-container">
    <div class="row h-100 g-0">
        <!-- القائمة الجانبية -->
        <div id="sidePanel" class="col-md-3 border-end chat-sidebar">
            <div class="p-3">
                <!-- علامات التبويب (تم دمجها في قائمة واحدة) -->
                <div class="mb-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0"><i class="fas fa-comments me-2"></i> المحادثات</h5>
                    <?php if ($is_admin): ?>
                        <button class="btn btn-sm btn-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#createGroupModal">
                            <i class="fas fa-plus me-1"></i> مجموعة
                        </button>
                    <?php endif; ?>
                </div>

                <!-- محتوى القائمة الموحدة -->
                <div id="usersList" class="list-group shadow-sm rounded"></div>

                <!-- إعدادات التنبيهات -->
                <div class="mt-3 p-3 bg-light rounded">
                    <h6 class="fw-bold mb-2"><i class="fas fa-bell me-1"></i> التنبيهات</h6>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="soundToggle" <?php echo $settings['sound_enabled'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="soundToggle">صوت التنبيه</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="notificationToggle" <?php echo $settings['notification_enabled'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="notificationToggle">التنبيهات</label>
                    </div>
                </div>
            </div>
        </div>

        <!-- منطقة المحادثة -->
        <div id="mainChatPanel" class="col-md-9 d-flex flex-column chat-main-panel">
            <!-- رأس المحادثة -->
            <div class="p-3 border-bottom bg-light d-flex align-items-center">
                <button id="backToListBtn" class="btn btn-link p-0 me-3 d-md-none text-dark text-decoration-none">
                    <i class="fas fa-arrow-right fa-lg"></i>
                </button>
                <h5 id="chatTitle" class="mb-0 flex-grow-1">اختر محادثة للبدء</h5>
            </div>

            <!-- منطقة الرسائل -->
            <div id="messagesContainer" class="p-3">
                <div class="text-center text-muted mt-5">
                    <i class="fas fa-comments fa-3x mb-2"></i>
                    <p>اختر محادثة من القائمة الجانبية</p>
                </div>
            </div>

            <!-- صندوق الكتابة والإرسال -->
            <div class="p-3 border-top bg-white">
                <form id="messageForm" class="d-flex gap-2">
                    <div class="flex-grow-1 position-relative">
                        <textarea id="messageInput" class="form-control rounded-pill" placeholder="اكتب رسالتك هنا..." rows="1" style="resize: none; padding-right: 45px;"></textarea>
                        <label for="imageUpload" class="position-absolute" style="right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #007bff;">
                            <i class="fas fa-image fa-lg"></i>
                            <input type="file" id="imageUpload" class="d-none" accept="image/*">
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary rounded-pill px-4" style="height: 40px; display: flex; align-items: center;">
                        <i class="fas fa-paper-plane me-1"></i> إرسال
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal لإنشاء مجموعة جديدة -->
<div class="modal fade" id="createGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إنشاء مجموعة جديدة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">اسم المجموعة</label>
                    <input type="text" id="groupName" class="form-control" placeholder="أدخل اسم المجموعة">
                </div>
                <div class="mb-3">
                    <label class="form-label">الوصف (اختياري)</label>
                    <textarea id="groupDescription" class="form-control" placeholder="أدخل وصف المجموعة" rows="3"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">اختر الأعضاء</label>
                    <div id="membersList" class="border rounded p-2" style="max-height: 300px; overflow-y: auto;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-primary" id="createGroupBtn">إنشاء</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal لتعديل المجموعة -->
<div class="modal fade" id="editGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تعديل المجموعة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editGroupId">
                <div class="mb-3">
                    <label class="form-label">اسم المجموعة</label>
                    <input type="text" id="editGroupName" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">الوصف</label>
                    <textarea id="editGroupDescription" class="form-control" rows="3"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">إدارة الأعضاء</label>
                    <div id="editMembersList" class="border rounded p-2" style="max-height: 300px; overflow-y: auto;"></div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-danger" id="deleteGroupBtn">حذف المجموعة</button>
                <div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="button" class="btn btn-primary" id="updateGroupBtn">حفظ التغييرات</button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .justify-content-between {
        justify-content: space-between !important;
    }

    .badge {
        font-size: 0.75rem;
        padding: 0.25em 0.5em;
    }

    .bg-danger {
        background-color: #dc3545 !important;
    }

    .rounded-pill {
        border-radius: 50rem !important;
    }

        .message-bubble {
            position: relative;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05) !important;
            border-radius: 12px !important;
        }
        .msg-own {
            background-color: var(--primary-color) !important;
            color: white !important;
            border: none !important;
        }
        .msg-other {
            background-color: #ffffff !important;
            color: #212529 !important;
            border: 1px solid #dee2e6 !important;
        }
        body.theme-dark .msg-other {
            background-color: #1e2d45 !important;
            color: #f1f5f9 !important;
            border-color: #334155 !important;
        }

        /* Dark Mode Overrides */
        body.theme-dark .chat-app-container { background-color: #0b1120 !important; }
        body.theme-dark .chat-sidebar { background-color: #0b1120 !important; border-color: #1e2d45 !important; }
        body.theme-dark .chat-main-panel { background-color: #0b1120 !important; }
        body.theme-dark .list-group-item { background-color: transparent; border-color: #1e2d45; color: #e2e8f0; }
        body.theme-dark .list-group-item:hover { background-color: #1e2d45; color: #fff; }
        body.theme-dark .list-group-item.active { background-color: var(--primary-color) !important; border-color: var(--primary-color) !important; }
        body.theme-dark .chat-sidebar .bg-light { background-color: #111827 !important; color: #e2e8f0 !important; }
        body.theme-dark .chat-main-panel .bg-light { background-color: #111827 !important; border-color: #1e2d45 !important; color: #e2e8f0 !important; }
        body.theme-dark #messagesContainer { background-color: #0b1120 !important; }
        body.theme-dark .bg-white { background-color: #111827 !important; border-color: #1e2d45 !important; color: #e2e8f0 !important; }
        body.theme-dark .chat-main-panel > .border-top.bg-white { background-color: #111827 !important; border-color: #1e2d45 !important; }
        body.theme-dark #messageForm .bg-white { background-color: transparent !important; }
        body.theme-dark .form-control { background-color: #1e2d45; border-color: #334155; color: #f1f5f9; }
        body.theme-dark .form-control::placeholder { color: #64748b; }
        body.theme-dark .text-muted { color: #94a3b8 !important; }
        body.theme-dark .text-dark { color: #f1f5f9 !important; }
        body.theme-dark .modal-content { background-color: #111827; color: #f1f5f9; border-color: #1e2d45; }
        body.theme-dark .modal-header, body.theme-dark .modal-footer { border-color: #1e2d45; }
        body.theme-dark .message-actions .btn-link { background: rgba(255,255,255,0.05); color: #94a3b8 !important; }
        body.theme-dark .message-actions .btn-link:hover { background: rgba(255,255,255,0.1); color: #fff !important; }
        body.theme-dark .dropdown-menu { background-color: #1e293b; border: 1px solid #334155; }
        body.theme-dark .dropdown-item { color: #f1f5f9; }
        body.theme-dark .dropdown-item:hover { background-color: #334155; }
        
        /* Mobile Dark Mode */
        @media (max-width: 767.98px) {
            body.theme-dark .chat-main-panel { background-color: #0b1120; }
            body.theme-dark .chat-main-panel > div:last-child { background-color: #111827 !important; border-color: #1e2d45 !important; }
        }

        .message-wrapper {
        position: relative;
        max-width: 75%;
        display: flex;
        align-items: flex-start;
    }

    .justify-content-end .message-wrapper {
        flex-direction: row-reverse;
    }

    .message-actions {
        opacity: 0;
        transition: all 0.2s ease;
        visibility: hidden;
        margin: 0 5px;
    }

    .mb-3:hover .message-actions {
        opacity: 1;
        visibility: visible;
    }

    .message-actions .btn-link {
        color: #999 !important;
        width: 28px;
        height: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: rgba(0,0,0,0.03);
        text-decoration: none;
    }

    .message-actions .btn-link:hover {
        background: rgba(0,0,0,0.08);
        color: #666 !important;
    }

    .dropdown-menu {
        font-size: 0.85rem;
        border: none;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        border-radius: 10px;
        padding: 5px;
    }

    .dropdown-item {
        border-radius: 6px;
        padding: 6px 12px;
        transition: all 0.2s;
    }

    .dropdown-item:hover {
        background-color: #f8f9fa;
    }

    .dropdown-item.text-danger:hover {
        background-color: #fff5f5;
    }

    .message-bubble::after {
        content: "";
        position: absolute;
        width: 0;
        height: 0;
        border-top: 10px solid transparent;
        border-bottom: 10px solid transparent;
    }

    .justify-content-start .message-bubble::after {
        left: -10px;
        top: 10px;
        border-right: 10px solid #ffffff;
    }

    .justify-content-end .message-bubble::after {
        right: -10px;
        top: 10px;
        border-left: 10px solid #007bff;
    }

    #messagesContainer::-webkit-scrollbar {
        width: 6px;
    }

    #messagesContainer::-webkit-scrollbar-thumb {
        background-color: #ccc;
        border-radius: 10px;
    }

    .list-group-item.active {
        background-color: #007bff !important;
        border-color: #007bff !important;
    }

    .chat-sidebar, .chat-main-panel {
        height: calc(100vh - 100px);
        display: flex;
        flex-direction: column;
    }

    .chat-sidebar {
        overflow-y: auto;
    }

    #messagesContainer {
        flex-grow: 1;
        overflow-y: auto;
    }

    /* تحسينات الهواتف */
    @media (max-width: 767.98px) {
        .chat-app-container {
            padding: 0;
            margin: 0;
            height: calc(100vh - 56px);
            overflow: hidden;
        }

        .chat-sidebar {
            width: 100% !important;
            height: 100% !important;
            max-height: none !important;
            display: block;
            border: none !important;
            overflow-y: auto;
        }

        .chat-main-panel {
            width: 100% !important;
            max-height: none !important;
            display: none !important;
            position: fixed;
            top: 56px;
            left: 0;
            right: 0;
            bottom: 0 !important;
            z-index: 1050;
            background: white;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-main-panel.show-mobile {
            display: flex !important;
        }

        .message-wrapper {
            max-width: 90%;
        }

        .message-bubble {
            padding: 8px 12px !important;
            font-size: 0.85rem;
            border-radius: 12px !important;
        }

        #messagesContainer {
            padding: 10px !important;
            flex-grow: 1;
            overflow-y: auto;
            height: 0;
        }

        .chat-sidebar.hide-mobile {
            display: none !important;
        }

        /* تنسيق منطقة الإرسال في الموبايل */
        .chat-main-panel > div:last-child {
            padding: 10px !important;
            background: white;
            border-top: 1px solid #dee2e6;
            margin-bottom: 0 !important;
            position: sticky;
            bottom: 0;
            z-index: 1060;
            flex-shrink: 0;
        }

        #messageForm {
            display: flex !important;
            gap: 5px !important;
            width: 100%;
        }

        #messageInput {
            font-size: 0.9rem;
            padding: 10px 45px 10px 15px !important;
            width: 100%;
            border-radius: 20px !important;
        }

        #messageForm button[type="submit"] {
            padding: 0 15px !important;
            font-size: 0.85rem;
            flex-shrink: 0;
            height: 40px;
            display: flex !important;
            align-items: center;
            justify-content: center;
        }

        #chatTitle {
            font-size: 1rem;
        }

        #backToListBtn {
            margin-right: 10px !important;
        }
    }
</style>

    <script>
        let currentChatType = null; // 'user' or 'group'
        let currentChatId = null;
        let messageRefreshInterval = null;

        // تحميل قائمة المستخدمين والمجموعات
        async function loadUsers() {
            console.log('Loading users and groups...');
            try {
                // جلب المجموعات أولاً
                const groupsRes = await fetch('internal_messages.php?action=get_groups');
                const groupsData = await groupsRes.json();
                
                // جلب المستخدمين
                const usersRes = await fetch('internal_messages.php?action=get_users');
                const usersData = await usersRes.json();
                
                const usersList = document.getElementById('usersList');
                usersList.innerHTML = '';
                
                // عرض المجموعات أولاً
                if (groupsData.groups && groupsData.groups.length > 0) {
                    const groupHeader = document.createElement('div');
                    groupHeader.className = 'p-2 bg-light small fw-bold text-muted border-bottom';
                    groupHeader.innerHTML = '<i class="fas fa-users me-1"></i> المجموعات';
                    usersList.appendChild(groupHeader);
                    
                    groupsData.groups.forEach(group => {
                        const groupEl = document.createElement('a');
                        groupEl.href = '#';
                        groupEl.className = 'list-group-item list-group-item-action d-flex align-items-center justify-content-between';
                        if (currentChatType === 'group' && currentChatId == group.id) {
                            groupEl.classList.add('active');
                        }
                        
                        groupEl.innerHTML = `
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle me-2 bg-primary d-flex align-items-center justify-content-center text-white" style="width: 40px; height: 40px;">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="ms-1">
                                    <div class="fw-bold">${group.name}</div>
                                    <small class="${currentChatId == group.id && currentChatType === 'group' ? 'text-white-50' : 'text-muted'}">
                                        بواسطة ${group.creator_name} • ${group.msg_count} رسالة
                                    </small>
                                </div>
                            </div>
                        `;
                        
                        groupEl.onclick = (e) => {
                            e.preventDefault();
                            openChat('group', group.id, group.name);
                        };
                        usersList.appendChild(groupEl);
                    });
                }
                
                // عرض الأفراد
                const userHeader = document.createElement('div');
                userHeader.className = 'p-2 bg-light small fw-bold text-muted border-bottom mt-2';
                userHeader.innerHTML = '<i class="fas fa-user me-1"></i> الأفراد';
                usersList.appendChild(userHeader);
                
                if (usersData.users) {
                    usersData.users.forEach(user => {
                        const userEl = document.createElement('a');
                        userEl.href = '#';
                        userEl.className = 'list-group-item list-group-item-action d-flex align-items-center justify-content-between';
                        if (currentChatType === 'user' && currentChatId == user.id) {
                            userEl.classList.add('active');
                        }
                        
                        const displayName = user.full_name && user.full_name.trim() !== '' ? user.full_name : user.username;
                        const profileImg = user.profile_image ? `../assets/uploads/profiles/${user.profile_image}` : null;
                        
                        const lastSeenDate = new Date(user.last_seen);
                        const now = new Date();
                        const diffMinutes = Math.floor((now - lastSeenDate) / 1000 / 60);
                        const isOnline = user.is_online == 1 && diffMinutes < 5;
                        
                        const imgHtml = profileImg ? 
                            `<div class="position-relative">
                                <img src="${profileImg}" class="rounded-circle me-2" width="40" height="40" style="object-fit: cover;">
                                ${isOnline ? '<span class="position-absolute bottom-0 end-0 border border-white rounded-circle bg-success" style="width: 12px; height: 12px; margin-right: 5px;"></span>' : ''}
                            </div>` :
                            `<div class="position-relative">
                                <div class="rounded-circle me-2 bg-secondary d-flex align-items-center justify-content-center text-white" style="width: 40px; height: 40px;"><i class="fas fa-user"></i></div>
                                ${isOnline ? '<span class="position-absolute bottom-0 end-0 border border-white rounded-circle bg-success" style="width: 12px; height: 12px; margin-right: 5px;"></span>' : ''}
                            </div>`;

                        const unreadBadge = user.unread_count > 0 ? `<span class="badge bg-danger rounded-pill">${user.unread_count}</span>` : '';
                        
                        let lastSeenText = '';
                        if (isOnline) {
                            lastSeenText = '<span class="text-success small">متصل الآن</span>';
                        } else {
                            if (isNaN(lastSeenDate.getTime())) {
                                lastSeenText = '';
                            } else if (diffMinutes < 60) {
                                lastSeenText = `<span class="small text-muted">آخر ظهور قبل ${diffMinutes} د</span>`;
                            } else if (diffMinutes < 1440) {
                                lastSeenText = `<span class="small text-muted">آخر ظهور قبل ${Math.floor(diffMinutes/60)} س</span>`;
                            } else {
                                lastSeenText = `<span class="small text-muted">${lastSeenDate.toLocaleDateString('ar-SA')}</span>`;
                            }
                        }

                        userEl.innerHTML = `
                            <div class="d-flex align-items-center">
                                ${imgHtml}
                                <div class="ms-1">
                                    <div class="fw-bold">${displayName}</div>
                                    <div class="d-flex align-items-center gap-2">
                                        <small class="${currentChatId == user.id && currentChatType === 'user' ? 'text-white-50' : 'text-muted'}">@${user.username}</small>
                                        ${lastSeenText}
                                    </div>
                                </div>
                            </div>
                            ${unreadBadge}
                        `;
                        
                        userEl.onclick = (e) => {
                            e.preventDefault();
                            openChat('user', user.id, displayName, user.profile_image, isOnline, user.last_seen);
                        };
                        usersList.appendChild(userEl);
                    });
                }
            } catch (error) {
                console.error('Error loading chats:', error);
            }
        }

        // تحميل قائمة المجموعات (تم دمجها مع loadUsers)
        function loadGroups() {
            // يمكن تركها فارغة أو حذفها من الأماكن الأخرى
            loadUsers();
        }

        // فتح نافذة تعديل المجموعة
        function openEditGroupModal(groupId) {
            fetch(`internal_messages.php?action=get_group_info&group_id=${groupId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        const group = data.group;
                        document.getElementById('editGroupId').value = group.id;
                        document.getElementById('editGroupName').value = group.name;
                        document.getElementById('editGroupDescription').value = group.description;
                        
                        // جلب قائمة المستخدمين لاختيار الأعضاء
                        fetch('internal_messages.php?action=get_users')
                            .then(r => r.json())
                            .then(userData => {
                                const editMembersList = document.getElementById('editMembersList');
                                editMembersList.innerHTML = '';
                                userData.users.forEach(user => {
                                    const isMember = group.members.includes(user.id.toString()) || group.members.includes(parseInt(user.id));
                                    const div = document.createElement('div');
                                    div.className = 'form-check mb-2';
                                    div.innerHTML = `
                                        <input class="form-check-input edit-member-checkbox" type="checkbox" value="${user.id}" id="edit_user_${user.id}" ${isMember ? 'checked' : ''}>
                                        <label class="form-check-label" for="edit_user_${user.id}">
                                            ${user.full_name} (@${user.username})
                                        </label>
                                    `;
                                    editMembersList.appendChild(div);
                                });
                                
                                const modal = new bootstrap.Modal(document.getElementById('editGroupModal'));
                                modal.show();
                            });
                    }
                });
        }

        // تحديث المجموعة
        document.getElementById('updateGroupBtn').addEventListener('click', function() {
            const groupId = document.getElementById('editGroupId').value;
            const name = document.getElementById('editGroupName').value;
            const description = document.getElementById('editGroupDescription').value;
            const selectedMembers = Array.from(document.querySelectorAll('.edit-member-checkbox:checked')).map(cb => cb.value);

            if (!name) {
                alert('اسم المجموعة مطلوب');
                return;
            }

            const formData = new FormData();
            formData.append('group_id', groupId);
            formData.append('name', name);
            formData.append('description', description);
            selectedMembers.forEach(id => formData.append('members[]', id));

            fetch('internal_messages.php?action=update_group', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    bootstrap.Modal.getInstance(document.getElementById('editGroupModal')).hide();
                    loadUsers();
                    // تحديث عنوان الدردشة إذا كانت هي المفتوحة
                    if (currentChatType === 'group' && currentChatId == groupId) {
                        document.querySelector('#chatTitle span.fw-bold').innerText = name;
                    }
                } else {
                    alert(data.message);
                }
            });
        });

        // حذف المجموعة
        document.getElementById('deleteGroupBtn').addEventListener('click', function() {
            const groupId = document.getElementById('editGroupId').value;
            if (confirm('هل أنت متأكد من حذف هذه المجموعة وجميع رسائلها؟ لا يمكن التراجع عن هذا الإجراء.')) {
                const formData = new FormData();
                formData.append('group_id', groupId);
                
                fetch('internal_messages.php?action=delete_group', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        bootstrap.Modal.getInstance(document.getElementById('editGroupModal')).hide();
                        loadUsers();
                        if (currentChatType === 'group' && currentChatId == groupId) {
                            currentChatId = null;
                            document.getElementById('chatTitle').innerText = 'اختر محادثة للبدء';
                            document.getElementById('messagesContainer').innerHTML = '<div class="text-center text-muted mt-5"><p>اختر محادثة من القائمة الجانبية</p></div>';
                        }
                    } else {
                        alert(data.message);
                    }
                });
            }
        });

        // فتح محادثة
        function openChat(type, id, name, image = null, isOnline = false, lastSeen = null) {
            currentChatType = type;
            currentChatId = id;
            
            // في الموبايل: إخفاء القائمة وإظهار المحادثة
            if (window.innerWidth < 768) {
                document.getElementById('sidePanel').classList.add('hide-mobile');
                document.getElementById('mainChatPanel').classList.add('show-mobile');
            }

            const chatTitle = document.getElementById('chatTitle');
            if (type === 'user') {
                const imgHtml = image ? 
                    `<img src="../assets/uploads/profiles/${image}" class="rounded-circle me-2" width="35" height="35" style="object-fit: cover;">` :
                    `<div class="rounded-circle me-2 bg-secondary d-flex align-items-center justify-content-center text-white" style="width: 35px; height: 35px;"><i class="fas fa-user fa-sm"></i></div>`;
                
                let subText = isOnline ? '<span class="text-success small">متصل الآن</span>' : '';
                if (!isOnline && lastSeen) {
                    const lsDate = new Date(lastSeen);
                    subText = `<span class="text-muted small">آخر ظهور: ${lsDate.toLocaleString('ar-SA')}</span>`;
                }

                chatTitle.innerHTML = `
                    <div class="d-flex align-items-center">
                        ${imgHtml}
                        <div class="d-flex flex-column">
                            <span class="fw-bold">${name}</span>
                            ${subText}
                        </div>
                    </div>
                `;
            } else {
                chatTitle.innerHTML = `
                    <div class="d-flex align-items-center justify-content-between w-100">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-users me-2 text-primary fa-lg"></i> 
                            <div class="d-flex flex-column">
                                <span class="fw-bold">${name}</span>
                                <small id="groupMemberStatus" class="text-muted small" style="font-size: 0.75rem;">جاري التحميل...</small>
                            </div>
                        </div>
                        <?php if ($is_admin): ?>
                        <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="openEditGroupModal(${id})">
                            <i class="fas fa-cog me-1"></i> الإعدادات
                        </button>
                        <?php endif; ?>
                    </div>
                `;
                updateGroupHeaderStatus(id);
            }

            // تحديث التحديد في القائمة
            document.querySelectorAll('.list-group-item').forEach(item => item.classList.remove('active'));
            
            if (messageRefreshInterval) clearInterval(messageRefreshInterval);
            loadMessages();
            messageRefreshInterval = setInterval(loadMessages, 3000);
        }

        // تحديث حالة المجموعة في الرأس (الأعضاء المتصلين والعدد الإجمالي)
        function updateGroupHeaderStatus(groupId) {
            if (currentChatType !== 'group' || currentChatId != groupId) return;
            
            fetch(`internal_messages.php?action=get_group_info&group_id=${groupId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        const statusEl = document.getElementById('groupMemberStatus');
                        if (statusEl) {
                            statusEl.innerHTML = `${data.group.total_members} عضو • <span class="text-success">${data.group.online_members} متصل</span>`;
                        }
                    }
                });
        }

        // تحميل الرسائل
        function loadMessages() {
            if (!currentChatId) return;
            
            // تحديث حالة المجموعة إذا كانت مجموعة
            if (currentChatType === 'group') {
                updateGroupHeaderStatus(currentChatId);
            }

            const url = currentChatType === 'user' ?
                `internal_messages.php?action=fetch&user=${currentChatId}` :
                `internal_messages.php?action=fetch_group&group=${currentChatId}`;

            fetch(url)
                .then(r => r.json())
                .then(data => {
                    const container = document.getElementById('messagesContainer');
                    const isAtBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 100;
                    
                    container.innerHTML = '';

                    if (!data.messages || data.messages.length === 0) {
                        container.innerHTML = '<div class="text-center text-muted mt-5"><p>لا توجد رسائل بعد. ابدأ المحادثة الآن!</p></div>';
                        return;
                    }

                    data.messages.forEach(msg => {
                        const msgEl = document.createElement('div');
                        msgEl.className = `mb-3 d-flex ${msg.is_own ? 'justify-content-end' : 'justify-content-start'}`;
                        
                        const senderImg = msg.sender_image ? 
                            `../assets/uploads/profiles/${msg.sender_image}` : 
                            null;
                        
                        const imgHtml = !msg.is_own ? (senderImg ? 
                            `<img src="${senderImg}" class="rounded-circle me-2" width="30" height="30" style="object-fit: cover;" title="${msg.sender_name}">` :
                            `<div class="rounded-circle me-2 bg-secondary d-flex align-items-center justify-content-center text-white" style="width: 30px; height: 30px;" title="${msg.sender_name}"><i class="fas fa-user fa-xs"></i></div>`) : '';

                        const actionsHtml = `
                            <div class="dropdown message-actions">
                                <button class="btn btn-link btn-sm text-muted p-0" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                    ${msg.is_own ? `<li><a class="dropdown-item py-1 px-3 small" href="#" onclick="editMessage(${msg.id}, '${msg.message.replace(/'/g, "\\'")}'); return false;"><i class="fas fa-edit me-2"></i> تعديل</a></li>` : ''}
                                    <li><a class="dropdown-item py-1 px-3 small" href="#" onclick="deleteMessage(${msg.id}, 'for_me'); return false;"><i class="fas fa-user-times me-2"></i> حذف لدي</a></li>
                                    ${msg.is_own || (currentChatType === 'group' && <?php echo $is_admin ? 'true' : 'false'; ?>) ? `<li><a class="dropdown-item py-1 px-3 small text-danger" href="#" onclick="deleteMessage(${msg.id}, 'for_everyone'); return false;"><i class="fas fa-trash-alt me-2"></i> حذف لدى الجميع</a></li>` : ''}
                                </ul>
                            </div>
                        `;

                        msgEl.innerHTML = `
                            ${!msg.is_own ? imgHtml : ''}
                            <div class="message-wrapper">
                                <div class="message-bubble rounded-3 p-3 ${msg.is_own ? 'msg-own' : 'msg-other'}">
                                    ${currentChatType === 'group' && !msg.is_own ? `<div class="fw-bold small mb-1" style="color: var(--primary-color);">${msg.sender_name}</div>` : ''}
                                    ${msg.image_path ? `<a href="../${msg.image_path}" target="_blank"><img src="../${msg.image_path}" class="rounded mb-2 img-fluid" style="max-height: 250px;"></a>` : ''}
                                    <p class="mb-0" style="word-wrap: break-word; white-space: pre-wrap;">${msg.message}</p>
                                    <div class="d-flex justify-content-between align-items-center mt-1" style="opacity: 0.7; font-size: 0.7rem;">
                                        <div class="d-flex align-items-center gap-1">
                                            <span>${new Date(msg.created_at).toLocaleTimeString('ar-SA', {hour: '2-digit', minute:'2-digit'})}</span>
                                            ${msg.is_edited == 1 ? '<span class="ms-1">(معدلة)</span>' : ''}
                                        </div>
                                        ${msg.is_own ? (msg.is_read == 1 ? '<i class="fas fa-check-double ms-1 text-white"></i>' : '<i class="fas fa-check ms-1"></i>') : ''}
                                    </div>
                                </div>
                                ${actionsHtml}
                            </div>
                        `;
                        container.appendChild(msgEl);
                    });

                    if (isAtBottom) {
                        container.scrollTop = container.scrollHeight;
                    }
                })
                .catch(error => {
                    console.error('Error loading messages:', error);
                    document.getElementById('messagesContainer').innerHTML = '<div class="text-center text-danger mt-5"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><p>حدث خطأ أثناء تحميل الرسائل. يرجى المحاولة مرة أخرى.</p></div>';
                });
        }

        // تعديل رسالة
        function editMessage(id, oldMessage) {
            const newMessage = prompt('تعديل الرسالة:', oldMessage);
            if (newMessage !== null && newMessage.trim() !== '' && newMessage !== oldMessage) {
                const action = currentChatType === 'user' ? 'edit_message' : 'edit_group_message';
                const formData = new FormData();
                formData.append('message_id', id);
                formData.append('message', newMessage);

                fetch(`internal_messages.php?action=${action}`, {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        loadMessages();
                    } else {
                        alert(data.message || 'حدث خطأ أثناء التعديل');
                    }
                });
            }
        }

        // حذف رسالة
        function deleteMessage(id, type = 'for_me') {
            const confirmMsg = type === 'for_everyone' ? 'هل أنت متأكد من حذف هذه الرسالة لدى الجميع؟' : 'هل أنت متأكد من حذف هذه الرسالة لديك فقط؟';
            if (confirm(confirmMsg)) {
                const action = currentChatType === 'user' ? 'delete_message' : 'delete_group_message';
                const formData = new FormData();
                formData.append('message_id', id);
                formData.append('type', type);

                fetch(`internal_messages.php?action=${action}`, {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        loadMessages();
                    } else {
                        alert(data.message || 'حدث خطأ أثناء الحذف');
                    }
                });
            }
        }

        // إرسال رسالة
        document.getElementById('messageForm').addEventListener('submit', function(e) {
            e.preventDefault();

            if (!currentChatId) {
                alert('اختر محادثة أولاً');
                return;
            }

            const formData = new FormData();
            const message = document.getElementById('messageInput').value;
            const image = document.getElementById('imageUpload').files[0];

            if (!message && !image) return;

            formData.append('message', message);
            if (image) formData.append('chat_image', image);

            if (currentChatType === 'user') {
                formData.append('receiver_id', currentChatId);
                fetch('internal_messages.php?action=send', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'success') {
                            document.getElementById('messageInput').value = '';
                            document.getElementById('imageUpload').value = '';
                            loadMessages();
                        }
                    });
            } else {
                formData.append('group_id', currentChatId);
                fetch('internal_messages.php?action=send_group', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'success') {
                            document.getElementById('messageInput').value = '';
                            document.getElementById('imageUpload').value = '';
                            loadMessages();
                        }
                    });
            }
        });

        // تبديل إعدادات التنبيهات
        document.getElementById('soundToggle').addEventListener('change', function() {
            fetch('internal_messages.php?action=toggle_notification', {
                method: 'POST',
                body: new URLSearchParams({
                    type: 'sound',
                    enabled: this.checked ? 1 : 0
                })
            });
        });

        document.getElementById('notificationToggle').addEventListener('change', function() {
            fetch('internal_messages.php?action=toggle_notification', {
                method: 'POST',
                body: new URLSearchParams({
                    type: 'notification',
                    enabled: this.checked ? 1 : 0
                })
            });
        });

        // تحميل البيانات الأولية
        loadUsers();

        // تحديث قائمة المحادثات كل 5 ثوانٍ
        setInterval(loadUsers, 5000);

        // فتح الـ modal لإنشاء مجموعة
        const createGroupModal = document.getElementById('createGroupModal');
        if (createGroupModal) {
            createGroupModal.addEventListener('show.bs.modal', function() {
                fetch('internal_messages.php?action=get_users')
                    .then(r => r.json())
                    .then(data => {
                        const membersList = document.getElementById('membersList');
                        membersList.innerHTML = '';
                        data.users.forEach(user => {
                            const div = document.createElement('div');
                            div.className = 'form-check mb-2';
                            div.innerHTML = `
                                <input class="form-check-input member-checkbox" type="checkbox" value="${user.id}" id="user_${user.id}">
                                <label class="form-check-label" for="user_${user.id}">
                                    ${user.full_name} (@${user.username})
                                </label>
                            `;
                            membersList.appendChild(div);
                        });
                    });
            });
        }

        // إنشاء مجموعة
        const createGroupBtn = document.getElementById('createGroupBtn');
        if (createGroupBtn) {
            createGroupBtn.addEventListener('click', function() {
                const name = document.getElementById('groupName').value;
                const description = document.getElementById('groupDescription').value;
                const selectedMembers = Array.from(document.querySelectorAll('.member-checkbox:checked')).map(cb => cb.value);

                if (!name) {
                    alert('يرجى إدخال اسم المجموعة');
                    return;
                }

                const formData = new FormData();
                formData.append('name', name);
                formData.append('description', description);
                selectedMembers.forEach(id => formData.append('members[]', id));

                fetch('internal_messages.php?action=create_group', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'success') {
                            bootstrap.Modal.getInstance(createGroupModal).hide();
                            loadGroups();
                            // إعادة تعيين النموذج
                            document.getElementById('groupName').value = '';
                            document.getElementById('groupDescription').value = '';
                        } else {
                            alert(data.message || 'حدث خطأ أثناء إنشاء المجموعة');
                        }
                    });
            });
        }

        // زر العودة لقائمة المحادثات في الموبايل
        document.getElementById('backToListBtn').addEventListener('click', function() {
            document.getElementById('sidePanel').classList.remove('hide-mobile');
            document.getElementById('mainChatPanel').classList.remove('show-mobile');
            currentChatId = null;
            if (messageRefreshInterval) clearInterval(messageRefreshInterval);
        });

        // تحسين التعامل مع لوحة المفاتيح في الهواتف
        const messageInput = document.getElementById('messageInput');
        const messagesContainer = document.getElementById('messagesContainer');

        if (window.innerWidth < 768) {
            messageInput.addEventListener('focus', function() {
                setTimeout(() => {
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    // التمرير للعنصر نفسه للتأكد من ظهوره
                    messageInput.scrollIntoView({ behavior: 'smooth', block: 'end' });
                }, 300);
            });

            // مراقبة الـ Visual Viewport للتعامل مع ارتفاع لوحة المفاتيح بشكل أدق
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', () => {
                    if (currentChatId && document.getElementById('mainChatPanel').classList.contains('show-mobile')) {
                        const offset = window.innerHeight - window.visualViewport.height;
                        if (offset > 100) { // لوحة المفاتيح مفتوحة
                            document.getElementById('mainChatPanel').style.bottom = `${offset}px`;
                        } else {
                            document.getElementById('mainChatPanel').style.bottom = '0';
                        }
                        messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    }
                });
            }
        }

        // معالجة تغيير حجم النافذة للتأكد من ظهور العناصر بشكل صحيح
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 768) {
                document.getElementById('sidePanel').classList.remove('hide-mobile');
                document.getElementById('mainChatPanel').classList.remove('show-mobile');
            } else {
                if (currentChatId) {
                    document.getElementById('sidePanel').classList.add('hide-mobile');
                    document.getElementById('mainChatPanel').classList.add('show-mobile');
                }
            }
        });
    </script>

<?php require_once 'footer.php'; ?>
