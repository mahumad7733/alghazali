<?php
declare(strict_types=1);

/** أداة مؤقتة لترقية قاعدة البيانات وتعيين اسم المستخدم «محمد» للمدير الرئيسي. */
const REMOTE_TOKEN_HASH = '3181fa45621602ddfd0ae3ff0b4550c781abccaea25e426597bcda0a30dbec73';

$appRoot = is_file(__DIR__ . '/includes/bootstrap.php') ? __DIR__ : dirname(__DIR__);
require_once $appRoot . '/includes/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
if ($isCli) {
    $password = (string) getenv('SUPER_USER_PASSWORD');
} else {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode((string) file_get_contents('php://input'), true);
    $input = is_array($input) ? $input : $_POST;
    if (!hash_equals(REMOTE_TOKEN_HASH, hash('sha256', (string) ($input['token'] ?? '')))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'رمز التنفيذ غير صالح.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $password = (string) ($input['password'] ?? '');
}

if (strlen($password) < 8) {
    throw new RuntimeException('كلمة المرور غير صالحة.');
}

$pdo = $database->pdo();
try {
    $column = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'username'");
    $column->execute();
    if ((int) $column->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE users ADD COLUMN username VARCHAR(80) NULL UNIQUE AFTER full_name');
    }

    $pdo->beginTransaction();
    $email = 'mohammed@rihla.local';
    $username = 'محمد';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $lookup = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $lookup->execute(['email' => $email]);
    $userId = (int) $lookup->fetchColumn();
    if ($userId > 0) {
        $update = $pdo->prepare('UPDATE users SET full_name = :name, username = :username, password_hash = :password_hash, status = \'active\' WHERE id = :id');
        $update->execute(['name' => $username, 'username' => $username, 'password_hash' => $hash, 'id' => $userId]);
    } else {
        $insert = $pdo->prepare('INSERT INTO users (full_name, username, email, password_hash, status) VALUES (:name, :username, :email, :password_hash, \'active\')');
        $insert->execute(['name' => $username, 'username' => $username, 'email' => $email, 'password_hash' => $hash]);
        $userId = (int) $pdo->lastInsertId();
    }

    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'super_admin' LIMIT 1")->fetchColumn();
    if ($roleId < 1) {
        throw new RuntimeException('دور المدير الرئيسي غير متاح.');
    }
    $role = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id, company_id) VALUES (:user_id, :role_id, NULL)');
    $role->execute(['user_id' => $userId, 'role_id' => $roleId]);
    $pdo->commit();

    $payload = ['success' => true, 'user_id' => $userId, 'username' => $username, 'email' => $email, 'role' => 'super_admin'];
    if ($isCli) {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        @unlink(__FILE__);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($isCli) {
        throw $exception;
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'تعذر تحديث اسم المستخدم.'], JSON_UNESCAPED_UNICODE);
}
