<?php
declare(strict_types=1);

/** تنفيذ لمرة واحدة عبر GET؛ يزيل نفسه فور اكتمال العملية. */
const TOKEN_HASH = '9ae5c4b5eb9ad7c8c9388c277024093a9045a76a0ba519f8de88fdc0aba2771f';
const PASSWORD_HASH = '$2y$10$X3nCCyFt0kcn4IVGYSk1kumSm3AIexGv5z3jMjTw3NpJ.ezEbxwuy';

if (!hash_equals(TOKEN_HASH, hash('sha256', (string) ($_GET['token'] ?? '')))) {
    http_response_code(403);
    exit('غير مصرح.');
}

require_once __DIR__ . '/includes/bootstrap.php';
$pdo = $database->pdo();
try {
    $column = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'username'");
    $column->execute();
    if ((int) $column->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE users ADD COLUMN username VARCHAR(80) NULL UNIQUE AFTER full_name');
    }

    $pdo->beginTransaction();
    $lookup = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $lookup->execute(['email' => 'mohammed@rihla.local']);
    $userId = (int) $lookup->fetchColumn();
    if ($userId > 0) {
        $statement = $pdo->prepare('UPDATE users SET full_name = :name, username = :username, password_hash = :password_hash, status = \'active\' WHERE id = :id');
        $statement->execute(['name' => 'محمد', 'username' => 'محمد', 'password_hash' => PASSWORD_HASH, 'id' => $userId]);
    } else {
        $statement = $pdo->prepare('INSERT INTO users (full_name, username, email, password_hash, status) VALUES (:name, :username, :email, :password_hash, \'active\')');
        $statement->execute(['name' => 'محمد', 'username' => 'محمد', 'email' => 'mohammed@rihla.local', 'password_hash' => PASSWORD_HASH]);
        $userId = (int) $pdo->lastInsertId();
    }
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'super_admin' LIMIT 1")->fetchColumn();
    if ($roleId < 1) {
        throw new RuntimeException('دور المدير الرئيسي غير موجود.');
    }
    $role = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id, company_id) VALUES (:user_id, :role_id, NULL)');
    $role->execute(['user_id' => $userId, 'role_id' => $roleId]);
    $pdo->commit();
    @unlink(__FILE__);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'user_id' => $userId, 'username' => 'محمد', 'role' => 'super_admin'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    exit('تعذر تنفيذ الترقية.');
}
