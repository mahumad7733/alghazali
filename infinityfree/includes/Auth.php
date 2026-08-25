<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;

final class Auth
{
    private AuditLogger $audit;

    public function __construct(private Database $database)
    {
        $this->audit = new AuditLogger($database);
    }

    /** @return array<string, mixed> */
    public function registerCustomer(array $input): array
    {
        $fullName = Security::cleanText($input['full_name'] ?? null, 180);
        $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $phone = Security::cleanText($input['phone'] ?? null, 32);
        $password = (string) ($input['password'] ?? '');
        $countryId = filter_var($input['country_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $cityId = filter_var($input['city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($email === false || !$countryId || !$cityId || mb_strlen($password) < 10) {
            Response::error('تحقق من الاسم والبريد والهاتف وكلمة المرور والدولة والمدينة. يجب أن تتكون كلمة المرور من 10 أحرف على الأقل.', 'VALIDATION_ERROR', 422);
        }

        $result = $this->database->transaction(function (PDO $pdo) use ($fullName, $email, $phone, $password, $countryId, $cityId): array {
            $country = $pdo->prepare('SELECT id FROM countries WHERE id = :id AND is_active = 1');
            $country->execute(['id' => $countryId]);
            $city = $pdo->prepare('SELECT id FROM cities WHERE id = :id AND country_id = :country_id AND is_active = 1');
            $city->execute(['id' => $cityId, 'country_id' => $countryId]);
            if (!$country->fetch() || !$city->fetch()) {
                Response::error('الدولة أو المدينة المختارة غير متاحة.', 'VALIDATION_ERROR', 422);
            }

            $exists = $pdo->prepare('SELECT id FROM users WHERE email = :email OR phone = :phone LIMIT 1');
            $exists->execute(['email' => $email, 'phone' => $phone]);
            if ($exists->fetch()) {
                Response::error('البريد الإلكتروني أو رقم الهاتف مسجل مسبقًا.', 'DUPLICATE_ACCOUNT', 409);
            }

            $user = $pdo->prepare('INSERT INTO users (full_name, email, phone, password_hash) VALUES (:full_name, :email, :phone, :password_hash)');
            $user->execute([
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int) $pdo->lastInsertId();

            $customerRole = $pdo->query("SELECT id FROM roles WHERE code = 'customer' LIMIT 1")->fetchColumn();
            $role = $pdo->prepare('INSERT INTO user_roles (user_id, role_id, company_id) VALUES (:user_id, :role_id, NULL)');
            $role->execute(['user_id' => $userId, 'role_id' => $customerRole]);
            $customer = $pdo->prepare('INSERT INTO customers (user_id, country_id, city_id) VALUES (:user_id, :country_id, :city_id)');
            $customer->execute(['user_id' => $userId, 'country_id' => $countryId, 'city_id' => $cityId]);

            return ['id' => $userId, 'full_name' => $fullName, 'email' => $email];
        });

        $this->createSession((int) $result['id']);
        $this->audit->log((int) $result['id'], null, 'customer_registered', 'user', (int) $result['id']);
        return $this->currentUser() ?? $result;
    }

    /** @return array<string, mixed> */
    public function login(array $input): array
    {
        $identifier = trim((string) ($input['identifier'] ?? $input['email'] ?? $input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (mb_strlen($identifier) < 2 || $password === '') {
            Response::error('بيانات الدخول غير صحيحة.', 'INVALID_CREDENTIALS', 422);
        }

        $attempts = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE email = :email AND ip_address = :ip AND was_successful = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $attempts->execute(['email' => $identifier, 'ip' => $ipAddress]);
        if ((int) $attempts->fetchColumn() >= 5) {
            Response::error('تم تجاوز عدد محاولات تسجيل الدخول. يرجى المحاولة بعد 15 دقيقة.', 'RATE_LIMITED', 429);
        }

        $statement = $this->database->pdo()->prepare('SELECT id, password_hash, status FROM users WHERE email = :email OR username = :username OR phone = :phone LIMIT 1');
        $statement->execute(['email' => $identifier, 'username' => $identifier, 'phone' => $identifier]);
        $user = $statement->fetch();
        $isValid = is_array($user) && $user['status'] === 'active' && password_verify($password, (string) $user['password_hash']);
        $this->recordLoginAttempt($identifier, $ipAddress, $isValid);

        if (is_array($user) && $user['status'] !== 'active' && password_verify($password, (string) $user['password_hash'])) {
            Response::error('حسابك غير نشط حاليًا. يرجى التواصل مع الإدارة.', 'ACCOUNT_INACTIVE', 403);
        }

        if (!$isValid) {
            Response::error('بيانات الدخول غير صحيحة.', 'INVALID_CREDENTIALS', 401);
        }

        $userId = (int) $user['id'];
        $this->createSession($userId);
        $update = $this->database->pdo()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $update->execute(['id' => $userId]);
        $this->audit->log($userId, $this->companyIdForUser($userId), 'user_logged_in', 'user', $userId);

        return $this->currentUser() ?? [];
    }

    public function logout(): void
    {
        $current = $this->currentUser();
        if ($current !== null) {
            $this->audit->log((int) $current['id'], $current['company_id'] ? (int) $current['company_id'] : null, 'user_logged_out', 'user', (int) $current['id']);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    /** @return array<string, mixed>|null */
    public function currentUser(): ?array
    {
        $sessionUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        if ($sessionUserId > 0) {
            return $this->loadUser($sessionUserId);
        }

        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) !== 1) {
            return null;
        }

        $tokenHash = hash('sha256', $matches[1]);
        $token = $this->database->pdo()->prepare(
            'SELECT user_id FROM api_tokens WHERE token_hash = :token_hash AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $token->execute(['token_hash' => $tokenHash]);
        $userId = (int) $token->fetchColumn();
        if ($userId <= 0) {
            return null;
        }

        $this->database->pdo()->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE token_hash = :token_hash')->execute(['token_hash' => $tokenHash]);
        return $this->loadUser($userId);
    }

    /** @return array<string, mixed> */
    public function requireUser(): array
    {
        $user = $this->currentUser();
        if ($user === null) {
            Response::error('يجب تسجيل الدخول للوصول إلى هذا المورد.', 'UNAUTHORIZED', 401);
        }
        return $user;
    }

    /** @param list<string> $requiredPermissions */
    public function requirePermissions(array $requiredPermissions): array
    {
        $user = $this->requireUser();
        if (in_array('super_admin', $user['roles'], true)) {
            return $user;
        }
        foreach ($requiredPermissions as $permission) {
            if (!in_array($permission, $user['permissions'], true)) {
                Response::error('لا تملك الصلاحية المطلوبة لتنفيذ هذه العملية.', 'FORBIDDEN', 403);
            }
        }
        return $user;
    }

    public function assertCompanyAccess(array $user, int $companyId): void
    {
        if (in_array('super_admin', $user['roles'], true)) {
            return;
        }
        if ((int) ($user['company_id'] ?? 0) !== $companyId) {
            Response::error('لا يمكن الوصول إلى بيانات شركة أخرى.', 'FORBIDDEN', 403);
        }
    }

    /** @return array{token:string,expires_at:string} */
    public function issueApiToken(int $userId, string $name = 'جلسة تطبيق'): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO api_tokens (user_id, token_name, token_hash, expires_at) VALUES (:user_id, :token_name, :token_hash, :expires_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_name' => mb_substr($name, 0, 120),
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => $expiresAt,
        ]);
        return ['token' => $rawToken, 'expires_at' => $expiresAt];
    }

    /** @return array<string, mixed>|null */
    private function loadUser(int $userId): ?array
    {
        $statement = $this->database->pdo()->prepare('SELECT id, full_name, email, phone, status FROM users WHERE id = :id AND status = \'active\' LIMIT 1');
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            return null;
        }

        $roles = $this->database->pdo()->prepare(
            'SELECT r.code, ur.company_id FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :user_id'
        );
        $roles->execute(['user_id' => $userId]);
        $roleRows = $roles->fetchAll();
        $roleCodes = array_values(array_unique(array_map(static fn(array $row): string => (string) $row['code'], $roleRows)));
        $companyId = null;
        foreach ($roleRows as $roleRow) {
            if ($roleRow['company_id'] !== null) {
                $companyId = (int) $roleRow['company_id'];
                break;
            }
        }

        $permissions = $this->database->pdo()->prepare(
            'SELECT DISTINCT p.code FROM user_roles ur INNER JOIN role_permissions rp ON rp.role_id = ur.role_id INNER JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = :user_id'
        );
        $permissions->execute(['user_id' => $userId]);
        $permissionCodes = array_values(array_map(static fn(array $row): string => (string) $row['code'], $permissions->fetchAll()));

        $customerId = $this->scalar('SELECT id FROM customers WHERE user_id = :user_id LIMIT 1', ['user_id' => $userId]);
        $agentId = $this->scalar('SELECT id FROM agents WHERE user_id = :user_id LIMIT 1', ['user_id' => $userId]);

        return [
            'id' => (int) $user['id'],
            'full_name' => (string) $user['full_name'],
            'email' => (string) $user['email'],
            'phone' => $user['phone'],
            'roles' => $roleCodes,
            'permissions' => $permissionCodes,
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'agent_id' => $agentId,
        ];
    }

    private function createSession(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['authenticated_at'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    private function recordLoginAttempt(string $email, string $ipAddress, bool $wasSuccessful): void
    {
        $statement = $this->database->pdo()->prepare('INSERT INTO login_attempts (email, ip_address, was_successful) VALUES (:email, :ip_address, :was_successful)');
        $statement->execute(['email' => $email, 'ip_address' => $ipAddress, 'was_successful' => $wasSuccessful ? 1 : 0]);
    }

    private function companyIdForUser(int $userId): ?int
    {
        $companyId = $this->scalar('SELECT company_id FROM user_roles WHERE user_id = :user_id AND company_id IS NOT NULL LIMIT 1', ['user_id' => $userId]);
        return $companyId === null ? null : (int) $companyId;
    }

    /** @param array<string, mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        return $value === false ? null : $value;
    }
}
